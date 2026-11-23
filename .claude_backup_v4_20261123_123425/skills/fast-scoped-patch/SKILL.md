---
name: fast-scoped-patch
description: Protokol eksekusi cepat untuk patching dan audit terarah tanpa pemindaian massal atau sub-agen berat. Gunakan pada setiap tugas implementasi patch.
---

# Protokol Eksekusi Cepat (`Fast Scoped Patch`)

1. **Hindari Sub-Agen (`Task` / `Explore`) untuk tugas patching terfokus**, kecuali diperintahkan eksplisit via `/bangun-fitur`, `/audit-dan-tambal`, atau `/perbaiki-ui`. Kerjakan langsung di thread utama.
2. **Pencarian Terarah (`rg`):** `rg -n "<kata_kunci>" php-crud/ php-func/ js/ views/`. JS sebuah halaman bisa INLINE di `views/*.php`, jadi `views/` wajib ikut dicari. Jangan memindai seluruh proyek.
3. **Baca Seperlunya, dengan satu pengecualian:** untuk modul biasa baca hanya bagian fungsi yang diubah (`offset`/`limit`). Untuk bug halaman kosong / data tidak tampil / kontrak frontend-backend, baca KEDUA sisi: handler `case` di `php-crud/*CRD.php` dan blok JS yang memanggilnya, karena bug ada di antara keduanya.
4. **Edit Terarah:** gunakan `Edit` (str_replace) dengan diff minimal. Jangan menulis ulang file eksisting secara penuh (risiko mengembalikan bagian lama).
5. **Verifikasi Ringkas:** setelah mengedit, jalankan `php -l <file_diubah>`, lalu `curl` endpoint yang diubah. Jalankan skrip E2E hanya bila logika transaksi/DB berubah. Setelah itu baru rapikan dokumentasi.
