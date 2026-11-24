#!/usr/bin/env bash
set -euo pipefail

cd /home/myadmin/proyekgmn-dev

# 1. Siapkan direktori isolasi backup
mkdir -p tmp_bak

# 2. Pindahkan file .bak yang tercecer di folder agents/ ke tmp_bak/
if ls .claude/agents/*.bak* 1> /dev/null 2>&1; then
  mv .claude/agents/*.bak* tmp_bak/
  echo "[OK] File .bak di .claude/agents/ dipindahkan ke tmp_bak/"
elif ls agents/*.bak* 1> /dev/null 2>&1; then
  mv agents/*.bak* tmp_bak/
  echo "[OK] File .bak di agents/ dipindahkan ke tmp_bak/"
fi

# 3. Backup CLAUDE.md sebelum dipatch
cp CLAUDE.md "tmp_bak/CLAUDE.md.bak-$(date +%Y%m%d%H%M%S)"

# 4. Patch CLAUDE.md menggunakan Python agar aman & akurat (menyatukan Bagian 1.1, 13, 14)
python3 - << 'PYEOF'
with open("CLAUDE.md", "r", encoding="utf-8") as f:
    content = f.read()

# Ganti Bagian 1.1
old_1_1_start = content.find("## 1.1 Cara mengubah & backup file")
old_1_2_start = content.find("## 1.2 Klasifikasi tugas")

new_1_1 = """## 1.1 Cara mengubah, backup & batas edit file (SSOT)
- **Isolasi Backup Wajib:** Sebelum menyunting file apa pun, wajib salin ke folder terisolasi `tmp_bak/<nama_file>.bak` (`mkdir -p tmp_bak && cp <path_file> tmp_bak/<nama_file>.bak`). **DILARANG** menaruh file `.bak` di dalam folder aplikasi (`js/`, `php-crud/`, `agents/`, dll.).
- **Batas Surgical Edit (`> 150` baris):** Agent **DILARANG** menulis ulang seluruh isi file lama (`Write`) jika panjangnya lebih dari 150 baris. Wajib gunakan penyuntingan terarah (`Edit`) hanya pada blok kode yang bermasalah.
- **File baru:** Ditulis secara utuh.
- **Konteks:** Sebelum mengubah file, baca konteks fungsi, seluruh pemanggil, dan dependency terkait.

"""

if old_1_1_start != -1 and old_1_2_start != -1:
    content = content[:old_1_1_start] + new_1_1 + content[old_1_2_start:]
else:
    print("[WARN] Bagian 1.1 tidak ditemukan persis, melewati penggantian 1.1.")

# Ganti Bagian 13 & 14 di akhir file
old_13_start = content.find("## 13. ATURAN MUTLAK SURGICAL EDIT ONLY")
new_13_14 = """## 13. HARD GATE VERIFIKASI PATCH & ANTI-REGRESI (WAJIB)
Karena beberapa direktori (`js/`, `php-crud/`) diabaikan oleh `.gitignore`, `git diff` **TIDAK DAPAT** diandalkan sebagai satu-satunya pemeriksa perubahan.
1. **Eksekusi Gate Otomatis:** Setiap selesai mengedit 1 file, agen wajib menjalankan:
   `./verify_patch_diff.sh tmp_bak/<nama_file>.bak <path_file_target>`
2. **Validasi Sintaks & Artefak:** Skrip otomatis mengecek `php -l` (PHP), `node --check` + deteksi kata/artefak nyasar (JS), serta menolak perubahan jika file menyusut `> 10` baris.
3. **Auto-Rollback:** Jika skrip menolak perubahan, file otomatis dikembalikan dari `tmp_bak/`. Agen dilarang memaksa perubahan dengan *full rewrite* dan wajib mengulang penyuntingan terarah (`Edit`).
4. **Isolasi Lapisan:** Saat menjalankan patch backend PHP, **DILARANG** menyentuh file `js/*.js`, `views/*.php`, atau `css/*.css` kecuali diminta eksplisit.
"""

if old_13_start != -1:
    content = content[:old_13_start] + new_13_14
else:
    content = content.rstrip() + "\n\n---\n" + new_13_14

with open("CLAUDE.md", "w", encoding="utf-8") as f:
    f.write(content)

print("[OK] CLAUDE.md berhasil dirapikan (SSOT Bagian 1.1 & Bagian 13).")
PYEOF

# 5. Tulis ulang verify_patch_diff.sh dengan deteksi kata nyasar & artefak markdown JS
cat << 'VEOF' > verify_patch_diff.sh
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
  FENCE_LINES=\$(grep -n '```' "$TARGET_FILE" || true)
  if [[ -n "$FENCE_LINES" ]]; then
    echo "[FAIL] Terdeteksi artefak markdown (\`\`\`) di dalam $TARGET_FILE:"
    echo "$FENCE_LINES"
    rollback
  fi
fi

# 4. Tampilkan ringkasan diff aktual
echo "[PASS] Verifikasi lulus untuk $TARGET_FILE ($OLD_LINES -> $NEW_LINES baris)."
diff -u "$BAK_FILE" "$TARGET_FILE" | head -n 40 || true
VEOF

chmod +x verify_patch_diff.sh
echo "[OK] verify_patch_diff.sh berhasil diperbarui dan diberi izin eksekusi."

# 6. Pastikan tmp_bak/ masuk ke .gitignore
if [[ -f .gitignore ]] && ! grep -qx "tmp_bak/" .gitignore; then
  echo "tmp_bak/" >> .gitignore
  echo "[OK] tmp_bak/ ditambahkan ke .gitignore."
fi
