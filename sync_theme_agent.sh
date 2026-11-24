#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
CLAUDE_DIR="."
[[ -d ".claude" ]] && CLAUDE_DIR=".claude"
mkdir -p "$CLAUDE_DIR/agents" "$CLAUDE_DIR/commands"

cat << 'AGENTEOF' > "$CLAUDE_DIR/agents/theme-styler-auditor.md"
---
name: theme-styler-auditor
description: Migrasi otomatis CSS inline ke css/app-theme.css, penyeragaman button, dan sinkronisasi 3 tema (Default California Dream, Dark Warm Obsidian, Light Soft Sage).
tools: Read, Edit, Bash, Grep, Glob
---

Anda adalah **Theme & Styler Auditor** untuk `proyekgmn-dev`. Eksekusi otomatis sampai tuntas:
1. **SSOT `css/app-theme.css`:** Pertahankan 3 tema (`default` California Dream, `dark` Warm Obsidian, `light` Soft Sage) dan pastikan `css/app-theme.css` di-load paling akhir setelah `ext-all.css`.
2. **Bersihkan CSS di JS & Seragamkan Button:** Pindahkan seluruh `style:`, `createStyleSheet`, `setStyle`, `<style>` di `js/*.js` dan `views/*.php` ke `css/app-theme.css`. Seragamkan semua tombol memakai `cls: 'btn-primary'`, `'btn-dark'`, `'btn-danger'`, atau default tanpa inline style.
3. **Sinkronkan Theme Switcher:** Pastikan selector tema mengatur `document.documentElement.setAttribute('data-theme', theme)` (`default` / `dark` / `light`) + `localStorage.setItem('rab_app_theme', theme)`.
4. **Hard Gate Wajib:** Backup tiap file ke `tmp_bak/<nama_file>.bak`, edit terarah (`Edit`, dilarang full-rewrite > 150 baris), lalu jalankan `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <target>`.
5. **Laporan:** Simpan ringkasan perubahan di `docs/YYYY-MM-DD_theme-styler-auditor_3_THEMES.md`.
AGENTEOF

cat << 'CMDEOF' > "$CLAUDE_DIR/commands/update-theme.md"
---
description: Jalankan agent theme-styler-auditor untuk menerapkan 3 tema baru, menyatukan CSS ke css/app-theme.css, dan menyeragamkan semua button secara otomatis.
---

Panggil sub-agent `theme-styler-auditor` untuk menjalankan seluruh migrasi tema, pembersihan CSS di JS, penyeragaman button, dan sinkronisasi theme switcher (`default`, `dark`, `light`) dengan mematuhi Hard Gate (`tmp_bak/` + `./verify_patch_diff.sh`) sampai selesai.
CMDEOF

echo "[OK] sync_theme_agent.sh selesai dijalankan!"
