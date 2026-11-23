---
description: Mengaudit keseimbangan jurnal (Debit == Kredit), validasi tutup buku, dan pemetaan akun COA pada modul transaksi atau skrip SQL.
---
Lakukan **Audit Akuntansi & Integritas Jurnal** pada modul atau file: **$ARGUMENTS**

Jalankan langkah berikut secara berurutan:
1. Periksa alur pembentukan jurnal pada file backend (`/php-crud/`) maupun fungsi/trigger SQL terkait **$ARGUMENTS**.
2. Cocokkan pemetaan kode akun dengan tabel konfigurasi database via MCP `postgres` (`SELECT` only) untuk memastikan tidak ada kode akun mati atau *hardcoded*.
3. Verifikasi apakah transaksi sudah dilindungi penguncian baris (`FOR UPDATE`), validasi periode tutup buku (`accounting_periods`), dan pengecekan `Total Debit == Total Kredit`.
4. Sajikan laporan temuan celah akuntansi beserta rekomendasi atau tambalan kodenya jika ditemukan ketidaksesuaian.
