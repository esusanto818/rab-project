# HOWTO — Retur Sisa Material (Leftover Return) & Guard Hapus RAB
**Tanggal:** 2026-11-15 · **Patch:** Gelombang 1 (audit hulu-hilir `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` §9)
**Item tertutup:** F-3.1, F-5.1, F-5.2, F-5.3

---

## 1. Apa yang diperbaiki

| Item | Masalah sebelum patch | Perilaku sesudah patch |
|------|----------------------|------------------------|
| F-3.1 | `reference_type` jurnal `'material_leftover'` tidak dikenal → retur sisa material proyek gagal posting jurnal (`invalid input value for enum reference_type`) | `'material_leftover'` & `'subcon_leftover_buyback'` terdaftar di enum DB (`ALTER TYPE ... ADD VALUE`) dan di daftar validasi `in_array` aplikasi (`AutoPostJournal` di `php-func/function.all.php`, `JrnPost`/`JrnReverseByRef` di `php-func/journal.stock.php`) |
| F-5.1 | Rule `PRJ_DEP_APPLY` belum ada di `fiscal_config` (data readiness) | Migrasi `sql/2026-11-15_patch_gelombang1_readiness_rab.sql` memastikan rule ada & aktif: Dr 2200 Uang Muka Penjualan / Cr 1200 Piutang Usaha (tanpa kas/bank) |
| F-5.2 | `save_rab` (`php-crud/rabCRD.php`) menghapus item RAB lama yang tidak ada di grid **tanpa guard** → item yang sudah dipakai PO/SPK/issue bisa hilang diam-diam | Sebelum DELETE dijalankan, setiap `budget_item_id` lama yang hilang dari grid diperiksa dengan `rabItemBlock()`; bila dipakai transaksi lain → simpan dibatalkan dengan pesan eksplisit |
| F-5.3 | CHECK DB mengenal `'Alat'`, PHP mengenal `'Peralatan'`; input `'Peralatan'` di-demote diam-diam ke `'Lain-lain'` lalu baris material dibuang tanpa peringatan | Daftar kategori disamakan (`Material, Upah, Peralatan, Transport, Konsumsi, Lain-lain`) di DB & PHP; normalisasi **case-insensitive** dengan validasi **ketat** (kategori tak dikenal → error eksplisit, bukan demote) |

> **Koreksi asumsi audit:** `journal_entries.reference_type` ternyata bertipe **ENUM** `public.reference_type` (bukan varchar seperti dicatat §10 audit). Patch memakai `ALTER TYPE ... ADD VALUE` (idempoten via katalog `pg_enum`).

## 2. Berkas yang diubah

- `sql/2026-11-15_patch_gelombang1_readiness_rab.sql` — migrasi (enum + PRJ_DEP_APPLY + CHECK kategori + `budget_items.updated_at`)
- `php-func/function.all.php` — daftar `JRN_VALID_REF_TYPES` + validasi `in_array` di `AutoPostJournal`
- `php-func/journal.stock.php` — konstanta `JRN_REF_MATERIAL_LEFTOVER`/`JRN_REF_SUBCON_LEFTOVER_BUYBACK` + validasi `in_array` di `JrnPost`/`JrnReverseByRef`
- `php-crud/rabCRD.php` — `rabNormCategory()` (F-5.3), `rabItemBlock()` + guard sebelum delete (F-5.2)
- `php-crud/m.proyekCRD.php` — `mpExpCategory()` case-insensitive + ketat (F-5.3)
- `tests/run_e2e_leftover_and_rab_guard.php` — uji E2E (11 kasus)

## 3. Cara menjalankan migrasi

```bash
psql "host=127.0.0.1 dbname=proyekGMN_test user=postgres" \
     -f sql/2026-11-15_patch_gelombang1_readiness_rab.sql
```

Idempoten: `ALTER TYPE ADD VALUE` dibungkus `DO $$ ... IF NOT EXISTS ... $$` via `pg_enum`;
`PRJ_DEP_APPLY` pakai `ON CONFLICT (rule_code) DO UPDATE`; CHECK diganti (`DROP CONSTRAINT IF EXISTS` lalu `ADD`).
Bagian enum **tidak** di dalam `BEGIN/COMMIT` karena PG < 12 melarang `ALTER TYPE ADD VALUE` di dalam transaksi
yang lalu-lalang nilainya. Test runner juga menjalankan migrasi idempoten saat setup, jadi menjalankan file SQL
terpisah bersifat opsional.

## 4. Cara menjalankan uji

```bash
php tests/run_e2e_leftover_and_rab_guard.php          # target: PASS=11 FAIL=0
php tests/run_e2e_audit_sync.php                      # regresi lintas lapisan: FAIL=0
php tests/run_e2e_release.php                         # regresi jurnal tagihan/payment: FAIL=0
php tests/run_e2e_inventory_check.php                 # regresi stok/PO: FAIL=0
php tests/run_e2e_po_prerelease.php                   # regresi validasi PO: FAIL=0
```

Prasyarat: server web `php -S 127.0.0.1:8098` dengan env `RAB_DB_NAME=proyekGMN_test` aktif
(lihat komentar `[FIX-LOGIN]` di `tests/run_e2e_release.php`).

Yang diverifikasi `run_e2e_leftover_and_rab_guard.php`:
- **TC-LGR-01..05** — retur sisa material proyek: `stock_movements` IN (`MATERIAL_LEFTOVER`) + jurnal
  `WH_LEFTOVER` (Dr 1500 Persediaan / Cr 1700 WIP) terbit dengan `reference_type='material_leftover'`,
  lalu reverse membatalkan stok & jurnal (status `reversed`).
- **TC-LGR-10/11** — `save_rab` menolak hapus item RAB yang sudah dipakai PO; data utuh (rollback ACID).
- **TC-LGR-12/13** — kategori `'PERALATAN'` (huruf besar) ternormalisasi & tersimpan sebagai `'Peralatan'`.
- **TC-LGR-14** — kategori tak dikenal (`'Honor'`) ditolak dengan pesan eksplisit.
- **TC-LGR-20** — `PRJ_DEP_APPLY` aktif di `fiscal_config` (Dr 2200 / Cr 1200).

## 5. Catatan operasional

- **Pesan guard hapus RAB** berbentuk: `Item RAB tidak dapat dihapus karena sudah dipakai transaksi lain: "M<material_id>" (id <bid>): sudah dipakai PO <po_number>. ...`
- **Reverse retur sisa** hanya bila stok hasil retur belum terpakai (cek `lo_onhand` di `material.leftoverCRD.php`).
- **`subcon_leftover_buyback`** didaftarkan sebagai nilai yang dikenal (F-3.1), tetapi jalur buy-back-nya
  (PO khusus + rule `LEFTOVER_BUYBACK_INV`/`LEFTOVER_BUYBACK_PAY`) adalah **Patch Gelombang 1 item F-2.1**
  yang belum diminta pada sesi ini — daftarnya disiapkan lebih dulu agar tidak ada lagi blocker enum.
- Bonus terkait: `subcon_invoice`/`subcon_invoice_pay` juga ditambahkan ke enum (sudah dipakai
  `subcon.invoiceCRD.php` tapi belum ada di enum — celah tersendiri yang ditutup migrasi ini).
- Bug terkait F-5.3 yang ikut tertutup: `save_rab` path UPDATE non-material memakai kolom
  `budget_items.updated_at` yang tidak ada di skema → migrasi menambah kolom + trigger `trg_budget_items_touch`.
