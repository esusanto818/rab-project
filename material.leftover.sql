-- ============================================================
-- Material Left Over (retur sisa material proyek ke gudang)
-- Database : proyekGMN (PostgreSQL 9.6). Dipakai: material.leftover.js / material.leftoverCRD.php
-- Aman dijalankan ulang. Jalankan 2026-10-27.leftover_journal_enum.sql TERPISAH (di luar transaksi).
-- ============================================================
BEGIN;

CREATE TABLE IF NOT EXISTS public.material_leftover_header (
    id           serial PRIMARY KEY,
    ref_no       varchar(50)  NOT NULL UNIQUE,
    project_id   integer      NOT NULL REFERENCES public.projects(id),
    warehouse_id integer      NOT NULL REFERENCES public.warehouses(id),
    doc_date     date         NOT NULL DEFAULT CURRENT_DATE,
    status       varchar(20)  NOT NULL DEFAULT 'posted',          -- posted | reversed
    remark       text,
    created_by   integer,
    created_at   timestamp    NOT NULL DEFAULT now(),
    reversed_at  timestamp,
    reversed_by  integer
);

CREATE TABLE IF NOT EXISTS public.material_leftover_items (
    id          serial PRIMARY KEY,
    header_id   integer       NOT NULL REFERENCES public.material_leftover_header(id) ON DELETE CASCADE,
    material_id integer       NOT NULL REFERENCES public.materials(id),
    qty         numeric(12,4) NOT NULL CHECK (qty > 0),
    unit_cost   numeric(15,4) NOT NULL DEFAULT 0,                  -- biaya rata-rata issue bersih proyek saat retur
    notes       text
);

CREATE INDEX IF NOT EXISTS idx_mlo_hdr_project ON public.material_leftover_header (project_id, status);
CREATE INDEX IF NOT EXISTS idx_mlo_items_hdr   ON public.material_leftover_items (header_id);
CREATE INDEX IF NOT EXISTS idx_mlo_items_mat   ON public.material_leftover_items (material_id);

-- Aturan jurnal WH_LEFTOVER: Dr 1500 Persediaan Bahan Baku / Cr 1700 Pekerjaan Dalam Proses (WIP) Proyek
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'WH_LEFTOVER', 'Retur sisa material proyek ke gudang',
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '1500'),
       (SELECT id FROM public.chart_of_accounts WHERE account_code = '1700'), true
 WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'WH_LEFTOVER')
   AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '1500')
   AND EXISTS (SELECT 1 FROM public.chart_of_accounts WHERE account_code = '1700');

COMMIT;

-- Verifikasi aturan jurnal (harus 1 baris: debit 1500, kredit 1700):
-- SELECT f.rule_code, d.account_code AS debit, c.account_code AS kredit FROM public.fiscal_config f
--   JOIN public.chart_of_accounts d ON d.id = f.debit_account_id JOIN public.chart_of_accounts c ON c.id = f.credit_account_id
--  WHERE f.rule_code = 'WH_LEFTOVER';

-- Menu (ISI grup & seq, lalu jalankan). Contoh:
-- INSERT INTO public.m_form_name (group_id, seq, title, php_url, js_url, remark, main_table, tna, active)
-- SELECT <GROUP_ID>, '<SEQ>', 'MATERIAL LEFT OVER', 'material.leftover.php', 'material.leftover.js',
--        'Retur sisa material proyek ke gudang & posisi per proyek', 'material_leftover_header', false, true
--  WHERE NOT EXISTS (SELECT 1 FROM public.m_form_name f WHERE f.php_url = 'material.leftover.php');
-- Hak akses menu tidak diberikan otomatis (m_user_access_menu).
