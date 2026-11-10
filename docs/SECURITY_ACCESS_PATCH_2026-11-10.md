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
| `php-crud/spkCRD.php` | `88bebb4f2430b511fade01025d2731f1511244fe` |
| `php-crud/project.wipreleaseCRD.php` | `778da9141bd51453fe31ac8aabfc8872840f17bc` |
| Dokumen patch | `d35aa528deaddd2f4a1a0b2ca1878f7c240cdcfe` |

HEAD branch dev setelah pencatatan dokumen: `d35aa528deaddd2f4a1a0b2ca1878f7c240cdcfe`.
