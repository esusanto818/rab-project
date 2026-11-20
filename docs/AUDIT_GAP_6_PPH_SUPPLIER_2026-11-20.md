# Implementasi Gap #6: PPh Supplier (Pemotongan PPh Invoice Supplier)

**Tanggal:** 2026-11-20 (Diperbarui dengan Integrasi UI ExtJS 3.4.1)
**Scope:** Penambahan kolom PPh (`pph_amount`, `pph_rate`) pada tabel `purchase_invoice_hdr`, penambahan Akun CoA dan Rule Jurnal pemotongan PPh Supplier (`PPH_SUPPLIER`), penyesuaian backend `php-crud/purchaseinvoiceCRD.php`, serta penambahan komponen UI ExtJS 3.4.1 (`js/purchaseinvoice.js`) dengan perhitungan otomatis PPh.

## 1. Rangkuman Perubahan Teknis

1. **Database / Skema**:
   - Menambahkan kolom `pph_amount` (NUMERIC(15,2) DEFAULT 0) dan `pph_rate` (NUMERIC(5,2) DEFAULT 0) pada tabel `purchase_invoice_hdr`.
   - Membuat akun baru di `chart_of_accounts`: `Hutang PPh Supplier` (Account Code `2140`, Tipe `liability`, Parent `2` / `LIABILITAS`).
   - Membuat rule penjurnalan otomatis baru di `fiscal_config`: `PPH_SUPPLIER` (Debit: `Hutang Usaha` / akun 25, Kredit: `Hutang PPh Supplier` / akun 54).

2. **Backend CRUD (`php-crud/purchaseinvoiceCRD.php`)**:
   - Menangkap parameter `pph_rate` dan `pph_amount` dari form/request frontend.
   - Menyertakan `pph_rate` dan `pph_amount` ke dalam query `SELECT` data grid dan operasi `UPDATE` pada `purchase_invoice_hdr`.
   - Memperbarui fungsi `PiPostJournal()` untuk menerima parameter `$pph` dan otomatis memanggil `AutoPostJournal()` dengan rule `PPH_SUPPLIER` bila `$pph > 0.005`.
   - Menggunakan penanganan transaksi PDO yang aman (`beginTransaction()`, `commit()`, `rollBack()`) dan perlindungan eksepsi.

3. **Frontend UI ExtJS 3.4.1 (`js/purchaseinvoice.js`)**:
   - Menambahkan field `pph_rate` (Tarif PPh %) dan `pph_amount` (Nominal PPh) pada `invoiceFields` (Store) dan form editor `frmEditor`.
   - Menambahkan fungsi perhitungan otomatis `recalcInvoicePph()` (`pph_amount = DPP * pph_rate / 100`) saat `pph_rate` atau rincian matched GRN berubah.
   - Menambahkan kolom `PPh (Rp)` pada `gridMain` dengan format mata uang yang rapi (`0,000.00`).

4. **Status Verifikasi**:
   - `php -l php-crud/purchaseinvoiceCRD.php` sukses (syntax valid).
   - Layout ExtJS 3.4.1 tetap rapi, stabil, dan terintegrasi penuh.
