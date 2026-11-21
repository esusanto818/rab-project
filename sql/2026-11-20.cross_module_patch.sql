-- ============================================================================
-- sql/2026-11-20.cross_module_patch.sql
-- Cross-Module Integration Patch & Audit Hardening (proyekGMN)
-- ============================================================================

BEGIN;

-- 1. Pastikan indeks dan kolom PPh pada purchase_invoice_hdr sudah ada
ALTER TABLE IF EXISTS public.purchase_invoice_hdr
    ADD COLUMN IF NOT EXISTS pph_rate numeric(5,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS pph_amount numeric(18,2) DEFAULT 0.00;

-- 2. Pastikan indeks persetujuan GRN aktif
CREATE INDEX IF NOT EXISTS idx_grn_hdr_approval_status
    ON public.grn_hdr (approval_status);

-- 3. Pastikan rule PPh Supplier di fiscal_config ada dan aktif
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PPH_SUPPLIER', 'Pemotongan PPh Supplier',
       (SELECT id FROM m_coa WHERE code LIKE '2%' LIMIT 1),
       (SELECT id FROM m_coa WHERE code LIKE '2%' ORDER BY id DESC LIMIT 1),
       true
WHERE NOT EXISTS (
    SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PPH_SUPPLIER'
);

COMMIT;

-- -- ROLLBACK / DOWN MIGRATION
-- BEGIN;
-- ALTER TABLE IF EXISTS public.purchase_invoice_hdr DROP COLUMN IF EXISTS pph_rate, DROP COLUMN IF EXISTS pph_amount;
-- DROP INDEX IF EXISTS idx_grn_hdr_approval_status;
-- DELETE FROM public.fiscal_config WHERE rule_code = 'PPH_SUPPLIER';
-- COMMIT;
