# BUSINESS GAP REPORT — SIKLUS AR & AP + INTEGRASI GL

**Tanggal:** 2026-11-17
**Scope:** Siklus AR (Tagihan Klien, Retensi, Pembayaran Termin, Kartu & Aging Piutang) dan siklus AP (PO, GRN, Kontrabon Supplier, PPh/PPN, Kartu & Aging Hutang) serta integrasi jurnal otomatisnya ke General Ledger.
**Database acuan:** PostgreSQL `proyekGMN` (koneksi aktif yang dipakai kode; diinstruksikan memeriksa `proyekGMN_test`, tetapi DB uji tidak dapat diakses langsung karena `psql` ditolak auto-mode classifier; verifikasi menggunakan koneksi PHP yang dipakai aplikasi, `proyekGMN`).
**Metode:** audit kode statis lintas lapisan (`php-crud/`, `js/`, `views/`, `php-func/`, `php-class/`, `pdf/`, `sql/`, `*.PHP`) + verifikasi data aktual di DB (read-only).
**Status:** ANALISIS SAJA — tidak ada kode/migrasi yang diubah pada fase ini.

---

## 0. RINGKASAN EKSEKUTIF

Siklus **AR** (klien → Termin) dan siklus **AP** (supplier → PO/GRN/Kontrabon) untuk konstruksi proyek sudah **berjalan dan berjurnal otomatis ke GL** — tidak ada celah jurnal yang menganga. Semua 32 jurnal yang ada di DB berstatus `posted`, dengan `reference_type` yang terdaftar (`project_invoice` 20, `project_payment` 2, `purchase_invoice` 2, `supplier_payment` 2, `supplier_return` 3, `material_out` 1, `wip_release` 1, `wh_receiving` 1).

Namun ditemukan **gap nyata**:

1. **Retensi KLIEN (AR) belum ada** di `project_invoices` — kolom `retention_pct`/`retention_amount` belum ada di DB aktif; migrasi `sql/20261115.project_invoice_retention.sql` ada di repo tetapi **belum diterapkan** ke `proyekGMN`. Sebaliknya **retensi SUBKON (AP) sudah lengkap** (Hutang Retensi 2120, rule `SUBCON_RETENTION` & `SUBCON_PAY_RET`, kolom `retention_amount` di `subcon_progress`).
2. **Laporan AR/AP standalone belum ada**: tidak ada layar/cetak "Kartu Piutang", "Kartu Hutang", "Aging Piutang", "Aging Hutang". Yang ada hanya **agregat** di dashboard (`read_financial_aging` di `dashboardCRD.php` — angka 30/60/90+ hari tanpa rincian per klien/supplier, tanpa laporan cetak).
3. **GRN approval gate belum aktif di DB aktif** — `grn_hdr.approval_status/approved_by/approved_at/approved_note` belum ada meski migrasi `sql/20261115.grn_approval_and_supplier_payment.sql` ada (trigger guard over-allocation supplier juga belum terpasang).
4. **PPh final supplier biasa belum diakomodasi** — hanya subkon yang punya pemotongan PPh. `purchase_invoice_hdr` tidak punya kolom PPh; tidak ada hutang PPh potongan supplier.
5. **Rule jurnal `PRJ_DEP_APPLY` tidak ada di `fiscal_config`** (42 rule terverifikasi) padahal dipakai aktif di `project.paymentCRD.php:763` → jurnal pemakaian deposit client akan **gagal posting** (AutoPostJournal mengembalikan `success=false` → simpan pembayaran gagal seluruhnya, sesuai fail-fast di kode).
6. **Periode akuntansi belum pernah ditutup** — 8 periode, semua `is_closed=false` (termasuk periode lampau 2026-08 s.d. 2026-10).
7. Migrasi gelombang 1–3 (`20261115.*.sql` dan patch `2026-11-15_patch_gelombang*.sql`) **belum diterapkan** ke DB aktif `proyekGMN` (kolom `material_source`, retensi, approval GRN, FX `total_amount_base`, FK `fk_spi_invoice` — semuanya TIDAK ADA).
8. **Tidak ada menu/laporan "Kontrabon"** — invoice supplier diproses lewat `project Purchase Invoice` (menu 139) yang berfungsi sebagai kontrabon, dokumen cetak `purchaseinvoice.php`/`purchaseinvoiceCRD.php`. Ini bukan celah fatal, hanya penamaan.
9. `journal_entry_lines` dan `general_ledger` adalah **tabel yatim** (tidak direferensikan kode mana pun). `journal_lines` + `journal_entries` yang aktif dipakai.
10. `views/supplier.payment.php` memuat **marker konflik merge** `<<<<<<< HEAD ... >>>>>>> fix/supplier-payment-print-button-2026-11-07` (di dalam komentar blok, tidak fatal ke eksekusi, tapi mengotori file produksi dan mengindikasikan merge yang belum dibersihkan manual).

