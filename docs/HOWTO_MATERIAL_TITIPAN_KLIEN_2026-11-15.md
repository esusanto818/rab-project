# How-To: Pengelolaan Material Titipan Klien (`CLIENT_SUPPLIED` Rp 0)

Dokumen ini menjelaskan alur pencatatan material khusus/spesial milik klien pada proyek agar stok fisik tetap terlacak di gudang tanpa menimbulkan jurnal biaya/hutang rupiah maupun kebutuhan pembelian (PO).

## 1. Tujuan Fitur & Konteks Bisnis
- Fitur ini memproses material yang **dititipkan oleh klien** (`budget_items.material_source = 'CLIENT_SUPPLIED'`) agar diperlakuan sebagai biaya Rp 0.
- Material titipan klien: tidak perlu dibeli (tidak ada PO), biaya perolehan Rp 0, namun fisik masuk/keluar tetap tercatat di `stock_movements` gudang proyek.
- Tujuannya: melacak stok fisik klien tanpa mempengaruhi HPP proyek atau membuat hutang pembelian.

## 2. Tabel Perbandingan: Sebelum vs Sesudah

| Aspek | Material `COMPANY` (Lama) | Material `CLIENT_SUPPLIED` (Baru) |
|-------|---------------------------|-----------------------------------|
| Sumber material | Dibeli perusahaan | Disediakan klien |
| `qty_to_buy` di Planning | dihitung dari kebutuhan RAB | **Wajib 0** (skip total pembelian) |
| Pembuatan PO | Diterima & dihitung harga | **Diblokir** (save_po menolak) |
| Harga satuan (unit_cost) di GRN | Harga perolehan nyata | **Dipaksa 0** server-side |
| Jurnal GRN_RCV | Terposting (Dr Persediaan) | **Dilewati** (tanpa jurnal rupiah) |
| Jurnal ISSUE_PROJECT | Terposting ke HPP | **Dilewati** (tanpa mempengaruhi HPP) |
| Catatan stok fisik | Tercatat di `stock_movements` | **Masih tercatat** di `stock_movements` IN/OUT @ Rp 0 |

## 3. Langkah-langkah Migrasi SQL
Migrasi dilakukan dalam file `sql/2026-11-15_patch_gelombang2_client_supplied.sql`. Urutan idempoten:

1. **Menambah kolom** `material_source` ke `public.budget_items` dengan default `'COMPANY'`:
   ```sql
   ALTER TABLE public.budget_items
       ADD COLUMN IF NOT EXISTS material_source VARCHAR(20) NOT NULL DEFAULT 'COMPANY';
   ```
2. **Backfill data lama**: normalisasi nilai NULL atau luar daftar menjadi `'COMPANY'`:
   ```sql
   UPDATE public.budget_items
      SET material_source = 'COMPANY'
   WHERE material_source IS NULL
      OR UPPER(TRIM(material_source)) NOT IN ('COMPANY', 'CLIENT_SUPPLIED');
   ```
3. **Menambahkan constraint CHECK**: hanya izinkan `'COMPANY'` atau `'CLIENT_SUPPLIED'`:
   ```sql
   ALTER TABLE public.budget_items
       DROP CONSTRAINT IF EXISTS budget_items_material_source_check;
   ALTER TABLE public.budget_items
       ADD CONSTRAINT budget_items_material_source_check
       CHECK (material_source IN ('COMPANY', 'CLIENT_SUPPLIED'));
   ```
4. **Verifikasi**: pastikan kolom ada, default terpasang, dan tidak ada baris dengan nilai diluar daftar (dilakukan melalui blok `DO $$`).

Setelah migrasi, data lama otomatis mendapatkan `material_source = 'COMPANY'` dan baris baru untuk material titipan klien harus diisi `'CLIENT_SUPPLIED'` saat disimpan di RAB.

## 4. Cara Menjalankan Uji E2E (TC-CS-01 s/d TC-CS-12)
Pengujian otomatis mencakup 12 kasus uji end-to-end untuk fitur ini:

```bash
php tests/run_e2e_client_supplied.php
```

**Hasil yang diharapkan:** `PASS=12 FAIL=0`.

Laporan cetakan tersimpan ke `test_report_client_supplied.md`. Kasus uji mencakup:
- **TC-CS-01**: item RAB CLIENT_SUPPLIED tersimpan di `budget_items.material_source`.
- **TC-CS-02**: RAB non-titipan tetap `COMPANY` (kontrol).
- **TC-CS-03**: `load_planning` mengembalikan `qty_to_buy = 0` untuk CLIENT_SUPPLIED.
- **TC-CS-04**: `load_planning` untuk COMPANY menghitung kebutuhan beli normal (bukan 0).
- **TC-CS-05**: `poCRD list_rab_items` menampilkan `qty_to_buy = 0` & `material_source = CLIENT_SUPPLIED`.
- **TC-CS-06**: `save_po` menolak `budget_item CLIENT_SUPPLIED` (tidak boleh masuk PO pembelian).
- **TC-CS-07**: `save_po` menerima `budget_item COMPANY` sebagai kontrol (alur normal tidak terganggu).
- **TC-CS-08**: GRN untuk PO titipan klien memaksa `unit_cost = 0` server-side (kiriman nilai >0 diabaikan).
- **TC-CS-09**: WH Receiving Rp 0 -> `stock_movements IN` tercatat, **TANPA** jurnal `GRN_RCV`.
- **TC-CS-10**: Issuing Rp 0 -> `stock_movements OUT + material_out_items @ Rp 0`, **TANPA** jurnal `ISSUE_PROJECT`.
- **TC-CS-11**: stok gudang proyek lain TIDAK mengubah `qty_to_buy` CLIENT_SUPPLIED (tetap 0).
- **TC-CS-12**: `check_inventory` menandai CLIENT_SUPPLIED: `qty_to_buy = 0` + pesan "titipan klien".

