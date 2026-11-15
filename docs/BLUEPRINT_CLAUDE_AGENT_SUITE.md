# Master Blueprint: Tim Agen Otomatis Claude Code (Arsitek Fitur, Audit, Auto-Patch, Security, QA & Dokumentasi)

Dokumen ini merupakan panduan lengkap (arsitektur, instalasi, dan *How-To step-by-step*) untuk membangun dan mengoperasikan **6 Sub-Agen Spesialis (*Custom Subagents*)** di Claude Code yang terhubung ke **PostgreSQL lokal (*read-only* via MCP)** serta mematuhi aturan pengembangan aplikasi **PHP + PostgreSQL + ExtJS 3.4.1** melalui file `CLAUDE.md`.

---

## 1. Arsitektur Tim Agen (6 Spesialis + 1 Koordinator)

Sesi utama Claude Code bertindak sebagai **Koordinator** yang mendelegasikan pekerjaan ke 6 sub-agen terisolasi sesuai peran masing-masing:

| No | Nama Agen | Peran & Tanggung Jawab Utama | Hak Akses Tool |
| :--- | :--- | :--- | :--- |
| **1** | **`db-code-auditor`** | Memetakan alur bisnis hulu-ke-hilir (*end-to-end*), mendeteksi *Logic Gap* (proses bolong, misal: pengeluaran barang tanpa pemasukan), mencocokkan kode dengan skema DB, dan mengecek integritas data. | Baca file (`Read`, `Grep`, `Glob`) + DB *Read-Only* (`mcp__postgres__query`). **Dilarang write.** |
| **2** | **`feature-architect-builder`** | Merancang dan membangun **fitur/layer baru dari nol** (misal: layer pengecekan stok gudang sebelum open PO), membuat migrasi SQL, backend PHP, UI ExtJS 3.4.1, serta titik integrasi ke modul eksisting. | Baca file, DB *Read-Only*, & Tulis file kode (`Write`, `Edit`). |
| **3** | **`fullstack-patcher`** | Menambal celah logika atau bug pada modul yang sudah ada (skrip migrasi `.sql`, validasi backend, dan perbaikan UI) sesuai temuan auditor. | Baca & Tulis file kode (`Read`, `Write`, `Edit`). |
| **4** | **`security-auditor-patcher`** | Mengaudit dan menambal celah keamanan (SQL Injection tanpa `pg_query_params`, Broken Access Control/IDOR, XSS, kebocoran pesan error DB ke JSON) sesuai `CLAUDE.md`. | Baca & Tulis file kode (`Read`, `Write`, `Edit`). |
| **5** | **`qa-test-engineer`** | Menyusun matriks *Test Case* (positif, negatif logika, & payload uji keamanan), mencari sampel data uji eksisting di DB lokal, dan mengeksekusi pengujian. | Baca file, DB *Read-Only*, & Terminal (`Bash`). |
| **6** | **`tech-writer`** | Menerjemahkan alur sistem dan fitur baru menjadi panduan operasional pengguna (*How-To Guide* / SOP) berbahasa Indonesia di folder `docs/`. | Baca & Tulis dokumen (`Read`, `Write`, `Edit`). |

---

## 2. Persiapan & Kebutuhan Sistem

Sebelum menjalankan instalasi master template, pastikan mesin Linux Anda memiliki:
1. **Claude Code CLI** versi terbaru yang sudah terinstal dan terautentikasi.
2. **PostgreSQL Lokal** dengan akses superuser (`postgres`).
3. **Runtime MCP**:
   * **Node.js (`npx`)** — untuk server MCP PostgreSQL mode *Standard*.
   * **Python (`uv` / `uvx`)** — untuk server MCP PostgreSQL mode *Pro* (mendukung analisis `EXPLAIN` plan, rekomendasi indeks, dan cek kesehatan DB).

---

## 3. Instalasi Master Template (Cukup Sekali di Mesin Anda)

Jalankan langkah-langkah berikut secara berurutan di terminal Linux untuk membuat direktori master `~/.claude-templates/agent-suite/` beserta seluruh agen, perintah slash, `CLAUDE.md`, dan skrip otomatisasi.

