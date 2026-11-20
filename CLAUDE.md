# DEVELOPMENT GUIDELINE & INSTRUCTIONS

## 1. ATURAN UMUM PENGEMBANGAN
- **Full File Format:** Setiap perbaikan atau upgrade kode (JavaScript/ExtJS maupun PHP) WAJIB dikembalikan dalam bentuk versi FULL FILE LENGKAP.
- **Remark/Comment:** Berikan komentar yang jelas pada baris kode yang berubah atau ditambah.
- **Pemisahan Logika:** Pisahkan tegas antara logika UI (`/js/` atau `/views/`) dan pemrosesan data (`/php-crud/`), gunakan modul PO sebagai acuan pembagian.

## 2. STANDAR BACKEND (PHP & POSTGRESQL)
- **Helper & Koneksi Tunggal:** Wajib panggil di setiap endpoint backend:
  `require_once '../php-func/function.all.php';`
  `require_once '../php-class/crudClass.php';`
- **Kompatibilitas PHP 7.4:** Dilarang menggunakan sintaks atau fungsi PHP 8+ (`str_starts_with`, `str_contains`, `str_ends_with`).
- **Keamanan Query:** Selalu gunakan `pg_query_params` atau PDO `prepare/execute` untuk mencegah SQL Injection.
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

## 5. ATURAN WAJIB PELAPORAN & AUDIT FILE
Setiap kali selesai melakukan audit, perbaikan bug, atau perubahan kode pada sesi mana pun, Anda **WAJIB** melakukan hal berikut sebelum mengakhiri respons:
1. Jalankan perintah `git status -s` dan `git diff --stat` untuk memverifikasi daftar file aktual di disk lokal (jangan mengandalkan ingatan).
2. Tulis atau perbarui file log **`AUDIT_CHANGELOG.md`** di root proyek dengan format tabel Markdown berisi:
   - Tanggal & Waktu sesi
   - Path file lengkap yang diubah/dibuat
   - Ringkasan perubahan (baris/fungsi) & alasan perbaikan
   - Status Git (`Committed` atau `Uncommitted`)
3. Tampilkan daftar file tersebut secara eksplisit di akhir pesan terminal.

## 6. STANDAR AUDIT MENDALAM (SETARA AUDIT MANUAL & DATA READINESS)
Setiap sesi audit otomatis atau pengujian regresi **WAJIB** menggabungkan audit kode statis dan audit kesiapan data aktual:
1. **Cakupan Kode Lintas Lapisan:** Jangan membatasi pemindaian pada `php-crud/*.php` dan `js/*.js`. Wajib ikut memeriksa `php-func/*.php`, `views/*.php`, `pdf/*.php`, `js-func/*.js`, dan file berekstensi huruf besar seperti `*.PHP` (`db_connection.PHP`).
2. **Audit Logika Bisnis, Regex PostgreSQL & SQL Dinamis:**
   - Periksa komentar `//` di dalam string query SQL multi-baris yang dapat memicu error PostgreSQL.
   - Verifikasi fungsi penomoran otomatis (`CreateRefNo`, `ti_next_release_no`, urutan `TAG-`/`INV-`/`PPAY-`) agar bebas dari bug regex karakter titik (`.`) atau transisi bulan.
   - Pastikan urutan validasi bisnis (cek status `released`, sisa tagihan, kepemilikan client) dieksekusi selaras dengan validasi input dasar.
3. **Audit Kesiapan Data & Master Rule (`Data Readiness`):**
   - Periksa ketersediaan master `fiscal_config` (termasuk `PRJ_DEP_APPLY`), status `accounting_periods`, distribusi `m_user_access_menu`, dan data pra-migrasi yang sudah berjurnal agar tidak terjadi *double-posting*.
   - Setiap temuan baru wajib dibuatkan kasus uji otomatis di `tests/` dan dijalankan terhadap database uji `proyekGMN_test`.

## 5. STANDAR MIGRASI SQL REVERSIBEL
- Setiap file migrasi baru di folder `sql/YYYY-MM-DD.nama_fitur.sql` WAJIB menyertakan blok komentar `-- ROLLBACK / DOWN MIGRATION` di bagian paling bawah file (berisi perintah `DROP` / `ALTER` kebalikan yang aman) untuk mempercepat pemulihan saat pengujian.

## Aturan Wajib Dokumentasi Pasca-Tugas (Berlaku untuk Semua Agent & Sesi)
- Setiap agent atau sub-agent yang selesai menjalankan tugas **WAJIB** membuat dokumentasi ringkasan hasil kerja dalam format Markdown (`.md`).
- Lokasi penyimpanan wajib berada di **`/home/myadmin/proyekgmn-dev/docs/`**.
- Jangan pernah menaruh file laporan audit, panduan, atau changelog baru di root `/home/myadmin/proyekgmn-dev/`.

## Aturan Kecepatan Eksekusi Agen (Fast Mode)
- **Single-File Logging:** Cukup catat ringkasan perubahan ke `docs/SESSION_LOG.md` (maksimal 5 baris per sesi). Jangan menulis ulang laporan panjang kecuali diminta eksplisit oleh user.
- **Targeted Search:** Gunakan `Grep` langsung pada nama file/modul target; hindari memindai seluruh direktori proyek secara berulang.
- **Minimal Verification:** Cukup jalankan `node --check` (untuk JS) atau `php -l` (untuk PHP) pada file yang diubah; jangan membuat file unit-test baru kecuali diminta.
- **Concise Output:** Berikan jawaban dan ringkasan akhir secara padat dan langsung ke poin utama.

## Aturan Wajib Pendaftaran Menu & Hak Akses (App/Modul Baru)
Setiap kali agen membuat halaman, modul, atau laporan baru (`views/`, `js/`):
1. **Wajib Daftar ke Tabel Menu & Permission DB:** Periksa struktur tabel menu dan role/hak akses yang berlaku di proyek ini, lalu otomatis masukkan (*INSERT*) entri menu baru (lengkap dengan *parent menu*, ikon, urutan, URL/handler JS, dan hak akses role) ke database target (`proyekGMN_test`).
2. **Sertakan di File Migrasi SQL:** Tulis query `INSERT ... ON CONFLICT DO NOTHING` (atau pengecekan `WHERE NOT EXISTS`) untuk pendaftaran menu dan hak akses tersebut di dalam file migrasi `.sql` modul terkait agar otomatis ikut terpasang saat deploy ke database produksi (`proyekGMN`).
3. **Verifikasi Menu pada Fase Audit:** Pastikan query pengecekan menu masuk ke dalam daftar verifikasi sebelum tugas dinyatakan selesai.

## 9. ATURAN WAJIB NOTIFIKASI WHATSAPP PASCA-TUGAS
Setiap kali selesai menyelesaikan 1 Gap, 1 siklus audit, 1 fitur, atau sebelum mengakhiri sesi:
1. Wajib eksekusi tool Bash untuk menjalankan perintah asli berikut:
   `hermes send --to whatsapp:6281231788177 "Ringkasan hasil pengerjaan, status, dan commit hash"`
2. DILARANG KERAS hanya mencetak teks simulasi di layar tanpa mengeksekusi `hermes send`.
3. Jika perintah `hermes send` mengembalikan exit code non-zero (gateway mati), jalankan `hermes gateway restart && sleep 3` lalu ulangi perintah `hermes send` tersebut.
