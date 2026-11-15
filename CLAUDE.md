# DEVELOPMENT GUIDELINE & INSTRUCTIONS

## 1. ATURAN UMUM PENGEMBANGAN
- **Full File Format:** Setiap perbaikan atau upgrade kode (JavaScript/ExtJS maupun PHP) WAJIB dikembalikan dalam bentuk versi FULL FILE LENGKAP.
- **Remark/Comment:** Berikan komentar yang jelas pada baris kode yang berubah atau ditambah.
- **Pemisahan Logika:** Pisahkan tegas antara logika UI (`/js/` atau `/views/`) dan pemrosesan data (`/php-crud/`), gunakan modul PO sebagai acuan pembagian.

## 2. STANDAR BACKEND (PHP & POSTGRESQL)
- **Helper & Koneksi Tunggal:** Wajib panggil di setiap endpoint backend:
  `require_once '../php-func/function.all.php';`
  `require_once '../php-class/crudClass.php';`
- **Keamanan Query:** Selalu gunakan `pg_query_params` untuk mencegah SQL Injection.
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

## Aturan Wajib Pelaporan & Audit File
Setiap kali Anda selesai melakukan audit, perbaikan bug, atau perubahan kode pada sesi mana pun, Anda **WAJIB** melakukan dua hal berikut sebelum mengakhiri respons:
1. Jalankan perintah `git status -s` dan `git diff --stat` untuk memverifikasi daftar file aktual di disk lokal (jangan mengandalkan ingatan).
2. Tulis atau perbarui file log **`AUDIT_CHANGELOG.md`** di root proyek dengan format tabel Markdown berisi:
   - Tanggal & Waktu sesi
   - Path file lengkap yang diubah/dibuat
   - Ringkasan perubahan (baris/fungsi) & alasan perbaikan
   - Status Git (`Committed` atau `Uncommitted`)
3. Tampilkan daftar file tersebut secara eksplisit di akhir pesan terminal.

## Standar Audit Mendalam (Setara Audit Manual)
Setiap sesi audit otomatis atau pengujian regresi **WAJIB** mematuhi aturan berikut:
1. **Cakupan File Lintas Lapisan:** Jangan membatasi pemindaian pada `php-crud/*.php` dan `js/*.js`. Wajib ikut memeriksa `php-func/*.php`, `views/*.php`, `pdf/*.php`, `js-func/*.js`, dan file berekstensi huruf besar seperti `*.PHP` (misalnya `db_connection.PHP`).
2. **Audit Logika Bisnis & Sintaks SQL Dinamis:**
   - Periksa komentar `//` di dalam string query SQL multi-baris yang dapat memicu error PostgreSQL.
   - Verifikasi parsing nomor seri dokumen (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) pada batas transisi bulan dan angka besar.
   - Pastikan urutan validasi bisnis (cek status `released`, sisa tagihan, kepemilikan client) dieksekusi sebelum atau selaras dengan validasi input dasar.
3. **Verifikasi via Eksekusi Nyata (Bukan Hanya Static Scan):** Setiap temuan logika bisnis atau celah akses wajib dibuatkan kasus uji otomatis di `tests/` dan dijalankan terhadap database uji `proyekGMN_test`.

## Standar Audit Mendalam (Setara Audit Manual)
Setiap sesi audit otomatis atau pengujian regresi **WAJIB** mematuhi aturan berikut:
1. **Cakupan Lintas Lapisan:** Jangan membatasi pemindaian pada `php-crud/*.php` dan `js/*.js`. Wajib ikut memeriksa `php-func/*.php`, `views/*.php`, `pdf/*.php`, `js-func/*.js`, dan file berekstensi huruf besar seperti `*.PHP` (misalnya `db_connection.PHP`).
2. **Audit Logika Bisnis, Regex PostgreSQL & SQL Dinamis:**
   - Periksa komentar `//` di dalam string query SQL multi-baris yang dapat memicu error PostgreSQL.
   - Verifikasi fungsi penomoran otomatis (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) agar bebas dari bug regex karakter titik (`.`) atau transisi bulan.
   - Pastikan urutan validasi bisnis (cek status `released`, sisa tagihan, kepemilikan client) dieksekusi selaras dengan validasi input dasar.
3. **Verifikasi via Eksekusi Nyata & Data Aktual:** Setiap temuan logika bisnis atau celah akses wajib diverifikasi menggunakan database uji `proyekGMN_test` dan ditambahkan ke suite pengujian otomatis di `tests/`.

## Standar Audit Mendalam (Setara Audit Manual & Data Readiness)
Setiap sesi audit otomatis atau pengujian regresi **WAJIB** menggabungkan audit kode statis dan audit kesiapan data aktual:
1. **Cakupan Kode Lintas Lapisan:** Jangan membatasi pemindaian pada `php-crud/*.php` dan `js/*.js`. Wajib ikut memeriksa `php-func/*.php`, `views/*.php`, `pdf/*.php`, `js-func/*.js`, dan file berekstensi huruf besar seperti `*.PHP` (`db_connection.PHP`).
2. **Audit Logika Bisnis, Regex PostgreSQL & SQL Dinamis:**
   - Periksa komentar `//` di dalam string query SQL multi-baris yang dapat memicu error PostgreSQL.
   - Verifikasi fungsi penomoran otomatis (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) agar bebas dari bug regex karakter titik (`.`) atau transisi bulan.
3. **Audit Kesiapan Data & Master Rule (`Data Readiness`):**
   - Periksa ketersediaan master `fiscal_config` (termasuk `PRJ_DEP_APPLY`), status `accounting_periods`, distribusi `m_user_access_menu`, dan data pra-migrasi yang sudah berjurnal agar tidak terjadi *double-posting*.
   - Setiap temuan baru wajib dibuatkan kasus uji otomatis di `tests/` dan dijalankan terhadap `proyekGMN_test`.
