# DEVELOPMENT GUIDELINE & INSTRUCTIONS

## 0. PETA INSTANCE (baca dulu)
- URL dev yang dibuka developer: `http://127.0.0.1/rab-project/dev/`. Server E2E: env `RAB_BASE_URL` (default `http://127.0.0.1:8098`).
- DB uji: `proyekGMN_test`. DB produksi: `proyekGMN`. DB yang benar-benar dipakai aplikasi dibaca dari `php-func/function.all.php`; jangan menebak.
- Folder kerja = hasil `pwd`. Sebelum mengedit pastikan file itu memang yang disajikan URL di atas (cek symlink/docroot). TODO developer: konfirmasi hubungan `/home/myadmin/proyekgmn-dev` dengan `/rab-project/dev`.

## 1. ATURAN UMUM PENGEMBANGAN
- **Cara menulis perubahan:** file BARU ditulis utuh. File EKSISTING diubah dengan edit terarah (diff minimal) dan komentar jelas pada baris yang berubah atau ditambah. Menulis ulang seluruh file eksisting hanya bila file ≤ 300 baris atau diminta eksplisit. Alasannya: tulis-ulang-penuh dari bacaan parsial pernah mengembalikan bagian lama (regresi).
- **Tingkat tugas:** *Kecil* = bugfix/UI, maksimal 3 file, tanpa SQL. *Besar* = audit, fitur/modul baru, migrasi SQL, atau patch lebih dari 3 file. Tingkat ini menentukan beban administrasi (lihat §5).
- **Pemisahan logika:** pisahkan UI (`/js/` atau `/views/`) dari pemrosesan data (`/php-crud/`); modul PO adalah acuan.
- **Kontrak API:** bila mengubah nama `action`, parameter, atau bentuk JSON sebuah endpoint, ubah SEMUA pemanggilnya dalam perubahan yang sama: `rg -n "<nama_action>" views/ js/ js-func/ php-crud/` (JS halaman bisa inline di `views/*.php`). Tugas selesai hanya bila kedua sisi cocok.
- **Halaman kosong / angka 0 / data tidak tampil:** gunakan `/perbaiki-ui` (agent `contract-debugger`). Reproduksi dulu (curl atau DevTools, tab Network), baru baca kode.

## 2. STANDAR BACKEND (PHP & POSTGRESQL)
- **Helper & koneksi tunggal:** di setiap endpoint backend panggil
  `require_once __DIR__ . '/../php-func/function.all.php';` dan
  `require_once __DIR__ . '/../php-class/crudClass.php';`
  (path relatif ke file, bukan ke direktori kerja).
- **PHP 7.4:** dilarang sintaks/fungsi PHP 8+ (`str_starts_with`, `str_contains`, `str_ends_with`, `match`, dll.).
- **Keamanan query:** selalu `pg_query_params` atau PDO `prepare/execute`.
- **Format respons JSON (`/php-crud/*.php`):**
  - Read (CRUD/grid): `{"success": true, "totalCount": 100, "data": [...]}`
  - Mutasi: `{"success": true, "message": "Data berhasil disimpan."}`
  - Gagal: `{"success": false, "message": "Pesan kesalahan spesifik."}`
  - Endpoint agregat read-only (mis. `dashboardCRD.php`) boleh memakai key khusus (`items`, `kpi`, `datasets`, ...) asalkan selalu ada `success`, `message` saat gagal, dan bentuk tiap action dicatat di komentar header file.
- **Error handling:** tangkap `Throwable` (bukan hanya `Exception`) di endpoint. Fatal error harus menghasilkan JSON `{"success": false, "message": ...}` (HTTP 500) plus `error_log`, bukan body kosong.
- **Transaksi ACID:** `BEGIN ... COMMIT / ROLLBACK` untuk data bertingkat (header & detail), `FOR UPDATE` untuk perubahan status dokumen. Dilarang `echo`/`var_dump`/`print_r` untuk debugging; satu-satunya output adalah JSON final. Format tanggal `YYYY-MM-DD`, desimal memakai titik.

## 3. STANDAR FRONTEND
### 3a. Halaman ExtJS 3.4.1 (`js/*.js` dan view yang memuat `Ext.*`)
- Setiap `Ext.data.Store` wajib punya listener `exception` yang menampilkan error eksplisit.
- Null-check `dataRecord` sebelum Edit/Delete; pastikan form ter-render sebelum `.getForm().load()` atau `.setValue()`.
- Payload bertingkat: `Ext.util.JSON.encode` via POST.
### 3b. Halaman non-ExtJS (Tailwind + JS biasa + Chart.js, mis. `views/dashboard.php`)
- Ikuti pola file itu sendiri; jangan dikonversi ke ExtJS.
- Semua `fetch` lewat satu helper yang melempar error bila respons bukan JSON atau `success:false`, dan errornya ditampilkan di layar. Dilarang `if (!json.success) return;` yang diam-diam.
- Escape teks dari DB sebelum dimasukkan ke `innerHTML`.
- CSS Tailwind dikompilasi (`assets/css/dashboard.tailwind.css`, CDN dinonaktifkan): pakai class yang sudah ada, atau build ulang CSS-nya.

## 4. STRUKTUR DIREKTORI PROYEK
- `ext-3.4.1/` framework ExtJS · `js/` & `js-func/` UI dan fungsi global ExtJS · `views/` halaman utama (JS non-ExtJS bisa inline di sini)
- `php-crud/` handler backend (acuan: `purchaseinvoiceCRD.php`) · `php-func/` & `php-class/` helper dan class global
- `sql/` migrasi · `tests/` uji E2E · `docs/` dokumentasi · `cron/` · `assets/` CSS/JS lokal

