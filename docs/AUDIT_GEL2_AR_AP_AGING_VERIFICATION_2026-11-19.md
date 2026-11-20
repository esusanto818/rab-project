# VERIFIKASI AUDIT GELONGANG 2 — Kartu Piutang & Aging AR + Kartu Hutang & Aging AP

**Tanggal:** 2026-11-19
**Scope:** Verifikasi implementasi pilihan #2 (Kartu Piutang + Aging Piutang Klien) dan #3 (Kartu Hutang + Aging Hutang Supplier) dari `docs/BUSINESS_GAP_REPORT.md`
**Target DB:** `proyekGMN_test` (hanya database uji)

---

## 1. RINGKASAN EKSEKUTIF

✅ **MIGRASI DITERAPKAN**: File `sql/2026-11-17.ar_ap_aging_card.sql` berhasil dijalankan ke `proyekGMN_test`
- 4 view dibuat: `v_ar_aging`, `v_ar_card`, `v_ap_aging`, `v_ap_card`
- 2 menu baru terdaftar di `m_form_name`:
  - Seq 6038: "KARTU PIUTANG & AGING" → `ar.aging.php` / `ar.aging.js`
  - Seq 6039: "KARTU HUTANG & AGING" → `ap.aging.php` / `ap.aging.js`
- 6 baris akses menu (level 4) dibuat untuk user yang punya akses ke LAPORAN PPN (form 155)

✅ **BACKEND & FRONTEND SUDAH ADA** (dibuat sesi sebelumnya):
- `php-crud/ar.agingCRD.php` — task: `list_clients`, `list_cards`, `list_details`, `export_csv`
- `php-crud/ap.agingCRD.php` — task: `list_suppliers`, `list_cards`, `list_details`, `export_csv`
- `js/ar.aging.js` — Tab Kartu Piutang (periode bulanan) + Aging Detail (per faktur), filter, export CSV
- `js/ap.aging.js` — Tab Kartu Hutang (periode bulanan) + Aging Detail (per invoice), filter, export CSV
- `views/ar.aging.php`, `views/ap.aging.php` — shell view dengan header & include JS
- Semua file lulus `php -l` dan `node --check`

✅ **QUERY VERIFIKASI BERHASIL**:
- `v_ar_aging`: 4 baris (4 invoice released PT Karya Mandiri, outstanding Rp 15.000.000 di bucket 31-60)
- `v_ar_card`: 1 baris (PT Karya Mandiri, saldo awal Rp 15.000.000)
- `v_ap_aging`: 1 baris (invoice BAPAK KAREL, outstanding 0)
- `v_ap_card`: 1 baris (BAPAK KAREL, saldo awal Rp 274.336.500, sisa 0)

---

## 2. DETAIL VERIFIKASI TEKNIS

### 2.1 Database Views (`proyekGMN_test`)

| View | Row Count | Catatan |
|------|-----------|---------|
| `v_ar_aging` | 4 | 4 invoice released, 1 outstanding Rp 15M (INV-2610-00002, age 35 hari → bucket 31-60) |
| `v_ar_card` | 1 | PT Karya Mandiri: saldo_awal=15M, faktur=0, pembayaran=0, alokasi=0, sisa=15M, bucket_31_60=15M |
| `v_ap_aging` | 1 | BAPAK KAREL: PINV-2610-00001, outstanding=0, age=44 hari |
| `v_ap_card` | 1 | BAPAK KAREL: saldo_awal=274.3M, faktur=0, pembayaran=275M, alokasi=274.3M, sisa=0 |

### 2.2 Menu & Akses

| Seq | Title | php_url | js_url | Form ID | User Access (level 4) |
|-----|-------|---------|--------|---------|------------------------|
| 6038 | KARTU PIUTANG & AGING | ar.aging.php | ar.aging.js | 165 | user_id: 1, 83, 89 |
| 6039 | KARTU HUTANG & AGING | ap.aging.php | ap.aging.js | 166 | user_id: 1, 83, 89 |

### 2.3 Backend Query Testing

**AR - list_cards (Nov 2026)**:
```json
{
  "client_id": 2,
  "client_name": "PT Karya Mandiri",
  "period_start": "2026-11-01",
  "period_end": "2026-12-01",
  "saldo_awal": 15000000.00,
  "faktur": 0,
  "pembayaran": 0,
  "alokasi": 0,
  "sisa": 15000000.00,
  "invoice_count": 4,
  "bucket_31_60": 15000000.00,
  "outstanding_all": 15000000.00
}
```

