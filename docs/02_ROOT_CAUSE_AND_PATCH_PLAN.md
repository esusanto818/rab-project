# Fase 2: Root Cause Analysis & Patch Plan

- **Tanggal Penyusunan:** 2026-11-27
- **Status:** Menunggu Persetujuan sebelum Fase 3 (Controlled Fix)
- **Referensi Audit:** `docs/01_FULL_VERIFICATION_AUDIT.md`

---

## 1. Root Cause Analysis (Analisis Akar Masalah)

1. **[SEC-01 | CRITICAL] 6 Endpoint CRUD Belum Memanggil `php-func/user.valid.php`**
   - **Target:** `leftoverCRD.php`, `spkCRD.php`, `subconCRD.php`, `subcon.progressCRD.php`, `dashboardCRD.php`, `inventory.status.php`.
   - **Akar Masalah:** Keenam file memakai pengecekan sesi lama secara parsial dan tidak memanggil `user.valid.php`, sehingga melewati `app_require_same_origin_post()`, pemeriksaan status user aktif, serta lookup matriks otorisasi `m_user_access_menu`.
   - **Dampak:** Pengguna yang tidak memiliki hak akses pada menu SPK, Subkon, atau Material Leftover berpotensi memanggil endpoint secara langsung (*Broken Access Control*).

2. **[SEC-02 | CRITICAL] Concatenation Query Tanpa Parameterized Statement pada `php-crud/m.employeeCRD.php`**
   - **Target:** `php-crud/m.employeeCRD.php` (blok `create` dan `update`, baris 291–397).
   - **Akar Masalah:** Masih memakai pola legacy `checksinglequote($_POST[...])` dengan helper string `insertQ()` / `updateQ()`, bukan `PDO::prepare` atau `pg_query_params`.
   - **Dampak:** Karakter escape tertentu dapat merusak struktur query SQL dan tidak memiliki jaminan atomisitas penuh jika salah satu query mutasi gagal di tengah jalan.

3. **[RBAC-01 | HIGH] Penegakan Level `FormAccess` Belum Seragam pada Operasi Mutasi**
   - **Target:** `php-func/user.valid.php` dan endpoint transaksi (`project.invoiceCRD.php`, `spkCRD.php`, `subconCRD.php`, `leftoverCRD.php`, dll.).
   - **Akar Masalah:** `user.valid.php` sudah mengisi `$_SESSION['FormAccess']` (`1=Read`, `2/3=Create/Update`, `4=Delete`), namun penegakan batas minimum pada `task=save/update/delete/release` masih bergantung pada implementasi masing-masing file.

---

## 2. Controlled Patch Plan (Rencana Perbaikan Fase 3)

| Tahap | File Target | Perubahan yang Akan Dilakukan | Verifikasi & Rollback |
| :--- | :--- | :--- | :--- |
| **Patch 3A** | `php-func/user.valid.php` | 1. Daftarkan alias `leftoverCRD.php`, `spkCRD.php`, `subconCRD.php`, `subcon.progressCRD.php`, `inventory.status.php` di `$endpointAliases`.<br>2. Tambahkan fungsi standar `app_require_form_access($minLevel)`. | `php -l php-func/user.valid.php` & uji RBAC role read-only. Rollback: `git checkout -- php-func/user.valid.php`. |
| **Patch 3B** | 6 endpoint `SEC-01` (`leftoverCRD`, `spkCRD`, `subconCRD`, `subcon.progressCRD`, `dashboardCRD`, `inventory.status`) | Sisipkan `require_once __DIR__ . '/../php-func/user.valid.php';` dan terapkan `app_require_form_access()` untuk aksi tulis/hapus. | `php -l` pada keenam file + tes curl tanpa sesi & beda role. Rollback: `git checkout -- php-crud/<file>`. |
| **Patch 3C** | `php-crud/m.employeeCRD.php` & `php-crud/project.invoiceCRD.php` | 1. Konversi query `create`/`update` `m.employeeCRD.php` ke `pg_query_params` / `PDO::prepare` dalam transaksi atomik.<br>2. Pastikan seluruh mutasi di `project.invoiceCRD.php` menegakkan `FormAccess`. | `php -l` + uji simpan/ubah karyawan & invoice di `proyekGMN_test`. Rollback: `git checkout -- php-crud/m.employeeCRD.php php-crud/project.invoiceCRD.php`. |
| **Fase 4** | `tests/e2e_erp_suite.php` & `cek.deploy.sh` | Bangun suite E2E terisolasi di `proyekGMN_test` (siklus pengadaan, proyek, jurnal, stok, dan concurrency lock) + laporan `03_E2E_REGRESSION_REPORT.md`. | Jalankan `php tests/e2e_erp_suite.php` & `bash cek.deploy.sh --strict-css`. |
