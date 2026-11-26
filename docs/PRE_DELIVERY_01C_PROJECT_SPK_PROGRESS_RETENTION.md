# PRE_DELIVERY_01C_PROJECT_SPK_PROGRESS_RETENTION.md

## 1. Lingkup Audit
Audit Project Management, SPK, Subcontractor, Progress, Opname, dan Retention.
- File audit utama: `php-crud/project.progressCRD.php`, `php-crud/subcon.progressCRD.php`, `php-crud/spkCRD.php`, `php-crud/subconCRD.php`.

## 2. Pemeriksaan Keamanan & Logika Bisnis

### 2.1 Progress Proyek (`project.progressCRD.php`)
- **Alur Progres:** Progres proyek sekarang dikendalikan melalui sistem klaim (Diajukan -> Diverifikasi). Tidak bisa lagi diubah bebas di tabel `projects`.
- **Indeks Biaya (CPI):** Menggunakan rumus *Earned Value* sederhana. Terdapat pengecekan realisasi biaya (material + expense) menggunakan view `v_project_actual_cost`.
- **Validasi:** Klaim 100% diblokir jika realisasi material = 0.

### 2.2 Subkontraktor (`subconCRD.php`, `spkCRD.php`)
- **SPK (Surat Perintah Kerja):**
  - SPK dibedakan menjadi `INTERNAL` dan `SUBCON`.
  - SPK `SUBCON` memerlukan `execution_subcontractor_id`.
  - Terdapat mekanisme pembatasan material gudang berdasarkan kuota RAB dan pembayaran termin klien (untuk tipe `INTERNAL`).
- **Prestasi & Inspeksi:**
  - `subcon.progressCRD.php` menangani inspeksi lapangan dan sertifikat prestasi.
  - Terdapat validasi *hard-check* saldo progres kumulatif (tidak boleh melebihi kontrak).
  - Pembayaran (DP, Progress, Retensi) dihitung berdasarkan proporsi dari nilai kontrak.
- **SQL & Keamanan:**
  - Sebagian besar query menggunakan PDO prepared statements atau `pg_query_params` dengan binding yang aman.
  - Proteksi terhadap *race condition* (transaksi ACID, `FOR UPDATE`).
  - Tidak ditemukan *SQL injection* yang nyata pada fungsi CRUD utama.

## 3. Temuan & Catatan
- **GAP-05 s/d GAP-07:** Sinkronisasi status dan validasi pada modul subkontraktor telah diimplementasikan sesuai kebutuhan bisnis yang diidentifikasi sebelumnya.
- **Integritas Retensi:** Logika retensi (ditahan vs dilepas) sudah terintegrasi dalam modul pembayaran (`subconCRD.php`).
- **Risiko:** Mekanisme pembatalan inspeksi dan *reversal* jurnal pada pembayaran/prestasi merupakan titik krusial yang memerlukan pengujian *negative* yang cermat di Tahap 7.

## 4. Kesimpulan Tahap 1C
- Modul Proyek dan Subkontraktor telah diaudit dan menunjukkan kepatuhan terhadap standar keamanan yang ditetapkan (penggunaan PDO/prepared statements, validasi input, ACID transactions).
- Alur kerja (workflow) antara inspeksi, prestasi, dan pembayaran sudah terotomatisasi dan terintegrasi dengan akuntansi (jurnal otomatis).
- Status: **Audit modul selesai (1A-1C)**. Bersiap menuju Tahap 1D (Dashboard, UI, PDF).
