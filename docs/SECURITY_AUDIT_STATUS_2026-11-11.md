# Audit Keamanan Endpoint CRUD & Hak Akses — Status 11 November 2026

## Status repository

- Repository: `esusanto818/rab-project`
- Branch pekerjaan: **dev**
- HEAD branch `dev` saat dokumen dibuat: `04adc0ea987e449b814a511f3f51cc51117ad7f6`
- HEAD branch `main`: `9aca8baf196e1047de5295aec7517a3fdc971456`
- Seluruh perubahan audit pada tahap ini dikirim hanya ke `dev`. Tidak ada merge atau perubahan ke `main`.

**Status:** patch statis telah diterapkan dan diperiksa ulang melalui GitHub. Audit belum boleh dinyatakan lulus atau siap rilis sebelum PHP lint, uji HTTP, uji database, dan regression test dilakukan di server dev.

## Cakupan pemeriksaan

Pemeriksaan statis mencakup:

1. 57 file endpoint standar `php-crud/*CRD.php`.
2. File nonstandar di folder `php-crud`, termasuk endpoint lama, file koneksi, file backup, dan file patch.
3. `router.php`, `.htaccess`, dan `php-func/user.valid.php`.
4. 74 file PHP di folder `views`, termasuk halaman mobile dan halaman cetak.
5. 12 file PHP di folder `pdf`.
6. Endpoint dashboard, sinkronisasi kurs pajak, analisis AI, dan cron pengambil kurs pajak.

Konvensi hak akses:
- Level 1: baca.
- Level 2: buat/tambah.
- Level 3: ubah/perbarui dan tindakan khusus yang ditetapkan pada level ini.
- Level 4: hapus, reversal/pembatalan sensitif, atau administrasi khusus.

## Perubahan keamanan yang diterapkan

### 1. Pemetaan endpoint ke menu induk

- Sebanyak 56 dari 57 endpoint standar `*CRD.php` kini memanggil `php-func/user.valid.php` sebelum memproses operasi.
- Endpoint tidak lagi hanya menggunakan nilai `FormAccess` yang tersisa dari halaman lain. Helper mencari hak user berdasarkan nama file endpoint yang dipetakan ke `m_form_name.php_url`, lalu mengisi `FormAccess` dari menu induk yang cocok.
- Parameter `menu_id` atau `form_id` dari pemanggil tidak lagi boleh memilih menu lain untuk request endpoint CRUD. Parameter menu tersebut hanya diterima pada jalur legacy `/views/router`.
- Sesi juga divalidasi terhadap `m_user_system.active` pada request terlindungi. Akun yang dinonaktifkan atau sudah tidak ada akan kehilangan sesi dan mendapat HTTP 401.
- Bila terdapat beberapa alias menu yang cocok, helper memilih level akses paling rendah (least privilege).
- Alias khusus ditambahkan untuk:
  - `project.invoiceCRD.php` → `project.invoice.php` / `projectinvoice.php`
  - `projectprogressCRD.php` → `project.progress.php` / `project.progress.m.php`
  - `ppn.backfillCRD.php` → `ppn.report.php`
  - `dashboardQ.php` → `dashboard.php`
  - `rab_input.php` → `rab.php`
  - `stock.card.m.php` → `stock.card.php`
  - halaman cetak pembayaran supplier → `supplier.payment.php`
- `dashboardCRD.php` tetap menjadi pengecualian karena source menetapkan kebijakan dashboard dapat dibaca oleh setiap akun yang sudah login. Seluruh operasi yang mengubah data di dashboard diperiksa secara khusus terhadap menu induk terkait.

**Efek kepada pengguna:** request langsung ke endpoint tetap memerlukan akses ke menu induknya. Hak akses tinggi dari menu lain tidak boleh dipakai untuk menjalankan operasi pada modul berbeda. Pengguna yang tidak memiliki akses menu induk akan mendapat HTTP 403. Jika akun dinonaktifkan admin, sesi yang masih terbuka akan ditolak pada request terlindungi berikutnya dan pengguna diminta login kembali.

### 2. Konsistensi level edit Draft

- `spkCRD.php`: edit SPK Draft dengan ID yang sudah ada memerlukan level 3.
- `subconCRD.php`: edit subkontraktor, SPK Subkon Draft, atau prestasi Draft dengan ID yang sudah ada memerlukan level 3.
- `subcon.invoiceCRD.php`: edit invoice Subkon Draft memerlukan level 3.
- `purchase.returnCRD.php`: edit retur yang sudah ada memerlukan level 3.
- `stock.opnameCRD.php`: edit dokumen opname Draft yang sudah ada memerlukan level 3.
- `invoiceCRD.php`: finalisasi invoice penjualan memerlukan level 3.

