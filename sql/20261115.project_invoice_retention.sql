-- ============================================================================
-- 20261115.project_invoice_retention.sql
--
-- [GAP 2] Project invoice retention not recorded; payment allocation not
--         validated at database level
--
-- Evidence / business flow trace (upstream -> downstream):
--   project_invoices -> project_payment_items -> project_payments
--
--   1. `projects` and `subcon_contracts` carry `retention_pct` / `retention_amount`
--      but `project_invoices` has NONE. Client retention (the percentage of an
--      invoice withheld until final acceptance) cannot be recorded anywhere, so
--      the retention-release step in year close is not traceable.
--   2. `project_payment_items` has amount_paid / amount_cur but no amount_due /
--      balance, and there is no DB rule that the sum of allocations to an
--      invoice cannot exceed (amount - retention_amount). Over-allocation to an
--      invoice is therefore possible.
--
-- Backward-compatible: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS
-- + CREATE FUNCTION / TRIGGER (idempotent via DO block).
-- ============================================================================

BEGIN;

-- 1. Retention columns on project_invoices -------------------------------------
ALTER TABLE public.project_invoices
    ADD COLUMN IF NOT EXISTS retention_pct         numeric(5,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS retention_amount      numeric(18,2) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_project_invoices_outstanding
    ON public.project_invoices (project_id)
    WHERE release_status = 'released'
      AND amount > paid_amount;

-- 2. DB guard: project_payment_items.amount_paid <= (amount - paid - retention)
CREATE OR REPLACE FUNCTION public.fn_check_project_payment_within_invoice()
RETURNS trigger AS $$
DECLARE
    v_balance numeric(18,2);
BEGIN
    SELECT COALESCE(amount,0)
         - COALESCE(paid_amount,0)
         - COALESCE(retention_amount,0)
      INTO v_balance
      FROM public.project_invoices
     WHERE id = NEW.invoice_id;

    IF v_balance < 0 THEN
        v_balance := 0;
    END IF;

    IF NEW.amount_paid > v_balance + 0.01 THEN
        RAISE EXCEPTION 'Project payment allocation (%.2F) exceeds invoice balance (%.2F)',
                        NEW.amount_paid, v_balance;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_project_payment_within_invoice'
          AND tgrelid = 'public.project_payment_items'::regclass) THEN
        CREATE TRIGGER trg_check_project_payment_within_invoice
            BEFORE INSERT OR UPDATE OF amount_paid
            ON public.project_payment_items
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_project_payment_within_invoice();
    END IF;
END
$$;

COMMIT;
