-- ============================================================================
-- 20261115.project_payment_fx_and_allocation.sql
--
-- [GAP 3] Project payment flow:
--          - project_payments lacks currency / base-FX columns (same gap as AP,
--            which was fixed for purchase_invoice_hdr/supplier_payments in
--            2026-11-12.ap_currency.sql but NOT for project_payments / project_invoices)
--          - project_payment_items.amount_paid is not validated against the
--            payment header total
--          - allocated_amount / excess_amount / deposit_applied exist only at
--            header level, with no DB guard limiting them to total_amount
--
-- Evidence / business flow trace (upstream -> downstream):
--   financial_incomes/project_invoices -> project_payments -> project_payment_items
--
-- Backward-compatible: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS
-- + CREATE FUNCTION / TRIGGER (idempotent via DO block).
-- ============================================================================

BEGIN;

-- 1. Currency / base-FX columns on project_payments (parallel to AP migration)
ALTER TABLE public.project_payments
    ADD COLUMN IF NOT EXISTS currency_id          bigint REFERENCES public.m_currency(id),
    ADD COLUMN IF NOT EXISTS exch_rate            numeric(15,4) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS total_amount_base    numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS allocated_amount_base numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS excess_amount_base   numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS deposit_applied_base numeric(18,2) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_project_payments_overallocated
    ON public.project_payments (client_id, payment_date)
    WHERE allocated_amount > total_amount
       OR allocated_amount_base > total_amount_base;

-- 2. DB guard: sum(project_payment_items.amount_paid) per payment <= payment total
CREATE OR REPLACE FUNCTION public.fn_check_project_payment_items_within_header()
RETURNS trigger AS $$
DECLARE
    v_total      numeric(18,2);
    v_allocated  numeric(18,2);
BEGIN
    SELECT COALESCE(total_amount, 0) INTO v_total
      FROM public.project_payments
     WHERE id = NEW.payment_id;

    SELECT COALESCE(SUM(amount_paid), 0) INTO v_allocated
      FROM public.project_payment_items
     WHERE payment_id = NEW.payment_id;

    IF v_allocated > v_total + 0.01 THEN
        RAISE EXCEPTION 'Project payment item allocation (%.2F) exceeds payment total (%.2F)',
                        v_allocated, v_total;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_project_payment_items_within_header'
          AND tgrelid = 'public.project_payment_items'::regclass) THEN
        CREATE TRIGGER trg_check_project_payment_items_within_header
            BEFORE INSERT OR UPDATE OF amount_paid
            ON public.project_payment_items
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_project_payment_items_within_header();
    END IF;
END
$$;

COMMIT;
