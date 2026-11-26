# PRE_DELIVERY_01E_FINANCE_ACCOUNTING.md

## 1. Lingkup Audit
Audit Keuangan, Akuntansi/GL, Jurnal Otomatis, Pajak, AR/AP, dan Pembayaran.
- File audit utama: `php-crud/journal.entryCRD.php`, `php-crud/project.invoiceCRD.php`, `php-crud/purchaseinvoiceCRD.php`, `php-func/function.all.php`.

## 2. Pemeriksaan Keamanan & Integritas Akuntansi

### 2.1 Jurnal Otomatis & Konsistensi
- **AutoPostJournal:** Fungsi `AutoPostJournal` di `function.all.php` bertindak sebagai *hub* terpusat untuk pembuatan jurnal otomatis. Penggunaan PDO (`$db`) memastikan transaksi ACID tetap terjaga (header dan baris jurnal di-commit bersamaan).
- **Integritas:** Jurnal dibuat sebagai status `posted` secara otomatis untuk transaksi yang tervalidasi di modul sumber. Pembatalan menggunakan fungsi `ReverseJournalByReference` yang membuat baris pembalik (koreksi), bukan menghapus sejarah.
- **Keseimbangan:** Terdapat validasi `totalDebit == totalCredit` di `saveJournal` (`journal.entryCRD.php`).

### 2.2 Pajak (PPN/PPh)
- **PPN:** PPN dihitung berdasarkan mode PO (EXCL/INCL/NONE) dan tarif yang sesuai. Patch `F-3.1` dan `F-4.1` memastikan pajak dihitung berbasis DPP (Dasar Pengenaan Pajak).
- **Validasi:** Terdapat `PI_PPN_TOLERANCE` untuk memvalidasi input PPN dari faktur supplier agar tidak berbeda signifikan dengan hitungan sistem.

### 2.3 AR/AP & Pembayaran
- **Project Invoice (AR):** Invoice terbagi menjadi `DRAFT` dan `RELEASED`. Hanya invoice `RELEASED` yang memicu jurnal penagihan dan muncul di modul Pembayaran.
- **Supplier Invoice (AP):** Invoice supplier dijurnal saat diposting. Validasi ketat dilakukan terhadap matching GRN dan potongan retur supplier.
- **Keseimbangan Kurs:** Penggunaan `exch_rate` dari GRN untuk perhitungan nilai dasar (base currency) pada jurnal invoice supplier memastikan konsistensi mata uang.

## 3. Temuan & Catatan
- **Integritas:** Mekanisme jurnal sudah cukup matang dengan penggunaan *advisory lock* untuk penomoran jurnal (`JournalNextRefNo`) yang mencegah nomor ganda saat akses konkuren.
- **Pencegahan Double-Posting:** `ti_has_journal` dan `ReverseJournalByReference` memastikan jurnal hanya terbentuk sekali per transaksi (idempotensi).
- **Status Baseline:** Implementasi jurnal akuntansi sudah memenuhi standar *double-entry* dan *audit trail* yang baik.

## 4. Kesimpulan Tahap 1E
- Audit menunjukkan integritas data keuangan terjaga dengan baik melalui sinkronisasi transaksi sumber dan jurnal otomatis.
- Tidak ada indikasi *double-posting* yang berbahaya karena adanya pengecekan status jurnal dan *referensi* dokumen.
- Status: **Audit Modul Selesai (1A–1E)**.
