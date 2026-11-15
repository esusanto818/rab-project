# AUDIT CHANGELOG

Log perubahan kode setiap sesi audit / perbaikan bug / perubahan kode.
Format: Tanggal & Waktu sesi | Path file lengkap | Ringkasan perubahan (baris/fungsi) & alasan | Status Git.

---

## Sesi 2026-11-15 — Cek Inventory sebelum Purchase Planning / Purchase Order

**Permintaan:** tambahkan pengecekan inventory sebelum membuat purchase planning; cek apakah
material di inventory tersedia, dengan catatan material tsb BUKAN milik proyek lain;
planningkan sesuai kebutuhan (kebutuhan bersih = sisa kuota RAB − stok tersedia).

**Model data yang dipakai:**
- Ledger stok: `stock_movements` (item_id = materials.id; saldo = IN − OUT via `view_stock_balance`).
- Kepemilikan proyek: `warehouses.project_id`. Stok yang dapat dipakai proyek = stok di
  gudang milik proyek itu + gudang bersama (`warehouses.project_id IS NULL`, mis. Gudang Pusat).
  Stok di gudang milik PROYEK LAIN TIDAK dihitung.

### File yang diubah

| Tanggal & Waktu | Path file | Ringkasan perubahan & alasan | Status Git |
|-----------------|-----------|------------------------------|------------|
| 2026-11-15 10:50 | `php-func/function.all.php` | Tambah `GetProjectStockMap($db, $projectId)` setelah `GetStockBalance()` (sekitar baris 699–727): saldo stok per material yang dapat dipakai proyek (gudang milik proyek + gudang bersama), dipakai bersama oleh purchase planning & PO. | Committed |
| 2026-11-15 10:55 | `php-crud/purchase.planningCRD.php` | 1) Header docblock: catatan fitur pengecekan inventory. 2) `load_planning`: tambah kolom `qty_on_hand` & `qty_to_buy` (sisa perlu beli − stok tersedia); stok material ganda di RAB hanya dipakai sekali. 3) Task baru `check_inventory`: validasi rencana beli (sisa kuota RAB + stok tersedia + stok tidak ditindih antarbaris). 4) Inisialisasi `$db = new crud()` untuk helper PDO. | Committed |
| 2026-11-15 11:05 | `php-crud/poCRD.php` | 1) Fungsi baru `po_project_stock_map()` & `po_net_need()` (setelah `po_received_total()`). 2) `list_rab_items`: tambah kolom `qty_on_hand` & `qty_to_buy` per baris RAB. 3) `save_po`: validasi kebutuhan bersih — qty PO melebihi `sisa kuota RAB − stok tersedia` ditolak (stok proyek lain tidak dihitung). | Committed |
| 2026-11-15 11:10 | `js/purchase.planning.js` | 1) Panel detail: tambah kolom "Stok Tersedia" & "Perlu Beli (Net)"; material stoknya sudah mencukupi ditandai "Cukup stok" & checkbox dinonaktifkan. 2) Default qty input = `qty_to_buy` (kebutuhan bersih), bukan sisa RAB penuh. 3) Tombol "Cek Inventory" memanggil task `check_inventory` dan menampilkan hasilnya. | Committed |
| 2026-11-15 11:15 | `js/po.js` | 1) Store `rabDs`: tambah field `qty_on_hand` & `qty_to_buy`. 2) Grid item: tambah kolom "Stok Tersedia" & "Perlu Beli". 3) Saat material dipilih, qty default = `qty_to_buy` (kebutuhan bersih). | Committed |
| 2026-11-15 11:20 | `tests/run_e2e_inventory_check.php` | **BARU**: runner E2E Kelompok F (TC-101 s/d TC-110) terhadap `proyekGMN_test` + web 8098. Fixture: proyek P1+P9, material M1/M2, gudang WH-A (milik P1, stok M1=40) & WH-B (milik P9, stok M1=500). Menguji: load_planning stok benar, stok lintas proyek tidak dipinjam, check_inventory OK/needs-buy/over-quota, stok tidak ditindih antarbaris, list_rab_items stok, save_po terima qty = kebutuhan bersih & tolak yang melebihi. | Committed |
| 2026-11-15 11:20 | `test_report_inventory_check.md` | **BARU**: laporan hasil eksekusi TC-101..TC-110 (PASS=10, FAIL=0). | Committed |
| 2026-11-15 11:25 | `docs/HOWTO_CHECK_INVENTORY_PURCHASE_PLANNING_2026-11-15.md` | **BARU**: dokumen How-To fitur (alur, aturan stok, cara pakai, catatan teknis). | Committed |

### Verifikasi
- `php -l` bersih untuk semua file PHP yang diubah.
- `php tests/run_e2e_inventory_check.php` → **PASS=10 FAIL=0** (laporan: `test_report_inventory_check.md`).
- `php tests/run_e2e_release.php` → **PASS=52 FAIL=0** (tidak ada regresi di layer release tagihan).
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0** (lintas lapisan tetap bersih).

### Daftar file aktual di disk (git status -s)
```
 M js/po.js
 M js/purchase.planning.js
 M php-crud/poCRD.php
 M php-crud/purchase.planningCRD.php
 M php-func/function.all.php
 M test_report_tc001_065.md      (hasil run suite release: timestamp + id invoice fixture, bukan perubahan kode)
?? test_report_inventory_check.md
?? tests/run_e2e_inventory_check.php
?? AUDIT_CHANGELOG.md
?? docs/HOWTO_CHECK_INVENTORY_PURCHASE_PLANNING_2026-11-15.md
```
