cat << 'EOF' > docs/SESSION_HANDOVER_20261125.md
# Handover Context — RAB-Project (`proyekgmn-dev`) Deep Audit & UAT Preparation

**Tanggal Rangkuman:** 25 November 2026  
**Direktori Proyek:** `/home/myadmin/proyekgmn-dev`  
**Branch Aktif:** `dev` (Belum di-merge ke `main` — menunggu UAT manual & verifikasi gap lintas-modul)  
**Database Uji:** `proyekGMN_test` (Env var server: `RAB_DB_NAME=proyekGMN_test`)

## 1. Status Audit & Checkpoint Git (Branch `dev`)
- `v1.0-audit-complete` (Fase 1–8 — `12/12 VERIFIED`): Transaksi atomik PDO, isolasi `CLIENT_SUPPLIED` Rp 0, pemisahan COA Retensi, validasi `accounting_periods`, upload MIME whitelist, PDF common include, & ExtJS lifecycle (`docs/DEEP_AUDIT_REGISTER.md`).
- `v1.1-audit-phase9` (Fase 9 — `E2E 12/12 PASS`): Row lock `FOR UPDATE OF gd`, guard void WHR/Subcon Invoice/PO, validasi batas kontrak termin, prepared statement PDO, & `approve_grn` pada `tests/run_e2e_client_supplied.php` (`docs/DEEP_AUDIT_PHASE9_REGISTER.md`).
- `v1.2-audit-phase10` (Fase 10 — `COMPLETE`): Indeks DB `sql/2026-11-24.phase10_indexes.sql`, rekonsiliasi Trial Balance & AR/AP Aging, & hardening `.htaccess` (`docs/DEEP_AUDIT_PHASE10_REGISTER.md`).

## 2. Konfigurasi Claude Code Router (`127.0.0.1:3458`)
- Target Utama: `Mistral/codestral-latest` | Fallback: `Mistral/codestral-2508`, `Mistral/ministral-14b-2512`, `GROQ/qwen/qwen3.8-27b`, `GROQ/openai/gpt-oss-120b`.
- Jalankan `/clear` di Claude Code sebelum setiap sesi audit baru.

## 3. Next Actions
1. Jalankan Audit Integrasi Lintas-Modul -> simpan ke `docs/CROSS_MODULE_GAP_AUDIT.md`.
2. Terapkan migrasi SQL terbaru ke DB dev/uji & eksekusi 16 skenario UAT di `docs/UAT_CHECKLIST_FINAL.html`.
3. Setelah semua lulus, merge `dev` -> `main` dengan tag `v1.0.0-prod`.
EOF
