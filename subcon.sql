-- 2026-10-30.subcon.sql
-- Modul Subkontraktor (SPK -> task proyek -> prestasi -> pembayaran), akun, aturan jurnal, menu.
-- Aman diulang. Jalankan dalam mode AUTO-COMMIT (ALTER TYPE ... ADD VALUE tidak boleh di dalam transaksi pada PG 9.6).
-- Kompatibel PostgreSQL 9.6.

-- 0) Nilai enum journal_entries.reference_type (lihat nama tipe: SELECT t.typname FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid
--    JOIN pg_type t ON t.oid=a.atttypid WHERE c.relname='journal_entries' AND a.attname='reference_type';)
-- Ganti <nama_tipe> lalu jalankan dua baris ini (baris pertama di bawah sudah dibuktikan: enum Anda belum memuat nilai subkon):
-- ALTER TYPE <nama_tipe> ADD VALUE IF NOT EXISTS 'subcon_progress';
-- ALTER TYPE <nama_tipe> ADD VALUE IF NOT EXISTS 'subcon_payment';

-- 1) Master subkontraktor -----------------------------------------------------
CREATE TABLE IF NOT EXISTS subcontractors (
    id                serial PRIMARY KEY,
    code              varchar(30),
    name              varchar(255) NOT NULL,
    npwp_no           varchar(30),
    is_pkp            boolean      NOT NULL DEFAULT false,
    contact_person    varchar(255),
    phone             varchar(50),
    address           text,
    bank_name         varchar(100),
    bank_account_no   varchar(100),
    bank_account_name varchar(150),
    pph_rate          numeric(5,2) NOT NULL DEFAULT 2.65,      -- default tarif PPh final Ps. 4(2) jasa konstruksi (dapat diubah per SPK)
    active            boolean      NOT NULL DEFAULT true,
    created_at        timestamp    NOT NULL DEFAULT now()
);

-- 2) SPK (kontrak subkon) --------------------------------------------------------
CREATE TABLE IF NOT EXISTS subcon_contracts (
    id               serial PRIMARY KEY,
    spk_no           varchar(50)   NOT NULL UNIQUE,
    project_id       integer       NOT NULL REFERENCES projects(id),
    subcontractor_id integer       NOT NULL REFERENCES subcontractors(id),
    title            varchar(255)  NOT NULL,
    scope            text,
    budget_item_id   integer       REFERENCES budget_items(id),   -- baris RAB (opsional) yang menjadi batas nilai SPK
    contract_value   numeric(18,2) NOT NULL,                      -- nilai kontrak DPP (di luar PPN)
    ppn_rate         numeric(5,2)  NOT NULL DEFAULT 0,
    pph_rate         numeric(5,2)  NOT NULL DEFAULT 0,
    retention_pct    numeric(5,2)  NOT NULL DEFAULT 0,            -- retensi ditahan dari tiap prestasi
    dp_pct           numeric(5,2)  NOT NULL DEFAULT 0,            -- uang muka (% dari nilai kontrak), dipotong proporsional dari prestasi
    start_date       date,
    end_date         date,
    status           varchar(20)   NOT NULL DEFAULT 'Draft',      -- Draft, Diajukan, Disetujui, Selesai, Dibatalkan
    notes            text,
    created_by       integer, created_at timestamp NOT NULL DEFAULT now(), updated_at timestamp,
    approved_by      integer, approved_at timestamp,
    closed_by        integer, closed_at timestamp,
    cancelled_by     integer, cancelled_at timestamp, cancel_reason text,
    CONSTRAINT chk_sc_status CHECK (status IN ('Draft','Diajukan','Disetujui','Selesai','Dibatalkan')),
    CONSTRAINT chk_sc_value  CHECK (contract_value > 0)
);
CREATE INDEX IF NOT EXISTS idx_sc_project ON subcon_contracts(project_id);

-- 3) Pembagian nilai SPK ke task proyek (task_id NULL = seluruh pekerjaan / tanpa task)
CREATE TABLE IF NOT EXISTS subcon_contract_tasks (
    id          serial PRIMARY KEY,
    contract_id integer       NOT NULL REFERENCES subcon_contracts(id),
    task_id     integer,                                          -- project_tasks.id
    task_name   varchar(255)  NOT NULL,                           -- salinan nama task (jejak bila task diubah)
    task_value  numeric(18,2) NOT NULL,                           -- bagian nilai kontrak (DPP) untuk task ini
    notes       text
);
CREATE INDEX IF NOT EXISTS idx_sct_contract ON subcon_contract_tasks(contract_id);

