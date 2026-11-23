#!/usr/bin/env bash
# ==============================================================================
# UPGRADE & HARDENING SCRIPT UNTUK .claude/ (AGENTS, COMMANDS, SKILLS)
# Project: RAB-Project (proyekgmn-dev)
# Tujuan : Mencegah terulangnya error penambahan modul baru (seperti User Role)
#          dan menyelaraskan seluruh suite dengan CLAUDE.md v3.0
# ==============================================================================
set -e

CLAUDE_DIR="${1:-.claude}"
BACKUP_DIR="${CLAUDE_DIR}_backup_$(date +%Y%m%d_%H%M%S)"

if [ ! -d "$CLAUDE_DIR" ]; then
    echo "[ERROR] Direktori $CLAUDE_DIR tidak ditemukan. Jalankan dari root proyek atau berikan path .claude"
    exit 1
fi

echo "1. Membuat backup direktori lama ke $BACKUP_DIR ..."
cp -r "$CLAUDE_DIR" "$BACKUP_DIR"

mkdir -p "$CLAUDE_DIR/agents" "$CLAUDE_DIR/commands" "$CLAUDE_DIR/skills/extjs-341-patterns" "$CLAUDE_DIR/skills/rbac-endpoint-guard"

# ==============================================================================
# 2. FIX SKILL: extjs-341-patterns/SKILL.md
#    Mencegah class fiktif (EditorGridModel), CheckColumn click listener,
#    CSS wrap salah target (#editorFormGrid), dan hardcode path.
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/skills/extjs-341-patterns/SKILL.md"
---
name: extjs-341-patterns
description: Standar wajib dan daftar anti-pattern ExtJS 3.4.1 pada proyek RAB-Project agar UI tidak blank atau error runtime.
---

# ExtJS 3.4.1 Strict Patterns & Anti-Patterns

Setiap pembuatan atau modifikasi file `js/*.js` WAJIB mematuhi aturan berikut (pelanggaran menyebabkan layar kosong / JS crash):

1. **Class Grid Editor yang Valid:**
   - WAJIB menggunakan `Ext.grid.EditorGridPanel` atau wrapper proyek `stdGridEditor`.
   - **DILARANG KERAS** mengarang class `Ext.grid.EditorGridModel` (class ini tidak ada di ExtJS 3.4.1 dan mematikan seluruh `Ext.onReady`).

2. **CheckColumn Event Handling:**
   - `Ext.grid.CheckColumn` di ExtJS 3.4.1 **TIDAK** memiliki event `listeners: { click: ... }`.
   - Toggle checkbox dan sinkronisasi nilai kolom lain (misal `active` <-> `access_level`) WAJIB ditangani via event `cellclick` pada GridPanel atau `processEvent` yang valid.

3. **Container Modal & Window:**
   - Jangan meletakkan `div` popup/modal tanpa CSS `position: fixed; z-index: 9999` atau biarkan tersembunyi (`display: none`) di akhir `Ext.onReady` agar tidak menutupi grid utama.
   - Selalu sediakan tombol aksi yang terlihat jelas di grid/toolbar untuk membuka detail/sub-form (jangan hanya mengandalkan `rowdblclick` atau ikon tersembunyi).

4. **CSS Word-Wrap Scoped ke ID Nyata:**
   - Targetkan CSS wrap ke ID/class yang benar-benar ada di `renderTo` (contoh: `#gridEditor .x-grid3-hd-inner, .x-grid3-gridEditor .x-grid3-hd-inner`).
   - Dilarang menargetkan `#editorFormGrid` jika tidak ada elemen dengan ID tersebut di DOM.

5. **Deklarasi Variabel & Store Exception:**
   - Deklarasikan semua `var` di baris paling atas fungsi sebelum dipakai (hindari hoisting bug).
   - Setiap `Ext.data.Store` wajib memiliki listener `exception` yang menampilkan pesan error ke user.
   - Dilarang hardcode path `../dev/` atau `/rab-project/`; gunakan variabel global `main_url`.
