-- ============================================================
-- PPN - nilai enum journal_entries.reference_type untuk jurnal PPN Masukan invoice supplier.
-- JALANKAN TERPISAH, DI LUAR TRANSAKSI (PostgreSQL 9.6: ALTER TYPE ... ADD VALUE tidak boleh di dalam BEGIN/COMMIT).
-- 'project_invoice' (jurnal PPN Keluaran) sudah ada karena dipakai jurnal termin yang sekarang.
-- ============================================================

-- Langkah 1: cari nama tipe enum kolom reference_type
SELECT t.typname
  FROM pg_attribute a
  JOIN pg_class c ON c.oid = a.attrelid
  JOIN pg_type  t ON t.oid = a.atttypid
 WHERE c.relname = 'journal_entries' AND a.attname = 'reference_type';

-- Langkah 2: lihat nilai yang sudah ada (ganti <nama_enum> dengan hasil langkah 1)
-- SELECT enumlabel FROM pg_enum WHERE enumtypid = '<nama_enum>'::regtype ORDER BY enumsortorder;

-- Langkah 3: tambahkan nilai baru bila belum ada
-- ALTER TYPE <nama_enum> ADD VALUE IF NOT EXISTS 'purchase_invoice_ppn';