## 5. VERIFIKASI & PELAPORAN (BERJENJANG)
**Tugas Kecil:**
1. `php -l` (PHP) atau `node --check` (JS) pada file yang diubah; bila memungkinkan `curl` endpoint yang diubah dan periksa key JSON.
2. `git status -s` untuk daftar file aktual (jangan mengandalkan ingatan).
3. Satu entri ringkas (maksimal 5 baris) di `docs/SESSION_LOG.md`. Tidak perlu dokumen baru, unit-test baru, atau laporan panjang.

**Tugas Besar:** semua di atas, ditambah:
4. `git diff --stat` dan pembaruan `AUDIT_CHANGELOG.md` (satu-satunya file laporan yang memang berada di root proyek) berupa tabel: tanggal/waktu, path file, ringkasan perubahan dan alasan, status Git (`Committed`/`Uncommitted`).
5. Uji E2E (skill `e2e-test-template`) bila logika transaksi/DB berubah, dijalankan hingga `FAIL=0` terhadap instance yang sama dengan yang dipakai developer.
6. Dokumen `docs/YYYY-MM-DD_<NAMA_AGENT>_<MODUL>.md`: ringkasan, file diubah, SQL/migrasi, langkah verifikasi.

Selalu: tampilkan daftar file yang diubah di akhir respons; jangan `git commit` tanpa instruksi eksplisit; jawaban akhir padat dan langsung ke poin.

## 6. STANDAR AUDIT MENDALAM (hanya sesi audit/regresi, bukan bugfix kecil)
1. **Cakupan lintas lapisan:** periksa `php-crud/`, `php-func/`, `views/`, `pdf/`, `js/`, `js-func/`, dan file berekstensi huruf besar (`*.PHP`, mis. `db_connection.PHP`). Jangan hanya `php-crud` dan `js`.
2. **Logika bisnis, regex PostgreSQL & SQL dinamis:**
   - Cari komentar `//` di dalam string SQL multi-baris (memicu error PostgreSQL).
   - Verifikasi penomoran otomatis (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) bebas bug regex titik (`.`) dan transisi bulan.
   - Urutan validasi bisnis (status `released`, sisa tagihan, kepemilikan client) selaras dengan validasi input dasar.
3. **Kesiapan data & master rule:** periksa `fiscal_config` (termasuk `PRJ_DEP_APPLY`), `accounting_periods`, distribusi `m_user_access_menu`, dan data pra-migrasi yang sudah berjurnal (cegah double-posting).
4. Setiap temuan baru pada sesi audit dibuatkan kasus uji di `tests/` dan dijalankan ke DB uji `proyekGMN_test`.

## 7. MIGRASI SQL
- File baru: `sql/YYYY-MM-DD.nama_fitur.sql`, wajib diakhiri blok komentar `-- ROLLBACK / DOWN MIGRATION` (perintah `DROP`/`ALTER` kebalikan yang aman).
- Agent TIDAK mengeksekusi migrasi/DDL. Developer mengeksekusinya manual setelah review (alur `/bangun-fitur` dan `/audit-dan-tambal` berhenti untuk itu).

## 8. PENDAFTARAN MENU & HAK AKSES (hanya halaman/modul/laporan BARU)
1. Periksa struktur tabel menu dan hak akses yang berlaku, lalu tulis pendaftaran menu (parent, ikon, urutan, URL/handler JS, hak akses role) di file migrasi `.sql` modul tersebut sebagai `INSERT ... ON CONFLICT DO NOTHING` atau `WHERE NOT EXISTS`, agar ikut terpasang saat deploy ke produksi (`proyekGMN`).
2. Eksekusi ke DB tetap oleh developer (lihat §7). Masukkan pengecekan menu ke daftar verifikasi sebelum tugas dinyatakan selesai.
3. Tidak berlaku untuk perbaikan bug atau perubahan pada layar yang sudah ada.

## 9. DOKUMENTASI
- Semua dokumen di `docs/` pada root proyek (bukan path absolut lain). Jangan menaruh laporan, panduan, atau changelog baru di root; satu-satunya pengecualian adalah `AUDIT_CHANGELOG.md`.
- Tugas Kecil cukup `docs/SESSION_LOG.md`; laporan panjang hanya untuk Tugas Besar atau bila diminta.
- Pencarian terarah: `Grep`/`rg` pada modul target (termasuk `views/`), jangan memindai seluruh proyek berulang, kecuali pada sesi audit (§6).

## 10. NOTIFIKASI WHATSAPP PASCA-TUGAS
Dikirim sekali setiap Tugas Besar selesai (1 Gap, 1 siklus audit, 1 fitur) atau saat sesi berakhir setelah ada perubahan kode. Tidak dikirim untuk sesi tanya-jawab/baca saja.
1. Nomor tujuan dari env `HERMES_WA_TO` (jangan menulis nomor di file proyek). Jalankan lewat Bash:
   `hermes send --to "whatsapp:$HERMES_WA_TO" "<1-3 baris: apa yang dikerjakan, status, commit hash bila ada>"`
   Bila env kosong: lewati dan sebutkan itu di ringkasan akhir.
2. Dilarang hanya mencetak teks simulasi tanpa menjalankan perintah.
3. Bila exit code non-zero: jalankan `hermes gateway restart && sleep 3` lalu ulangi `hermes send` SEKALI. Bila masih gagal, laporkan di ringkasan akhir; jangan mengulang terus.
4. Jangan menyertakan kredensial atau data sensitif dalam pesan.