**Efek kepada pengguna:** level 2 tetap dapat membuat dokumen baru. Untuk mengubah dokumen Draft yang sudah tersimpan, pengguna harus memiliki level 3. Level 4 tetap dipakai untuk penghapusan atau pembatalan sensitif yang memang ditetapkan demikian.

### 3. Pembatasan AI Task Breakdown dan perubahan RAB

Pada `m.proyekCRD.php`, penyimpanan hasil AI dengan akses level 2 kini dibatasi menjadi penambahan data baru. Jika proses akan:
- menimpa task proyek yang sudah tersimpan,
- menambah kuantitas pada item RAB yang sudah ada, atau
- melengkapi/mengubah rentang harga pada master material/biaya yang sudah ada,

maka proses ditolak dan membutuhkan level 3.

**Efek kepada pengguna:** staf level 2 tetap dapat menyimpan task/material baru dari hasil AI. Perubahan terhadap task/RAB/master yang sudah ada memerlukan hak ubah level 3.

### 4. Finalisasi invoice dan jurnal otomatis

Pada `invoiceCRD.php`:
- finalisasi invoice dan pembuatan jurnal otomatis kini berada dalam satu transaksi database;
- baris invoice dikunci dengan `SELECT ... FOR UPDATE`;
- jika jurnal otomatis gagal, perubahan status invoice di-rollback;
- ID invoice yang tidak valid ditolak.

**Efek kepada pengguna:** jika proses jurnal gagal, invoice tidak lagi tertinggal sebagai final tanpa jurnal. Invoice tetap Draft dan pengguna dapat memperbaiki penyebab kegagalan lalu mencoba kembali.

### 5. Operasi perubahan wajib POST

Pemeriksaan metode POST ditambahkan pada operasi perubahan di endpoint berikut:

- `m.proyekCRD.php`
- `grnCRD.php`
- `whrcvCRD.php`
- `ppn.backfillCRD.php`
- `inventory.reconCRD.php`
- `project.paymentCRD.php`
- `project.invoiceCRD.php`
- `subconCRD.php`
- `subcon.invoiceCRD.php`
- `project.progressCRD.php`
- `project.wipreleaseCRD.php`
- `year.closingCRD.php`
- `m.fiscalperiodCRD.php`
- `material.leftoverCRD.php`
- `fiscalconfigCRD.php`
- `poCRD.php`
- `purchaseinvoiceCRD.php`
- `supplier.paymentCRD.php`
- `settingsCRD.php`
- `balancesheetCRD.php`
- `profitlossCRD.php`
- `invoiceCRD.php`
- `journal.entryCRD.php`
- `dashboardCRD.php`
- `views/dashboardQ.php`

Endpoint lain yang sudah memiliki helper `WhRequirePost()` tetap dipertahankan.

**Efek kepada pengguna:** operasi tulis yang dipanggil melalui GET akan ditolak dengan HTTP 405. UI dashboard diperbarui agar sinkronisasi kurs dan analisis AI baru/ulang mengirim POST.

**Perlindungan CSRF tambahan:** `app_require_same_origin_post()` ditambahkan pada `function.all.php` dan dipanggil oleh router serta `user.valid.php`. Untuk POST, server memeriksa Origin/Referer (host, scheme, port) dan menolak `Sec-Fetch-Site: cross-site`. Request POST tanpa sumber yang dapat diverifikasi juga ditolak.

**Catatan:** pemeriksaan same-origin ini adalah defense-in-depth, bukan synchronizer CSRF token. Tetap lakukan pengujian browser/ExtJS dan evaluasi token CSRF penuh sebelum rilis.

**Efek kepada pengguna:** request POST dari browser yang berasal dari host aplikasi tetap berjalan. Request lintas-origin atau tanpa Origin/Referer yang dapat diverifikasi akan mendapat HTTP 403.

### 6. Dashboard dan akses khusus

- Sinkronisasi kurs pajak di `dashboardCRD.php` dan `views/dashboardQ.php` memerlukan level 3 pada menu Master Kurs Pajak dan request POST.
- Analisis AI baru/ulang di `dashboardCRD.php` memerlukan level 3 pada menu Pembayaran Proyek. Jika belum ada analisis tersimpan, request GET hanya menampilkan instruksi; analisis pertama harus dipicu lewat POST.
- Query akses khusus dashboard menggunakan `MIN(access_level)` untuk menerapkan least privilege bila terdapat lebih dari satu menu cocok.
- UI `dashboard.php` dan `dashboard.latest.php` diperbarui untuk mengirim POST pada sinkronisasi kurs; UI `dashboard.php` juga mengirim POST untuk analisis AI baru/ulang.

