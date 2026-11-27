# Laporan Automated Risk Regression Test

- **Waktu Eksekusi:** 2026-11-27 09:30:07
- **Database Uji:** `proyekGMN_test`
- **Ringkasan:** `5 PASS` | `0 FAIL`

## Detail Hasil Pengujian
- **[PASS]** `[RISIKO-01]` Database target terisolasi di environment uji (proyekGMN_test)
- **[PASS]** `[RISIKO-02]` Seluruh 64 endpoint menolak akses tanpa otorisasi
- **[PASS]** `[RISIKO-03/08]` Concurrency test pada grnCRD.php: lolos 0/5 request paralel
- **[PASS]** `[RISIKO-12]` Upload guard pada project.progressCRD.php menolak payload PHP bermuatan palsu
- **[PASS]** `[RISIKO-DB]` Seluruh database views & koneksi PostgreSQL (proyekGMN_test) valid tanpa broken dependency
