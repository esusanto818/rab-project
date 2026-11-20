# Panduan Lengkap Ekosistem Claude Code Agents (`proyekgmn-dev`)

Dokumen ini merupakan referensi operasional lengkap untuk seluruh **Sub-Agents**, **Custom Slash Commands**, dan **Domain Skills** pada proyek ERP Konstruksi `proyekgmn-dev` (PHP 7.4 Native, PostgreSQL, ExtJS 3.4.1).

## 1. Alur Kerja Utama (Lifecycle Pengembangan)
1. **Penemuan Kekurangan (`/business-gap`)** -> Memetakan celah alur bisnis ke `docs/BUSINESS_GAP_REPORT.md`.
2. **Eksekusi Cepat (`/tambal-semua-gap`)** -> Membangun seluruh *gap* berurutan lengkap dengan SQL migrasi, menu, dan *git commit*.
3. **Inspeksi Kualitas (`/audit-dan-tambal`)** -> Menguji keamanan, jurnal akuntansi, dan membuat regresi E2E di `proyekGMN_test`.
4. **Kesiapan Rilis (`/siap-deploy`)** -> Verifikasi akhir sebelum *merge* dari branch `dev` ke produksi.

## 2. Daftar Custom Slash Commands (What, When, How, Effect & Contoh)

### 1. `/business-gap`
* **What:** Memanggil `business-gap-analyst` untuk mengidentifikasi kekurangan fitur bisnis ERP konstruksi dan akuntansi.
* **When:** Di awal fase pengembangan atau setelah menyelesaikan satu gelombang modul besar.
* **How:** Memindai modul di `views/`, `js/`, dan `php-crud/`, lalu mencatat temuannya ke `docs/BUSINESS_GAP_REPORT.md` tanpa mengubah kode.
* **Effect:** Menghasilkan daftar prioritas *gap* bisnis. Kode dan database tetap bersih.
* **Contoh:** `/business-gap fokus pada siklus AR/AP, retensi subkon, dan PPh`

### 2. `/tambal-semua-gap`
* **What:** Mesin eksekusi *batch loop* otomatis untuk menuntaskan seluruh sisa daftar tugas di `docs/BUSINESS_GAP_REPORT.md`.
* **When:** Saat daftar *gap* sudah tercatat dan Anda ingin membangunnya satu per satu dengan cepat dan hemat RAM.
* **How:** Analisis skema -> tulis kode PHP 7.4 & ExtJS 3.4.1 -> daftar menu & migrasi SQL reversibel -> cek `php -l` -> dokumentasi ke `docs/` -> `git commit` per *gap* -> lanjut ke *gap* berikutnya.
* **Effect:** Seluruh fitur bisnis terbangun, terdaftar di menu, dan terkomit rapi per *gap* di branch `dev`.
* **Contoh:** `/tambal-semua-gap` atau `/tambal-semua-gap kerjakan maksimal 2 gap teratas dulu`

### 3. `/audit-dan-tambal`
* **What:** Audit mendalam lintas lapisan (keamanan, jurnal, database) sekaligus perbaikan otomatis dan pembuatan tes E2E.
* **When:** Setelah seluruh fitur/gap selesai dibangun, atau saat menemukan *bug* logika/jurnal yang kompleks.
* **How:** Memanggil `db-code-auditor`, `security-auditor-patcher`, `fullstack-patcher`, dan `qa-test-engineer` untuk memindai `.php`, `.PHP`, `.js`, memperbaiki celah, dan menjalankan skrip pengujian di `tests/` pada database `proyekGMN_test`.
* **Effect:** Aplikasi bebas celah SQL injection, *race condition*, dan salah hitung jurnal, serta memiliki bukti pengujian E2E otomatis.
* **Contoh:** `/audit-dan-tambal periksa modul purchaseinvoiceCRD.php dan jurnal PPH_SUPPLIER`

### 4. `/bangun-fitur`
* **What:** Merancang dan membangun satu fitur/modul baru dari nol (*end-to-end*).
* **When:** Saat ingin menambahkan fitur spesifik di luar daftar `BUSINESS_GAP_REPORT.md`.
* **How:** Memanggil `feature-architect-builder` untuk membuat migrasi SQL, endpoint `php-crud/`, UI `js/` & `views/`, serta mendaftarkan menu.
* **Effect:** Modul baru siap pakai lengkap dengan tabel database, UI ExtJS 3.4.1, dan menu aplikasi.
* **Contoh:** `/bangun-fitur Laporan Rekapitulasi Retensi Proyek beserta export PDF`

