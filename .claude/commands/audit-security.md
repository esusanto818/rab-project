---
description: Audit keamanan menyeluruh (SQLi, Auth/Session, XSS, Validasi Server) dan terapkan patch keamanan pada modul target.
---
Lakukan **Security Audit & Hardening** pada file atau modul: **$ARGUMENTS**

Jalankan tahapan berikut:
1. Panggil sub-agen `security-auditor-patcher` untuk memindai seluruh file backend (`/php-crud/`) dan frontend (`/js/`, `/views/`) terkait **$ARGUMENTS**.
2. Identifikasi seluruh kerentanan (SQL Injection tanpa `pg_query_params`, bypass sesi/otorisasi, input tidak tervalidasi, kebocoran pesan error DB, dan XSS pada ExtJS).
3. Terapkan tambalan keamanan secara langsung ke file terkait berupa edit terarah (diff minimal) dengan komentar penanda perubahan; jangan menulis ulang file eksisting secara penuh.
4. Minta sub-agen `qa-test-engineer` menyusun payload pengujian keamanan (uji negatif) untuk memverifikasi bahwa celah tersebut sudah tertutup.
