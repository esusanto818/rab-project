---
description: Perbaiki halaman kosong / angka 0 / data tidak tampil / "Aksi tidak ditemukan" (kontrak frontend dan backend). Cepat, edit minimal, tanpa dokumen panjang.
---
Gunakan sub-agent `contract-debugger` untuk memperbaiki masalah: **$ARGUMENTS**

Aturan eksekusi:
1. Jika `$ARGUMENTS` tidak menyebut halaman/URL, tanyakan satu hal saja: path view atau URL yang bermasalah.
2. Ikuti protokol `contract-debugger` secara berurutan: reproduksi, peta kontrak, bandingkan bentuk respons, cek fungsi/id/konstanta yang hilang, cek riwayat git, edit minimal, verifikasi dengan bukti.
3. Jangan membuat migrasi SQL, modul baru, pendaftaran menu, atau dokumen How-To. Cukup satu entri di `docs/SESSION_LOG.md`.
4. Jangan menulis ulang file eksisting secara penuh; gunakan edit terarah.
5. Akhiri dengan tabel kontrak (sebelum/sesudah), daftar file yang diubah, dan hal yang belum terverifikasi.