**Efek kepada pengguna:** pengguna level baca tetap dapat melihat ringkasan dashboard dan hasil AI tersimpan. Sinkronisasi kurs atau pembuatan/analisis ulang AI memerlukan hak khusus sesuai menu induknya.

### 7. Endpoint legacy dan file sensitif

- `.htaccess` menolak endpoint legacy yang tidak mengikuti pola pemetaan router:
  - `grnCRD_err.php`
  - `grnCRD_err00.php`
  - `whrcvCRD_err.php`
  - `inventory.status.php`
- `.htaccess` menolak file backup, patch, workspace, dump, dan file dokumentasi di folder `php-crud`, serta memblokir artefak berekstensi sensitif di web root.
- Akses HTTP ke direktori `.git`, `database`, `sql`, `docs`, direktori backup, serta symlink `dev`, `live`, dan `proyekgmn` diblokir; directory listing dimatikan.
- `php-func/function.all.php` dan `php-class/crudClass.php` kini memakai konfigurasi environment yang sama (`RAB_DB_HOST`, `RAB_DB_PORT`, `RAB_DB_NAME`, `RAB_DB_USER`, `RAB_DB_PASSWORD`; tersedia fallback nama umum `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`). Tidak ada password database hardcoded di kedua file tersebut.
- `php-crud/db_connection.PHP` tidak lagi menyimpan kredensial database di source; file tersebut menjadi wrapper yang memakai koneksi aplikasi dari `crudClass.php`.
- `cron/fetch_kurs_pajak.php` tidak lagi memiliki fallback credential hardcoded dan menggunakan koneksi aplikasi bersama. Verifikasi TLS untuk koneksi Kemenkeu diaktifkan (`verify_peer`, `verify_peer_name`, dan `CURLOPT_SSL_VERIFYHOST`).
- Detail error koneksi database tidak lagi ditampilkan ke browser; detail teknis dicatat di server log.
- Endpoint PDF kurs pajak tetap dipetakan ke menu Master Kurs Pajak melalui router.

**Efek kepada pengguna:** endpoint legacy yang tidak resmi tidak dapat dipanggil melalui web. Fungsi cetak kurs pajak tetap memakai koneksi aplikasi standar. Server dev wajib menyediakan environment database di Apache/PHP-FPM; jika variabel tidak tersedia dan PostgreSQL tidak mengizinkan password kosong, aplikasi akan menolak koneksi database sampai konfigurasi disiapkan. Sinkronisasi kurs dapat gagal bila server dev tidak memiliki CA certificate yang valid; perbaiki CA bundle, jangan menonaktifkan verifikasi TLS.

**Tindakan wajib:** kredensial database pernah tersimpan di riwayat Git. Menghapusnya dari file terbaru tidak menghapus riwayat commit. Rotasi kredensial database terkait tetap diperlukan.

### 8. Perlindungan halaman view dan cetak yang sebelumnya terbuka

- `views/rab_input.php` kini memerlukan akses level 2 pada menu RAB.
- `views/stock.card.m.php` kini memerlukan hak baca menu Kartu Stok.
- `views/supplier.payment.list.print.php` dan `views/supplier.payment.print.php` kini memerlukan hak baca menu Pembayaran Supplier.
- `views/menudata.php` dan `views/nav_wrapper.php` kini menolak request tanpa sesi login valid.
- Halaman dashboard lama (`dashboard.php`, `dashboard.latest.php`, dan `dashboardV1.php`) juga memvalidasi status akun aktif saat dibuka.
- Bug PHP pada renderer tanda terima supplier yang membandingkan nilai dengan konstanta `undefined` diperbaiki menjadi pemeriksaan null.

**Efek kepada pengguna:** halaman input RAB, kartu stok mobile, dan cetak pembayaran supplier tidak lagi dapat dibuka tanpa hak menu yang sesuai. Cetak pembayaran tetap dapat dilakukan oleh pengguna yang memiliki hak baca menu Pembayaran Supplier.

## File dan area yang diperbarui

