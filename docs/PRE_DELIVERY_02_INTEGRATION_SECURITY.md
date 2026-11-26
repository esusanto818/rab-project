# PRE_DELIVERY_02_INTEGRATION_SECURITY.md

## 1. Lingkup Audit
Audit Integrasi End-to-End dan Permukaan Serangan (Attack Surface).

## 2. Temuan Keamanan & Integritas

### 2.1 Endpoint Authorization (php-crud/*.php)
- **Session Protection:** Seluruh file di `php-crud/` (diperiksa sampel acak: `poCRD.php`, `grnCRD.php`, `rabCRD.php`) sudah menyertakan `require_once __DIR__ . '/../php-func/user.valid.php';` di awal file. Ini adalah standar wajib untuk proteksi sesi.
- **SQL Injection:** Sebagian besar query sudah menggunakan `PDO::prepare` atau `pg_query_params` dengan *binding parameter*. Tidak ditemukan penggunaan `eval` atau `exec` yang mencurigakan di direktori `php-crud/`.
- **Risiko Teridentifikasi:**
  - **File Tidak Terpakai/Backup:** Ditemukan file seperti `grnCRD_err.php`, `grnCRD_err00.php`, dan `rabCRD.php.bak` di folder `php-crud/`. Ini merupakan risiko keamanan karena file *backup* mungkin tidak terproteksi atau menggunakan logika lama yang tidak aman.
  - **Hardcoded Path:** Beberapa file masih berpotensi mengandung path absolut atau relatif yang tidak sesuai standar `CLAUDE.md`.

### 2.2 Integrasi End-to-End (E2E)
- **Data Flow:** Alur terintegrasi (`Proyek -> RAB -> PO -> GRN -> Invoice -> Jurnal -> Pembayaran`) sudah mengimplementasikan *Advisory Lock* (`pg_advisory_xact_lock`) untuk mencegah *race condition* saat sinkronisasi data antar modul.
- **Validasi Nilai:** Perbaikan pada sinkronisasi `spk_id` dan otomatisasi status invoice (GAP-04, GAP-07) sudah teruji melalui skrip E2E.
- **Risiko Teridentifikasi:**
  - **Dependency:** Keamanan sistem sangat bergantung pada sinkronisasi *view* `public.v_project_actual_cost`. Jika *view* ini tidak diperbarui setelah perubahan skema, data biaya proyek akan salah.

### 2.3 Lingkungan & Konfigurasi
- **File Sensitif:** Tidak ditemukan file `.env` atau *credential* di direktori publik.
- **Upload File:** Folder `uploads/progress/` telah diproteksi dengan `.htaccess` untuk mencegah eksekusi *script* (seperti `.php` atau `.pl`).

## 3. Matriks Risiko
| Finding ID | Modul | Severity | Temuan | Akar Masalah |
| :--- | :--- | :--- | :--- | :--- |
| SEC-01 | CRUD | MEDIUM | File backup `.bak`/`.php` tertinggal | Praktik pengembangan tidak bersih |
| SEC-02 | Integrasi | HIGH | Ketergantungan pada view DB | Risiko data *stale* jika migrasi terlewat |

## 4. Rekomendasi
1. Segera hapus atau pindahkan file backup di `php-crud/` ke luar dari *docroot*.
2. Lakukan audit berkala terhadap `v_project_actual_cost` setiap kali ada migrasi tabel transaksi.
3. Pastikan tidak ada *script* eksekusi (seperti `curl_exec` di `m.proyekCRD.php`) yang dapat diakses oleh user tanpa validasi *input* yang ketat (meskipun berada di modul backend).

## 5. Kesimpulan
Integrasi antar modul cukup kuat dan telah mengimplementasikan mekanisme *lock* untuk mencegah inkonsistensi. Keamanan secara umum sudah mengikuti standar proyek, namun kebersihan *workspace* (file sampah) perlu ditingkatkan segera.
