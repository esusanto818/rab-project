-- ============================================================
-- Migrasi: rentang harga MIN - MAX di MASTER (material & biaya)
-- Aturan   : harga MAX = materials.estimated_price / expense_master.default_price (kolom yang sudah ada)
--            harga MIN = kolom baru di bawah.
--            Harga satuan RAB (budget_items.unit_price_plan) = default MAX; user boleh mengubahnya di RAB
--            selama masih di antara MIN dan MAX master (rentang berlaku bila MIN > 0 dan MAX >= MIN).
-- Dipakai  : m_proyekCRD.php (analisis AI), rabCRD.php + rab.js (batas edit harga)
-- PostgreSQL 9.6+. Aman dijalankan ulang. Data lama TIDAK diubah (MIN kosong = belum ada rentang = tanpa batas).
-- ============================================================
BEGIN;

ALTER TABLE public.materials      ADD COLUMN IF NOT EXISTS estimated_price_min numeric(15,2);
ALTER TABLE public.expense_master ADD COLUMN IF NOT EXISTS default_price_min   numeric(15,2);

COMMENT ON COLUMN public.materials.estimated_price_min      IS 'Harga terendah; estimated_price = harga tertinggi (MAX). Harga satuan RAB default = MAX, boleh diubah di antara MIN dan MAX';
COMMENT ON COLUMN public.expense_master.default_price_min   IS 'Harga terendah; default_price = harga tertinggi (MAX). Harga satuan RAB default = MAX, boleh diubah di antara MIN dan MAX';

COMMIT;

-- OPSIONAL: bila versi migrasi sebelumnya sempat dijalankan, dua kolom ini tidak dipakai lagi (rentang kini milik master).
-- Hapus hanya setelah yakin tidak ada kode lain yang membacanya:
-- ALTER TABLE public.budget_items DROP COLUMN IF EXISTS unit_price_min, DROP COLUMN IF EXISTS unit_price_max;
