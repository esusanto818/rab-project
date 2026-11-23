#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="${1:-.claude}"
CMD_DIR="$TARGET_DIR/commands"
SKILL_DIR="$TARGET_DIR/skills/forensic-audit-guard"

mkdir -p "$CMD_DIR" "$SKILL_DIR"

cat << 'EOF' > "$SKILL_DIR/SKILL.md"
---
name: forensic-audit-guard
description: Aturan audit forensik dan skeptis untuk mencegah laporan "false PASS" pada RAB-Project.
---

# Forensic Audit Guard (Anti-False-PASS)

1. **AUDIT FIRST — NO PATCH**: Dilarang keras mengubah source code, membuat file PHP/JS baru, atau menjalankan DDL/migrasi selama sesi `/audit-ketat`.
2. **LARANGAN KLAIM TANPA BUKTI**: Dilarang menulis status `PASS` atau `SAFE` tanpa menyebutkan **nama file + nomor baris** serta **potongan logika** yang membuktikannya.
3. **BUDGET TOKEN KETAT**: Periksa maksimal **4 file per batch**. Gunakan `rg` untuk mencari endpoint/fungsi terkait sebelum membaca file besar secara utuh.
4. **WAJIB SIMPAN KE DISK**: Setiap selesai 1 batch, wajib menulis/menambahkan hasil temuan ke `docs/DEEP_AUDIT_REGISTER.md` dalam format tabel & Finding Card (`ID`, `Severity`, `Lokasi File:Baris`, `Bukti Kode`, `Skenario Gagal di Klien`, `Rekomendasi Perbaikan`, `Test Case`).
5. **UJI NYATA DI `proyekGMN_test`**: Jika melakukan pengujian endpoint (`curl`) atau query verifikasi, gunakan hanya database `proyekGMN_test` dan hapus/bersihkan record uji berawalan `AUDIT-TEST-` setelah selesai.
EOF

cat << 'EOF' > "$CMD_DIR/audit-ketat.md"
---
description: Audit forensik dan integrasi mendalam per batch (mencari bug nyata sebelum rilis ke klien).
argument-hint: "[batch1 | batch2 | batch3 | batch4]"
---

Jalankan **FORENSIC DEEP AUDIT** untuk cakupan `$ARGUMENTS` (jika kosong, jalankan `batch1`) sesuai `CLAUDE.md` v3.0 dan skill `forensic-audit-guard`.

## ATURAN MUTLAK
- **NO PATCH**: Jangan ubah kode aplikasi apa pun.
- **SKEPTICAL MODE**: Anggap setiap modul masih memiliki bug tersembunyi sampai Anda membuktikan sebaliknya lewat bacaan kode baris per baris.
- **MAKSIMAL 4 FILE PER BATCH**: Jangan membaca seluruh repo sekaligus agar tidak kena limit token.
- **OUTPUT WAJIB**: Append seluruh temuan ke `docs/DEEP_AUDIT_REGISTER.md`.

---

## DAFTAR PEMERIKSAAN PER BATCH

### `batch1` — Gerbang Keamanan, RBAC, User Role, & UI ExtJS 3.4.1
Periksa `php-func/user.valid.php`, `js/m.user.role.js`, `js/m.user.system.js`, `php-crud/m.user.systemCRD.php` (serta `roleCRD.php`):
1. **Endpoint Alias & 403 Trap**: Apakah semua file `*CRD.php` di folder `php-crud/` punya pasangan `php_url` yang cocok di `m_form_name` atau terdaftar di `$endpointAliases` pada `user.valid.php`? Jalankan pencarian lintas file untuk menemukan endpoint yang berisiko kena `403 Akses ditolak`.
2. **UI & ExtJS 3.4.1 Crash**: Cek apakah ada pemanggilan class fiktif (`EditorGridModel`), listener `click` pada `CheckColumn`, tombol aksi yang tersembunyi di icon tidak jelas, atau variabel global bocor.
3. **RBAC Matrix & Transaksi**: Cek apakah checkbox `ACTIVE` di matriks role sinkron dengan `access_level`, apakah `Apply Role` memfilter `access_level = 0`, dan apakah `INSERT`/`UPDATE`/`DELETE` hak akses user dibungkus transaksi atomik (`BEGIN`/`COMMIT`/`ROLLBACK`).
4. **Menu Registration**: Cek apakah semua form aktif di `m_form_name` muncul di menu dashboard sesuai `m_user_access_menu`.

