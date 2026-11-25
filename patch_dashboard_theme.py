#!/usr/bin/env python3
"""
patch_dashboard_theme.py
========================
Memperbaiki logika button theme toggle di views/dashboard.php
Masalah: Label menampilkan tema AKTIF, seharusnya menampilkan tema TUJUAN (konvensi toggle standar)

Usage:
  python3 patch_dashboard_theme.py                    # Jalankan patch
  python3 patch_dashboard_theme.py --dry-run          # Preview saja
  python3 patch_dashboard_theme.py --revert           # Kembalikan ke semula
  python3 patch_dashboard_theme.py --file views/dashboard.php  # Path custom
"""

import argparse
import os
import re
import shutil
import sys
from datetime import datetime
from pathlib import Path

# ===== KONFIGURASI PATCH =====
PATCHES = {
    # Patch 1: Balik logika di fungsi updateThemeUI()
    "updateThemeUI_logic": {
        "search": (
            "  // Tampilkan theme yang SEDANG AKTIF\n"
            "  if (theme === 'light') { icon.textContent = '☀️'; lbl.textContent = 'Light'; }\n"
            "  else { icon.textContent = '🌙'; lbl.textContent = 'Dark'; }"
        ),
        "replace": (
            "  // Tampilkan theme TUJUAN (lawan dari yang aktif) - konvensi toggle standar\n"
            "  if (theme === 'light') { icon.textContent = '🌙'; lbl.textContent = 'Dark'; }\n"
            "  else { icon.textContent = '☀️'; lbl.textContent = 'Light'; }"
        ),
        "description": "Balik logika label updateThemeUI()"
    },
    # Patch 2: Balik default HTML tombol (karena default theme = dark)
    "default_html": {
        "search": "<span id=\"theme_icon\">🌙</span> <span id=\"theme_lbl\">Dark</span>",
        "replace": "<span id=\"theme_icon\">☀️</span> <span id=\"theme_lbl\">Light</span>",
        "description": "Balik default HTML tombol theme"
    }
}

def parse_args():
    parser = argparse.ArgumentParser(description="Patch theme toggle di dashboard.php")
    parser.add_argument("--file", default="views/dashboard.php",
                        help="Path file dashboard.php (default: views/dashboard.php)")
    parser.add_argument("--dry-run", action="store_true",
                        help="Preview perubahan tanpa memodifikasi file")
    parser.add_argument("--revert", action="store_true",
                        help="Kembalikan ke kondisi sebelum patch (gunakan backup)")
    parser.add_argument("--no-backup", action="store_true",
                        help="Skip backup file (tidak direkomendasikan)")
    parser.add_argument("--verbose", "-v", action="store_true",
                        help="Tampilkan detail perubahan")
    return parser.parse_args()

def create_backup(filepath):
    """Buat backup file dengan timestamp"""
    backup_path = f"{filepath}.bak_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(filepath, backup_path)
    return backup_path

def apply_patch(filepath, dry_run=False, no_backup=False, verbose=False):
    """Terapkan semua patch ke file"""
    if not os.path.exists(filepath):
        print(f"❌ File tidak ditemukan: {filepath}")
        print(f"   Jalankan dari root project atau gunakan --file <path>")
        return False

    with open(filepath, 'r', encoding='utf-8') as f:
        original_content = f.read()

    # Buat backup
    if not dry_run and not no_backup:
        backup_path = create_backup(filepath)
        print(f"💾 Backup dibuat: {backup_path}")

    new_content = original_content
    changes = []

    # Terapkan setiap patch
    for patch_name, patch in PATCHES.items():
        if patch["search"] in new_content:
            if verbose:
                print(f"\n🔍 Menemukan '{patch['description']}'")
            new_content = new_content.replace(patch["search"], patch["replace"])
            changes.append(patch["description"])
        else:
            print(f"⚠️  Pattern tidak ditemukan: {patch['description']}")
            print(f"   Kemungkinan sudah di-patch atau file berbeda versi.")

    if not changes:
        print("\n⚠️  Tidak ada perubahan yang diperlukan (sudah di-patch atau versi berbeda)")
        return False

    # Tampilkan ringkasan
    print(f"\n{'='*60}")
    print(f"📋 RINGKASAN PERUBAHAN")
    print(f"{'='*60}")
    for i, change in enumerate(changes, 1):
        print(f"  {i}. {change}")
    print(f"\n📁 File: {filepath}")
    print(f"📊 Total perubahan: {len(changes)}")

    if dry_run:
        print(f"\n🔍 [DRY RUN] Tidak ada file yang dimodifikasi.")
        return True

    # Tulis file
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(new_content)

    print(f"\n✅ Patch berhasil diterapkan!")
    print(f"📝 Silakan test di browser, lalu commit jika OK:")
    print(f"   git add {filepath}")
    print(f'   git commit -m "fix(ui): theme toggle label now shows TARGET theme (not active)"')
    print(f"   git push origin dev")
    return True

def revert_patch(filepath):
    """Kembalikan ke kondisi sebelum patch dari backup terbaru"""
    backups = sorted(Path(filepath).parent.glob(f"{Path(filepath).name}.bak_*"))
    if not backups:
        print(f"❌ Tidak ada backup ditemukan untuk {filepath}")
        return False

    latest_backup = backups[-1]
    shutil.copy2(latest_backup, filepath)
    print(f"✅ File dikembalikan dari backup: {latest_backup}")
    return True

def verify_patch(filepath):
    """Verifikasi apakah patch sudah diterapkan"""
    if not os.path.exists(filepath):
        return None

    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    has_new_logic = "Tampilkan theme TUJUAN" in content
    has_old_logic = "Tampilkan theme yang SEDANG AKTIF" in content
    has_new_html = '<span id="theme_icon">☀️</span> <span id="theme_lbl">Light</span>' in content
    has_old_html = '<span id="theme_icon">🌙</span> <span id="theme_lbl">Dark</span>' in content

    return {
        "logic_new": has_new_logic,
        "logic_old": has_old_logic,
        "html_new": has_new_html,
        "html_old": has_old_html,
        "fully_patched": has_new_logic and has_new_html and not has_old_logic and not has_old_html
    }

def main():
    args = parse_args()
    filepath = args.file

    print(f"🔧 Theme Toggle Patch Tool")
    print(f"{'='*60}")

    if args.revert:
        revert_patch(filepath)
        return

    # Verifikasi status saat ini
    status = verify_patch(filepath)
    if status and status["fully_patched"]:
        print("ℹ️  File sudah dalam kondisi ter-patch dengan benar.")
        print("   Gunakan --revert jika ingin mengembalikan.")
        return
    elif status and status["logic_old"]:
        print("ℹ️  File masih dalam kondisi lama (belum di-patch)")

    # Terapkan patch
    success = apply_patch(
        filepath,
        dry_run=args.dry_run,
        no_backup=args.no_backup,
        verbose=args.verbose
    )

    if success and not args.dry_run:
        # Verifikasi setelah patch
        status = verify_patch(filepath)
        if status and status["fully_patched"]:
            print("\n✅ Verifikasi: Patch terpasang sempurna!")
        else:
            print("\n⚠️  Verifikasi: Ada yang tidak sesuai, cek file manual.")

if __name__ == "__main__":
    main()