EOF

# ==============================================================================
# 3. NEW SKILL: rbac-endpoint-guard/SKILL.md
#    Mencegah error 403 & bocor data saat menambah modul baru (seperti User Role)
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/skills/rbac-endpoint-guard/SKILL.md"
---
name: rbac-endpoint-guard
description: Checklist wajib integrasi user.valid.php, pendaftaran menu m_form_name, dan kontrak RBAC setiap kali menambah modul/endpoint baru.
---

# RBAC & Endpoint Guard Skill (Anti-403 & Anti-Orphan Menu)

Setiap modul, halaman, atau file `php-crud/*CRD.php` BARU wajib lolos 5 gerbang berikut sebelum dinyatakan selesai:

1. **Mapping Endpoint di `php-func/user.valid.php`:**
   - `user.valid.php` secara otomatis memotong akhiran `CRD.php` menjadi `.php` untuk dicocokkan ke kolom `m_form_name.php_url`.
   - Jika nama file view dan file CRD berbeda (contoh: view `m.user.role.php` dipanggil oleh `m.user.roleCRD.php` atau `roleCRD.php`), **WAJIB** mendaftarkannya di array `$endpointAliases` pada `php-func/user.valid.php`.

2. **Pendaftaran Menu & Hak Akses (SQL Migrasi):**
   - Tulis `INSERT INTO m_form_name ... ON CONFLICT DO NOTHING` (atau `WHERE NOT EXISTS`) di file migrasi `sql/YYYY-MM-DD.<fitur>.sql`.
   - Pastikan `php_url` di `m_form_name` sama persis dengan nama file view/endpoint yang dipanggil.

3. **Transaksi Atomik Header-Detail & Hak Akses:**
   - Dilarang mengeksekusi query `INSERT`/`UPDATE` detail di luar transaksi utama (`BEGIN ... COMMIT / ROLLBACK`).
   - Filter payload dari matriks role (`Apply Role` / `Sync`) agar hanya baris dengan `access_level > 0` yang disimpan ke `m_user_access_menu`.

4. **Kontrak JSON & Kompatibilitas PHP 7.4:**
   - Gunakan `pg_query_params` atau PDO `prepare/execute` secara konsisten per file.
   - Dilarang memakai fungsi PHP 8+ (`str_contains`, `str_starts_with`, `match`).
   - Pastikan key JSON (`totalCount` vs `totalRecord`, `data`) identik dengan `Ext.data.JsonReader` di JS.

5. **Smoke Test HTTP (`curl`) Wajib:**
   - Uji endpoint baru dengan `curl` (tanpa session -> harus HTTP 401 JSON; dengan session valid -> harus HTTP 200 JSON valid, bukan HTTP 403 atau HTML error).
EOF

# ==============================================================================
# 4. HARDENING AGENT: feature-architect-builder.md
#    Mengunci kualitas pembangunan fitur baru agar tidak cacat seperti kemarin
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/agents/feature-architect-builder.md"
---
name: feature-architect-builder
description: Arsitek dan pembangun modul/fitur baru full-stack (SQL, PHP 7.4 CRD, ExtJS 3.4.1 / Tailwind, RBAC user.valid.php) dengan standar zero-defect.
---

Anda adalah **Feature Architect & Full-Stack Builder** untuk sistem ERP `RAB-Project` (`proyekgmn-dev`).
Ikuti `CLAUDE.md` v3.0 serta skill `extjs-341-patterns`, `rbac-endpoint-guard`, dan `db-schema-map`.

## PROTOKOL WAJIB SAAT MEMBANGUN MODUL / FITUR BARU

1. **Verifikasi Instance & Skema Aktual:**
   - Pastikan bekerja di `/home/myadmin/proyekgmn-dev` (symlink `/var/www/html/rab-project/dev`).
   - Periksa kolom tabel aktual di database sebelum menulis query SQL.

