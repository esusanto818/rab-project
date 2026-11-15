# Audit Data Readiness — proyekGMN_test

**Tanggal audit:** 2026-11-14
**Basis:** branch `dev`, baseline commit `7cb757c` (layer release tagihan)
**DB:** `proyekGMN_test` @ `127.0.0.1:5432` (user `postgres`)
**Sumber:** psql `\d` + SELECT atas tabel terkait TC-001 s/d TC-065
        (`docs/TESTCASE_RELEASE_TAGIHAN_E2E_2026-11-14.md`)

> Catatan privasi: kolom PII (`contact_person`, `phone`, `email`, `address`, `password`,
> identitas karyawan) **tidak** dibaca/didump. Yang diaudit hanyalah struktur, jumlah,
> dan nilai bisnis (nomor/nilai/status) yang dibutuhkan test case.

---

## 1. Ringkasan Kesiapan

| # | Prasyarat | Status | Bukti |
|---|-----------|--------|-------|
| 1 | Migrasi kolom release (`release_status`, `release_no`, `release_date`, `released_by`, `release_note`) | ✅ SIAP | Ada di `public.project_invoices`, default `draft`, index `idx_project_invoices_release_status`, unique `project_invoices_release_no_key` |
| 2 | `fiscal_config` rule penagihan/pembayaran aktif | ⚠️ SEBAGIAN | `PROJECT_INVOICE`, `PROJECT_INVOICE_PPN`, `PRJ_PAY_AR`, `PRJ_PAY_DEP` aktif; **`PRJ_DEP_APPLY` BELUM ADA** di fiscal_config (diperlukan TC-023/024) → jalankan `client.deposit.sql` sebelum eksekusi, atau helper setup membuatnya otomatis |
| 3 | Periode akuntansi bulan berjalan open | ✅ SIAP | `2026-11` (`is_closed = f`, id 6); periode 2027-01..04 juga ada & open |
| 4 | Client + proyek untuk data uji | ⚠️ PERLU TAMBAH | Ada 4 client / 10 proyek, tetapi **belum ada** Client A (2 proyek) & Client B (1 proyek) sesuai anjuran dokumen TC → dibuat oleh `setup_test_data()` di `tests/run_e2e_release.php` |
| 5 | User FormAccess level <3 / 3 / >=4 untuk TC-055 | ⚠️ PERLU TAMBAH | Semua 7 user yang punya akses form 156 (TAGIHAN KE KLIEN) & 145 (pembayaran proyek) berada di level **4**; **tidak ada** user level 1/2/3 pada kedua form itu → helper `ensure_form_access_users()` membuat 3 user uji (level 1, 3, 4) |
| 6 | Metode pembayaran valid | ✅ SIAP | Konstanta `PP_METHODS = ['BANK_TRANSFER','CASH','GIRO','CEK']` (`project.paymentCRD.php:81`); tabel menyimpan varchar(20), data lama hanya `BANK_TRANSFER` |
| 7 | Web server + endpoint | ✅ SIAP | Apache aktif di port 80; base URL `http://127.0.0.1/rab-project/dev/` → worktree `proyekgmn-dev`; `/login.php` 200, CRUD endpoint 302 tanpa session (benar) |
| 8 | Akun COA untuk jurnal | ✅ SIAP | 19 cash/bank, 20 Piutang Usaha, 26 Uang Muka Penjualan, 27 PPN Keluaran, 42 Bank BCA (IDR), 48 Pendapatan Proyek (Termin) |
| 9 | Invoice pra-migrasi (TC-015: tidak diposting ulang) | ✅ ADA | 12 invoice lama semuanya `release_status='draft'`; 6 di antaranya (id 22–25, 7–10 dll.) sudah punya jurnal `project_invoice` → cocok untuk skenario "pra-migrasi tidak diposting ulang" |

**Kesimpulan:** DB struktural siap 100%; yang belum ada hanya **data uji** (client/proyek/user khusus) — disiapkan otomatis oleh setup helper sebelum eksekusi TC.

---

## 2. Tabel & Struktur Terkait

### 2.1 `clients`
| Kolom | Tipe | Nullable | Default |
|-------|------|----------|---------|
| id | integer (seq) | NOT NULL | nextval |
| name | varchar(255) | NOT NULL | |
| contact_person | varchar(255) | | '' |
| phone | varchar(50) | | '' |
| email | varchar(255) | | '' |
| address | text | | '' |
| created_at | timestamp | NOT NULL | now() |

### 2.2 `projects` (kolom relevan)
`id`, `name`, `client_id` → clients(id) ON DELETE SET NULL, `contract_value` numeric(18,2),
`ppn_rate` numeric(5,2), `ppn_inclusive` bool, `dp_min_pct`, `status` (check: Belum Mulai/Berjalan/Tertunda/Selesai),
`budget_currency_id` → m_currency(id), `budget_exch_rate`, `progress` 0..100.

