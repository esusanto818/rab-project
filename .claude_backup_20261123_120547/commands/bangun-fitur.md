---
description: Merancang & membangun layer/fitur baru (DB + Backend + UI), melakukan hardening security, membuat test case, dan menyusun dokumen How-To.
---
> Untuk memperbaiki bug pada fitur yang sudah ada gunakan `/perbaiki-ui`.

Lakukan siklus **Pembangunan Fitur Baru End-to-End** untuk kebutuhan: **$ARGUMENTS**

Jalankan tahapan berikut secara berurutan:
1. **Arsitektur & Pembangunan Fitur (`feature-architect-builder`)**:
   - Analisis tabel database aktual dan modul terkait yang sudah ada.
   - Rancang dan buat file migrasi SQL di folder `sql/`, endpoint backend di `/php-crud/`, serta tampilan UI ExtJS 3.4.1 di `/js/` atau `/views/` sesuai standar `CLAUDE.md`.
2. **Audit & Hardening Keamanan (`security-auditor-patcher`)**:
   - Periksa seluruh kode fitur baru maupun file eksisting yang diubah terhadap celah SQL Injection, otorisasi sesi, validasi server-side, dan kebocoran error, lalu terapkan patch keamanannya.
   - *Catatan: Berhenti sejenak setelah file migrasi SQL dan kode selesai dibuat agar saya bisa mengeksekusi migrasi SQL secara manual.*
3. **Penyusunan Skenario Uji (`qa-test-engineer`)**:
   - Susun tabel Test Case (positif, negatif/validasi batas, dan uji keamanan) beserta sampel ID data dari database lokal untuk pengujian.
4. **Panduan Pengguna (`tech-writer`)**:
   - Buat dokumen panduan operasional (`docs/HOWTO_<slug-nama-fitur>.md`) agar user memahami cara menggunakan layer/fitur baru tersebut.
