---
description: Pemeriksaan gerbang pra-deploy (cek sintaks PHP, sisa debug var_dump/console.log, kebersihan Git, dan catatan rilis).
---
Lakukan **Pre-Deployment Gatekeeper Check** sebelum kode di-commit atau di-push ke GitHub:

1. Jalankan `git status -s` dan `git diff --stat` untuk mendata seluruh file yang berubah atau baru dibuat.
2. Jalankan `php -l` pada seluruh file `.php` yang berubah untuk memastikan tidak ada *syntax error*.
3. Pindai file yang berubah terhadap sisa kode debug (`var_dump`, `print_r`, `die(`, `console.log`) maupun potensi kebocoran kredensial/password.
4. Jika tersedia skrip validasi proyek seperti `./cek.deploy.sh` atau `./cek_rule_dipakai_kode.sh`, jalankan untuk verifikasi tambahan.
5. Susun ringkasan perubahan siap pakai untuk pesan `git commit` dan pembaruan `AUDIT_CHANGELOG.md` (jangan jalankan `git commit` secara otomatis).
