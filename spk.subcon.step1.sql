-- spk.subcon.step1.sql
-- SPK Internal / Subkon - Langkah 1 (docs/SUBCONTRACT_MANAGEMENT_DESIGN.md)
--
-- DDL SPK subkon (kolom spk_type, execution_subcontractor_id, tabel spk_work_items, dst) SUDAH ada di database dev
-- Anda, jadi file ini hanya melengkapi satu hal yang masih kurang: nilai enum jurnal untuk jalur prestasi/pembayaran
-- subkon. Tanpa ini, menyetujui prestasi subkon ditolak: "Tipe jurnal 'subcon_progress' belum terdaftar".
--
-- PENTING: jalankan dua baris ALTER TYPE di bawah satu per satu, TANPA BEGIN/COMMIT.
-- Nama tipe yang benar adalah public.reference_type. subcon_invoice.sql memakai nama salah
-- (journal_reference_type) sehingga blok DO-nya tidak melakukan apa-apa.
--
-- Nilai untuk jalur invoice (subcon_invoice, subcon_invoice_pay) SENGAJA belum ditambahkan:
-- jalur itu menunggu keputusan desain (invoice tidak boleh membuka WIP lagi), lihat penjelasan di chat.

ALTER TYPE public.reference_type ADD VALUE IF NOT EXISTS 'subcon_progress';
ALTER TYPE public.reference_type ADD VALUE IF NOT EXISTS 'subcon_payment';

-- Cek hasil: harus muncul subcon_progress dan subcon_payment
SELECT e.enumlabel FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
 WHERE t.typname = 'reference_type' AND e.enumlabel LIKE 'subcon%' ORDER BY e.enumsortorder;
