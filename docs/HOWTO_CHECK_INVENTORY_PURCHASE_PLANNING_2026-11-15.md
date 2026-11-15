# How-To: Cek Inventory sebelum Purchase Planning / Purchase Order

**Tanggal:** 2026-11-15  
**Modul:** Purchase Planning (menu 138) & Purchase Order (menu 24)  
**File terkait:**
- `php-func/function.all.php` — `GetProjectStockMap()`
- `php-crud/purchase.planningCRD.php` — `load_planning`, `check_inventory`
- `php-crud/poCRD.php` — `list_rab_items`, `save_po`, `po_project_stock_map()`, `po_net_need()`
- `js/purchase.planning.js` — panel detail + tombol "Cek Inventory"
- `js/po.js` — grid item + default qty kebutuhan bersih
- `tests/run_e2e_inventory_check.php` — TC-101..TC-110 (E2E terhadap `proyekGMN_test`)

---

## 1. Tujuan

Sebelum membuat Purchase Planning / Purchase Order, sistem memeriksa inventory:
1. Apakah material yang dibutuhkan **tersedia** di stok.
2. Stok yang dihitung **hanya milik proyek ini** — stok di gudang proyek lain
   **tidak boleh** dipinjamkan.
3. Rencana beli dihitung dari **kebutuhan bersih** (sisa kuota RAB − stok tersedia),
   bukan dari sisa RAB penuh, sehingga tidak terjadi over-purchase.

## 2. Aturan stok yang dapat dipakai

| Sumber stok | Dihitung? | Alasan |
|-------------|-----------|--------|
| Gudang `warehouses.project_id = <proyek ini>` | ✅ Ya | Milik proyek ini |
| Gudang `warehouses.project_id IS NULL` (gudang bersama, mis. Gudang Pusat) | ✅ Ya | Tidak dibind ke proyek mana pun |
| Gudang `warehouses.project_id = <proyek lain>` | ❌ Tidak | Bukan milik proyek ini |

Saldo per material = `SUM(IN) − SUM(OUT)` dari `stock_movements`
(`item_id` merujuk `materials.id`).

**Kebutuhan bersih** (`qty_to_buy`) = `sisa kuota RAB − stok tersedia`,
dengan `sisa kuota RAB = qty_plan × (1 + tolerance_pct/100) − qty sudah di-PO`
(PO berstatus "Dibatalkan" tidak dihitung).

## 3. Alur di UI

### Purchase Planning (`purchase.planning.php`)
1. Klik baris proyek → panel rincian material muncul.
2. Kolom baru:
   - **Stok Tersedia** — saldo stok yang dapat dipakai proyek ini.
   - **Perlu Beli (Net)** — kebutuhan bersih (sisa perlu beli − stok).
   - Material yang stoknya sudah mencukupi ditandai **"Cukup stok"** (hijau) dan
     checkbox-nya tidak aktif — tidak perlu dibeli lagi.
3. Centang material, atur qty (default = Perlu Beli Net), lalu:
   - **Cek Inventory** — validasi awal: laporan per baris apakah stok mencukupi,
     berapa yang masih perlu dibeli, atau apakah qty melebihi kuota RAB.
   - **Buat PO dari Terpilih** — data dibawa ke halaman PO (`router?id=24`) lewat
     sessionStorage; qty yang dikirim sudah = kebutuhan bersih.

### Purchase Order (`po.php`)
1. Pilih proyek → combo material menampilkan label sisa RAB; kolom grid:
   **Stok Tersedia** dan **Perlu Beli** (kebutuhan bersih).
2. Saat material dipilih, qty otomatis = Perlu Beli (bukan sisa RAB penuh).
3. Saat save (`save_po`), backend menolak bila qty melebihi kebutuhan bersih:
   ```
   <material>: qty PO X melebihi kebutuhan bersih Y <unit>
   (sisa kuota RAB Z - stok tersedia W).
   ```
   Penolakan ini melindungi dari:
   - over-purchase melebihi kuota RAB, dan
   - pemakaian stok milik proyek lain (karena stok proyek lain tidak pernah
     mengurangi kebutuhan bersih).

## 4. Endpoint backend

### `POST /php-crud/purchase.planningCRD.php`
- `task=list_projects` — grid proyek (tidak berubah).
- `task=load_planning&project_id=N` — tambahan kolom per baris:
  `qty_on_hand` (stok tersedia), `qty_to_buy` (kebutuhan bersih).
- `task=check_inventory` dengan payload:
  ```json
  { "project_id": 8,
    "items": [ {"rab_material_id": 12, "material_id": 101, "qty": 60} ] }
  ```
  Respons per baris: `ok`, `qty_on_hand`, `qty_covered`, `qty_to_buy`, `message`.
  `ok=false` hanya bila qty melebihi kuota RAB; "perlu beli" adalah informasi, bukan error.

### `POST /php-crud/poCRD.php`
- `task=list_rab_items` — tambahan kolom `qty_on_hand`, `qty_to_buy`.
- `task=save_po` — validasi kebutuhan bersih di atas (di dalam transaksi, sebelum insert).

## 5. Catatan teknis
- `GetProjectStockMap()` di `php-func/function.all.php` adalah satu-satunya sumber
  angka stok untuk kedua modul (konsisten antara planning & PO).
- Dua baris RAB dengan material sama: stok hanya dipakai sekali (baris pertama
  yang mengklaim); sisa baris melihat sisa stok yang belum diklaim.
- Sistem PO aktif adalah `poCRD.php` (`purchase_orders`/`po_items`); file
  `purchase.orderCRD.php` (`po_hdr`/`po_dtl`) sudah tidak dipakai menu mana pun.
- Kompatibilitas PHP 7.4 (tidak ada `str_starts_with`/`str_contains`); semua query
  memakai prepared statement (`pg_query_params` / PDO prepare-execute).

## 6. Uji coba
```bash
php tests/run_e2e_inventory_check.php            # TC-101..TC-110 (PASS=10 FAIL=0)
php tests/run_e2e_release.php                    # regresi release tagihan (PASS=52 FAIL=0)
php tests/run_e2e_audit_sync.php                 # lintas lapisan (PASS=9 FAIL=0)
```
Laporan: `test_report_inventory_check.md`.