## 5. Catatan Operasional
- **FIFO guard (`warehouse.issuingCRD.php`)**: FIFO mengeluarkan layer termurah dulu, sehingga layer Rp 0 titipan klien keluar lebih dulu dan HPP proyek = Rp 0 secara otomatis (tanpa perubahan alokasi). Bila `issueValue == 0` (material CLIENT_SUPPLIED), jurnal `ISSUE_PROJECT` di-skip (bukan error) agar WIP tidak tercemar jurnal Rp 0.
- **GRN/WH Receiving**: jurnal `GRN_RCV` hanya diposting bila `total_amount > 0`. Titipan klien (unit_cost dipaksa 0) -> total_amount = 0 -> jurnal di-skip (bukan error); stok fisik tetap tercatat IN.
- **Pesan `check_inventory`**: baris CLIENT_SUPPLIED menampilkan `material titipan klien (CLIENT_SUPPLIED): tidak dibeli, qty_to_buy = 0` (informasi, bukan error).
- **Ubah material_source**: Jika kolom `material_source` diubah dari `CLIENT_SUPPLIED` menjadi `COMPANY` (atau sebaliknya), sistem memperlakukan sesuai perilaku baru (hitung kebutuhan beli / blokir PO; unit_cost Rp 0 / harga normal). Perubahan hanya berpengaruh mulai data disimpan ulang (RAB/PO/GRN berikutnya).
- **Batasan**: Fitur ini hanya berlaku untuk kategori `Material` (`rabCRD.php` memaksa `material_source = 'COMPANY'` untuk kategori non-Material). Nilai di luar `COMPANY`/`CLIENT_SUPPLIED` tidak dapat tersimpan (constraint CHECK DB).
- **Data siap**: Pastikan migrasi `sql/2026-11-15_patch_gelombang2_client_supplied.sql` sudah diterapkan sebelum fitur diaktifkan di produksi. Bila kolom belum ada, RAB gagal menyimpan (`SQLSTATE[42703]` kolom tidak dikenal) — jalankan migrasi terlebih dahulu.
- **Debug**: Jika `qty_to_buy` muncul nilai aneh, periksa `budget_items.material_source` di DB dan pastikan migrasi constraint CHECK sudah terpasang.

## 6. Daftar File yang Diperbarui / Dibuat

| Path | Jenis | Ringkasan |
|------|-------|-----------|
| `sql/2026-11-15_patch_gelombang2_client_supplied.sql` | Migrasi SQL | Penambahan kolom `material_source`, constraint CHECK, backfill data lama |
| `php-crud/rabCRD.php` | Backend PHP | Menyimpan `material_source` ke `budget_items`, normalisasi nilai, integrasi dengan `qty_to_buy` |
| `php-crud/purchase.planningCRD.php` | Backend PHP | Mengembalikan `qty_to_buy = 0` dan `material_source` pada `load_planning` & `check_inventory`; menolak PO untuk CLIENT_SUPPLIED |
| `php-crud/poCRD.php` | Backend PHP | Validasi `save_po` menolak `budget_item CLIENT_SUPPLIED`; `list_rab_items` menampilkan `material_source` & `qty_to_buy` |
| `php-crud/grnCRD.php` | Backend PHP | Memaksa `unit_cost = 0` server-side untuk item `CLIENT_SUPPLIED` |
| `php-crud/whrcvCRD.php` | Backend PHP | Penerimaan fisik `IN` @ Rp 0, tanpa jurnal `GRN_RCV` |
| `php-crud/warehouse.issuingCRD.php` | Backend PHP | Pengeluaran fisik `OUT` @ Rp 0, tanpa jurnal `ISSUE_PROJECT` |
| `js/rab.js` | Frontend ExtJS | Menampilkan kolom `material_source` di form RAB; default `'COMPANY'` |
| `js/purchase.planning.js` | Frontend ExtJS | Menampilkan `material_source` & `qty_to_buy` di panel planning; menghitung ulang jika berubah |
| `js/po.js` | Frontend ExtJS | Menampilkan kolom `material_source` di grid PO; validasi tidak boleh menyimpan CLIENT_SUPPLIED ke PO |
| `tests/run_e2e_client_supplied.php` | Uji E2E | Runner 12 kasus (TC-CS-01 s/d TC-CS-12) untuk verifikasi fitur |
| `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` | Dokumentasi | File panduan ini |