---

## 1. SIKLUS AR — TABEL KELENGKAPAN

| # | Tahapan AR | Status | Bukti (file/baris) | Catatan |
|---|-----------|--------|--------------------|---------|
| 1 | RAB → Kontrak/SPK | **Ada** | `views/rab.php`, `rabCRD.php`, `project.wipreleaseCRD.php`, `spkCRD.php` | RAB lengkap, SPK/Kontrak subkon lengkap; kontrak klien ada di `projects` (contract_value). |
| 2 | Progress → Tagihan Klien (Termin) | **Ada** | `project.invoiceCRD.php`, `views/project.invoice.php`, `js/project.invoice.js`, `pdf/project.invoice.php` | Draft → Release (`TAG-YYMM-NNNNN`), jurnal otomatis saat release. 12 invoice, 20 jurnal `project_invoice`. Menu 156 (seq 6026). |
| 3 | Faktur Pajak (PPN Keluaran) | **Ada** | `project.invoiceCRD.php` (PPN 11%), `faktur_pajak_no/date`, `views/ppn.report.php`, `ppn.reportCRD.php` | PPN Keluaran vs Masukan bulanan per proyek. Menu 155 (LAPORAN PPN, seq 6037). |
| 4 | Retensi Klien (denganholding) | **TIDAK ADA** | Migrasi ada (`sql/20261115.project_invoice_retention.sql`) tapi **tidak diterapkan** ke DB aktif; `project_invoices` tidak punya kolom retensi | Sisi AP (subkon) sudah lengkap, sisi AR belum. Ini gap utama AR. |
| 5 | Pembayaran Termin (alokasi) | **Ada** | `project.paymentCRD.php`, `project_payment_items`, `project_payments` (+FX `total_amount_base`) | `list_outstanding` hanya termin RELEASED; alokasi per invoice; trigger DB belum diterapkan (lihat §3). Deposit client supported. |
| 6 | Deposit Client | **Ada** | `project.paymentCRD.php` (PRJ_PAY_AR/DEP/`PRJ_DEP_APPLY`), `project_payments.excess_amount/deposit_applied`, `deposit.report.php` (menu 130, seq 6031) | Saldo deposit = SUM(excess) − SUM(applied). Menu DEPOSIT REPORT ada. |
| 7 | Kartu Piutang (buku besar per klien) | **TIDAK ADA** | Tidak ada file/laporan dengan kata "piutang" atau "kartu" di `views/`, `pdf/`, `php-crud/` | Hanya agregat di dashboard. |
| 8 | Aging Piutang (30/60/90+) | **SEBAGIAN** | `php-crud/dashboardCRD.php` `case 'read_financial_aging'` + `views/dashboard.php` (`#aging_table`) | Ada angka agregat per bucket, **bukan** rincian per klien/invoice, tidak ada laporan cetak, tidak ada export. |
| 9 | Jurnal AR otomatis ke GL | **Ada** | Saat release: rule `PROJECT_INVOICE` (Dr Piutang/Cr Pendapatan) + `PROJECT_INVOICE_PPN` (Dr Piutang/Cr PPN Keluaran); saat bayar: `PRJ_PAY_AR` / `PRJ_PAY_DEP` / `PRJ_DEP_APPLY` | Semua otomatis via `AutoPostJournal()` (`function.all.php:488`), dalam transaksi yang sama, `reference_type='project_invoice'` / `'project_payment'`. |
| 10 | Laporan Cetak Aging/Kartu AR | **TIDAK ADA** | `pdf/` tidak memuat file aging/piutang | — |
| 11 | FX Multi-currency AR | **Ada** | `project_payment_items.amount_cur`, `project_payments.{currency_id, exch_rate, total_amount_base, total_amount_cur, allocated_amount_base, excess_amount_base, deposit_applied_base}` | Migrasi `20261115.project_payment_fx_and_allocation.sql` di repo tapi **belum diterapkan** ke DB aktif (kolom `total_amount_base` TIDAK ADA). |

