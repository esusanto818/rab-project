Berikut adalah dokumentasi lengkap How-To Guide yang dirangkum dari seluruh proses kita hari ini. Anda dapat menyimpannya (misalnya sebagai DOCS_CLAUDE_DB_AUDIT.md di komputer Anda) sebagai referensi di masa depan.

Panduan Lengkap: Audit Database PostgreSQL Lokal dengan Claude Code (MCP)

Dokumen ini mencakup langkah-langkah mulai dari pembuatan user khusus read-only di PostgreSQL, menghubungkannya ke Claude Code melalui Model Context Protocol (MCP), menyambung sesi percakapan, hingga menyusun prompt audit yang aman dan terstruktur.

Bagian 1: Setup User Read-Only di PostgreSQL

Untuk mencegah Claude Code mengubah, menambah, atau menghapus data secara tidak sengaja saat melakukan audit, buatlah role khusus yang hanya memiliki hak akses baca (SELECT).

1. Masuk ke PostgreSQL sebagai Superuser

Buka terminal dan masuk ke psql:
bash
psql -h localhost -p 5432 -U postgres
Use code with caution.

2. Cek Nama Exact Database

Periksa daftar database dan perhatikan huruf besar/kecilnya:
sql
\l
Use code with caution.
Penting: Jika nama database mengandung huruf kapital (contoh: proyekGMN), PostgreSQL mewajibkan penggunaan tanda kutip ganda ("proyekGMN") di setiap perintah SQL. Tanpa tanda kutip, PostgreSQL akan membacanya sebagai huruf kecil (proyekgmn) dan memunculkan error does not exist.

3. Buat Role dan Berikan Hak Akses Read-Only

