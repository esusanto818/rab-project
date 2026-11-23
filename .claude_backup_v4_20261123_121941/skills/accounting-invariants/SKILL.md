---
name: accounting-invariants
description: Aturan keseimbangan jurnal akuntansi (Debit == Kredit), validasi periode tutup buku, dan pemetaan COA dinamis. Gunakan setiap kali menyentuh modul finance, invoice, payment, GRN, atau jurnal otomatis.
---

# Standar Integritas Akuntansi & Jurnal (`rab-project`)

1. **Keseimbangan Jurnal Mutlak (Double-Entry)**:
   - Sebelum mengeksekusi `COMMIT` pada transaksi yang membentuk jurnal umum/proyek, sistem WAJIB memvalidasi bahwa `ROUND(SUM(debit), 2) === ROUND(SUM(credit), 2)`. Tolak dan `ROLLBACK` jika selisih > 0.
2. **Validasi Periode Akuntansi (`accounting_periods`)**:
   - Setiap transaksi dengan `tanggal_transaksi` wajib memeriksa status `is_closed` pada tabel `accounting_periods`. Dilarang keras memposting atau membatalkan jurnal pada periode yang sudah tutup buku.
3. **Pemetaan Kode Akun (COA) Dinamis**:
   - Dilarang melakukan *hardcode* kode akun (misal `'1101.01'`) di dalam file PHP. Ambil pemetaan akun dari tabel konfigurasi/aturan jurnal proyek (`fiscal_config` atau tabel master aturan akun).
4. **Idempotensi Posting**:
   - Pastikan satu dokumen sumber (`ref_no` / `source_id` + `source_type`) tidak dapat diposting menjadi jurnal ganda saat tombol simpan diklik dua kali.