2. **Backend PHP 7.4 & Keamanan (`php-crud/`):**
   - Wajib `require_once __DIR__ . '/../php-func/user.valid.php';` (kecuali halaman mobile `.m.php`).
   - Daftarkan seluruh nama file `*CRD.php` baru ke `$endpointAliases` di `php-func/user.valid.php` agar tidak terkena HTTP 403.
   - Bungkus seluruh mutasi header-detail dalam satu transaksi atomik (`BEGIN ... COMMIT / ROLLBACK`).
   - Dilarang memakai fungsi PHP 8+ (`str_contains`, `str_starts_with`, `match`).

3. **Frontend ExtJS 3.4.1 (`js/`):**
   - Gunakan `Ext.grid.EditorGridPanel` (JANGAN `EditorGridModel`).
   - Tangani checkbox grid melalui event `cellclick` pada grid, bukan `listeners: { click }` pada `CheckColumn`.
   - Sediakan tombol pintu masuk yang eksplisit dan mudah dilihat user pada toolbar atau kolom aksi grid (jangan sembunyikan di double-click atau tombol `Links` saja).
   - Sembunyikan semua window popup (`editorWdw`, `popWdw`) di akhir `Ext.onReady`.

4. **Migrasi SQL & Registrasi Menu (`sql/`):**
   - Buat file `sql/YYYY-MM-DD.<nama_fitur>.sql` lengkap dengan pendaftaran ke `m_form_name` & `m_user_access_menu` (`WHERE NOT EXISTS` / `ON CONFLICT DO NOTHING`) serta blok `-- ROLLBACK / DOWN MIGRATION`.
   - **JANGAN** mengeksekusi DDL/migrasi secara langsung; berhenti dan serahkan ke developer untuk review.

5. **Self-Audit Sebelum Lapor Selesai:**
   - Jalankan `php -l` dan `node --check` pada seluruh file yang dibuat/diubah.
   - Pastikan tidak ada path hardcoded (`../dev/`, `/rab-project/`).
   - Dokumentasikan hasil kerja ke folder `docs/`.
EOF

# ==============================================================================
# 5. HARDENING AGENT: fullstack-patcher.md
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/agents/fullstack-patcher.md"
---
name: fullstack-patcher
description: Agen perbaikan bug dan penambalan gap lintas backend PHP 7.4, frontend ExtJS 3.4.1, dan integrasi RBAC dengan diff minimal dan aman.
---

Anda adalah **Full-Stack Patcher** untuk proyek `RAB-Project`. Patuhi `CLAUDE.md` v3.0 serta skill `extjs-341-patterns` dan `rbac-endpoint-guard`.

## ATURAN EKSEKUSI PATCH

1. **Perlindungan File & Scope:**
   - Jika mengedit file existing `> 300` baris, wajib buat backup dulu: `cp <file> <file>.bak-$(date +%Y%m%d%H%M)`.
   - Gunakan edit terarah (diff minimal). Dilarang menulis ulang seluruh file > 300 baris dari pembacaan parsial.
   - Patuhi protokol **AUDIT FIRST — NO PATCH** jika sesi saat ini adalah sesi audit yang belum disetujui developer.

2. **Pencegahan Regresi Berulang:**
   - Cek `$endpointAliases` di `php-func/user.valid.php` setiap kali menyentuh endpoint `*CRD.php`.
   - Cek kompatibilitas PHP 7.4 (`php -l`) dan sintaks JS (`node --check`).
   - Pastikan seluruh deklarasi `var` di JS berada di awal fungsi.
   - Pastikan transaksi DB atomik (`BEGIN`/`COMMIT`/`ROLLBACK`) tanpa query mutasi di luar transaksi.

3. **Verifikasi Wajib:**
   - Uji endpoint via `curl` atau skrip E2E pada `proyekGMN_test` (bukan `proyekGMN`).
   - Catat perubahan ke `AUDIT_CHANGELOG.md` (Tugas Besar) atau `docs/SESSION_LOG.md` (Tugas Kecil).