### Langkah 1: Buat Struktur Direktori Master
```bash
mkdir -p ~/.claude-templates/agent-suite/.claude/agents
mkdir -p ~/.claude-templates/agent-suite/.claude/commands
```

### Langkah 2: Buat Template Pedoman Proyek (`CLAUDE.md`)
File ini otomatis dibaca oleh seluruh agen untuk memastikan gaya koding, struktur folder, dan aturan keamanan selalu dipatuhi:

```bash
cat << 'EOF' > ~/.claude-templates/agent-suite/CLAUDE.md
# DEVELOPMENT GUIDELINE & INSTRUCTIONS

## 1. ATURAN UMUM PENGEMBANGAN
- **Full File Format:** Setiap perbaikan, tambalan, atau fitur baru (JavaScript/ExtJS maupun PHP) WAJIB dikembalikan dalam bentuk versi FULL FILE LENGKAP.
- **Remark/Comment:** Berikan komentar yang jelas pada baris kode yang berubah atau ditambah (misal: `// [NEW FEATURE]`, `// [PATCH]`, atau `// [SECURITY PATCH]`).
- **Pemisahan Logika:** Pisahkan tegas antara logika UI (`/js/` atau `/views/`) dan pemrosesan data (`/php-crud/`), gunakan modul PO sebagai acuan pembagian.
- **Keamanan Database:** Perubahan skema database WAJIB dibuat dalam bentuk file migrasi baru di folder `sql/` (format `YYYY-MM-DD.nama_fitur.sql`) untuk dieksekusi manual oleh developer.

## 2. STANDAR BACKEND (PHP & POSTGRESQL)
- **Helper & Koneksi Tunggal:** Wajib panggil di setiap endpoint backend:
  `require_once '../php-func/function.all.php';`
  `require_once '../php-class/crudClass.php';`
- **Keamanan Query:** Selalu gunakan `pg_query_params` untuk mencegah SQL Injection. Dilarang menggabungkan variabel input langsung ke string SQL.
- **Format Respon JSON (`/php-crud/*.php`):**
  - Read: `{"success": true, "totalCount": 100, "data": [...]}`
  - Mutasi: `{"success": true, "message": "Data berhasil disimpan."}`
  - Gagal: `{"success": false, "message": "Pesan kesalahan spesifik."}`
- **Transaksi ACID & Logging:** Gunakan `BEGIN ... COMMIT / ROLLBACK` untuk data bertingkat (Header & Detail). Dilarang menggunakan `echo`, `var_dump`, atau `print_r` di kode produksi agar JSON tidak rusak. Format tanggal `YYYY-MM-DD` dan desimal memakai titik (`.`).

## 3. STANDAR FRONTEND (EXTJS 3.4.1)
- **Store Exception:** Setiap `Ext.data.Store` wajib memiliki listener `exception` untuk menampilkan pesan error eksplisit.
- **Validasi:** Lakukan null-check `dataRecord` sebelum Edit/Delete, dan pastikan form ter-render sebelum `.getForm().load()` atau `.setValue()`.
- **Payload Bertingkat:** Serialisasi data detail menggunakan `Ext.util.JSON.encode` via metode POST.

