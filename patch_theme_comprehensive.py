#!/usr/bin/env python3
"""
patch_theme_comprehensive.py
=============================
Script patching komprehensif untuk RAB Project Theme System.

MENYELESAIKAN AKAR MASALAH:
1. mainCSS.css - 8+ rule dengan !important yang menimpa semua tema
2. app-standard.css - Duplikasi patch bertumpuk (v5, PATCH FINAL, v2, v3, FINAL FIX)
3. dashboard.php - Duplikasi updateThemeUI + default HTML tombol terbalik

Cara pakai:
  python3 patch_theme_comprehensive.py              # Jalankan patch
  python3 patch_theme_comprehensive.py --dry-run    # Preview saja
  python3 patch_theme_comprehensive.py --revert     # Kembalikan dari backup
  python3 patch_theme_comprehensive.py --verify     # Verifikasi status
"""

import os
import re
import sys
import shutil
import argparse
from datetime import datetime
from pathlib import Path

# ===== KONFIGURASI =====
PROJECT_ROOT = Path(__file__).parent

# ===== RULE BERMASALAH DI mainCSS.css YANG HARUS DIKOMENTARI =====
MAINCSS_RULES_TO_DISABLE = [
    # Rule 1: Teks panel & form dipaksa putih
    (
        r'\.x-panel-body,\s*\.x-form\s*\{color\s*:\s*#FFF\s*!important;\s*border:\s*none\s*!important;\}',
        'Panel & form text dipaksa putih (#FFF !important)'
    ),
    
    # Rule 2: Teks grid row dipaksa putih
    (
        r'\.x-grid3-row\s+td\s*\{color:\s*#FFF;\s*font-size:\s*\.7rem;\s*\}',
        'Grid row text dipaksa putih (#FFF)'
    ),
    
    # Rule 3: Header grid background gelap + teks kuning
    (
        r'\.x-grid3-hd-row\s+td,\s*\.x-grid3-header,\s*\.x-grid3-header-inner\s*\{\s*background-color:\s*rgba\(0,\s*0,\s*0,\s*\.2\);\s*color:\s*#ffff00;\s*font-size:\s*\.8rem;\s*font-weight:\s*lighter;\s*\}',
        'Header grid background gelap + teks kuning (#ffff00)'
    ),
    
    # Rule 4: Alternating row gelap
    (
        r'\.x-grid3-row-alt\s*\{background-color:\s*rgba\(0,\s*0,\s*0,\s*\.1\);\s*\}',
        'Alternating row background gelap'
    ),
    
    # Rule 5: Cell selected teal transparan
    (
        r'\.x-grid3-cell-selected\s*\{\s*background-color:\s*rgba\(112,194,178,\.3\)!important;\s*color:\s*#000;\s*\}',
        'Cell selected background teal transparan'
    ),
    
    # Rule 6: Row selected background gelap
    (
        r'\.x-grid3-row-selected\s*\{background-color:\s*rgba\(0,\s*0,\s*0,\s*\.5\)\s*!important;\}',
        'Row selected background gelap (rgba(0,0,0,.5))'
    ),
    
    # Rule 7: Cell di row selected kuning transparan
    (
        r'\.x-grid3-row-selected\s+\.x-grid3-cell\s*\{background-color:\s*rgba\(153,\s*112,\s*33,\s*0\.2\)\s*!important;\s*color:\s*#fff\s*!important;\}',
        'Cell di row selected kuning transparan'
    ),
    
    # Rule 8: Row hover gelap
    (
        r'\.x-grid3-row-over\s+\.x-grid3-cell\s*\{background-color:\s*rgba\(85,\s*92,\s*105,\s*\.7\)\s*!important;\s*color:\s*#fff\s*!important;\}',
        'Row hover background gelap'
    ),
    
    # Rule 9: Toolbar background gelap
    (
        r'\.x-panel-bbar\s*\.x-toolbar,\s*\.x-panel-tbar\s*\.x-toolbar\s*\{background-color:\s*rgba\(0,\s*0,\s*0,\s*\.2\);\}',
        'Toolbar background gelap'
    ),
    
    # Rule 10: x-grid3-scroller & row background transparan gelap
    (
        r'\.x-grid3-scroller,\s*\.x-grid3-row\s*\{\s*background-color:\s*rgba\(0,\s*0,\s*0,\s*\.0\);\s*\}',
        'Grid scroller & row background transparan'
    ),
]

