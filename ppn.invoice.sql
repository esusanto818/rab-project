-- ============================================================
-- Migrasi PPN - TAHAP 1b & 2 (PPN Masukan di Purchase Invoice, PPN Keluaran di Termin Proyek)
-- Database : proyekGMN (PostgreSQL 9.6). Jalankan SETELAH 2026-10-26.ppn_po.sql.
-- Dipakai  : purchaseinvoice.js/purchaseinvoiceCRD.php, project_payment.js/project_paymentCRD.php
-- Aman dijalankan ulang.
-- Akun (chart_of_accounts): 1200 Piutang Usaha, 2100 Hutang Usaha, 2300 PPN Keluaran, 2400 PPN Masukan.
-- ============================================================
BEGIN;

-- 1) Termin proyek (project_invoices): amount tetap = TOTAL tagihan (DPP + PPN).
ALTER TABLE public.project_invoices
    ADD COLUMN IF NOT EXISTS dpp_amount        numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ppn_rate          numeric(5,2)  NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ppn_amount        numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS faktur_pajak_no   varchar(30),
    ADD COLUMN IF NOT EXISTS faktur_pajak_date date;
-- Termin lama: seluruh amount dianggap DPP, tanpa PPN.
UPDATE public.project_invoices SET dpp_amount = amount WHERE dpp_amount = 0 AND amount > 0;

-- 2) Invoice supplier (purchase_invoice_hdr): total_amount tetap = nilai hutang (DPP + PPN).
ALTER TABLE public.purchase_invoice_hdr
    ADD COLUMN IF NOT EXISTS dpp_amount        numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ppn_rate          numeric(5,2)  NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ppn_amount        numeric(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS faktur_pajak_no   varchar(30),
    ADD COLUMN IF NOT EXISTS faktur_pajak_date date;
UPDATE public.purchase_invoice_hdr SET dpp_amount = total_amount WHERE dpp_amount = 0 AND total_amount > 0;

-- No. faktur pajak masukan unik per supplier (NULL boleh banyak)
CREATE UNIQUE INDEX IF NOT EXISTS uq_pinv_supplier_faktur
    ON public.purchase_invoice_hdr (supplier_id, faktur_pajak_no)
    WHERE faktur_pajak_no IS NOT NULL;

-- 3) Aturan jurnal PPN di fiscal_config (dipakai AutoPostJournal)
--    PROJECT_INVOICE_PPN  : Dr 1200 Piutang Usaha  / Cr 2300 PPN Keluaran
--    PURCHASE_INVOICE_PPN : Dr 2400 PPN Masukan    / Cr 2100 Hutang Usaha
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PROJECT_INVOICE_PPN', 'Penagihan termin - PPN Keluaran',
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '1200'),
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '2300'), true
WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PROJECT_INVOICE_PPN')
  AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '1200')
  AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '2300');

INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PURCHASE_INVOICE_PPN', 'Invoice supplier - PPN Masukan',
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '2400'),
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '2100'), true
WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PURCHASE_INVOICE_PPN')
  AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '2400')
  AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '2100');

COMMIT;

-- Verifikasi (harus 2 baris):
-- SELECT rule_code, debit_account_id, credit_account_id FROM public.fiscal_config WHERE rule_code IN ('PROJECT_INVOICE_PPN','PURCHASE_INVOICE_PPN');
