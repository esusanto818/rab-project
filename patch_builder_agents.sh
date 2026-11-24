#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev
mkdir -p tmp_bak

CLAUDE_DIR="."
[[ -d ".claude" ]] && CLAUDE_DIR=".claude"

TARGETS=(
  "$CLAUDE_DIR/agents/feature-architect-builder.md"
  "$CLAUDE_DIR/agents/fullstack-patcher.md"
)

for FILE in "${TARGETS[@]}"; do
  if [[ -f "$FILE" ]]; then
    cp "$FILE" "tmp_bak/$(basename "$FILE").bak-$(date +%Y%m%d%H%M%S)"

    if ! grep -q "STANDAR UI, CSS & BUTTON SERAGAM" "$FILE"; then
cat << 'RULEEOF' >> "$FILE"

---
### STANDAR UI, CSS & BUTTON SERAGAM (WAJIB DIPATUHI)
1. **Dilarang Menulis CSS di dalam JS:** Dilarang keras memakai `style: '...'`, `Ext.util.CSS.createStyleSheet`, `setStyle`, atau tag `<style>` di dalam file `js/*.js`. Seluruh aturan styling tambahan wajib diletakkan di `css/app-theme.css` (SSOT) dan dipanggil via `cls` / `iconCls`.
2. **Standar Tombol Seragam (Industrial Slate & Steel Amber):**
   - Simpan / Submit / Approve / Proses: wajib `cls: 'btn-primary'` (ExtJS) atau `class="app-btn app-btn-primary"` (HTML).
   - Cetak / PDF / Excel / Export / Filter: wajib `cls: 'btn-dark'` atau `class="app-btn app-btn-dark"`.
   - Hapus / Delete / Void / Tolak: wajib `cls: 'btn-danger'` atau `class="app-btn app-btn-danger"`.
   - Aksi Umum / Tambah / Edit / Batal: tanpa `cls` (otomatis default Slate) atau `cls: 'btn-slate'`.
3. **Hard Gate Verification:** Setiap mengedit file existing wajib backup ke `tmp_bak/<nama_file>.bak` dan lolos `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <target>`.
RULEEOF
      echo "[OK] Aturan UI & Button seragam ditambahkan ke $FILE"
    else
      echo "[SKIP] $FILE sudah memiliki aturan standar UI."
    fi
  else
    echo "[WARN] File tidak ditemukan: $FILE"
  fi
done