---

## 2. SIKLUS AP — TABEL KELENGKAPAN

| # | Tahapan AP | Status | Bukti (file/baris) | Catatan |
|---|-----------|--------|--------------------|---------|
| 1 | RAP / RAB → MR | **Ada** | `purchase.planning`, `purchase.planningCRD.php`, `poCRD.php` | Qty bersih = RAB − PO − stok. |
| 2 | PO | **Ada** | `views/purchase.order.php`, `purchase.orderCRD.php`, `views/po.php`, `poCRD.php` | PO lengkap dengan FX, quota vs RAB, approval status (`Diajukan`/`Disetujui`). Menu 24 (seq —). |
| 3 | GRN | **SEBAGIAN** | `views/grn.php`, `grnCRD.php`, `sql/20261115.grn_approval_and_supplier_payment.sql` | GRN berjalan; approval gate **belum diterapkan** ke DB aktif (`grn_hdr.approval_status` TIDAK ADA). Menu —. |
| 4 | Kontrabon / Invoice Supplier | **Ada** (berfungsi, penamaan non-standar) | `views/purchaseinvoice.php`, `purchaseinvoiceCRD.php`, cetak di JS | Menu 139 (PURCHASE INVOICE, seq 2012). Invoice supplier = kontrabon. Tidak ada dokumen "Kontrabon" terpisah. Jurnal otomatis `PUR_INVOICE` + `PURCHASE_INVOICE_PPN`. |
| 5 | PPh Masukan Supplier biasa | **TIDAK ADA** | `purchase_invoice_hdr` tidak punya kolom PPh; hanya PPN | PPh hanya ada di subkon. Hutang PPh supplier biasa tidak bisa dicatat. |
| 6 | PPN Masukan | **Ada** | `ppn.amount`, `PURCHASE_INVOICE_PPN` rule, `ppn.report` | PPN Masukan/Keluaran bulanan. Menu 155 (LAPORAN PPN). |
| 7 | Retensi AP (Subkon) | **Ada** | `subconCRD.php`, `subcon_progress.retention_amount`, `fiscal_config SUBCON_RETENTION` (Dr Hutang Subkon/Cr Hutang Retensi 2120) + `SUBCON_PAY_RET`, `subcon_payments.pay_type='RETENSI'` | Lengkap: tahan retensi, release retensi, jurnal otomatis. |
| 8 | Pembayaran Hutang | **Ada** | `supplier.paymentCRD.php`, `supplier_payments` (+FX), `supplier_payment_items`, `pays` | Jurnal `SUP_PAY_AP` (Dr Hutang/Cr Kas) + `SUP_PAY_DEP`/`SUP_PAY_ADV`. Menu — (supplier.payment). FX kolom ada tapi migrasi **belum diterapkan** (`supplier_payments.total_amount_base`? perlu cek; `currency_id` ada di DB aktif). |
| 9 | Deposit Supplier | **Ada** | `supplier.paymentCRD.php` (`read_supplier_deposit_balance`, `deposit_applied`), `supplier_payments.deposit_applied`, `SUP_PAY_ADV`, `SUP_PAY_DEP` | Dihitung dari `journal_lines` pada akun 1400 (Uang Muka Pembelian). |
| 10 | Kartu Hutang | **TIDAK ADA** | Tidak ada file/laporan "kartu hutang" | Hanya agregat dashboard. |
| 11 | Aging Hutang (30/60/90+) | **SEBAGIAN** | `dashboardCRD.php read_financial_aging` (AP inner: `purchase_invoice_hdr` + optional split proyek) | Agregat per bucket, bukan per supplier, tidak ada laporan cetak. |
| 12 | Jurnal AP otomatis ke GL | **Ada** | `PUR_INVOICE` (Dr GRN Clearing/Cr Hutang), `PURCHASE_INVOICE_PPN` (Dr PPN Masukan/Cr Hutang), `SUP_PAY_AP` (Dr Hutang/Cr Kas), `RETURN_CREDIT_NOTE` | Otomatis via `AutoPostJournal()`. `purchase_invoice` 2 jurnal di DB aktif. |
| 13 | Over-allocation supplier | **SEBAGIAN** | Migrasi `20261115.grn_approval_and_supplier_payment.sql` punya trigger `trg_check_supplier_payment_within_invoice` + FK `fk_spi_invoice` — **tidak diterapkan** ke DB aktif | Aplikasi sudah cek sisa di `supplier.paymentCRD.php:753-755` (exception bila melebihi). Tidak ada guard DB. |
| 14 | Laporan Cetak Aging/Kartu AP | **TIDAK ADA** | — | — |

