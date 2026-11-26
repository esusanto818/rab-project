# PRE_DELIVERY_09_HANDOVER_PACKAGE.md

## Handover Package: RAB-Project

### a. Ringkasan Eksekutif
Sistem RAB-Project telah diaudit secara menyeluruh dan dinyatakan siap untuk dioperasikan dalam lingkungan produksi. Modul utama meliputi RAB, Procurement, Inventory, Project Management, Subcontractor, dan Finance.

### b. Panduan Alur Operasional (End-to-End)
1. **Perencanaan:** RAB/RAP disusun di `rabCRD.php` sebagai dasar anggaran.
2. **Pengadaan:** Purchase Planning -> PO (`poCRD.php`) -> GRN (`grnCRD.php`).
3. **Pelaksanaan:** SPK (`spkCRD.php`) -> Subcon Progress (`subcon.progressCRD.php`) -> Project Progress (`project.progressCRD.php`).
4. **Keuangan:** Penagihan termin klien (`project.invoiceCRD.php`) -> Pembayaran -> Jurnal GL otomatis (`AutoPostJournal`) -> Laporan Keuangan.

### c. Checklist Deployment & Backup
- **Deployment:** Pastikan environment variabel `RAB_DB_HOST`, `RAB_DB_USER`, `RAB_DB_PASSWORD`, `RAB_DB_NAME` terkonfigurasi dengan benar di server produksi.
- **Backup:** Lakukan *dump* database PostgreSQL setiap hari menggunakan `pg_dump`.

### d. Konfigurasi Environment & Pemeliharaan
- **Konfigurasi Wajib:** Periksa `fiscal_config` untuk aturan posting jurnal otomatis sebelum operasional.
- **Troubleshooting:**
  - Cek `error_log` di folder `php-crud/` jika terjadi kegagalan posting jurnal.
  - Gunakan `php -l` untuk memvalidasi sintaks jika ada modifikasi manual di masa depan.
- **Maintenance:** Jalankan `sql/` migrasi secara periodik jika ada fitur baru (cek versi database vs versi aplikasi).
