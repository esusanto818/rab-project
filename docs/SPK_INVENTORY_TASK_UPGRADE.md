# Upgrade SPK, Task, Material, dan Expenses

## Model bisnis yang diterapkan
- Satu proyek memiliki banyak Task dan banyak item RAB.
- Satu SPK dapat mengikat banyak Task.
- Satu Task dapat digunakan oleh beberapa SPK (pekerjaan bertahap / beberapa kontraktor).
- Item RAB boleh belum dialokasikan ke Task saat RAB disusun.
- Sebelum material/expense digunakan pada SPK, item RAB harus dialokasikan ke Task.
- Material mengikuti Qty RAB dan kontrol stok Inventory.
- Expenses mengikuti plafon nilai RAB dan realisasi biaya; tidak menggunakan perhitungan stok.

## Perubahan pada branch ini
- SPK lookup dan validasi backend tidak lagi menolak Task yang sudah dipakai SPK aktif lain.
- Task tetap wajib berasal dari proyek yang sama dengan SPK.
- RAB editor menyediakan pilihan Master Expense untuk kategori non-Material dan mengirimkan `expense_id` saat menyimpan.
- Kategori Konsumsi tersedia pada editor RAB.
- Migration SPK-Task yang sudah ada: `database/spk_task_link.sql`.
- Migration fondasi alokasi Task/RAB/SPK/Expenses: `database/task_material_expense_allocation.sql`.

## Tabel alokasi baru
| Tabel | Fungsi |
|---|---|
| `task_materials` | Alokasi Qty item RAB Material ke Task |
| `task_expenses` | Alokasi nilai item RAB non-Material ke Task |
| `spk_task_materials` | Pembagian Qty material SPK per Task |
| `spk_task_expenses` | Pembagian nilai expenses SPK per Task |
| `financial_expense_allocations` | Pembagian transaksi biaya aktual ke Task/Expense RAB dan opsional SPK |

Trigger PostgreSQL memvalidasi kesamaan proyek, kategori item, total alokasi RAB, total alokasi SPK per Task, dan agar total alokasi transaksi aktual tidak melebihi nilai transaksi sumber.

## Penting: batas cakupan
Migration ini menyiapkan struktur dan perlindungan database. Migration TIDAK menebak pembagian Task untuk SPK lama dan TIDAK mengisi otomatis alokasi RAB lama. Data SPK/RAB yang sudah ada tetap tersimpan.
UI untuk mengalokasikan setiap item RAB ke Task, membagi material/expenses per Task pada SPK, serta mengalokasikan transaksi financial expense ke Task perlu diselesaikan dan diuji sebagai tahap aplikasi berikutnya. Sampai UI tersebut tersedia, jangan menerbitkan SPK baru dengan asumsi pembagian per Task sudah tercatat.

## Urutan migration staging
1. Backup database.
2. Jalankan `database/spk_task_link.sql` jika tabel `spk_tasks` belum tersedia.
3. Jalankan `database/task_material_expense_allocation.sql`.
4. Deploy kode PHP/JS dari branch ini.
5. Uji semua skenario di bawah pada staging.

## Checklist pengujian staging
- Task dari proyek lain ditolak pada SPK.
- Task yang sama dapat dipilih pada dua SPK berbeda.
- Master Expense muncul sesuai kategori pada editor RAB.
- Expense tanpa pilihan Master Expense ditolak sebelum simpan.
- Simpan/edit RAB Material dan Expense tidak mengubah item kategori lain.
- Item RAB yang sudah dialokasikan ke Task tidak dapat dihapus atau diturunkan di bawah alokasi.
- Total alokasi Task tidak melebihi Qty/nilai item RAB.
- Total alokasi material SPK untuk Task tidak melebihi alokasi Task dan Qty material pada SPK.
- Total alokasi expenses SPK tidak melebihi anggaran expenses Task.
- Total alokasi transaksi financial expense tidak melebihi nilai transaksi.
- Warehouse Issuing lama tetap berfungsi dan menghitung sisa SPK dengan benar.
- SPK lama dan RAB lama tetap dapat dibuka tanpa alokasi baru.

## Status verifikasi
Review statis saja belum membuktikan runtime. Migration PostgreSQL, PHP, ExtJS, transaksi konkuren, dan regresi Warehouse Issuing belum dijalankan pada database/browser pengguna. Jangan merge ke `main` sebelum pengujian staging selesai.
