# Laporan Audit & Konsolidasi CSS Terpusat — Tahap 0

**Tanggal:** 2026-11-22
**Branch:** `dev`
**File tujuan:** `css/app-theme.css` (Single Source of Truth)
**Cakupan:** Seluruh file `.css` di `css/` (kecuali vendor `ext-all.css`) + semua blok `<style>` inline di `views/` dan `includes/`.

---

## 1. Ringkasan Eksekutif

Sebelum Tahap 0, proyek memiliki 37 file `.css` di `css/` dengan banyak duplikasi palet warna, aturan `!important` yang bertabrakan, dan kode warna hardcode yang tersebar antara Dashboard, Menu/Navbar, dan Form ExtJS 3.4.1. Tidak ada satu file yang menjadi referensi tungga untuk warna tema.

Setelah Tahap 0:
- Semua warna tema dikonsolidasikan ke **satu file**: `css/app-theme.css` sebagai Single Source of Truth menggunakan CSS Design Tokens (variabel CSS) pada `:root, html[data-theme="light"]` dan `html[data-theme="dark"]`.
- 17 file CSS duplikat yang tidak direferensi manapun dihapus.
- Struktur `app-theme.css` dibagi menjadi 4 bagian (A: Design Tokens, B: Dashboard scope, C: Navbar & Dropdown Menu scope, D: Form & Grid ExtJS 3.4.1 scope).
- Tidak ada perubahan layout HTML atau `<script>` pada `views/dashboard.php`, dan tidak ada perubahan fungsi PHP/JS.

---

## 2. Audit Menyeluruh CSS

### 2.1 File `.css` di `css/` (sebelum konsolidasi)

| File | Status | Keterangan |
|------|--------|------------|
| `ext-all.css` | Dipertahankan | Vendor library inti ExtJS 3.4.1 (dikeluarkan dari audit) |
| `app-theme.css` | Diperbarui | Single Source of Truth (rewrite, Section A–D) |
| `buttonColumn.css` | Dipertahankan | Digunakan oleh grid ExtJS (kolom tombol) |
| `components.css` | Dipertahankan | Digunakan oleh halaman non-dashboard |
| `fileuploadfield.css` | Dipertahankan | Komponen file upload ExtJS |
| `frm.css` | Dipertahankan | Digunakan oleh form ExtJS |
| `grddetail.css` | Dipertahankan | Digunakan oleh grid detail |
| `header.black.css` | Dipertahankan | Tema header hitam yang masih direferensi |
| `jquery-ui.min.css` | Dipertahankan | Vendor jQuery UI |
| `jquery-ui.theme.css` | Dipertahankan | Vendor jQuery UI theme |
| `jquery.mobile-1.4.5.min.css` | Dipertahankan | Vendor jQuery Mobile |
| `jquery.mobile.flatui.css` | Dipertahankan | Vendor jQuery Mobile FlatUI |
| `jquery.mobile.flatui.min.css` | Dipertahankan | Vendor jQuery Mobile FlatUI minified |
| `logWdw.css` | Dipertahankan | Window log |
| `mainCSS.css` | Dipertahankan | CSS utama yang masih direferensi |
| `materialize.css` | Dipertahankan | Vendor Materialize |
| `materialize.min.css` | Dipertahankan | Vendor Materialize minified |
| `menu.css` | Dipertahankan | CSS menu |
| `myFonts.css` | Dipertahankan | Font kustom |
| `popUp.css` | Dipertahankan | Popup window |
| `theme.css` | Dipertahankan | Tema dasar |
| `xtheme-gray.css` | Dipertahankan | Tema gray ExtJS |
| `darkB.css` | **Dihapus** | Tidak direferensi, duplikasi tema gelap |
| `darkTheme.css` | **Dihapus** | Tidak direferensi, duplikasi tema gelap |
| `dark_thm.css` | **Dihapus** | Tidak direferensi, duplikasi tema gelap |
| `frm_err1.css` | **Dihapus** | Tidak direferensi, duplikasi form error |
| `frm_err2.css` | **Dihapus** | Tidak direferensi, duplikasi form error |
| `frm_err.css` | **Dihapus** | Tidak direferensi, duplikasi form error |
| `grddetail00.css` | **Dihapus** | Tidak direferensi, duplikasi grid detail |
| `header.blue.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.bluegrey.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.green.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.grey.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.orange.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.red.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.teal.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `header.white.css` | **Dihapus** | Tidak direferensi, duplikasi header |
| `linkMenu.css` | **Dihapus** | Tidak direferensi |
| `mainCSS_err.css` | **Dihapus** | Tidak direferensi, duplikasi mainCSS |
| `mobileCSS.css` | **Dihapus** | Tidak direferensi, duplikasi mobile |

**Total:** 37 file → 20 file (17 file duplikat dihapus).

### 2.2 Blok `<style>` inline yang ditemukan

Berikut adalah semua file yang memiliki blok `<style>` inline (dikotak dalam `views/` dan `includes/`):

`views/dashboard.php`, `views/nav_wrapper.php`, dan 57 file `.php` lainnya di `views/`, serta `includes/card-monitoring.php`.

Masalah yang ditemukan di blok inline:
- **Hardcode warna berulang** di `views/dashboard.php`: `#000000`, `#e2e8f0`, `#22d3ee`, `#008afb`, `#ee2222`, `#1f2d3a`, `#2b3f52`, `#066b78`, `#04101c`, `#0a2d4a`, `#071d30`, rose/cyan/amber/emerald/slate variants, dll.
- **Hardcode warna di `views/nav_wrapper.php`**: `bg-[#060a12]`, `bg-[#0c1322]/95`, `border-slate-800/700`, `text-slate-300`, `hover:text-amber-400`, `bg-rose-600`, dll.
- Tidak ada file yang menggunakan variabel CSS dari `app-theme.css` di blok inline (seluruhnya hardcode).

