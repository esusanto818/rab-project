# HOWTO — Kontrol Progress Proyek vs Realisasi Biaya (F-1.1 & F-1.2)
**Tanggal:** 2026-11-16 · **Patch:** Gelombang 3 bagian kedua (audit hulu-hilir `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md` §7 A1/A2, §9 F-1.1/F-1.2)
**Item tertutup:** F-1.1 (validasi klaim/verifikasi progress vs realisasi biaya), F-1.2 (satu rumus HPP via `v_project_actual_cost`)

---

## 1. Apa yang diperbaiki

| Item | Masalah sebelum patch | Perilaku sesudah patch |
|------|----------------------|------------------------|
| F-1.1 | Progress 100% bisa diklaim/verified tanpa satu pun material terpakai (temuan A1 KRITIS); progress juga bisa di-set manual bebas di Master Proyek (`m.proyekCRD.php`), sehingga "satu-satunya jalur yang mengubah progress" di `project.progressCRD.php` menjadi tidak benar | `submit_claim` & `verify` di `project.progressCRD.php` **hard-block** klaim 100% bila `cost_material = 0` (belum ada issue material); `projectprogressCRD.php` (mobile) memakai blok yang sama. Master Proyek: perubahan `progress` hanya untuk admin (FormAccess ≥ 4); nilai yang sama dikirim ulang tetap diperbolehkan (UI lama) |
| F-1.1 | Tidak ada peringatan bila klaim progress jauh melampaui realisasi biaya | Soft-block warning: bila `percent_claimed − cost_pct ≥ 25`, klaim tetap diterima tetapi pesan menampilkan gap & mewajibkan bukti lapangan (verifikator diminta lengkapi catatan bila CPI < 0.80) |
| F-1.2 | Realisasi HPP dihitung dengan 3 rumus berbeda: `prog_metrics()` (progress menu) = material+expense; `dash_project_context()` (dashboard) = material+expense+subkon; `wr_calc()` (WIP release) = jurnal material_out saja. Status filter expense juga tidak konsisten (`'BATAL'` ada di satu modul, tak di lain modul) | Satu rumus otoritatif: view `public.v_project_actual_cost` (`sql/2026-11-16.project_actual_cost_view.sql`) = material issue bersih + expense (status void/cancel/Batal/Dibatalkan disaring) + subkon tersertifikasi APPROVED. Helper `ProjectActualCost()` di `php-func/function.all.php` membungkus view ini; dipakai oleh `prog_metrics()`, `dash_project_context()`, `wr_calc()`, dan gate issuing |
| F-1.2 | Gate issuing `validateMaterialOutAgainstProgress()` memakai `projects.budget` mentah (header manual) + hanya material; pesan error juga rusak (`%%d%%`/`%%s` literal — angka tidak pernah tercetak) | Gate memakai `rab_budget` terpadu (budget×kurs, fallback SUM RAB bila kosong) + `cost_total` dari view; fallback ke rumus lama bila view belum dimigrasi. Bug `sprintf` diperbaiki (pesan kini memuat angka asli) |
| F-1.2 | `snap_cost_base` di `project_progress_log` selalu 0 (biaya tak pernah dihitung saat verifikasi) | Verifikasi menyimpan `snap_cost_base = cost_total` terpadu (teruji TC-PC-08: `snap_cost_base=1000000.00`) |

> **Catatan desain (F-1.2):** `wr_calc()` tetap menghitung pelepasan WIP dari **jurnal** `material_out` (bukan `cost_total`),
> karena secara akuntansi pelepasan WIP hanya melepas beban material yang memang berjurnal ke akun WIP.
> View `v_project_actual_cost` dipakai untuk **tampilan & CPI** dan sebagai pembanding di preview WIP
> (`actual_cost`, `actual_cost_expense`, `cost_released`, `cost_remaining_wip`), bukan sebagai dasar pelepasan.

## 2. Berkas yang diubah

- `sql/2026-11-16.project_actual_cost_view.sql` — migrasi view `v_project_actual_cost` (F-1.2) + blok ROLLBACK
- `php-func/function.all.php` — helper `ProjectActualCost()` (satu rumus terpadu, dipakai 4 modul)
- `php-func/project.analysis.php` — `dash_project_context()` memakai `ProjectActualCost()` (fallback rumus lama bila view belum ada)
- `php-crud/project.progressCRD.php` — `prog_metrics()` via `ProjectActualCost()`; hard-block 100% tanpa material di `submit_claim` & `verify`; warning lunak gap ≥ 25%
- `php-crud/projectprogressCRD.php` — hard-block yang sama untuk laporan progress mobile (F-1.1)
- `php-crud/project.wipreleaseCRD.php` — `wr_calc()` menampilkan konteks terpadu (`actual_cost`, `actual_cost_expense`, `cost_released`, `cost_remaining_wip`) di preview
- `php-crud/warehouse.issuingCRD.php` — gate progress memakai `rab_budget` + `cost_total` view; perbaikan `sprintf` (`%%d%%` → `%d%%`)
- `php-crud/m.proyekCRD.php` — set manual `progress` non-admin ditolak (create: dipaksa 0; update: hanya admin yang mengubah)
- `php-crud/dashboardCRD.php` — `read_project_profitability` & `read_project_health` memakai view via `LEFT JOIN` (fallback subquery bila view belum ada)
- `tests/run_e2e_progress_cost_control.php` — uji E2E (13 kasus, TC-PC-01 s/d TC-PC-13)