-- 4) Prestasi (opname pekerjaan) per periode ----------------------------------------
CREATE TABLE IF NOT EXISTS subcon_progress (
    id               serial PRIMARY KEY,
    ref_no           varchar(50)   NOT NULL UNIQUE,
    contract_id      integer       NOT NULL REFERENCES subcon_contracts(id),
    progress_date    date          NOT NULL,
    status           varchar(20)   NOT NULL DEFAULT 'Draft',      -- Draft, Disetujui, Dibatalkan
    gross_amount     numeric(18,2) NOT NULL DEFAULT 0,            -- nilai pekerjaan periode ini (DPP)
    ppn_amount       numeric(18,2) NOT NULL DEFAULT 0,
    pph_amount       numeric(18,2) NOT NULL DEFAULT 0,
    retention_amount numeric(18,2) NOT NULL DEFAULT 0,
    dp_recovery      numeric(18,2) NOT NULL DEFAULT 0,
    net_payable      numeric(18,2) NOT NULL DEFAULT 0,            -- gross + ppn - pph - retensi - potongan uang muka
    cum_pct          numeric(7,3)  NOT NULL DEFAULT 0,            -- progres kumulatif kontrak (%), tertimbang nilai task
    evidence_ref     varchar(255),
    remark           text,
    created_by       integer, created_at timestamp NOT NULL DEFAULT now(), updated_at timestamp,
    approved_by      integer, approved_at timestamp,
    cancelled_by     integer, cancelled_at timestamp, cancel_reason text,
    CONSTRAINT chk_sp_status CHECK (status IN ('Draft','Disetujui','Dibatalkan'))
);
CREATE INDEX IF NOT EXISTS idx_sp_contract ON subcon_progress(contract_id);

CREATE TABLE IF NOT EXISTS subcon_progress_dtl (
    id               serial PRIMARY KEY,
    progress_id      integer       NOT NULL REFERENCES subcon_progress(id),
    contract_task_id integer       NOT NULL REFERENCES subcon_contract_tasks(id),
    prev_pct         numeric(7,3)  NOT NULL DEFAULT 0,
    cum_pct          numeric(7,3)  NOT NULL DEFAULT 0,
    amount           numeric(18,2) NOT NULL DEFAULT 0,            -- task_value x (cum - prev) / 100
    UNIQUE (progress_id, contract_task_id)
);

-- 5) Pembayaran ke subkon (uang muka / prestasi / pelepasan retensi) ---------------------
CREATE TABLE IF NOT EXISTS subcon_payments (
    id            serial PRIMARY KEY,
    payment_no    varchar(50)   NOT NULL UNIQUE,
    contract_id   integer       NOT NULL REFERENCES subcon_contracts(id),
    pay_type      varchar(10)   NOT NULL,                         -- DP, PROGRESS, RETENSI
    progress_id   integer       REFERENCES subcon_progress(id),   -- wajib untuk PROGRESS
    payment_date  date          NOT NULL,
    account_id    bigint        NOT NULL REFERENCES chart_of_accounts(id),   -- kas / bank sumber
    amount        numeric(18,2) NOT NULL,
    reference_no  varchar(100),
    notes         text,
    status        varchar(12)   NOT NULL DEFAULT 'Posted',        -- Posted, Dibatalkan
    created_by    integer, created_at timestamp NOT NULL DEFAULT now(),
    cancelled_by  integer, cancelled_at timestamp, cancel_reason text,
    CONSTRAINT chk_spy_type   CHECK (pay_type IN ('DP','PROGRESS','RETENSI')),
    CONSTRAINT chk_spy_status CHECK (status IN ('Posted','Dibatalkan')),
    CONSTRAINT chk_spy_amount CHECK (amount > 0)
);
CREATE INDEX IF NOT EXISTS idx_spy_contract ON subcon_payments(contract_id);

-- 6) Akun baru (tipe & induk disalin dari akun yang sejenis) -------------------------------
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '1410', 'Uang Muka Subkontraktor', c.account_type, c.parent_id, true FROM chart_of_accounts c
 WHERE c.account_code = '1400' AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '1410');
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '2110', 'Hutang Subkontraktor', c.account_type, c.parent_id, true FROM chart_of_accounts c
 WHERE c.account_code = '2100' AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2110');
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '2120', 'Hutang Retensi Subkontraktor', c.account_type, c.parent_id, true FROM chart_of_accounts c
 WHERE c.account_code = '2100' AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2120');
