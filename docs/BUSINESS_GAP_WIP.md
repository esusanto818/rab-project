# BUSINESS_GAP_WIP.md

## Audit Sub-Alur 1: RAB -> Project -> Task -> Material/Expense
- **Status:** Audit Selesai (E2E Test PASS)
- **Temuan Kritis:**
    - **GAP-01 (Severity: HIGH):** `task_materials` tidak memiliki validasi kategori ketat, memungkinkan item non-material masuk ke perencanaan material. (Status: Patch DB Trigger Diterapkan)
    - **GAP-02 (Severity: HIGH):** `spk_task_materials` tidak memiliki bound ketat terhadap `qty_budget` di `task_materials`, memungkinkan *over-allocation*. (Status: Patch DB Trigger Diterapkan)
- **Verifikasi Kode (m.proyekCRD.php):**
    - Transaksi ACID sudah diterapkan dengan `BEGIN/COMMIT`.
    - Validasi plafon (total RAB vs Nilai Kontrak) sudah diimplementasikan di level aplikasi sebelum commit.
    - Penanganan error DB (`RAISE EXCEPTION`) sudah di-handle oleh `try-catch` di PHP.

## Audit Sub-Alur 2: PO -> GRN
- **Status:** Audit Selesai (E2E Test PASS)
- **Temuan:**
    - **GAP-03 (Severity: MEDIUM):** Belum ditemukan validasi ketat antara `po_items` terhadap kuota RAB pada `poCRD.php`. (Status: Verified. Validasi ketat sudah diimplementasikan di `save_po` dan `po_release_validate`.)
    - **GAP-04 (Severity: MEDIUM):** Alur GRN (`warehouse.issuing.js`) perlu dipastikan sinkron dengan `spk_id`. (Status: Verified. `spk_id` telah disinkronkan ke tabel `material_out_header`.)

## Audit Sub-Alur 3: SPK -> Subkon -> Progress -> Inspeksi
- **Status:** Audit Selesai (E2E Test PASS)
- **Temuan:**
    - **GAP-05 (Severity: MEDIUM):** Pada `spkCRD.php`, `SPK_SUBCON_REQUIRE_WORK_ITEMS_ON_ISSUE` masih diset `false`. (Status: Patched - Konfigurasi diperbarui menjadi `true`.)
    - **GAP-06 (Severity: HIGH):** Pada `subcon.progressCRD.php`, tidak ditemukan validasi saldo progres (kumulatif vs kontrak) secara *hard-check* di dalam `save_progress_draft`. (Status: Patch Perbaikan Diterapkan & Diverifikasi)

## Audit Sub-Alur 4: Progress Proyek -> Payment Certificate (Klien)
- **Status:** Audit Selesai (E2E Test PASS)
- **Temuan:**
    - **GAP-07 (Severity: MEDIUM):** Pada `project.progressCRD.php`, verifikasi klaim progress tidak mengunci termin kontrak (project_invoices) secara otomatis. (Status: Patch Diterapkan & Diverifikasi. Catatan: Invoice yang disetujui dirilis secara massal per proyek saat progress disetujui; perlu audit apakah termin spesifik perlu dipetakan ke persentase progress tertentu di masa depan).
    - **GAP-08 (Severity: HIGH):** Pada `project.paymentCRD.php`, pembayaran termin (save_payment) mengizinkan `total_amount` untuk dialokasikan ke termin, namun validasi `total_amount_cur` (mata uang asing) terhadap `total_amount` (IDR) dalam logika konversi kurs perlu diperketat untuk mencegah selisih pembulatan yang berpotensi memanipulasi saldo deposit client. (Status: Patch Perbaikan Diterapkan & Diverifikasi)
