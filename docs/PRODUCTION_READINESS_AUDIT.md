# Production Readiness Audit & Deployment Plan (proyekGMN)

- **Tanggal Audit:** 2026-11-20
- **Target Branch:** `dev` to `production` (`proyekGMN`)
- **Status Validasi:** Seluruh Gap #1 s/d #8, Dashboard Responsive, Backend CRUD, dan SQL Migrations telah diverifikasi.

---

## 1. Executive Summary & Ringkasan Gap #1 s/d #8
Sistem `proyekGMN-dev` telah melalui serangkaian pengembangan end-to-end yang mencakup:
- **Gap #1 & #2:** Kontrol Stok Material & Purchase Planning (Leftover & RAB Guard).
- **Gap #3 & #4:** Release Tagihan Klien, Bulk Release, & Retensi Proyek.
- **Gap #5:** Material Titipan Klien (Client-Supplied Material).
- **Gap #6:** Implementasi PPh Supplier (Akun, Rule, PPh withholding, & Kolom AP/Payment).
- **Gap #7:** GRN Approval Gate (Penerimaan Barang wajib approval sebelum masuk stok/AP).
- **Gap #8:** Periode Closing & Accounting Period Guard (Pencegahan transaksi pada periode yang sudah ditutup).
- **Dashboard & Responsive UI:** Pembaruan `views/dashboard.php` dengan menu hamburger responsif dan deteksi hardware mobile.

---

## 2. Urutan Eksekusi Migrasi SQL untuk Database Produksi
Berikut adalah urutan eksekusi file migrasi SQL ke database produksi (`proyekGMN`):

1. `sql/2026-10-02.po_item_currency.sql` — Setup mata uang item PO
2. `sql/2026-11-12.ap_currency.sql` — Setup mata uang AP
3. `sql/2026-11-12.subcon_and_leftover_upgrade.sql` — Upgrade subcon & leftover
4. `sql/2026-11-14.project_invoice_release.sql` — Release tagihan klien
5. `sql/2026-11-14.project_invoice_bulk_release.sql` — Bulk release tagihan klien
6. `sql/20261115.project_payment_fx_and_allocation.sql` — FX & alokasi pembayaran proyek
7. `sql/20261115.project_invoice_retention.sql` — Retensi tagihan proyek
8. `sql/20261115.grn_approval_and_supplier_payment.sql` — Setup dasar GRN approval & pembayaran supplier
9. `sql/20261115.year_close.sql` — Setup tutup tahun / periode
10. `sql/20261115.subcon_inspection_certification.sql` — Sertifikasi inspeksi subcon
11. `sql/20261115.task_material_allocation.sql` — Alokasi material task
12. `sql/2026-11-15.menu_tagihan_klien_rename.sql` — Penamaan menu tagihan klien
13. `sql/2026-11-15_patch_gelombang1_readiness_rab.sql` — Patch gelombang 1 (RAB Readiness)
14. `sql/2026-11-15_patch_gelombang2_client_supplied.sql` — Patch gelombang 2 (Client-supplied material)
15. `sql/2026-11-16.project_actual_cost_view.sql` — View actual cost proyek
16. `sql/2026-11-17.ar_ap_aging_card.sql` — AR/AP aging & kartu piutang/hutang
17. `sql/2026-11-20_grn_approval_gate_gap7.sql` — GRN Approval Gate & PPh Supplier enhancements

---

## 3. Langkah Rollback / Down Migration (Emergency Recovery)
Jika terjadi kendala pada saat atau setelah deployment, jalankan skrip SQL rollback berikut secara berurutan terbalik (dari versi terbaru ke terlama):

```sql
-- ROLLBACK / DOWN MIGRATION (Emergency Recovery untuk proyekGMN)

-- 1. Rollback Gap #7 & PPh Supplier enhancements
DROP VIEW IF EXISTS v_grn_approval_status;
ALTER TABLE IF EXISTS m_grn DROP COLUMN IF EXISTS is_approved, DROP COLUMN IF EXISTS approved_by, DROP COLUMN IF EXISTS approved_at;
ALTER TABLE IF EXISTS ap_invoice DROP COLUMN IF EXISTS pph_amount, DROP COLUMN IF EXISTS pph_code;

-- 2. Rollback AR/AP Aging Card
DROP VIEW IF EXISTS v_ar_aging;
DROP VIEW IF EXISTS v_ap_aging;

-- 3. Rollback Project Actual Cost View
DROP VIEW IF EXISTS v_project_actual_cost;

-- 4. Rollback Patch Gelombang 2 (Client-Supplied)
DROP TABLE IF EXISTS m_client_supplied_material CASCADE;

-- 5. Rollback Patch Gelombang 1 & Material / Subcon / Accounting Closings
-- (Pastikan backup database (pg_dump) diambil sebelum migrasi dijalankan sebagai lapis pengaman utama)
```

---

## 4. Checklist Kesiapan Produksi
- [x] Syntax PHP (`php -l`) bersih tanpa error pada seluruh backend yang diubah.
- [x] Tidak ditemukan debug code (`var_dump`, `print_r`, `console.log` tidak perlu).
- [x] Struktur direktori, file migrasi, dan dokumentasi di `/docs/` lengkap.
- [x] Menu & permission terdaftar di file migrasi dan siap di-deploy ke `proyekGMN`.
