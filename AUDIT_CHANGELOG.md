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

---

## Sesi 2026-11-15 19:49 — Patch Gelombang 1: Data Readiness & Leftover Return + Guard Hapus Item RAB

**Permintaan:** terapkan Patch Gelombang 1 (audit hulu-hilir §9): F-3.1 & F-5.1 (daftarkan `material_leftover`/`subcon_leftover_buyback` sebagai reference_type jurnal valid + rule `PRJ_DEP_APPLY` di fiscal_config), F-5.2 & F-5.3 (guard hapus item RAB di `save_rab` + samakan daftar kategori DB vs PHP secara case-insensitive tanpa demote diam-diam), lengkap dengan uji E2E, HOWTO, dan pelaporan.

**Koreksi asumsi audit:** `journal_entries.reference_type` ternyata bertipe **ENUM** `public.reference_type` (bukan varchar seperti dicatat §10 dokumen audit) → F-3.1 diselesaikan dengan `ALTER TYPE ... ADD VALUE` di migrasi, bukan sekadar daftar PHP.

### File yang diubah

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-15 19:49 | `sql/2026-11-15_patch_gelombang1_readiness_rab.sql` | **BARU**: migrasi idempoten. (0) `ALTER TYPE public.reference_type ADD VALUE` untuk `material_leftover`, `subcon_leftover_buyback`, `subcon_invoice`, `subcon_invoice_pay` di luar blok transaksi (kewajiban PG) + 2 nilai terakhir menutup celah `subcon.invoiceCRD.php` yang sudah pakai tapi belum ada di enum; (1) pastikan `PRJ_DEP_APPLY` aktif di `fiscal_config` (Dr 2200 / Cr 1200, `ON CONFLICT DO UPDATE` + DO-block verifikasi); (2a–2c) petakan `'Alat'→'Peralatan'` & ganti CHECK `budget_items_category_check` + tambah CHECK `expense_master_category_check` agar daftar kategori sama persis dengan `RAB_CATEGORIES` PHP; (2d) tambah kolom `budget_items.updated_at` + trigger `trg_budget_items_touch` (bug pra-eksisting: path UPDATE non-material di `save_rab` memakai kolom tak ada → SQLSTATE[42703]). | Committed |
| 2026-11-15 19:49 | `php-func/function.all.php` | Tambah konstanta `JRN_VALID_REF_TYPES` (daftar reference_type jurnal yang dikenal, termasuk `material_leftover` & `subcon_leftover_buyback`) + helper `AutoPostRefKnown()`; `AutoPostJournal()` menolak `reference_type` tak terdaftar via validasi `in_array` sebelum insert `journal_entries` (F-3.1). | Committed |
| 2026-11-15 19:49 | `php-func/journal.stock.php` | Tambah konstanta `JRN_REF_MATERIAL_LEFTOVER` / `JRN_REF_SUBCON_LEFTOVER_BUYBACK` + `JRN_VALID_REF_TYPES` + helper `JrnRefKnown()`; `JrnPost()` & `JrnReverseByRef()` menolak `reference_type` tak dikenal via `in_array` (F-3.1). | Committed |
| 2026-11-15 19:49 | `php-crud/rabCRD.php` | (F-5.2) fungsi baru `rabItemBlock()` (replika `mpItemBlock()` karena `rabCRD.php` tidak menyertakan `m.proyekCRD.php`), dipanggil `save_rab` untuk setiap `budget_item_id` lama yang hilang dari grid → hapus ditolak dengan pesan eksplisit bila item sudah dipakai PO/SPK/kontrak subkon/task expense/issue gudang; (F-5.3) fungsi baru `rabNormCategory()` normalisasi case-insensitive + penolakan kategori tak dikenal (mengganti 4x demote diam-diam ke `'Lain-lain'` di `load_rab` & 3 loop `save_rab`). | Committed |
| 2026-11-15 19:49 | `php-crud/m.proyekCRD.php` | `mpExpCategory()` menjadi case-insensitive + melempar Exception untuk kategori tak dikenal (F-5.3, daftar `MP_EXPENSE_CATEGORIES` sudah sama dengan `RAB_CATEGORIES`); `expense_master` mendapat CHECK DB yang setara. | Committed |
| 2026-11-15 19:49 | `tests/run_e2e_leftover_and_rab_guard.php` | **BARU**: runner E2E terhadap `proyekGMN_test` + web 8098. Setup idempoten menjalankan migrasi (enum + PRJ_DEP_APPLY + CHECK + updated_at/trigger), buat user harness level 4, proyek uji, issue material (fixture `material_out_header/items`), RAB + PO Draft 'Disetujui' pemakai budget item. TC-LGR-01..05 retur sisa material proyek (save → stok IN `MATERIAL_LEFTOVER` + jurnal WH_LEFTOVER Dr 23/Cr 47 → reverse → semua batal); TC-LGR-10/11 guard hapus RAB pemakai PO + rollback ACID; TC-LGR-12/13 kategori `'PERALATAN'` ternormalisasi menjadi `'Peralatan'`; TC-LGR-14 kategori tak dikenal ditolak eksplisit; TC-LGR-20 readiness PRJ_DEP_APPLY. | Committed |
| 2026-11-15 19:49 | `docs/HOWTO_LEFTOVER_AND_RAB_GUARD_2026-11-15.md` | **BARU**: panduan singkat — tabel sebelum/sesudah, cara jalankan migrasi & uji, catatan operasional (pesan guard, syarat reverse, cakupan F-2.1 buy-back belum termasuk, bonus updated_at). | Committed |
| 2026-11-15 19:49 | `AUDIT_CHANGELOG.md` | Tambah entri sesi ini (wajib CLAUDE.md §5). | Committed |

