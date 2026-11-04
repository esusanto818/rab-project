# Pedoman Modul Proyek — RAB-Project

**Status:** Pedoman fungsional  
**Cakupan:** Edit Proyek, Master Material, RAB Proyek, AI Task, dan alokasi material per Task  
**Berlaku untuk:** Pengembangan modul Proyek dan integrasinya dengan SPK

## 1. Tujuan

Pedoman ini menetapkan aturan agar perubahan data proyek, hasil analisis AI, RAB, Task, dan material tetap konsisten. Modul SPK harus menggunakan Task Proyek sebagai sumber lingkup pekerjaan dan tidak boleh merusak data proyek yang sudah berjalan.

## 2. Hirarki data

1. **Master Material** adalah katalog induk material yang dikenal sistem.
2. **RAB Proyek** adalah daftar material proyek beserta kuantitas dan harga anggarannya.
3. **Task Proyek** adalah pembagian lingkup pekerjaan.
4. **Alokasi Material Task** menghubungkan item RAB dengan Task tertentu dan menyimpan kuantitas alokasinya.
5. **SPK** mengambil lingkup Task dan alokasi material sesuai batas yang disetujui.

Material yang direkomendasikan AI harus mengacu pada Master Material. Agar dapat dialokasikan dari form Task, material harus tersedia sebagai item kategori Material pada RAB proyek.

## 3. Aturan Master Material dan RAB

- Material baru yang belum dikenal harus dibuat di Master Material terlebih dahulu.
- Material yang sudah ada di Master Material tetapi belum ada di RAB proyek harus ditambahkan ke RAB proyek sebelum dialokasikan ke Task.
- Penambahan ke RAB harus meminta kuantitas, satuan, dan harga sesuai struktur RAB yang berlaku.
- Sistem tidak boleh membuat item Master Material atau RAB secara diam-diam hanya berdasarkan keluaran AI.
- Rekomendasi AI harus membedakan material yang sudah tersedia di RAB, material yang ada di Master Material tetapi belum ada di RAB, dan material yang belum ada di Master Material.

## 4. Aturan Edit Proyek

### 4.1 Data yang dapat diedit

Nama proyek, lokasi, catatan, PIC, klien, tanggal, budget, nilai kontrak, dan definisi lingkup dapat diedit sesuai hak akses.

Perubahan klien/PIC, tanggal, budget, atau nilai kontrak pada proyek yang sudah berjalan harus menampilkan konfirmasi dan mencatat riwayat perubahan.

### 4.2 Data turunan yang dilindungi

- Mengedit informasi header proyek tidak boleh otomatis menghapus atau menimpa Task.
- Mengedit definisi lingkup tidak boleh otomatis menghapus alokasi material.
- Task yang sudah diedit manual harus dipertahankan saat analisis AI dijalankan ulang.
- Task/material yang sudah dipakai SPK, memiliki progres, inspeksi, pengeluaran gudang, atau transaksi keuangan harus dilindungi dari perubahan destruktif.
- Penghapusan atau pengurangan kuantitas yang bertentangan dengan alokasi harus ditolak atau diproses melalui revisi/approval.

## 5. Aturan Analisis AI dan Review

1. AI menghasilkan draft Task dan rekomendasi material; hasil AI bukan perubahan final.
2. Pengguna meninjau nama Task, deskripsi, jadwal, material, kuantitas estimasi, satuan, dan alasan rekomendasi.
3. Pengguna dapat menambah, mengubah, atau menghapus rekomendasi sebelum menyimpan.
4. Material yang tersedia di RAB dapat dialokasikan langsung ke Task.
5. Material yang ada di Master Material tetapi belum ada di RAB menawarkan tindakan **Tambahkan ke RAB Proyek**.
6. Material yang belum ada di Master Material menawarkan tindakan pembuatan Master Material, lalu penambahan ke RAB.
7. Sistem harus menampilkan perbedaan antara data tersimpan dan draft AI sebelum menerapkan perubahan.
8. Analisis ulang tidak boleh menimpa perubahan manual atau menghapus Task/material yang sudah digunakan transaksi.

