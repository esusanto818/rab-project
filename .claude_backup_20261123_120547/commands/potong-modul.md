Gunakan sub-agent `module-extractor-merger` untuk memotong modul secara utuh (termasuk seluruh sub-modul, laporan/cetak PDF/Excel, combo lookup, dan objek DB terkait) dari proyek sumber ke proyek tujuan.

Parameter dari user: $ARGUMENTS

Langkah Eksekusi:
1. **Validasi Target:** Pastikan path proyek sumber, nama modul target, dan path proyek tujuan jelas. Jika belum lengkap, tanyakan secara ringkas terlebih dahulu.
2. **Inventarisasi "Tangan & Jari" (Full Dependency & Report Scan):**
   - Daftar semua file UI (`views/`, `js/`), backend (`php-crud/`), dan **seluruh file laporan/cetak/export** (`reports/`, `print/`, `pdf/`, `excel/`, dll) yang terhubung dengan modul.
   - Daftar semua `Ext.data.Store` / *lookup combo* yang dipanggil oleh layar maupun filter laporan.
   - Daftar semua tabel, sequence, **VIEW**, dan **FUNCTION/TRIGGER** PostgreSQL yang dipakai modul & laporannya.
3. **Eksekusi Pemotongan & Penyalinan:**
   - Jalankan **Mode A (`init`)** jika proyek tujuan masih kosong, atau **Mode B (`inject`)** jika proyek tujuan sudah memiliki kerangka dasar.
4. **Generasi SQL & Laporan Hasil Bedah:**
   - Simpan skema/migrasi SQL ke `<proyek_tujuan>/sql/`.
   - Simpan dokumentasi lengkap hasil ekstraksi ke `<proyek_tujuan>/docs/MODULE_EXTRACTION_REPORT.md`.