**Verifikasi (semua terhadap proyekGMN_test):**
- `php tests/run_e2e_leftover_and_rab_guard.php` → **PASS=11 FAIL=0**
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0**
- `php tests/run_e2e_release.php` → PASS=55 FAIL=0 · `run_e2e_inventory_check.php` → PASS=10 FAIL=0 · `run_e2e_po_prerelease.php` → PASS=10 FAIL=0
- `php -l` bersih pada semua file PHP yang diubah.

## [2026-11-16] Patch Gelombang 2: Material Titipan Klien (`CLIENT_SUPPLIED` Rp 0)
- **Status Git:** Committed
- **Ringkasan Perubahan:**
  - `sql/2026-11-15_patch_gelombang2_client_supplied.sql`: Penambahan kolom `material_source` (`COMPANY` | `CLIENT_SUPPLIED`) pada `public.budget_items`.
  - `php-crud/rabCRD.php` & `js/rab.js`: Dukungan simpan dan tampil sumber material pada RAB.
  - `php-crud/purchase.planningCRD.php`, `php-crud/poCRD.php`, `php-func/function.all.php`, `js/purchase.planning.js`, & `js/po.js`: Pengecualian otomatis `qty_to_buy = 0` dan pemblokiran PO untuk material titipan klien.
  - `php-crud/grnCRD.php`, `php-crud/whrcvCRD.php`, & `php-crud/warehouse.issuingCRD.php`: Penerimaan (`IN`) dan pengeluaran (`OUT`) fisik material titipan klien dipaksa `unit_cost = 0` tanpa posting jurnal rupiah.
  - `tests/run_e2e_client_supplied.php` & `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md`: Pengujian E2E lengkap (`FAIL=0`) serta panduan operasional.

---

## Sesi 2026-11-16 — Dokumentasi Fitur Material Titipan Klien (`CLIENT_SUPPLIED` Rp 0)

**Permintaan:** lengkapi/perbarui dokumen panduan `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` agar memenuhi standar dokumentasi (tujuan fitur & konteks bisnis, tabel before/after, langkah migrasi SQL idempoten, cara menjalankan uji E2E, catatan operasional, daftar file yang diubah) dan catat di AUDIT_CHANGELOG. Tanpa perubahan kode.