Jalankan perintah berikut secara bertahap (jangan gabungkan meta-command \c dengan perintah SQL dalam satu kali tempel/paste):
Langkah A — Buat role dan beri izin koneksi:
sql
CREATE ROLE claude_audit WITH LOGIN PASSWORD 'password_aman_anda';
GRANT CONNECT ON DATABASE "nama_database" TO claude_audit;
Use code with caution.
Langkah B — Pindah ke database target:
sql
\c "nama_database"
Use code with caution.
(Pastikan prompt terminal berubah dari postgres=# menjadi nama_database=#).
Langkah C — Berikan izin baca pada skema dan tabel (saat ini & masa depan):
sql
GRANT USAGE ON SCHEMA public TO claude_audit;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO claude_audit;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO claude_audit;
Use code with caution.

4. Verifikasi Setup di PostgreSQL

Pastikan role dan hak akses sudah terpasang dengan benar:
• Cek status login & superuser:sql
SELECT rolname, rolcanlogin, rolsuper FROM pg_roles WHERE rolname = 'claude_audit';
Use code with caution.
(Hasil harus menunjukkan rolcanlogin = t dan rolsuper = f).
• Cek izin baca tabel:sql
SELECT table_name, privilege_type 
FROM information_schema.role_table_grants 
WHERE grantee = 'claude_audit' 
LIMIT 5;
Use code with caution.
• Keluar dari psql: Ketik \q lalu tekan Enter.

Bagian 2: Menghubungkan Database ke Claude Code (MCP)


1. Daftarkan Server MCP di Folder Proyek

Buka terminal, masuk ke direktori proyek Anda, lalu jalankan perintah claude mcp add.
Catatan Password: Jika password mengandung karakter khusus atau spasi (misalnya claude audit), ubah spasi menjadi %20 (claude%20audit) pada URI koneksi.
• Opsi A: Official Postgres MCP (Ringan, menggunakan Node.js / npx)bash
cd /path/ke/proyek-anda
claude mcp add postgres -- npx -y @modelcontextprotocol/server-postgres "postgresql://claude_audit:password_anda@localhost:5432/nama_database"
Use code with caution.
• Opsi B: Postgres MCP Pro (Mendukung EXPLAIN plan & saran optimasi indeks, menggunakan Python uvx)bash
cd /path/ke/proyek-anda
claude mcp add --transport stdio \
  --env DATABASE_URI="postgresql://claude_audit:password_anda@localhost:5432/nama_database" \
  postgres -- uvx postgres-mcp --access-mode=restricted
Use code with caution.
(Secara default, konfigurasi ini disimpan secara lokal di ~/.claude.json sehingga kredensial tidak ikut ter-commit ke repositori Git).

2. Memuat Ulang Sesi Claude Code Tanpa Kehilangan Riwayat Chat

Jika sesi Claude Code sudah terbuka sebelum Anda menambahkan MCP, sesi tersebut harus ditutup dulu agar konfigurasi baru terbaca:
1. Tutup sesi aktif: Ketik /exit di Claude Code (atau tekan Ctrl + C dua kali). Perhatikan Session ID yang tercetak di terminal saat keluar.
2. Sambung kembali sesi terakhir:
	• Menggunakan ID spesifik:bash
claude --resume <session-id>
Use code with caution.
	• Atau langsung melanjutkan percakapan terakhir di folder tersebut:bash
claude --continue
Use code with caution.
(Tambahkan flag --model ... jika Anda menggunakan model kustom seperti --model openrouter/free).

3. Cek Status Koneksi MCP

Di dalam sesi Claude Code, ketik /mcp lalu tekan Enter. Pastikan server postgres muncul di daftar Local MCPs dengan indikator aktif (hijau). Tekan Esc untuk kembali ke kotak ketik prompt.

Bagian 3: Template Prompt & Skenario Audit

Gunakan kerangka prompt di bawah ini setiap kali Anda ingin Claude Code mengaudit fitur baru, migrasi skema, atau kesiapan test case. Sesuaikan bagian dalam tanda kurung siku [...] dengan konteks proyek Anda.

Template Prompt Audit Lengkap

text
Akses database `[NAMA_DB]` sudah aktif via MCP `postgres` dengan hak akses **read-only (`SELECT` saja)**. 
Jangan jalankan perintah tulis (`INSERT`, `UPDATE`, `DELETE`) atau skrip migrasi ke database.

Gunakan akses DB ini untuk melakukan **audit menyeluruh** terhadap fitur `[NAMA_FITUR]` dan kesiapan pengujian berdasarkan kode aplikasi dan skema DB aktual. Tolong cek dan laporkan hal-hal berikut:

1. **Audit Skema & Kesesuaian Migrasi (`[NAMA_FILE_MIGRASI.sql]`)**:
   - Bandingkan kolom, tipe data, constraint, dan indeks pada tabel-tabel terkait (`[DAFTAR_TABEL]`) di DB aktual terhadap ekspektasi kode (`[NAMA_FILE_KODE]`) dan skrip migrasi.
   - Identifikasi kolom, relasi foreign key, atau constraint yang belum ada atau berpotensi konflik sebelum migrasi dijalankan.

2. **Audit Data Eksisting & Integritas**:
   - Periksa distribusi status data saat ini pada tabel utama.
   - Deteksi potensi anomali data: data *orphan* (tanpa induk), nilai negatif yang tidak wajar, duplikasi, atau selisih antara tabel transaksi dan tabel jurnal/laporan.

3. **Pemetaan Kesiapan Data Uji (Test Case)**:
   - Periksa ketersediaan data sampel di DB untuk menguji skenario positif (sukses) maupun skenario negatif (validasi gagal / rollback) sesuai daftar *test case* `[KODE_TC]`.
   - Sebutkan ID atau kriteria data eksisting yang aman dipakai untuk simulasi uji coba.

4. **Checklist Verifikasi Pra & Pasca Eksekusi**:
   - Berikan daftar kueri `SELECT` singkat yang harus saya jalankan secara manual sebelum dan sesudah saya mengeksekusi skrip migrasi.
Use code with caution.

Bagian 4: Checklist Keamanan Operasional

• Backup sebelum migrasi: Selalu jalankan pg_dump -U postgres -d "nama_database" > backup_$(date +%F).sql sebelum mengeksekusi skrip perubahan skema (ALTER/CREATE/UPDATE).
• Pemisahan peran: Biarkan Claude Code bekerja dengan claude_audit (read-only) untuk investigasi, analisis bug, dan audit. Eksekusi skrip migrasi atau write tetap dilakukan secara sadar melalui user postgres atau aplikasi Anda.
• Izin Tool MCP: Saat Claude Code memanggil tool postgres untuk pertama kalinya dalam sebuah sesi, pilih opsi Allow for this session agar proses audit berjalan lancar tanpa jeda konfirmasi di setiap tabel.
Apakah Anda ingin saya buatkan juga template skrip bash otomatis untuk membuat user claude_audit dan mendaftarkannya ke MCP dalam satu kali jalan untuk proyek-proyek Anda berikutnya?

#######################################################################################################################################################
#######################################################################################################################################################
#######################################################################################################################################################
#######################################################################################################################################################
#######################################################################################################################################################


Panduan Lengkap: Setup & Audit Database PostgreSQL Lokal dengan Claude Code (MCP)

Dokumen ini menjadi panduan standar untuk memberikan akses database PostgreSQL lokal ke Claude Code secara aman (read-only), memilih server MCP yang sesuai, serta menjalankan audit skema, data, dan performa kueri.

1. Perbandingan Mode MCP Server

Sebelum memulai, pilih mode MCP yang sesuai dengan kebutuhan audit proyek Anda:

Fitur / Kebutuhan	Mode Standard (@modelcontextprotocol/server-postgres)	Mode Pro (postgres-mcp --pro)
Prasyarat Runtime	Node.js (npx)	Python (uv / uvx)
Inspeksi Skema & Tabel	Ya	Ya
Menjalankan Kueri SELECT	Ya	Ya
Analisis EXPLAIN Plan	Terbatas (manual)	Ya (otomatis & mendalam)
Rekomendasi Indeks (Index Tuning)	Tidak	Ya (simulasi indeks virtual)
Cek Kesehatan DB (Health Checks)	Tidak	Ya (cek index bloat, cache hit, vacuum)
Proteksi Akses	Mengandalkan role PostgreSQL	Berlapis (Role DB + --access-mode=restricted)


2. Skrip Otomatisasi Setup (setup_claude_audit.sh)

Gunakan skrip berikut untuk membuat user khusus read-only (claude_audit) di PostgreSQL dan langsung menghubungkannya ke proyek Claude Code Anda dalam satu kali jalan.

A. Instalasi Skrip (Sekali Saja di Mesin Anda)

Jalankan perintah ini di terminal untuk menyimpan skrip di direktori home (~/setup_claude_audit.sh):
bash
cat << 'EOF' > ~/setup_claude_audit.sh
#!/usr/bin/env bash
set -e

# ==============================================================================
# Script Otomatis Setup Read-Only PostgreSQL Audit + MCP Claude Code
# Penggunaan:
#   ~/setup_claude_audit.sh <NAMA_DATABASE> [PASSWORD_AUDIT] [--pro]
#
# Contoh:
#   ~/setup_claude_audit.sh proyekGMN "claude_audit_2026"        # Mode Standard (npx)
#   ~/setup_claude_audit.sh proyekGMN "claude_audit_2026" --pro  # Mode Pro (uvx)
# ==============================================================================

DB_NAME=""
AUDIT_PASS="claude_audit_ro"
MCP_MODE="standard"
AUDIT_USER="claude_audit"
PG_HOST="${PG_HOST:-localhost}"
PG_PORT="${PG_PORT:-5432}"
PG_SUPERUSER="${PG_SUPERUSER:-postgres}"

for arg in "$@"; do
  case $arg in
    --pro)
      MCP_MODE="pro"
      ;;
    *)
      if [ -z "$DB_NAME" ]; then
        DB_NAME="$arg"
      else
        AUDIT_PASS="$arg"
      fi
      ;;
  esac
