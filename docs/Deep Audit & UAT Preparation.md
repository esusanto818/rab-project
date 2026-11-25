# Handover Context — RAB-Project (`proyekgmn-dev`) Deep Audit & UAT Preparation

**Tanggal Rangkuman:** 25 November 2026  
**Direktori Proyek:** `/home/myadmin/proyekgmn-dev`  
**Branch Aktif:** `dev` (Belum di-merge ke `main` — menunggu UAT manual & verifikasi gap lintas-modul)  
**Database Uji:** `proyekGMN_test` (Env var server: `RAB_DB_NAME=proyekGMN_test`)

---

## 1. Status Audit & Checkpoint Git (Branch `dev`)

Seluruh tahapan audit kode dari Fase 1 hingga Fase 10 telah selesai dikerjakan, diverifikasi, dan di-push ke remote GitHub (`origin/dev`) dengan tag rilis berikut:

1. **`v1.0-audit-complete` (Fase 1–8 — `12/12 VERIFIED`):**
   * Penyeragaman transaksi database eksplisit (`BEGIN/COMMIT/ROLLBACK` / PDO).
   * Isolasi material titipan klien (`CLIENT_SUPPLIED` = `Rp 0`) pada alur `Planning -> PO -> GRN -> WH Receiving -> Issuing -> Stock Card` tanpa jurnal rupiah maupun utang usaha (AP).
   * Pemisahan akun COA Retensi pada `project.invoiceCRD.php` dan `subcon.invoiceCRD.php`.
   * Validasi periode tutup buku (`ensure_period_open()` / `accounting_periods`).
   * Perbaikan autentikasi runner E2E (`127.0.0.1:8098`), validasi MIME-type upload file, standarisasi header PDF (`print.common.php`), kelengkapan `endpointAliases`, validasi `form_id`, dan perbaikan siklus hidup UI ExtJS 3.4.1 (`css/app-theme.css`).
   * Dokumen register: `docs/DEEP_AUDIT_REGISTER.md` (`COMPLETE`).

2. **`v1.1-audit-phase9` (Fase 9 — Hardening & Concurrency | `E2E 12/12 PASS`):**
   * `CONC-03`: Penambahan row lock `FOR UPDATE OF gd` dengan named parameter PDO (`:grn_dtl_id_1`, `:grn_dtl_id_2`, `:grn_hdr_id`) pada pengecekan sisa kuantitas GRN di `php-crud/whrcvCRD.php`.
   * `VOID-02`: Proteksi pembatalan `whrcvCRD.php` jika stok barang sudah terpakai oleh *Material Issuing*.
   * `VOID-05`: Proteksi pembatalan `subcon.invoiceCRD.php` jika invoice sudah memiliki pembayaran aktif.
   * `BUS-01` & `BUS-03`: Proteksi pembatalan PO jika sudah memiliki GRN aktif (`poCRD.php`) dan validasi batas nilai kontrak pada termin proyek (`project.invoiceCRD.php`).
   * `SQL-01..04`: Sanitasi query dinamis menjadi *prepared statement* PDO.
   * Perbaikan `tests/run_e2e_client_supplied.php` (menambahkan langkah `approve_grn` sebelum `whrcvCRD`) dan lulus penuh **`PASS=12 FAIL=0`**.
   * Dokumen register: `docs/DEEP_AUDIT_PHASE9_REGISTER.md` (`CLOSED / VERIFIED`).

3. **`v1.2-audit-phase10` (Fase 10 — Production Readiness & Indexing):**
   * `DB-001` & `DB-002`: File migrasi indeks idempotent `sql/2026-11-24.phase10_indexes.sql` (`journal_entries`, `project_invoices`, `purchase_invoice_hdr`).
   * `FIN-001` & `FIN-002`: Verifikasi mekanisme jurnal balik (*double-entry reversal*) pada `trialbalanceCRD.php` dan penyeragaman bucket hari pada `ar.agingCRD.php` & `ap.agingCRD.php`.
   * `SEC-001`: Penguatan blokir folder internal (`sql`, `docs`, `tests`, `tmp_bak`, `.claude`, `.git`) pada `.htaccess`.
   * Dokumen register: `docs/DEEP_AUDIT_PHASE10_REGISTER.md` (`COMPLETE`).

---

## 2. Konfigurasi Claude Code Router (`127.0.0.1:3458`)

* **Provider Aktif:** `Mistral` (mengatasi limit harian Google AI Studio & batas ketat 8K TPM Groq).
* **Global Routing (`agent-free`):**
  * **Target Utama:** `Mistral/codestral-latest`
  * **Fallback Targets:** `Mistral/codestral-2508`, `Mistral/ministral-14b-2512`, `GROQ/qwen/qwen3.8-27b`, `GROQ/openai/gpt-oss-120b`
* **Catatan Operasional:**
  * Selalu jalankan `/clear` di terminal Claude Code sebelum memulai tugas audit baru agar konteks lama tidak membebani *rate limit* (`HTTP 429`).
  * Wajib instruksikan Claude Code menggunakan tool `Grep` & `Read` pada file asli (jangan mengarang nama file/baris) dan menyimpan backup ke `tmp_bak/`.

---

## 3. Artefak Pengujian & Skrip Penting

* **Halaman UAT Interaktif (16 Kasus Uji Sequential Hulu-ke-Hilir):** `docs/UAT_CHECKLIST_FINAL.html` (dibuat via `/tmp/gen_uat16.php`, menyimpan status centang `OPEN` -> `VERIFIED` di `localStorage` browser).
* **Perintah Menjalankan Tes Otomatis E2E (`CLIENT_SUPPLIED`):**
  ```bash
  RAB_DB_NAME=proyekGMN_test DB_NAME=proyekGMN_test php -S 127.0.0.1:8098 > /tmp/php_e2e.log 2>&1 &
  sleep 1
  php tests/run_e2e_client_supplied.php
  pkill -f "php -S 127.0.0.1:8098"
  ```

---

## 4. Langkah Berikutnya (Next Actions)

1. **Jalankan Audit Integrasi Lintas-Modul (`docs/CROSS_MODULE_GAP_AUDIT.md`):**
   * Memeriksa 6 titik *hand-off* hulu-ke-hilir (Addendum RAB ↔ Planning/PO, Retur Pembelian/Material, Issuing ↔ Realisasi Biaya Proyek/RAP, Subcon Buy-Back & Retensi, Pelepasan Retensi / *Retention Release*, dan kelengkapan registrasi menu/hak akses).
2. **Selesaikan UAT Manual (`docs/UAT_CHECKLIST_FINAL.html`):**
   * Pastikan skrip migrasi `sql/2026-11-23.fin_fa_patch_batch2.sql` dan `sql/2026-11-24.phase10_indexes.sql` sudah dijalankan di DB uji/dev, lalu verifikasi 16 skenario UAT.
3. **Final Release (`dev` -> `main`):**
   * Setelah gap lintas-modul (jika ada) ditutup dan 16/16 UAT lulus, lakukan *merge* `dev` ke `main` dengan tag produksi `v1.0.0-prod` (sesuaikan `RewriteBase` di `.htaccess` saat deploy ke server klien).