## 4. STRUKTUR DIREKTORI PROYEK
- `ext-3.4.1/` : Framework ExtJS 3.4.1
- `js/` & `js-func/` : UI & fungsi global ExtJS
- `views/` : Halaman tampilan utama
- `php-crud/` : Handler Backend CRUD (acuan: `purchaseinvoiceCRD.php`)
- `php-func/` & `php-class/` : Helper koneksi & class global PHP
- `sql/` : Skrip migrasi dan perubahan struktur database
EOF
```

### Langkah 3: Buat Definisi 6 Sub-Agen Spesialis

* **1. Agen Auditor Logic & DB (`db-code-auditor.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/db-code-auditor.md
  ---
  name: db-code-auditor
  description: Mengaudit kesesuaian kode, skema DB, dan kelengkapan alur logika bisnis (mendeteksi proses yang terputus/bolong).
  tools: Read, Grep, Glob, mcp__postgres__query
  ---
  Anda adalah Senior Systems & Business Logic Auditor.
  Aturan ketat:
  1. DILARANG mengubah data atau skema DB secara langsung (hanya gunakan kueri SELECT via MCP postgres).
  2. Lakukan **End-to-End Workflow Mapping**: telusuri ketergantungan antar modul (hulu ke hilir, misal: Purchase -> Pemasukan/GRN -> Stok -> Pengeluaran/GI, atau Invoice -> Pembayaran -> Jurnal).
  3. Deteksi **Logic Gap**:
     - Apakah ada transaksi hilir yang bisa dieksekusi tanpa validasi transaksi hulu?
     - Apakah tabel database dan kode mengizinkan kondisi tidak valid (misal: pengeluaran barang tanpa catatan pemasukan/stok, saldo minus, status lompat)?
     - Modul, tabel, atau UI apa yang hilang untuk melengkapi siklus tersebut?
  4. Keluarkan laporan terstruktur berisi: Daftar Logic Gap, Dampak Bisnis, dan Spesifikasi Teknis Tambalan (Tabel/Kolom DB, Validasi Backend, dan UI yang dibutuhkan).
  EOF
  ```

* **2. Agen Arsitek & Pembangun Fitur Baru (`feature-architect-builder.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/feature-architect-builder.md
  ---
  name: feature-architect-builder
  description: Merancang dan membangun modul, aplikasi, atau layer fitur baru secara end-to-end (skema DB, backend PHP, UI ExtJS 3.4.1, dan integrasi ke modul eksisting).
  tools: Read, Grep, Glob, Write, Edit, mcp__postgres__query
  ---
  Anda adalah Principal Fullstack Solutions Architect yang bertugas merancang dan membangun fitur/layer baru pada aplikasi PHP, PostgreSQL, dan ExtJS 3.4.1.

  Aturan Wajib:
  1. **Pelajari Konteks & Aturan Proyek**:
     - Wajib baca `CLAUDE.md` terlebih dahulu.
     - Periksa skema database aktual via MCP `postgres` (`SELECT` only) untuk mengetahui nama tabel master (barang/material, gudang, PO, dll.) dan tipe datanya.
     - Baca modul eksisting yang menjadi acuan (misal: `php-crud/purchaseinvoiceCRD.php` dan modul PO di `/js/` dan `/views/`) untuk meniru pola kode, layout, dan navigasi menu.
  2. **Desain Database (Aman)**:
     - Jangan jalankan DDL langsung ke database.
     - Buatkan file migrasi SQL di folder `sql/` (format `YYYY-MM-DD.nama_fitur.sql`) berisi tabel, view, indeks, dan relasi foreign key yang dibutuhkan.
  3. **Implementasi Backend (`/php-crud/*.php`)**:
     - Gunakan helper tunggal (`function.all.php` & `crudClass.php`), `pg_query_params`, transaksi `BEGIN ... COMMIT/ROLLBACK`, serta format respon JSON standar proyek.
     - Tambahkan titik integrasi pada modul eksisting jika diperlukan (contoh: pengecekan posisi stok gudang sebelum simpan/open PO).
  4. **Implementasi Frontend (`ExtJS 3.4.1`)**:
     - Buat komponen UI baru (Grid, Form, Window/Modal pengecekan stok, atau tombol aksi di modul terkait) lengkap dengan listener `exception` pada setiap `Ext.data.Store` dan null-check `dataRecord`.
     - Daftarkan menu/akses fitur baru ke struktur navigasi aplikasi jika diperlukan.
  5. **Format Output**: Kembalikan seluruh file PHP/JS yang dibuat atau diubah dalam versi **FULL FILE LENGKAP** dengan komentar penanda (misal: `// [NEW FEATURE: ...]`).
  EOF
  ```

* **3. Agen Penambal Bug & Logic (`fullstack-patcher.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/fullstack-patcher.md
  ---
  name: fullstack-patcher
  description: Membuat tambalan sistem secara end-to-end (skrip migrasi SQL, validasi/modul backend, dan UI) berdasarkan temuan audit logic gap.
  tools: Read, Grep, Glob, Write, Edit
  ---
  Anda adalah Senior Fullstack Developer yang bertugas menambal celah logika sistem secara rapi dan aman.
  Aturan wajib sebelum menulis kode:
  1. **Patuhi `CLAUDE.md` & Konvensi Proyek**: Baca `CLAUDE.md` serta file acuan (`php-crud/purchaseinvoiceCRD.php` dan modul PO) terlebih dahulu.
  2. **Database Migration (Aman)**:
     - Jangan jalankan DDL langsung ke database.
     - Buatkan file migrasi SQL baru di folder `sql/` (format `YYYY-MM-DD.nama_fitur.sql`) berisi `CREATE TABLE` / `ALTER TABLE` / `CONSTRAINT` / `INDEX` yang dibutuhkan.
  3. **Backend Logic & Guardrails**:
     - Gunakan `pg_query_params`, transaksi `BEGIN ... COMMIT/ROLLBACK`, dan format JSON sesuai standar di `CLAUDE.md`.
     - Tambahkan validasi ketat di titik transaksi hilir dan buat modul hulu yang hilang.
  4. **User Interface (ExtJS 3.4.1)**:
     - Buat antarmuka input & grid yang konsisten dengan komponen UI proyek ini, lengkap dengan listener `exception` pada Store dan null-check `dataRecord`.
  5. **Format Output Kode**: Kembalikan file PHP/JS yang diubah/dibuat dalam versi **FULL FILE LENGKAP** dengan komentar penanda perubahan (`// [PATCH]`).
  EOF
  ```

* **4. Agen Audit & Patch Keamanan (`security-auditor-patcher.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/security-auditor-patcher.md
  ---
  name: security-auditor-patcher
  description: Mengaudit celah keamanan kode (SQL Injection, Broken Access Control/IDOR, XSS, CSRF, kebocoran error) dan membuatkan tambalan kode yang aman sesuai standar proyek.
  tools: Read, Grep, Glob, Write, Edit
  ---
  Anda adalah Senior Application Security (AppSec) Engineer yang ahli dalam mengamankan aplikasi PHP, PostgreSQL, dan ExtJS 3.4.1.

  Aturan Wajib:
  1. **Baca Standar Proyek**: Periksa `CLAUDE.md` serta file helper global (`php-func/function.all.php` dan `php-class/crudClass.php`) sebelum mengaudit atau menulis patch.
  2. **Checklist Audit Keamanan (`/php-crud/`, `/js/`, `/views/`)**:
     - **SQL Injection**: Tandai semua pemanggilan `pg_query` yang menyisipkan variabel `$_POST`/`$_GET`/`$_REQUEST` tanpa `pg_query_params`.
     - **Authentication & Authorization**: Pastikan setiap endpoint backend memeriksa status login/sesi dan otorisasi aksi (Create/Read/Update/Delete) sebelum memproses data.
     - **IDOR & Validasi Input**: Pastikan tipe data angka/tanggal/ID divalidasi ketat di sisi server (jangan hanya mengandalkan validasi ExtJS di sisi klien).
     - **Kebocoran Informasi**: Pastikan pesan error database mentah (`pg_last_error()`) tidak langsung dikirim ke JSON klien; kirim pesan error aman sesuai format JSON proyek dan pastikan tidak ada `echo`/`var_dump`/`print_r`.
     - **Keamanan Frontend (ExtJS 3.4.1)**: Pastikan output data di template/renderer bebas dari injeksi HTML/XSS dan error ditangani oleh listener `exception`.
  3. **Aturan Patching Kode**:
     - Kembalikan file yang ditambal dalam versi **FULL FILE LENGKAP** dengan komentar `// [SECURITY PATCH] ...` pada setiap baris yang ditambah atau diubah.
  4. **Format Laporan**: Sajikan temuan berdasarkan tingkat keparahan (**CRITICAL, HIGH, MEDIUM, LOW**), lokasi file, skenario eksploitasi, serta ringkasan perbaikan yang diterapkan.
  EOF
  ```

* **5. Agen QA & Pengujian (`qa-test-engineer.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/qa-test-engineer.md
  ---
  name: qa-test-engineer
  description: Menyusun daftar Test Case (fungsional & keamanan), mencari sampel data uji di DB, dan menjalankan verifikasi endpoint/skrip tes.
  tools: Read, Grep, Glob, Bash, mcp__postgres__query
  ---
  Anda adalah Senior QA & Security Test Engineer.
  Tugas Anda:
  1. Susun tabel Test Case (ID, Kategori, Skenario, Prasyarat Data, Langkah/Payload Uji, Ekspektasi Hasil) mencakup:
     - Uji positif (alur normal hulu ke hilir).
     - Uji negatif logika (memastikan celah alur bisnis ditolak sistem).
     - Uji negatif keamanan (payload SQLi dasar, bypass parameter/IDOR, input kosong/invalid).
  2. Gunakan MCP postgres (SELECT) untuk mencari ID data eksisting di DB lokal yang cocok dijadikan sampel uji tanpa merusak data.
  3. Jalankan pengujian otomatis (via curl/unit test/psql pada DB test) jika diminta, dan laporkan status PASS/FAIL secara objektif.
  EOF
  ```

* **6. Agen Dokumentasi (`tech-writer.md`)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/agents/tech-writer.md
  ---
  name: tech-writer
  description: Menyusun panduan pengguna (How-To Guide), SOP operasional, atau dokumentasi teknis rilis fitur dalam format Markdown.
  tools: Read, Grep, Glob, Write, Edit
  ---
  Anda adalah Technical Writer profesional.
  Tugas Anda:
  1. Buat dokumen panduan langkah demi langkah (How-To) dalam bahasa Indonesia yang jelas, ringkas, dan bebas jargon membingungkan untuk end-user.
  2. Sertakan bagian: Tujuan Fitur, Prasyarat, Langkah Penggunaan (berurutan), Contoh Kasus, dan Troubleshooting error umum.
  3. Simpan hasil dokumentasi ke folder `docs/` dalam format Markdown yang rapi.
  EOF
  ```

