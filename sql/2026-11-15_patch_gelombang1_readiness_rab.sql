-- =====================================================================
-- Patch Gelombang 1 — Data Readiness & RAB Guard (audit hulu-hilir 2026-11-15)
-- Item    : F-3.1 (reference_type material_leftover/subcon_leftover_buyback)
--           + F-5.1 (data readiness PRJ_DEP_APPLY)
--           + F-5.3 (samakan daftar kategori RAB DB vs PHP)
-- Target  : proyekGMN_test (dan produksi bila dijalankan ulang)
-- Idempoten: ya (DO-block pg_enum + ON CONFLICT DO UPDATE + drop constraint bila lama).
--
-- Rincian:
--  0. [PERBAIKAN ASUMSI AUDIT §9.2/§10] journal_entries.reference_type ternyata bertipe
--     ENUM public.reference_type (BUKAN varchar). Maka F-3.1 butuh ALTER TYPE ADD VALUE
--     untuk 'material_leftover' & 'subcon_leftover_buyback'. ALTER TYPE ADD VALUE tidak boleh
--     berjalan di dalam blok transaksi yang lalu-lalang padanya -> bagian ini mandiri
--     (tanpa BEGIN/COMMIT di sekitarnya) dan idempoten via catalog pg_enum.
--     Daftar di PHP (JRN_VALID_REF_TYPES di function.all.php & journal.stock.php) juga
--     menambahkan 'subcon_invoice'/'subcon_invoice_pay' yang sudah dipakai
--     subcon.invoiceCRD.php tetapi belum ada di enum (celah tersendiri, ditutup di sini).
--  1. Pastikan rule PRJ_DEP_APPLY ada di fiscal_config (pemakaian deposit
--     klien untuk bayar termin: Dr Uang Muka Penjualan / Cr Piutang Usaha,
--     tanpa kas/bank -- sesuai rancangan audit §9.4 & project.paymentCRD.php:763).
--     Akun: 26 (2200 Uang Muka Penjualan) & 20 (1200 Piutang Usaha).
--     Bila aturan sudah ada tapi akunnya menyimpang, koreksi ke pasangan ini.
--  2. F-5.3: samakan CHECK kategori DB dengan daftar PHP (RAB_CATEGORIES =
--     Material/Upah/Peralatan/Transport/Konsumsi/Lain-lain) tanpa demote diam-diam.
--     Data lama berkategori 'Alat' (bila ada) dipetakan dulu ke 'Peralatan'.
-- =====================================================================

-- 0) Tambah nilai enum reference_type yang belum terdaftar (idempoten via pg_enum)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'reference_type' AND e.enumlabel = 'material_leftover') THEN
        ALTER TYPE public.reference_type ADD VALUE 'material_leftover';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'reference_type' AND e.enumlabel = 'subcon_leftover_buyback') THEN
        ALTER TYPE public.reference_type ADD VALUE 'subcon_leftover_buyback';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'reference_type' AND e.enumlabel = 'subcon_invoice') THEN
        ALTER TYPE public.reference_type ADD VALUE 'subcon_invoice';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'reference_type' AND e.enumlabel = 'subcon_invoice_pay') THEN
        ALTER TYPE public.reference_type ADD VALUE 'subcon_invoice_pay';
    END IF;
END $$;

BEGIN;

-- 1) PRJ_DEP_APPLY -- insert bila belum ada, koreksi akun bila sudah ada tapi berbeda
INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PRJ_DEP_APPLY',
       'Pemakaian deposit client untuk bayar termin',
       d.id, c.id, true
  FROM chart_of_accounts d, chart_of_accounts c
 WHERE d.account_code = '2200' AND c.account_code = '1200'
 ON CONFLICT (rule_code) DO UPDATE
    SET rule_name          = EXCLUDED.rule_name,
        debit_account_id   = EXCLUDED.debit_account_id,
        credit_account_id  = EXCLUDED.credit_account_id,
        is_active          = EXCLUDED.is_active
  WHERE fiscal_config.debit_account_id  <> EXCLUDED.debit_account_id
     OR fiscal_config.credit_account_id <> EXCLUDED.credit_account_id
     OR fiscal_config.is_active        <> EXCLUDED.is_active;

-- 2) Verifikasi pasangan akun benar (Dr 2200 / Cr 1200, aktif)
DO $$
DECLARE
  n integer;
BEGIN
  SELECT COUNT(*) INTO n
    FROM fiscal_config f
    JOIN chart_of_accounts d ON d.id = f.debit_account_id
    JOIN chart_of_accounts c ON c.id = f.credit_account_id
   WHERE f.rule_code = 'PRJ_DEP_APPLY'
     AND d.account_code = '2200' AND c.account_code = '1200'
     AND f.is_active = true;
  IF n <> 1 THEN
    RAISE EXCEPTION 'PRJ_DEP_APPLY tidak valid di fiscal_config (harus Dr 2200 / Cr 1200, aktif). Temuan % baris.', n;
  END IF;
END $$;

-- =====================================================================
-- F-5.3: Samakan daftar kategori DB dengan RAB_CATEGORIES di PHP.
-- CHECK lama mengenal 'Alat'; PHP mengenal 'Peralatan' -> input 'Peralatan'
-- sempat di-demote diam-diam ke 'Lain-lain' (dan baris material dibuang).
-- Solusi paling sederhana: ubah CHECK ke daftar PHP, petakan 'Alat'->'Peralatan'.
-- =====================================================================

-- 2a) Petakan data lama 'Alat' -> 'Peralatan' (bila ada)
UPDATE budget_items SET category = 'Peralatan' WHERE category = 'Alat';

-- 2b) Ganti CHECK constraint dengan daftar kategori yang sama persis dengan RAB_CATEGORIES (rabCRD.php:28)
ALTER TABLE budget_items DROP CONSTRAINT IF EXISTS budget_items_category_check;
ALTER TABLE budget_items
  ADD CONSTRAINT budget_items_category_check
  CHECK (category IN ('Material', 'Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'));

-- 2c) expense_master.tidak punya CHECK; petakan 'Alat' lama dan tambah CHECK agar tak pernah masuk lagi
--     (mpExpCategory di m.proyekCRD.php kini menolak kategori tak dikenal alih-alih demote diam-diam).
UPDATE expense_master SET category = 'Peralatan' WHERE category = 'Alat';
ALTER TABLE expense_master DROP CONSTRAINT IF EXISTS expense_master_category_check;
ALTER TABLE expense_master
  ADD CONSTRAINT expense_master_category_check
  CHECK (category IN ('Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'));

-- 2d) [PERBAIKAN BUG TERKAIT F-5.3] rabCRD.php save_rab path UPDATE non-material memakai kolom
--     updated_at yang TIDAK ADA di budget_items -> SQLSTATE[42703] bila expense RAB diperbarui.
--     Tambah kolom updated_at + trigger pemelihara otomatis (idempoten).
ALTER TABLE budget_items ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone;

CREATE OR REPLACE FUNCTION fn_budget_items_touch() RETURNS trigger AS $$
BEGIN
  NEW.updated_at := NOW();
  RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_budget_items_touch ON budget_items;
CREATE TRIGGER trg_budget_items_touch BEFORE UPDATE ON budget_items
  FOR EACH ROW EXECUTE PROCEDURE fn_budget_items_touch();   -- PG 9.6: EXECUTE PROCEDURE

COMMIT;