done

if [ -z "$DB_NAME" ]; then
  echo "❌ Error: Nama database wajib diisi."
  echo "Penggunaan: $0 <NAMA_DATABASE> [PASSWORD_AUDIT] [--pro]"
  exit 1
fi

echo "=== 1. Konfigurasi Role & Hak Akses Read-Only di PostgreSQL ==="
echo "Target Database : $DB_NAME ($PG_HOST:$PG_PORT)"
echo "Audit User      : $AUDIT_USER"
echo "Mode MCP        : $MCP_MODE"

psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SUPERUSER" -d postgres -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '$AUDIT_USER') THEN
    CREATE ROLE $AUDIT_USER WITH LOGIN PASSWORD '$AUDIT_PASS';
    RAISE NOTICE 'Role $AUDIT_USER berhasil dibuat.';
  ELSE
    ALTER ROLE $AUDIT_USER WITH LOGIN PASSWORD '$AUDIT_PASS';
    RAISE NOTICE 'Role $AUDIT_USER sudah ada, password diperbarui.';
  END IF;
END
\$\$;

GRANT CONNECT ON DATABASE "$DB_NAME" TO $AUDIT_USER;
SQL

psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SUPERUSER" -d "$DB_NAME" -v ON_ERROR_STOP=1 <<SQL
GRANT USAGE ON SCHEMA public TO $AUDIT_USER;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO $AUDIT_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO $AUDIT_USER;
SQL

