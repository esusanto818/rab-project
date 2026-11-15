# AUDIT CHANGELOG

Log perubahan kode setiap sesi audit / perbaikan bug / perubahan kode.
Format: Tanggal & Waktu sesi | Path file lengkap | Ringkasan perubahan (baris/fungsi) & alasan | Status Git.

---

## Sesi 2026-11-15 — Cek Inventory sebelum Purchase Planning / Purchase Order

**Permintaan:** tambahkan pengecekan inventory sebelum membuat purchase planning; cek apakah
material di inventory tersedia, dengan catatan material tsb BUKAN milik proyek lain;
planningkan sesuai kebutuhan (kebutuhan bersih = sisa kuota RAB − stok tersedia).

**Model data yang dipakai:**
- Ledger stok: `stock_movements` (item_id = materials.id; saldo = IN − OUT via `view_stock_balance`).
- Kepemilikan proyek: `warehouses.project_id`. Stok yang dapat dipakai proyek = stok di
  gudang milik proyek itu + gudang bersama (`warehouses.project_id IS NULL`, mis. Gudang Pusat).
  Stok di gudang milik PROYEK LAIN TIDAK dihitung.

### File yang diubah

| Tanggal & Waktu | Path file | Ringkasan perubahan & alasan | Status Git |
|-----------------|-----------|------------------------------|------------|
| 2026-11-15 10:50 | `php-func/function.all.php` | Tambah `GetProjectStockMap($db, $projectId)` setelah `GetStockBalance()` (sekitar baris 699–727): saldo stok per material yang dapat dipakai proyek (gudang milik proyek + gudang bersama), dipakai bersama oleh purchase planning & PO. | Committed |
| 2026-11-15 10:55 | `php-crud/purchase.planningCRD.php` | 1) Header docblock: catatan fitur pengecekan inventory. 2) `load_planning`: tambah kolom `qty_on_hand` & `qty_to_buy` (sisa perlu beli − stok tersedia); stok material ganda di RAB hanya dipakai sekali. 3) Task baru `check_inventory`: validasi rencana beli (sisa kuota RAB + stok tersedia + stok tidak ditindih antarbaris). 4) Inisialisasi `$db = new crud()` untuk helper PDO. | Committed |
| 2026-11-15 11:05 | `php-crud/poCRD.php` | 1) Fungsi baru `po_project_stock_map()` & `po_net_need()` (setelah `po_received_total()`). 2) `list_rab_items`: tambah kolom `qty_on_hand` & `qty_to_buy` per baris RAB. 3) `save_po`: validasi kebutuhan bersih — qty PO melebihi `sisa kuota RAB − stok tersedia` ditolak (stok proyek lain tidak dihitung). | Committed |
| 2026-11-15 11:10 | `js/purchase.planning.js` | 1) Panel detail: tambah kolom "Stok Tersedia" & "Perlu Beli (Net)"; material stoknya sudah mencukupi ditandai "Cukup stok" & checkbox dinonaktifkan. 2) Default qty input = `qty_to_buy` (kebutuhan bersih), bukan sisa RAB penuh. 3) Tombol "Cek Inventory" memanggil task `check_inventory` dan menampilkan hasilnya. | Committed |
| 2026-11-15 11:15 | `js/po.js` | 1) Store `rabDs`: tambah field `qty_on_hand` & `qty_to_buy`. 2) Grid item: tambah kolom "Stok Tersedia" & "Perlu Beli". 3) Saat material dipilih, qty default = `qty_to_buy` (kebutuhan bersih). | Committed |
| 2026-11-15 11:20 | `tests/run_e2e_inventory_check.php` | **BARU**: runner E2E Kelompok F (TC-101 s/d TC-110) terhadap `proyekGMN_test` + web 8098. Fixture: proyek P1+P9, material M1/M2, gudang WH-A (milik P1, stok M1=40) & WH-B (milik P9, stok M1=500). Menguji: load_planning stok benar, stok lintas proyek tidak dipinjam, check_inventory OK/needs-buy/over-quota, stok tidak ditindih antarbaris, list_rab_items stok, save_po terima qty = kebutuhan bersih & tolak yang melebihi. | Committed |
| 2026-11-15 11:20 | `test_report_inventory_check.md` | **BARU**: laporan hasil eksekusi TC-101..TC-110 (PASS=10, FAIL=0). | Committed |
| 2026-11-15 11:25 | `docs/HOWTO_CHECK_INVENTORY_PURCHASE_PLANNING_2026-11-15.md` | **BARU**: dokumen How-To fitur (alur, aturan stok, cara pakai, catatan teknis). | Committed |

