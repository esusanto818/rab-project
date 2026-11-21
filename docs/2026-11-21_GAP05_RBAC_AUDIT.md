# Laporan Audit GAP-05: Role-Based Access Control (RBAC) & Hak Akses Pengguna

**Tanggal Audit:** 21 November 2026  
**Modul/Fokus:** Pemeriksaan Hak Akses Pengguna (`m_user_access_menu`, `m_user_system`), Validasi Sesi (`php-func/user.valid.php`), serta Otorisasi Backend CRUD (`php-crud/`).

---

## 1. Ringkasan Eksekutif
Audit GAP-05 melakukan analisis mendalam terhadap mekanisme pengontrolan akses berbasis peran (RBAC) di dalam sistem `proyekGMN`. Fokus utama meliputi:
1. Validasi keberadaan dan ketepatan pemeriksaan hak akses di layer backend (`php-crud/`).
2. Pemeriksaan tabel relasi hak akses pengguna (`m_user_access_menu`) terhadap form modul (`m_form_name`).
3. Analisis potensi celah bypass akses (misalnya penggunaan session yang tertinggal atau pengiriman parameter `form_id` yang tidak tervalidasi).

---

## 2. Temuan Analisis & Verifikasi Lapisan
- **Inisialisasi Sesi & Status User:** Seluruh file backend CRUD diinisialisasi melalui `php-func/user.valid.php` dan `php-func/function.all.php`, yang menerapkan pengecekan status aktif akun (`app_require_active_user()`) serta pembatasan same-origin untuk mencegah CSRF pada permintaan POST mutasi.
- **Resolusi Form ID & Level Akses:** 
  - Validasi akses pada file `php-func/user.valid.php` memastikan bahwa endpoint AJAX/CRUD langsung memetakan dirinya ke menu induk yang sesuai (`$endpointAliases`) dan mencegah caller meminjam `FormAccess` dari modul lain yang terakhir dibuka.
  - Level akses dibedakan dengan jelas: Level 1 (Read), Level 2 (Create), Level 3 (Update), Level 4 (Delete / Admin).
- **Endpoint Khusus & Tindakan Keamanan:** 
  - Aksi sensitif seperti `sync_tax_rates` pada `dashboardCRD.php` dan perubahan hak akses user pada `m.user.systemCRD.php` melakukan verifikasi langsung ke tabel `m_user_access_menu` dengan level minimum yang disyaratkan (Level 3/4), sehingga aman dari manipulasi sesi parsial.

---

## 3. Kesimpulan & Status
- **Status:** **AMAN & TERVERIFIKASI (PASS)**
- Tidak ditemukan celah kritis pada manajemen RBAC backend. Seluruh controller telah mengimplementasikan pemeriksaan sesi dan level akses yang ketat.
