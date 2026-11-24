#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(pwd)"
CLAUDE_DIR="${1:-.claude}"
TS="$(date +%Y%m%d_%H%M%S)"

echo "=== [1/4] Membuat folder isolasi backup tmp_bak/ ==="
mkdir -p "${PROJECT_DIR}/tmp_bak"
if [ -f "${PROJECT_DIR}/.gitignore" ] && ! grep -q "^tmp_bak/$" "${PROJECT_DIR}/.gitignore"; then
    echo "tmp_bak/" >> "${PROJECT_DIR}/.gitignore"
fi

echo "=== [2/4] Membuat gate otomatis verify_patch_diff.sh ==="
cat << 'EOF' > "${PROJECT_DIR}/verify_patch_diff.sh"
#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 2 ]; then
    echo "Usage: ./verify_patch_diff.sh <backup_file> <target_file> [max_deleted_lines]"
    exit 1
fi

BAK_FILE="$1"
TARGET_FILE="$2"
MAX_DEL="${3:-10}"

if [ ! -f "$BAK_FILE" ]; then
    echo "ERROR: File backup tidak ditemukan di $BAK_FILE"
    exit 1
fi

if [ ! -f "$TARGET_FILE" ]; then
    echo "ERROR: File target tidak ditemukan di $TARGET_FILE"
    exit 1
fi

# 1. Validasi Sintaks (PHP / JS)
EXT="${TARGET_FILE##*.}"
if [ "$EXT" = "php" ] || [ "$EXT" = "PHP" ]; then
    if ! php -l "$TARGET_FILE" >/dev/null 2>&1; then
        echo "FATAL SYNTAX ERROR pada $TARGET_FILE! Melakukan AUTO-ROLLBACK dari $BAK_FILE..."
        cp "$BAK_FILE" "$TARGET_FILE"
        php -l "$TARGET_FILE" || true
        exit 2
    fi
elif [ "$EXT" = "js" ]; then
    if command -v node >/dev/null 2>&1; then
        if ! node --check "$TARGET_FILE" >/dev/null 2>&1; then
            echo "FATAL JS SYNTAX ERROR pada $TARGET_FILE! Melakukan AUTO-ROLLBACK dari $BAK_FILE..."
            cp "$BAK_FILE" "$TARGET_FILE"
            exit 2
        fi
    fi
fi

# 2. Hitung penyusutan baris (Anti-Pangkas)
OLD_LINES=$(wc -l < "$BAK_FILE")
NEW_LINES=$(wc -l < "$TARGET_FILE")
DIFF_LINES=$((OLD_LINES - NEW_LINES))

if [ "$DIFF_LINES" -gt "$MAX_DEL" ]; then
    echo "DITOLAK! File $TARGET_FILE menyusut sebanyak $DIFF_LINES baris (batas maksimal: $MAX_DEL baris)."
    echo "Melakukan AUTO-ROLLBACK dari $BAK_FILE untuk mencegah hilangnya kode lama..."
    cp "$BAK_FILE" "$TARGET_FILE"
    exit 3
fi

echo "=== VERIFIKASI PATCH LULUS: $TARGET_FILE ==="
echo "Baris lama: $OLD_LINES | Baris baru: $NEW_LINES | Selisih bersih: $((NEW_LINES - OLD_LINES))"
diff -u "$BAK_FILE" "$TARGET_FILE" | head -n 60 || true
EOF
chmod +x "${PROJECT_DIR}/verify_patch_diff.sh"

echo "=== [3/4] Memperbarui CLAUDE.md dengan aturan verify_patch_diff.sh ==="
if [ -f "${PROJECT_DIR}/CLAUDE.md" ]; then
    cp "${PROJECT_DIR}/CLAUDE.md" "${PROJECT_DIR}/tmp_bak/CLAUDE.md.bak_${TS}"
    if ! grep -q "verify_patch_diff.sh" "${PROJECT_DIR}/CLAUDE.md"; then
        cat << 'EOF' >> "${PROJECT_DIR}/CLAUDE.md"

---
## 14. HARD GATE VERIFIKASI PATCH (WAJIB UNTUK SEMUA EDIT)
Karena beberapa direktori (`js/`, `php-crud/`) diabaikan oleh `.gitignore`, `git diff` **TIDAK DAPAT** diandalkan sebagai satu-satunya pemeriksa perubahan.
1. **Isolasi Backup:** Sebelum menyunting file apa pun, wajib salin ke `tmp_bak/<nama_file>.bak` (jangan taruh `.bak` di dalam folder aplikasi).
2. **Eksekusi Gate Otomatis:** Setiap selesai mengedit 1 file, wajib jalankan:
   `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <path_file_target>`
3. **Auto-Rollback:** Skrip tersebut otomatis mengecek sintaks (`php -l` / `node --check`) dan menolak jika file menyusut > 10 baris. Jika ditolak, dilarang memaksa perubahan dengan menulis ulang file; lakukan penyuntingan terarah (`Edit`) ulang.
4. **Isolasi Lapisan:** Saat menjalankan patch backend PHP, **DILARANG** menyentuh file `js/*.js`, `views/*.php`, atau `css/*.css` kecuali diminta eksplisit.
EOF
    fi
fi

echo "=== [4/4] Memperbarui fullstack-patcher.md ==="
PATCHER_FILE="${CLAUDE_DIR}/agents/fullstack-patcher.md"
if [ -f "$PATCHER_FILE" ]; then
    cp "$PATCHER_FILE" "${PROJECT_DIR}/tmp_bak/fullstack-patcher.md.bak_${TS}"
    if ! grep -q "verify_patch_diff.sh" "$PATCHER_FILE"; then
        cat << 'EOF' >> "$PATCHER_FILE"

## MANDATORY PATCH VERIFICATION GATE (v5)
- Never rely solely on `git diff` because `js/` and `php-crud/` may be gitignored.
- Always back up target files to `tmp_bak/<filename>.bak` before editing.
- After EVERY single file edit, immediately run `./verify_patch_diff.sh tmp_bak/<filename>.bak <target_file>`.
- Do NOT touch `js/*.js` or `views/*.php` during backend PHP patch batches.
EOF
    fi
fi

echo "SELESAI! verify_patch_diff.sh dan pagar pengaman v5 berhasil dipasang."
