# BUSINESS GAP REPORT — SIKLUS AR & AP + INTEGRASI GL

**Tanggal:** 2026-11-17
**Scope:** Siklus AR (Tagihan Klien, Retensi, Pembayaran Termin, Kartu & Aging Piutang) dan siklus AP (PO, GRN, Kontrabon Supplier, PPh/PPN, Kartu & Aging Hutang) serta integrasi jurnal otomatisnya.
**Database acuan:** PostgreSQL `proyekGMN`.
**Metode:** audit kode statis lintas lapisan (`php-crud/`, `js/`, `views/`, `php-func/`, `php-class/`, `pdf/`, `sql/`) + verifikasi data aktif.
**Status:** LAPORAN ANALISIS—tidak ada perubahan data atau kode.

---

## 0. RINGKASAN EKSEKUTIF

Siklus AR dan AP sudah tersedia sebagai modul operasional dan sebagiannya sudah terintegrasi ke GL. Namun kualitas implementasi masih perlu diperbaiki agar dapat dipakai aman sebagai sistem akuntansi konstruksi.

### Poin positif
- Tagihan termin klien dapat dibuat, direlease, dan dijurnal.
- Pembayaran termin klien dapat dialokasikan ke banyak invoice dan mendukung deposit serta mata uang.
- Invoice supplier dapat dicocokkan dengan GRN, PPN, dan jurnal hutang.
- Pembayaran supplier dapat dialokasikan ke banyak invoice dan mendukung deposit supplier.
- Retensi subkon dan integrasi inventory/jurnal stock sudah tersedia pada modul subkon.
- Ada mekanisme reverse jurnal saat invoice/pembayaran dibatalkan atau dihapus.

### Temuan utama
1. Perlu ada kartu AR/AP resmi dan aging berbasis sub-ledger per counterparty.
2. Retensi klien belum tersedia sebagai model dokumentasi dan jurnal yang konsisten.
3. Penutupan periode masih tidak lengkap; posting ke periode lampau perlu diblokir.
4. Beberapa migration penting belum terbukti aktif pada database.
5. Kontrabon belum mempunyai nama workflow yang konsisten; invoice supplier berperan sebagai kontrabon.
6. PPh supplier belum dimodelkan secara utuh.
7. Perlu reconciliations report antara saldo sub-ledger dan akun GL.

---

## 1. STATUS AR YANG SUDAH TERSEDIA

| Tahap | Status | Evaluasi |
|---|---|---|
| RAB & Kontrak Proyek | Ada | Kontrak dan nilai proyek tersedia; lifecycle kontrak klien masih perlu diperjelas |
| SPK | Ada | SPK dan task tersedia |
| Progress | Ada | Progress, usage, dan WIP tersedia |
| Invoice Termin Klien | Ada | Mendukung DPP, PPN, faktur pajak, tanggal jatuh tempo, release, dan jurnal |
| Retensi Klien | Belum lengkap | Belum ada kolom dan rule retensi pada invoice klien |
| Pembayaran Termin | Ada | Mendukung multi-proyek, deposit, kurs, dan alokasi termin |
| Deposit Klien | Ada | Memerlukan rule jurnal deposit yang aktif dan konsisten |
| Aging AR | Sebagian | Ada agregat dashboard, belum ada aging per invoice dan per klien |
| Kartu Piutang | Belum tersedia | Tidak ada detail mutasi piutang per klien |
| Jurnal AR | Ada | Invoice dan pembayaran memposting jurnal, reversal tersedia |

## 2. STATUS AP YANG SUDAH TERSEDIA

| Tahap | Status | Evaluasi |
|---|---|---|
| RAB/RAP ke Rencana Beli | Ada Sebagian | Purchase planning tersedia, tetapi alur MR formal masih perlu disempurnakan |
| PO | Ada | PO mendukung currency, PPN, dan alokasi proyek |
| GRN | Ada Sebagian | Penerimaan tersedia, namun approval gate perlu diuji pada database aktif |
| Invoice/kontrabon Supplier | Ada | Berfungsi sebagai matching GRN dan invoice supplier |
| PPN Masukan | Ada | PPN dan laporan PPN tersedia |
| PPh Supplier | Belum lengkap | PPh supplier biasa/badan belum dimodelkan penuh |
| Pembayaran Hutang | Ada | Alokasi dan deposit supplier tersedia |
| Aging AP | Sebagian | Data aggregat tersedia, belum ada aging per invoice/supplier |
| Kartu Hutang | Belum tersedia | Tidak ada ledger mutasi hutang per supplier |
| Jurnal AP | Ada | Invoice, retur, dan payment memiliki jurnal/reversal |

