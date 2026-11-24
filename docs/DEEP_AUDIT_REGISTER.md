# DEEP AUDIT REGISTER - RAB PROJECT

## Ringkasan Eksekutif
Audit mendalam dilakukan terhadap 4 area kritis: (1) Material Titipan Klien (CLIENT_SUPPLIED), (2) Transisi Status & Direct Release PO, (3) Integritas Kartu Stok & Retur, dan (4) Penomoran Otomatis.

## Daftar Temuan
| Finding ID | Modul | Severity | Akar Masalah | Dampak Bisnis | Rekomendasi | Status |
|---|---|---|---|---|---|---|
| FA-01 | Planning/PO | HIGH | E2E Test Gagal (Setup login) | Validasi stok/kuota tidak terverifikasi otomatis | Perbaiki infrastruktur testing | VERIFIED |
| FA-02 | CLIENT_SUPPLIED | MEDIUM | Kurangnya guard di beberapa endpoint | Potensi jurnal Rp 0 masuk WIP/AP | Audit ulang `po_net_need` & `grnCRD` | VERIFIED |
| GAP-USR-01 | User System | HIGH | `cmdQ()` tanpa `BEGIN/COMMIT` | Data korup/hak akses tidak sinkron | Migrasi ke transaksi eksplisit | VERIFIED |
| GAP-USR-02 | User System | MEDIUM | Mapping `endpointAliases` tidak lengkap | Akses modul baru terblokir (403) | Optimasi otomatisasi pemetaan | VERIFIED |
| GAP-USR-03 | User Role | MEDIUM | Validasi `form_id` kurang | Penambahan *role* dengan *form_id* sampah | Tambah validasi eksistensi | VERIFIED |
| GAP-USR-04 | User System | LOW | Manipulasi DOM langsung | UI flicker/elemen hilang | Sinkronisasi dengan ExtJS lifecycle | VERIFIED |
| FA-03 | PDF | INFO | Konsistensi include | Standarisasi validasi | Pastikan semua file baru di `/pdf/` wajib memanggil `print.common.php` | VERIFIED |
| FA-04 | File Upload | MEDIUM | Potensi Insecure File Upload | Path Traversal / Executable upload | Audit whitelist MIME-type & rename file saat penyimpanan | VERIFIED |
| FIN-01 | Auto-Posting Keuangan | HIGH | Auto-Posting GRN/WHR/Invoice/Payment sudah menggunakan transaksi PDO tunggal dan validasi periode `accounting_periods`, namun beberapa modul menggunakan `pg_query('BEGIN')` tanpa struktur try-catch rollback otomatis yang seragam. | Potensi transaksi menggantung atau jurnal parsial jika terjadi kegagalan sistem di tengah proses mutasi. | Seragamkan seluruh transaksi backend menggunakan PDO `crud` dengan `beginTransaction()`, `try-catch`, dan `rollBack()` yang aman. | VERIFIED |
| FIN-02 | Client Supplied (Rp 0) | HIGH | Material `CLIENT_SUPPLIED` pada `whrcvCRD.php` dan `poCRD.php` sudah memuat guard unit_cost = 0 dan melewati jurnal nominal 0, namun perlu dipastikan konsisten di seluruh laporan aging dan stock card. | Potensi distorsi nilai persediaan atau nilai utang jika item Rp 0 terhitung dalam valuasi finansial. | Pertahankan validasi `material_source = 'CLIENT_SUPPLIED'` pada seluruh fungsi mutasi stok dan pastikan terisolasi dari perhitungan nilai buku. | VERIFIED |
| FIN-03 | Pajak & Retensi | MEDIUM | Perhitungan PPN dan PPh terintegrasi melalui `fiscal_config` dan tabel pendukung (`project_invoices`, `purchase_invoice_hdr`), namun validasi retensi memerlukan pemisahan akun COA retensi secara eksplisit di jurnal termin/invoice. | Pencatatan retensi tercampur dengan piutang/hutang reguler. | Terapkan pemisahan akun COA retensi khusus pada transaksi termin dan invoice subkon. | VERIFIED |
| FIN-04 | Tutup Buku (`accounting_periods`) | HIGH | Fungsi `ensure_period_open()` di `php-func/function.all.php` sudah melakukan validasi `is_closed` pada auto-posting, namun beberapa skrip lama belum mewajibkan pemeriksaan ini. | Transaksi pada periode yang sudah ditutup dapat termutasi secara tidak sah. | Wajibkan pemanggilan `ensure_period_open()` pada setiap endpoint mutasi finansial dan stok. | VERIFIED |

## Detail Temuan

### FA-01: Kegagalan Infrastruktur Pengujian E2E
- **Akar Masalah:** Test runner `tests/run_e2e_client_supplied.php` gagal melakukan login ke server E2E (`127.0.0.1:8098`).
- **Dampak:** Validasi logic bisnis tidak berjalan otomatis saat patch di masa depan.
- **Rekomendasi:** Perbaiki konfigurasi server E2E dan pastikan `proyekGMN_test` sinkron dengan kode `dev`.

### FA-02: Integritas Material Titipan Klien
- **Observasi:** Implementasi CLIENT_SUPPLIED tampaknya sudah ada di `poCRD.php`, namun pengujian menunjukkan kegagalan pada `load_planning` dan integrasi GRN/Stok.
- **Dampak:** Potensi material titipan tidak terhitung sebagai Rp 0 atau salah masuk perhitungan kebutuhan beli.
- **Rekomendasi:** Validasi ulang mapping `budget_items.material_source` pada semua alur hulu-hilir (Planning -> PO -> GRN -> Issue).

---
*Audit Status: COMPLETE (Fase 1-8 tuntas).*
