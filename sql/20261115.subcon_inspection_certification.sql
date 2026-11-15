-- ============================================================================
-- 20261115.subcon_inspection_certification.sql
--
-- [GAP 5] Subcontract flow:
--          - subcon_inspection_items has no rework_required flag / rework chain,
--            although the design doc requires rework to open a NEW inspection
--            event (rework cannot flow into the same certificate)
--          - subcon_payment_certificate_items has no DB guard that
--            qty_certified <= linked inspection_item qty_passed
--          - subcon_payment_certificates has no rule that net_payable equals
--            gross - all deductions (no functional consistency check)
--
-- Evidence / business flow trace (upstream -> downstream):
--   subcon_contracts -> subcon_contract_tasks -> subcon_progress
--     -> subcon_progress_qty_items -> subcon_inspection_items
--     -> subcon_payment_certificate_items -> subcon_payment_certificates
--     -> subcon_invoices -> subcon_invoice_payments
--
-- Backward-compatible: ADD COLUMN IF NOT EXISTS + ADD CONSTRAINT IF NOT EXISTS
-- + CREATE FUNCTION / TRIGGER (idempotent via DO block).
-- ============================================================================

BEGIN;

-- 1. rework_required flag on inspection items (separate rework -> new inspection)
ALTER TABLE public.subcon_inspection_items
    ADD COLUMN IF NOT EXISTS rework_required boolean NOT NULL DEFAULT false;

-- 2. DB guard: qty_certified <= linked inspection_item qty_passed
CREATE OR REPLACE FUNCTION public.fn_check_subcon_cert_item_within_passed()
RETURNS trigger AS $$
DECLARE
    v_passed numeric(18,4);
BEGIN
    SELECT COALESCE(sii.qty_passed, 0) INTO v_passed
      FROM public.subcon_inspection_items sii
     WHERE sii.id = NEW.inspection_item_id;

    IF NEW.qty_certified > v_passed + 0.001 THEN
        RAISE EXCEPTION 'subcon_payment_certificate_items qty_certified (%.4F) exceeds passed qty (%.4F)',
                        NEW.qty_certified, v_passed;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_check_subcon_cert_item_within_passed'
          AND tgrelid = 'public.subcon_payment_certificate_items'::regclass) THEN
        CREATE TRIGGER trg_check_subcon_cert_item_within_passed
            BEFORE INSERT OR UPDATE OF qty_certified
            ON public.subcon_payment_certificate_items
            FOR EACH ROW EXECUTE PROCEDURE public.fn_check_subcon_cert_item_within_passed();
    END IF;
END
$$;

-- 3. Consistency rule: net_payable = gross - all deductions (same row)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.subcon_payment_certificates'::regclass
          AND conname = 'chk_subcon_cert_net_payable'
          AND contype = 'c'
    ) THEN
        ALTER TABLE public.subcon_payment_certificates
            ADD CONSTRAINT chk_subcon_cert_net_payable
                CHECK (net_payable =
                       gross_amount - ppn_amount - pph_amount - retention_amount
                       - dp_recovery - other_deduction);
    END IF;
END
$$;

COMMIT;
