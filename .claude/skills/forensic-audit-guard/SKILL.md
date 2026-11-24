---
name: forensic-audit-guard
description: Aturan audit forensik dan skeptis untuk mencegah laporan "false PASS" pada RAB-Project.
---

# Forensic Audit Guard (Anti-False-PASS)

1. **AUDIT FIRST — NO PATCH**: Dilarang keras mengubah source code, membuat file PHP/JS baru, atau menjalankan DDL/migrasi selama sesi `/audit-ketat`.
2. **LARANGAN KLAIM TANPA BUKTI**: Dilarang menulis status `PASS` atau `SAFE` tanpa menyebutkan **nama file + nomor baris** serta **potongan logika** yang membuktikannya.
3. **BUDGET TOKEN KETAT**: Periksa maksimal **4 file per batch**. Gunakan `rg` untuk mencari endpoint/fungsi terkait sebelum membaca file besar secara utuh.
4. **WAJIB SIMPAN KE DISK**: Setiap selesai 1 batch, wajib menulis/menambahkan hasil temuan ke `docs/DEEP_AUDIT_REGISTER.md` dalam format tabel & Finding Card (`ID`, `Severity`, `Lokasi File:Baris`, `Bukti Kode`, `Skenario Gagal di Klien`, `Rekomendasi Perbaikan`, `Test Case`).
5. **UJI NYATA DI `proyekGMN_test`**: Jika melakukan pengujian endpoint (`curl`) atau query verifikasi, gunakan hanya database `proyekGMN_test` dan hapus/bersihkan record uji berawalan `AUDIT-TEST-` setelah selesai.
