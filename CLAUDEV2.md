# DEVELOPMENT GUIDELINE & INSTRUCTIONS (v2)

## 0. PETA INSTANCE (baca dulu)
URL dev yang dibuka developer: `http://127.0.0.1/rab-project/dev/`. Server E2E: env `RAB_BASE_URL` (default `http://127.0.0.1:8098`).
DB uji: `proyekGMN_test`. DB produksi: `proyekGMN`. DB yang benar-benar dipakai aplikasi dibaca dari `php-func/function.all.php`; jangan menebak.
Folder kerja = hasil `pwd`. Sebelum mengedit pastikan file itu memang yang disajikan URL di atas (cek symlink/docroot). TODO developer: konfirmasi hubungan `/home/myadmin/proyekgmn-dev` dengan `/rab-project/dev`.
[v2] LARANGAN HARDCODE PATH: dilarang keras path hardcoded seperti `../dev/`, `/rab-project/`, atau absolut OS. JS ExtJS wajib pakai global `main_url`; JS vanilla/mobile wajib pakai `window.APP_BASE_URL` (di-set dari PHP `$APP_BASE`); PHP endpoint wajib pakai `__DIR__ . '/../'`.

## 1. ATURAN UMUM PENGEMBANGAN
Cara menulis perubahan: file BARU ditulis utuh. File EKSISTING diubah dengan edit terarah (diff minimal) dan komentar jelas pada baris yang berubah atau ditambah. Menulis ulang seluruh file eksisting hanya bila file <= 300 baris atau diminta eksplisit. Alasannya: tulis-ulang-penuh dari bacaan parsial pernah mengembalikan bagian lama (regresi).
Tingkat tugas: Kecil = bugfix/UI, maksimal 3 file, tanpa SQL. Besar = audit, fitur/modul baru, migrasi SQL, atau patch lebih dari 3 file. Tingkat ini menentukan beban administrasi (lihat section 5).
Pemisahan logika: pisahkan UI (`/js/` atau `/views/`) dari pemrosesan data (`/php-crud/`); modul PO adalah acuan.
Kontrak API: bila mengubah nama `action`, parameter, atau bentuk JSON sebuah endpoint, ubah SEMUA pemanggilnya dalam perubahan yang sama: `rg -n "<nama_action>" views/ js/ js-func/ php-crud/` (JS halaman bisa inline di `views/*.php`). Tugas selesai hanya bila kedua sisi cocok.
Halaman kosong / angka 0 / data tidak tampil: gunakan `/perbaiki-ui` (agent `contract-debugger`). Reproduksi dulu (curl atau DevTools, tab Network), baru baca kode.
[v2] Backup: sebelum edit file kritis (>300 baris / produksi), jalankan `cp file file.bak-$(date +%Y%m%d%H%M)`. Jangan andalkan git saja untuk file yang aktif di produksi.
[v2] Deklarasi JS: semua `var`/`let`/`const` dideklarasikan di awal fungsi sebelum digunakan; jangan mengandalkan hoisting. Setelah edit JS, buka DevTools > Console, pastikan 0 error merah sebelum menyatakan selesai.

## 2. STANDAR BACKEND (PHP & POSTGRESQL)
Helper & koneksi tunggal: di setiap endpoint backend panggil
`require_once __DIR__ . '/../php-func/function.all.php';` dan
`require_once __DIR__ . '/../php-class/crudClass.php';`
(path relatif ke file, bukan ke direktori kerja).
PHP 7.4: dilarang sintaks/fungsi PHP 8+ (`str_starts_with`, `str_contains`, `str_ends_with`, `match`, dll.).
Keamanan query: selalu `pg_query_params` atau PDO `prepare/execute`.
[v2] Dual sistem query: endpoint lama (`ar.agingCRD`, `ap.agingCRD`, `m.user.roleCRD`) pakai `pg_query_params` dengan `$1, $2`; endpoint baru (`poCRD`, `dashboardCRD`) pakai PDO via `$db = new crud()`. Endpoint baru WAJIB ikut pola PDO; jangan campur dalam satu file.
Format respons JSON (`/php-crud/*.php`):
Read (CRUD/grid): `{"success": true, "totalCount": 100, "data": [...]}`
Mutasi: `{"success": true, "message": "Data berhasil disimpan."}`
Gagal: `{"success": false, "message": "Pesan kesalahan spesifik."}`
[v2] Kontrak JSON dua bentuk: (1) List ExtJS `{success, totalCount, data:[...]}` dipakai `ar.agingCRD`, `ap.agingCRD`, `m.user.roleCRD`, `m.user.systemCRD`, `stock.cardCRD`; (2) Nested poCRD `{success, message, data:{data:[...],totalCount}}` atau `{success, message, data:{header, items, allowed_actions}}`. Reader JS HARUS cocok dengan bentuk server; catat bentuknya di komentar header endpoint.
Endpoint agregat read-only (mis. `dashboardCRD.php`) boleh memakai key khusus (`items`, `kpi`, `datasets`, ...) asalkan selalu ada `success`, `message` saat gagal, dan bentuk tiap action dicatat di komentar header file.
Error handling: tangkap `Throwable` (bukan hanya `Exception`) di endpoint. Fatal error harus menghasilkan JSON `{"success": false, "message": ...}` (HTTP 500) plus `error_log`, bukan body kosong.
Transaksi ACID: `BEGIN ... COMMIT / ROLLBACK` untuk data bertingkat (header & detail), `FOR UPDATE` untuk perubahan status dokumen. Dilarang `echo`/`var_dump`/`print_r` untuk debugging; satu-satunya output adalah JSON final. Format tanggal `YYYY-MM-DD`, desimal memakai titik.
[v2] Modul baru yang mungkin belum migrasi tabelnya: bungkus query dengan `tbl_exists($db, 'nama_tabel')` (pola `dashboardCRD.php`).
[v2] Hak akses: read-only agregat (dashboard, aging, stock card) boleh cek hak langsung dari `m_user_access_menu` via `php_url`; CRUD (save/update/delete) WAJIB pakai `FormAccess` dari sesi yang di-set `user.valid.php`. Jangan pakai `FormAccess` menu A untuk otorisasi operasi di menu B.