**Konteks:** dokumen versi awal (4 section singkat) diperluas menjadi 6 section lengkap mengacu pada `tests/run_e2e_client_supplied.php` (TC-CS-01..12), migrasi `sql/2026-11-15_patch_gelombang2_client_supplied.sql`, dan 11 file kode terkait.

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-16 00:30 | `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` | **DIPERBARUI**: perluasan dari 4 section menjadi 6 section — (1) Tujuan Fitur & Konteks Bisnis (material titipan klien, mengapa Rp 0); (2) Tabel perbandingan sebelum/sesudah (`COMPANY` vs `CLIENT_SUPPLIED`: `material_source`, `qty_to_buy`, PO, `unit_cost` GRN, jurnal `GRN_RCV`/`ISSUE_PROJECT`, catatan stok fisik); (3) Langkah migrasi SQL idempoten (ADD COLUMN default `COMPANY`, backfill, CHECK `budget_items_material_source_check`, verifikasi `DO $$`) sesuai file `sql/2026-11-15_patch_gelombang2_client_supplied.sql`; (4) Cara menjalankan `php tests/run_e2e_client_supplied.php` (ekspektasi PASS=12 FAIL=0) + rincian 12 kasus TC-CS-01..12; (5) Catatan operasional (FIFO guard, perilaku saat `material_source` diubah, batasan kategori `Material`, kesiapan migrasi, debug `qty_to_buy`); (6) Daftar file yang diubah/dibuat (tabel path + ringkasan, 11 file kode + migrasi + uji + dokumen). | Uncommitted |
| 2026-11-16 00:30 | `AUDIT_CHANGELOG.md` | Tambah entri sesi dokumentasi ini (wajib CLAUDE.md §5). | Uncommitted |

## Sesi 2026-11-16 — Verifikasi TC-CS-10 & Regresi 3 Suite E2E

**Permintaan:** perbaiki TC-10 di `tests/run_e2e_client_supplied.php` (dugaan salah nilai `reference_type` pada query `journal_entries`), hapus baris debug `[DBG-TC10]`, lalu jalankan 3 suite E2E hingga semua `FAIL=0`.

**Hasil investigasi (read-only DB `proyekGMN_test`):**
- `journal_entries.reference_type` bertipe **ENUM** `public.reference_type` (bukan varchar). Nilai valid untuk jurnal issue = `'material_out'` (lowercase), sesuai `JRN_REF_ISSUE` di `php-func/journal.stock.php:24`.
- `stock_movements.reference_type` bertipe **varchar**; nilai = `'MATERIAL_OUT'` (uppercase) dari `ISSUE_REF_TYPE` di `php-crud/warehouse.issuingCRD.php:28`.
- Query TC-CS-10 (baris 480) memakai `reference_type = 'material_out'` untuk `journal_entries` — **sudah benar**. Tidak perlu diubah ke `'MATERIAL_OUT'` (malah akan error ENUM invalid / count 0).
- Tidak ada baris `[DBG-TC10]` dan tidak ada `var_dump`/debug `echo` tersisa di `tests/run_e2e_client_supplied.php`.

**Kesimpulan:** tidak ada perubahan pada `tests/run_e2e_client_supplied.php` (premis brief salah: mengira kolom varchar+uppercase, padahal enum+lowercase). Tidak ada code produksi yang diubah.

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-16 | `tests/run_e2e_client_supplied.php` | Tidak diubah — verifikasi DB membuktikan query `reference_type = 'material_out'` sudah benar (ENUM, lowercase). | Uncommitted (tidak berubah) |
| 2026-11-16 | `AUDIT_CHANGELOG.md` | Tambah entri sesi verifikasi ini (wajib CLAUDE.md §5). | Uncommitted |

**Verifikasi (semua terhadap proyekGMN_test + web 127.0.0.1:8098):**
- `php tests/run_e2e_client_supplied.php` → **PASS=12 FAIL=0**
- `php tests/run_e2e_inventory_check.php` → **PASS=10 FAIL=0**
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0**

**Daftar file aktual di disk (git status -s):**
```
 M AUDIT_CHANGELOG.md
 M docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md
 M test_report_inventory_check.md
?? test_report_client_supplied.md
```

## Sesi 2026-11-16 — Penyelesaian Akhir Patch Gelombang 2 (delegasi sub-agen QA + Tech Writer)

