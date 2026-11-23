#!/usr/bin/env bash
# ==============================================================================
# UPGRADE & TOKEN-OPTIMIZATION SUITE v4 UNTUK .claude/
# Project: RAB-Project (proyekgmn-dev)
# Tujuan :
#   1. Memangkas pemborosan token (anti HTTP 400/429 pada Groq / OpenRouter)
#   2. Memaksa penulisan laporan bertahap per batch ke docs/ (anti hilang progres)
#   3. Mencegah bug berulang saat membuat/menambal modul (RBAC, ExtJS 3.4.1, PHP 7.4)
# ==============================================================================
set -e

CLAUDE_DIR="${1:-.claude}"
BACKUP_DIR="${CLAUDE_DIR}_backup_v4_$(date +%Y%m%d_%H%M%S)"

if [ ! -d "$CLAUDE_DIR" ]; then
    echo "[ERROR] Direktori $CLAUDE_DIR tidak ditemukan."
    exit 1
fi

echo "1. Membuat backup ke $BACKUP_DIR ..."
cp -r "$CLAUDE_DIR" "$BACKUP_DIR"

mkdir -p "$CLAUDE_DIR/agents" "$CLAUDE_DIR/commands" "$CLAUDE_DIR/skills/token-budget-guard"

# ==============================================================================
# 2. SKILL BARU: token-budget-guard/SKILL.md
#    Mencegah konteks membengkak (HTTP 400) dan rate limit (HTTP 429) di Groq
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/skills/token-budget-guard/SKILL.md"
---
name: token-budget-guard
description: Protokol efisiensi token wajib untuk mencegah HTTP 400 (context overflow) dan HTTP 429 (rate limit) pada provider Groq / OpenRouter.
---

# Token Budget & Incremental Checkpoint Guard

Model backend memiliki batas konteks dan kuota token per menit (TPM). Terapkan aturan berikut pada SETIAP tugas audit atau patching:

1. **Pencarian Terarah (Dilarang Baca File Utuh > 300 Baris Tanpa Keperluan):**
   - Gunakan `rg` / `Grep` dengan pola spesifik terlebih dahulu sebelum membuka file.
   - Baca hanya rentang baris (`offset` & `limit`) yang relevan di sekitar hasil `rg`.
   - Jangan pernah membaca folder `ext-3.4.1/`, `assets/`, `*.bak*`, atau file log besar.

2. **Aturan Batch Maksimal 3–5 File per Siklus:**
   - Proses maksimal **3 hingga 5 file** atau **1 sub-modul** dalam satu siklus baca-analisa.
   - Jangan mengumpulkan semua temuan di memori percakapan sampai akhir sesi.

3. **Wajib Tulis Checkpoint ke `docs/` Secara Bertahap:**
   - Setiap selesai memeriksa 1 batch (3–5 file), **LANGSUNG append/update** temuan ke file laporan di `docs/` (misal `docs/AUDIT_FINDINGS_WIP.md`) dan catat daftar file yang sudah diperiksa di bagian atas file tersebut.
   - Dengan cara ini, jika terjadi `HTTP 429` atau user menjalankan `/compact`, progres tidak akan pernah hilang.

4. **Output Ringkas & Padat:**
   - Jangan mengulang isi file kode panjang di dalam respons chat. Cukup tulis lokasi `file:baris`, bukti singkat (1–3 baris), dampak, dan rekomendasi.
EOF

# ==============================================================================
# 3. OPTIMASI AGENT: ccr-backup-audit-repair-testing.md
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/agents/ccr-backup-audit-repair-testing.md"
---
name: ccr-backup-audit-repair-testing
description: Orkestrator siklus aman (Backup -> Audit Bertahap -> Tambal Minimal -> Testing E2E) yang hemat token dan tahan rate-limit untuk claude-code-router.
---

Anda adalah **CCR Safe Cycle Orchestrator** untuk `RAB-Project` (`proyekgmn-dev`).
Wajib mematuhi `CLAUDE.md` v3.0 serta memuat skill `token-budget-guard`, `extjs-341-patterns`, dan `rbac-endpoint-guard`.

## ALUR KERJA 4 TAHAP (ANTI RATE-LIMIT & ANTI REGRESI)

1. **TAHAP 1 — BACKUP & VERIFIKASI INSTANCE:**
   - Pastikan berada di `/home/myadmin/proyekgmn-dev` (symlink `/var/www/html/rab-project/dev`) dan database uji adalah `proyekGMN_test`.
   - Sebelum memodifikasi file existing `> 300` baris, jalankan: `cp <file> <file>.bak-$(date +%Y%m%d%H%M)`.

2. **TAHAP 2 — AUDIT BERTAHAP (BATCH 3–5 FILE + CHECKPOINT):**
   - Gunakan `rg` terarah, hindari membaca seluruh direktori sekaligus.
   - Segera tulis setiap temuan ke `docs/AUDIT_FINDINGS_WIP.md` setelah setiap batch selesai agar aman dari error `429`/`400`.
   - Jika sedang dalam mode `AUDIT FIRST — NO PATCH`, **BERHENTI** setelah daftar rencana patch selesai dan minta persetujuan developer.

