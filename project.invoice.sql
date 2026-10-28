-- ============================================================
-- Tagihan ke Klien (invoice termin proyek) - jatuh tempo + menu
-- Database : proyekGMN (PostgreSQL 9.6). Jalankan SETELAH 2026-10-26.ppn_invoice.sql.
-- Dipakai  : projectinvoice.js / projectinvoiceCRD.php / pdf/projectinvoice.php
-- Aman dijalankan ulang.
-- ============================================================
BEGIN;

-- 1) Jatuh tempo invoice (opsional; invoice lama NULL = tanpa jatuh tempo). Dasar status jatuh tempo & proyeksi penerimaan kas.
ALTER TABLE public.project_invoices
    ADD COLUMN IF NOT EXISTS due_date date;
CREATE INDEX IF NOT EXISTS idx_project_invoices_due ON public.project_invoices (due_date) WHERE due_date IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_project_invoices_project ON public.project_invoices (project_id, invoice_date);

-- 2) Menu TAGIHAN KE KLIEN: grup 75, seq 6026
INSERT INTO public.m_form_name (group_id, seq, title, php_url, js_url, remark, main_table, tna, active)
SELECT 75, '6026', 'TAGIHAN KE KLIEN', 'projectinvoice.php', 'projectinvoice.js',
       'Terbitkan invoice termin ke client, pantau pembayaran & jatuh tempo', 'project_invoices', false, true
 WHERE EXISTS (SELECT 1 FROM public.m_form_group g WHERE g.id = 75)
   AND NOT EXISTS (SELECT 1 FROM public.m_form_name f WHERE f.seq = '6026' OR f.php_url = 'projectinvoice.php');

COMMIT;

-- Verifikasi (harus 1 baris):
-- SELECT id, group_id, seq, title, php_url FROM public.m_form_name WHERE seq = '6026';
-- Hak akses menu TIDAK diberikan otomatis. Contoh untuk satu user (access_level 4 = penuh):
-- INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
-- SELECT <user_id>, f.id, 4 FROM public.m_form_name f WHERE f.seq = '6026';
