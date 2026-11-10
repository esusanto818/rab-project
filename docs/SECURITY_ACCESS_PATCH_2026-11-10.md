# Patch Audit Hak Akses CRUD — 10 November 2026

## Status dan ruang lingkup

Dokumen ini mencatat patch keamanan yang telah diterapkan langsung pada branch **dev** repository `esusanto818/rab-project`. Patch tidak diterapkan ke branch `main`, tidak membuat merge, dan tidak mengubah production.

HEAD branch dev sebelum patch audit: `f6d3286197c9033ac347756ed4c3d2ddca007966`.

Kebijakan level hak akses yang dipakai:
- Level 1: baca.
- Level 2: buat/tambah.
- Level 3: ubah/perbarui.
- Level 4: hapus atau pembalikan/pembatalan yang bersifat destruktif.

## Daftar patch

### 1. `php-crud/bin.locationCRD.php`
- Validasi sesi diperketat: `user_id` harus numerik dan lebih besar dari nol.
- Operasi update dinaikkan dari level 2 menjadi level 3.
- Operasi delete dinaikkan dari level 3 menjadi level 4.
- Pembacaan tetap mensyaratkan level 1 dan pembuatan level 2.

**Efek ke pengguna:** pengguna yang hanya memiliki hak tambah (level 2) tidak lagi dapat mengubah lokasi rak; pengguna level 3 dapat mengubah tetapi tidak menghapus; penghapusan hanya dapat dilakukan level 4. Pengguna yang tidak memiliki sesi valid ditolak.

### 2. `php-crud/purchase.orderCRD.php`
- Task `update/edit` mensyaratkan level 3 (sebelumnya level 2).
- Update detail PO kini mensyaratkan `po_dtl.id` sekaligus `po_hdr_id = PO yang sedang diedit`.
- Update biaya PO kini mensyaratkan `po_costs.id` sekaligus `po_id = PO yang sedang diedit`.

**Efek ke pengguna:** pengguna level 2 masih dapat membuat PO tetapi tidak mengubah PO yang sudah ada. Pengguna level 3 dapat mengubah PO. Form atau request yang mengirim ID detail/biaya milik PO lain tidak lagi dapat mengubah baris tersebut melalui endpoint ini.

### 3. `php-crud/inventory.status.php`
- Offset pagination dipaksa menjadi integer non-negatif.
- Parameter pencarian diberi nilai default kosong bila tidak dikirim.
- Parameter `item_id`, `grade_id`, dan `wood_id` pada `readDetail` divalidasi sebagai integer dengan rentang non-negatif (item harus positif).
- SQL memakai nilai integer yang telah divalidasi, bukan menggabungkan nilai POST mentah.
- Task yang tidak dikenal mendapat respons HTTP 400.

**Efek ke pengguna:** permintaan detail persediaan dengan ID kosong, non-numerik, negatif, atau tidak valid akan ditolak dengan HTTP 400. Permintaan pencarian tanpa kata kunci tetap dapat diproses. Ini mengurangi risiko manipulasi SQL melalui parameter detail.

### 4. `php-crud/clientsCRD.php`
- Update master klien dinaikkan dari level 2 menjadi level 3.
- Penolakan hak akses update mengembalikan HTTP 403.

**Efek ke pengguna:** level 2 hanya dapat menambah klien; perubahan data klien memerlukan level 3.

### 5. `php-crud/spkCRD.php`
- Hak buat/ubah SPK tetap level 2; terbitkan/tutup/batalkan tetap level 3.
- Penghapusan SPK dipisahkan ke konstanta level 4.

**Efek ke pengguna:** pengguna level 3 tetap dapat menjalankan alur tindakan SPK yang ditetapkan, tetapi tidak dapat menghapus SPK; hapus memerlukan level 4.

### 6. `php-crud/project.wipreleaseCRD.php`
- Hak simpan pelepasan WIP tetap level 2.
- Hak hapus/reversal pelepasan WIP dinaikkan dari level 3 menjadi level 4.
- Komentar kebijakan akses diselaraskan.

**Efek ke pengguna:** pengguna level 3 tidak dapat menghapus/membalikkan transaksi pelepasan WIP; reversal memerlukan level 4.

## Pemeriksaan setelah patch

- Seluruh enam file di atas berhasil diperbarui pada branch `dev`.
- Nilai level akses dan klausa kepemilikan detail PO dibaca ulang dari GitHub setelah perubahan.
- Branch `main` tetap tidak disentuh.
- Patch belum dianggap lulus uji runtime: perlu pengujian login, role level 1–4, operasi CRUD, serta request manipulasi ID di lingkungan dev dengan data uji.
- Pemeriksaan otomatis PHP lint dan uji integrasi database belum dijalankan dari server aplikasi.

