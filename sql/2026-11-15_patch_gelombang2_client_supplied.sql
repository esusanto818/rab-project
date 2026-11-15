-- =====================================================================
-- Patch Gelombang 2 — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0
-- Item    : F-4.1 (audit hulu-hilir 2026-11-15, §9.3)
-- Target  : proyekGMN_test (dan produksi bila dijalankan ulang)
-- Idempoten: ya (ADD COLUMN IF NOT EXISTS + drop constraint bila lama).
--
-- Rincian:
--  1. Tambah 1 kolom flag di RAB: budget_items.material_source.
--     Kepemilikan bersifat PER-PROYEK (bukan per-material), maka kolom
--     diletakkan di budget_items (bukan di materials).
--     COMPANY = material dibeli perusahaan (perilaku lama, default).
--     CLIENT_SUPPLIED = titipan klien: qty_to_buy wajib 0, harga beli 0,
--     jurnal rupiah di-skip, fisik tetap tercatat di stock_movements.
--  2. Backfill data lama ke 'COMPANY' agar CHECK langsung valid.
-- =====================================================================

BEGIN;

-- 1) Kolom flag sumber material (idempoten).
ALTER TABLE public.budget_items
    ADD COLUMN IF NOT EXISTS material_source VARCHAR(20) NOT NULL DEFAULT 'COMPANY';

-- 2) Normalisasi data lama / bila ada nilai lain di luar daftar -> COMPANY.
UPDATE public.budget_items
   SET material_source = 'COMPANY'
 WHERE material_source IS NULL
    OR UPPER(TRIM(material_source)) NOT IN ('COMPANY', 'CLIENT_SUPPLIED');

-- 3) Constraint nilai hanya COMPANY / CLIENT_SUPPLIED.
ALTER TABLE public.budget_items
    DROP CONSTRAINT IF EXISTS budget_items_material_source_check;
ALTER TABLE public.budget_items
    ADD CONSTRAINT budget_items_material_source_check
    CHECK (material_source IN ('COMPANY', 'CLIENT_SUPPLIED'));

-- 4) Verifikasi: kolom ada, default COMPANY, CHECK terpasang, tidak ada nilai lain.
DO $$
DECLARE
    n_col   integer;
    n_bad   integer;
BEGIN
    SELECT COUNT(*) INTO n_col
      FROM information_schema.columns
     WHERE table_schema = 'public' AND table_name = 'budget_items'
       AND column_name = 'material_source';
    IF n_col <> 1 THEN
        RAISE EXCEPTION 'Kolom budget_items.material_source tidak ditemukan.';
    END IF;
    SELECT COUNT(*) INTO n_bad
      FROM public.budget_items
     WHERE material_source NOT IN ('COMPANY', 'CLIENT_SUPPLIED');
    IF n_bad <> 0 THEN
        RAISE EXCEPTION 'Ada % baris budget_items dengan material_source di luar daftar.', n_bad;
    END IF;
END $$;

COMMIT;
