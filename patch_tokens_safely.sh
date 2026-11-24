#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
mkdir -p tmp_bak

# 1. Backup css/app-theme.css 54 KB yang sudah dipulihkan
cp css/app-theme.css "tmp_bak/app-theme.css.bak-$(date +%Y%m%d%H%M%S)"

# 2. Ganti HANYA blok DESIGN TOKENS (BAGIAN A) dan tambahkan UNIFIED BUTTON di akhir file
python3 - << 'PYEOF'
with open("css/app-theme.css", "r", encoding="utf-8") as f:
    content = f.read()

start_marker = ":root, html[data-theme=\"light\"] {"
end_marker = "/* ------------------------------------------------------------------------\n   Global Transition"

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx == -1 or end_idx == -1:
    raise SystemExit("[ERROR] Marker token asli tidak ditemukan. Tidak ada file yang diubah.")

new_tokens = """/* 1. DEFAULT THEME (Gambar 1 — California Dream) */
:root, html[data-theme="default"] {
  --bg-main: #FCF7EF;
  --bg-panel: #FFFFFF;
  --bg-card: #FFFFFF;
  --header-bg: #243958;
  --header-text: #FCF7EF;
  --border-color: #7FC8D6;
  --text-main: #243958;
  --text-muted: #4379A2;
  --primary: #4379A2;
  --primary-hover: #243958;
  --accent-strong: #F8C5AA;
  --grid-header-bg: #F4DB96;
  --grid-header-text: #243958;
  --grid-row-bg: #FFFFFF;
  --grid-row-alt: #FCF7EF;
  --grid-cell-text: #243958;
  --grid-selected-bg: #7FC8D6;
  --grid-selected-text: #243958;
  --input-bg: #FFFFFF;
  --input-text: #243958;
  --input-border: #7FC8D6;
}

/* 2. DARK THEME (Gambar 2 — Warm Obsidian & Slate) */
html[data-theme="dark"] {
  --bg-main: #202128;
  --bg-panel: #2D4354;
  --bg-card: #2D4354;
  --header-bg: #202128;
  --header-text: #FED7A5;
  --border-color: #534145;
  --text-main: #FED7A5;
  --text-muted: #C5C2B8;
  --primary: #9E6752;
  --primary-hover: #73766A;
  --accent-strong: #FED7A5;
  --grid-header-bg: #534145;
  --grid-header-text: #FED7A5;
  --grid-row-bg: #2D4354;
  --grid-row-alt: #253746;
  --grid-cell-text: #FED7A5;
  --grid-selected-bg: #9E6752;
  --grid-selected-text: #FFFFFF;
  --input-bg: #202128;
  --input-text: #FED7A5;
  --input-border: #73766A;
}

/* 3. LIGHT THEME (Gambar 3 — Soft Sage & Mint) */
html[data-theme="light"] {
  --bg-main: #C2D9AD;
  --bg-panel: #F4F9F1;
  --bg-card: #F4F9F1;
  --header-bg: #375647;
  --header-text: #F4F9F1;
  --border-color: #91A4A8;
  --text-main: #375647;
  --text-muted: #4E725E;
  --primary: #729E7E;
  --primary-hover: #375647;
  --accent-strong: #94BC9B;
  --grid-header-bg: #94BC9B;
  --grid-header-text: #375647;
  --grid-row-bg: #F4F9F1;
  --grid-row-alt: #E5F0DE;
  --grid-cell-text: #375647;
  --grid-selected-bg: #729E7E;
  --grid-selected-text: #FFFFFF;
  --input-bg: #FFFFFF;
  --input-text: #375647;
  --input-border: #91A4A8;
}

"""

updated = content[:start_idx] + new_tokens + content[end_idx:]

# Tambahkan aturan tombol seragam di akhir file jika belum ada
btn_marker = "UNIFIED BUTTON SYSTEM (SSOT)"
if btn_marker not in updated:
    updated += """

/* ==========================================================================
   UNIFIED BUTTON SYSTEM (SSOT) — SERAGAM BENTUK & WARNA DI SEMUA THEME
   ========================================================================== */
.x-btn {
  border-radius: 5px !important;
  overflow: hidden;
}
.x-btn-tl, .x-btn-tr, .x-btn-tc,
.x-btn-ml, .x-btn-mr, .x-btn-mc,
.x-btn-bl, .x-btn-br, .x-btn-bc {
  background-image: none !important;
}
.x-btn-mc {
  height: 28px !important;
  padding: 0 12px !important;
  background-color: var(--bg-panel) !important;
  border: 1px solid var(--border-color) !important;
  border-radius: 5px !important;
}
.x-btn button {
  font-size: 12px !important;
  font-weight: 600 !important;
  line-height: 28px !important;
  color: var(--text-main) !important;
}
.x-btn-over .x-btn-mc {
  background-color: var(--grid-header-bg) !important;
}

/* Primary / Simpan / Submit */
.btn-primary .x-btn-mc, .btn-amber .x-btn-mc {
  background-color: var(--primary) !important;
  border-color: var(--primary-hover) !important;
}
.btn-primary button, .btn-amber button {
  color: #FFFFFF !important;
}
.btn-primary.x-btn-over .x-btn-mc, .btn-amber.x-btn-over .x-btn-mc {
  background-color: var(--primary-hover) !important;
}

/* Dark / Cetak / Export */
.btn-dark .x-btn-mc {
  background-color: var(--header-bg) !important;
  border-color: var(--border-color) !important;
}
.btn-dark button {
  color: var(--header-text) !important;
}

/* Danger / Hapus / Batal */
.btn-danger .x-btn-mc {
  background-color: #DC2626 !important;
  border-color: #B91C1C !important;
}
.btn-danger button {
  color: #FFFFFF !important;
}
.btn-danger.x-btn-over .x-btn-mc {
  background-color: #B91C1C !important;
}
"""

with open("css/app-theme.css", "w", encoding="utf-8") as f:
    f.write(updated)

print("[OK] Token 3 tema dan tombol seragam berhasil dipatch ke css/app-theme.css (ukuran utuh ~56 KB).")
PYEOF