## Temuan yang masih perlu dituntaskan sebelum dinyatakan selesai

1. Inventaris awal seluruh 62 endpoint PHP di `php-crud/` telah dilakukan. Audit mendalam per operasi dan pemeriksaan object-level authorization untuk modul-modul selain kasus PO yang sudah diperbaiki masih perlu diselesaikan.
2. `inventory.status.php` bukan nama standar berakhiran `CRD.php`, sehingga tidak otomatis cocok dengan pemetaan endpoint standar di router. Perlu ditelusuri apakah endpoint ini masih dipakai; jangan menambahkan alias tanpa memastikan menu induknya.
3. `router.php` memiliki pengecualian otorisasi menu untuk `dashboardCRD.php`. File tersebut memeriksa login dan memiliki pemeriksaan hak khusus untuk sinkronisasi kurs pajak, tetapi semua action dashboard perlu dipastikan hanya baca atau memiliki pemeriksaan khusus yang sesuai.
4. `.htaccess` sementara mengizinkan akses fisik file view. View utama yang diperiksa, `views/dashboard.php`, memeriksa sesi login; audit perlu memastikan seluruh view yang dapat diakses langsung melakukan pemeriksaan yang tepat.
5. Validasi syntax, pengujian endpoint melalui router, pengujian level 1–4, serta regression test modul terkait harus dilakukan di server dev sebelum merge.

## Status rilis

**Patch berada di branch dev untuk audit dan pengujian. Jangan merge atau publish ke main sebelum seluruh temuan terselesaikan dan seluruh pengujian dinyatakan lulus.**

## Referensi commit patch

| File | Commit |
|---|---|
| `php-crud/bin.locationCRD.php` | `4f0772045e8d2e26624e266b54c24e6c67623475` |
| `php-crud/purchase.orderCRD.php` | `9c5e376245b0742654cf0b03df7b4dd8fde965db` |
| `php-crud/inventory.status.php` | `9f6cae05dc76f785900a0730de5c772dfa129835` |
| `php-crud/clientsCRD.php` | `6882998994fe09dd779d21e8ee36dc9302e652ce` |
| `php-crud/spkCRD.php` | `006691549f66027d4927ae9116b3d1415d45f908` (level 4 delete guard) |
| `php-crud/project.wipreleaseCRD.php` | `778da9141bd51453fe31ac8aabfc8872840f17bc` |
| Dokumen patch | `d35aa528deaddd2f4a1a0b2ca1878f7c240cdcfe` |

Referensi commit pada bagian awal adalah commit patch tahap pertama. Patch lanjutan diterapkan dalam commit-commit sesudahnya; HEAD branch dev terbaru dicatat pada bagian Status Verifikasi di bawah.


## Patch lanjutan setelah audit endpoint berikutnya

Patch berikut juga diterapkan pada branch `dev` setelah pemeriksaan lanjutan:

| File | Perubahan |
|---|---|
| `m.coaCRD.php` | Create level 2, update level 3, delete level 4 (menormalkan level yang sebelumnya tidak konsisten) |
| `m.colorCRD.php`, `m.currencyCRD.php`, `m.departmentCRD.php` | Update level 3 dan delete level 4 |
| `m.employeeCRD.php`, `m.locationCRD.php`, `m.materialsCRD.php`, `m.umCRD.php`, `m.customerCRD.php`, `m.warehouseCRD.php` | Update level 3 dan delete level 4 |
| `m.expensesCRD.php` | Aksi gabungan save/create membedakan create level 2 dan update level 3; soft-delete level 4 |
| `stock.cardCRD.php` | Menambahkan validasi sesi login dan hak baca level 1 di backend |
| `grnCRD.php` | Update GRN level 3 |
| `grnCRD_err.php` | Create level 2, konfirmasi penerimaan fisik yang mem-posting stok/jurnal level 3 |
| `poCRD.php` | Aksi gabungan save_po membedakan create level 2 dan update PO level 3 |
| `project.invoiceCRD.php` | Aksi gabungan save_invoice membedakan terbitkan baru level 2 dan update invoice level 3 |
| `year.closingCRD.php` | Tutup buku level 3; buka kembali/reversal penutupan level 4 |
| `inventory.reconCRD.php` | Perbaikan/backfill jurnal persediaan level 4 |