# ===== KONTEN BERSIH app-standard.css =====
CLEAN_APP_STANDARD = '''/* ==========================================================================
   RAB-PROJECT STANDARD THEME (app-standard.css) v6 - CLEAN
   Palet IDENTIK dengan views/dashboard.php
   - Dark:  #373647 (ungu gelap)
   - Light: #f4f1ea (warm cream)
   - Accent: #728E7E (sage green)
   
   [CLEAN] Duplikasi patch dihapus. Semua rule dalam satu tempat.
   ========================================================================== */

/* ===== 1. PALET DARK (default) ===== */
:root,
html[data-theme="dark"] {
  --bg-main: #373647;
  --bg-surface: #3f3e52;
  --bg-elevated: #4a4960;
  --bg-deeper: #2e2d3d;
  --border-subtle: rgba(114, 142, 126, 0.25);
  --border-strong: rgba(114, 142, 126, 0.5);
  --text-muted: #B1A8A8;
  --text-primary: #C2B8AD;
  --accent-primary: #728E7E;
  --accent-2: #949C8B;
  --hover-bg: #4a4960;
  --hover-bg-strong: #5a5970;
  --hover-text: #ffffff;
}

/* ===== 2. PALET LIGHT — warm cream ===== */
html[data-theme="light"] {
  --bg-main: #f4f1ea;
  --bg-surface: #fbf8f1;
  --bg-elevated: #ece7dc;
  --bg-deeper: #e6e0d3;
  --border-subtle: rgba(95, 125, 107, 0.28);
  --border-strong: rgba(95, 125, 107, 0.55);
  --text-muted: #5c564c;
  --text-primary: #2d2b26;
  --accent-primary: #5f7d6b;
  --accent-2: #8a9482;
  --hover-bg: #e5dfd6;
  --hover-bg-strong: #ddd6c9;
  --hover-text: #2d2b26;
}

/* ===== 3. BASE ===== */
body {
  background-color: var(--bg-main) !important;
  color: var(--text-primary) !important;
  font-family: 'Inter', sans-serif;
  transition: background-color .3s ease, color .3s ease;
}

/* ===== 4. EXTJS GRID — HEADER ===== */
html[data-theme="light"] .x-grid3-header,
html[data-theme="light"] .x-grid3-header-offset,
html[data-theme="light"] .x-grid3-hd-row td,
html[data-theme="light"] td.x-grid3-hd {
  background-color: var(--bg-elevated) !important;
  background-image: none !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
  font-weight: 600 !important;
}

html[data-theme="dark"] .x-grid3-header,
html[data-theme="dark"] .x-grid3-header-offset,
html[data-theme="dark"] .x-grid3-hd-row td,
html[data-theme="dark"] td.x-grid3-hd {
  background-color: var(--bg-elevated) !important;
  background-image: none !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
  font-weight: 600 !important;
}

/* ===== 5. EXTJS GRID — ROWS ===== */
html[data-theme="light"] .x-grid3-row,
html[data-theme="light"] .x-grid3-row td,
html[data-theme="light"] .x-grid3-row .x-grid3-cell-inner {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-subtle) !important;
}

html[data-theme="dark"] .x-grid3-row,
html[data-theme="dark"] .x-grid3-row td,
html[data-theme="dark"] .x-grid3-row .x-grid3-cell-inner {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-subtle) !important;
}

html[data-theme="light"] .x-grid3-row-alt,
html[data-theme="light"] .x-grid3-row-alt td {
  background-color: var(--bg-elevated) !important;
}

html[data-theme="dark"] .x-grid3-row-alt,
html[data-theme="dark"] .x-grid3-row-alt td {
  background-color: var(--bg-elevated) !important;
}

/* ===== 6. EXTJS GRID — SELECTED ROW (SEMUA KOLOM) ===== */
html[data-theme="light"] .x-grid3-row-selected,
html[data-theme="light"] .x-grid3-row-selected td,
html[data-theme="light"] .x-grid3-row-selected .x-grid3-cell,
html[data-theme="light"] .x-grid3-row-selected .x-grid3-cell-inner,
html[data-theme="light"] .x-grid3-row-selected .x-grid3-cell-selected {
  background-color: var(--accent-primary) !important;
  background-image: none !important;
  color: #ffffff !important;
  font-weight: 600 !important;
  border: none !important;
  border-bottom: 0 !important;
  border-top: 0 !important;
}

html[data-theme="dark"] .x-grid3-row-selected,
html[data-theme="dark"] .x-grid3-row-selected td,
html[data-theme="dark"] .x-grid3-row-selected .x-grid3-cell,
html[data-theme="dark"] .x-grid3-row-selected .x-grid3-cell-inner,
html[data-theme="dark"] .x-grid3-row-selected .x-grid3-cell-selected {
  background-color: var(--accent-primary) !important;
  background-image: none !important;
  color: #ffffff !important;
  font-weight: 600 !important;
  border: none !important;
  border-bottom: 0 !important;
  border-top: 0 !important;
}

/* Override kolom spesifik (col-0 sampai col-9) */
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-0,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-1,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-2,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-3,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-4,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-5,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-6,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-7,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-8,
html[data-theme="light"] .x-grid3-row-selected td.x-grid3-col-9 {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-0,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-1,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-2,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-3,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-4,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-5,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-6,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-7,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-8,
html[data-theme="dark"] .x-grid3-row-selected td.x-grid3-col-9 {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

/* ===== 7. EXTJS GRID — HOVER ===== */
html[data-theme="light"] .x-grid3-row-over,
html[data-theme="light"] .x-grid3-row-over td {
  background-color: var(--hover-bg-strong) !important;
}

html[data-theme="dark"] .x-grid3-row-over,
html[data-theme="dark"] .x-grid3-row-over td {
  background-color: var(--hover-bg-strong) !important;
}

/* ===== 8. EXTJS FORM ===== */
html[data-theme="light"] .x-form-text,
html[data-theme="light"] .x-form-field,
html[data-theme="light"] textarea.x-form-field,
html[data-theme="light"] input.x-form-field {
  background-color: var(--bg-elevated) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="dark"] .x-form-text,
html[data-theme="dark"] .x-form-field,
html[data-theme="dark"] textarea.x-form-field,
html[data-theme="dark"] input.x-form-field {
  background-color: var(--bg-elevated) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="light"] .x-form-item-label,
html[data-theme="light"] .x-fieldset legend {
  color: var(--text-muted) !important;
}

html[data-theme="dark"] .x-form-item-label,
html[data-theme="dark"] .x-fieldset legend {
  color: var(--text-muted) !important;
}

/* ===== 9. EXTJS COMBOBOX ===== */
html[data-theme="light"] .x-combo-list,
html[data-theme="light"] .x-combo-list-inner,
html[data-theme="light"] .x-layer.x-combo-list {
  background-color: var(--bg-surface) !important;
  border-color: var(--border-strong) !important;
  color: var(--text-primary) !important;
}

html[data-theme="light"] .x-combo-list .x-combo-list-item {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
}

html[data-theme="light"] .x-combo-list .x-combo-list-item:hover,
html[data-theme="light"] .x-combo-list .x-combo-selected {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

html[data-theme="dark"] .x-combo-list,
html[data-theme="dark"] .x-combo-list-inner,
html[data-theme="dark"] .x-layer.x-combo-list {
  background-color: var(--bg-surface) !important;
  border-color: var(--border-strong) !important;
  color: var(--text-primary) !important;
}

html[data-theme="dark"] .x-combo-list .x-combo-list-item {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
}

html[data-theme="dark"] .x-combo-list .x-combo-list-item:hover,
html[data-theme="dark"] .x-combo-list .x-combo-selected {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

/* ===== 10. EXTJS WINDOW MODAL ===== */
html[data-theme="light"] .x-window-tl,
html[data-theme="light"] .x-window-tr,
html[data-theme="light"] .x-window-tc,
html[data-theme="light"] .x-window-header {
  background-color: var(--bg-deeper) !important;
  background-image: none !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="light"] .x-window-header-text {
  color: var(--text-primary) !important;
  font-weight: 700 !important;
}

html[data-theme="dark"] .x-window-tl,
html[data-theme="dark"] .x-window-tr,
html[data-theme="dark"] .x-window-tc,
html[data-theme="dark"] .x-window-header {
  background-color: var(--bg-deeper) !important;
  background-image: none !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="dark"] .x-window-header-text {
  color: var(--text-primary) !important;
  font-weight: 700 !important;
}

html[data-theme="light"] .x-window-mc,
html[data-theme="light"] .x-window-ml,
html[data-theme="light"] .x-window-mr,
html[data-theme="light"] .x-window-bl,
html[data-theme="light"] .x-window-br,
html[data-theme="light"] .x-window-bc {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="dark"] .x-window-mc,
html[data-theme="dark"] .x-window-ml,
html[data-theme="dark"] .x-window-mr,
html[data-theme="dark"] .x-window-bl,
html[data-theme="dark"] .x-window-br,
html[data-theme="dark"] .x-window-bc {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

/* ===== 11. EXTJS BUTTON ===== */
html[data-theme="light"] .x-btn button,
html[data-theme="dark"] .x-btn button {
  color: var(--text-primary) !important;
}

html[data-theme="light"] .x-btn-mc,
html[data-theme="dark"] .x-btn-mc {
  background-color: var(--bg-surface) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="light"] .x-btn-over .x-btn-mc,
html[data-theme="dark"] .x-btn-over .x-btn-mc {
  background-color: var(--hover-bg-strong) !important;
}

/* ===== 12. EXTJS PANEL ===== */
html[data-theme="light"] .x-panel-body,
html[data-theme="light"] .x-panel-bwrap,
html[data-theme="light"] .x-plain-body,
html[data-theme="light"] .x-tab-panel-body {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
}

html[data-theme="dark"] .x-panel-body,
html[data-theme="dark"] .x-panel-bwrap,
html[data-theme="dark"] .x-plain-body,
html[data-theme="dark"] .x-tab-panel-body {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
}

/* ===== 13. DATE PICKER ===== */
html[data-theme="light"] .x-date-picker,
html[data-theme="light"] .x-date-inner,
html[data-theme="light"] .x-date-inner td,
html[data-theme="light"] .x-date-bottom {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="light"] .x-date-middle,
html[data-theme="light"] .x-date-left,
html[data-theme="light"] .x-date-right {
  background-color: var(--bg-deeper) !important;
  background-image: none !important;
}

html[data-theme="light"] .x-date-inner th {
  background-color: var(--bg-elevated) !important;
  color: var(--text-primary) !important;
}

html[data-theme="light"] .x-date-inner a {
  color: var(--text-primary) !important;
}

html[data-theme="light"] .x-date-inner .x-date-selected a {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

html[data-theme="dark"] .x-date-picker,
html[data-theme="dark"] .x-date-inner,
html[data-theme="dark"] .x-date-inner td,
html[data-theme="dark"] .x-date-bottom {
  background-color: var(--bg-surface) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="dark"] .x-date-middle,
html[data-theme="dark"] .x-date-left,
html[data-theme="dark"] .x-date-right {
  background-color: var(--bg-deeper) !important;
  background-image: none !important;
}

html[data-theme="dark"] .x-date-inner th {
  background-color: var(--bg-elevated) !important;
  color: var(--text-primary) !important;
}

html[data-theme="dark"] .x-date-inner a {
  color: var(--text-primary) !important;
}

html[data-theme="dark"] .x-date-inner .x-date-selected a {
  background-color: var(--accent-primary) !important;
  color: #ffffff !important;
}

/* ===== 14. HOTFIX: DETAIL PANEL (m.proyek.js inline style) ===== */
html[data-theme="light"] #projMainDtlContainer,
html[data-theme="light"] .detail-panel-container,
html[data-theme="light"] #divDetailGrid,
html[data-theme="light"] #poDtlContainer {
  background-color: var(--bg-surface) !important;
  border-color: var(--border-strong) !important;
  color: var(--text-primary) !important;
}

html[data-theme="light"] #projMainDtlContainer h2,
html[data-theme="light"] #projMainDtlContainer h3,
html[data-theme="light"] #content h3[style*="color:#00bcd4"],
html[data-theme="light"] #content b[style*="color:#00bcd4"] {
  color: var(--accent-primary) !important;
}

html[data-theme="light"] #projMainDtlContainer div[style*="background:#132330"],
html[data-theme="light"] #projMainDtlContainer div[style*="background:#0f"],
html[data-theme="light"] #projMainDtlContainer div[style*="background:#3b"],
html[data-theme="light"] #projMainDtlContainer div[style*="background:#3a"] {
  background-color: var(--bg-elevated) !important;
  border-color: var(--border-strong) !important;
  color: var(--text-primary) !important;
}

html[data-theme="light"] #projMainDtlContainer table,
html[data-theme="light"] #projMainDtlContainer td,
html[data-theme="light"] #projMainDtlContainer th,
html[data-theme="light"] #projMainDtlContainer tr,
html[data-theme="light"] #projMainDtlContainer span,
html[data-theme="light"] #projMainDtlContainer b,
html[data-theme="light"] #projMainDtlContainer div {
  color: var(--text-primary) !important;
  border-color: var(--border-subtle) !important;
}

html[data-theme="light"] #projMainDtlContainer tr[style*="background:#1a2f40"],
html[data-theme="light"] #projMainDtlContainer thead tr[style*="background:#1a2f40"] {
  background-color: var(--bg-elevated) !important;
}

html[data-theme="light"] #content div[style*="background"],
html[data-theme="light"] #divDetailGrid div[style*="background"],
html[data-theme="light"] .detail-panel-container > div {
  background-color: var(--bg-elevated) !important;
  color: var(--text-primary) !important;
  border-color: var(--border-strong) !important;
}

html[data-theme="light"] #content div[style*="background"] *,
html[data-theme="light"] #divDetailGrid div[style*="background"] *,
html[data-theme="light"] .detail-panel-container > div * {
  color: var(--text-primary) !important;
}

/* ===== 15. SCROLLBAR ===== */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--bg-main); }
::-webkit-scrollbar-thumb { background: var(--bg-elevated); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent-2); }
'''


