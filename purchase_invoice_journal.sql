-- 2026-10-29.purchase_invoice_journal.sql
-- Jurnal invoice supplier: tambah nilai 'purchase_invoice' pada enum journal_entries.reference_type.
-- Jalankan SEBELUM menyimpan / mengedit invoice supplier dengan purchaseinvoiceCRD.php versi baru.
-- Jalankan dalam mode AUTO-COMMIT (DBeaver: "Auto"), bukan di dalam BEGIN ... COMMIT (ALTER TYPE ... ADD VALUE).

-- Langkah 1: lihat nama tipe enum & nilainya
SELECT t.typname, string_agg(e.enumlabel, ', ' ORDER BY e.enumsortorder) AS nilai
  FROM pg_attribute a
  JOIN pg_class c ON c.oid = a.attrelid
  JOIN pg_type t ON t.oid = a.atttypid
  JOIN pg_enum e ON e.enumtypid = t.oid
 WHERE c.relname = 'journal_entries' AND a.attname = 'reference_type'
 GROUP BY t.typname;

-- Langkah 2: ganti <nama_tipe> dengan hasil langkah 1, lalu jalankan (jika 'purchase_invoice' sudah ada, aman diulang)
-- ALTER TYPE <nama_tipe> ADD VALUE IF NOT EXISTS 'purchase_invoice';
