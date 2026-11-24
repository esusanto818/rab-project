#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
CLAUDE_DIR="."
[[ -d ".claude" ]] && CLAUDE_DIR=".claude"
mkdir -p "$CLAUDE_DIR/commands"

cat << 'CMDEOF' > "$CLAUDE_DIR/commands/patch-all-remaining.md"
---
description: Eksekusi berantai seluruh sisa temuan OPEN di docs/DEEP_AUDIT_REGISTER.md (Batch 3, Batch 4, hingga 100% VERIFIED) menggunakan fullstack-patcher.
---

Baca `docs/DEEP_AUDIT_REGISTER.md` dan selesaikan **seluruh temuan yang masih berstatus `OPEN`** secara berurutan per batch sampai 100% berstatus `VERIFIED`:

### Urutan Eksekusi Berantai
1. **PATCH BATCH 3 (Backend User & RBAC):**
   - **GAP-USR-01 (HIGH):** Bungkus mutasi hak akses/user (`cmdQ()` / modul user & role) ke dalam transaksi eksplisit `BEGIN/COMMIT` dengan `ROLLBACK` otomatis saat error.
   - **GAP-USR-02 (MEDIUM):** Lengkapi pemetaan `endpointAliases` agar modul/endpoint baru tidak terblokir 403.
   - **GAP-USR-03 (MEDIUM):** Tambahkan validasi eksistensi `form_id` pada penyimpanan User Role agar tidak masuk data sampah.
2. **PATCH BATCH 4 (Frontend Lifecycle & Infrastruktur E2E):**
   - **GAP-USR-04 (LOW):** Ganti manipulasi DOM langsung di modul User System agar sinkron dengan ExtJS 3.4.1 component lifecycle.
   - **FA-01 (HIGH):** Perbaiki infrastruktur login E2E test (`tests/run_e2e_client_supplied.php` / `127.0.0.1:8098`) agar pengujian otomatis lulus.
3. **PATCH BATCH 5 (Final Audit Sweep):**
   - Cek ulang seluruh tabel di `docs/DEEP_AUDIT_REGISTER.md`. Jika masih ada item lain yang berstatus `OPEN` atau `PARTIAL`, tuntaskan hingga seluruh temuan berstatus `VERIFIED`.

### Aturan Wajib (Hard Gate)
- Delegasikan pengerjaan kode ke sub-agent `fullstack-patcher`.
- **Backup Wajib:** Sebelum menyunting file apa pun, wajib salin ke `tmp_bak/<nama_file>.bak`.
- **Surgical Edit & Verifikasi:** Gunakan `Edit` terarah (dilarang full-rewrite untuk file `> 150` baris) dan jalankan `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <target>` setiap selesai 1 file.
- **Isolasi CSS:** Dilarang menyentuh `css/app-theme.css`.
- **Penutupan Register:** Ubah seluruh status temuan di `docs/DEEP_AUDIT_REGISTER.md` menjadi `VERIFIED`, ubah `Audit Status` menjadi `COMPLETE`, dan catat log lengkap di `docs/SESSION_LOG.md`.
CMDEOF

echo "[OK] Command /patch-all-remaining siap digunakan di Claude Code!"
