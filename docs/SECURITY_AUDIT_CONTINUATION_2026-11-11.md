# Audit Keamanan RAB-Project — Lanjutan dan Patch Tahap Berikutnya

Tanggal pemeriksaan: 11 November 2026
Repository: `esusanto818/rab-project`
Branch audit/patch: `dev`
HEAD saat catatan ini dibuat: `d6a85e3a784857fa8e3f1812c9fee3947a60175a`

## Batasan keselamatan repository

- Seluruh patch audit dibuat hanya pada branch `dev`.
- Branch `main` tidak diubah, tidak di-merge, dan tidak dipublikasikan.
- Pada pemeriksaan terakhir, `dev` berada 385 commit di depan `main` dan 0 commit di belakang.
- Working copy lokal yang dilaporkan pengguna masih berada pada `e035d11`; commit patch di GitHub belum diambil ke working copy tersebut.
- Tidak ada hasil GitHub status/CI untuk commit patch terbaru. Status checks yang dikembalikan kosong.

## Cakupan pemeriksaan

- Inventarisasi 57 endpoint standar `php-crud/*CRD.php`.
- Pemeriksaan statis login, pemetaan menu induk, FormAccess, metode HTTP, parameter request, query SQL dinamis, upload, dan transaksi.
- Pemeriksaan alur khusus SPK, subkontraktor, invoice, pembayaran, jurnal, persediaan, dan progress proyek.
- Pemeriksaan pendukung terhadap `user.valid.php`, `function.all.php`, `loginV1.php`, `.htaccess`, dan workflow PHP lint.

Pemeriksaan ini belum mencakup seluruh kemungkinan kombinasi runtime, seluruh skema database yang sedang dipakai di server, atau uji penetrasi eksternal.

## Patch yang sudah diterapkan di dev

### Commit `abbc0973ecd3c4b19711dd6e747072899ab2f588`

- `clientsCRD.php`: operasi create/update/delete diwajibkan POST; input mutasi dibaca dari POST; penghapusan mensyaratkan FormAccess level 4.
- `m.materialsCRD.php`: operasi mutasi diwajibkan POST dan input mutasi dibaca dari POST.
- `m.taxexchangerateCRD.php`: fetch/sync/delete diwajibkan POST.
- `m.user.systemCRD.php`: mutasi akun diwajibkan POST dan ID delete tidak lagi fallback ke GET.
- `function.all.php`: nilai pencarian pada `searchData()` dan `srcDataGrid()` di-escape sebagai literal PostgreSQL; pencarian tidak lagi menaikkan limit menjadi 99999 secara diam-diam.

### Commit `656bd02bfccc95c181232af13ead716ffa5f0e79`

- `m.employeeCRD.php`: pencarian nama karyawan tidak lagi menggabungkan teks pencarian mentah ke SQL.
- `function.all.php`: `CreateNikNo()` memakai `pg_query_params()` untuk unit/departemen, status, dan tahun.
- `m.user.systemCRD.php`: pencarian pada task addform diperlakukan sebagai nilai pencarian, bukan SQL fragment dari pemanggil.
- `spkCRD.php` dan `subconCRD.php`: memakai pemeriksaan endpoint/menu melalui `user.valid.php`.
- `subconCRD.php`: maker-checker untuk persetujuan SPK dan prestasi diaktifkan; operasi mutasi diwajibkan POST.
- `dashboardCRD.php`: status akun aktif diverifikasi untuk request dashboard.
- `loginV1.php`: same-origin check dipanggil pada POST login; password plaintext/Base64/MD5 lama di-upgrade ke password_hash setelah login berhasil.
- `journal.entryCRD.php`: reverse jurnal dan penghapusan jurnal Draft dibungkus transaksi; baris jurnal sumber dikunci untuk mencegah reversal bersamaan.

### Commit `d6a85e3a784857fa8e3f1812c9fee3947a60175a`

- `m.employeeCRD.php`: upload foto memakai validasi data URI, strict Base64, batas 5 MB, pemeriksaan MIME/isi gambar, nama file acak dari server, dan direktori filesystem yang diikat ke lokasi endpoint. Nama file dari klien tidak lagi dipakai sebagai path.

## Temuan yang masih menjadi penghalang audit/rilis

