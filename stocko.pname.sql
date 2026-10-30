-- 2026-10-30.stock_opname.sql
-- Modul Stock Opname: tabel, akun selisih persediaan, aturan jurnal, menu.
-- Aman diulang (IF NOT EXISTS / WHERE NOT EXISTS). Jalankan dalam mode AUTO-COMMIT.
-- Kompatibel PostgreSQL 9.6.

-- 1) Tabel ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_opname_hdr (
    id            serial PRIMARY KEY,
    ref_no        varchar(50)  NOT NULL UNIQUE,
    warehouse_id  integer      NOT NULL REFERENCES warehouses(id),
    opname_date   date         NOT NULL DEFAULT ('now'::text)::date,
    status        varchar(20)  NOT NULL DEFAULT 'Draft',      -- Draft -> Diajukan -> Disetujui | Dibatalkan
    remark        text,
    created_by    integer,
    created_at    timestamp    NOT NULL DEFAULT now(),
    updated_at    timestamp,
    submitted_by  integer,
    submitted_at  timestamp,
    approved_by   integer,
    approved_at   timestamp,
    cancelled_by  integer,
    cancelled_at  timestamp,
    cancel_reason text,
    total_gain    numeric(18,2) NOT NULL DEFAULT 0,           -- nilai selisih lebih yang diposting
    total_loss    numeric(18,2) NOT NULL DEFAULT 0,           -- nilai selisih kurang yang diposting
    CONSTRAINT chk_so_status CHECK (status IN ('Draft','Diajukan','Disetujui','Dibatalkan'))
);

CREATE TABLE IF NOT EXISTS stock_opname_dtl (
    id          serial PRIMARY KEY,
    opname_id   integer       NOT NULL REFERENCES stock_opname_hdr(id),
    material_id integer       NOT NULL REFERENCES materials(id),
    system_qty  numeric(14,4) NOT NULL DEFAULT 0,             -- stok sistem saat disimpan / di-refresh (snapshot)
    counted_qty numeric(14,4),                                -- hasil hitung fisik; NULL = tidak dihitung (tidak disesuaikan)
    diff_qty    numeric(14,4) NOT NULL DEFAULT 0,             -- counted - system
    unit_cost   numeric(15,4) NOT NULL DEFAULT 0,             -- biaya satuan penilaian (wajib > 0 untuk selisih lebih)
    diff_value  numeric(18,2) NOT NULL DEFAULT 0,             -- nilai selisih yang diposting (+ lebih / - kurang)
    reason      varchar(60),
    note        text,
    UNIQUE (opname_id, material_id)
);
CREATE INDEX IF NOT EXISTS idx_so_dtl_opname ON stock_opname_dtl(opname_id);

-- Satu gudang hanya boleh punya satu opname berjalan (Draft / Diajukan)
CREATE UNIQUE INDEX IF NOT EXISTS uq_so_open_per_wh ON stock_opname_hdr(warehouse_id) WHERE status IN ('Draft','Diajukan');

-- 2) Akun selisih persediaan (tipe & induk disalin dari akun beban 5100) --------
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '5600', 'Selisih Persediaan (Stock Opname)', c.account_type, c.parent_id, true
  FROM chart_of_accounts c
 WHERE c.account_code = '5100'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '5600');

-- 3) Aturan jurnal: lebih = Dr Persediaan / Cr Selisih ; kurang = Dr Selisih / Cr Persediaan
INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'STOCK_ADJ_GAIN', 'Stock opname - selisih lebih (Dr Persediaan / Cr Selisih Persediaan)',
       (SELECT id FROM chart_of_accounts WHERE account_code = '1500'),
       (SELECT id FROM chart_of_accounts WHERE account_code = '5600'), true
 WHERE NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'STOCK_ADJ_GAIN');

INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'STOCK_ADJ_LOSS', 'Stock opname - selisih kurang (Dr Selisih Persediaan / Cr Persediaan)',
       (SELECT id FROM chart_of_accounts WHERE account_code = '5600'),
       (SELECT id FROM chart_of_accounts WHERE account_code = '1500'), true
 WHERE NOT EXISTS (SELECT 1 FROM fiscal_config WHERE rule_code = 'STOCK_ADJ_LOSS');

-- 4) Menu (disalin dari menu Kartu Stok: grup, departemen, format php_url / js_url) -----
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT f.group_id,
       (SELECT (MAX(CASE WHEN x.seq ~ '^[0-9]+$' THEN x.seq::int END) + 1)::text FROM m_form_name x WHERE x.group_id = f.group_id),
       'Stock Opname',
       regexp_replace(f.php_url, '[^/]*$', 'stock.opname.php'),
       regexp_replace(f.js_url,  '[^/]*$', 'stock.opname.js'),
       'Stock opname per gudang: hitung fisik, selisih, posting penyesuaian stok & jurnal',
       f.department_id, 'stock_opname_hdr', f.tna, true
  FROM m_form_name f
 WHERE f.php_url ILIKE '%stock.card%' AND f.active
   AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE title = 'Stock Opname')
 LIMIT 1;

-- Hak akses: salin dari pemilik akses menu Kartu Stok (level sama)
INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT a.user_id, (SELECT id FROM m_form_name WHERE title = 'Stock Opname' ORDER BY id DESC LIMIT 1), a.access_level
  FROM m_user_access_menu a
  JOIN m_form_name f ON f.id = a.form_id
 WHERE f.php_url ILIKE '%stock.card%'
   AND NOT EXISTS (SELECT 1 FROM m_user_access_menu z
                    WHERE z.user_id = a.user_id
                      AND z.form_id = (SELECT id FROM m_form_name WHERE title = 'Stock Opname' ORDER BY id DESC LIMIT 1));

-- 5) Audit trigger (data_log_*): salin definisi dari tabel yang sudah ber-trigger, lalu terapkan ke
--    stock_opname_hdr dan stock_opname_dtl. Lihat definisi yang ada:
--    SELECT tgrelid::regclass AS tabel, pg_get_triggerdef(oid) FROM pg_trigger
--     WHERE NOT tgisinternal AND pg_get_triggerdef(oid) ILIKE '%if_modified_func%' LIMIT 5;

-- Cek hasil:
-- SELECT id, group_id, seq, title, php_url, js_url FROM m_form_name WHERE title = 'Stock Opname';
