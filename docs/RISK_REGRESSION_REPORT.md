# Laporan Automated Risk Regression Test

- **Waktu Eksekusi:** 2026-11-27 12:09:41
- **Database Uji:** `proyekGMN_test`
- **Ringkasan:** `7 PASS` | `0 FAIL`

## Detail Hasil Pengujian
- **[PASS]** `[RISIKO-01]` Database target terisolasi di environment uji (proyekGMN_test)
- **[PASS]** `[RISIKO-02]` Seluruh 64 endpoint menolak akses tanpa otorisasi
- **[PASS]** `[RISIKO-02B]` Simulasi login sesi (_audit_bot_staff) aktif & aksi admin lintas role ditolak (Anti Privilege Escalation)
- **[PASS]** `[RISIKO-03/08]` Concurrency test pada grnCRD.php: lolos 0/5 request paralel
- **[PASS]** `[RISIKO-12]` Upload guard pada project.progressCRD.php menolak payload PHP bermuatan palsu
- **[PASS]** `[RISIKO-DB-01]` Seluruh database views PostgreSQL (proyekGMN_test) valid tanpa broken dependency
- **[PASS]** `[RISIKO-DB-02]` Audit saldo jurnal (Debit = Credit) & invarian stok kumulatif non-negatif valid (0 anomali)
