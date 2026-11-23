---
name: e2e-test-template
description: Template baku pembuatan skrip uji E2E CLI PHP terhadap database aplikasi dan server HTTP uji (base URL dari env `RAB_BASE_URL`). Gunakan setiap kali diminta membuat file tests/run_e2e_*.php.
---

# Template Baku Uji E2E (`proyekGMN_test`)

Jangan membaca file `tests/run_e2e_*.php` lama hanya untuk menyalin boilerplate. Gunakan langsung pola standar berikut:

1. **Koneksi & Endpoint:**
   - Database: PostgreSQL TCP `host=127.0.0.1 dbname=proyekGMN_test` (baca kredensial dari `php-func/function.all.php` atau variabel lingkungan `RAB_DB_*`).
   - Web Server Uji: `$base = getenv('RAB_BASE_URL') ?: 'http://127.0.0.1:8098';` lalu endpoint `$base . '/php-crud/<endpoint>.php'` via cURL dengan cookie jar sementara (`/tmp/e2e_cookie.txt`).
   - PASTIKAN `RAB_BASE_URL` menunjuk instance yang sama dengan yang dibuka user di browser (folder dan port yang sama). Tes lulus di instance lain tidak membuktikan apa pun.
2. **Isolasi & Cleanup Wajib:**
   - Gunakan awalan unik untuk data uji (misalnya `E2E-<KODE>-`) dan bungkus eksekusi dengan `try { ... } finally { ... }` untuk menghapus seluruh data uji (`DELETE`) serta mengembalikan flag master (`active = true`, `is_closed = false`) meskipun ada assertion yang gagal.
   - Selalu sinkronkan sequence jika menyisipkan ID eksplisit: `SELECT setval(pg_get_serial_sequence('<table>', 'id'), COALESCE((SELECT MAX(id) FROM <table>), 1));`.
3. **Format Output:**
   - Cetak `[PASS] TC-XXX ...` atau `[FAIL] TC-XXX ...` per kasus, lalu akhiri dengan ringkasan `Hasil Keseluruhan: PASS=X FAIL=Y` dan keluar dengan kode `exit($fail > 0 ? 1 : 0);`.
