# DDL Subcontract Management

## Isi
File `database/subcontract_management_ddl.sql` adalah migrasi additive untuk fondasi database Subcontract Management.

## Urutan
1. Backup database dan restore ke staging.
2. Pastikan migration SPK/RAB Task Allocation PR #4 sudah diputuskan. DDL ini mereferensikan `project_tasks` dan `budget_items`; jika PR #4 belum diterapkan, jalankan dan uji migration PR #4 terlebih dahulu.
3. Jalankan file DDL ini pada staging.
4. Jalankan pemeriksaan pasca-migrasi yang tercantum di bagian akhir SQL.
5. Isi relasi `subcon_contracts.spk_id` untuk kontrak lama setelah pemetaan SPK diverifikasi manual.
6. Uji SPK INTERNAL tanpa material, SPK SUBCON, klaim kuantitas, inspeksi parsial, sertifikat, invoice, dan transaksi material sisa.
7. Jangan jalankan di production sebelum export schema aktual dan uji integrasi PHP/inventory/journal selesai.

## Keputusan desain yang diterapkan
- Satu master SPK untuk INTERNAL dan SUBCON.
- `spk_items` tidak diubah; tetap untuk izin pengeluaran material gudang.
- `spk_work_items` menjadi lingkup pekerjaan dan kuantitas kontrak.
- Klaim kuantitas dipisahkan dari hasil inspeksi.
- Pembayaran hanya boleh berasal dari kuantitas yang lolos inspeksi dan disetujui.
- Invoice ditautkan ke sertifikat pembayaran.
- Material sisa mempertahankan pemilik asal; perpindahan kepemilikan harus melalui transaksi pembelian/penjualan terdokumentasi.

## Batasan yang masih harus divalidasi
DDL ini dibuat dari definisi SQL yang ada di repository, bukan hasil dump schema database production. ID type dan relasi mengikuti file migration yang diperiksa. Sebelum production, cocokkan dengan schema export aktual, terutama tabel `project_tasks`, `budget_items`, `materials`, `subcontractors`, `subcon_progress`, dan `subcon_invoices`.

DDL belum membuat jurnal atau mutasi stok secara otomatis. Ini disengaja agar tidak membuat jurnal ganda atau melewati mekanisme inventory yang sudah berjalan. Aturan agregat lintas baris (akumulasi qty, batas sertifikasi, invoice dan pembayaran) harus dikunci dan divalidasi di layer transaksi aplikasi; trigger tambahan dibuat setelah skema production dikonfirmasi.

## Status
Draft DDL untuk staging review. Belum dijalankan pada database, belum diuji runtime PostgreSQL, dan belum mengubah branch `main`.
