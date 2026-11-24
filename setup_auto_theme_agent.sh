#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
mkdir -p tmp_bak css

# Deteksi lokasi folder .claude atau root
CLAUDE_DIR="."
[[ -d ".claude" ]] && CLAUDE_DIR=".claude"
mkdir -p "$CLAUDE_DIR/agents" "$CLAUDE_DIR/commands"

# 1. Backup css/app-theme.css jika sudah ada, lalu append standar tombol seragam
if [[ -f "css/app-theme.css" ]]; then
  cp css/app-theme.css "tmp_bak/app-theme.css.bak-$(date +%Y%m%d%H%M%S)"
fi

if ! grep -q "UNIFIED BUTTON SYSTEM" css/app-theme.css 2>/dev/null; then
cat << 'CSSEOF' >> css/app-theme.css

/* ==========================================================================
   UNIFIED BUTTON SYSTEM — INDUSTRIAL SLATE & STEEL AMBER (SSOT)
   ========================================================================== */
:root {
  --slate-900: #0f172a;
  --slate-800: #1e293b;
  --slate-700: #334155;
  --slate-200: #e2e8f0;
  --slate-100: #f1f5f9;
  --amber-600: #d97706;
  --amber-500: #f59e0b;
  --amber-700: #b45309;
  --danger-600: #dc2626;
  --danger-700: #b91c1c;
  --btn-radius: 5px;
  --btn-height: 28px;
}

.x-btn {
  border-radius: var(--btn-radius) !important;
  overflow: hidden;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}
.x-btn-tl, .x-btn-tr, .x-btn-tc,
.x-btn-ml, .x-btn-mr, .x-btn-mc,
.x-btn-bl, .x-btn-br, .x-btn-bc {
  background-image: none !important;
}
.x-btn-mc {
  height: var(--btn-height) !important;
  padding: 0 12px !important;
  vertical-align: middle !important;
}
.x-btn button {
  font-family: inherit !important;
  font-size: 12px !important;
  font-weight: 600 !important;
  letter-spacing: 0.02em;
  line-height: var(--btn-height) !important;
}

/* Default / Sekunder (Industrial Slate Outline) */
.x-btn-noicon .x-btn-mc,
.x-btn-text-icon .x-btn-mc,
.btn-slate .x-btn-mc {
  background-color: var(--slate-100) !important;
  border: 1px solid #cbd5e1 !important;
  border-radius: var(--btn-radius) !important;
}
.x-btn button, .btn-slate button { color: var(--slate-800) !important; }
.x-btn-over .x-btn-mc, .btn-slate.x-btn-over .x-btn-mc {
  background-color: var(--slate-200) !important;
  border-color: var(--slate-700) !important;
}

