---
description: Jalankan alur lengkap Audit DB/Kode, Pembuatan Test Case, dan Dokumen How-To untuk fitur tertentu.
---
Lakukan alur kerja kolaboratif untuk fitur: **$ARGUMENTS**

Jalankan tahapan berikut secara berurutan:
1. Panggil sub-agen `db-code-auditor` untuk mengaudit kode terkait fitur tersebut dan mencocokkannya dengan skema database PostgreSQL aktual.
2. Panggil sub-agen `qa-test-engineer` untuk menyusun skenario Test Case lengkap beserta pemetaan sampel data uji dari database lokal.
3. Panggil sub-agen `tech-writer` untuk menyusun draf dokumen panduan pengguna (`docs/HOWTO_<slug-nama-fitur>.md`) berdasarkan alur kode yang sudah terverifikasi.
4. Sajikan ringkasan eksekutif dari ketiga agen tersebut kepada saya.
