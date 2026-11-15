# How-To: Menu "TAGIHAN KLIEN" — Rename, Watermark DRAFT & Cek Periode Akuntansi Saat Release

Dokumen ini menjelaskan tiga perubahan pada modul **Tagihan Klien** (invoice termin proyek)
yang dirilis pada **2026-11-15**: penyesuaian penamaan menu, watermark DRAFT sejati pada
cetak PDF, dan pengecekan periode akuntansi terbuka sebelum release. Backend:
`php-crud/project.invoiceCRD.php`, cetak: `pdf/project.invoice.php`, depan:
`views/project.invoice.php` + `js/project.invoice.js`.

## 1. Rename Menu "TAGIHAN KE KLIEN" → "TAGIHAN KLIEN"

- Migrasi: `sql/2026-11-15.menu_tagihan_klien_rename.sql` (idempoten — `UPDATE` berbasis title
  lama; bila title sudah "TAGIHAN KLIEN" tidak ada baris yang terupdate).
- Yang **TIDAK** berubah: id menu `156`, `seq` `6026`, grup `75` (TRANSACTION), `php_url`
  (`project.invoice.php`) dan `js_url` (`project.invoice.js`). Rute lama tetap aman; bookmark
  atau tautan lama tetap berfungsi.
- Label depan ikut diperbarui: `<title>` di `views/project.invoice.php` dan header
  `mainHeaderText` di `js/project.invoice.js` ("Tagihan <em>Klien</em>").
- Jalankan di DB uji `proyekGMN_test` (dan DB produksi saat rilis):

  ```
  psql -U postgres -d proyekGMN_test -v ON_ERROR_STOP=1 -f sql/2026-11-15.menu_tagihan_klien_rename.sql
  ```

## 2. Watermark DRAFT pada Cetak PDF (`pdf/project.invoice.php`)

- Invoice berstatus **DRAFT** (belum release) dicetak dengan tumpukan besar miring buram
  "DRAFT" + keterangan "BUKAN DOKUMEN RESMI - TERBITKAN DULUAN (RELEASE) SEBELUM DISERAHKAN
  KE CLIENT" di seluruh halaman (`body::before` / `body::after`, warna `rgba(179,84,30,0.28)`),
  bukan cuma baris status di header.
- Invoice **RELEASED** tidak membawa watermark — dokumen yang diserahkan ke client adalah
  yang sudah diterbitkan (ber-nomor `TAG-...`).
- CSS diinjeksikan via parameter `$extraCss` pada `pr_open(...)`; `print.common.php` tidak
  berubah, modul cetak lain tidak terdampak.

## 3. Cek Periode Akuntansi Terbuka Sebelum Release (`ti_ensure_period_open`)

- `ti_release_one()` kini memeriksa `accounting_periods` untuk **tanggal terbit**
  (`release_date`) dan **tanggal invoice** (`invoice_date`, bila terisi) SEBELUM mutasi
  release, dalam transaksi yang sama.
- Gagal cek → `TiError` → seluruh release dibatalkan (tidak ada invoice `released` tanpa
  jurnal penagihan):
  - Periode tidak ditemukan: "Tidak ada periode akuntansi yang mencakup tanggal ... Buat
    periode-nya dulu di menu Fiscal Period."
  - Periode ditutup: "Periode akuntansi untuk tanggal ... sudah ditutup. Invoice ... tidak
    dapat diterbitkan."
- `invoice_date` NULL/'' (data tidak lazim) dilewati dengan aman — tidak ada
  `CAST('' AS date)` yang error; cukup tanggal terbit yang diperiksa.

## 4. Verifikasi Otomatis

- Skrip uji E2E: `tests/run_e2e_release.php` — kasus baru **TC-007** (rename menu),
  **TC-008** (watermark DRAFT di PDF, RELEASED tanpa watermark), **TC-009** (release ke
  periode tertutup ditolak, invoice tetap draft, tanpa `TAG-`, tanpa jurnal).
- Hasil saat ini: `PASS=55 FAIL=0` (laporan: `test_report_tc001_065.md`).
- Audit lintas lapisan: `tests/run_e2e_audit_sync.php` — `PASS=9 FAIL=0`.
- Ulangi bila diperlukan:

  ```
  php tests/run_e2e_release.php
  php tests/run_e2e_audit_sync.php
  ```

## 5. Catatan Operasional

- Urutan saat rilis: jalankan migrasi SQL rename menu **sebelum** deploy file `views/` + `js/`
  agar label di DB dan label di layar konsisten (perubahan ini tidak merusak rute, sehingga
  urutan longgar tetap aman berkat idempotensi).
- Bila ada invoice DRAFT yang dicetak sebelum perubahan ini, cetak ulang untuk mendapat
  watermark; bila RELEASED, tidak perlu cetak ulang.
- Periode akuntansi baru harus dibuat lebih dulu di menu Fiscal Period sebelum ada release
  invoice di periode tersebut — pesan error akan mengarahkan ke menu itu.
