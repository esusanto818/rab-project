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
