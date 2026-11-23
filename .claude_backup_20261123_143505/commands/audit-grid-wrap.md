Gunakan sub-agent `grid-wrap-auditor` untuk mengaudit seluruh view data grid/tabel di proyek ini (`views/`, `js/`, `css/`).

Langkah kerja:
1. Periksa semua grid/tabel (termasuk halaman Tagihan Klien, Pembayaran Termin, Dashboard, Warehouse, dan modul lainnya) yang memiliki kolom atau header terpotong (`...` / `nowrap`).
2. Terapkan aturan CSS/konfigurasi **wrap text** yang rapi pada header (`th` / grid header) dan sel data (`td` / grid cell) tanpa merusak tata letak atau alignment angka kanan-kiri.
3. Jika ada file CSS global yang mengatur grid di seluruh aplikasi, perbarui secara terpusat agar konsisten di semua halaman.
4. Simpan ringkasan hasil audit dan daftar file yang diperbaiki ke `docs/AUDIT_GRID_WRAP_REPORT.md`.