## 3. Cara menjalankan migrasi

```bash
psql "host=127.0.0.1 dbname=proyekGMN_test user=postgres" \
     -f sql/2026-11-16.project_actual_cost_view.sql
```

Idempoten: `CREATE OR REPLACE VIEW`. Rollback (bila diperlukan):

```sql
DROP VIEW IF EXISTS public.v_project_actual_cost;
```

> Aplikasi memakai fallback defensif bila view belum dimigrasi (angka 0 atau rumus lama), sehingga migrasi
> tidak wajib segera di produksi — tetapi tanpa view, F-1.2 (satu rumus) tidak aktif.

## 4. Cara menjalankan uji

```bash
php tests/run_e2e_progress_cost_control.php   # target: PASS=13 FAIL=0
php tests/run_e2e_inventory_check.php          # regresi stok/PO: FAIL=0
php tests/run_e2e_release.php                  # regresi tagihan/payment: FAIL=0
php tests/run_e2e_client_supplied.php          # regresi titipan klien: FAIL=0
```

Prasyarat: server web `php -S 127.0.0.1:8098` dengan env `RAB_DB_NAME=proyekGMN_test` aktif.

Yang diverifikasi `run_e2e_progress_cost_control.php`:
- **TC-PC-01/02** — view ada, satu baris/proyek, `cost_material` = SUM manual material posted.
- **TC-PC-03** — `list_projects` (prog_metrics) mengembalikan `cost_actual` = material+expense+subkon.
- **TC-PC-04** — klaim 100% tanpa issue material **DITOLAK** (hard-block F-1.1).
- **TC-PC-05/06** — issue PROJECT 10 Buah terindeks realisasi; klaim 30% setelah issue diterima (+warning lunak).
- **TC-PC-07** — pelapor tidak boleh memverifikasi klaimnya sendiri.
- **TC-PC-08** — verifikasi sah menyimpan `snap_cost_base > 0` (realisasi tercatat, bukan 0).
- **TC-PC-09/10** — expense PAID masuk `cost_expense` view & `cost_other` prog_metrics.
- **TC-PC-11** — preview WIP memuat field realisasi terpadu (`actual_cost`, `actual_cost_expense`, `cost_remaining_wip`).
- **TC-PC-12** — dashboard profitability memuat `cost_total` terpadu.
- **TC-PC-13** — issuing ditolak bila proyeksi spend melebihi plafon progress-gated (pesan error kini memuat angka).

## 5. Catatan operasional

- **Pesan hard-block F-1.1** (klaim 100% tanpa material):
  `Klaim 100% DITOLAK: belum ada realisasi pemakaian material (issue material Rp 0) untuk proyek ini. Catat issue material di modul Warehouse Issuing terlebih dahulu.`
- **Pesan warning lunak** (klaim jauh melampaui biaya):
  `PERHATIAN: klaim 30% melampaui realisasi biaya 0.2% (gap +30). Pastikan bukti lapangan lengkap; verifikator wajib mengisi catatan.`
- **Pesan gate issuing** kini memuat angka asli (sebelum patch selalu `%d%`/`%s` literal):
  `TRANSAKSI DITOLAK: Pemakaian anggaran melampaui batas progress fisik saat ini! Progress fisik: 30%. Plafon diizinkan: Rp 157.500.000,00. Total pengajuan: Rp 203.500.000,00.`
- **Set manual progress** di Master Proyek kini khusus admin; staf lapangan harus lewat modul Project Progress (klaim → verifikasi) — ini menutup celah audit A1 (progress palsu merusak CPI, gap, plafon issue, dan WIP release).
- **`material_out_items.amount`** (temuan F-1.3/audit 1.4 RENDAH) sengaja **tidak** disentuh pada gelombang ini: seluruh konsumen memakai `qty_out*unit_cost`, mengisi kolom mati tidak mengubah perilaku; masuk daftar housekeeping berikutnya.
- **Subkon buy-back (F-2.1)** & **isi `spk_id` saat issuing (F-2.2)** adalah item Gelombang 3 lainnya, bukan bagian patch ini.