**Permintaan:** delegasikan penyelesaian akhir Patch Gelombang 2 ke sub-agen `qa-test-engineer` dan `tech-writer`: (1) cek nilai valid enum `reference_type` pada `stock_movements`/`journal_entries`, perbaiki TC-10 serta hapus baris debug `[DBG-TC10]`, lalu jalankan 3 suite E2E hingga `FAIL=0`; (2) buat dokumen panduan `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` dan perbarui `AUDIT_CHANGELOG.md` (status `Uncommitted`); (3) tampilkan `php -l`, `git status -s`, `git diff --stat`. Jangan commit.

**Hasil:**
- `qa-test-engineer`: verifikasi DB membuktikan `journal_entries.reference_type` bertipe ENUM `public.reference_type` bernilai `'material_out'` (lowercase, sesuai `JRN_REF_ISSUE` di `php-func/journal.stock.php:24`); `stock_movements.reference_type` varchar bernilai `'MATERIAL_OUT'` (uppercase, dari `ISSUE_REF_TYPE` di `php-crud/warehouse.issuingCRD.php:28`). Query TC-CS-10 baris 480 **sudah benar** (`'material_out'`), premis brief salah. Tidak ada baris `[DBG-TC10]` / debug `echo` tersisa. Tidak ada perubahan code produksi.
- `tech-writer`: `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` diperluas (101 baris tambahan) — tabel sebelum/sesudah, migrasi SQL idempoten, cara jalankan E2E, catatan operasional, daftar file.
- `AUDIT_CHANGELOG.md` ditambah entri sesi verifikasi + entri sesi ini.

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-16 | `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` | Diperbarui: tambah §2 tabel before/after, §3 migrasi SQL, §4 cara jalankan E2E (TC-CS-01..12), §5 catatan operasional (FIFO guard, GRN/WH Rcv skip jurnal, pesan check_inventory, ubah material_source, batasan kategori), §6 daftar file. | Uncommitted |
| 2026-11-16 | `AUDIT_CHANGELOG.md` | Tambah entri sesi verifikasi TC-CS-10 + entri sesi penyelesaian akhir ini (wajib CLAUDE.md §5). | Uncommitted |
| 2026-11-16 | `test_report_client_supplied.md` | **BARU**: laporan cetak runner E2E CLIENT_SUPPLIED (PASS=12 FAIL=0). | Uncommitted |
| 2026-11-16 | `test_report_inventory_check.md` | Diperbarui: ringkasan hasil run suite inventory check (PASS=10 FAIL=0). | Uncommitted |

**Verifikasi (DB `proyekGMN_test` + web 127.0.0.1:8098):**
- `php tests/run_e2e_client_supplied.php` → **PASS=12 FAIL=0**
- `php tests/run_e2e_inventory_check.php` → **PASS=10 FAIL=0**
- `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0**
- `php -l` bersih pada `tests/run_e2e_client_supplied.php`, `tests/run_e2e_inventory_check.php`, `tests/run_e2e_audit_sync.php`, `php-crud/warehouse.issuingCRD.php`.

## Sesi 2026-11-16 15:52 WIB — Status Akhir Patch Gelombang 2 (Verifikasi Disk & Penutupan)

**Permintaan:** tutup kewajiban CLAUDE.md §5 — pastikan `AUDIT_CHANGELOG.md` mencatat status `Uncommitted` untuk 4 file yang berubah, dan buat/perbarui `docs/SESSION_LOG.md`. Pekerjaan dilakukan langsung di thread utama (tanpa sub-agen / background agent).

**Verifikasi disk aktual (`git status -s`):**
```
 M AUDIT_CHANGELOG.md
 M docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md
 M test_report_inventory_check.md
