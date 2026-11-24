# AUDIT CHANGELOG - RAB PROJECT

| Tanggal & Waktu | Path File | Ringkasan Perubahan & Alasan | Status Git |
|---|---|---|---|
| 2026-11-23 19:15 | `php-crud/m.user.roleCRD.php` | Implementasi transaksi eksplisit `BEGIN`/`COMMIT`/`ROLLBACK` (`GAP-USR-01`) dan validasi eksistensi `form_id` (`GAP-USR-03`). | Modified |
| 2026-11-23 19:15 | `php-func/user.valid.php` | Penambahan mapping `endpointAliases` lengkap untuk modul user dan role (`GAP-USR-02`). | Modified |
| 2026-11-23 18:35 | `php-crud/project.invoiceCRD.php` | Implementasi pemisahan akun COA Retensi Proyek (`FIN-03`) pada fungsi `ti_post_journal()` dengan pengecekan `fiscal_config` rule `PROJECT_RETENTION`. | Modified |
| 2026-11-23 18:35 | `php-crud/subcon.invoiceCRD.php` | Implementasi pemisahan akun COA Retensi Subkon (`FIN-03`) pada action `approve_invoice` dengan pengecekan `fiscal_config` rule `SUBCON_RETENTION_LIAB`. | Modified |
| 2026-11-23 18:35 | `sql/2026-11-23.fin_fa_patch_batch2.sql` | Pembuatan skrip migrasi SQL DDL untuk aturan retensi, indeks `CLIENT_SUPPLIED` (`FA-02`), dan tabel log upload (`FA-04`). | New (Untracked) |
| 2026-11-23 18:35 | `docs/SESSION_LOG.md` | Pencatatan sesi implementasi Patch Batch 2 (`FIN-03`, `FA-02`, `FA-04`). | Modified |
