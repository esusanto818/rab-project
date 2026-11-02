# Upgrade SPK: Task Proyek & Batas Inventory

## Cakupan
- SPK yang sudah ada tetap digunakan; alur status material lama tidak diganti.
- Setiap SPK baru / draft yang disimpan wajib memiliki minimal satu Task Proyek.
- Task harus berasal dari proyek yang sama dan tidak boleh sedang terikat pada SPK aktif lain.
- Item material SPK harus merupakan item Material pada RAB proyek.
- Qty default di editor menggunakan nilai tersedia dari kalkulasi server.
- Batas tersedia mengambil nilai terendah antara kuota RAB/pembayaran dan stok fisik setelah dikurangi sisa alokasi SPK aktif lain.
- Server memvalidasi ulang saat menyimpan Draft dan saat menerbitkan SPK.
- Kolom hapus baris menggunakan ikon trash merah pada header dan setiap baris.

## Penerapan
1. Backup database.
2. Jalankan `database/spk_task_link.sql` pada database PostgreSQL.
3. Deploy `php-func/function.spk.php`, `php-crud/spkCRD.php`, dan `js/spk.js`.
4. Hard refresh browser / bersihkan cache JavaScript.

## Checklist pengujian staging
- SPK baru: pilih proyek, pilih satu atau lebih task dari proyek tersebut.
- Task dari proyek lain tidak dapat dipilih atau disimpan.
- Task yang sudah terikat ke SPK aktif tidak dapat dipakai pada SPK lain.
- Material default qty = nilai available yang dikirim server.
- Qty melebihi available ditolak di browser dan backend.
- Qty melebihi available melalui request langsung juga ditolak.
- Dua SPK aktif tidak dapat mengalokasikan stok fisik yang sama dua kali.
- Perubahan stok setelah Draft disimpan diuji: penerbitan harus menolak qty yang sudah tidak tersedia.
- Tombol hapus baris dan ikon trash header terlihat dan berfungsi.
- Warehouse Issuing lama tetap dapat memilih SPK dan mengeluarkan material sesuai sisa SPK.

## Catatan
Pemeriksaan kode statis saja belum membuktikan perilaku runtime. Migration, browser, database, dan regresi Warehouse Issuing wajib diuji pada staging sebelum merge ke main.