?? test_report_client_supplied.md
```

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-16 15:52 | `AUDIT_CHANGELOG.md` | Tambah blok status akhir sesi ini (daftar 4 file + verifikasi disk + catatan `ccsr.txt`). Entri-entri sebelumnya (Sesi Dokumentasi, Sesi Verifikasi TC-CS-10, Sesi Penyelesaian Akhir) sudah mencantumkan 4 file ini dengan status `Uncommitted`; blok ini menegaskan status akhir di disk. | Uncommitted |
| 2026-11-16 15:52 | `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` | Tidak diubah pada langkah ini — sudah diperbarui (6 section, tabel before/after, migrasi, cara jalankan E2E, catatan operasional) oleh pekerjaan tech-writer yang selesai dan terverifikasi di disk. | Uncommitted |
| 2026-11-16 15:52 | `test_report_client_supplied.md` | Tidak diubah pada langkah ini — laporan cetak hasil `run_e2e_client_supplied.php` (PASS=12 FAIL=0), sudah ada di disk sebagai file baru. | Uncommitted |
| 2026-11-16 15:52 | `test_report_inventory_check.md` | Tidak diubah pada langkah ini — tanggal eksekusi pada laporan diperbarui otomatis oleh runner (2026-11-16 07:10:21, PASS=10 FAIL=0). | Uncommitted |

**Catatan:**
- `?? ccsr.txt` (323 byte, berisi catatan label UI konfigurasi provider/OpenRouter) muncul sebagai file untracked dan **bukan** hasil pekerjaan sesi ini. Tidak diubah, tidak di-commit, tidak dihapus. Perlu konfirmasi pemilik file sebelum di-commit atau dihapus.
- Sub-agen `qa-test-engineer` menyerahkan laporannya: `journal_entries.reference_type` adalah ENUM (`'material_out'` lowercase), query TC-CS-10 baris 480 sudah benar — tidak ada perubahan code. Sub-agen `tech-writer` dihentikan oleh user, namun hasil kerjanya (HOWTO + entri changelog sesi dokumentasi) sudah ada di disk dan telah diperiksa ulang (isi dokumen dibaca penuh, konsisten).
- Tidak ada commit dibuat (sesuai instruksi "Jangan commit dulu").

**Verifikasi:** `php -l` bersih untuk `tests/run_e2e_client_supplied.php`, `tests/run_e2e_inventory_check.php`, `tests/run_e2e_audit_sync.php`, `php-crud/warehouse.issuingCRD.php`. E2E: client_supplied PASS=12 FAIL=0 · inventory_check PASS=10 FAIL=0 · audit_sync PASS=9 FAIL=0.

---

## Sesi 2026-11-17 — Patch Gelombang 3 Bagian Kedua: F-1.1 & F-1.2 (Progress vs Realisasi Biaya)

**Permintaan (pasted_content):** lanjutkan Patch Gelombang 3 bagian kedua — implementasi fitur **F-1.1 & F-1.2** (Kontrol Progress Proyek vs Realisasi Biaya): muat skill (`db-schema-map`, `erp-business-invariants`, `fast-scoped-patch`, `e2e-test-template`), cek `git status` + skema tabel `material_out_items`, tabel proyek (`projects`) dan RAB (`budget_items`) di `proyekGMN_test`, implementasikan validasi perbandingan HPP aktual vs RAB + % progress fisik pada `m.proyekCRD.php`, `project.analysis.php`, `project.wipreleaseCRD.php`, `projectprogressCRD.php`, `warehouse.issuingCRD.php`, lalu jalankan `tests/run_e2e_progress_cost_control.php` hingga FAIL=0 dan `php -l` bersih, dan terakhir buat panduan `docs/HOWTO_PROGRESS_COST_CONTROL_2026-11-16.md` serta perbarui `AUDIT_CHANGELOG.md` + `docs/SESSION_LOG.md` (nama file HOWTO sengaja memakai tanggal 2026-11-16 sesuai perintah; sesi kerja aktual berjalan 2026-11-17).

**Lingkup temuan audit yang dikerjakan:** `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` §7 A1 (progress bisa 100% tanpa material; progress di-set manual) & §7 A2 (HPP 3 rumus berbeda).

**Implementasi:**
- F-1.1 — `project.progressCRD.php`: **hard-block** klaim/verifikasi 100% bila `cost_material = 0` (belum ada issue material); **soft warning** bila klaim melampaui realisasi biaya >= 25% (gap). `projectprogressCRD.php` (mobile) memakai hard-block yang sama. `m.proyekCRD.php`: set manual `progress` khusus admin (FormAccess >= 4); nilai tidak berubah tetap boleh dikirim; non-admin saat create dipaksa `progress = 0`.
- F-1.2 — **satu rumus realisasi HPP**: view `public.v_project_actual_cost` = material issue posted (tidak reversed) + expense (status VOID/CANCELLED/CANCELED/BATAL/DIBATALKAN disaring) + prestasi subkon tersertifikasi APPROVED (fallback `subcon_progress.'Disetujui'` bila sertifikat belum ada). Helper `ProjectActualCost()` di `php-func/function.all.php` memakai view via PDO (prepared); dipakai oleh `prog_metrics()`, `dash_project_context()`, `wr_calc()`, gate issuing, dan `dashboardCRD.php` (profitability & health lewat LEFT JOIN view + fallback subquery). **Rumus pelepasan WIP sengaja tidak diubah** (tetap jurnal `material_out`); biaya terpadu hanya tampil sebagai pembanding/preview (`actual_cost`, `cost_released`, `cost_remaining_wip`).
- Gate issuing `validateMaterialOutAgainstProgress()`: plafon = budget terpadu (`budget × kurs`, fallback `SUM(budget_items)`) × progress% × 1.05; biaya terpakai dari view (`cost_total`), fallback ke material-only bila view belum dimigrasi. **Bug fix:** `sprintf` memakai `%%d%%`/`%%s` (angka tidak pernah tercetak) → `%d%%`.
- `php-func/project.analysis.php`: `dash_project_context()` memakai `ProjectActualCost()`; fallback rumus lama bila view belum ada; `$cs` tidak di-double-count (nilai subkon hanya diambil dari `$sub['tertagih_dpp']` ketika view tidak tersedia); filter status expense selaras dengan jalur lain.
- **Verifikasi `$snap_cost_base`**: klaim terverifikasi kini selalu menyimpan `snap_cost_base` = `cost_total` (teruji `> 0` setelah ada issue material: `1.000.000,00`).

| Tanggal & Waktu | Path file lengkap | Ringkasan perubahan & alasan | Status Git |
|-----------------|-------------------|------------------------------|------------|
| 2026-11-17 | `sql/2026-11-16.project_actual_cost_view.sql` | **BARU**: migrasi idempoten `CREATE OR REPLACE VIEW public.v_project_actual_cost` (+ `budget_base_raw`, `rab_budget`, `hpp_released`, `wip_remaining`, `cost_total`); blok `-- ROLLBACK / DOWN MIGRATION` di bagian bawah; dijalankan **hanya di proyekGMN_test** (bukan produksi). | Uncommitted |
| 2026-11-17 | `php-func/function.all.php` | Tambah `ProjectActualCost($db, $pid, ?float $progressOverride)` (setelah `CreateRefNoDb()`): satu sumber kebenaran realisasi proyek; `earned_value` dihitung dengan override progress saat verifikasi klaim; `cpi` hanya bila `cost_total > 0`; graceful `null` + `error_log` bila view belum ada. | Uncommitted |
| 2026-11-17 | `php-func/project.analysis.php` | `dash_project_context()`: biaya = `ProjectActualCost()` (fallback rumus lama bila view belum ada); `$issueWhere` dipindah ke scope umum (masih dipakai kueri RAB/`lastIssue`); `$sub['tertagih_dpp']` hanya dipakai bila view tidak ada (hindari dobel-hitung). | Uncommitted |
| 2026-11-17 | `php-crud/project.progressCRD.php` | `PROG_COST_LAG_WARN = 25`; `prog_metrics()` memakai view; `submit_claim` hard-block 100% tanpa issue + warning gap >= 25; `verify` mengulang hard-block 100% + snapshot `snap_cost_base` dari biaya terpadu; klaim 100% di `projectprogressCRD.php` (mobile) juga diblok saat issue Rp 0. | Uncommitted |
| 2026-11-17 | `php-crud/projectprogressCRD.php` | Hard-block laporan 100% bila `cost_material = 0` (selaras modul web). | Uncommitted |
| 2026-11-17 | `php-crud/project.wipreleaseCRD.php` | `wr_calc()` menambahkan konteks terpadu `actual_cost`, `actual_cost_material/expense/subcon`, `cost_released`, `cost_remaining_wip` pada preview (tidak mengubah dasar pelepasan WIP berbasis jurnal `material_out`). | Uncommitted |
| 2026-11-17 | `php-crud/warehouse.issuingCRD.php` | Gate progress: plafon dari budget terpadu + realisasi `cost_total` (bukan `SUM material` saja); fallback aman bila view tidak ada; perbaikan bug `sprintf` (angka tampil); header docblock F-1.2. | Uncommitted |
| 2026-11-17 | `php-crud/m.proyekCRD.php` | Set manual `progress`: admin (FormAccess >= 4) bebas; non-admin create dipaksa 0; non-admin update hanya boleh mengirim nilai yang sama dengan DB (selisih ditolak eksplisit). | Uncommitted |
| 2026-11-17 | `php-crud/dashboardCRD.php` | `read_project_profitability` & `read_project_health`: `LEFT JOIN public.v_project_actual_cost` (`ac`) dengan fallback subquery (`COALESCE`); filter status expense disamakan (`DIBATALKAN`). | Uncommitted |
| 2026-11-17 | `tests/run_e2e_progress_cost_control.php` | **BARU** runner E2E `TC-PC-01..13` (helper DB/HTTP sama pola template E2E). Awal gagal 4 (fixture issue diblokir plafon progress=0; `progress` awal 20; self-verify harus level 3 agar cabang self-verify tercapai; cleanup `reference_type` enum `MATERIAL_OUT` vs lowercase `material_out`; tabel `project_progress` tidak ada di DB uji; dashboard memakai `action`, bukan `task`; `$g` harus referensi). Dikoreksi; hasil: **PASS=13 FAIL=0**. | Uncommitted |
| 2026-11-17 | `test_report_progress_cost_control.md` | **BARU**: laporan otomatis hasil run TC-PC-01..13 (PASS=13 FAIL=0, date 2026-11-17). | Uncommitted |
| 2026-11-17 | `docs/HOWTO_PROGRESS_COST_CONTROL_2026-11-16.md` | **BARU**: panduan F-1.1/F-1.2 (perbaikan, migrasi, perintah uji, catatan operasional, nama file sesuai permintaan). | Uncommitted |
| 2026-11-17 | `AUDIT_CHANGELOG.md` | Entri sesi ini (wajib CLAUDE.md §5). | Uncommitted |
| 2026-11-17 | `docs/SESSION_LOG.md` | Entri sesi ini (wajib CLAUDE.md §5). | Uncommitted |
| 2026-11-17 | `test_report_inventory_check.md`, `test_report_po_prerelease.md`, `test_report_tc001_065.md` | **Tidak diubah kode** — laporan cetak hasil regresi (tanggal/ID fixture saja, bukan perubahan fitur). | Uncommitted |

**Verifikasi (proyekGMN_test + web 127.0.0.1:8098):**
- `php -l` bersih: `php-func/function.all.php`, `php-func/project.analysis.php`, `php-crud/m.proyekCRD.php`, `php-crud/project.progressCRD.php`, `php-crud/projectprogressCRD.php`, `php-crud/project.wipreleaseCRD.php`, `php-crud/warehouse.issuingCRD.php`, `php-crud/dashboardCRD.php`, `tests/run_e2e_progress_cost_control.php`.
- `php tests/run_e2e_progress_cost_control.php` → **PASS=13 FAIL=0** (laporan `test_report_progress_cost_control.md`).
- Regresi: `run_e2e_inventory_check.php` → **PASS=10**; `run_e2e_client_supplied.php` → **PASS=12**; `run_e2e_leftover_and_rab_guard.php` → **PASS=11**; `run_e2e_release.php` → **PASS=55**; `run_e2e_po_prerelease.php` → **PASS=10**.

**Daftar file aktual di disk (`git status -s`) — sesi ini & sisa Wave 2 + Wave 3:**
```
 M AUDIT_CHANGELOG.md
 M docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md
 M php-crud/dashboardCRD.php
 M php-crud/m.proyekCRD.php
 M php-crud/project.progressCRD.php
 M php-crud/project.wipreleaseCRD.php
 M php-crud/projectprogressCRD.php
 M php-crud/warehouse.issuingCRD.php
 M php-func/function.all.php
 M php-func/project.analysis.php
 M test_report_inventory_check.md
 M test_report_po_prerelease.md
 M test_report_tc001_065.md