def create_backup(filepath):
    """Buat backup file dengan timestamp"""
    if not os.path.exists(filepath):
        return None
    backup_path = f"{filepath}.bak_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(filepath, backup_path)
    print(f"  💾 Backup: {backup_path}")
    return backup_path


def patch_mainCSS(filepath, dry_run=False):
    """Patch mainCSS.css - disable rule yang bermasalah"""
    print(f"\n🔧 Patching: {filepath}")
    
    if not os.path.exists(filepath):
        print(f"  ❌ File tidak ditemukan: {filepath}")
        return False
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    original_content = content
    changes = []
    
    for pattern, description in MAINCSS_RULES_TO_DISABLE:
        if re.search(pattern, content):
            content = re.sub(
                pattern,
                f'/* [DISABLED by patch_theme_comprehensive] {description} */\n/* {pattern} */',
                content
            )
            changes.append(description)
            print(f"  ✅ Disabled: {description}")
        else:
            print(f"  ⚠️  Tidak ditemukan: {description}")
    
    if not changes:
        print("  ℹ️  Tidak ada perubahan (sudah di-patch)")
        return False
    
    if not dry_run:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"  ✅ {len(changes)} rule dinonaktifkan")
    else:
        print(f"  🔍 [DRY RUN] {len(changes)} rule akan dinonaktifkan")
    
    return True


