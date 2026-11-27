#!/usr/bin/env bash
set -euo pipefail

BAK_FILE="${1:-}"
TARGET_FILE="${2:-}"

if [[ -z "$BAK_FILE" || -z "$TARGET_FILE" ]]; then
  echo "Usage: ./verify_patch_diff.sh <backup_file> <target_file>"
  exit 1
fi

if [[ ! -f "$BAK_FILE" || ! -f "$TARGET_FILE" ]]; then
  echo "[FAIL] File backup atau target tidak ditemukan."
  exit 1
fi

rollback() {
  echo "[ROLLBACK] Mengembalikan $TARGET_FILE dari $BAK_FILE ..."
  cp "$BAK_FILE" "$TARGET_FILE"
  exit 1
}

# 1. Cek penyusutan baris ekstrem (> 10 baris pada file > 150 baris)
OLD_LINES=$(wc -l < "$BAK_FILE")
NEW_LINES=$(wc -l < "$TARGET_FILE")
DIFF_LINES=$((OLD_LINES - NEW_LINES))

if (( OLD_LINES > 150 && DIFF_LINES > 10 )); then
  echo "[FAIL] File menyusut terlalu banyak ($OLD_LINES -> $NEW_LINES baris, hilang $DIFF_LINES baris). Terindikasi full-rewrite."
  rollback
fi

# 2. Validasi khusus PHP
if [[ "$TARGET_FILE" == *.php || "$TARGET_FILE" == *.PHP ]]; then
  if ! php -l "$TARGET_FILE" >/dev/null 2>&1; then
    echo "[FAIL] Syntax error PHP pada $TARGET_FILE:"
    php -l "$TARGET_FILE" || true
    rollback
  fi
fi

# 3. Validasi khusus JavaScript (node --check + Deteksi Kata Nyasar)
if [[ "$TARGET_FILE" == *.js ]]; then
  # 3a. Cek sintaks standar Node
  if ! node --check "$TARGET_FILE" >/dev/null 2>&1; then
    echo "[FAIL] Syntax error JS (node --check) pada $TARGET_FILE:"
    node --check "$TARGET_FILE" || true
    rollback
  fi

  # 3b. Deteksi kata nyasar / bare identifier berdiri sendiri (misal: role, assistant, user)
  STRAY_LINES=$(grep -nE '^[[:space:]]*[a-zA-Z_][a-zA-Z0-9_]*[[:space:]]*;?[[:space:]]*$' "$TARGET_FILE" \
    | grep -vE '^[0-9]+:[[:space:]]*(debugger|return|break|continue)[[:space:]]*;?[[:space:]]*$' || true)

  if [[ -n "$STRAY_LINES" ]]; then
    echo "[FAIL] Terdeteksi kata nyasar / bare identifier berdiri sendiri di $TARGET_FILE:"
    echo "$STRAY_LINES"
    rollback
  fi

  # 3c. Deteksi sisa markdown code fence (```) di dalam file JS
  FENCE_LINES=$(grep -n '```' "$TARGET_FILE" || true)
  if [[ -n "$FENCE_LINES" ]]; then
    echo "[FAIL] Terdeteksi artefak markdown (\`\`\`) di dalam $TARGET_FILE:"
    echo "$FENCE_LINES"
    rollback
  fi
fi

# 4. Tampilkan ringkasan diff aktual
echo "[PASS] Verifikasi lulus untuk $TARGET_FILE ($OLD_LINES -> $NEW_LINES baris)."
diff -u "$BAK_FILE" "$TARGET_FILE" | head -n 40 || true