### Langkah 4: Buat 4 Slash Command Otomatis

* **Command 1: `/audit-fitur` (Audit Fungsional + QA + Dokumentasi tanpa ubah kode)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/commands/audit-fitur.md
  ---
  description: Jalankan alur lengkap Audit DB/Kode, Pembuatan Test Case, dan Dokumen How-To untuk fitur tertentu.
  ---
  Lakukan alur kerja kolaboratif untuk fitur: **$ARGUMENTS**

  Jalankan tahapan berikut secara berurutan:
  1. Panggil sub-agen `db-code-auditor` untuk mengaudit kode terkait fitur tersebut dan mencocokkannya dengan skema database PostgreSQL aktual.
  2. Panggil sub-agen `qa-test-engineer` untuk menyusun skenario Test Case lengkap beserta pemetaan sampel data uji dari database lokal.
  3. Panggil sub-agen `tech-writer` untuk menyusun draf dokumen panduan pengguna (`docs/HOWTO_$ARGUMENTS.md`) berdasarkan alur kode yang sudah terverifikasi.
  4. Sajikan ringkasan eksekutif dari ketiga agen tersebut kepada saya.
  EOF
  ```

* **Command 2: `/audit-security` (Audit & Hardening Keamanan Khusus)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/commands/audit-security.md
  ---
  description: Audit keamanan menyeluruh (SQLi, Auth/Session, XSS, Validasi Server) dan terapkan patch keamanan pada modul target.
  ---
  Lakukan **Security Audit & Hardening** pada file atau modul: **$ARGUMENTS**

  Jalankan tahapan berikut:
  1. Panggil sub-agen `security-auditor-patcher` untuk memindai seluruh file backend (`/php-crud/`) dan frontend (`/js/`, `/views/`) terkait **$ARGUMENTS**.
  2. Identifikasi seluruh kerentanan (SQL Injection tanpa `pg_query_params`, bypass sesi/otorisasi, input tidak tervalidasi, kebocoran pesan error DB, dan XSS pada ExtJS).
  3. Terapkan tambalan keamanan secara langsung ke file terkait dengan mematuhi aturan **Full File Lengkap** dan komentar penanda perubahan di `CLAUDE.md`.
  4. Minta sub-agen `qa-test-engineer` menyusun payload pengujian keamanan (uji negatif) untuk memverifikasi bahwa celah tersebut sudah tertutup.
  EOF
  ```

