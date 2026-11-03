# DDL Subcontract Management — schema aktual

## Basis
Disesuaikan dengan tiga export database yang diberikan pada 3 November 2026:
- `rab-schema.md`
- `columns_202611030804.csv`
- `vw_table_relations_202611030759.csv`

## File utama
`database/subcontract_management_ddl.sql`

## Penyesuaian terhadap schema aktual
- ID `spk`, `projects`, `project_tasks`, `budget_items`, `subcontractors`, dan tabel Subcon existing menggunakan `integer`; kolom relasi baru mengikuti tipe tersebut.
- Menggunakan tabel existing `spk_tasks` sebagai relasi SPK-task yang sudah tersedia. `spk_work_items` adalah detail kuantitas dan harga lingkup pekerjaan, bukan pengganti `spk_tasks`.
- `subcon_contract_tasks` dipertahankan dan ditautkan ke `spk_work_items` melalui `work_item_id`; kolom legacy tidak dihapus.
- `subcon_progress` tetap menjadi header klaim; detail klaim kuantitas baru ditambahkan di `subcon_progress_qty_items`.
- `subcon_invoices` tetap dipakai dan ditautkan ke payment certificate. Dua tabel pembayaran existing tidak diubah agar tidak mencampur dua jalur posting sebelum rekonsiliasi.
- `material_leftover_header/items` adalah dokumen inventory existing. Register kepemilikan material sisa dibuat terpisah pada `spk_material_leftover_lots` dan `spk_material_leftover_transactions`; ini mencegah material milik Subcon otomatis masuk stok perusahaan.
- `spk_items` tidak diubah dan tetap untuk otorisasi issue material gudang. Relasi `material_out_header.spk_id` tetap dipertahankan.

## Urutan pelaksanaan
1. Backup database, lalu restore ke staging.
2. Pastikan branch/migration SPK Task Allocation yang relevan sudah dipasang dan diuji. Schema export sudah menunjukkan `spk_tasks` tersedia.
3. Jalankan SQL dalam satu transaksi di staging.
4. Jalankan query pemeriksaan di akhir SQL.
5. Mapping data kontrak lama: isi `subcon_contracts.spk_id` setelah SPK, proyek, dan Subcon diverifikasi.
6. Mapping setiap `subcon_contract_tasks` ke `spk_work_items` melalui `work_item_id`.
7. Uji SPK INTERNAL tanpa material, SPK SUBCON, klaim qty, inspeksi parsial, sertifikat, invoice, dan transaksi material sisa.
8. Uji posting inventory dan jurnal melalui workflow aplikasi existing; jangan membuat stock movement/jurnal kedua.
9. Baru setelah lulus staging, siapkan rencana deployment production dan rollback.

## Alur data yang didukung
1. SPK INTERNAL atau SUBCON dibuat pada master `spk`.
2. Lingkup pekerjaan, volume, harga, task, dan item RAB disimpan pada `spk_work_items`.
3. Kontrak Subcon ditautkan ke SPK melalui `subcon_contracts.spk_id`; rincian kontrak lama ditautkan melalui `subcon_contract_tasks.work_item_id`.
4. Progress Subcon diajukan dalam kuantitas melalui `subcon_progress_qty_items`.
5. Inspeksi menyimpan qty diperiksa, lolos, ditolak, dan rework.
6. Sertifikat hanya boleh mengambil kuantitas dari inspeksi berstatus APPROVED.
7. Invoice existing ditautkan ke sertifikat yang sudah APPROVED.
8. Pembayaran tetap melalui jalur existing setelah dipilih dan direkonsiliasi oleh implementasi aplikasi.
9. Material sisa dicatat per lot dengan pemilik asal dan pemilik saat ini. Perubahan kepemilikan dicatat melalui transaksi BUY_FROM_SUBCON atau SELL_TO_CLIENT.

