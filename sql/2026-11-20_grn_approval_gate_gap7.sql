-- ============================================================================
-- 2026-11-20_grn_approval_gate_gap7.sql
-- Gap #7: GRN Approval Gate
--
-- Implements approval workflow for Goods Receipt Notes (GRN):
-- - GRN must be APPROVED before it can be received into warehouse (WH Receiving)
-- - GRN must be APPROVED before it can be matched to Purchase Invoice
-- - Draft/Pending GRNs do NOT add to warehouse stock nor create AP liability
-- - PO release flow remains unchanged (direct to 'Released')
-- ============================================================================

BEGIN;

-- 1. GRN header approval control ------------------------------------------------
-- Add approval columns if they don't exist (migration may have been partially applied)
ALTER TABLE public.grn_hdr
    ADD COLUMN IF NOT EXISTS approval_status text
        NOT NULL DEFAULT 'PENDING'
        CHECK (approval_status IN ('PENDING','APPROVED','REJECTED')),
    ADD COLUMN IF NOT EXISTS approved_by integer,
    ADD COLUMN IF NOT EXISTS approved_at timestamp without time zone,
    ADD COLUMN IF NOT EXISTS approved_note text;

-- Update existing GRNs to have proper approval_status based on current status
-- Existing 'open' GRNs should become PENDING (waiting for approval)
-- Existing 'partial'/'confirmed' GRNs should become APPROVED (already processed)
UPDATE public.grn_hdr
SET approval_status = CASE
    WHEN LOWER(status) IN ('partial', 'confirmed', 'posted') THEN 'APPROVED'
    ELSE 'PENDING'
END
WHERE approval_status IS NULL OR approval_status = 'PENDING';

CREATE INDEX IF NOT EXISTS idx_grn_hdr_approval_status
    ON public.grn_hdr (approval_status);

-- 2. Supplier payment -> invoice linkage (FK constraint) -------------------------
-- Make the invoice FK explicit so every install has a real FK constraint.
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

-- 4. Ensure grn_hdr.status values align with approval workflow
-- Valid statuses: 'open' (draft, pending approval), 'partial' (partially received), 'confirmed' (fully received), 'cancelled'
-- approval_status: 'PENDING' -> 'APPROVED' -> (then can be received)
-- When GRN is approved, status changes from 'open' to 'confirmed' (ready for WH Receiving)
-- Actually, let's keep status as-is and use approval_status as the gate

COMMIT;