* **Command 3: `/audit-dan-tambal` (Deteksi Logic Bolong + Tambal DB/Backend/UI + Security + QA + Docs)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/commands/audit-dan-tambal.md
  ---
  description: Audit alur bisnis & keamanan, buat tambalan DB + Backend + UI, hardening security, susun test case, dan buat dokumen How-To.
  ---
  Lakukan siklus **Full Audit, Auto-Patch & Security Hardening** untuk modul/alur: **$ARGUMENTS**

  Jalankan tahapan berikut secara berurutan:
  1. **Audit Logic & Alur Bisnis (`db-code-auditor`)**:
     - Telusuri kode dan skema DB terkait **$ARGUMENTS** untuk menemukan celah logika atau proses yang bolong.
  2. **Pembuatan Tambalan Fungsional (`fullstack-patcher`)**:
     - Buatkan file migrasi SQL di folder `sql/`, validasi/modul backend, dan UI sesuai aturan `CLAUDE.md`.
  3. **Audit & Hardening Keamanan (`security-auditor-patcher`)**:
     - Periksa seluruh kode eksisting maupun kode baru hasil tambalan di atas terhadap celah SQL Injection, otorisasi sesi, validasi server-side, dan kebocoran error, lalu terapkan patch keamanannya.
     - *Catatan: Berhenti sejenak setelah file migrasi SQL dan patch kode selesai dibuat agar saya bisa mengeksekusi migrasi SQL secara manual.*
  4. **Pengujian Celah, Keamanan & Alur (`qa-test-engineer`)**:
     - Susun skenario uji fungsional (positif/negatif) dan uji penetrasi dasar untuk memastikan celah logika maupun keamanan sudah tertutup.
  5. **Dokumentasi (`tech-writer`)**:
     - Buat panduan pengguna (`docs/HOWTO_$ARGUMENTS.md`) yang menjelaskan alur kerja baru yang sudah lengkap dan aman.
  EOF
  ```

* **Command 4: `/bangun-fitur` (Arsitektur & Pembangunan Fitur/Layer Baru dari Nol)**
  ```bash
  cat << 'EOF' > ~/.claude-templates/agent-suite/.claude/commands/bangun-fitur.md
  ---
  description: Merancang & membangun layer/fitur baru (DB + Backend + UI), melakukan hardening security, membuat test case, dan menyusun dokumen How-To.
  ---
  Lakukan siklus **Pembangunan Fitur Baru End-to-End** untuk kebutuhan: **$ARGUMENTS**

  Jalankan tahapan berikut secara berurutan:
  1. **Arsitektur & Pembangunan Fitur (`feature-architect-builder`)**:
     - Analisis tabel database aktual dan modul terkait yang sudah ada.
     - Rancang dan buat file migrasi SQL di folder `sql/`, endpoint backend di `/php-crud/`, serta tampilan UI ExtJS 3.4.1 di `/js/` atau `/views/` sesuai standar `CLAUDE.md`.
  2. **Audit & Hardening Keamanan (`security-auditor-patcher`)**:
     - Periksa seluruh kode fitur baru maupun file eksisting yang diubah terhadap celah SQL Injection, otorisasi sesi, validasi server-side, dan kebocoran error, lalu terapkan patch keamanannya.
     - *Catatan: Berhenti sejenak setelah file migrasi SQL dan kode selesai dibuat agar saya bisa mengeksekusi migrasi SQL secara manual.*
  3. **Penyusunan Skenario Uji (`qa-test-engineer`)**:
     - Susun tabel Test Case (positif, negatif/validasi batas, dan uji keamanan) beserta sampel ID data dari database lokal untuk pengujian.
  4. **Panduan Pengguna (`tech-writer`)**:
     - Buat dokumen panduan operasional (`docs/HOWTO_$ARGUMENTS.md`) agar user memahami cara menggunakan layer/fitur baru tersebut.
  EOF
  ```

### Langkah 5: Buat 2 Skrip Otomatisasi (`setup_claude_audit.sh` & `init_claude_agents.sh`)

* **Skrip 1: `~/setup_claude_audit.sh` (Setup User PostgreSQL Read-Only + Koneksi MCP)**
  ```bash
  cat << 'EOF' > ~/setup_claude_audit.sh
  #!/usr/bin/env bash
  set -e

  DB_NAME=""
  AUDIT_PASS="claude_audit_ro"
  MCP_MODE="standard"
  AUDIT_USER="claude_audit"
  PG_HOST="${PG_HOST:-localhost}"
  PG_PORT="${PG_PORT:-5432}"
  PG_SUPERUSER="${PG_SUPERUSER:-postgres}"

  for arg in "$@"; do
    case $arg in
      --pro) MCP_MODE="pro" ;;
      *)
        if [ -z "$DB_NAME" ]; then DB_NAME="$arg"; else AUDIT_PASS="$arg"; fi
        ;;
    esac
  done

  if [ -z "$DB_NAME" ]; then
    echo "Penggunaan: $0 <NAMA_DATABASE> [PASSWORD_AUDIT] [--pro]"
    exit 1
  fi

  psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_SUPERUSER" -d postgres -v ON_ERROR_STOP=1 <<SQL
  DO \$\$
  BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '$AUDIT_USER') THEN
      CREATE ROLE $AUDIT_USER WITH LOGIN PASSWORD '$AUDIT_PASS';
    ELSE
      ALTER ROLE $AUDIT_USER WITH LOGIN PASSWORD '$AUDIT_PASS';
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

  ENCODED_PASS=$(python3 -c "import urllib.parse, sys; print(urllib.parse.quote(sys.argv, safe=''))" "$AUDIT_PASS")
  DB_URI="postgresql://${AUDIT_USER}:${ENCODED_PASS}@${PG_HOST}:${PG_PORT}/${DB_NAME}"

  claude mcp remove postgres 2>/dev/null || true

  if [ "$MCP_MODE" = "pro" ]; then
    claude mcp add --transport stdio --env DATABASE_URI="$DB_URI" postgres -- uvx postgres-mcp --access-mode=restricted
  else
    claude mcp add postgres -- npx -y @modelcontextprotocol/server-postgres "$DB_URI"
  fi
  echo "✅ MCP Postgres ($MCP_MODE) terhubung ke '$DB_NAME'."
  EOF
  chmod +x ~/setup_claude_audit.sh
  ```

* **Skrip 2: `~/init_claude_agents.sh` (Deploy Tim Agen + `CLAUDE.md` ke Proyek Baru)**
  ```bash
  cat << 'EOF' > ~/init_claude_agents.sh
  #!/usr/bin/env bash
  set -e

  DB_NAME="$1"
  AUDIT_PASS="${2:-claude_audit_ro}"
  MODE_FLAG="${3:-}"

  if [ -z "$DB_NAME" ]; then
    echo "Penggunaan: $0 <NAMA_DATABASE> [PASSWORD_AUDIT] [--pro]"
    exit 1
  fi

  cp -rf ~/.claude-templates/agent-suite/.claude .
  if [ ! -f ./CLAUDE.md ]; then
    cp ~/.claude-templates/agent-suite/CLAUDE.md ./CLAUDE.md
    echo "📄 File standar CLAUDE.md ditambahkan ke root proyek."
  fi

  ~/setup_claude_audit.sh "$DB_NAME" "$AUDIT_PASS" $MODE_FLAG
  echo "🚀 Seluruh Tim Agen (6 Spesialis) siap digunakan di proyek ini!"
  EOF
  chmod +x ~/init_claude_agents.sh
  ```

---

## 4. How-To Step-by-Step: Operasional di Proyek Baru & Eksisting

### Tahap 1: Inisialisasi Proyek (1 Perintah)
Masuk ke direktori proyek Anda dan jalankan:
```bash
cd ~/nama-proyek-anda
~/init_claude_agents.sh nama_database "password_audit" --pro
```
*(Gunakan `--pro` jika Python `uvx` terinstal untuk fitur `EXPLAIN` plan & saran indeks; hilangkan `--pro` jika hanya memakai Node.js `npx`).*

### Tahap 2: Buka Claude Code & Verifikasi Agen
1. Jalankan `claude` (atau `claude --continue` untuk menyambung sesi terakhir).
2. Ketik `/mcp` -> pastikan **`postgres`** aktif (hijau), lalu tekan **Esc**.
3. Ketik `/agents` -> pastikan ke-6 agen (`db-code-auditor`, `feature-architect-builder`, `fullstack-patcher`, `security-auditor-patcher`, `qa-test-engineer`, `tech-writer`) terdaftar.
4. **Pengaturan Mode Eksekusi:** Jika `auto mode` memblokir perintah dengan pesan *classifier blocked*, tekan **`Shift + Tab`** untuk beralih ke **`# manual mode on`**, lalu pilih *"Yes, and don't ask again for similar commands"* saat diminta izin pertama kali.