## 3. STANDAR FRONTEND
### 3a. Halaman ExtJS 3.4.1 (`js/*.js` dan view yang memuat `Ext.*`)
Setiap `Ext.data.Store` wajib punya listener `exception` yang menampilkan error eksplisit.
Null-check `dataRecord` sebelum Edit/Delete; pastikan form ter-render sebelum `.getForm().load()` atau `.setValue()`.
Payload bertingkat: `Ext.util.JSON.encode` via POST.
[v2] API ExtJS 3.4.1: HANYA gunakan class yang benar-benar ada di 3.4.1. Editor grid = `Ext.grid.EditorGridPanel` (BUKAN `EditorGridModel` — class itu tidak ada dan mematikan seluruh `Ext.onReady`). CheckColumn = `Ext.grid.CheckColumn` (tidak punya event `click`; toggle via `cellclick` di grid). `Extensive.grid.ItemDeleter` ada di proyek ini, boleh pakai properti `header` untuk SVG icon. Bila ragu, cek `ext-3.4.1/source/` sebelum menulis; jangan mengarang class.
[v2] Grid teks panjang: `viewConfig: { forceFit: false, scrollOffset: 0 }` (pola `po.js` & `project.invoice.js`). CSS wrap SCOPED ke id grid yang NYATA dari `renderTo` (cek DOM): `#gridEditor .x-grid3-hd-inner, .x-grid3-gridEditor .x-grid3-hd-inner { white-space:normal !important; word-wrap:break-word; ... }`. DILARANG target `#editorFormGrid` bila grid tidak di-render ke id itu (bug berulang di `m.user.system.js`).
### 3b. Halaman non-ExtJS (Tailwind + JS biasa + Chart.js, mis. `views/dashboard.php`)
Ikuti pola file itu sendiri; jangan dikonversi ke ExtJS.
Semua `fetch` lewat satu helper yang melempar error bila respons bukan JSON atau `success:false`, dan errornya ditampilkan di layar. Dilarang `if (!json.success) return;` yang diam-diam.
Escape teks dari DB sebelum dimasukkan ke `innerHTML`.
CSS Tailwind dikompilasi (`assets/css/dashboard.tailwind.css`, CDN dinonaktifkan): pakai class yang sudah ada, atau build ulang CSS-nya.
[v2] Tema & warna: warna HARUS dari token `css/app-theme.css` (SSOT); jangan hex acak di inline style. Jangan pakai class global ber-`background:linear-gradient` (seperti `my-label-GroupLbl`) di komponen kecil; buat class scoped. Label toolbar (mis. "Form Access") = teks saja, tanpa background gradient yang meluber.
[v2] Mobile (halaman `.m.php`): header/hamburger/bottom-nav SERAGAM `dashboard.php`. Link "Dashboard" di bottom-nav HARUS `dashboard.php` (bukan `router?id=X`) agar tidak kena validasi `user.valid.php` yang mencari `form_id`. Halaman mobile TIDAK include `user.valid.php`; cukup cek `$_SESSION['user_id']`.

