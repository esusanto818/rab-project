# PRE_DELIVERY_01B_MASTER_RAB_PROC_INV.md

## 1. Lingkup Audit
Audit Master Data, RAB, Procurement, Inventory.
- File audit utama: `php-crud/poCRD.php`, `php-crud/rabCRD.php`, `php-crud/purchase.planningCRD.php`, `php-crud/grnCRD.php`.

## 2. Pemeriksaan Keamanan & Logika Bisnis

### 2.1 RAB (`rabCRD.php`)
- **Validasi:** Menggunakan `rabNormCategory` dan `rabNormSource` untuk validasi input ketat (case-insensitive).
- **Patch F-4.1:** Dukungan `CLIENT_SUPPLIED` (material titipan) sudah diimplementasikan, memaksa `qty_to_buy = 0` dan harga beli `0`.
- **Budget Control:** `save_rab` melakukan pengecekan terhadap plafon Nilai Kontrak dan Budget Proyek. Terdapat fungsi `rabItemBlock` untuk mencegah penghapusan item yang sudah dipakai transaksi (PO, SPK, dll).
- **SQL:** Query menggunakan PDO prepared statements secara konsisten (`$db->prepare(...)`).

### 2.2 Procurement (`poCRD.php`, `purchase.planningCRD.php`)
- **PO Logic:**
  - `poCRD.php` menggunakan `po_release_validate` yang dipanggil di dalam transaksi (`FOR UPDATE`).
  - Validasi real-time dilakukan untuk kuota RAB dan stok tersedia.
  - Advisory lock per proyek (`po_lock_project`) digunakan untuk mencegah *race condition*.
  - `po_net_need` menangani logika titipan klien.
- **Purchase Planning:**
  - `purchase.planningCRD.php` mengintegrasikan pengecekan stok gudang (`GetProjectStockMap`) dan sisa kuota RAB.
  - `check_inventory` memastikan baris RAB yang sama tidak saling menindih klaim stok.

### 2.3 Inventory (`grnCRD.php`)
- **Logic:** `InsertGrnItems` melakukan pengecekan toleransi (10%) terhadap sisa kuota PO.
- **Patch F-4.1:** Harga per unit untuk `CLIENT_SUPPLIED` dipaksa `0.0` di server side meskipun klien mengirim harga.
- **SQL:** Menggunakan `pg_query_params` secara konsisten.

## 3. Temuan & Catatan
- **Integritas:** Logika `CLIENT_SUPPLIED` telah diterapkan secara konsisten di backend (RAB, PO Planning, GRN).
- **SQL Injection:** Secara umum, query menggunakan `prepare`/`pg_query_params` dengan parameter terikat.
- **Status Baseline:** Logika bisnis yang diselaraskan melalui perbaikan gap sebelumnya (GAP-01 s/d GAP-04) tampak terimplementasi dengan baik di versi kode saat ini.

## 4. Kesimpulan Tahap 1B
- Modul RAB, Procurement, dan Inventory menunjukkan tingkat keamanan dan validasi yang tinggi berdasarkan audit read-only.
- Tidak ada indikasi kerentanan SQL injection yang mencolok pada file yang diperiksa.
- Logika bisnis (stok, kuota, titipan klien) telah terintegrasi di backend sesuai dengan kebijakan bisnis yang terdokumentasi.