### 2.3 `project_invoices` (kolom release layer)
| Kolom | Tipe | Nullable | Default |
|-------|------|----------|---------|
| release_status | text | NOT NULL | `'draft'` |
| release_no | varchar(30) | | NULL |
| release_date | date | | NULL |
| released_by | integer | | NULL |
| release_note | text | NOT NULL | `''` |

Index: `idx_project_invoices_release_status`; UNIQUE `project_invoices_release_no_key` (multi-NULL diperbolehkan).
Check lain: `amount > 0`; UNIQUE `inv_no`.

### 2.4 `project_payments`
`payment_no` UNIQUE (prefix `PPAY-`), `client_id`, `account_id` → chart_of_accounts, `payment_date`,
`payment_method` varchar(20) default `'BANK_TRANSFER'`, `total_amount`, `allocated_amount`,
`excess_amount`, `deposit_applied`, `currency_id`, `exch_rate`, `total_amount_cur`.

### 2.5 `project_payment_items`
`payment_id`, `invoice_id`, `amount_paid` (>0), `amount_cur`.

### 2.6 Jurnal
- `journal_entries`: `ref_no` UNIQUE (prefix `JV-`), `journal_date`, `period_id` → accounting_periods,
  `reference_type` (enum termasuk `project_invoice`, `project_payment`), `reference_id`,
  `status` (`draft`/`posted`), `posted_by`, `posted_at`.
- `journal_lines`: `journal_id`, `account_id`, `debit`, `credit` (check: tidak boleh keduanya > 0).

### 2.7 `fiscal_config` & `accounting_periods`
Struktur sesuai dokumen release; lihat data di §3.

### 2.8 FormAccess
`m_user_system(id, login_name, employee_id, password, active)`
`m_user_access_menu(user_id, form_id, access_level)` — level: 1 (lihat), 2 (tambah?), 3 (ubah), 4 (hapus/admin).
`m_form_name(id, title, php_url, ...)`.

---

## 3. Data Eksisting (tanpa PII)

### 3.1 Client (4 baris)
| id | name |
|----|------|
| 1 | PT Sejahtera Abadi |
| 2 | PT Karya Mandiri |
| 3 | CV Maju Bersama |
| 4 | Dinas PUPR Kab. Bogor |

### 3.2 Proyek (10 baris)
| id | name | client_id | contract_value | ppn_rate | status |
|----|------|-----------|----------------|----------|--------|
| 1 | Pembangunan Ruko Grand Blok C | 1 | 900.000.000,00 | 0 | Berjalan |
| 2 | Renovasi Gedung Kantor Pusat | 2 | 1.250.000.000,00 | 0 | Berjalan |
| 3 | Pembangunan Gudang Logistik | 3 | 2.150.000.000,00 | 0 | Selesai |
| 4 | Pembangunan Jembatan Desa | 4 | 700.000.000,00 | 0 | Tertunda |
| 5 | Pembangunan Gedung Perkantoran Surabaya | 3 | 1.300.000.000,00 | 0 | Berjalan |
| 6 | Renovasi Jembatan & Akses Jalan Tambak | 1 | 500.000.000,00 | 10 | Berjalan |
| 7 | Pembangunan Gudang Logistik Sidoarjo | 2 | 900.000.000,00 | 0 | Berjalan |
| 8 | Gedung Wisma A - Surabaya | 2 | 150.000.000,00 | 0 | Berjalan |
| 9 | Pembangunan Gudang MERR | 2 | 150.000.000,00 | 0 | Berjalan |
| 10 | test-001 | 1 | 8.000.000.000,00 | 11 | Belum Mulai |

### 3.3 User & FormAccess (tanpa PII)
- 72 baris di `m_user_system`; distribusi `m_user_access_menu`: level 1 = 792 baris/61 user,
  level 2 = 5 baris/5 user, level 3 = 222 baris/34 user, level 4 = 1461 baris/66 user.
- Form **156 TAGIHAN KE KLIEN** (`project.invoice.php`): hanya level 4, 7 user.
- Form **145 pembayaran proyek** (`project.payment.php`): hanya level 4, 7 user.
- **Tidak ada** user dengan level <3 maupun level 3 pada kedua form tersebut →
  TC-055 (akses level rendah → HTTP 403) memerlukan user uji baru (dibuat helper setup).

### 3.4 Metode pembayaran
- Konstanta valid (`project.paymentCRD.php:81`): `BANK_TRANSFER`, `CASH`, `GIRO`, `CEK`.
- Data `project_payments` existing: 2 baris, semuanya `BANK_TRANSFER` (PPAY-2610-00001/00002).