**Dampak tambahan kepada pengguna:** sejumlah hak master yang sebelumnya bisa update pada level 2 atau delete pada level 3 kini membutuhkan level 3 atau 4. Perubahan PO/invoice yang menggunakan tombol simpan yang sama tetap dapat membuat data pada level 2, tetapi edit data lama memerlukan level 3. Kartu stok sekarang menolak request tanpa sesi atau tanpa hak baca. Konfirmasi penerimaan GRN memerlukan level 3 karena membuat pergerakan stok dan jurnal.

## Hasil pemeriksaan statis lanjutan dan batas audit

- Endpoint yang sudah diperiksa lebih mendalam mencakup PO, invoice proyek, GRN, kartu stok, opname, transfer stok, warehouse issuing, retur pembelian, SPK, subkontrak, pembayaran supplier, tutup buku, jurnal, master data, pengaturan, dan dashboard.
- Dashboard memiliki pengecualian pemetaan menu pada router. Di dalam endpoint terdapat pemeriksaan login; sinkronisasi kurs pajak dan analisis ulang AI memeriksa hak menu khusus langsung dari tabel hak akses user. Pembacaan ringkasan lintas proyek mengikuti kebijakan aplikasi yang tertulis pada source.
- Beberapa endpoint legacy seperti `grnCRD_err.php`, `whrcvCRD_err.php`, dan `inventory.status.php` perlu dikonfirmasi apakah masih dipanggil UI. Jangan hapus atau menambah alias router sebelum pemanggil dan menu induknya dipastikan.
- `stock.cardCRD.php` sebelumnya tidak memiliki validasi login/FormAccess mandiri; sekarang sudah ditambahkan.
- Pemeriksaan source statis belum dapat membuktikan object-level authorization untuk seluruh operasi, karena perlu ditentukan batas kepemilikan data per proyek, gudang, user, dan role. Selain temuan PO detail/biaya yang sudah diperbaiki, bagian tersebut masih memerlukan pengujian IDOR lintas record.
- Belum dilakukan PHP lint, pengujian HTTP di server dev, atau regression test database dari koneksi ini. Karena itu patch belum boleh dinyatakan lulus uji atau siap rilis.

## Pengujian wajib di server dev sebelum merge

1. PHP syntax check seluruh file yang berubah (`php -l`).
2. Uji role level 1: seluruh endpoint read boleh sesuai menu; create/update/delete harus mendapat HTTP 403.
3. Uji role level 2: create boleh; update dan delete harus mendapat HTTP 403.
4. Uji role level 3: update dan aksi persetujuan yang ditentukan boleh; delete/reversal level 4 harus mendapat HTTP 403.
5. Uji role level 4: operasi delete/reversal hanya boleh pada status dokumen yang memang diizinkan.
6. Uji PO: kirim ID detail dan biaya milik PO lain saat update PO aktif; record lain tidak boleh berubah.
7. Uji request tanpa login dan `user_id` tidak valid pada endpoint standar dan legacy.
8. Uji endpoint lewat router dan URL fisik langsung, termasuk file di folder `views/`; pastikan tidak ada view yang membocorkan data saat diakses langsung.
9. Uji alur GRN, invoice proyek, tutup buku, kartu stok, SPK, opname, transfer, retur, pembayaran, dan dashboard agar tidak ada regresi.
10. Periksa log aplikasi dan database setelah setiap uji; gunakan data uji dan backup dev, bukan transaksi production.

**Status akhir audit pada sesi ini: patch statis telah diterapkan, tetapi audit dan validasi runtime belum tuntas. Branch `main` tidak boleh diubah atau menerima merge sampai seluruh tes di atas lulus.**


## Status Verifikasi GitHub — 10 November 2026

- Commit terakhir yang memuat patch aplikasi sebelum pencatatan status ini: `8524c26cc2cabb1070b1e45bcfa45223a672c1fe`.
- Branch `main` saat verifikasi: `9aca8baf196e1047de5295aec7517a3fdc971456`.
- Commit dokumentasi status ini dibuat setelah commit patch aplikasi di atas; lihat riwayat commit branch `dev` untuk HEAD dokumentasi terbaru.
- `main` tidak menerima commit dari pekerjaan ini.
- Semua patch dan dokumen yang disebut dalam laporan berada pada branch `dev`.
- Verifikasi ini memastikan keberadaan perubahan di GitHub; bukan bukti bahwa PHP berjalan benar pada server aplikasi.

**Keputusan:** status pekerjaan adalah *patch statis diterapkan — menunggu pengujian runtime*. Jangan melakukan merge/publish ke `main` sebelum checklist pengujian di atas selesai dan hasilnya dicatat.