### Tahap 3: Pilih Perintah Sesuai Skenario Kerja

* **Skenario 1 — Membangun Fitur / Layer Baru dari Nol:**
  ```text
  /bangun-fitur layer pengecekan posisi stok material di seluruh gudang (stok fisik, terbooking, dan stok minimum) sebelum user membuat atau open Purchase Order (PO)
  ```
* **Skenario 2 — Mendeteksi Alur Bisnis yang Bolong & Menambalnya:**
  ```text
  /audit-dan-tambal alur purchase, pemasukan barang, dan pengeluaran barang
  ```
* **Skenario 3 — Audit & Hardening Keamanan File/Modul:**
  ```text
  /audit-security php-crud/purchaseinvoiceCRD.php
  ```
* **Skenario 4 — Audit Fungsional, Test Case & Dokumentasi (Tanpa Ubah Kode):**
  ```text
  /audit-fitur modul release tagihan dan pelunasan piutang
  ```

### Tahap 4: Eksekusi Migrasi & Pengujian Terisolasi (Aman & Hemat Disk)
Jangan pernah mengeksekusi skrip migrasi atau tes tulis (`INSERT/UPDATE/DELETE`) langsung pada database produksi/utama:

1. **Cek Ukuran DB Utama:**
   ```bash
   psql -h localhost -p 5432 -U postgres -c "SELECT pg_size_pretty(pg_database_size('nama_database'));"
   ```