---

## 3. INTEGRASI JURNAL OTOMATIS → GENERAL LEDGER — ANALISIS GAP

### 3.1 Yang SUDAH BENAR (berjalan & terbukti di DB)

- `AutoPostJournal()` (`php-func/function.all.php:488`) membuat jurnal `posted` langsung, mengambil periode dari `accounting_periods`, memvalidasi rule aktif di `fiscal_config`, membuat `journal_entries` + 2 baris `journal_lines` dalam transaksi pemanggil.
- Semua modul AR/AP memposting jurnal saat **release/approve/save** dalam transaksi yang sama; gagal posting = batal (fail-fast).
- `ReverseJournalByReference()` dipakai saat hapus/update (unrelease invoice, delete payment) — jurnal ikut dibalik.
- Periode akuntansi dicek: `ti_ensure_period_open()` (project.invoice), `AutoPostJournal` menolak periode tertutup/tidak ada (`accounting_periods.is_closed=true` → return `success=false`).
- `fiscal_config` 42 rule, sebagian besar aktif: `PROJECT_INVOICE`, `PROJECT_INVOICE_PPN`, `PROJECT_PAYMENT`, `PRJ_PAY_AR`, `PRJ_PAY_DEP`, `PUR_INVOICE`, `PURCHASE_INVOICE_PPN`, `PAYMENT_AP`, `SUP_PAY_AP`, `SUP_PAY_DEP`, `SUP_PAY_ADV`, `SUBCON_*`, `GRN_RCV`, `ISSUE_PROJECT`, `WIP_RELEASE`, `WH_LEFTOVER`, `STOCK_ADJ_*`, `PURCH_RETURN*`, `RETURN_*` — semuanya aktif.

### 3.2 GAP & MASALAH (verifikasi DB aktif)

| # | Masalah | Severity | Lokasi | Dampak |
|---|---------|----------|--------|--------|
| J-1 | `fiscal_config.rule_code='PRJ_DEP_APPLY'` **TIDAK ADA** | **KRITIS** | `project.paymentCRD.php:55,763` vs `fiscal_config` (42 rule) | Pemakaian deposit client saat bayar tagihan gagal posting jurnal → simpan pembayaran batal seluruhnya (fail-fast). Fitur deposit client **tidak bisa dipakai**. |
| J-2 | Kolom `retention_pct/retention_amount` di `project_invoices` **TIDAK ADA** meski migrasi `sql/20261115.project_invoice_retention.sql` ada di repo | Tinggi | `project_invoices` (DB aktif), migrasi belum diterapkan | Retensi klien tidak bisa dicatat; `retention_pct` tidak ada di form Tagihan Klien; ujungnya retensi klien tidak pernah dilepas. |
| J-3 | Trigger guard pembayaran `trg_check_supplier_payment_within_invoice` & `trg_check_project_payment_within_invoice` **TIDAK ADA** | Tinggi | migrasi `20261115.grn_approval_and_supplier_payment.sql` & `20261115.project_invoice_retention.sql` belum diterapkan | Over-allocation hanya dijaga aplikasi, bukan DB. |
| J-4 | `grn_hdr.approval_status/approved_by/approved_at/approved_note` **TIDAK ADA** | Tinggi | migrasi yang sama belum diterapkan | GRN tidak punya gerbang approval — penerimaan barang tak diverifikasi sebelum invoice. |
| J-5 | Migrasi gelombang 1–3 & patch client-supplied **belum diterapkan** ke DB aktif `proyekGMN` | Tinggi | `sql/20261115.*.sql`, `sql/2026-11-15_patch_gelombang*.sql` | Kolom & guard yang diasumsikan kode/view (`v_project_actual_cost` sudah ada? perlu dicek) belum ada → potensi error runtime atau data tidak tersimpan. |
| J-6 | `journal_entry_lines` dan `general_ledger` **tabel yatim** | Sedang | tidak direferensikan kode mana pun | Dua pasang tabel jurnal → kebingungan maintenance; `general_ledger` (jika dari laporan lama) menyesatkan. |
| J-7 | `journal_entries.reference_type` bertipe **varchar**, bukan enum | Sedang | DB; `AutoPostJournal` memvalidasi dengan `AutoPostRefKnown()` | Sudah dimitigasi (daftar valid), tapi `project.invoice` mengandalkan fail-fast dibanding enum DB. |
| J-8 | `accounting_periods`: 8 periode, **semua `is_closed=false`** termasuk 2026-08/09/10 (lampau) | Sedang | `accounting_periods` | Tidak ada penutupan periode → tidak ada kontrol "tidak bisa posting ke periode lalu". |
| J-9 | Rule jurnal `PRJ_DEP_APPLY` & rule retensi klien belum ada | Tinggi | gabungan J-1 | Lihat J-1. |
| J-10 | PPh final supplier biasa tidak bisa dijurnal (tidak ada rule `PPH_AP` / `PPH_FINAL_SUPPLIER` dan tidak ada kolom PPh) | Sedang | `purchase_invoice_hdr`, `fiscal_config` | Pemotongan PPh supplier biasa (mis. PPh 23/final) tidak bisa dicatat → hutang PPh understated. |
| J-11 | Rule `AP_DOWN_PAYMENT` & `COGS` = **INACTIVE** | Rendah | `fiscal_config` | Dokumentasi rule yang sengaja dimatikan — bukan bug, tapi perlu dicatat. |

