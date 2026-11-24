#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
mkdir -p tmp_bak
cp css/app-theme.css "tmp_bak/app-theme.css.bak-$(date +%Y%m%d%H%M%S)"

python3 - << 'PYEOF'
with open("css/app-theme.css", "r", encoding="utf-8") as f:
    content = f.read()

start_marker = "/* 3. LIGHT THEME (Gambar 3 — Soft Sage & Mint) */"
end_marker = "/* ------------------------------------------------------------------------\n   Global Transition"

s_idx = content.find(start_marker)
e_idx = content.find(end_marker)

if s_idx == -1 or e_idx == -1:
    raise SystemExit("[ERROR] Blok LIGHT THEME tidak ditemukan di css/app-theme.css")

new_light = """/* 3. LIGHT THEME (Gambar 3 — Soft Sage & Mint Full Palette, Tanpa Putih Polos) */
html[data-theme="light"] {
  --bg-main: #C2D9AD;
  --bg-panel: #DCE8CF;
  --bg-card: #DCE8CF;
  --header-bg: #375647;
  --header-text: #E9F2E1;
  --border-color: #91A4A8;
  --text-main: #263D32;
  --text-muted: #375647;
  --primary: #729E7E;
  --primary-hover: #375647;
  --accent-strong: #94BC9B;
  --grid-header-bg: #94BC9B;
  --grid-header-text: #263D32;
  --grid-row-bg: #E4EED9;
  --grid-row-alt: #D6E4C7;
  --grid-cell-text: #263D32;
  --grid-selected-bg: #729E7E;
  --grid-selected-text: #FFFFFF;
  --input-bg: #E9F2E1;
  --input-text: #263D32;
  --input-border: #91A4A8;
}

"""

updated = content[:s_idx] + new_light + content[e_idx:]

# Override kelas putih polos pada dashboard & ExtJS khusus saat html[data-theme="light"]
override_marker = "/* SAGE LIGHT SURFACE OVERRIDE (NO PLAIN WHITE) */"
if override_marker not in updated:
    updated += """

/* SAGE LIGHT SURFACE OVERRIDE (NO PLAIN WHITE) */
html[data-theme="light"] .bg-white,
html[data-theme="light"] .bg-slate-50,
html[data-theme="light"] .bg-gray-50,
html[data-theme="light"] .dashboard-card,
html[data-theme="light"] .card,
html[data-theme="light"] .x-panel-body,
html[data-theme="light"] .x-grid3,
html[data-theme="light"] .x-window-mc,
html[data-theme="light"] .x-tab-panel-body {
  background-color: var(--bg-card) !important;
  border-color: var(--border-color) !important;
}

html[data-theme="light"] .x-grid3-row {
  background-color: var(--grid-row-bg) !important;
}
html[data-theme="light"] .x-grid3-row-alt {
  background-color: var(--grid-row-alt) !important;
}
html[data-theme="light"] .x-grid3-row-selected {
  background-color: var(--grid-selected-bg) !important;
}
html[data-theme="light"] .x-grid3-row-selected td,
html[data-theme="light"] .x-grid3-row-selected .x-grid3-cell-inner {
  color: var(--grid-selected-text) !important;
}
"""

with open("css/app-theme.css", "w", encoding="utf-8") as f:
    f.write(updated)

print("[OK] Palet Soft Sage & Mint (#375647, #729E7E, #94BC9B, #C2D9AD, #91A4A8) diterapkan penuh untuk Light Theme!")
PYEOF