**AR - list_details (Oct-Nov 2026, PT Karya Mandiri)**:
| Invoice | Project | Termin | Amount | Paid | Outstanding | Age | Bucket 31-60 |
|---------|---------|--------|--------|------|-------------|-----|--------------|
| INV-2610-00001 | Gedung Wisma A | DP-1 | 37.5M | 37.5M | 0 | 35 | 0 |
| INV-2610-00002 | Gudang Logistik Sidoarjo | DP-1 | 315M | 300M | **15M** | 35 | **15M** |
| INV-2610-00003 | Gudang MERR | DP-1 | 37.5M | 37.5M | 0 | 35 | 0 |
| INV-2610-00004 | Renovasi Gedung Kantor Pusat | DP1 | 375M | 375M | 0 | 35 | 0 |

**AP - list_cards (Nov 2026)**:
```json
{
  "supplier_id": 126,
  "supplier_name": "BAPAK KAREL",
  "period_start": "2026-11-01",
  "period_end": "2026-12-01",
  "saldo_awal": 274336500.00,
  "faktur": 0,
  "pembayaran": 275000000.00,
  "alokasi": 274336500.00,
  "sisa": 0.00,
  "invoice_count": 1,
  "outstanding_all": 0.00
}
```

**AP - list_details (Oct-Nov 2026, BAPAK KAREL)**:
| Invoice | Supplier Invoice | Status | Amount | Paid | Outstanding | Age |
|---------|------------------|--------|--------|------|-------------|-----|
| PINV-2610-00001 | INV-KRL-001 | posted | 274.3M | 274.3M | 0 | 44 |

---

## 3. KELENGKAPAN FILE (Sudah Ada di Disk)

| File | Status | Catatan |
|------|--------|---------|
| `sql/2026-11-17.ar_ap_aging_card.sql` | ✅ Ada | Migrasi idempoten (CREATE OR REPLACE VIEW + DO block menu/akses) |
| `views/ar.aging.php` | ✅ Ada | Shell view, include ar.aging.js |
| `views/ap.aging.php` | ✅ Ada | Shell view, include ap.aging.js |
| `js/ar.aging.js` | ✅ Ada | Tab Cards/Details, filter bulan/tahun & rentang tanggal, export CSV, scoped grid-wrap CSS |
| `js/ap.aging.js` | ✅ Ada | Tab Cards/Details, filter bulan/tahun & rentang tanggal, export CSV, scoped grid-wrap CSS |
| `php-crud/ar.agingCRD.php` | ✅ Ada | Prepared statements, error handling, totals, export CSV |
| `php-crud/ap.agingCRD.php` | ✅ Ada | Prepared statements, error handling, totals, export CSV |

---

## 4. CATATAN OPERASIONAL & BATASAN SAAT INI

1. **Data AR masih minim** — Hanya 1 client (PT Karya Mandiri) punya invoice released. 8 invoice lain masih `draft`.
2. **Data AP minim** — Hanya 1 supplier (BAPAK KAREL) punya 1 invoice.
3. **Period kartu = bulan berjalan** — `v_ar_card`/`v_ap_card` hardcoded `date_trunc('month', CURRENT_DATE)`. Backend `ar.agingCRD.php`/`ap.agingCRD.php` menghitung ulang untuk periode bebas via parameter `year`/`month`.
4. **Aging anchor = CURRENT_DATE** — Bucket aging dihitung vs hari ini, bukan vs akhir periode.
5. **Export CSV** — Menggunakan `sep=;` untuk kompatibilitas Excel Indonesia (desimal koma).
6. **Grid-wrap CSS** — Sudah diterapkan scoped ke `#divMainGrid` (konsisten dengan Audit Grid Wrap gelombang sebelumnya).

---

## 5. STATUS GIT (SEBELUM COMMIT)