2. **Buat Database Kloningan Uji (`nama_database_test`):**
   * **Jika ukuran DB kecil (< 1 GB) — Kloning Penuh:**
     ```bash
     dropdb -h localhost -p 5432 -U postgres --if-exists "nama_database_test"
     createdb -h localhost -p 5432 -U postgres "nama_database_test"
     pg_dump -h localhost -p 5432 -U postgres "nama_database" | psql -q -h localhost -p 5432 -U postgres -d "nama_database_test"
     ```
   * **Jika ruang disk terbatas / ukuran DB besar — Kloning Skema Saja (`--schema-only`):**
     ```bash
     createdb -h localhost -p 5432 -U postgres "nama_database_test"
     pg_dump -h localhost -p 5432 -U postgres --schema-only "nama_database" | psql -q -h localhost -p 5432 -U postgres -d "nama_database_test"
     ```
3. **Jalankan File Migrasi SQL Buatan Agen ke DB Test:**
   ```bash
   psql -h localhost -p 5432 -U postgres -d "nama_database_test" -f sql/YYYY-MM-DD.nama_fitur.sql
   ```
4. **Minta `qa-test-engineer` Menjalankan Uji Coba di DB Test**, lalu hapus DB test setelah laporan selesai untuk mengembalikan ruang penyimpanan:
   ```bash
   dropdb -h localhost -p 5432 -U postgres "nama_database_test"
   ```
