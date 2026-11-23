#!/usr/bin/env bash
set -euo pipefail

CLAUDE_MD="CLAUDE.md"
AGENT_DIR=".claude/agents"

if [ ! -f "$CLAUDE_MD" ]; then
  echo "Error: File CLAUDE.md tidak ditemukan di direktori saat ini. Jalankan dari ~/proyekgmn-dev."
  exit 1
fi

BACKUP_SUFFIX=$(date +%Y%m%d_%H%M%S)
cp "$CLAUDE_MD" "${CLAUDE_MD}.bak_${BACKUP_SUFFIX}"
echo "[1/3] Backup CLAUDE.md dibuat: ${CLAUDE_MD}.bak_${BACKUP_SUFFIX}"

if ! grep -q "SURGICAL EDIT ONLY" "$CLAUDE_MD"; then
  cat << 'EOF' >> "$CLAUDE_MD"

---

## 13. ATURAN MUTLAK SURGICAL EDIT ONLY (ANTI-FULL-REWRITE)
Untuk mencegah kerusakan tata letak UI, hilangnya event listener lama, dan bug regresi seperti yang pernah terjadi pada modul User Role:
1. **Dilarang Full Rewrite File > 150 Baris:** Agent DILARANG menggunakan tool `Write` untuk menimpa seluruh isi file lama yang panjangnya lebih dari 150 baris. Wajib gunakan penyuntingan presisi (`Edit` / patch baris tertentu) hanya pada bagian yang bermasalah.
2. **Wajib Backup & Cek Diff:** Sebelum menyunting file apa pun, wajib salin ke `<nama_file>.bak`. Setelah menyunting, wajib jalankan `git diff --stat` dan pastikan jumlah baris yang dihapus/ditambah proporsional dengan ruang lingkup perbaikan (tidak menghapus blok fungsi atau CSS tema yang tidak terkait).
3. **Rollback Otomatis jika Melenceng:** Jika `git diff` menunjukkan perubahan yang merusak struktur ExtJS/CSS yang sudah jalan, wajib segera kembalikan file dari `.bak` dan ulangi dengan perubahan yang lebih kecil.
EOF
  echo "[2/3] Aturan SURGICAL EDIT ONLY ditambahkan ke CLAUDE.md"
else
  echo "[2/3] Aturan SURGICAL EDIT ONLY sudah ada di CLAUDE.md"
fi

if [ -d "$AGENT_DIR" ]; then
  for agent_file in "$AGENT_DIR/fullstack-patcher.md" "$AGENT_DIR/feature-architect-builder.md"; do
    if [ -f "$agent_file" ] && ! grep -q "SURGICAL EDIT ONLY" "$agent_file"; then
      cp "$agent_file" "${agent_file}.bak_${BACKUP_SUFFIX}"
      cat << 'EOF' >> "$agent_file"

## Aturan Keras Penyuntingan Kode (SURGICAL EDIT ONLY)
- **DILARANG menimpa penuh (full rewrite) file lama > 150 baris.** Gunakan hanya `Edit` terarah pada blok yang bermasalah.
- Wajib buat `<file>.bak` sebelum mengedit, jalankan `php -l` / `node --check`, dan verifikasi `git diff --stat` agar tidak ada kode UI/tema/endpoint yang terhapus tidak sengaja.
EOF
      echo "      -> Updated: $agent_file"
    fi
  done
  echo "[3/3] Sub-agent patcher & builder berhasil diperkuat."
else
  echo "[3/3] Direktori $AGENT_DIR tidak ditemukan, hanya CLAUDE.md yang diperbarui."
fi

echo "Selesai! Pagar pengaman SURGICAL EDIT ONLY kini aktif permanen."
