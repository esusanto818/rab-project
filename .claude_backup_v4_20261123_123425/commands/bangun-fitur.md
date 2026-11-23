---
description: Bangun modul atau fitur baru secara end-to-end dengan penjagaan ketat RBAC, ExtJS 3.4.1, PHP 7.4, dan pendaftaran menu otomatis.
---

Bangun fitur/modul baru sesuai permintaan user: **$ARGUMENTS**

WAJIB gunakan agent `feature-architect-builder` dan muat skill `extjs-341-patterns`, `rbac-endpoint-guard`, serta `db-schema-map`.

Langkah kerja berurutan:
1. Verifikasi peta instance (`pwd`, symlink `/var/www/html/rab-project/dev`, dan skema tabel PostgreSQL aktual).
2. Buat desain dan file migrasi `sql/YYYY-MM-DD.<nama_fitur>.sql` (termasuk `INSERT` pendaftaran menu ke `m_form_name` & `m_user_access_menu` serta blok `-- ROLLBACK / DOWN MIGRATION`). **Jangan jalankan DDL.**
3. Bangun backend `php-crud/<modul>CRD.php` (PHP 7.4, transaksi atomik, kontrak JSON standar) dan **wajib daftarkan aliasnya di `php-func/user.valid.php`**.
4. Bangun UI `js/<modul>.js` dan `views/<modul>.php` sesuai standar ExtJS 3.4.1 (wajib `EditorGridPanel`, `cellclick` untuk checkbox, tombol aksi matriks/detail yang terlihat jelas di layar, tanpa hardcode path).
5. Jalankan `php -l`, `node --check`, dan simulasi `curl` untuk memastikan tidak ada error sintaks maupun HTTP 403.
6. Buat dokumentasi penggunaan & teknis di `docs/` lalu berhenti menunggu review migrasi SQL dari developer.
