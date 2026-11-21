# GAP BACKLOG

Daftar temuan audit (Audit Awal - TAHAP 1)

| ID | Kategori | Deskripsi Temuan | Lokasi File | Prioritas |
|----|----------|------------------|-------------|-----------|
| GAP-01 | Frontend/Backend | Ketidaksesuaian kontrak API (dashboardCRD.php vs views/dashboard.php) | php-crud/dashboardCRD.php, views/dashboard.php | Kritis | DONE |
| GAP-02 | Keamanan | Potensi komentar `//` dalam string SQL multi-baris | Seluruh `php-crud/*.php` | Tinggi | DONE |
| GAP-03 | Keamanan | Penggunaan raw SQL tanpa `pg_query_params` | Seluruh `php-crud/*.php` | Tinggi | DONE |
| GAP-04 | Audit | Pendaftaran menu belum konsisten pada fitur baru | `sql/` migrasi | Sedang |

---
*Status: Sedang dianalisis*