/* Primary / Simpan / Submit (Steel Amber) */
.btn-primary .x-btn-mc, .btn-amber .x-btn-mc {
  background-color: var(--amber-500) !important;
  border: 1px solid var(--amber-600) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-primary button, .btn-amber button { color: #ffffff !important; }
.btn-primary.x-btn-over .x-btn-mc, .btn-amber.x-btn-over .x-btn-mc {
  background-color: var(--amber-600) !important;
  border-color: var(--amber-700) !important;
}

/* Dark / Cetak / Export (Industrial Slate Solid) */
.btn-dark .x-btn-mc {
  background-color: var(--slate-800) !important;
  border: 1px solid var(--slate-900) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-dark button { color: #f8fafc !important; }
.btn-dark.x-btn-over .x-btn-mc { background-color: var(--slate-700) !important; }

/* Danger / Hapus / Batal */
.btn-danger .x-btn-mc {
  background-color: var(--danger-600) !important;
  border: 1px solid var(--danger-700) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-danger button { color: #ffffff !important; }
.btn-danger.x-btn-over .x-btn-mc { background-color: var(--danger-700) !important; }

/* Kompatibilitas Tombol HTML / Non-ExtJS */
.app-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 32px;
  padding: 0 14px;
  font-size: 12px;
  font-weight: 600;
  border-radius: var(--btn-radius);
  border: 1px solid #cbd5e1;
  background-color: var(--slate-100);
  color: var(--slate-800);
  cursor: pointer;
  transition: all 0.15s ease;
}
.app-btn:hover { background-color: var(--slate-200); }
.app-btn-primary { background-color: var(--amber-500); border-color: var(--amber-600); color: #fff; }
.app-btn-primary:hover { background-color: var(--amber-600); }
.app-btn-dark { background-color: var(--slate-800); border-color: var(--slate-900); color: #f8fafc; }
.app-btn-danger { background-color: var(--danger-600); border-color: var(--danger-700); color: #fff; }
CSSEOF
  echo "[OK] Standar tombol seragam ditambahkan ke css/app-theme.css."
fi

# 2. Tambahkan aturan standar CSS & Button ke CLAUDE.md jika belum ada
if ! grep -q "Standar Pemisahan CSS & Button Seragam" CLAUDE.md; then
cat << 'MDEOF' >> CLAUDE.md

---
## 14. STANDAR PEMISAHAN CSS & BUTTON SERAGAM (SSOT)
1. **Dilarang CSS di dalam JS:** Dilarang keras menulis inline `style: '...'`, `Ext.util.CSS.createStyleSheet`, `setStyle`, atau `<style>` di dalam file `js/*.js`. Semua aturan visual wajib dipindahkan ke `css/app-theme.css` dan dipanggil melalui `cls` / `iconCls`.
2. **Standar Tombol Seragam (Industrial Slate & Steel Amber):**
   - Aksi Simpan / Submit / Approve: `cls: 'btn-primary'` (ExtJS) atau `class="app-btn app-btn-primary"` (HTML).
   - Aksi Cetak / Export / Filter: `cls: 'btn-dark'` atau `class="app-btn app-btn-dark"`.
   - Aksi Hapus / Void / Tolak: `cls: 'btn-danger'` atau `class="app-btn app-btn-danger"`.
   - Aksi Umum / Tambah / Edit / Batal: tanpa `cls` (otomatis default Slate) atau `cls: 'btn-slate'`.
MDEOF
  echo "[OK] Aturan CSS & Button dikunci di CLAUDE.md."
fi

# 3. Update agent theme-styler-auditor.md untuk eksekusi otomatis massal
cat << 'AGENTEOF' > "$CLAUDE_DIR/agents/theme-styler-auditor.md"
---
name: theme-styler-auditor
description: Audit dan migrasi otomatis seluruh CSS inline di file JS ke css/app-theme.css serta penyeragaman bentuk dan warna button (Industrial Slate & Steel Amber).
tools: Read, Edit, Bash, Grep, Glob
---

Anda adalah **Theme & Styler Auditor** untuk proyek `proyekgmn-dev`.
Tugas Anda adalah mengeksekusi pembersihan CSS dan penyeragaman tombol secara menyeluruh dan otomatis tanpa meminta intervensi manual user per file.

### Langkah Kerja Wajib:
1. **Inventarisasi Otomatis:**
   Jalankan pencarian di `js/` dan `views/` untuk menemukan:
   - Semua `style:`, `createStyleSheet`, `setStyle`, `applyStyles`, atau tag `<style>` di `js/*.js`.
   - Semua definisi tombol (`Ext.Button`, `buttons:`, `tbar:`, `<button>`) yang belum memakai standar `cls` (`btn-primary`, `btn-dark`, `btn-danger`, `btn-slate`) atau masih memakai warna/ukuran custom.
2. **Ekstraksi & Konsolidasi ke `css/app-theme.css`:**
   - Pastikan `css/app-theme.css` di-load paling akhir di header/template global (`views/` atau `php-func/`) setelah `ext-all.css`.
   - Pindahkan aturan CSS yang ditemukan di file JS ke class semantik di `css/app-theme.css`.
3. **Migrasi Presisi per File (Surgical Edit + Hard Gate):**
   - Proses setiap file satu per satu secara berurutan.
   - Sebelum mengedit file `<target>`, jalankan: `cp <target> tmp_bak/$(basename <target>).bak`
   - Gunakan `Edit` (DILARANG full-rewrite `Write` untuk file > 150 baris) untuk mengganti inline `style:` dengan `cls:` yang sesuai standar Bagian 14 `CLAUDE.md`:
     - Simpan/Submit/Proses -> `cls: 'btn-primary'`
     - Cetak/PDF/Excel/Export -> `cls: 'btn-dark'`
     - Hapus/Delete/Batal/Reset -> `cls: 'btn-danger'`
     - Lainnya -> hapus custom style agar otomatis memakai default Slate dari `app-theme.css`.
   - Segera jalankan `./verify_patch_diff.sh tmp_bak/$(basename <target>).bak <target>` setelah setiap file selesai diedit.
4. **Laporan Akhir:**
   - Simpan laporan lengkap di `docs/YYYY-MM-DD_theme-styler-auditor_CSS_BUTTON_UNIFICATION.md`.
AGENTEOF

# 4. Update slash command /update-theme.md agar langsung memicu orkestrasi otomatis
cat << 'CMDEOF' > "$CLAUDE_DIR/commands/update-theme.md"
---
description: Jalankan audit dan migrasi otomatis seluruh CSS di file JS ke css/app-theme.css serta seragamkan bentuk dan warna semua button ke tema Industrial Slate & Steel Amber.
---

Gunakan sub-agent `theme-styler-auditor` untuk menjalankan tugas berikut secara otomatis sampai tuntas:

1. **Audit & Satukan CSS:** Cari seluruh CSS inline (`style:`, `createStyleSheet`, `setStyle`, `<style>`) di dalam folder `js/` dan `views/`, pindahkan seluruh aturannya ke `css/app-theme.css` sebagai Single Source of Truth, dan pastikan `css/app-theme.css` dimuat paling akhir setelah `ext-all.css`.
2. **Seragamkan Semua Button:** Ubah seluruh tombol di `js/*.js` dan `views/*.php` agar seragam bentuk dan warnanya mengikuti standar **Industrial Slate & Steel Amber** (`btn-primary`, `btn-dark`, `btn-danger`, atau default Slate) tanpa inline style warna/tinggi.
3. **Wajib Patuhi Hard Gate:** Untuk setiap file yang diubah, wajib backup ke `tmp_bak/<nama_file>.bak`, gunakan `Edit` terarah (bukan full rewrite), dan jalankan `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <path_file>` sebelum lanjut ke file berikutnya.
4. Dokumentasikan seluruh file yang diubah ke `docs/SESSION_LOG.md` dan laporan audit tema di `docs/`.
CMDEOF

echo "[SELESAI] Agent theme-styler-auditor dan command /update-theme siap dijalankan!"
