Gunakan sub-agent `theme-styler-auditor` untuk memperbarui tema visual proyek berdasarkan komposisi warna yang diminta user.

Komposisi Warna Acuan dari User: $ARGUMENTS

Langkah Eksekusi:
1. **Validasi Palet Warna:** Jika user belum menyebutkan kode warna atau nuansa warna yang diinginkan, tanyakan komposisi warna acuannya terlebih dahulu. Jika user memberikan deskripsi warna (contoh: `"Burgundy #800020 dan Kuning Emas #F59E0B dengan latar bersih"`), turunkan menjadi palet lengkap (`--color-primary`, `--color-secondary`, `--color-accent`, `--color-bg`, `--color-surface`, `--color-text`).
2. **Audit Struktur Layout & CSS Saat Ini:** Periksa `index.php`, `login.php`, folder `css/`, serta class Tailwind/ExtJS yang dipakai di proyek.
3. **Terapkan Tema Terpusat (`css/custom-theme.css`):** Buat/perbarui stylesheet override yang mencakup komponen ExtJS 3.4.1 (Viewport, Panel, Tree Menu, Toolbar, Button, Window, Grid Header/Row) maupun class Tailwind/HTML tanpa merusak aturan *grid text-wrap*.
4. **Verifikasi & Dokumentasi:** Cek hasil perubahan dengan `git status`/`git diff` dan tulis dokumentasi palet warna ke `docs/THEME_GUIDE.md`.
