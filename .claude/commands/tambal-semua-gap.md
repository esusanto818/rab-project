---
description: Menambal daftar gap/temuan yang sudah disetujui developer secara bertahap (maksimal 3 finding per batch) dengan verifikasi penuh.
---

Tambal temuan/gap berdasarkan daftar pada: **$ARGUMENTS** (default: file temuan terbaru di `docs/`).

Gunakan agent `fullstack-patcher` bersama skill `token-budget-guard`, `extjs-341-patterns`, dan `rbac-endpoint-guard`.

ATURAN EKSEKUSI BERTAHAP:
1. Kelompokkan temuan berdasarkan prioritas (`CRITICAL` -> `HIGH` -> `MEDIUM`).
2. Kerjakan maksimal **3 Finding ID per batch** agar tidak melebihi batas konteks model.
3. Sebelum edit file `> 300` baris, buat salinan `.bak-YYYYMMDDHHMM`.
4. Setelah setiap batch:
   - Jalankan `php -l` / `node --check`.
   - Verifikasi kontrak API (`rg`) dan pendaftaran `$endpointAliases` di `user.valid.php`.
   - Uji skenario pada `proyekGMN_test`.
   - Update status Finding ID menjadi `VERIFIED` di file laporan `docs/` dan catat di `AUDIT_CHANGELOG.md`.
5. Jika masih ada batch berikutnya dan konteks sudah panjang, sarankan `/compact` lalu lanjutkan batch selanjutnya.