### Verifikasi
- `php -l` bersih untuk semua file PHP yang diubah.
- `php tests/run_e2e_inventory_check.php` → **PASS=10 FAIL=0** (laporan: `test_report_inventory_check.md`).
- `php tests/run_e2e_release.php` → **PASS=52 FAIL=0** (tidak ada regresi di layer release tagihan).
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0** (lintas lapisan tetap bersih).

### Daftar file aktual di disk (git status -s)
```
 M js/po.js
 M js/purchase.planning.js
 M php-crud/poCRD.php
 M php-crud/purchase.planningCRD.php
 M php-func/function.all.php
 M test_report_tc001_065.md      (hasil run suite release: timestamp + id invoice fixture, bukan perubahan kode)
?? test_report_inventory_check.md
?? tests/run_e2e_inventory_check.php
?? AUDIT_CHANGELOG.md
?? docs/HOWTO_CHECK_INVENTORY_PURCHASE_PLANNING_2026-11-15.md
```

## [2026-11-15] Pengecekan Purchase Order (PO) Sebelum Release
- **Status Git:** Committed
- **Ringkasan Perubahan:**
  - `php-crud/poCRD.php`: Menambahkan validasi real-time sebelum perubahan status (`change_status`) dalam transaksi ACID (`FOR UPDATE`), meliputi pengecekan ulang kebutuhan bersih stok proyek (`GetProjectStockMap` & `po_net_need`), kuota RAB aktual, integritas item PO, status aktif supplier, periode akuntansi terbuka, dan proteksi state machine anti double-release.
  - `js/po.js`: Menambahkan konfirmasi serta pengamanan tombol aksi perubahan status PO di UI ExtJS.
  - `tests/run_e2e_po_prerelease.php` & `test_report_po_prerelease.md`: Menambahkan 10 skenario uji E2E (`TC-201` s/d `TC-210`) dan lulus 100% (`PASS=10 FAIL=0`).
  - `docs/HOWTO_PO_PRERELEASE_CHECK_2026-11-15.md`: Dokumentasi panduan fitur pengecekan PO sebelum release.

## [2026-11-15] Tagihan Klien — Rename Menu, Watermark DRAFT di PDF, Cek Periode Akuntansi Saat Release

**Permintaan:** lanjutan rilis layer tagihan klien: (1) rename menu "TAGIHAN KE KLIEN" →
"TAGIHAN KLIEN", (2) watermark DRAFT sejati pada cetak PDF invoice belum release,
(3) tolak release bila periode akuntansi tanggal terbit/tanggal invoice sudah ditutup.

**Status Git:** Committed

**Ringkasan Perubahan:**

| Tanggal & Waktu | Path file | Ringkasan perubahan & alasan | Status Git |
|-----------------|-----------|------------------------------|------------|
| 2026-11-15 14:58 | `sql/2026-11-15.menu_tagihan_klien_rename.sql` | **BARU**: migrasi idempoten `UPDATE m_form_name SET title='TAGIHAN KLIEN' WHERE id=156 AND title='TAGIHAN KE KLIEN'`. id/seq/grup/php_url/js_url TIDAK berubah → rute lama tetap aman. | Committed |
| 2026-11-15 14:58 | `views/project.invoice.php` | `<title>` dan header `mainHeaderText`: "Tagihan ke Klien" → "Tagihan Klien"; catatan docblock rename. | Committed |
| 2026-11-15 14:58 | `js/project.invoice.js` | Header `mainHeaderText` = "Tagihan <em>Klien</em>"; catatan docblock rename. | Committed |
| 2026-11-15 14:58 | `pdf/project.invoice.php` | Watermark DRAFT sejati: bila `release_status <> 'RELEASED'`, injeksi CSS `body::before`/`body::after` bertumpuk "DRAFT" + "BUKAN DOKUMEN RESMI - TERBITKAN DULUAN (RELEASE) SEBELUM DISERAHKAN KE CLIENT" via `pr_open(..., $extraCss)`. Invoice RELEASED tanpa watermark. | Committed |
| 2026-11-15 14:58 | `php-crud/project.invoiceCRD.php` | Fungsi baru `ti_ensure_period_open($db, $journalDate, $invNo)`: cek `accounting_periods` (periode ada & `is_closed=false`) untuk tanggal terbit & tanggal invoice (NULL/'' dilewati aman) SEBELUM mutasi release dalam transaksi sama → gagal cek = release batal whole, tidak ada invoice released tanpa jurnal. `ti_release_one`: SELECT tambah kolom `invoice_date`. | Committed |
| 2026-11-15 14:58 | `tests/run_e2e_release.php` | Tambah TC-007 (rename menu: id 156/seq 6026/rute lama tak berubah, views+js ikut, label lama hilang), TC-008 (PDF DRAFT ada watermark `content: "DRAFT"` + "BUKAN DOKUMEN RESMI", RELEASED tanpa watermark), TC-009 (release ke periode tertutup ditolak; invoice tetap draft, tanpa `TAG-`, tanpa jurnal). Setup: migrasi rename menu di DB uji; cleanup: hapus periode uji "Uji Tertutup 2020". | Committed |
| 2026-11-15 14:58 | `test_report_tc001_065.md` | Hasil run suite release terkini: PASS=55 FAIL=0 (TC-001..TC-065 termasuk TC-007..TC-009). | Committed |
| 2026-11-15 14:58 | `docs/HOWTO_TAGIHAN_KLIEN_RELEASE_2026-11-15.md` | **BARU**: dokumen panduan fitur (rename menu, watermark DRAFT, cek periode akuntansi saat release, verifikasi otomatis, catatan operasional). | Committed |