- `php-func/user.valid.php`
- `router.php`
- `.htaccess`
- 56 endpoint standar CRUD selain pengecualian dashboard, untuk pemetaan menu induk
- Endpoint transaksi, keuangan, stok, dashboard, dan AI yang tercantum di atas
- `php-crud/db_connection.PHP`
- `cron/fetch_kurs_pajak.php`
- `views/dashboard.php`, `views/dashboard.latest.php`, `views/dashboardQ.php`
- `views/rab_input.php`, `views/stock.card.m.php`
- `views/supplier.payment.list.print.php`, `views/supplier.payment.print.php`
- `views/menudata.php`, `views/nav_wrapper.php`

## Hasil verifikasi statis

- File endpoint standar dan nonstandar di `php-crud` telah diinventarisasi.
- Seluruh endpoint standar selain `dashboardCRD.php` telah diperiksa keberadaan pemanggilan `user.valid.php`.
- Alias endpoint di router dan helper diperiksa ulang.
- Perubahan di branch `dev` dibaca ulang dari GitHub.
- Branch `main` tetap tidak menerima patch audit ini.
- Potongan query dinamis pemetaan endpoint, koneksi environment PostgreSQL, pemeriksaan same-origin, dan pemeriksaan user aktif diuji sintaksnya menggunakan PHP CLI.
- Uji fungsi same-origin lokal: request dengan origin/host sama diterima; request dengan origin berbeda ditolak. Ini belum menggantikan pengujian browser pada server dev.
- Pengujian tersebut bukan pengganti `php -l` untuk seluruh file.
- Workflow `.github/workflows/php-lint.yml` ditambahkan pada branch `dev` untuk menjalankan `php -l` pada seluruh file PHP, termasuk ekstensi `.PHP` huruf besar.
- Belum ada hasil run/status GitHub Actions yang dapat diverifikasi untuk workflow tersebut; full lint masih belum dinyatakan lulus.

## Pengujian yang masih wajib sebelum audit dapat dinyatakan selesai

1. Jalankan `php -l` untuk seluruh file PHP yang berubah pada server lokal dev.
2. Jalankan pengujian HTTP dengan akun level 1, 2, 3, dan 4 untuk setiap modul yang berubah.
3. Pastikan pemetaan `php_url` dalam database dev cocok dengan nama menu dan alias endpoint, khususnya `invoiceCRD.php`, `project.invoiceCRD.php`, `projectprogressCRD.php`, `ppn.backfillCRD.php`, dan halaman cetak/mobile.
4. Uji request langsung ke endpoint tanpa login (harus 401), tanpa akses menu induk (403), level operasi kurang (403), dan metode GET untuk operasi tulis (405).
5. Uji regresi alur GRN → WH Receiving → stok/jurnal; PO; invoice supplier; pembayaran supplier; invoice proyek; pembayaran proyek; SPK internal/subkon; progres/inspeksi; sisa material; opname; transfer; issuing; retur; tutup buku; WIP release; dan dashboard.
6. Uji finalisasi invoice: jika posting jurnal gagal, invoice harus tetap Draft dan tidak ada jurnal parsial.
7. Uji AI Task Breakdown level 2: task baru boleh disimpan, tetapi task/RAB/master lama tidak boleh tertimpa atau berubah.
8. Uji halaman cetak supplier, mobile stock card, halaman input RAB, dan halaman dashboard.
9. Uji pemeriksaan same-origin pada browser/ExtJS dan evaluasi kebutuhan token CSRF penuh; helper same-origin sudah dipasang tetapi belum diuji di server dev.
10. Uji IDOR/object-level authorization dengan ID proyek, PO, GRN, SPK, invoice, pembayaran, gudang, dan record milik entitas lain.
11. Audit dan tetapkan atribut cookie sesi `Secure`, `HttpOnly`, dan `SameSite` secara konsisten pada seluruh entry point yang memanggil `session_start()`.
12. Set environment database pada server dev sebelum pengujian: `RAB_DB_HOST`, `RAB_DB_PORT`, `RAB_DB_NAME`, `RAB_DB_USER`, dan `RAB_DB_PASSWORD` (atau nama umum `DB_*`). Jika password tidak disediakan, kode memakai password kosong sehingga hanya cocok untuk PostgreSQL yang mengizinkan trust/peer auth.
13. Rotasi kredensial database yang pernah ada di riwayat Git; periksa juga akses repo dan riwayat commit sesuai kebijakan organisasi.

## Keputusan rilis

**Belum siap merge/publish.** Patch statis sudah diterapkan pada `dev`, tetapi PHP lint seluruh file, pengujian database/HTTP, CSRF, IDOR, dan regression test belum dijalankan. Jangan merge atau mengubah `main` sebelum seluruh tes di atas lulus dan hasilnya dicatat.
