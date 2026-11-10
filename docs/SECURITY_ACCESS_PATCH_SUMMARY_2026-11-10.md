# Rangkuman Patch Audit Hak Akses — 10 November 2026

## Ruang lingkup
Patch dibuat langsung pada branch **dev** repository `esusanto818/rab-project`. Branch `main` tidak diubah, tidak di-merge, dan tidak dipublikasikan. Pemeriksaan ini merupakan tahap audit terarah pada login/session dan beberapa endpoint CRUD yang dibaca; belum merupakan sertifikasi bahwa seluruh endpoint telah lolos uji keamanan.

## Temuan dan perubahan

### 1. Login alternatif `index.org.php`
- Temuan: setelah login, kode menetapkan `$_SESSION['FormAccess'] = 3` tanpa memeriksa hak akses menu pengguna. Nilai sesi ini dapat terbawa sebagai hak modul yang tidak sesuai.
- Patch: menghapus nilai tetap tersebut; meregenerasi session ID saat login; menghapus konteks menu lama (`FormAccess`, `form_id`, `current_menu_id`). Router/validasi modul harus menetapkan hak akses berdasarkan `m_user_access_menu`.
- Efek bagi pengguna: hak akses tidak lagi otomatis dianggap level 3 setelah login melalui halaman alternatif. Menu/modul yang dibuka akan memakai hak yang terdaftar untuk akun tersebut.

### 2. Login `loginV1.php`
- Temuan: login mengisi `FormAccess` dari `form_access` pada data user, bukan hak akses untuk menu yang sedang dibuka.
- Patch: meregenerasi session ID sebelum menyimpan identitas login dan menghapus nilai/konteks akses menu lama. Nilai akses modul diisi kembali oleh router sesuai menu.
- Efek bagi pengguna: perpindahan modul tidak seharusnya mewarisi level akses global dari proses login; hak menu ditentukan ketika modul dibuka.

### 3. CRUD Master Klien `php-crud/clientsCRD.php`
- Patch: operasi update diselaraskan menjadi minimal level 2; delete minimal level 3.
- Efek bagi pengguna: pengguna level 2 dapat mengubah data klien; penghapusan membutuhkan level 3. Pemeriksaan tetap dilakukan di backend.

### 4. CRUD Master Material `php-crud/m.materialsCRD.php`
- Patch: update minimal level 2 dan delete minimal level 3.
- Efek bagi pengguna: aturan update/delete konsisten dengan kebijakan umum; request langsung ke endpoint tetap melewati pemeriksaan backend.

### 5. CRUD Chart of Accounts `php-crud/m.coaCRD.php`
- Patch: update minimal level 2 dan delete minimal level 3.
- Efek bagi pengguna: level 2 dapat melakukan perubahan biasa; penghapusan memerlukan level 3.

## Konvensi level yang diterapkan
- Level 1: baca.
- Level 2: buat dan ubah.
- Level 3: hapus dan tindakan khusus yang kebijakannya memang ditetapkan pada level tersebut.
- Level 4: tetap dipertahankan untuk tindakan sensitif tertentu seperti pembatalan/reversal finansial atau administrasi khusus. Patch ini tidak menurunkan semua tindakan level 4 secara massal.