## 4. STRUKTUR DIREKTORI PROYEK
`ext-3.4.1/` framework ExtJS · `js/` & `js-func/` UI dan fungsi global ExtJS · `views/` halaman utama (JS non-ExtJS bisa inline di sini)
`php-crud/` handler backend (acuan: `purchaseinvoiceCRD.php`) · `php-func/` & `php-class/` helper dan class global
`sql/` migrasi · `tests/` uji E2E · `docs/` dokumentasi · `cron/` · `assets/` CSS/JS lokal
[v2] Aturan file `.md`: semua dokumen di `docs/`. Satu-satunya `.md` yang boleh di root: `AUDIT_CHANGELOG.md`, `CLAUDE.md`, `README.md`, `DEVELOPMENT_GUIDELINE.md`, `BUSINESS_GAP_REPORT.md`, `MEMORY.md`. Laporan sesi (`test_report_*.md`, `*.db.schema.md`, `security_audit_report.md`) HARUS dipindah ke `docs/` — ini tugas kebersihan terpisah.

## 5. VERIFIKASI & PELAPORAN (BERJENJANG)
Tugas Kecil:
1. `php -l` (PHP) atau `node --check` (JS) pada file yang diubah; bila memungkinkan `curl` endpoint yang diubah dan periksa key JSON.
2. `git status -s` untuk daftar file aktual (jangan mengandalkan ingatan).
3. Satu entri ringkas (maksimal 5 baris) di `docs/SESSION_LOG.md`. Tidak perlu dokumen baru, unit-test baru, atau laporan panjang.
Tugas Besar: semua di atas, ditambah:
4. `git diff --stat` dan pembaruan `AUDIT_CHANGELOG.md` (satu-satunya file laporan yang memang berada di root proyek) berupa tabel: tanggal/waktu, path file, ringkasan perubahan dan alasan, status Git (`Committed` / `Uncommitted`).
5. Uji E2E (skill `e2e-test-template`) bila logika transaksi/DB berubah, dijalankan hingga `FAIL=0` terhadap instance yang sama dengan yang dipakai developer.
6. Dokumen `docs/YYYY-MM-DD_<NAMA_AGENT>_<MODUL>.md`: ringkasan, file diubah, SQL/migrasi, langkah verifikasi.
[v2] Verifikasi frontend (WAJIB untuk tugas UI): setelah edit JS/CSS, buka DevTools > Console, pastikan 0 error merah; hard refresh (`Ctrl+Shift+R`); verifikasi visual sesuai checklist perubahan; untuk halaman mobile cek header/hamburger/bottom-nav seragam `dashboard.php`.
Selalu: tampilkan daftar file yang diubah di akhir respons; jangan `git commit` tanpa instruksi eksplisit; jawaban akhir padat dan langsung ke poin.

## 6. STANDAR AUDIT MENDALAM (hanya sesi audit/regresi, bukan bugfix kecil)
Cakupan lintas lapisan: periksa `php-crud/`, `php-func/`, `views/`, `pdf/`, `js/`, `js-func/`, dan file berekstensi huruf besar (`*.PHP`, mis. `db_connection.PHP`). Jangan hanya `php-crud` dan `js`.
Logika bisnis, regex PostgreSQL & SQL dinamis: cari komentar `//` di dalam string SQL multi-baris (memicu error PostgreSQL). Verifikasi penomoran otomatis (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) bebas bug regex titik (`.`) dan transisi bulan. Urutan validasi bisnis (status `released`, sisa tagihan, kepemilikan client) selaras dengan validasi input dasar.
Kesiapan data & master rule: periksa `fiscal_config` (termasuk `PRJ_DEP_APPLY`), `accounting_periods`, distribusi `m_user_access_menu`, dan data pra-migrasi yang sudah berjurnal (cegah double-posting).
Setiap temuan baru pada sesi audit dibuatkan kasus uji di `tests/` dan dijalankan ke DB uji `proyekGMN_test`.

## 7. MIGRASI SQL
File baru: `sql/YYYY-MM-DD.nama_fitur.sql`, wajib diakhiri blok komentar `-- ROLLBACK / DOWN MIGRATION` (perintah `DROP`/`ALTER` kebalikan yang aman).
Agent TIDAK mengeksekusi migrasi/DDL. Developer mengeksekusinya manual setelah review (alur `/bangun-fitur` dan `/audit-dan-tambal` berhenti untuk itu).

## 8. PENDAFTARAN MENU & HAK AKSES (hanya halaman/modul/laporan BARU)
Periksa struktur tabel menu dan hak akses yang berlaku, lalu tulis pendaftaran menu (parent, ikon, urutan, URL/handler JS, hak akses role) di file migrasi `.sql` modul tersebut sebagai `INSERT ... ON CONFLICT DO NOTHING` atau `WHERE NOT EXISTS`, agar ikut terpasang saat deploy ke produksi (`proyekGMN`).
Eksekusi ke DB tetap oleh developer (lihat section 7). Masukkan pengecekan menu ke daftar verifikasi sebelum tugas dinyatakan selesai.
Tidak berlaku untuk perbaikan bug atau perubahan pada layar yang sudah ada.
[v2] Halaman baru yang tidak butuh menu (dashboard, mobile `.m.php`): daftarkan di `m_form_name` dengan `php_url` yang cocok, beri hak akses default ke semua user aktif via `INSERT ... ON CONFLICT DO NOTHING` di `m_user_access_menu`. Ini mencegah error 403 dari `user.valid.php`.

