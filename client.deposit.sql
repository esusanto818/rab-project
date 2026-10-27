-- ============================================================
-- Deposit client (left over) - pemakaian deposit untuk membayar tagihan termin berikutnya
-- Database : proyekGMN (PostgreSQL 9.6). Dipakai: project_payment.js / project_paymentCRD.php
-- Kolom project_payments.deposit_applied sudah ada (default 0); migrasi ini hanya menambah aturan jurnal & index.
-- Aman dijalankan ulang.
-- ============================================================
BEGIN;

-- Aturan jurnal PRJ_DEP_APPLY: Dr Uang Muka Penjualan / Kelebihan pembayaran (deposit) / Cr Piutang Usaha.
-- Akun diambil dari aturan yang sudah ada (PRJ_PAY_DEP kredit = akun deposit, PRJ_PAY_AR kredit = piutang);
-- bila tidak ditemukan dipakai akun COA 2200 dan 1200.
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PRJ_DEP_APPLY', 'Pemakaian deposit client untuk bayar termin',
       COALESCE((SELECT credit_account_id FROM public.fiscal_config WHERE rule_code = 'PRJ_PAY_DEP' AND is_active LIMIT 1),
                (SELECT id FROM public.chart_of_accounts WHERE account_code = '2200')),
       COALESCE((SELECT credit_account_id FROM public.fiscal_config WHERE rule_code = 'PRJ_PAY_AR' AND is_active LIMIT 1),
                (SELECT id FROM public.chart_of_accounts WHERE account_code = '1200')),
       true
 WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PRJ_DEP_APPLY');

CREATE INDEX IF NOT EXISTS idx_project_payments_client ON public.project_payments (client_id, payment_date);

COMMIT;

-- Verifikasi (harus 1 baris, debit = akun deposit, kredit = piutang usaha):
-- SELECT f.rule_code, d.account_code AS debit, d.account_name, c.account_code AS kredit, c.account_name
--   FROM public.fiscal_config f
--   JOIN public.chart_of_accounts d ON d.id = f.debit_account_id
--   JOIN public.chart_of_accounts c ON c.id = f.credit_account_id
--  WHERE f.rule_code IN ('PRJ_PAY_DEP', 'PRJ_DEP_APPLY');