---

## 3. MASUKAN PERBAIKAN UTAMA

### 3.1 AR — Prioritas Kritis

1. **Buat Kartu Piutang sebagai sub-ledger resmi**
   - Data mutasi: opening balance, invoice, payment, credit note, write-off, dan reversal.
   - Saldo klien diturunkan dari mutasi, bukan dihitung ulang berulang dari view UI.
   - Setiap mutasi menyimpan `counterparty_id`, `invoice_id`, `journal_id`, tanggal, mata uang, kurs, dan status.

2. **Buat Aging Piutang per invoice dan per klien**
   - bucket `belum jatuh tempo`, `1–30`, `31–60`, `61–90`, dan `>90 hari`.
   - Gunakan due date dan paid balance, dengan cut-off tanggal sebagai parameter.
   - Pisahkan aging DPP/PPN bila diperlukan pelaporan pajak.

3. **Lengkapi retensi klien**
   - Tambahkan retensi `%`, nilai retensi, nilai net invoice, release status, dan tanggal release.
   - Posting saat penagihan: Dr Piutang Bruto / Cr Pendapatan / Cr Hutang Retensi (sesuai kebijakan akun).
   - Pembayaran retensi harus memakai aturan reversal jurnal yang terpisah dari invoice utama.

4. **Normalisasi status invoice**
   - Gunakan satu status tunggal, misalnya `draft`, `released`, `partial`, `paid`, `overdue`, `cancelled`.
   - Hindari status invoice bergantung pada hasil perhitungan langsung saja.

5. **Perbaiki deposit klien**
   - Pastikan rule `PRJ_DEP_APPLY` aktif dan mencegah pemakaian deposit melebihi saldo tersedia.
   - Pembatalan pembayaran yang menggunakan deposit harus memperbaiki ledger dan menolak jika deposit sudah dipakai transaksi lain.

### 3.2 AP — Prioritas Kritis

1. **Buat Kartu Hutang sebagai sub-ledger resmi**
   - Mutasi: invoice supplier, PPN masukan, payment, deposit, retur supplier, credit note, dan reversal.
   - Jangan menghitung hutang hanya dari selisih total invoice dan paid amount.

2. **Buat Aging Hutang per supplier dan per invoice**
   - Gunakan due date invoice/kontrabon.
   - Kelompokkan supplier lokal, proyek, mata uang, dan status disputed bila tersedia.

3. **Sempurnakan PPh supplier**
   - Tambahkan DPP PPh, tarif PPh, nilai dipotong, bukti potong, tanggal bukti potong, dan status posted.
   - Aturan jurnal memisahkan Hutang Supplier, Hutang PPh, dan Kas/Bank.

4. **Jadikan GRN sebagai approval gate**
   - Terangkan status draft, submitted, approved, rejected, dan cancelled.
   - Invoice supplier harus menolak GRN yang belum approved.

5. **Tetapkan alur Kontrabon/Invoice Supplier**
   - Gunakan istilah yang konsisten: `Kontrabon / Purchase Invoice` sebagai satu dokumen.
   - Pembayaran harus hanya boleh alokasi ke invoice posted yang valid.

6. **Protect invoice dan payment dengan row lock**
   - Pastikan perubahan concurrent tidak menghasilkan paid_amount atau alokasi berlebih.
   - Gunakan pg advisory lock atau DB lock pada supplier/invoice selama save/delete.

### 3.3 Integrasi GL dan Close Period

1. **Pastikan satu journal hanya dipost satu kali**
   - Gunakan unique reference per business document dan event: `(reference_type, reference_id, rule_code)`.
   - Reversal harus menghapus atau membalik jurnal secara terarah, bukan mengubah jurnal posted.

2. **Tambah reconciliation report**
   - Bandingkan saldo AR subledger versus akun piutang di GL.
   - Bandingkan saldo AP subledger versus akun hutang di GL.
   - Report menampilkan selisih, dokumen penyebab, dan status matched.

