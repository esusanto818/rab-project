---
description: Audit alur bisnis & keamanan, buat tambalan DB + Backend + UI, hardening security, susun test case, dan buat dokumen How-To.
---
> Untuk bug tampilan (halaman kosong, angka 0, data tidak muncul) gunakan `/perbaiki-ui`, bukan command ini.

Lakukan siklus **Full Audit, Auto-Patch & Security Hardening** untuk modul/alur: **$ARGUMENTS**

Jalankan tahapan berikut secara berurutan:
1. **Audit Logic & Alur Bisnis (`db-code-auditor`)**:
   - Telusuri kode dan skema DB terkait **$ARGUMENTS** untuk menemukan celah logika atau proses yang bolong.
2. **Pembuatan Tambalan Fungsional (`fullstack-patcher`)**:
   - Buatkan file migrasi SQL di folder `sql/`, validasi/modul backend, dan UI sesuai aturan `CLAUDE.md`.
3. **Audit & Hardening Keamanan (`security-auditor-patcher`)**:
   - Periksa seluruh kode eksisting maupun kode baru hasil tambalan di atas terhadap celah SQL Injection, otorisasi sesi, validasi server-side, dan kebocoran error, lalu terapkan patch keamanannya.
   - *Catatan: Berhenti sejenak setelah file migrasi SQL dan patch kode selesai dibuat agar saya bisa mengeksekusi migrasi SQL secara manual.*
4. **Pengujian Celah, Keamanan & Alur (`qa-test-engineer`)**:
   - Susun skenario uji fungsional (positif/negatif) dan uji penetrasi dasar untuk memastikan celah logika maupun keamanan sudah tertutup.
5. **Dokumentasi (`tech-writer`)**:
   - Buat panduan pengguna (`docs/HOWTO_<slug-nama-fitur>.md`) yang menjelaskan alur kerja baru yang sudah lengkap dan aman.