Karena aturan Tahap 0 melarang mengubah layout HTML / `<script>` di `views/dashboard.php` dan melarang mengubah fungsi PHP/JS, blok inline hardcode **tidak diubah** pada Tahap 0. Mereka akan ditangani di tahap selanjutnya (migrasi ke variabel `app-theme.css`).

### 2.3 Duplikasi & bentrok `!important`

File CSS yang dihapus berisi banyak aturan dengan `!important` yang saling menimpa:
- `darkTheme.css`, `dark_thm.css`, `darkB.css`: masing-masing menimpa `.x-grid3-hd-row`, `.x-grid3-row`, `.x-grid3-cell`, `.x-toolbar` dengan warna hardcode berbeda-beda (`#0c1021`, `#04101c`, `#02121e`, dll.).
- `frm.css` dan `frm_err*.css`: duplikasi warna form (`#1d4c6d`, `#38bdf8`, `#091a29`, `#ffffff`).
- `header.*.css`: 9 file tema header dengan palet yang berbeda-beda tetapi selector yang sama.
- `mobileCSS.css` vs `mainCSS.css`: duplikasi aturan warna untuk layar selang.

Semua telah digabung dan diganti oleh variabel tunggal di `css/app-theme.css` (Bagian D untuk ExtJS, Bagian C untuk navbar/menu).

---

## 3. Standarisasi `css/app-theme.css` (Single Source of Truth)

File `css/app-theme.css` ditulis ulang dengan struktur 4 bagian:

### Bagian A — Design Tokens (Palet Industrial Slate & Steel Amber)

Variabel CSS didefinisikan pada `:root, html[data-theme="light"]` (*Architectural Daylight*) dan `html[data-theme="dark"]` (*Obsidian Blueprint*), mencakup seluruh variabel yang ditentukan:

