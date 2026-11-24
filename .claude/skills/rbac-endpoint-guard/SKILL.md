---
name: rbac-endpoint-guard
description: Checklist wajib integrasi user.valid.php, pendaftaran menu m_form_name, dan kontrak RBAC setiap kali menambah modul/endpoint baru.
---

# RBAC & Endpoint Guard Skill (Anti-403 & Anti-Orphan Menu)

Setiap modul, halaman, atau file `php-crud/*CRD.php` BARU wajib lolos 5 gerbang berikut sebelum dinyatakan selesai:

1. **Mapping Endpoint di `php-func/user.valid.php`:**
   - `user.valid.php` secara otomatis memotong akhiran `CRD.php` menjadi `.php` untuk dicocokkan ke kolom `m_form_name.php_url`.
   - Jika nama file view dan file CRD berbeda (contoh: view `m.user.role.php` dipanggil oleh `m.user.roleCRD.php` atau `roleCRD.php`), **WAJIB** mendaftarkannya di array `$endpointAliases` pada `php-func/user.valid.php`.

2. **Pendaftaran Menu & Hak Akses (SQL Migrasi):**
   - Tulis `INSERT INTO m_form_name ... ON CONFLICT DO NOTHING` (atau `WHERE NOT EXISTS`) di file migrasi `sql/YYYY-MM-DD.<fitur>.sql`.
   - Pastikan `php_url` di `m_form_name` sama persis dengan nama file view/endpoint yang dipanggil.

3. **Transaksi Atomik Header-Detail & Hak Akses:**
   - Dilarang mengeksekusi query `INSERT`/`UPDATE` detail di luar transaksi utama (`BEGIN ... COMMIT / ROLLBACK`).
   - Filter payload dari matriks role (`Apply Role` / `Sync`) agar hanya baris dengan `access_level > 0` yang disimpan ke `m_user_access_menu`.

4. **Kontrak JSON & Kompatibilitas PHP 7.4:**
   - Gunakan `pg_query_params` atau PDO `prepare/execute` secara konsisten per file.
   - Dilarang memakai fungsi PHP 8+ (`str_contains`, `str_starts_with`, `match`).
   - Pastikan key JSON (`totalCount` vs `totalRecord`, `data`) identik dengan `Ext.data.JsonReader` di JS.

5. **Smoke Test HTTP (`curl`) Wajib:**
   - Uji endpoint baru dengan `curl` (tanpa session -> harus HTTP 401 JSON; dengan session valid -> harus HTTP 200 JSON valid, bukan HTTP 403 atau HTML error).