## Batasan dan kontrol wajib
- SQL ini bersifat additive dan tidak menghapus tabel atau kolom existing.
- Belum memindahkan data historis progress berbasis persen ke kuantitas.
- Belum memilih salah satu dari `subcon_payments` dan `subcon_invoice_payments` sebagai satu-satunya jalur pembayaran. Keduanya harus direkonsiliasi lebih dahulu.
- Trigger ownership mengubah register lot ketika transaksi berubah ke POSTED; posting penerimaan/pengeluaran stok harus dilakukan aplikasi melalui modul inventory existing dalam transaksi database yang sama.
- Trigger dasar memvalidasi referensi dan batas per baris. Agregasi kumulatif lintas klaim, approval certificate header, invoice balance, payment balance, dan concurrency perlu validasi aplikasi serta pengujian transaksi sebelum production.

## Status
Revisi berbasis schema export aktual. Belum dieksekusi pada database dan belum diuji runtime PostgreSQL. Tetap di branch terpisah; tidak mengubah `main`.


## Catatan transaksi material sisa
Versi DDL terbaru memecah lot ketika transaksi BUY_FROM_SUBCON atau SELL_TO_CLIENT diposting. Kuantitas yang berpindah dibuat sebagai lot turunan dengan pemilik baru, sedangkan sisa kuantitas pada lot asal tetap dimiliki pemilik sebelumnya. Ini penting untuk transaksi sebagian agar kepemilikan tidak berpindah untuk seluruh sisa material.


## Hasil validasi statis DDL (3 November 2026)
Revisi lanjutan menambahkan:
- Relasi opsional `spk_work_items.spk_task_id` ke `spk_tasks.id`, dengan validasi SPK dan task harus cocok.
- Validasi bahwa work item kontrak Subcon harus berasal dari SPK SUBCON untuk proyek dan Subcon yang sama.
- Akumulasi qty inspeksi untuk satu item klaim tidak boleh melebihi qty klaim.
- Approval payment certificate memeriksa total detail terhadap `gross_amount`, rumus `net_payable`, serta status inspeksi APPROVED.
- Transaksi material sisa POSTED tidak dapat diedit/dihapus; gunakan dokumen koreksi.
- Transaksi material sisa yang diposting harus merujuk dokumen inventory POSTED yang tepat: penerimaan menggunakan `material_leftover_header/items`, penjualan menggunakan `material_out_header/items`.
- Total kuantitas transaksi yang ditautkan ke satu dokumen inventory tidak boleh melebihi kuantitas dokumen. Posting langsung dengan status POSTED juga diproses oleh trigger, bukan hanya perubahan DRAFT ke POSTED.
- Transfer sebagian material sisa memecah lot sehingga sisa lot asal tetap memiliki pemilik lama.

## Temuan yang masih harus ditutup sebelum production
1. Export schema yang diberikan adalah metadata tabel/relasi, bukan dump lengkap definisi constraint, trigger, function, dan index. Nama kolom sudah dicocokkan, tetapi potensi trigger existing yang bertabrakan belum bisa disingkirkan.
2. Akumulasi total qty PASSED antar seluruh inspeksi APPROVED untuk satu work item belum dijaga sebagai satu invariant database lintas semua header inspeksi. Aplikasi perlu mengunci work item saat approval, atau tambahkan prosedur approval terpusat sebelum produksi.
3. Invoice dan dua tabel pembayaran lama (`subcon_payments`, `subcon_invoice_payments`) belum direkonsiliasi. Jangan mengaktifkan dua jalur pembayaran bersamaan.
4. Pencatatan stok harus dilakukan oleh workflow inventory existing dalam transaksi database yang sama. DDL ini hanya mewajibkan referensi dokumen inventory yang sudah POSTED dan mencocokkan kuantitas; tidak membuat jurnal atau stock movement sendiri.
5. Validasi eksekusi PostgreSQL, skenario konkurensi, rollback, serta integrasi PHP/JS belum dilakukan.

**Kesimpulan:** DDL telah melalui review statis dan beberapa guard penting sudah diperbaiki, tetapi statusnya masih *staging candidate*, bukan production-ready.