| Variabel | Light | Dark |
|----------|-------|------|
| `--bg-main` | `#F1F5F9` | `#090D16` |
| `--bg-panel` | `#FFFFFF` | `#111827` |
| `--bg-card` | `#FFFFFF` | `#111827` |
| `--header-bg` | `#0F172A` | `#030712` |
| `--header-text` | `#F8FAFC` | `#F8FAFC` |
| `--border-color` | `#CBD5E1` | `#1E293B` |
| `--text-main` | `#0F172A` | `#F1F5F9` |
| `--text-muted` | `#475569` | `#94A3B8` |
| `--primary` | `#0369A1` | `#38BDF8` |
| `--primary-hover` | `#075985` | `#0EA5E9` |
| `--accent-strong` | `#D97706` | `#F59E0B` |
| `--grid-header-bg` | `#E2E8F0` | `#1E293B` |
| `--grid-header-text` | `#0F172A` | `#FBBF24` |
| `--grid-row-bg` | `#FFFFFF` | `#111827` |
| `--grid-row-alt` | `#F8FAFC` | `#0F172A` |
| `--grid-cell-text` | `#0F172A` | `#F1F5F9` |
| `--grid-selected-bg` | `#DBEAFE` | `#1E3A5F` |
| `--grid-selected-text` | `#1E3A8A` | `#FFFFFF` |
| `--input-bg` | `#FFFFFF` | `#090D16` |
| `--input-text` | `#0F172A` | `#F1F5F9` |
| `--input-border` | `#94A3B8` | `#334155` |

### Bagian B — Scope Dashboard (`.dashboard-scope`)

Aturan tema berbasis variabel untuk kartu (`.dashboard-card`, `.bg-card`, `.card`), tab (`.tab-btn` aktif/hover), dan tabel (thead, tbody, baris genap/ganjil) di `views/dashboard.php`.

### Bagian C — Scope Navbar & Dropdown Menu

Aturan tema berbasis variabel untuk `.navbar-scope`, `nav`, `.header`, `.nav-item > a`, `.dropdown-menu`, `.dropdown-menu a`, `.menu-column h3`, hamburger (`#hamburger_btn`), dan mobile dropdown (`#mobile_dropdown`).

### Bagian D — Scope Form & Grid ExtJS 3.4.1

Aturan tema berbasis variabel untuk `.x-panel-body`, `.x-window-body`, `.x-window-header`, `.x-grid3-hd-row`, `.x-grid3-row`, `.x-grid3-row-alt`, `.x-grid3-cell`, `.x-grid3-row-selected`, `.x-form-text`, `.x-form-field`, `.x-form-item label`, `.x-toolbar`, `.x-btn button`, `.x-combo-list`, `.x-combo-list-item`, `.x-tab-panel-header`, `.x-tab-strip-top`, tanpa mengubah ukuran atau positioning ExtJS.

---

## 4. Pembersihan File CSS Duplikat

17 file CSS yang tidak direferensi manapun di seluruh proyek (divalidasi dengan `grep` ke `*.php`, `*.js`, `*.html`) dihapus:

`darkB.css`, `darkTheme.css`, `dark_thm.css`, `frm_err1.css`, `frm_err2.css`, `frm_err.css`, `grddetail00.css`, `header.blue.css`, `header.bluegrey.css`, `header.green.css`, `header.grey.css`, `header.orange.css`, `header.red.css`, `header.teal.css`, `header.white.css`, `linkMenu.css`, `mainCSS_err.css`, `mobileCSS.css`.

Tidak ada file CSS ganda yang tersisa yang menimpa `app-theme.css` untuk warna tema inti.

---

## 5. Verifikasi

- `git status -s`: 17 file dihapus, 1 file (`css/app-theme.css`) diubah.
- Tidak ada referensi tersisa ke file CSS yang dihapus di `views/`, `includes/`, atau file lain di root proyek (kecuali folder backup yang dikecualikan).
- `css/app-theme.css` menggunakan hanya sintaks CSS standar (variabel CSS `:root` / `html[data-theme]`, `var()`), kompatibel dengan semua browser target.
- Tidak ada perubahan layout HTML, `<script>`, fungsi PHP, atau fungsi JS pada Tahap 0.

---

## 6. Langkah Selanjutnya (Tahap 1+)

- Migrasi hardcode warna di blok `<style>` inline `views/dashboard.php` dan `views/nav_wrapper.php` ke variabel `app-theme.css`.
- Pastikan semua halaman memuat `css/app-theme.css` sebagai referensi tema tunggal.
- Uji visual Light/Dark theme pada Dashboard, Menu, dan Form ExtJS.