### `batch2` — RAB/RAP Budget Control, Material `CLIENT_SUPPLIED`, PO, GRN, & Stok
Periksa modul RAB/RAP, `php-crud/poCRD.php`, `js/po.js`, dan modul GRN / Kartu Stok:
1. **Material Titipan Klien (`CLIENT_SUPPLIED`)**: Apakah item dengan harga Rp 0 / titipan klien bisa masuk RAB/RAP, melewati validasi PO/GRN tanpa ditolak pembagian nol (`division by zero`) atau blokir budget, dan menambah stok fisik tanpa menciptakan hutang AP?
2. **Transisi Status & Direct Release PO**: Apakah PO berstatus `Released` terkunci dari edit/hapus ilegal, dan apakah GRN hanya bisa menerima barang dari PO yang sah tanpa melebihi sisa qty PO (`over-receiving`)?
3. **Integritas Kartu Stok & Retur**: Apakah stok masuk/keluar/retur memakai locking (`FOR UPDATE`), mencatat saldo awal-akhir konsisten, dan mencegah stok minus?
4. **Penomoran Otomatis**: Cek `CreateRefNo` / `CreateRefNoDb` terhadap bug transisi bulan/tahun dan regex titik literal PostgreSQL.

### `batch3` — SPK/Subkon (Buy-Back), Opname Progress, Retensi, Tagihan, PPN, & Jurnal
Periksa modul SPK/Subkon, Opname Progress, Invoice/Tagihan Klien, Pembayaran, dan Jurnal Otomatis:
1. **Batas Progress & Double Claim**: Apakah akumulasi opname progress (internal, subkon, maupun proyek) divalidasi ketat `<= 100%` di sisi server (bukan hanya di UI JS)?
2. **Subcon Buy-Back & Potongan DP**: Apakah pemotongan material *buy-back* subkontraktor dan pengembalian uang muka (DP) terhitung akurat dan tidak membuat nilai tagihan bersih menjadi negatif?
3. **Retensi Klien & PPN**: Apakah persentase retensi dan PPN (DPP + PPN) konsisten antara Tagihan Proyek, Kartu Piutang (`ar.aging`), dan Jurnal Umum?
4. **Pencegahan Double-Posting Jurnal**: Apakah tombol Release / Approve / Posting memiliki *idempotency guard* (cek status + `FOR UPDATE`) sehingga klik ganda tidak menghasilkan jurnal kembar?

### `batch4` — Dashboard, Cetak PDF, Mobile Views, Upload, & Final Release Gate
Periksa `views/dashboard.php`, `php-crud/dashboardCRD.php`, `pdf/*.php`, `*.m.php`, dan endpoint upload:
1. **Cetak PDF (`pdf/`)**: Cek apakah semua file di `pdf/` memanggil `user.valid.php` atau validasi session aktif (tidak bisa dibuka langsung lewat URL tanpa login) dan aman dari pembagian nol / variabel kosong saat item kosong.
2. **Dashboard & Mobile**: Cek apakah angka KPI di dashboard cocok dengan tabel transaksi aktual dan halaman mobile (`*.m.php`) bebas dari *hardcode path*.
3. **Final Release Decision**: Rangkum total temuan `CRITICAL`, `HIGH`, `MEDIUM`, `LOW` di `docs/DEEP_AUDIT_REGISTER.md` dan berikan keputusan objektif: **LAYAK RILIS** atau **TUNDA RILIS (WAJIB PATCH DULU)**.
EOF

echo "Berhasil memasang /audit-ketat dan skill forensic-audit-guard di $TARGET_DIR"