### 3.5 fiscal_config (rule yang dipakai TC)
| rule_code | rule_name | debit | kredit | is_active |
|-----------|-----------|-------|--------|-----------|
| PROJECT_INVOICE | Tagihan Termin Proyek | 20 | 48 | t |
| PROJECT_INVOICE_PPN | Penagihan termin - PPN Keluaran | 20 | 27 | t |
| PRJ_PAY_AR | Pembayaran Termin Proyek - Pelunasan Piutang | 42 | 20 | t |
| PRJ_PAY_DEP | Pembayaran Termin Proyek - Lebih Bayar (Deposit Client) | 42 | 26 | t |
| **PRJ_DEP_APPLY** | **BELUM ADA** di fiscal_config — wajib untuk TC-023/024 (pakai deposit) | — | — | **hilang** |
| PAY_ADV, PAY_AR, PROJECT_ADV, PROJECT_PAYMENT, dll. | aturan uang muka/piutang lainnya | — | — | t |

> `PRJ_DEP_APPLY` (Dr Uang Muka Penjualan / Cr Piutang Usaha) dibutuhkan `save_payment` saat
> `deposit_apply > 0` (`project.paymentCRD.php:761`). Skrip pembuatnya sudah ada:
> `client.deposit.sql` (INSERT ... WHERE NOT EXISTS, idempotent). Helper setup e2e menjalankannya
> otomatis bila rule belum ada, dan mengembalikan kondisi semula saat cleanup.

### 3.6 accounting_periods
| id | period_name | start | end | is_closed |
|----|-------------|-------|-----|-----------|
| 1 | 2026-08 | 2026-08-01 | 2026-08-31 | f |
| 2 | 2026-09 | 2026-09-01 | 2026-09-30 | f |
| 5 | 2026-10 | 2026-10-01 | 2026-10-31 | f |
| **6** | **2026-11** | 2026-11-01 | 2026-11-30 | **f (OPEN)** |
| 7 | 2027-01 | 2027-01-01 | 2027-01-31 | f |
| 8 | 2027-02 | 2027-02-01 | 2027-02-28 | f |
| 9 | 2027-03 | 2027-03-01 | 2027-03-31 | f |
| 10 | 2027-04 | 2027-04-01 | 2027-04-30 | f |

### 3.7 project_invoices existing (12 baris, semuanya `draft` — hasil migrasi 2026-11-14)
INV-2610-00001..00008 (proyek 2,6,7,8,9) & INV-2611-00001..00004 (proyek 10, ppn 11%).
Beberapa sudah punya jurnal `project_invoice` (JV-2611-00003..00010) — ini data pra-migrasi
yang dipakai skenario TC-015 (jurnal tidak diposting ulang saat bulk release).

### 3.8 Mata uang
`m_currency`: 2=IDR (base), 3=USD, 4=EUR, 5=MYR, 6=AUD, 7=BND, 8=CAD, 9=CHF, …

---

## 4. Celah & Tindakan (ditangani `tests/run_e2e_release.php`)

| Celah | Tindakan helper |
|-------|-----------------|
| Belum ada Client A (2 proyek) / Client B (1 proyek) | `setup_test_data()` membuat client + proyek + invoice DRAFT (DPP 10jt ppn 11%, DPP tanpa PPN, invoice pra-migrasi ber-jurnal) |
| Tidak ada user level <3 / 3 pada form tagihan | `ensure_form_access_users()` membuat 3 user uji dengan `m_user_access_menu` level 1 / 3 / 4 pada form 156 |
| Periode tutup / rule nonaktif (TC-053/054) | Helper menutup-buka periode & menonaktifkan-aktifkan rule **dengan save/restore** nilai asli di akhir runnning |
| Data lain tercemar | Semua data uji dihapus saat cleanup (`cleanup_test_data()`), transaksi rollback untuk skenario gagal |

## 5. Cara Menjalankan Ulang Audit Ini

```bash
psql -h 127.0.0.1 -U postgres -d proyekGMN_test -c "\d project_invoices"   # struktur
psql -h 127.0.0.1 -U postgres -d proyekGMN_test \
  -c "SELECT rule_code,is_active FROM fiscal_config WHERE rule_code IN ('PROJECT_INVOICE','PROJECT_INVOICE_PPN','PRJ_PAY_AR','PRJ_PAY_DEP','PRJ_DEP_APPLY');" \
  -c "SELECT * FROM accounting_periods WHERE period_name='2026-11';"
php tests/run_e2e_release.php --audit      # cetak ulang ringkasan kesiapan
```