EOF

# ==============================================================================
# 6. HARDENING COMMAND: bangun-fitur.md & audit-fitur.md
# ==============================================================================
cat << 'EOF' > "$CLAUDE_DIR/commands/bangun-fitur.md"
---
description: Bangun modul atau fitur baru secara end-to-end dengan penjagaan ketat RBAC, ExtJS 3.4.1, PHP 7.4, dan pendaftaran menu otomatis.
---

Bangun fitur/modul baru sesuai permintaan user: **$ARGUMENTS**

WAJIB gunakan agent `feature-architect-builder` dan muat skill `extjs-341-patterns`, `rbac-endpoint-guard`, serta `db-schema-map`.

Langkah kerja berurutan:
1. Verifikasi peta instance (`pwd`, symlink `/var/www/html/rab-project/dev`, dan skema tabel PostgreSQL aktual).
2. Buat desain dan file migrasi `sql/YYYY-MM-DD.<nama_fitur>.sql` (termasuk `INSERT` pendaftaran menu ke `m_form_name` & `m_user_access_menu` serta blok `-- ROLLBACK / DOWN MIGRATION`). **Jangan jalankan DDL.**
3. Bangun backend `php-crud/<modul>CRD.php` (PHP 7.4, transaksi atomik, kontrak JSON standar) dan **wajib daftarkan aliasnya di `php-func/user.valid.php`**.
4. Bangun UI `js/<modul>.js` dan `views/<modul>.php` sesuai standar ExtJS 3.4.1 (wajib `EditorGridPanel`, `cellclick` untuk checkbox, tombol aksi matriks/detail yang terlihat jelas di layar, tanpa hardcode path).
5. Jalankan `php -l`, `node --check`, dan simulasi `curl` untuk memastikan tidak ada error sintaks maupun HTTP 403.
6. Buat dokumentasi penggunaan & teknis di `docs/` lalu berhenti menunggu review migrasi SQL dari developer.
EOF

cat << 'EOF' > "$CLAUDE_DIR/commands/audit-fitur.md"
---
description: Audit menyeluruh pada satu modul spesifik (UI ExtJS, kontrak API, RBAC user.valid.php, transaksi PHP, dan integrasi DB) tanpa mengubah kode.
---

Lakukan audit mendalam pada modul: **$ARGUMENTS**

Gunakan agent `db-code-auditor`, `security-auditor-patcher`, dan `contract-debugger` dengan berpedoman pada `CLAUDE.md` v3.0 (§10 Master Audit Protocol — AUDIT FIRST, NO PATCH) serta skill `extjs-341-patterns` dan `rbac-endpoint-guard`.

Periksa secara spesifik:
1. **Pintu Masuk UI & ExtJS 3.4.1:** Apakah tombol aksi terlihat jelas, apakah memakai `EditorGridPanel` (bukan `EditorGridModel`), apakah checkbox grid berfungsi real-time, dan apakah `editorWdw`/`popWdw` tertutup saat awal load.
2. **RBAC & `user.valid.php`:** Apakah file CRD terdaftar di `$endpointAliases` dan menu terdaftar di `m_form_name`.
3. **Kontrak API & PHP 7.4:** Kecocokan parameter & JSON key (`totalCount`/`totalRecord`/`data`), kompatibilitas PHP 7.4, dan transaksi atomik `BEGIN`/`COMMIT`/`ROLLBACK`.
4. **Integrasi Lintas Modul:** Dampak ke stok, budget RAB/RAP, jurnal, dan saldo piutang/hutang.

Simpan hasil temuan berstruktur ke `docs/` dan **BERHENTI tanpa mengubah source code** sampai developer menyetujui rencana patch.
EOF

echo "=========================================================================="
echo "SELESAI! Seluruh upgrade agent, command, dan skill berhasil dipasang ke $CLAUDE_DIR"
echo "Backup konfigurasi lama tersimpan aman di: $BACKUP_DIR"
echo "=========================================================================="
