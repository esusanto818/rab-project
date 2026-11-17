# RE-AUDIT LAPORAN — Business Gap Fase 3 (pilihan #1 + #5)

**Tanggal:** 2026-11-17 13:34 WIB
**Scope:** Re-audit otomatis pasca penerapan pilihan #1 (migrasi gelombang 1-4) & #5 (rule
`PRJ_DEP_APPLY`) dari `docs/BUSINESS_GAP_REPORT.md`.
**Target DB:** `proyekGMN_test` SAJA (bukan produksi `proyekGMN`).
**Runner:** `scripts/reaudit_fase3.php` (transaksi uji + ROLLBACK; tidak meninggalkan data).
**Hasil:** **PASS=38 / FAIL=0**.

---

## 1. Yang diterapkan (Fase 2)

- 13 file migrasi idempoten dari `sql/` dijalankan ke `proyekGMN_test`:
  `2026-11-14.project_invoice_release.sql`, `2026-11-15.menu_tagihan_klien_rename.sql`,
  `2026-11-15_patch_gelombang1_readiness_rab.sql`, `2026-11-15_patch_gelombang2_client_supplied.sql`,
  `2026-11-16.project_actual_cost_view.sql`, `20261115.project_invoice_retention.sql`,
  `20261115.project_payment_fx_and_allocation.sql`, `20261115.grn_approval_and_supplier_payment.sql`,
  `20261115.subcon_inspection_certification.sql`, `20261115.task_material_allocation.sql`,
  `20261115.year_close.sql`, `2026-11-12.ap_currency.sql`, `2026-11-12.subcon_and_leftover_upgrade.sql`.
- `sql/2026-11-14.project_invoice_bulk_release.sql` **DILEWATI** sesuai instruksi user
  (butuh parameter `rel_date/user_id` dan mengubah data, bukan skema).
- Rule `fiscal_config.PRJ_DEP_APPLY` di-upsert (Dr `2200` Uang Muka Penjualan / Cr `1200`
  Piutang Usaha, aktif) — menutup gap J-1/J-9 laporan Fase 1.

## 2. Hasil re-audit (Fase 3) — 38 cek

| Kelompok | Cek | Hasil |
|----------|-----|-------|
| Struktur AR | `project_invoices.retention_pct/retention_amount` | PASS |
| Struktur AR | `project_payments` FX + basis (`currency_id`, `exch_rate`, `total_amount_base`, `allocated_amount_base`, `excess_amount_base`, `deposit_applied_base`) | PASS |
| Struktur AP | `grn_hdr.approval_status/approved_by/approved_at/approved_note` | PASS |
| Struktur AP | `fk_spi_invoice` (supplier_payment_items → purchase_invoice_hdr) | PASS |
| Struktur RAB | `budget_items.material_source` (CLIENT_SUPPLIED), `budget_items.updated_at` | PASS |
| Tahun tutup | `fk_year_closing_journal`, `uq_year_closing_fiscal_year` | PASS |
| Subkon | `chk_subcon_cert_net_payable` (net = gross − PPN − PPh − retensi − DP − potongan) | PASS |
| GL | view `v_project_actual_cost` terbit | PASS |
| GL | enum `reference_type`: `material_leftover`, `subcon_invoice` | PASS |
| Guard DB | 9 trigger (alokasi termin ≤ saldo, alokasi PO ≤ total, alokasi supplier ≤ total, kategori material, qty SPK ≤ alokasi, sertifikat ≤ qty lulus, blokir posting periode tertutup ×2, touch updated_at) | PASS |
| Menu | `m_form_name` id 156 = "TAGIHAN KLIEN" | PASS |
| Periode | ≥1 periode hari ini open | PASS |
| **#5** | `PRJ_DEP_APPLY` Dr 2200 / Cr 1200 aktif | PASS |
| **#5** | `AutoPostJournal('PRJ_DEP_APPLY')` berhasil posting (uji coba, rollback) | PASS |
| **#5** | Jurnal uji: Dr 2200, Cr 1200, Dr==Kr==amount, bukan akun kas | PASS |
| Guard | trigger menolak alokasi pembayaran > saldo invoice (uji coba, rollback) | PASS |
| Integritas | semua jurnal historis Dr==Kr; tidak ada journal_entries yatim | PASS |

## 3. Sisa gap yang BELUM ditutup (butuh pilihan lanjutan)

- **Retensi klien (AR)**: kolom + guard DB sudah ada, tapi UI/form Tagihan Klien belum input
  `retention_pct` dan jurnal pelepasan retensi belum ada → pilihan #4.
- **Kartu Piutang / Aging Piutang** per klien → pilihan #2; **Kartu Hutang / Aging Hutang**
  per supplier → pilihan #3 (baru agregat dashboard).
- **GRN approval gate**: kolom + status ada, tapi aksi approve/reject di `grnCRD.php` + blokir
  invoice untuk GRN belum approve → pilihan #7.
- **PPh final / PPh 23 supplier biasa** (kolom + rule + bukti potong) → pilihan #6.
- **Penutupan periode akuntansi** (semua masih open) → pilihan #8.
- **Hygiene repo**: marker konflik merge di `views/supplier.payment.php:3`, tabel yatim
  `journal_entry_lines`/`general_ledger` → pilihan #10.
- **Test E2E jurnal AR/AP** → pilihan #11; **subledger control report** → #12; **laba rugi
  per proyek + neraca AP/AR** → #9.

## 4. Catatan

- `proyekGMN_test` sudah dipakai sesi tes sebelumnya (menu 156 sudah rename, 53 jurnal), sehingga
  migrasi idempoten hanya memverifikasi ulang — bukan indikasi kegagalan.
- Kedua runner memaksa `RAB_DB_NAME=proyekGMN_test` + verifikasi `current_database()`; produksi
  `proyekGMN` **tidak disentuh**.
- File yang dibuat sesi ini: `scripts/apply_migrations_wave1_4.php`,
  `scripts/reaudit_fase3.php`, `docs/BUSINESS_GAP_REAUDIT_2026-11-17.md` (file ini),
  + entri `AUDIT_CHANGELOG.md` & `docs/SESSION_LOG.md`.