1. **Integrasi inspeksi dan sertifikat subkon belum selesai.** Alur lama `subconCRD.php` masih menyetujui progres dan memposting jurnal tanpa mewajibkan inspeksi dan sertifikat pembayaran dari struktur DDL baru. Endpoint belum menunjukkan integrasi menyeluruh dengan `subcon_inspections` dan `subcon_payment_certificates`.
2. **Invoice subkon belum konsisten dengan kontrak/progres tersertifikasi.** Pada `subcon.invoiceCRD.php`, pemilihan progres harus diperiksa terhadap contract_id yang sama; invoice masih dapat dibuat tanpa progress_id dan nominal invoice belum dipastikan sama dengan nilai yang dapat ditagihkan. Perlu keputusan bisnis dan integrasi sertifikat sebelum mengunci aturan.
3. **Persetujuan invoice subkon** belum dipastikan menerapkan maker-checker secara konsisten pada seluruh transisi/status dan jalur posting jurnal.
4. **SPK material supply dan leftover baru** sudah mempunyai struktur DDL, tetapi integrasi penuh pada endpoint SPK, issuing, pembelian/penjualan sisa material, dan rekonsiliasi persediaan masih perlu diselesaikan.
5. **Journal reverse** telah dibuat atomik pada endpoint jurnal manual. Helper reverse bersama dan seluruh pemanggilnya masih harus diperiksa untuk idempotensi, periode jurnal, dan transaksi lintas modul.
6. **Object-level authorization / IDOR** masih memerlukan uji dengan ID proyek, SPK, PO, GRN, invoice, pembayaran, gudang, dan record milik entitas lain.
7. **Session cookie policy** belum terbukti konsisten pada seluruh entry point. Tetapkan dan uji Secure, HttpOnly, SameSite, lifetime, dan session regeneration.
8. **Password legacy** masih menerima plaintext/Base64/MD5 sebagai jalur verifikasi kompatibilitas. Setelah seluruh akun berhasil dimigrasikan, fallback tersebut harus dihapus melalui rencana migrasi yang aman.
9. **Upload lain** (progress, delivery, foto karyawan, dan bukti transaksi) masih perlu diuji pada konfigurasi Apache aktual, termasuk akses file langsung, MIME, batas ukuran, dan larangan eksekusi.
10. **Kredensial database** pernah berada di riwayat Git. Rotasi kredensial, audit akses repo, dan pemeriksaan riwayat masih wajib. Menghapus rahasia dari file terbaru tidak menghapusnya dari riwayat.
11. **SQL dinamis lain** dan penggunaan helper `insertQ/updateQ/cmdQ` masih memerlukan pemeriksaan menyeluruh terhadap nama tabel/kolom dan input request.
12. **Uji runtime** untuk semua operasi level 1–4, GET/POST, same-origin, CSRF, dan regresi transaksi belum dilakukan di server dev.

## Status pengujian

- Pengguna menjalankan lint seluruh file PHP pada working copy lokal `e035d11`; hasil `LINT_EXIT=0`.
- Hasil tersebut belum mencakup patch GitHub setelah `e035d11`.
- GitHub status checks untuk HEAD patch terbaru kosong; tidak ada bukti CI lulus.
- Pemeriksaan sintaks pada patch terbaru masih harus dijalankan di worktree yang mengambil commit `dev` terbaru.
- Pengujian database/HTTP belum dijalankan; jangan menjalankan tes mutasi pada production.

## Langkah pengujian lokal yang aman

Ambil commit terbaru ke worktree terpisah agar branch dan file kerja yang ada tidak tertimpa:

```bash
cd /home/myadmin/proyekgmn-dev
git fetch origin dev
git worktree add --detach /tmp/rab-project-audit-d6a85e3 origin/dev
cd /tmp/rab-project-audit-d6a85e3

git rev-parse HEAD
find . -type f -iname '*.php' -not -path './.git/*' \
  -print0 | xargs -0 -n1 php -l \
  > /tmp/rab-php-lint-patched.log 2>&1

echo "LINT_EXIT=$?"
grep -v "No syntax errors detected" /tmp/rab-php-lint-patched.log | head -100
```

Gunakan database dev disposable/salinan untuk uji HTTP dan transaksi. Jangan menjalankan skenario mutasi, reversal, backfill, atau upload terhadap database production.

## Keputusan

**Audit belum selesai dan belum siap rilis.** Patch tahap awal telah diterapkan ke `dev`, tetapi beberapa area integrasi dan pengujian di atas masih terbuka. Jangan merge, publish, atau mengubah `main` sampai semua temuan ditangani, seluruh pengujian lulus, dan hasilnya dicatat.