## 6. Aturan Alokasi Material per Task

- Satu Task boleh memiliki banyak material.
- Satu material RAB boleh dialokasikan ke beberapa Task.
- Total kuantitas alokasi material pada seluruh Task tidak boleh melebihi kuantitas RAB.
- Material yang sama dapat digunakan oleh lebih dari satu Task; sistem tidak boleh menganggap duplikasi tersebut sebagai kesalahan.
- Kuantitas, satuan, dan referensi item RAB harus ditampilkan jelas.
- Perubahan kuantitas RAB tidak boleh membuat total alokasi Task melebihi kuantitas RAB.
- Item RAB yang sudah memiliki alokasi Task tidak boleh dihapus atau diganti identitasnya tanpa menyelesaikan alokasi/revisi terkait.

## 7. Aturan perubahan setelah proyek berjalan

Sebelum menyimpan perubahan, sistem harus memeriksa apakah data terkait sudah digunakan pada SPK, pengeluaran material, progres, inspeksi, sertifikat pembayaran, atau transaksi lain.

Jika sudah digunakan:
- perubahan identitas Task atau item RAB diblokir atau memerlukan revisi resmi;
- pengurangan kuantitas tidak boleh membuat realisasi/alokasi melampaui batas baru;
- data historis dan referensi transaksi harus tetap dapat ditelusuri;
- perubahan harus mencatat pengguna, waktu, nilai sebelum, nilai sesudah, dan alasan.

## 8. Integrasi dengan SPK

- SPK memilih satu proyek dan satu atau lebih Task yang berasal dari proyek tersebut.
- SPK dapat berupa pekerjaan internal atau pekerjaan Subcon.
- Lingkup pekerjaan dan kuantitas kerja SPK harus dipisahkan dari kuantitas material untuk pengeluaran gudang.
- Material perusahaan yang akan dikeluarkan tetap mengikuti aturan kuota SPK dan Warehouse Issuing.
- Material yang dibawa Subcon tidak boleh otomatis menambah atau mengurangi stok perusahaan.
- Kuantitas pekerjaan yang disetujui dalam SPK menjadi batas pengukuran progres; perubahan dilakukan melalui revisi/addendum.

## 9. Validasi dan pesan pengguna

Pesan validasi harus menjelaskan tindakan yang dapat dilakukan pengguna, misalnya:
- "Material belum ada di RAB proyek. Tambahkan material ini ke RAB terlebih dahulu."
- "Kuantitas alokasi Task melebihi sisa kuantitas RAB."
- "Task sudah digunakan pada SPK dan tidak dapat dihapus langsung. Buat revisi atau selesaikan transaksi terkait."
- "Analisis AI menghasilkan draft. Periksa perubahan sebelum menyimpan."

## 10. Kriteria penerimaan

- Edit header proyek tidak menghapus Task atau material.
- AI dapat merekomendasikan lebih dari satu material untuk satu Task.
- Pengguna dapat menambahkan material ke RAB tanpa meninggalkan alur review.
- Material yang sama dapat dialokasikan ke beberapa Task selama totalnya tidak melampaui RAB.
- Review menampilkan perubahan AI dan data lama secara jelas.
- Task/material yang telah digunakan SPK atau transaksi terlindungi.
- SPK dapat menggunakan Task proyek tanpa mencampur lingkup pekerjaan dengan hak pengeluaran material.
- Semua validasi kuantitas dijalankan di server/database, bukan hanya di tampilan.

## 11. Hubungan dengan pedoman SPK

Pedoman ini harus dibaca bersama `docs/SUBCONTRACT_MANAGEMENT_DESIGN.md`. Jika terdapat konflik, aturan perlindungan data historis, validasi kuantitas, dan pemisahan lingkup pekerjaan dari pengeluaran material harus dipertahankan.
