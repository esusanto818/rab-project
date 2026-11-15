# How-To: Pengelolaan Material Titipan Klien (`CLIENT_SUPPLIED` Rp 0)

Dokumen ini menjelaskan alur pencatatan material khusus/spesial milik klien pada proyek agar stok fisik tetap terlacak di gudang tanpa menimbulkan jurnal biaya/hutang rupiah maupun kebutuhan pembelian (PO).

## 1. Penandaan di RAB (`Menu RAB`)
- Saat menambahkan item material pada RAB proyek, pilih kolom **Sumber Material** (`material_source`):
  - **`COMPANY` (Perusahaan):** Material dibeli oleh perusahaan, dihitung kebutuhan belinya di Purchase Planning/PO, dan dicatat dengan harga perolehan.
  - **`CLIENT_SUPPLIED` (Titipan Klien):** Material disediakan langsung oleh klien.

## 2. Pengecualian Otomatis pada Purchase Planning & PO
- Item berstatus `CLIENT_SUPPLIED` otomatis memiliki kebutuhan beli **`qty_to_buy = 0`** pada modul Purchase Planning (`purchase.planningCRD.php`) dan Purchase Order (`poCRD.php`).
- Sistem akan otomatis memblokir apabila user mencoba membuat PO pembelian untuk item titipan klien.

## 3. Penerimaan & Pengeluaran Fisik Rp 0 (Tanpa Jurnal)
- **Penerimaan Gudang (`GRN` / `Warehouse Receiving`):** Sistem otomatis memaksa `unit_cost = 0`, mencatat stok masuk (`IN`) ke `stock_movements` gudang proyek, dan melewati posting jurnal rupiah.
- **Pengeluaran ke Proyek (`Warehouse Issuing`):** Pemakaian material ke lapangan memotong saldo fisik (`OUT`) pada `stock_movements` dengan nilai Rp 0 dan melewati jurnal `ISSUE_PROJECT` sehingga tidak mencemari HPP proyek.

## 4. Verifikasi Otomatis
- Skrip uji E2E: `tests/run_e2e_client_supplied.php` (`TC-CS-01` s/d `TC-CS-12`, lulus `FAIL=0`).
