-- ============================================================================
-- 20261115.grn_approval_and_supplier_payment.sql
--
-- [GAP 1] PO -> GRN -> Supplier Payment  (inbound AP cycle)
--
-- Evidence / business flow trace (upstream -> downstream):
--   purchase_orders -> grn_hdr/grn_dtl -> wh_receiving_hdr -> purchase_invoice_hdr
--   -> supplier_payment_items -> supplier_payments
--
--   1. GRN (grn_hdr) has free-text status "open" only. Nothing records whether
--      the receipt was APPROVED by the inventory/quality function before the
--      invoice is matched. There is no approval_date, approver or approval
--      note, so the upstream "receipt confirmed" gate is not enforced in DB.
--   2. supplier_payment_items links a payment to an invoice via invoice_id, but
--      there is NO database-level rule that the total allocated to an invoice
--      cannot exceed the invoice total. Application code only; double /
--      over-allocation can occur silently.
--
-- Backward-compatible: ADD COLUMN IF NOT EXISTS + ADD CONSTRAINT IF NOT EXISTS
-- + CREATE INDEX IF NOT EXISTS. Nothing is dropped or changed in place.
-- ============================================================================

BEGIN;

-- 1. GRN header approval control ------------------------------------------------
ALTER TABLE public.grn_hdr
    ADD COLUMN IF NOT EXISTS approval_status text
        NOT NULL DEFAULT 'PENDING'
        CHECK (approval_status IN ('PENDING','APPROVED','REJECTED')),
    ADD COLUMN IF NOT EXISTS approved_by integer,
    ADD COLUMN IF NOT EXISTS approved_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS approved_note text;

CREATE INDEX IF NOT EXISTS idx_grn_hdr_approval_status
    ON public.grn_hdr (approval_status);

-- 2. Supplier payment -> invoice linkage ---------------------------------------
--    Make the invoice FK explicit so every install has a real FK constraint.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.supplier_payment_items'::regclass
          AND conname = 'fk_spi_invoice'
          AND contype = 'f'
    ) THEN
        ALTER TABLE public.supplier_payment_items
            ADD CONSTRAINT fk_spi_invoice
                FOREIGN KEY (invoice_id) REFERENCES public.purchase_invoice_hdr(id)
                    ON DELETE RESTRICT;
    END IF;
END
$$;

-- 3. DB guard: sum(supplier_payment_items.amount_paid) per invoice <= invoice total
CREATE OR REPLACE FUNCTION public.fn_check_supplier_payment_within_invoice()
RETURNS trigger AS $$
DECLARE
    v_total   numeric(18,2);
    v_allocated numeric(18,2);
BEGIN
    SELECT COALESCE(total_amount, 0) INTO v_total
      FROM public.purchase_invoice_hdr
     WHERE id = NEW.invoice_id;

    SELECT COALESCE(SUM(amount_paid), 0) INTO v_allocated
      FROM public.supplier_payment_items
     WHERE invoice_id = NEW.invoice_id;

    IF v_allocated > v_total + 0.01 THEN
        RAISE EXCEPTION 'Supplier payment allocation (%.2F) exceeds invoice total (%.2F)',
                        v_allocated, v_total;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_supplier_payment_within_invoice'
          AND tgrelid = 'public.supplier_payment_items'::regclass) THEN
        CREATE TRIGGER trg_check_supplier_payment_within_invoice
            BEFORE INSERT OR UPDATE OF amount_paid
            ON public.supplier_payment_items
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_supplier_payment_within_invoice();
    END IF;
END
$$;

COMMIT;
