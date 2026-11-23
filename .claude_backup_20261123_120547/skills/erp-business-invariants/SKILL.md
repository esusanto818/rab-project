---
name: erp-business-invariants
description: Aturan wajib pengembangan PHP 7.4, ExtJS 3.4.1, transaksi ACID PostgreSQL, isolasi stok proyek, dan standar pelaporan AUDIT_CHANGELOG.md.
---

# Standar Wajib Pengembangan & Audit ERP (`proyekgmn-dev`)

## 1. Standar Kode & Keamanan
- **Kompatibilitas PHP 7.4:** Dilarang keras memakai fungsi PHP 8+ (`str_starts_with`, `str_contains`, `str_ends_with`).
- **SQL Injection & Regex:** Selalu gunakan `pg_query_params` atau PDO `prepare/execute`. Hindari komentar `//` di dalam string SQL multi-baris dan waspadai regex titik (`.`) PostgreSQL pada penomoran otomatis (`CreateRefNo`, `ti_next_release_no`).
- **Transaksi ACID:** Gunakan `BEGIN ... COMMIT / ROLLBACK` dengan penguncian baris (`FOR UPDATE`) untuk seluruh mutasi bertingkat atau perubahan status dokumen.
- **Format JSON:** Dilarang mencetak `echo`, `var_dump`, atau `print_r` di endpoint `php-crud/*.php`.

## 2. Aturan Bisnis Lintas Modul
- **Stok Proyek:** Selalu hitung kebutuhan bersih via `GetProjectStockMap()` di `php-func/function.all.php`. Stok proyek lain tidak boleh dipinjam.
- **Tagihan Klien vs Payment:** Jadwal termin berstatus `Draft` tidak boleh muncul di modul Payment (`project.paymentCRD.php`). Hanya tagihan yang sudah diterbitkan & berstatus `Released` yang muncul di Payment dan boleh dicetak resmi tanpa watermark DRAFT.

## 3. Verifikasi & Pelaporan Wajib Sebelum Selesai
1. Jalankan `php -l` pada seluruh file PHP yang diubah.
2. Bila logika transaksi/DB berubah: jalankan uji E2E di `tests/` hingga `FAIL=0` (pastikan base URL dan DB = instance yang dipakai user). Bila hanya UI/kontrak JSON yang berubah: `curl` setiap endpoint yang diubah dan periksa key JSON.
3. Perbarui `AUDIT_CHANGELOG.md` di root proyek dan tampilkan output `git status -s` serta `git diff --stat`.
4. **Jangan pernah menjalankan `git commit` tanpa instruksi eksplisit dari user.**
