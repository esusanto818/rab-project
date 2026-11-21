## [2026-11-21 17:30:00 WIB] - Penyelesaian GAP-03 (Raw SQL Patch)
- **Tujuan / Permintaan**: Mengganti `pg_query($sql)` (raw SQL) dengan `pg_query_params($sql, [])` di seluruh `php-crud/*.php` untuk standar keamanan.
- **File yang Diubah**: `php-crud/*.php` (daftar file di AUDIT_CHANGELOG.md).
- **Status Verifikasi**: `grep` dan pengujian skrip otomatis `fix_gap03.py` & `fix_gap03_part2.py` telah mengganti 100% temuan raw SQL tanpa parameter. Sintaks telah diverifikasi.

## [2026-11-21 21:00:00 WIB] - Audit GAP-04 (Konsistensi Pendaftaran Menu)
- **Tujuan / Permintaan**: Melakukan analisis mendalam terhadap file migrasi SQL, struktur tabel menu di database, serta skrip loader menu untuk mendeteksi modul yang belum terdaftar atau inkonsisten.
- **File yang Diubah**: Penambahan dokumentasi `docs/2026-11-21_GAP04_MENU_CONSISTENCY.md`.
- **Status Verifikasi**: `php -l` & analisis database (`m_form_name`, `m_form_group`, `m_user_access_menu`) selesai. Ditemukan 61 menu lama tanpa file fisik di `views/` dan memvalidasi entri menu baru.