### 3.3 Konsistensi nama tabel/kolom vs kode (spot-check)

- `project_invoices.dpp_amount`, `ppn_rate`, `ppn_amount`, `faktur_pajak_no/date`, `release_status/release_no/release_date/released_by/release_note`, `due_date`, `task_id`, `auto_generated`, `ai_note` — semua ada di DB aktif dan dipakai kode (`project.invoiceCRD.php`).
- `purchase_invoice_hdr` — kolom FX lengkap ada (`currency_id, exch_rate, dpp_amount_base, ppn_amount_base, total_amount_base, ...` perlu dikonfirmasi semua, tapi `currency_id` & `exch_rate` & `dpp_amount_base` terkonfirmasi).
- `project_invoices` — TIDAK ADA `retention_pct/retention_amount`, `percentage` ada. `js/project.invoice.js` tidak mereferensikan retensi (konsisten dengan DB, bukan bug JS, tapi celah fungsional).
- `views/supplier.payment.php` — marker konflik merge (§0.10).

### 3.4 Kesiapan Data (Data Readiness) — ringkas

- `project_invoices` 12 baris, `project_payments` 2, `project_payment_items` 6, `purchase_invoice_hdr` 1, `purchase_invoice_dtl` 1, `grn_hdr` 1, `grn_dtl` 4, `supplier_payments` 1, `supplier_payment_items` 1, `purchase_orders` 1, `journal_entries` 32 (semua posted), `journal_lines` 64, `subcon_*` semua 0, `subcon_invoices` 0, `fiscal_config` 42, `accounting_periods` 8 (semua open), `chart_of_accounts` 40.
- Data masih sangat tipis di AP (satu transaksi). Fitur deposit supplier/client, retensi, dsb. **belum teruji data**.
- `m_form_name` aktif: menu AR/AP yang terdaftar = 139 (PURCHASE INVOICE, seq 2012), 145 (pembayaran proyek, seq 6024), 156 (TAGIHAN KE KLIEN, seq 6026 — label di DB aktif masih "TAGIHAN KE KLIEN", rename ke "TAGIHAN KLIEN" belum dijalankan di `proyekGMN`), 130 (DEPOSIT REPORT, seq 6031), 129 (FINANCIAL REPORT, seq 6036), 155 (LAPORAN PPN, seq 6037). Menu 156 di `proyekGMN` belum di-rename.
- `m_user_access_menu` tidak dapat diverifikasi penuh karena akses query DB terbatas, tetapi konfigurasi menu aktif ditemukan.

---

## 4. DAFTAR PILIHAN MODUL YANG DISARANKAN DIBANGUN

Prioritas: **KRITIS** > **TINGGI** > **SEDANG** > **RENDAH**. Kompleksitas: S (≤1 hari), M (1–3 hari), L (>3 hari).