## Bagian yang sengaja tidak diubah
- `router.php` dan pengecualian `dashboardCRD.php): dashboard memiliki aturan akses khusus per aksi; perlu audit seluruh aksi dan pemetaan menu sebelum perubahan.
- Endpoint pembayaran, PO, SPK, gudang, opname, dan subkon: tindakan seperti posting, approval, reversal, pembatalan, atau penghapusan transaksi dapat memiliki konsekuensi finansial/stok. Level tinggi yang sudah ada tidak diturunkan tanpa verifikasi kebijakan per aksi.
- `php-func/user.valid.php`: fallback pemetaan menu lama dan penentuan konteks dari parameter/session perlu pengujian integrasi router sebelum disederhanakan.

## Pengujian dan status
- File yang diubah telah dikirim ke branch `dev) melalui GitHub Contents API.
- Isi file setelah patch perlu diverifikasi ulang dengan fetch GitHub dan dibandingkan diff.
- Belum menjalankan PHP lint, pengujian database, atau pengujian HTTP terautentikasi dari lingkungan aplikasi. Karena itu patch **belum dinyatakan lulus uji runtime**.
- Pengujian wajib sebelum dipakai: login melalui kedua jalur; akses modul dengan level 1/2/3/4; uji langsung endpoint CRUD (tanpa UI); pastikan request tanpa login mendapat 401; pastikan akses di bawah ambang mendapat 403; dan pastikan operasi sah tetap berjalan.
- Setelah file di-deploy ke lingkungan dev lokal, jalankan `php -l` pada setiap file yang diubah dan uji browser/AJAX. Jangan deploy ke production.

## File yang diubah
- `index.org.php`
- `loginV1.php`
- `php-crud/clientsCRD.php`
- `php-crud/m.materialsCRD.php`
- `php-crud/m.coaCRD.php`

## Catatan rilis
Patch ini hanya berada di branch `dev`. Jangan merge ke `main` sebelum audit endpoint yang tersisa, pengujian runtime, dan validasi hak akses per menu selesai.


## Patch lanjutan — audit endpoint master data (branch dev)

Pada tahap audit berikutnya, delapan endpoint master data ditemukan memakai level update 3 dan delete 4. Ambang tersebut diselaraskan ke level update 2 dan delete 3:
- `php-crud/m.locationCRD.php`
- `php-crud/m.umCRD.php`
- `php-crud/m.colorCRD.php`
- `php-crud/m.currencyCRD.php`
- `php-crud/m.customerCRD.php`
- `php-crud/m.departmentCRD.php`
- `php-crud/m.employeeCRD.php`
- `php-crud/m.warehouseCRD.php`

Endpoint `php-crud/m.expensesCRD.php` juga diselaraskan: operasi `save` (create maupun update) memerlukan level 2, sedangkan soft-delete memerlukan level 3. Endpoint ini memanggil `user.valid.php` untuk login dan pemetaan hak menu sebelum memproses task.

### Efek pengguna dari patch lanjutan
- Pengguna dengan level 2 dapat menambah dan mengubah data master tersebut.
- Pengguna dengan level 3 dapat menghapus/nonaktifkan data master.
- Level 1 tetap hanya dapat membaca.
- Pengelolaan menu, grup menu, hak akses pengguna, konfigurasi fiskal, dan tindakan keuangan/stock khusus tetap menggunakan ambang lebih tinggi karena merupakan operasi administratif atau berdampak pada pembukuan/persediaan.

### Catatan audit router
- `.htaccess` mengarahkan endpoint `php-crud/*.php` ke `router.php`.
- Router memeriksa login dan mencocokkan endpoint CRUD dengan nama halaman induk di `m_form_name` serta hak pengguna pada `m_user_access_menu`; setelah itu endpoint tetap wajib memeriksa level operasi.
- `dashboardCRD.php` merupakan pengecualian pemetaan menu. File tersebut mewajibkan login; operasi sensitif yang diperiksa pada bagian yang dibaca melakukan pemeriksaan hak menu khusus dari database. Pengecualian ini perlu dipertahankan hanya selama seluruh aksi tulis dashboard telah diaudit.
- `m.form.groupCRD.php`, `m.form.nameCRD.php`, dan perubahan hak pada `m.user.systemCRD.php` tetap dibatasi level 4 sebagai administrasi akses.

### Status audit setelah patch lanjutan
Patch pada file-file di atas sudah dikirim ke branch `dev`. Belum dilakukan PHP lint dan uji HTTP/database runtime. Audit belum selesai: setiap task di endpoint transaksi/keuangan dan endpoint lama/alternatif masih harus ditinjau per aksi, termasuk verifikasi bahwa file legacy tidak menjadi jalur akses yang tidak terpetakan.


