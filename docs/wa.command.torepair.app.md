layar projet progress amobile... 3 button di bawah... dasboard-stock card...lancar....masalah di layar stockcard, dashboard button error... akses di tolak: anda tidak punya hak di modul ini...
dimana salahnya... button di project progress lancar semua... analisa dan perbaiki


complex:
Lakukan audit mendalam dan perbaikan (patching) khusus pada modul **Purchase Order (`js/po.js` dan `php-crud/poCRD.php`)** di `proyekgmn-dev` untuk mengatasi masalah **data detail item PO tidak tampil (kosong)** saat baris PO di grid atas diklik atau dibuka.

Patuhi seluruh aturan di `CLAUDE.md` (PHP Native, ExtJS 3.4.1, PostgreSQL `proyekGMN`, penggunaan `pg_query_params` / prepared statement, transaksi `BEGIN/COMMIT/ROLLBACK`, dan penulisan dokumentasi di `/home/myadmin/proyekgmn-dev/docs`). Kerjakan langkah-langkah berikut secara berurutan:

### 1. Diagnosa Akar Masalah (Frontend ExtJS `js/po.js` vs Backend `php-crud/poCRD.php` vs Database `proyekGMN`)
1. **Periksa Event & Store Detail PO di `js/po.js`:**
   - Cek event `rowclick` / `selectionchange` pada grid header PO (`poGrid` / `sm.on('rowselect', ...)`). Pastikan parameter ID PO (`po_id` atau `id`) serta parameter `task` / `action` terkirim dengan nama yang tepat saat memanggil `poItemStore.load(...)`.
   - Periksa konfigurasi `Ext.data.JsonReader` pada store detail item PO (`root`, `totalProperty`, `idProperty`, dan daftar `fields`) apakah 100% cocok dengan struktur JSON yang dikembalikan oleh `php-crud/poCRD.php`.
   - Periksa juga apakah ada `renderer` kolom atau `hidden: true` atau tinggi panel grid detail (`height` / `region: 'south'`) yang membuat baris data tidak terlihat meskipun JSON sudah masuk.
2. **Periksa Query & Response JSON di `php-crud/poCRD.php`:**
   - Periksa `case` / `task` pembacaan detail item PO (seperti `read_items`, `load_items`, atau `read_po_items`).
   - Cek apakah query `SELECT ... FROM po_items` gagal karena ada kolom yang belum ada di tabel `po_items` / `budget_items` / `materials` pada database `proyekGMN`, atau karena filter `WHERE po_id = ...` menangkap nama parameter `$_POST` / `$_GET` yang salah (`po_id` vs `id`).
   - Uji langsung eksekusi query dan output JSON `php-crud/poCRD.php` via CLI PHP menggunakan data PO yang ada di database `proyekGMN` (misalnya `PO-2610-00001`).
3. **Cek Alur Simpan & Edit PO (`save` / `update`):**
   - Pastikan juga saat PO baru disimpan atau di-edit, baris detail di `po_items` benar-benar tersimpan dengan `po_id` yang valid (tidak yatim/NULL) sehingga muncul kembali saat PO dipilih.

### 2. Terapkan Patching & Verifikasi
1. Terapkan perbaikan pada `php-crud/poCRD.php` dan/atau `js/po.js` (serta jalankan migrasi SQL idempoten jika ditemukan kolom `po_items` yang kurang di database `proyekGMN`).
2. Pastikan `php -l php-crud/poCRD.php` menghasilkan **0 error** dan uji ulang lewat CLI PHP untuk memastikan JSON respons berisi array `data` / `rows` item PO yang lengkap.

### 3. Dokumentasi, Git Commit & Notifikasi Hermes
1. Buat laporan analisis penyebab dan detail patch di `/home/myadmin/proyekgmn-dev/docs/2026-11-22_PO_DETAIL_ITEM_PATCH.md`, serta perbarui `docs/SESSION_LOG.md` dan `AUDIT_CHANGELOG.md`.
2. Lakukan `git add` dan `git commit` pada branch `dev` dengan pesan commit `fix(po): restore missing PO detail items loading and rendering`, lalu kirim ringkasan via Hermes WhatsApp setelah selesai.


simple:
Audit dan patch modul Purchase Order (`js/po.js` dan `php-crud/poCRD.php`) karena grid detail item PO kosong/tidak tampil saat baris PO diklik.

Lakukan secara berurutan:
1. Cek `rowselect`/`rowclick` & `JsonReader` di `js/po.js` vs endpoint detail item di `php-crud/poCRD.php` (pastikan nama parameter `po_id`/`id`, `task`, `root`, dan `fields` cocok 100%).
2. Uji query SQL detail `po_items` langsung ke DB `proyekGMN` via CLI PHP (cek jika ada kolom belum termigrasi atau `WHERE po_id` salah tangkap parameter).
3. Patch `js/po.js` dan/atau `php-crud/poCRD.php` sampai JSON detail PO keluar lengkap, lalu cek `php -l`.
4. Simpan laporan ke `/home/myadmin/proyekgmn-dev/docs/2026-11-22_PO_DETAIL_PATCH.md`, update `docs/SESSION_LOG.md` & `AUDIT_CHANGELOG.md`, lalu `git commit` ke branch `dev`.
 
 1 baris:
 Audit dan patch `js/po.js` serta `php-crud/poCRD.php` agar detail item PO muncul saat baris PO diklik sekaligus valid saat simpan/edit PO baru (cek `rowselect`/`JsonReader`, `po_id`, query `SELECT`/`INSERT`/`UPDATE` `po_items` ke DB `proyekGMN`), verifikasi `php -l`, dokumentasikan ke `/home/myadmin/proyekgmn-dev/docs/2026-11-22_PO_DETAIL_PATCH.md`, lalu `git commit` di branch `dev`.