**Verifikasi:**
- `php tests/run_e2e_release.php` → **PASS=55 FAIL=0**.
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0**.
- `php -l` bersih untuk semua file PHP yang diubah.

---

## Sesi 2026-11-15 17:26 — Audit Hulu-Hilir: verifikasi ulang kritis & laporan final

**Permintaan:** tulis dokumen laporan final `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` mencakup temuan kritis terverifikasi (SQL injection leftoverCRD, E1 delete RAB, E4 CHECK kategori, SPK subcon material supply, desain solusi Subcon Buy-Back / Leftover Return / Client-Supplied Rp 0).

**Catatan penting:** konteks sesi audit sebelumnya hilang saat `/clear`, sehingga setiap klaim dalam brief diverifikasi ulang langsung ke kode + DB (`pg_constraint`, `information_schema`, `pg_enum`, grep) sebelum ditulis. 3 dari 5 klaim brief **ditolak** karena tidak akurat seperti dinyatakan; temuan pengganti yang sah dicatat di §7b dokumen.

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-15 17:26 | `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` | **DITULIS ULANG** (full file, 290+ baris): (1) §0/§10 dikoreksi terhadap data verified — `journal_entries.reference_type` **varchar bukan enum** (koreksi atas asumsi sesi sebelumnya; akibatnya C2 bukan error 22P02 tapi silent-insert nilai tak dikenal), `fiscal_config` 42 rule (bukan 45), `projects.spent` ≠0 pada 4 proyek tapi tanpa write runtime; (2) §2.4 baru — `spk_material_supply`: CHECK kepemilikan **ada** di DB (`ck_spk_supply_owner` dst.), celah nyata = tanpa jalur serah-terima + `SpkMaterialQuota` tak melihat supply; (3) §7b baru — tabel penolakan 3 klaim brief (SQLi leftoverCRD: semua input (int)-cast, pola `cmdQ({$id})` hanya untuk variabel integer → F-2.3 hardening; E1 delete RAB: sudah ada trigger `fn_guard_budget_item_task_allocations` + `mpItemBlock`, celah sebenarnya di `save_rab` yang skip guard → F-5.2; E4: CHECK DB ada tapi mismatch `'Alat'` vs `'Peralatan'` + demote silent → F-5.3); (4) §9.1/9.2/9.3 desain solusi paling sederhana untuk Subcon Buy-Back (PO khusus `source='SUBCON_LEFTOVER'` + 2 rule fiscal_config), Leftover Return (daftarkan `material_leftover` ke referensi tipe yang dikenal), Client-Supplied Rp 0 (1 kolom `budget_items.material_source` + 3 perilaku: qty_to_buy=0, GRN paksa cost 0 skip jurnal, FIFO guard); (5) §9.4 urutan eksekusi patch. | Uncommitted |
| 2026-11-15 17:26 | `AUDIT_CHANGELOG.md` | Tambah entri sesi ini (wajib CLAUDE.md §5). | Uncommitted |

**Verifikasi:**
- Semua query read-only via `mcp__postgres__query`; tidak ada mutasi DB.
- `git status -s` → hanya `?? docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` (belum ada perubahan file lain).