echo "✅ Hak akses read-only di PostgreSQL berhasil diterapkan."

echo ""
echo "=== 2. Mendaftarkan MCP Server Postgres ke Claude Code ==="
ENCODED_PASS=$(python3 -c "import urllib.parse, sys; print(urllib.parse.quote(sys.argv[1], safe=''))" "$AUDIT_PASS")
DB_URI="postgresql://${AUDIT_USER}:${ENCODED_PASS}@${PG_HOST}:${PG_PORT}/${DB_NAME}"

claude mcp remove postgres 2>/dev/null || true

if [ "$MCP_MODE" = "pro" ]; then
  if ! command -v uvx &> /dev/null; then
    echo "⚠️  Peringatan: 'uvx' tidak ditemukan. Instal uv terlebih dahulu (misal: pip install uv)."
    exit 1
  fi
  echo "Memasang Postgres MCP Pro (uvx postgres-mcp --access-mode=restricted)..."
  claude mcp add --transport stdio \
    --env DATABASE_URI="$DB_URI" \
    postgres -- uvx postgres-mcp --access-mode=restricted
else
  echo "Memasang Official Postgres MCP (npx @modelcontextprotocol/server-postgres)..."
  claude mcp add postgres -- npx -y @modelcontextprotocol/server-postgres "$DB_URI"
fi

echo ""
echo "🎉 Selesai! Database '$DB_NAME' siap diaudit oleh Claude Code (Mode: $MCP_MODE)."
EOF

chmod +x ~/setup_claude_audit.sh
Use code with caution.

B. Cara Menjalankan di Proyek Baru

Masuk ke direktori proyek Anda dan eksekusi skrip:
• Untuk audit skema & data biasa (Mode Standard):bash
cd ~/folder-proyek-anda
~/setup_claude_audit.sh namaDatabase "password_rahasia"
Use code with caution.
• Untuk audit performa, indeks, & kesehatan DB (Mode Pro):bash
cd ~/folder-proyek-anda
~/setup_claude_audit.sh namaDatabase "password_rahasia" --pro
Use code with caution.

3. Setup Manual & Troubleshooting PostgreSQL

Jika Anda perlu melakukan setup atau pengecekan secara manual di psql, perhatikan aturan penting berikut:
1. Huruf Kapital pada Nama Database: PostgreSQL mengubah nama tanpa tanda kutip menjadi huruf kecil. Jika nama database mengandung huruf kapital (contoh: proyekGMN), selalu apit dengan tanda kutip ganda ("proyekGMN").
2. Jangan Gabungkan \c saat Paste: Meta-command \c "nama_db" harus dijalankan pada baris terpisah agar tidak memicu error invalid integer value for port.
3. Encoding Password: Jika password mengandung spasi (contoh: claude audit), ubah spasi menjadi %20 (claude%20audit) saat menulis URI koneksi manual.
Perintah Verifikasi Manual di psql:
sql
-- Cek status login (t) dan non-superuser (f)
SELECT rolname, rolcanlogin, rolsuper FROM pg_roles WHERE rolname = 'claude_audit';