## 9. DOKUMENTASI
Semua dokumen di `docs/` pada root proyek (bukan path absolut lain). Jangan menaruh laporan, panduan, atau changelog baru di root; satu-satunya pengecualian adalah `AUDIT_CHANGELOG.md`.
Tugas Kecil cukup `docs/SESSION_LOG.md`; laporan panjang hanya untuk Tugas Besar atau bila diminta.
Pencarian terarah: `Grep`/`rg` pada modul target (termasuk `views/`), jangan memindai seluruh proyek berulang, kecuali pada sesi audit (section 6).

## 10. NOTIFIKASI WHATSAPP PASCA-TUGAS
Dikirim sekali setiap Tugas Besar selesai (1 Gap, 1 siklus audit, 1 fitur) atau saat sesi berakhir setelah ada perubahan kode. Tidak dikirim untuk sesi tanya-jawab/baca saja.
Nomor tujuan dari env `HERMES_WA_TO` (jangan menulis nomor di file proyek). Jalankan lewat Bash:
`hermes send --to "whatsapp:$HERMES_WA_TO" "<1-3 baris: apa yang dikerjakan, status, commit hash bila ada>"`
Bila env kosong: lewati dan sebutkan itu di ringkasan akhir. Dilarang hanya mencetak teks simulasi tanpa menjalankan perintah.
Bila exit code non-zero: jalankan `hermes gateway restart && sleep 3` lalu ulangi `hermes send` SEKALI. Bila masih gagal, laporkan di ringkasan akhir; jangan mengulang terus.
Jangan menyertakan kredensial atau data sensitif dalam pesan.

## 11. [v2] ATURAN KHUSUS MODUL YANG SERING BUG
Purchase Order (`po.js` / `poCRD.php`): deklarasikan `var actions` dan `var csBanner` di AWAL fungsi `renderDetailPanel()` sebelum dipakai (hoisting tidak cukup bila ada `var` di tengah fungsi yang dipakai sebelum baris deklarasinya — TypeError fatal). Panel detail PO dibuat dinamis via `document.createElement('div')` + `insertBefore`; jangan asumsikan `#poDtlContainer` sudah ada di template.
User Role (`m.user.role.js`): pakai `Ext.grid.EditorGridPanel` (bukan `EditorGridModel`). CheckColumn toggle via `cellclick` di grid, bukan `listeners:{click}` di kolom. Sembunyikan `editorWdw`/`popWdw` di akhir `Ext.onReady` agar tidak menutupi grid.
User System (`m.user.system.js`): CSS wrap grid target `#gridEditor` + `.x-grid3-gridEditor` (bukan `#editorFormGrid`). Toolbar "Form Access" = label kiri + hbox tombol kanan; label TIDAK pakai `my-label-GroupLbl`. `editorDeleter` dengan `header: '<svg>...</svg>'` untuk icon trash.
Stock Card Mobile (`stock.card.m.js`): `window.APP_BASE_URL` di-set dari PHP sebelum load JS. `lookup_warehouses` hanya return gudang yang punya stok (ada di `stock_movements`). `lookup_items` wajib `warehouse_id > 0`.
Dashboard (`dashboard.php` + `dashboardCRD.php`): action name di JS HARUS cocok dengan `case` di CRD (`read_*`, bukan `get_*`). `dashboard.php` tidak include `user.valid.php`; cek session manual. Menu dropdown dikontrol via JS class `.open` + `closeAll()`; jangan pakai `:hover`/`:focus-within` CSS agar tidak bertumpuk. `.nav-item` = `position: relative` agar dropdown menempel di bawah menu, bukan di kiri layar.

## 12. [v2] CHECKLIST SEBELUM MENYATAKAN SELESAI
- `php -l` / `node --check` bersih
- DevTools Console: 0 error merah
- Network tab: semua request JS/CSS status 200 (bukan 404/302)
- Kontrak API: nama action di JS == nama `case` di CRD (cek dengan `rg`)
- Kontrak JSON: reader JS cocok dengan bentuk respons server
- Tidak ada hardcode path (`../dev/`, absolut)
- Tidak ada `var` yang dipakai sebelum dideklarasikan di fungsi yang sama
- CSS wrap grid ditarget ke id/class NYATA dari `renderTo`
- Daftar file yang diubah ditampilkan di akhir respons