INSERT INTO chart_of_accounts (account_code, account_name, account_type, parent_id, is_active)
SELECT '2130', 'Hutang PPh Final Ps. 4(2) Subkontraktor', c.account_type, c.parent_id, true FROM chart_of_accounts c
 WHERE c.account_code = '2100' AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE account_code = '2130');

-- 7) Aturan jurnal (debit_account_id, credit_account_id) ----------------------------------
-- Prestasi disetujui : WORK  Dr 1700 WIP / Cr 2110 ; PPN Dr 2400 / Cr 2110 ; PPH Dr 2110 / Cr 2130 ; RET Dr 2110 / Cr 2120 ; DPREC Dr 2110 / Cr 1410
-- Pembayaran         : DP Dr 1410 / Cr kas-bank ; PAY Dr 2110 / Cr kas-bank ; RET Dr 2120 / Cr kas-bank   (sisi kas-bank diganti saat posting)
INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT v.code, v.name, (SELECT id FROM chart_of_accounts WHERE account_code = v.dr), (SELECT id FROM chart_of_accounts WHERE account_code = v.cr), true
  FROM (VALUES
    ('SUBCON_WORK',        'Prestasi subkon - nilai pekerjaan (Dr WIP / Cr Hutang Subkon)',        '1700', '2110'),
    ('SUBCON_PPN',         'Prestasi subkon - PPN Masukan (Dr PPN Masukan / Cr Hutang Subkon)',    '2400', '2110'),
    ('SUBCON_PPH',         'Prestasi subkon - PPh final dipotong (Dr Hutang Subkon / Cr Hutang PPh)', '2110', '2130'),
    ('SUBCON_RETENTION',   'Prestasi subkon - retensi ditahan (Dr Hutang Subkon / Cr Hutang Retensi)', '2110', '2120'),
    ('SUBCON_DP_RECOVERY', 'Prestasi subkon - potongan uang muka (Dr Hutang Subkon / Cr Uang Muka Subkon)', '2110', '1410'),
    ('SUBCON_PAY_DP',      'Bayar uang muka subkon (Dr Uang Muka Subkon / Cr Kas-Bank)',           '1410', '1100'),
    ('SUBCON_PAY',         'Bayar prestasi subkon (Dr Hutang Subkon / Cr Kas-Bank)',               '2110', '1100'),
    ('SUBCON_PAY_RET',     'Bayar pelepasan retensi subkon (Dr Hutang Retensi / Cr Kas-Bank)',     '2120', '1100')
  ) AS v(code, name, dr, cr)
 WHERE NOT EXISTS (SELECT 1 FROM fiscal_config f WHERE f.rule_code = v.code);

-- 8) Menu (disalin dari menu PO: grup, departemen, format php_url / js_url) -----------------------
INSERT INTO m_form_name (group_id, seq, title, php_url, js_url, remark, department_id, main_table, tna, active)
SELECT f.group_id,
       (SELECT (MAX(CASE WHEN x.seq ~ '^[0-9]+$' THEN x.seq::int END) + 1)::text FROM m_form_name x WHERE x.group_id = f.group_id),
       'Subkontraktor',
       regexp_replace(f.php_url, '[^/]*$', 'subcon.php'),
       regexp_replace(f.js_url,  '[^/]*$', 'subcon.js'),
       'SPK subkontraktor per proyek / task, prestasi, retensi, pembayaran',
       f.department_id, 'subcon_contracts', f.tna, true
  FROM m_form_name f
 WHERE f.php_url ILIKE '%po.php' AND f.active
   AND NOT EXISTS (SELECT 1 FROM m_form_name WHERE title = 'Subkontraktor')
 LIMIT 1;

INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT a.user_id, (SELECT id FROM m_form_name WHERE title = 'Subkontraktor' ORDER BY id DESC LIMIT 1), a.access_level
  FROM m_user_access_menu a JOIN m_form_name f ON f.id = a.form_id
 WHERE f.php_url ILIKE '%po.php'
   AND NOT EXISTS (SELECT 1 FROM m_user_access_menu z WHERE z.user_id = a.user_id
                    AND z.form_id = (SELECT id FROM m_form_name WHERE title = 'Subkontraktor' ORDER BY id DESC LIMIT 1));

-- 9) Audit trigger: terapkan trigger data_log_* yang sama ke semua tabel baru (lihat definisi:
--    SELECT tgrelid::regclass, pg_get_triggerdef(oid) FROM pg_trigger WHERE NOT tgisinternal AND pg_get_triggerdef(oid) ILIKE '%if_modified_func%' LIMIT 5;)