```
 M AUDIT_CHANGELOG.md
 D CLAUDE.md
 M docs/HOWTO_AGENTS_GUIDE.md
 M docs/SESSION_LOG.md
?? .claudeignore
?? CLAUDE.md.bak
?? audit-gemini-request.txt
?? backups/
?? ccr.recov.txt
?? ccr.recov.zip
?? ccsr.txt
?? docs/AUDIT_GEL2_AR_AP_AGING_VERIFICATION_2026-11-19.md  <-- FILE INI
?? docs/BUSINESS_GAP_REPORT.md
?? docs/BUSINESS_GAP_REAUDIT_2026-11-17.md
?? fix.ccr.sh
?? js/ap.aging.js
?? js/ar.aging.js
?? model.txt
?? opr.cek.txt
?? os
?? php-crud/ap.agingCRD.php
?? php-crud/ar.agingCRD.php
?? scripts/auto_test_patch_loop.sh
?? scripts/reaudit_fase3_ar_ap.php
?? scripts/sync_schema_test_to_dev.sh
?? setup-ccr-openrouter.sh
?? sql/2026-11-17.ar_ap_aging_card.sql
?? views/ap.aging.php
?? views/ar.aging.php
```

**Catatan**: File-file `??` (untracked) adalah hasil implementasi pilihan #2 & #3 yang belum di-commit. File `sql/2026-11-17.ar_ap_aging_card.sql` sudah dijalankan ke `proyekGMN_test` (DDL committed di DB test).

---

## 6. REKOMENDASI LANJUTAN (SESUI BUSINESS_GAP_REPORT.md)

Setelah verifikasi #2 & #3 selesai, lanjutkan ke gap berikutnya secara berurutan:

| # | Modul | Prioritas | Kompleksitas | Catatan |
|---|-------|-----------|--------------|---------|
| **4** | **Retensi Klien (AR) lengkap** | TINGGI | L | Kolom `retention_pct/retention_amount` sudah ada di DB (migrasi gelombang 1). Butuh: UI input di Tagihan Klien, validasi pembayaran (plafon = amount - paid - retention), jurnal pelepasan retensi (rule baru `PROJECT_RETENTION_RELEASE`), laporan. |
| **6** | **PPh Final / PPh 23 Supplier** | SEDANG | L | `purchase_invoice_hdr` butuh kolom PPh + rule `PPH_AP` (Dr Hutang / Cr Hutang PPh) + UI input + cetak bukti potong. Saat ini PPh hanya untuk subkon. |
| **7** | **GRN Approval Gate** | TINGGI | M | Kolom `grn_hdr.approval_status` sudah ada (migrasi gelombang 1). Butuh: aksi `approve_grn`/`reject_grn` di `grnCRD.php`, blokir invoice supplier untuk GRN belum approve, UI di `grn.php`/`js/grn.js`. |
| **8** | **Penutupan Periode Akuntansi** | SEDANG | M | 8 periode masih semua `is_closed=false`. Butuh: tutup periode lampau (2026-08..10), wizard tutup tahun, pencegahan posting ke periode tertutup. |
| **10** | **Hygiene Repo** | RENDAH | S | Bersihkan marker konflik merge `views/supplier.payment.php`, arsipkan tabel yatim `journal_entry_lines`/`general_ledger`, rename menu #156 (sudah done di test DB). |
| **11** | **Unit/Integrasi Test Jurnal AR/AP** | SEDANG | M | `tests/run_e2e_ar_ap.php` — release→jurnal, bayar→jurnal, unrelease→reverse, deposit, retensi. |
| **12** | **Subledger Control Report** | RENDAH | S | Bandingkan Piutang/Hutang di Neraca vs saldo subledger AR/AP. |

**Urutan disarankan**: #4 → #7 → #6 → #8 → #11 → #10 → #12

---

## 7. KESIMPULAN

✅ **Pilihan #2 (Kartu Piutang & Aging AR) dan #3 (Kartu Hutang & Aging AP) — SUDAH DIIMPLEMENTASIKAN & TERVERIFIKASI DI TEST DB**

- Semua file backend, frontend, view, migrasi SQL, dan registrasi menu sudah lengkap
- Query berjalan benar, data aging & kartu akurat sesuai spesifikasi `BUSINESS_GAP_REPORT.md`
- Siap untuk diuji manual via browser (`views/router?id=165` untuk AR, `id=166` untuk AP)
- **Belum di-commit ke git** (menunggu instruksi user)

**Next Action**: Lanjut ke implementasi **#4 Retensi Klien (AR)** — gap utama AR yang masih hilang.