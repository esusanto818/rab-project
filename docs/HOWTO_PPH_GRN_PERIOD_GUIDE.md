# HOW TO — PPh Supplier, GRN Approval Gate, & Period Closing Guide

**Tanggal:** 2026-11-20  
**Modul:** Gap #6 (PPh Supplier), Gap #7 (GRN Approval Gate), Gap #8 (Tutup Periode)  
**Teknologi:** PHP 7.4, PostgreSQL, ExtJS 3.4.1  

---

## 1. Ikhtisar Alur Kerja Baru

Panduan ini mencakup 3 fitur utama yang telah diimplementasikan dan di-hardened pada proyek **proyekGMN**:

1. **Gap #6: PPh Supplier (`purchaseinvoice`)**
   - Pemotongan PPh Pasal 23 / PPh Pasal 22 secara otomatis berdasarkan flag atau master rule supplier (`is_pph`, `pph_pct`).
   - Jurnal akuntansi otomatis mencatat akun Hutang PPh (WHT Payable) saat Purchase Invoice (PI) disetujui/disimpan.

2. **Gap #7: GRN Approval Gate (`grn` & `purchaseinvoice`)**
   - Goods Receipt Note (GRN) kini memiliki status approval (`PENDING`, `APPROVED`, `REJECTED`).
   - **Aturan Bisnis:** GRN berstatus `PENDING` atau `REJECTED` **tidak dapat** menambah stok gudang secara akuntansi/inventori dan **tidak dapat** ditarik sebagai dasar penagihan di Purchase Invoice (`PiGrnInfo`).
   - Hanya GRN dengan status `APPROVED` yang memicu mutasi stok dan tagihan vendor.

3. **Gap #8: Tutup Periode / Period Closing (`ensure_period_open`)**
   - Validasi ketat berbasis tanggal transaksi (`ensure_period_open($db, $date)`) pada seluruh backend CRUD (`php-crud/*.php`).
   - Transaksi dengan tanggal yang masuk pada periode akuntansi tertutup (`is_closed = true` di tabel `accounting_periods`) akan otomatis diblokir dengan pesan error: `Gagal: Periode akuntansi untuk tanggal YYYY-MM-DD sudah ditutup.`.

---

## 2. Petunjuk Penggunaan / Operasional

### A. Pengoperasian GRN Approval Gate
1. Buka menu **Inventory -> Goods Receipt Note (GRN)** (`views/grn.php`).
2. Buat GRN baru (status awal otomatis `PENDING`).
3. Pada status `PENDING`, klik tombol **Approve** di grid toolbar untuk menyetujui GRN.
4. Setelah berstatus `APPROVED`, stok otomatis masuk ke kartu stok/gudang dan dokumen siap ditarik di menu Purchase Invoice.

### B. Pengoperasian Purchase Invoice & PPh Supplier
1. Buka menu **Accounting/Finance -> Purchase Invoice** (`views/purchaseinvoice.php`).
2. Pilih GRN yang sudah berstatus `APPROVED`.
3. Masukkan komponen PPh Supplier (persentase / nilai potong PPh) jika vendor dikenakan pemotongan PPh.
4. Simpan. Sistem akan menghitung Net Payable = Subtotal + Pajak (PPn) - PPh Supplier dan menjurnal ke akun terkait.

### C. Pengoperasian Tutup Periode (Period Closing)
1. Buka menu **Accounting -> Fiscal Period / Tutup Periode**.
2. Pilih periode bulan/tahun yang ingin ditutup, lalu klik **Tutup Periode** (`is_closed = true`).
3. Apabila pengguna mencoba memasukkan atau mengedit transaksi (Kas/Bank, AR, AP, Jurnal, GRN, PI) pada tanggal dalam periode tersebut, sistem akan menolak transaksi secara hard-block.

---

## 3. Verifikasi & Troubleshooting

- **Cek Status Git:**
  ```bash
  git status -s
  git diff --stat
  ```
- **Uji Syntax PHP:**
  ```bash
  php -l php-crud/grnCRD.php
  php -l php-crud/purchaseinvoiceCRD.php
  php -l php-func/function.all.php
  ```
- **Log Sesi & Perubahan:** Periksa `docs/SESSION_LOG.md` untuk ringkasan perubahan baris kode dan commit hash terkait.