### 5. `/potong-modul`
* **What:** Memecah file kode monolitik (terlalu panjang) menjadi sub-modul kecil atau menggabungkan modul terpisah.
* **When:** Saat file `.js` atau `.php` sudah ribuan baris dan mulai berat dimuat.
* **How:** Memanggil `module-extractor-merger` untuk mengekstrak fungsi/panel terkait tanpa memutus referensi store/endpoint.
* **Effect:** Struktur kode lebih modular, ringan di-edit, dan bebas duplikasi.
* **Contoh:** `/potong-modul pisahkan tab detail pembayaran dari js/purchase.invoice.js`

### 6. `/audit-security`
* **What:** Pemindaian khusus kerentanan keamanan backend dan sesi (`pg_query_params`, validasi sesi, sanitasi input).
* **When:** Sebelum rilis ke produksi atau setelah menambah endpoint AJAX baru.
* **Effect:** Semua query rentan SQL injection dan endpoint tanpa proteksi sesi langsung ditambal.
* **Contoh:** `/audit-security pindai seluruh file di folder php-crud/ dan php-func/`

### 7. `/audit-jurnal`
* **What:** Verifikasi integritas aturan akuntansi, keseimbangan Debit/Kredit, dan pemetaan `fiscal_config`.
* **When:** Setiap kali menambah transaksi keuangan baru (Invoice, Kas/Bank, Retensi, PPh, Tutup Periode).
* **Effect:** Menjamin neraca dan buku besar selalu seimbang dan tidak ada transaksi masuk ke periode tertutup.
* **Contoh:** `/audit-jurnal cek aturan jurnal GRN Approval Gate dan pelunasan hutang supplier`

### 8. `/audit-grid-wrap`
* **What:** Memeriksa dan memperbaiki tampilan teks terpotong pada `Ext.grid.GridPanel` ExtJS 3.4.1.
* **When:** Saat kolom keterangan/nama barang di tabel UI terpotong.
* **Effect:** Teks panjang di dalam tabel otomatis turun baris (*word-wrap*) dengan rapi.
* **Contoh:** `/audit-grid-wrap periksa grid pada modul Purchase Order dan GRN`

### 9. `/update-theme`
* **What:** Memperbarui skema warna antarmuka aplikasi berdasarkan palet warna yang ditentukan.
* **When:** Saat ingin menyesuaikan tampilan CSS/tema ExtJS sesuai identitas warna perusahaan.
* **Effect:** Tampilan seluruh form, header, dan tombol berubah seragam sesuai palet warna baru.
* **Contoh:** `/update-theme terapkan kombinasi warna utama #800020 dan aksen #F59E0B`

### 10. `/audit-fitur`
* **What:** Pemeriksaan menyeluruh pada satu fitur spesifik (UI, Backend, dan Database sekaligus).
* **When:** Saat pengguna melaporkan *bug* pada satu menu tertentu.
* **Effect:** Laporan akar masalah lengkap beserta perbaikan langsung pada fitur tersebut.
* **Contoh:** `/audit-fitur modul Cash & Bank Payment`

### 11. `/catat-sesi`
* **What:** Merangkum progres kerja sesi berjalan ke dalam `docs/SESSION_LOG.md` dan `docs/AUDIT_CHANGELOG.md`.
* **When:** Sebelum menutup terminal atau saat konteks percakapan sudah mulai penuh.
* **Effect:** Sesi berikutnya bisa langsung melanjutkan pekerjaan tanpa kehilangan konteks.
* **Contoh:** `/catat-sesi`

### 12. `/siap-deploy`
* **What:** Daftar periksa akhir (*pre-flight check*) sebelum memindahkan perubahan ke server produksi.
* **When:** Setelah semua fitur lolos uji di branch `dev` dan database `proyekGMN_test`.
* **Effect:** Menghasilkan ringkasan rilis dan skrip migrasi siap jalan untuk database produksi `proyekGMN`.
* **Contoh:** `/siap-deploy`

## 3. Daftar 10 Sub-Agents & 7 Skills
* **Agents:** `business-gap-analyst`, `feature-architect-builder`, `fullstack-patcher`, `db-code-auditor`, `security-auditor-patcher`, `qa-test-engineer`, `tech-writer`, `grid-wrap-auditor`, `theme-styler-auditor`, `module-extractor-merger`.
* **Skills:** `db-schema-map`, `accounting-invariants`, `erp-business-invariants`, `extjs-341-patterns`, `fast-scoped-patch`, `e2e-test-template`, `git-checkpoint-workflow`.
