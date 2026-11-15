# Master Log & Progress Checkpoint Audit Sistem ERP (`proyekgmn-dev`)

**Terakhir Diperbarui:** 2026-11-16 06:50 WIB  
**Database Uji Terisolasi:** `proyekGMN_test` (`127.0.0.1:5432`) | **Server Uji PHP:** `http://127.0.0.1:8098`

---

## 1. Optimasi Lingkungan & Infrastruktur Agen
- **Manajemen Memori (RAM 6 GB):** Menghentikan layanan lokal `ollama` (`sudo systemctl stop ollama`) saat menjalankan Claude Code untuk mencegah *swap thrashing*.
- **Pemasangan 4 Project Skills (`.claude/skills/`):**
  1. `db-schema-map` — Peta tabel, kolom kritis, dan isolasi gudang proyek.
  2. `erp-business-invariants` — Standar PHP 7.4, transaksi ACID (`FOR UPDATE`), dan aturan akuntansi.
  3. `e2e-test-template` — Kerangka baku pembuatan skrip pengujian E2E terisolasi.
  4. `fast-scoped-patch` — Protokol inspeksi terarah tanpa pemindaian massal.
- **Konfigurasi Claude Agent Suite (`.claude/agents/`):** 6 sub-agen spesialis (`db-code-auditor`, `feature-architect-builder`, `fullstack-patcher`, `security-auditor-patcher`, `qa-test-engineer`, `tech-writer`) siap didelegasikan dalam konteks terisolasi.

---

## 2. Riwayat Fitur & Patch yang Sudah Terkomit (`FAIL=0`)

1. **Validasi Pre-Release Purchase Order (`0fd331e`)**
   - **Perbaikan:** Validasi real-time saat transisi status `Draft -> Diajukan -> Disetujui` (`php-crud/poCRD.php`, `js/po.js`) mencakup kebutuhan bersih stok proyek (`GetProjectStockMap()`), kuota RAB aktual, integritas item, status aktif supplier, periode akuntansi terbuka, dan proteksi *double-release*.
   - **Verifikasi & Dokumen:** `tests/run_e2e_po_prerelease.php` (`TC-201` s/d `TC-210`, **`PASS=10 FAIL=0`**) & `docs/HOWTO_PO_PRERELEASE_CHECK_2026-11-15.md`.

2. **Pemisahan Layer Monitoring & Release Tagihan Klien (`4101e71`)**
   - **Perbaikan:** Mengubah label menu menjadi **"Tagihan Klien"** (`sql/2026-11-15_menu_tagihan_klien_rename.sql`), menyembunyikan jadwal termin berstatus `Draft` dari modul Payment (`project.paymentCRD.php`) hingga resmi di-`Release`, serta menambahkan *watermark* `DRAFT` otomatis pada cetakan PDF yang belum dirilis.
   - **Verifikasi & Dokumen:** `tests/run_e2e_release.php` (**`PASS=55 FAIL=0`**) & `docs/HOWTO_TAGIHAN_KLIEN_RELEASE_2026-11-15.md`.

3. **Audit Menyeluruh Hulu-ke-Hilir Proyek (`docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md`)**
   - **Hasil:** Pemetaan lubang logika pada alur `Proyek -> RAB -> Subkon/SPK -> Inventory -> Progress` dengan prinsip desain simpel namun akurat untuk 3 skenario khusus (*Leftover Return*, *Client-Supplied Rp 0*, dan *Subcon Buy-Back*).

4. **Patch Gelombang 1 — Data Readiness, Leftover Return, & Guard RAB (`a105548`)**
   - **Perbaikan:** Mendaftarkan `'material_leftover'` dan `'subcon_leftover_buyback'` pada validasi jurnal (`AutoPostJournal` & `JrnPost`), memasang guard `rabItemBlock()` sebelum penghapusan item RAB pada `save_rab` (`php-crud/rabCRD.php`), dan menyamakan validasi kategori RAB secara *case-insensitive*.
   - **Verifikasi & Dokumen:** `tests/run_e2e_leftover_and_rab_guard.php` (**`PASS=11 FAIL=0`**) & `docs/HOWTO_LEFTOVER_AND_RAB_GUARD_2026-11-15.md`.

5. **Patch Gelombang 2 — Material Titipan Klien (`CLIENT_SUPPLIED` Rp 0) (`4816957`)**
   - **Perbaikan:** Menambah kolom `budget_items.material_source` (`COMPANY` | `CLIENT_SUPPLIED`), mengecualikan item titipan klien dari kebutuhan beli (`qty_to_buy = 0` di Purchase Planning & PO), memaksa `unit_cost = 0` pada penerimaan (`GRN` / `Warehouse Receiving`) serta pengeluaran (`Warehouse Issuing`), dan melewati posting jurnal rupiah agar tidak mencemari HPP/WIP.
   - **Verifikasi & Dokumen:** `tests/run_e2e_client_supplied.php` (`TC-CS-01` s/d `TC-CS-12`, **`PASS=12 FAIL=0`**) & `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md`.

---

## 3. Tahap Final yang Tersisa: Patch Gelombang 3 (Gunakan Sub-Agen)
- **Ruang Lingkup:**
  1. **Subcon Material Supply & Buy-Back (`F-2.1`):** Memastikan material pasokan subkon (`SUBCON`) tidak memotong stok kita, serta menyediakan fitur pembelian sisa material milik subkon (`subcon_leftover_buyback`) masuk ke `stock_movements` (`IN`) dengan `unit_cost` kesepakatan + jurnal Persediaan vs Hutang Subkon/Kas.
  2. **Kontrol Progress Proyek vs Realisasi Biaya (`F-1.1` & `F-1.2`):** Validasi perbandingan HPP aktual terhadap RAB dan persentase progress fisik proyek.
