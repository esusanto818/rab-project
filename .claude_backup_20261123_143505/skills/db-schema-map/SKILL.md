---
name: db-schema-map
description: Peta skema database PostgreSQL proyekGMN_test, tipe data kolom kritis, daftar menu_id, dan pasangan file frontend-backend. Gunakan setiap kali mengaudit atau menambah fitur modul.
---

# Peta Skema Database & Modul (`proyekGMN_test`)

## 1. Koneksi Database Uji
- Database uji terisolasi: `proyekGMN_test` (gunakan konfigurasi koneksi di `php-func/function.all.php` atau `db_connection.PHP`).
- SEBELUM mempercayai hasil query atau menjalankan tes, pastikan DB yang dipakai aplikasi yang dibuka user sama dengan DB yang kamu periksa (baca konfigurasi koneksi di `function.all.php`); folder proyek dan DB bisa berbeda antara dev dan test.
- Sebelum menebak nama kolom atau tipe data, periksa skema aktual via CLI:
  `psql -U postgres -d proyekGMN_test -c "\d <nama_tabel>"`

## 2. Tabel Inti & Kolom Kritis
- **Purchase Order (Aktif):** `purchase_orders` & `po_items` (JANGAN gunakan legacy `po_hdr`/`po_dtl` atau `purchase.orderCRD.php`).
  - Alur status `purchase_orders`: `Draft` -> `Diajukan` -> `Disetujui` -> `Selesai` / `Dibatalkan`.
- **Inventory & Gudang:** `stock_movements` & `warehouses`
  - Saldo stok = `SUM(IN) - SUM(OUT)` per `item_id` (`materials.id`).
  - Isolasi proyek: hanya gunakan gudang dengan `warehouses.project_id = <project_id>` atau `warehouses.project_id IS NULL` (Gudang Pusat).
- **Periode Akuntansi & Konfigurasi:** `accounting_periods` & `fiscal_config`
  - Cek tipe data kolom `is_closed` di `accounting_periods` sebelum membandingkan (`boolean` vs `integer`/`string`).
  - Pastikan `PRJ_DEP_APPLY` pada `fiscal_config` terkonfigurasi sebelum posting jurnal.
- **Hak Akses Menu:** `m_user_access_menu`
  - Menu 24: Purchase Order (`views/po.php`, `js/po.js`, `php-crud/poCRD.php`)
  - Menu 138: Purchase Planning (`views/purchase.planning.php`, `js/purchase.planning.js`, `php-crud/purchase.planningCRD.php`)

## 3. Aturan Inspeksi Cepat
- Gunakan `rg` (ripgrep) terarah hanya pada file modul terkait di `views/`, `js/`, `js-func/`, `php-crud/`, `php-func/`, `pdf/`, dan `*.PHP`. JS halaman bisa inline di `views/*.php`.
