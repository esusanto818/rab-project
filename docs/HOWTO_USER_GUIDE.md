# HOW TO USER GUIDE — Overnight Project Audit (proyekgmn-dev)

**Tanggal:** 2026-11-14
**Target:** `~/proyekgmn-dev` — RSBI/SPK & RAB (PostgreSQL + PHP native pg_query_params + ExtJS 3.4.1)

Panduan ini ringkas untuk tim yang ingin menjalankan ulang, apply patch, atau memverifikasi hasil audit.

---

## 0. Prasyarat
- PostgreSQL 9.6 (CLI `psql`) — kebanyakan migrasi ditulis PG9.6-compatible.
- PHP 7.4+ (proc CRUD; `pg_query_params`).
- Node/npm (untuk lint JS opsional).
- Variabel lingkungan DB (opsional): `RAB_DB_HOST`, `RAB_DB_PORT`, `RAB_DB_NAME`, `RAB_DB_USER`, `RAB_DB_PASSWORD`. Default: host=127.0.0.1, port=5432, user=postgres, dbname=proyekGMN.

## 1. Jalankan audit ulang (mulai dari nol)
```bash
cd ~/proyekgmn-dev
# Data-uji & DB test (clone)
pg_dump -h 127.0.0.1 -U postgres -d proyekGMN -O -x -f /tmp/proyekgmn_main.sql
dropdb --if-exists --host=127.0.0.1 -U postgres proyekGMN_test
createdb -h 127.0.0.1 -U postgres -T proyekGMN proyekGMN_test   # template
psql -h 127.0.0.1 -U postgres -d proyekGMN_test -f /tmp/proyekgmn_main.sql
# Lalu terapkan migrasi sql/ (11 file, PG9.6-compatible)
for f in sql/*.sql; do psql -h 127.0.0.1 -U postgres -d proyekGMN_test -v ON_ERROR_STOP=1 -f "$f"; done
```
> Catatan: `sql/*.sql` sudah PG9.6-compatible (EXECUTE FUNCTION→PROCEDURE, constraint di DO-block, index validasi). Uji di staging dulu sebelum production.

## 2. Apply security patch (manual review dulu)
- Patch lengkap (full-file) ada di `work/security_patches/`.
- **Review dulu** setiap file sebelum apply ke `php-crud/` & `js/`.
- Konvensi: setiap perubahan pakai komentar `// [SECURITY PATCH]` di baris atas perubahan.
- Setelah apply, lint PHP (`php -l`) dan periksa kembali logika usaha.

## 3. Uji migration & E2E
```bash
cd ~/proyekgmn-dev
# Jalankan semua kelompok
php tests/run_e2e_release.php                  # kelompok A+B+C+D
# atau satu kelompok
php tests/run_e2e_release.php --group=C
# Report dieksekusi ke test_report_tc001_065.md
```
- **Normal:** `PASS=19 · FAIL=0` (Kelompok D negative-path).
- **Jika FAIL:**
  1. Baca `test_report_tc001_065.md` baris yang gagal.
  2. Cek DB `proyekGMN_test` (schema migrasi sudah terpakai).
  3. **Negative-path payment (TC-044)** — pastikan account_id valid; jangan beri deposit jika total > 0 tanpa akun kas/bank.

## 4. Perintah umum (CLI)
| Tugas | Command |
|-------|---------|
| Cek git state | `cd ~/proyekgmn-dev && git status && git diff --stat` |
| Validation order (payment) | Edit `php-crud/project.paymentCRD.php` (lihat atas tahap 5 laporan) |
| Refresh migrasi | `for f in sql/*.sql; do psql -h 127.0.0.1 -U postgres -d proyekGMN_test -v ON_ERROR_STOP=1 -f "$f"; done` |
| Drop clone (bersih-bersih) | `dropdb --if-exists -h 127.0.0.1 -U postgres proyekGMN_test` |

## 5. Struktur file yang relevan
```
~/proyekgmn-dev/
├── sql/                     # migrasi PostgreSQL (6 file baru 20261115.*)
├── work/                    # patch keamanan (work/security_patches/) — **jangan merge ke main**
├── docs/
│   ├── OVERNIGHT_AUDIT_REPORT.md     # laporan lengkap audit
│   └── HOWTO_USER_GUIDE.md           # panduan ini
├── php-crud/                # handler backend CRUD
├── js/                      # UI ExtJS
└── tests/run_e2e_release.php
```

## 6. Troubleshooting cepat
| Gejala | Penyebab | Solusi |
|--------|----------|--------|
| `template database proyekgmn does not exist` | nama DB salah case | pakai `proyekGMN` (capital GMN) |
| `PG9.6 syntax error` | EXECUTE FUNCTION (>=PG11) / ADD CONSTRAINT IF NOT EXISTS | gunakan sql/ sudah dibenerkan; cek file sql/ |
| TC-044 gagal (kas/bank kosong) | urutan validasi cash/bank masih dulu | pastikan `php-crud/project.paymentCRD.php` sudah apply patch urutan |
| Migration gagal `ON_ERROR_STOP` | syntax PG9.6 / dependency | baca log `psql`, cek file sql/ sebelumnya |
| web server 8098 kehilangan DB env | RAB_DB_NAME terbaca dari env server | pastikan env `RAB_DB_NAME=proyekGMN_test` di server :8098 |

---

**Lisensi / tanggung jawab:** laporan ini ringkasan otomatis audit bisnis & keamanan. Verifikasi manual tetap wajib sebelum deploy ke production. Deploy hanya file migrasi + patch full-file yang sudah direview.