def patch_app_standard(filepath, dry_run=False):
    """Bersihkan app-standard.css dari duplikasi patch"""
    print(f"\n🔧 Patching: {filepath}")
    
    if not os.path.exists(filepath):
        print(f"  ❌ File tidak ditemukan: {filepath}")
        return False
    
    if not dry_run:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(CLEAN_APP_STANDARD)
        print(f"  ✅ File dibersihkan dari duplikasi patch")
        print(f"  📊 Ukuran: {len(CLEAN_APP_STANDARD)} bytes")
    else:
        print(f"  🔍 [DRY RUN] File akan dibersihkan")
        print(f"  📊 Ukuran baru: {len(CLEAN_APP_STANDARD)} bytes")
    
    return True


def patch_dashboard(filepath, dry_run=False):
    """Bersihkan dashboard.php dari duplikasi updateThemeUI + fix default HTML"""
    print(f"\n🔧 Patching: {filepath}")
    
    if not os.path.exists(filepath):
        print(f"  ❌ File tidak ditemukan: {filepath}")
        return False
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    original_content = content
    changes = []
    
    # 1. Hapus blok updateThemeUI yang dikomentari (versi lama)
    pattern_old = r'/\*\s*\nfunction updateThemeUI\(theme\)\s*\{\s*\nconst icon = document\.getElementById\(\'theme_icon\'\);\s*\nconst lbl = document\.getElementById\(\'theme_lbl\'\);\s*\nif \(!icon \|\| !lbl\) return;\s*\n// Tampilkan theme yang SEDANG AKTIF\s*\nif \(theme === \'light\'\) \{ icon\.textContent = \'☀️\'; lbl\.textContent = \'Light\'; \}\s*\nelse \{ icon\.textContent = \'🌙\'; lbl\.textContent = \'Dark\'; \}\s*\n\}\s*\n\*/'
    
    if re.search(pattern_old, content):
        content = re.sub(pattern_old, '', content, count=1)
        changes.append("Duplikasi updateThemeUI (versi lama) dihapus")
        print("  ✅ Duplikasi updateThemeUI (versi lama) dihapus")
    else:
        print("  ℹ️  Duplikasi updateThemeUI tidak ditemukan (sudah bersih)")
    
    # 2. Fix default HTML tombol theme
    old_html = '<span id="theme_icon">☀️</span> <span id="theme_lbl">Light</span>'
    new_html = '<span id="theme_icon">🌙</span> <span id="theme_lbl">Dark</span>'
    
    if old_html in content:
        content = content.replace(old_html, new_html)
        changes.append("Default HTML tombol theme diperbaiki (🌙 Dark untuk default dark theme)")
        print("  ✅ Default HTML tombol theme diperbaiki")
    else:
        print("  ℹ️  Default HTML tombol sudah benar")
    
    if content != original_content:
        if not dry_run:
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)
            print(f"  ✅ {len(changes)} perubahan diterapkan")
        else:
            print(f"  🔍 [DRY RUN] {len(changes)} perubahan akan diterapkan")
        return True
    
    return False


