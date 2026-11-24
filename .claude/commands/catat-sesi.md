---
description: Merekam ringkasan pekerjaan sesi ini (masalah, perubahan file, status tes) secara kronologis ke dalam docs/SESSION_LOG.md.
---
Buat dan tambahkan (append) catatan log sesi kerja saat ini ke bagian paling bawah file `docs/SESSION_LOG.md` (buat filenya terlebih dahulu jika belum ada) dengan format berikut:

## [Tanggal & Jam WIB] - Ringkasan Topik Sesi
- **Tujuan / Permintaan**: (jelaskan singkat apa yang dikerjakan pada sesi ini)
- **File yang Diubah / Dibuat**: (jalankan `git status -s` untuk mendaftar file secara akurat)
- **Ringkasan Teknis & Keputusan**: (poin-poin perubahan logika, SQL, atau perbaikan bug)
- **Status Verifikasi**: (hasil `php -l` / uji E2E / catatan tindak lanjut jika ada)

Setelah file `docs/SESSION_LOG.md` diperbarui, tampilkan konfirmasi singkat bahwa log sesi telah tersimpan.