?? ccsr.txt
?? docs/GUIDE_CCR_CLAUDE_CODE_SETUP.md
?? docs/HOWTO_PROGRESS_COST_CONTROL_2026-11-16.md
?? docs/SESSION_LOG.md
?? sql/2026-11-16.project_actual_cost_view.sql
?? test_report_client_supplied.md
?? test_report_progress_cost_control.md
?? tests/run_e2e_progress_cost_control.php
```
Catatan: tidak ada perubahan pada file produksi lain; `docs/GUIDE_CCR_CLAUDE_CODE_SETUP.md` & `ccsr.txt` **bukan** hasil pekerjaan sesi ini (muncul sebagai untracked). Tidak ada commit dibuat.

---

## Sesi 2026-11-17 (lanjutan) — Audit Grid Wrap: Tagihan Klien (menu id 156)

**Permintaan:** audit dan perbaiki HANYA halaman Tagihan Klien (`views/router?id=156`) beserta file JS dan CSS grid yang dipakai. Terapkan wrap text & penyesuaian lebar kolom agar header & isi sel (nomor invoice, tanggal, nominal DPP/PPN/Total/Sisa, status) terbaca utuh tanpa titik-titik (`...`). Jangan ubah modul lain. Simpan laporan ke `docs/AUDIT_GRID_WRAP_REPORT.md`.

**Implementasi (scope ketat Tagihan Klien):**

| File | Ringkasan perubahan | Status |
|---|---|---|
| `js/project.invoice.js` | **a.** `injectCss()`: override SCOPED `#divMainGrid .x-grid3-hd-inner/#divMainGrid .x-grid3-cell-inner` → `white-space:normal`, `overflow:visible`, `text-overflow:clip`, `word-wrap:break-word`, `word-break:break-word`; header `vertical-align:middle`, padding perbaikan. <br> **b.** `viewConfig.forceFit:true` → `forceFit:false` + `scrollOffset:0` agar grid scroll horizontal bila lebar kolom > panel, bukan memaksa kolom menyusut & memotong teks. <br> **c.** Lebar kolom disesuaikan (No. Invoice 110→130, Dokumen Tagihan 150→145, Tanggal 82→90, Jatuh Tempo 105→120, Client 150→160, Proyek 180→190, Termin 110→130, DPP 105→130, PPN 90→110, Total Tagihan 115→140, Terbayar 105→130, Sisa 105→130, Status 90→105). Alignment angka/teks dipertahankan. | Uncommitted |
| `views/project.invoice.php` | Tidak diubah (grid & CSS di-JS). | Uncommitted |

**Verifikasi:**
- `php -l views/project.invoice.php` bersih; `node --check js/project.invoice.js` lolos.
- Laporan audit: `docs/AUDIT_GRID_WRAP_REPORT.md` (ringkasan masalah, akar, perbaikan, verifikasi manual, temuan luar scope, status git).
- **CSS global TIDAK diubah** (`ext-all.css`, `grddetail.css`, `mainCSS.css` memakai 39+ view — override scoped via JS `injectCss()` scoped ke `#divMainGrid`).

**Daftar file aktual di disk (`git status -s`) — sesi grid-wrap (Wave 3 F-1.1/F-1.2 sudah di-commit di `31cfb68` & `ade1f35`):**
```
 M AUDIT_CHANGELOG.md
 M docs/SESSION_LOG.md
 M js/project.invoice.js
?? docs/AUDIT_GRID_WRAP_REPORT.md
```
Catatan: `CLAUDE.md`, `backups/`, `os`, `scripts/`, `ccsr.txt` muncul di `git status` tetapi **bukan** hasil pekerjaan sesi ini. Tidak ada commit baru dibuat oleh sesi ini.