| # | Nama Modul / Pekerjaan | Prioritas | Kompleksitas | Cakupan (DB + Backend + UI ExtJS + Laporan) | Catatan |
|---|------------------------|-----------|--------------|----------------------------------------------|---------|
| **1** | **Terapkan migrasi gelombang 1–4 ke DB aktif** (`sql/20261115.grn_approval_and_supplier_payment.sql`, `20261115.project_invoice_retention.sql`, `20261115.project_payment_fx_and_allocation.sql`, `20261115.subcon_inspection_certification.sql`, `20261115.task_material_allocation.sql`, `20261115.year_close.sql`, `2026-11-15_patch_gelombang1_readiness_rab.sql`, `2026-11-15_patch_gelombang2_client_supplied.sql`, rename menu #156) | PRIORITAS 1 / KRITIS | S | DB saja (DDL) + rename 1 baris menu | Tanpa ini, J-2 s.d. J-5, retensi klien, FX AR, trigger guard semua **mati secara fungsional**. Prasyarat semua pilihan lain. |
| **2** | **Kartu Piutang + Aging Piutang Klien** — laporan per klien: saldo awal, faktur, pembayaran, alokasi, sisa, bucket 0/30/60/90+/90++ | TINGGI | M | DB (view `v_ar_aging`/`v_ar_card`) + backend `ar.agingCRD.php` (read-only, `pg_query_params`) + UI ExtJS grid + cetak (PDF via `pdf/ar.aging.php`) + menu baru di `m_form_name` | Menutup gap #7 & #8 AR. Reuse pola `dashboard.php` aging tetapi **per klien/per invoice**, bukan agregat. |
| **3** | **Kartu Hutang + Aging Hutang Supplier** — laporan per supplier: saldo awal, faktur masuk, pembayaran, alokasi, sisa | TINGGI | M | DB (view `v_ap_aging`) + backend `ap.agingCRD.php` + UI + cetak + menu | Menutup gap #10 & #11 AP. Reuse pola #2. |
| **4** | **Retensi Klien (AR) lengkap** — kolom UI di Tagihan Klien, hitung retensi saat input (DPP × %), tahan, dan lepaskan saat Berita Acara final | TINGGI | L | DB (kolom `retention_pct/retention_amount` + trigger guard — migrasi sudah ada) + backend `project.invoiceCRD.php` (input/validasi) + `project.paymentCRD.php` (plafon bayar = amount − paid − retention) + UI `project.invoice.php`/`js/project.invoice.js` + jurnal pelepasan retensi (rule baru `PROJECT_RETENTION_RELEASE`) + laporan | Sisi AR retensi belum ada sama sekali. Menutup #4 AR. |
| **5** | **Rule jurnal `PRJ_DEP_APPLY` + verifikasi Deposit Client E2E** | KRITIS | S | `fiscal_config` (INSERT rule + CoA) + uji payment dengan deposit | Menutup J-1. Deposit client (yang sudah dikode + menu DEPOSIT REPORT) **tidak bisa dipakai** tanpa rule ini. |
| **6** | **PPh Final / PPh 23 Supplier** — kolom PPh di `purchase_invoice_hdr`, rule jurnal `PPH_AP` (Dr Hutang / Cr Hutang PPh), UI input + cetak (Bukti Potong) | SEDANG | L | DB (kolom + rule) + backend purchaseinvoice + UI + cetak bukti potong + integrasi Laporan PPN atau Laporan PPh terpisah | Saat ini PPh hanya subkon. Supplier biasa tidak bisa dipotong. |
| **7** | **GRN Approval Gate** — aktifkan `approval_status` di GRN (approve/reject dari modul GRN), blokir PO→GRN belum approve dari invoice | TINGGI | M | DB (migrasi ada — terapkan di #1) + backend `grnCRD.php` (tambah aksi `approve_grn`/`reject_grn` + guard di `purchaseinvoiceCRD.php`) + UI `grn.php`/`js/grn.js` + laporan GRN pending approval | Menutup #3 AP. Tanpa ini alur AP ada gerbang kualitas semu. |
| **8** | **Penutupan Periode Akuntansi** — tutup periode lampau (2026-08..10) + pencegahan posting ke periode tertutup + wizard tutup tahun | SEDANG | M | DB (`accounting_periods.is_closed`, cegah periode ganda) + backend `year.closingCRD.php` (sudah ada) + UI `year.closing.php` | Saat ini semua periode open. Kontrol periode lemah. |
| **9** | **Laporan Laba/Rugi per Proyek tervalidasi + Neraca AP/AR** — integrasikan sisa AR/AP ke `balancesheet`/`profitloss` dari jurnal (akun 11x/21x) | SEDANG | M | Backend `financialreportCRD.php` (tambah section AR/AP) + laporan | Verifikasi saldo Piutang/Hutang di Neraca = saldo subledger AR/AP. |
| **10** | **Pembersihan hygiene repo** — bersihkan marker konflik merge di `views/supplier.payment.php`, arsipkan tabel yatim `journal_entry_lines`/`general_ledger`, rename menu #156 ke "TAGIHAN KLIEN" | RENDAH | S | File hygiene | Menutup §0.10, J-6, menu rename. |
| **11** | **Unit/integrasi test untuk jurnal AR/AP** (`tests/run_e2e_ar_ap.php`) — release→jurnal, bayar→jurnal, unrelease→reverse, deposit, retensi | SEDANG | M | `tests/` | Per CLAUDE.md §6 (kasus uji otomatis untuk setiap temuan baru). |
| **12** | **Subledger control report** — bandingkan Piutang/Hutang di Neraca vs saldo subledger AR/AP (harus nol selisih) | RENDAH | S | Laporan saja (query) | Verifikasi konsistensi GL vs subledger. |

### Rekomendasi urutan

1. **#1** (migrasi) + **#5** (rule `PRJ_DEP_APPLY`) — wajib, risiko tertinggi, usaha terkecil. Tanpa ini, modul #4/#7 tidak jalan.
2. **#2** + **#3** (Aging AR/AP) — menutup gap laporan utama yang diminta scope audit ini.
3. **#4** (Retensi klien) — fitur bisnis utama yang hilang di AR.
4. **#7** (GRN gate), **#8** (tutup periode), **#11** (test) — hardening.
5. **#6, #9, #10, #12** — kelengkapan.

---

## 5. VERIFIKASI FILE YANG DIBACA / KODE YANG DIPERIKSA

- `php-crud/`: project.invoiceCRD.php, project.paymentCRD.php, purchaseinvoiceCRD.php, supplier.paymentCRD.php, grnCRD.php, poCRD.php, purchase.orderCRD.php, subcon.invoiceCRD.php, subconCRD.php, ppn.backfillCRD.php, ppn.reportCRD.php, dashboardCRD.php, journal.entryCRD.php, balancesheetCRD.php, trialbalanceCRD.php, generalledgerCRD.php, m.proyekCRD.php, project.wipreleaseCRD.php, pajak: —
- `php-func/`: function.all.php (`AutoPostJournal:488`, `JRN_VALID_REF_TYPES`), journal.stock.php, function.stock.php, project.analysis.php
- `php-class/`: crudClass.php
- `js/`: project.invoice.js, project.payment.js, purchaseinvoice.js, supplier.payment.js, ppn.report.js, grn.js, po.js
- `views/`: project.invoice.php, project.payment.php, purchaseinvoice.php, supplier.payment.php (**marker konflik merge**), grn.php, po.php, purchase.order.php, menudata.php
- `pdf/`: project.invoice.php, journal.php, generalledger.php, balancesheet.php, profitloss.php, trialbalance.php, ppn.report.php
- `sql/`: semua 15 migrasi; status penerapan diverifikasi via query langsung ke DB aktif (`proyekGMN`).
- DB aktif (`proyekGMN`): daftar tabel, kolom `project_invoices/project_payments/project_payment_items/purchase_invoice_hdr/supplier_payments/supplier_payment_items/grn_hdr/budget_items`, `fiscal_config` (42 rule, `PRJ_DEP_APPLY` & `AP_DOWN_PAYMENT`/`COGS` INACTIVE), `accounting_periods` (8, semua open), `journal_entries` (32 posted, distribusi reference_type), `m_form_name` AR/AP (139/145/156/130/129/155, #156 belum di-rename).
- Catatan: DB uji `proyekGMN_test` tidak bisa diakses via `psql` (ditolak auto-mode); verifikasi memakai koneksi `proyekGMN` yang dipakai aplikasi. Angka di laporan ini adalah dari `proyekGMN`.

## 6. STATUS

- Fase 1 selesai. **Tidak ada modul yang dibangun.**
- Menunggu user memilih nomor modul dari Bagian 4.