-- Cek izin SELECT pada tabel (pastikan dijalankan setelah \c "nama_db")
SELECT table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = 'claude_audit' LIMIT 5;
Use code with caution.

4. Mengelola Sesi Claude Code & Koneksi MCP

1. Muat Ulang Sesi Aktif: Jika Claude Code sudah terbuka sebelum MCP didaftarkan, tutup sesi dengan mengetik /exit (atau Ctrl+C dua kali).
2. Sambung Kembali Riwayat Chat:
	• Lanjutkan chat terakhir di folder proyek: claude --continue
	• Lanjutkan sesi spesifik: claude --resume <session-id>
(Tambahkan --model <nama-model> jika menggunakan model kustom).
3. Verifikasi Koneksi MCP: Ketik /mcp di dalam Claude Code, pastikan postgres berstatus aktif (hijau) di bawah Local MCPs, lalu tekan Esc.

5. Template Prompt Audit Siap Pakai


Template A: Audit Skema, Migrasi, & Kesiapan Test Case

text
Akses database `[NAMA_DB]` sudah aktif via MCP `postgres` dengan hak akses **read-only (`SELECT` saja)**. 
Jangan jalankan perintah tulis (`INSERT`, `UPDATE`, `DELETE`) atau skrip migrasi ke database.

Lakukan **audit menyeluruh** terhadap fitur `[NAMA_FITUR]` dan kesiapan pengujian berdasarkan kode aplikasi (`[NAMA_FILE_KODE]`) serta skema DB aktual:

1. **Audit Skema & Migrasi (`[FILE_MIGRASI.sql]`)**: Bandingkan kolom, tipe data, constraint, dan indeks pada tabel terkait (`[DAFTAR_TABEL]`) terhadap ekspektasi kode. Identifikasi kolom/constraint yang belum ada atau berpotensi konflik.
2. **Audit Integritas Data**: Cek distribusi status data saat ini dan deteksi anomali (data *orphan*, nilai negatif tidak wajar, duplikasi, atau selisih antar tabel transaksi dan jurnal).
3. **Pemetaan Data Uji (`[KODE_TC]`)**: Cek ketersediaan sampel data di DB untuk skenario sukses maupun skenario gagal/rollback.
4. **Checklist Verifikasi**: Berikan kueri `SELECT` untuk pengecekan sebelum dan sesudah saya mengeksekusi migrasi secara manual.
Use code with caution.

Template B: Audit Performa Kueri & Indeks (Khusus Mode --pro)

text
Gunakan Postgres MCP Pro (`postgres`) untuk mengaudit performa kueri pada fitur `[NAMA_FITUR]`:
1. Periksa kueri-kueri utama di `[NAMA_FILE_KODE]` menggunakan `EXPLAIN` plan pada data aktual di `[NAMA_DB]`.
2. Identifikasi kueri yang masih melakukan *Sequential Scan* pada tabel besar atau memiliki *cost* tinggi.
3. Simulasikan dan rekomendasikan penambahan indeks (terutama pada *foreign key* dan kolom filter/join) beserta estimasi peningkatan performanya.
4. Jalankan pemeriksaan kesehatan database (*database health check*) untuk mendeteksi *index bloat* atau *cache hit ratio* yang rendah.
Use code with caution.

6. Aturan Keamanan Operasional

• Selalu Backup Sebelum Write: Sebelum mengeksekusi skrip migrasi atau perubahan data secara manual, jalankan pg_dump -U postgres -d "nama_database" > backup_$(date +%F).sql.
• Pemisahan Tugas: Gunakan Claude Code + claude_audit murni untuk investigasi, audit, dan validasi. Eksekusi DDL/DML tetap dilakukan manual menggunakan user postgres atau melalui aplikasi.
Apakah Anda ingin dokumen ini saya buatkan menjadi file .md yang bisa langsung diunduh, atau ada bagian tertentu yang ingin ditambahkan lagi?