3. **Implementasi tutup periode bulanan**
   - `open → soft close → hard close → reopen`.
   - Blokir save jurnal pada periode closed.
   - Simpan reason dan user pada reopen.

4. **Rapikan fiscal_config**
   - Pastikan semua rule yang dipanggil kode sudah aktif dan mempunyai akun debit/kredit valid.
   - Tambahkan test: rule missing, account missing, inactive rule, dan unbalanced journal.

5. **Uji reversal pada semua transaksi**
   - Invoice create/update/delete.
   - Payment create/update/delete.
   - Retensi dan deposit.
   - Invoice supplier dan supplier payment.

---

## 4. PERBAIKAN TEKNIS

### Database
- Terapkan migration yang belum berjalan pada DB aktif.
- Tambahkan constraint unik pada no. invoice, no. payment, dan no. faktur yang benar sesuai jenis dokumen.
- Tambahkan `due_date`, aging snapshot, dan foreign key yang masih kurang.
- Tambahkan indeks untuk `(customer_id, due_date)`, `(supplier_id, due_date)`, dan `journal_entries(reference_type, reference_id)`.
- Gunakan numeric/decimal untuk semua nilai keuangan, jangan float pada service/backend.

### Backend
- Pakai satu pola respons JSON agar frontend dapat menangani error konsisten.
- Semua operasi financial write dilakukan dalam satu transaksi DB.
- Pisahkan validasi bisnis dari kode dispatch CRUD.
- Sediakan helper untuk outstanding balance, aging, posting, reversal, dan reconciliation.

### ExtJS UI
- Grid AR/AP harus menampilkan invoice, counterparty, due date, amount, paid, remaining, dan status aging.
- Saat pembayaran, tunjukkan outstanding sebelum dan sesudah alokasi.
- Blokir input PPN/PPh yang tidak konsisten dengan PO/kontrak.
- Konfirmasi sebelum cancel/delete dan tampilkan dampak reversal.

### Laporan
- Kartu AR per klien.
- Aging AR detail dan summary.
- Kartu AP per supplier.
- Aging AP detail dan summary.
- Rekap PPN masukan/keluaran.
- Bukti PPh dan withholding report.
- Subledger-to-GL reconciliation.

---

## 5. DAFTAR PILIHAN PERBAIKAN AR/AP

| No | Perbaikan | Prioritas | Cakupan |
|---:|---|---|---|
| 1 | Kartu Piutang & Aging AR | P0/Kritis | DB + Backend + UI ExtJS + Laporan |
| 2 | Kartu Hutang & Aging AP | P0/Kritis | DB + Backend + UI ExtJS + Laporan |
| 3 | Reconciliation Subledger vs GL | P0/Kritis | DB + Backend + UI ExtJS + Laporan |
| 4 | Retensi Klien | P1/Tinggi | DB + Backend + UI ExtJS + Laporan |
| 5 | PPh Supplier/Bukti Potong | P1/Tinggi | DB + Backend + UI ExtJS + Laporan |
| 6 | GRN Approval Gate | P1/Tinggi | DB + Backend + UI ExtJS + Laporan |
| 7 | Monthly Period Closing | P0/Kritis | DB + Backend + UI ExtJS + Laporan |
| 8 | Deposit AR/AP & Reversal Rule | P0/Kritis | DB + Backend + UI ExtJS + Laporan |
| 9 | AR/AP Integration Test | P1/Tinggi | DB + Backend + Test + Laporan |
| 10 | Pembersihan Konflik & Coding Konsistensi | P2/Sedang | Backend + UI ExtJS |

## 6. URUTAN IMPLEMENTASI

1. Stabilkan jurnal dan fiscal_config: PRJ_DEP_APPLY, deposit, missing rule, period lock.
2. Terapkan migration approval GRN dan guard over-allocation.
3. Bangun reconciliation subledger vs GL.
4. Bangun kartu dan aging AR/AP.
5. Tambahkan retensi klien dan PPh supplier.
6. Lengkapi monthly closing dan regression test.

## 7. Kesimpulan

AR dan AP sudah mencapai tingkat implementasi operasional, bukan sekadar master menu. Fondasi yang paling kuat ada pada invoice, payment, alokasi, PPN, dan auto-journal. Gap yang paling penting adalah mengubah status bagian-bagian tersebut menjadi sub-ledger yang terukur, memiliki aging, reconciliation, approval, period control, dan reversal yang teruji.