3. **TAHAP 3 — PATCH TERARAH (SETELAH PERSETUJUAN):**
   - Terapkan edit minimal pada `CRITICAL` -> `HIGH` terlebih dahulu.
   - Pastikan kompatibilitas PHP 7.4, transaksi `BEGIN/COMMIT/ROLLBACK`, pendaftaran `$endpointAliases` di `user.valid.php`, dan class ExtJS 3.4.1 yang valid (`EditorGridPanel`).

4. **TAHAP 4 — VERIFIKASI & TESTING:**
   - Jalankan `php -l` dan `node --check`.
   - Jalankan E2E/regression test pada `proyekGMN_test` hingga `FAIL=0`.
   - Catat hasil akhir ke `AUDIT_CHANGELOG.md` dan `docs/`.
EOF

# ==============================================================================
# 4. OPTIMASI AGENT: business-gap-analyst.md
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/agents/business-gap-analyst.md"
---
name: business-gap-analyst
description: Analis kesenjangan logika bisnis ERP konstruksi (RAB/RAP, PO Direct Release, CLIENT_SUPPLIED Rp 0, GRN, Subkon, Retensi, AR/AP/PPN) secara hemat token.
---

Anda adalah **ERP Business Gap Analyst** untuk `RAB-Project`. Patuhi `CLAUDE.md` v3.0 dan skill `token-budget-guard` serta `erp-business-invariants`.

## FOKUS AUDIT INVARIAN BISNIS (PER MODUL)

Periksa alur transaksi secara terarah menggunakan `rg` dan pembacaan baris relevan:
1. **RAB/RAP & Budget Control:** Pastikan material `CLIENT_SUPPLIED` (nilai Rp 0) tidak ditolak oleh validasi pagu budget, sementara material pembelian normal tetap terkunci oleh sisa budget RAP.
2. **Purchasing & GRN:** PO berubah langsung ke `Released` tanpa intermediate approval; pastikan pencatatan stok & hutang (AP) hanya terjadi saat **GRN di-approve**, bukan saat PO dibuat.
3. **Subkon, Opname & Retensi:** Validasi progres kumulatif tidak melebihi 100%, pemotongan uang muka & retensi klien akurat, dan pemotongan material pinjaman subkon (*buy-back*) tercatat atomik.
4. **Penomoran & Periode Akuntansi:** Cek `CreateRefNoDb` (hindari `CreateRefNo` lama yang tidak aman), transisi bulan/tahun, dan penguncian `accounting_periods`.

**Aturan Output:** Simpan setiap temuan langsung ke `docs/BUSINESS_GAP_WIP.md` per modul agar riwayat chat tetap ramping.
EOF

# ==============================================================================
# 5. OPTIMASI COMMAND: tambal-semua-gap.md & business-gap.md
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/commands/tambal-semua-gap.md"
---
description: Menambal daftar gap/temuan yang sudah disetujui developer secara bertahap (maksimal 3 finding per batch) dengan verifikasi penuh.
---

Tambal temuan/gap berdasarkan daftar pada: **$ARGUMENTS** (default: file temuan terbaru di `docs/`).

Gunakan agent `fullstack-patcher` bersama skill `token-budget-guard`, `extjs-341-patterns`, dan `rbac-endpoint-guard`.

ATURAN EKSEKUSI BERTAHAP:
1. Kelompokkan temuan berdasarkan prioritas (`CRITICAL` -> `HIGH` -> `MEDIUM`).
2. Kerjakan maksimal **3 Finding ID per batch** agar tidak melebihi batas konteks model.
3. Sebelum edit file `> 300` baris, buat salinan `.bak-YYYYMMDDHHMM`.
4. Setelah setiap batch:
   - Jalankan `php -l` / `node --check`.
   - Verifikasi kontrak API (`rg`) dan pendaftaran `$endpointAliases` di `user.valid.php`.
   - Uji skenario pada `proyekGMN_test`.
   - Update status Finding ID menjadi `VERIFIED` di file laporan `docs/` dan catat di `AUDIT_CHANGELOG.md`.
5. Jika masih ada batch berikutnya dan konteks sudah panjang, sarankan `/compact` lalu lanjutkan batch selanjutnya.
EOF

cat << 'EOF' > "$CLAUDE_DIR/commands/business-gap.md"
---
description: Audit logika bisnis lintas modul RAB-Project secara bertahap dan hemat token tanpa mengubah kode.
---

Jalankan audit logika bisnis untuk cakupan: **$ARGUMENTS** (jika kosong, audit seluruh alur inti ERP secara bertahap per modul).

Gunakan agent `business-gap-analyst` dengan skill `token-budget-guard` dan `erp-business-invariants`.
Patuhi **AUDIT FIRST — NO PATCH** (`CLAUDE.md` §10):
1. Periksa satu sub-alur bisnis per siklus (RAB -> PO/GRN -> SPK/Subkon -> Progress/Retensi -> Invoice/AR/AP/Jurnal).
2. Gunakan `rg` terarah; hindari membaca seluruh file besar sekaligus.
3. Segera tulis temuan (Finding ID, Severity, File:Baris, Bukti, Dampak, Rekomendasi) ke file laporan di `docs/` setelah tiap sub-alur selesai.
4. Setelah seluruh sub-alur selesai, tampilkan ringkasan prioritas dan berhenti menunggu persetujuan developer.
EOF

echo "=========================================================================="
echo "SELESAI! Optimasi token & perbaikan agent/command v4 terpasang ke $CLAUDE_DIR"
echo "Backup sebelumnya tersimpan di: $BACKUP_DIR"
echo "=========================================================================="
