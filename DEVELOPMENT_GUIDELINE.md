================================================================================
                     DEVELOPMENT GUIDELINE & INSTRUCTIONS
================================================================================

1. ATURAN UMUM PENGEMBANGAN
--------------------------------------------------------------------------------
* Full File Format:
  Setiap perbaikan atau upgrade kode, baik JavaScript/ExtJS (UI) maupun PHP 
  (Backend), WAJIB disajikan dan dikembalikan dalam bentuk versi FULL FILE LENGKAP.

* Penggunaan Remark/Comment:
  Berikan komentar atau remark yang jelas pada baris kode yang mengalami 
  perubahan, penambahan, atau perbaikan agar mempermudah peninjauan ulang.

* Pemisahan Logika Tampilan & Backend:
  Pisahkan dengan tegas antara logika tampilan/UI (yang berada di direktori /js/
  atau /views/) dan logika pemrosesan data (yang berada di direktori /php-crud/).

--------------------------------------------------------------------------------
2. STANDAR BACKEND (PHP & POSTGRESQL DATABASE)
--------------------------------------------------------------------------------
* File Konfigurasi & Helper Terpusat:
  Gunakan pemanggilan helper/koneksi database tunggal yang konsisten di setiap
  endpoint backend:
	require_once   '../php-func/function.all.php';
	require_once  '../php-class/crudClass.php';
	
* Pengamanan Query (Prepared Statements):
  Selalu gunakan `pg_query_params` untuk setiap query ke PostgreSQL guna 
  mencegah celah keamanan SQL Injection.

* Format Respon JSON Standar:
  Seluruh file handler CRUD di `/php-crud/*.php` WAJIB mengembalikan respon
  bertipe `application/json` secara konsisten.

  - Respon Sukses (Read / Grid Data):
    {
      "success": true,
      "totalCount": 100,
      "data": [ ... ]
    }

  - Respon Sukses (Action / Mutation):
    {
      "success": true,
      "message": "Data berhasil disimpan."
    }

  - Respon Gagal (Error Handling):
    {
      "success": false,
      "message": "Pesan kesalahan spesifik yang jelas bagi pengguna."
    }

--------------------------------------------------------------------------------
3. STANDAR FRONTEND (EXTJS 3.4.1)
--------------------------------------------------------------------------------
* Exception Handling pada Store:
  Setiap `Ext.data.Store` wajib dilengkapi dengan listener `exception` atau
  callback penanganan error agar ketika terjadi kegagalan muat data dari backend,
  sistem menampilkan pesan peringatan secara eksplisit kepada pengguna.

* Validasi Poin Akses & Pemilihan Baris Data:
  - Lakukan null check / verifikasi pada baris data (`dataRecord`) sebelum
    menjalankan operasi Edit atau Delete.
  - Pastikan komponen form telah dirender secara sempurna sebelum mengeksekusi
    `.getForm().load()` atau `.setValue()`.

* Pengiriman Payload Data Bertingkat:
  Untuk data bertingkat (seperti Header & Detail Transaksi), data detail dikirimkan
  menggunakan serialisasi JSON (`Ext.util.JSON.encode`) melalui metode POST.

--------------------------------------------------------------------------------
4. STANDAR TRANSAKSI DATABASE & FORMAT DATA
--------------------------------------------------------------------------------
* Database Transactions (ACID):
  Operasi menyimpan atau memperbarui data bertingkat (seperti Header & Detail
  Transaksi) WAJIB menggunakan transaksi SQL (BEGIN ... COMMIT / ROLLBACK) di PHP
  untuk menjaga integritas data agar tidak tersimpan sebagian saat terjadi error.

* Format Tanggal & Numerik:
  - Format tanggal standar antar-sistem adalah YYYY-MM-DD.
  - Untuk nilai numerik desimal/mata uang, gunakan pemisah titik (.) saat dikirim
    ke backend PHP/PostgreSQL untuk menghindari kesalahan parsing.

* Logging & Debugging Mode:
  - Hindari penggunaan `echo`, `var_dump`, atau `print_r` langsung di PHP pada 
    mode produksi agar tidak merusak struktur response JSON yang dibaca ExtJS.
  - Simpan log kesalahan ke file log terpisah di server.

--------------------------------------------------------------------------------
5. STRUKTUR DIREKTORI PROYEK
--------------------------------------------------------------------------------
├── assets/
├── css/
│   ├── fonts/
│   └── images/
├── ext-3.4.1/             # Framework ExtJS 3.4.1
├── js/
├── js-func/               # Fungsi global & modul UI ExtJS
├── php/
├── php-class/
├── php-crud/              # Handler Backend CRUD (contoh: purchaseinvoiceCRD.php)
├── php-func/              # Helper koneksi & fungsi global PHP
└── views/                 # Halaman tampilan utama

================================================================================