## Patch lanjutan — konsistensi transaksi dan metode HTTP

### Penyesuaian hak edit Draft
- `php-crud/poCRD.php`: simpan PO baru maupun edit PO Draft kini memerlukan level 2. Perubahan status tetap level 3 dan hapus PO tetap level 4.
- `php-crud/grnCRD.php`: create dan update GRN kini memerlukan level 2. Hapus GRN tetap level 4.
- `php-crud/subconCRD.php`: penyimpanan data subkontraktor, SPK Subkon, dan prestasi (baru maupun edit Draft) kini memerlukan level 2. Perubahan status/approval tetap level 3; pembatalan/penghapusan tetap level 4.

Alasan: membuat dan mengubah dokumen yang masih Draft merupakan operasi tulis biasa; level 3/4 dipertahankan untuk perubahan status, persetujuan, pembatalan, dan penghapusan yang lebih sensitif.

### Pengamanan backfill jurnal retur supplier
- `php-crud/warehouse.issuingCRD.php`: fungsi `backfill_return_journal` sebelumnya dapat dipanggil melalui GET. Fungsi tersebut mengubah jurnal walaupun idempotent.
- Patch: menambahkan `WhRequirePost()` sebelum proses backfill. Hak akses level 4 yang sudah diperiksa di awal endpoint tetap berlaku.
- Efek pengguna: proses backfill jurnal hanya dapat dijalankan melalui request POST yang sah, bukan sekadar membuka tautan GET atau pemanggilan otomatis browser.

### Hasil pembacaan router dan endpoint legacy
- `.htaccess` mengarahkan `php-crud/*.php` dan `pdf/*.php` ke `router.php` sebelum aturan file fisik, sehingga endpoint harus melewati autentikasi dan pemetaan menu router.
- Router hanya memetakan nama endpoint CRUD dengan pola `*CRD.php` ke menu induk. File alternatif seperti `grnCRD_err.php` dan `grnCRD_err00.php` tidak cocok dengan pola tersebut dan tidak memperoleh otorisasi endpoint dari router; jangan gunakan file alternatif itu sebagai endpoint aktif.
- `views/dashboard.php` diizinkan diakses langsung oleh aturan khusus .htaccess, tetapi file tersebut tetap memeriksa session login. Menu lain perlu tetap menggunakan router atau validasi menu yang setara.
- `dashboardCRD.php` adalah pengecualian router untuk pembacaan ringkasan setelah login. Aksi sinkronisasi kurs pajak dan analisis ulang AI memeriksa hak khusus langsung dari tabel `m_user_access_menu`, bukan memakai `FormAccess` dari menu terakhir.

## Status pemeriksaan statis dan batasan
- File transaksi yang ditinjau secara rinci: PO, GRN, WH Receiving, Warehouse Issuing, Pembayaran Proyek, SPK internal, Subkon, Invoice Subkon, dan Stock Opname.
- Pemeriksaan menemukan dan memperbaiki ambang edit Draft yang terlalu tinggi pada PO, GRN, dan Subkon, serta metode GET untuk backfill jurnal retur.
- Hak level 4 pada penghapusan PO/GRN, reverse transaksi gudang, pembatalan pembayaran, pembatalan subkon, dan pembatalan opname sengaja dipertahankan.
- Pemeriksaan ini belum menggantikan pengujian runtime. Belum dilakukan PHP lint terhadap seluruh file, simulasi HTTP 401/403, uji CSRF dengan token/session browser, maupun pengujian transaksi database.
- Belum boleh menyatakan seluruh sistem aman atau audit selesai sebelum pengujian lokal dev dijalankan, terutama pengujian langsung tiap task endpoint dengan akun level 1/2/3/4.
