#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
mkdir -p tmp_bak css

if [[ -f "css/app-theme.css" ]]; then
  cp css/app-theme.css "tmp_bak/app-theme.css.bak-$(date +%Y%m%d%H%M%S)"
fi

cat << 'CSSEOF' > css/app-theme.css
/* ==========================================================================
   RAB-PROJECT UNIFIED THEME SYSTEM (SSOT)
   - Default Theme : California Dream (Gambar 1)
   - Dark Theme    : Warm Obsidian & Slate (Gambar 2) -> [data-theme="dark"]
   - Light Theme   : Soft Sage & Mint (Gambar 3)      -> [data-theme="light"]
   ========================================================================== */

/* 1. DEFAULT THEME (Gambar 1 — California Dream) */
:root,
[data-theme="default"] {
  --bg-app: #FCF7EF;
  --bg-surface: #FFFFFF;
  --bg-header: #243958;
  --bg-sidebar: #243958;
  --bg-subtle: #F4DB96;

  --text-main: #243958;
  --text-muted: #4379A2;
  --text-inverse: #FCF7EF;

  --border-color: #7FC8D6;
  --accent-primary: #4379A2;
  --accent-primary-hover: #243958;
  --accent-secondary: #7FC8D6;
  --accent-warm: #F8C5AA;
  --accent-highlight: #F4DB96;
  --danger-bg: #dc2626;
  --danger-hover: #b91c1c;

  --btn-radius: 5px;
  --btn-height: 28px;
}

/* 2. DARK THEME (Gambar 2 — Warm Obsidian & Slate) */
[data-theme="dark"] {
  --bg-app: #202128;
  --bg-surface: #2D4354;
  --bg-header: #202128;
  --bg-sidebar: #202128;
  --bg-subtle: #534145;

  --text-main: #FED7A5;
  --text-muted: #73766A;
  --text-inverse: #202128;

  --border-color: #534145;
  --accent-primary: #9E6752;
  --accent-primary-hover: #FED7A5;
  --accent-secondary: #73766A;
  --accent-warm: #FED7A5;
  --accent-highlight: #534145;
  --danger-bg: #dc2626;
  --danger-hover: #b91c1c;
}

/* 3. LIGHT THEME (Gambar 3 — Soft Sage & Mint) */
[data-theme="light"] {
  --bg-app: #C2D9AD;
  --bg-surface: #F4F9F1;
  --bg-header: #375647;
  --bg-sidebar: #375647;
  --bg-subtle: #94BC9B;

  --text-main: #375647;
  --text-muted: #729E7E;
  --text-inverse: #F4F9F1;

  --border-color: #91A4A8;
  --accent-primary: #729E7E;
  --accent-primary-hover: #375647;
  --accent-secondary: #94BC9B;
  --accent-warm: #91A4A8;
  --accent-highlight: #C2D9AD;
  --danger-bg: #dc2626;
  --danger-hover: #b91c1c;
}

/* ==========================================================================
   BASE LAYOUT & EXTJS 3.4.1 SURFACE ADAPTATION
   ========================================================================== */
body {
  background-color: var(--bg-app) !important;
  color: var(--text-main) !important;
}

.x-panel-header,
.x-window-header,
.x-tab-strip-wrap {
  background-color: var(--bg-header) !important;
  background-image: none !important;
  color: var(--text-inverse) !important;
  border-color: var(--border-color) !important;
}

.x-panel-body,
.x-grid3,
.x-form-field {
  background-color: var(--bg-surface) !important;
  color: var(--text-main) !important;
  border-color: var(--border-color) !important;
}

.x-grid3-header {
  background-color: var(--bg-subtle) !important;
  background-image: none !important;
}

.x-grid3-hd-inner {
  color: var(--text-main) !important;
  font-weight: 600 !important;
}

/* ==========================================================================
   UNIFIED BUTTON SYSTEM (SERAGAM DI SEMUA THEME)
   ========================================================================== */
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

/* Varian Default / Sekunder */
.x-btn-noicon .x-btn-mc,
.x-btn-text-icon .x-btn-mc,
.btn-slate .x-btn-mc {
  background-color: var(--bg-surface) !important;
  border: 1px solid var(--border-color) !important;
  border-radius: var(--btn-radius) !important;
}
.x-btn button, .btn-slate button {
  color: var(--text-main) !important;
}
.x-btn-over .x-btn-mc, .btn-slate.x-btn-over .x-btn-mc {
  background-color: var(--accent-secondary) !important;
}

/* Varian Primary / Simpan / Submit */
.btn-primary .x-btn-mc, .btn-amber .x-btn-mc {
  background-color: var(--accent-primary) !important;
  border: 1px solid var(--accent-primary-hover) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-primary button, .btn-amber button {
  color: var(--text-inverse) !important;
}
.btn-primary.x-btn-over .x-btn-mc, .btn-amber.x-btn-over .x-btn-mc {
  background-color: var(--accent-primary-hover) !important;
}

/* Varian Dark / Cetak / Export */
.btn-dark .x-btn-mc {
  background-color: var(--bg-header) !important;
  border: 1px solid var(--border-color) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-dark button {
  color: var(--text-inverse) !important;
}
.btn-dark.x-btn-over .x-btn-mc {
  opacity: 0.9;
}

/* Varian Danger / Hapus / Batal */
.btn-danger .x-btn-mc {
  background-color: var(--danger-bg) !important;
  border: 1px solid var(--danger-hover) !important;
  border-radius: var(--btn-radius) !important;
}
.btn-danger button {
  color: #ffffff !important;
}
.btn-danger.x-btn-over .x-btn-mc {
  background-color: var(--danger-hover) !important;
}

/* Kompatibilitas Tombol HTML / Tailwind Views */
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
  border: 1px solid var(--border-color);
  background-color: var(--bg-surface);
  color: var(--text-main);
  cursor: pointer;
  transition: all 0.15s ease;
}
.app-btn:hover { background-color: var(--accent-secondary); }
.app-btn-primary {
  background-color: var(--accent-primary);
  border-color: var(--accent-primary-hover);
  color: var(--text-inverse);
}
.app-btn-primary:hover { background-color: var(--accent-primary-hover); }
.app-btn-dark {
  background-color: var(--bg-header);
  border-color: var(--border-color);
  color: var(--text-inverse);
}
.app-btn-danger {
  background-color: var(--danger-bg);
  border-color: var(--danger-hover);
  color: #ffffff;
}
CSSEOF

echo "[OK] css/app-theme.css berhasil diperbarui dengan 3 tema (Default, Dark, Light)."
