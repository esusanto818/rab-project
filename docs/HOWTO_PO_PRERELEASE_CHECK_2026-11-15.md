# How-To: Pengecekan Purchase Order (PO) Sebelum Release

Dokumen ini menjelaskan mekanisme validasi otomatis sebelum perubahan status Purchase Order (`Draft -> Diajukan -> Disetujui`) pada modul **Purchase Order** (`php-crud/poCRD.php` dan `js/po.js`).

## 1. Tujuan Validasi
Mencegah PO dirilis apabila terjadi perubahan stok proyek, pemakaian kuota RAB oleh PO lain, penutupan periode akuntansi, atau perubahan status supplier setelah PO disimpan sebagai `Draft`.

## 2. Daftar Pengecekan Real-Time Saat Release (`change_status`)
1. **Validasi State Machine & Anti Double-Release:** Memastikan transisi status mengikuti alur sah (`Draft -> Diajukan -> Disetujui`) dan mengunci baris PO (`FOR UPDATE`) dalam transaksi ACID.
2. **Kebutuhan Bersih & Isolasi Stok Proyek:** Menghitung ulang `GetProjectStockMap()` dan `po_net_need()` secara real-time. Stok proyek lain (`warehouses.project_id != project_id`) tidak pernah dihitung/dipinjam.
3. **Kuota RAB Aktual:** Memblokir release apabila sisa kuota RAB telah terpakai oleh PO lain sehingga `qty` PO melebihi kebutuhan bersih.
4. **Integritas Item PO:** Memastikan PO memiliki minimal 1 item terikat ke `budget_items` proyek dengan `qty > 0` dan `unit_price >= 0`.
5. **Validasi Supplier & Periode Akuntansi:** Memastikan supplier berstatus aktif (`active = true`) dan `po_date` berada pada `accounting_periods` yang terbuka (`is_closed = false`).
6. **Otorisasi Akses:** Memverifikasi hak akses menu 24 (`m_user_access_menu`).

## 3. Verifikasi Otomatis
- Skrip uji E2E: `tests/run_e2e_po_prerelease.php` (`TC-201` s/d `TC-210`, hasil `PASS=10 FAIL=0`).