def verify_patches():
    """Verifikasi status patch"""
    print("\n🔍 Verifikasi Status Patch")
    print("=" * 60)
    
    # Cek mainCSS.css
    maincss_path = PROJECT_ROOT / 'css' / 'mainCSS.css'
    if maincss_path.exists():
        with open(maincss_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        issues = []
        for pattern, description in MAINCSS_RULES_TO_DISABLE:
            if re.search(pattern, content) and '[DISABLED by patch_theme_comprehensive]' not in content:
                issues.append(description)
        
        if issues:
            print(f"  ❌ mainCSS.css: {len(issues)} rule bermasalah masih aktif")
            for issue in issues:
                print(f"     - {issue}")
        else:
            print("  ✅ mainCSS.css: Semua rule bermasalah sudah dinonaktifkan")
    
    # Cek app-standard.css
    appstd_path = PROJECT_ROOT / 'css' / 'app-standard.css'
    if appstd_path.exists():
        with open(appstd_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        count = content.count('PALET DARK')
        if count > 1:
            print(f"  ❌ app-standard.css: Ada {count} duplikasi palet (harusnya 1)")
        else:
            print("  ✅ app-standard.css: Tidak ada duplikasi")
        
        patch_count = content.count('PATCH FINAL')
        if patch_count > 0:
            print(f"  ❌ app-standard.css: Ada {patch_count} blok 'PATCH FINAL' (harusnya 0)")
        else:
            print("  ✅ app-standard.css: Tidak ada blok patch bertumpuk")
    
    # Cek dashboard.php
    dash_path = PROJECT_ROOT / 'views' / 'dashboard.php'
    if dash_path.exists():
        with open(dash_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        count = content.count('function updateThemeUI')
        if count > 1:
            print(f"  ❌ dashboard.php: Ada {count} deklarasi updateThemeUI (harusnya 1)")
        else:
            print("  ✅ dashboard.php: Tidak ada duplikasi updateThemeUI")
        
        if '<span id="theme_icon">🌙</span> <span id="theme_lbl">Dark</span>' in content:
            print("  ✅ dashboard.php: Default HTML tombol theme benar (🌙 Dark)")
        else:
            print("  ❌ dashboard.php: Default HTML tombol theme masih salah")


def revert_patches():
    """Kembalikan dari backup terbaru"""
    print("\n🔄 Reverting Patches")
    print("=" * 60)
    
    files_to_revert = [
        ('css/mainCSS.css', 'mainCSS.css'),
        ('css/app-standard.css', 'app-standard.css'),
        ('views/dashboard.php', 'dashboard.php'),
    ]
    
    for rel_path, name in files_to_revert:
        filepath = PROJECT_ROOT / rel_path
        backups = sorted(Path(filepath).parent.glob(f"{filepath.name}.bak_*"))
        
        if backups:
            latest_backup = backups[-1]
            shutil.copy2(latest_backup, filepath)
            print(f"  ✅ {name}: Dikembalikan dari {latest_backup.name}")
        else:
            print(f"  ⚠️  {name}: Tidak ada backup ditemukan")


def main():
    parser = argparse.ArgumentParser(description='Patch tema RAB Project - Comprehensive Fix')
    parser.add_argument('--dry-run', action='store_true', help='Preview saja, tidak mengubah file')
    parser.add_argument('--revert', action='store_true', help='Kembalikan dari backup')
    parser.add_argument('--verify', action='store_true', help='Verifikasi status patch')
    args = parser.parse_args()
    
    print("🔧 RAB Project Theme Patch Tool - Comprehensive Fix")
    print("=" * 60)
    print("Menyelesaikan akar masalah:")
    print("  1. mainCSS.css - 10 rule bermasalah dengan !important")
    print("  2. app-standard.css - Duplikasi patch bertumpuk")
    print("  3. dashboard.php - Duplikasi updateThemeUI + default HTML terbalik")
    print("=" * 60)
    
    if args.revert:
        revert_patches()
        return
    
    if args.verify:
        verify_patches()
        return
    
    # Backup semua file
    print("\n📦 Membuat Backup")
    print("=" * 60)
    files_to_backup = [
        'css/mainCSS.css',
        'css/app-standard.css',
        'views/dashboard.php',
    ]
    for rel_path in files_to_backup:
        filepath = PROJECT_ROOT / rel_path
        if filepath.exists():
            create_backup(str(filepath))
    
    # Patch mainCSS.css
    if not patch_mainCSS(str(PROJECT_ROOT / 'css/mainCSS.css'), args.dry_run):
        print("  ⚠️  mainCSS.css tidak perlu di-patch")
    
    # Patch app-standard.css
    if not patch_app_standard(str(PROJECT_ROOT / 'css/app-standard.css'), args.dry_run):
        print("  ⚠️  app-standard.css tidak perlu di-patch")
    
    # Patch dashboard.php
    if not patch_dashboard(str(PROJECT_ROOT / 'views/dashboard.php'), args.dry_run):
        print("  ⚠️  dashboard.php tidak perlu di-patch")
    
    # Verifikasi
    if not args.dry_run:
        verify_patches()
    
    print("\n" + "=" * 60)
    if args.dry_run:
        print("🔍 [DRY RUN] Tidak ada file yang dimodifikasi")
    else:
        print("✅ Patching selesai!")
        print("\n📝 Langkah selanjutnya:")
        print("  1. Hard refresh browser: Ctrl + Shift + R")
        print("  2. Test: klik baris di grid → harus sage green semua kolom")
        print("  3. Test: toggle tema → semua harus ikut berubah")
        print("  4. Jika OK, commit:")
        print("     git add css/mainCSS.css css/app-standard.css views/dashboard.php")
        print('     git commit -m "fix(theme): comprehensive patch - disable 10 conflicting rules in mainCSS.css, clean app-standard.css duplicates, fix dashboard.php"')
        print("     git push origin dev")


if __name__ == '__main__':
    main()
