## [2026-11-21 17:30:00 WIB] - Penyelesaian GAP-03 (Raw SQL Patch)
- **Tujuan / Permintaan**: Mengganti `pg_query($sql)` (raw SQL) dengan `pg_query_params($sql, [])` di seluruh `php-crud/*.php` untuk standar keamanan.
- **File yang Diubah**: `php-crud/*.php` (daftar file di AUDIT_CHANGELOG.md).
- **Status Verifikasi**: `grep` dan pengujian skrip otomatis `fix_gap03.py` & `fix_gap03_part2.py` telah mengganti 100% temuan raw SQL tanpa parameter. Sintaks telah diverifikasi.
