# Session Log

Log kronologis sesi kerja proyekGMN. Setiap entri mencatat tujuan, file yang diubah, ringkasan teknis/keputusan, dan status verifikasi.

---

## [2026-11-16 15:52 WIB] - Penyelesaian Akhir Patch Gelombang 2: Material Titipan Klien (CLIENT_SUPPLIED Rp 0)

- **Tujuan / Permintaan**: Delegasikan penyelesaian akhir Patch Gelombang 2 ke sub-agen `qa-test-engineer` dan `tech-writer`: (1) cek nilai valid enum `reference_type` pada `stock_movements`/`journal_entries`, perbaiki TC-10 serta hapus baris debug `[DBG-TC10]` di `tests/run_e2e_client_supplied.php`, lalu jalankan 3 suite E2E hingga semuanya `FAIL=0`; (2) buat dokumen panduan `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md` dan perbarui `AUDIT_CHANGELOG.md` (status `Uncommitted`); (3) tampilkan `php -l`, `git status -s`, `git diff --stat`. Jangan commit. Penutupan sesi: perbarui `AUDIT_CHANGELOG.md` dengan status `Uncommitted` untuk 4 file yang berubah, dan buat `docs/SESSION_LOG.md` — dilakukan langsung di thread utama (tanpa background agent).

- **File yang Diubah / Dibuat** (`git status -s`):
  ```
   M AUDIT_CHANGELOG.md
   M docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md
   M test_report_inventory_check.md
  ?? test_report_client_supplied.md
  ```
  Catatan: `?? ccsr.txt` (323 byte, catatan UI provider OpenRouter) muncul sebagai untracked dan BUKAN hasil pekerjaan sesi ini — tidak disentuh, tidak di-commit, perlu konfirmasi pemilik.

- **Ringkasan Teknis & Keputusan**:
  - QA menemukan premis brief salah: `journal_entries.reference_type` adalah **ENUM** `public.reference_type` bernilai `'material_out'` (lowercase, dari `JRN_REF_ISSUE` di `php-func/journal.stock.php:24`), sedangkan `stock_movements.reference_type` adalah **varchar** bernilai `'MATERIAL_OUT'` (uppercase, dari `ISSUE_REF_TYPE` di `php-crud/warehouse.issuingCRD.php:28`). Query TC-CS-10 baris 480 (`reference_type = 'material_out'` untuk `journal_entries`) **sudah benar** — mengubahnya ke `'MATERIAL_OUT'` justru menyebabkan error ENUM invalid. Tidak ada perubahan code produksi.
  - Tidak ada baris debug `[DBG-TC10]` maupun `var_dump`/`echo` debug tersisa di `tests/run_e2e_client_supplied.php`.
  - Kegagalan TC-CS-10 pertama (`mv=null item_cost=n/a jurnal_rows=-1 saldo=0`) adalah efek sisa fixture kotor di DB uji, bukan bug query — setelah run penuh (setup+cleanup otomatis) semua PASS.
  - Guard klien-supplied di `IssSave()`: `issueValue == 0` → jurnal `ISSUE_PROJECT` di-skip eksplisit (bukan error) agar WIP tidak tercemar jurnal Rp 0; FIFO mengeluarkan layer Rp 0 lebih dulu sehingga HPP = Rp 0 otomatis.
  - Tech-writer: HOWTO diperluas dari 4 section menjadi 6 section (tujuan/konteks, tabel before/after, migrasi SQL idempoten, cara jalankan E2E TC-CS-01..12, catatan operasional, daftar file).
  - AUDIT_CHANGELOG.md diperbarui berlapis: entri verifikasi TC-CS-10, entri penyelesaian akhir, dan blok status akhir sesi ini yang menegaskan `Uncommitted` untuk 4 file.

- **Status Verifikasi**:
  - `php tests/run_e2e_client_supplied.php` → **PASS=12 FAIL=0**
  - `php tests/run_e2e_inventory_check.php` → **PASS=10 FAIL=0**
  - `php tests/run_e2e_audit_sync.php` → **PASS=9 FAIL=0**
  - `php -l` bersih: `tests/run_e2e_client_supplied.php`, `tests/run_e2e_inventory_check.php`, `tests/run_e2e_audit_sync.php`, `php-crud/warehouse.issuingCRD.php`.
  - Semua perubahan **Uncommitted** (sesuai instruksi "Jangan commit dulu"). Sub-agen `tech-writer` dihentikan user sebelum sempat menyerahkan laporan, namun hasil kerjanya sudah ada di disk dan isi dokumennya diperiksa ulang penuh (konsisten).

---

## [2026-11-17] - Patch Gelombang 3 Bagian Kedua: F-1.1 & F-1.2 (Progress vs Realisasi Biaya)

- **Tujuan / Permintaan**: lanjutkan Patch Gelombang 3 bagian kedua — F-1.1 & F-1.2 (Kontrol Progress Proyek vs Realisasi Biaya). Muat skill, cek skema `material_out_items`/`projects`/`budget_items` di `proyekGMN_test`, implementasikan validasi perbandingan HPP aktual vs RAB + % progress fisik pada `m.proyekCRD.php`, `php-func/project.analysis.php`, `project.wipreleaseCRD.php`, `projectprogressCRD.php`, `warehouse.issuingCRD.php`. Jalankan `tests/run_e2e_progress_cost_control.php` hingga FAIL=0 dan `php -l` bersih; buat panduan `docs/HOWTO_PROGRESS_COST_CONTROL_2026-11-16.md`; perbarui `AUDIT_CHANGELOG.md` + `docs/SESSION_LOG.md`. Jangan commit (sesuai instruksi utama).

- **File yang Diubah / Dibuat** (`git status -s`):
  ```
   M php-crud/dashboardCRD.php
   M php-crud/m.proyekCRD.php
   M php-crud/project.progressCRD.php
   M php-crud/project.wipreleaseCRD.php
   M php-crud/projectprogressCRD.php
   M php-crud/warehouse.issuingCRD.php
   M php-func/function.all.php
   M php-func/project.analysis.php
  ?? sql/2026-11-16.project_actual_cost_view.sql
  ?? tests/run_e2e_progress_cost_control.php
  ?? test_report_progress_cost_control.md
  ?? docs/HOWTO_PROGRESS_COST_CONTROL_2026-11-16.md
  ?? docs/SESSION_LOG.md (file ini)
  ```
  Catatan: `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md`, `test_report_inventory_check.md`, `test_report_po_prerelease.md`, `test_report_tc001_065.md` ikut berubah karena runner E2E menulis ulang laporan (tanggal/id fixture saja, bukan kode). `ccsr.txt` & `docs/GUIDE_CCR_CLAUDE_CODE_SETUP.md` **bukan** hasil sesi ini.

- **Ringkasan Teknis & Keputusan**:
  - F-1.1: `submit_claim` & `verify` (`project.progressCRD.php`) **hard-block** klaim 100% bila realisasi material `cost_material = 0`; mobile (`projectprogressCRD.php`) memakai blok yang sama. Klaim yang jauh melampaui realisasi biaya (>= 25%) diterima tetapi memicu **warning lunak** di pesan (verifikator wajib catatan bila CPI merah). `m.proyekCRD.php`: perubahan `progress` hanya untuk admin; non-admin create dipaksa 0; non-admin update hanya boleh mengirim nilai yang sama.
  - F-1.2: view `public.v_project_actual_cost` (migrasi idempoten, blok ROLLBACK di bagian bawah) = satu rumus `cost_total` (material issue posted + expense tanpa status batal + subkon tersertifikasi APPROVED). Helper `ProjectActualCost()` membungkus view (prepared statement). Dipakai `prog_metrics`, `dash_project_context`, `wr_calc` (preview), gate issuing, dan `dashboardCRD.php` (LEFT JOIN + fallback `COALESCE` subquery). `$cs` tidak di-double-count: nilai subkon dari `progressCRD`/`wr` memakai view; `dash_project_context` memakai hasil view bila tersedia, fallback hitung sertifikat lama bila tidak. Filter status expense disamakan di semua jalur (`VOID/CANCELLED/CANCELED/BATAL/DIBATALKAN`).
  - Gate issuing: plafon = `rab_budget` (budget×kurs, fallback `SUM(budget_items)`) × progress% × 1.05; `currentSpent` = `cost_total` view; fallback ke material-posted bila view belum dimigrasi. Perbaikan bug `sprintf`: `%%d%%`/`%%s` literal → `%d%%`.
  - WIP release **tidak** diubah dasar pelepasannya (tetap `material_out` jurnal); preview hanya menampilkan konteks terpadu (`actual_cost`, `cost_released`, `cost_remaining_wip`).
  - Bug tes yang dikoreksi: `$g` harus referensi `&$GLOBALS['g']`; `project_progress` tidak ada di DB uji (yang ada `project_progress_log`); `journal_entries.reference_type` ENUM lowercase `material_out`; `dashboardCRD.php` membaca `action` (bukan `task`); `progress` awal fixture harus > 0 agar gate issue mengizinkan.

- **Status Verifikasi**:
  - `php tests/run_e2e_progress_cost_control.php` → **PASS=13 FAIL=0** (`test_report_progress_cost_control.md`).
  - `php -l` bersih pada 9 file yang diubah/dibuat (lihat AUDIT_CHANGELOG).
  - Regresi: `run_e2e_inventory_check.php` → **PASS=10 FAIL=0**; `run_e2e_client_supplied.php` → **PASS=12 FAIL=0**; `run_e2e_leftover_and_rab_guard.php` → **PASS=11 FAIL=0**; `run_e2e_release.php` → **PASS=55 FAIL=0**; `run_e2e_po_prerelease.php` → **PASS=10 FAIL=0**.
  - Semua perubahan **Uncommitted** (menunggu instruksi user untuk commit).
  - Migrasi `sql/2026-11-16.project_actual_cost_view.sql` sudah dijalankan di `proyekGMN_test` (splash `BEGIN/CREATE VIEW/DO/COMMIT` sukses, view diverifikasi berisi data). **Belum dijalankan di produksi.**

---

## [2026-11-17] - Audit Grid Wrap: Halaman Tagihan Klien (menu id 156)

- **Tujuan / Permintaan**: audit HANYA halaman Tagihan Klien (`views/router?id=156`) + file JS/CSS grid yang dipakai; terapkan wrap text & penyesuaian lebar kolom agar header & isi sel (nomor invoice, tanggal, nominal DPP/PPN/Total/Sisa, status) terbaca utuh tanpa `...`. Jangan ubah modul lain. Simpan laporan ke `docs/AUDIT_GRID_WRAP_REPORT.md`.
- **File yang Diubah**: `js/project.invoice.js` (injectCss scoped `#divMainGrid`, `forceFit:false`, lebar kolom disesuaikan). `views/project.invoice.php` tidak diubah (grid/CSS di JS).
- **Ringkasan Teknis**: Akar masalah = rule global ExtJS 3.4.1 `.x-grid3-cell-inner/.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}` memotong teks. `grddetail.css`/`mainCSS.css` tidak menghapusnya. Perbaikan: override SCOPED `#divMainGrid .x-grid3-cell-inner/#divMainGrid .x-grid3-hd-inner{white-space:normal;overflow:visible;...}` + `forceFit:false` (scroll horizontal alih-alih memaksa kolom menyusut) + lebar kolom diperlebar (No.Invoice 130, nominal 130–140, Status 105, dst.). Alignment angka kanan/tanggal & status center dipertahankan. **CSS global tidak diubah** (`ext-all.css`, `grddetail.css`, `mainCSS.css` dipakai 39+ view — override scoped via JS).
- **Status Verifikasi**: `php -l views/project.invoice.php` bersih; `node --check js/project.invoice.js` lolos. Audit murni tampilan, tidak menjalankan E2E. Semua perubahan **Uncommitted**.
- **Laporan**: `docs/AUDIT_GRID_WRAP_REPORT.md`. Temuan luar scope (modul lain dengan `forceFit:true`/potensi potong) dicatat di laporan, TIDAK diubah.

---
**Auto-Log (2026-11-17 01:24:33)**


---
**Auto-Log (2026-11-17 06:42:27)**


---
**Auto-Log (2026-11-17 07:47:04)**


---
**Auto-Log (2026-11-17 08:27:48)**


---

## [2026-11-17] - Audit Grid Wrap: Pembayaran Termin & Purchase Order (PO)

- **Tujuan / Permintaan**: lanjutkan audit wrap text ke modul Pembayaran Termin (`project.payment.js`) dan PO (`po.js`) dengan pola KONSISTEN commit `3e68211` (scoped CSS override per container grid, `forceFit:true → false`, pelebaran kolom; CSS global TIDAK diubah). Hanya presentasi — tanpa ubah logika bisnis. JANGAN commit. Laporan ke `docs/AUDIT_GRID_WRAP_REPORT.md`.
- **File yang Diubah**: `js/project.payment.js` (ganti `injectHeaderCss()` lama yang mengunci `height:36px` → override scoped wrap untuk `.ppMainGrid`/`#editorFormGrid`/`.ppDepGrid`; `forceFit:false` pada grid utama, editor alokasi, window riwayat deposit; lebar kolom nominal/nomor dokumen diperlebar), `js/po.js` (IIFE `injectGridWrapCss()` scoped ke `#divMainGrid`/`#editorFormGrid`; `forceFit:false` pada grid daftar PO & editor item; lebar kolom diperlebar; `<td>` deskripsi material di panel detail ditambah inline wrap), `views/project.payment.print.php` & `views/project.payment.list.print.php` (aturan `<style>` scoped: sel tabel boleh wrap; kelas angka `.r-al`/`.num` tetap `nowrap`), `docs/AUDIT_GRID_WRAP_REPORT.md` (diperluas jadi bagian A invoice + bagian B termin/PO), `AUDIT_CHANGELOG.md` (entri sesi).
- **Ringkasan Teknis**: akar masalah sama seperti modul invoice — rule global ExtJS 3.4.1 `.x-grid3-cell-inner/.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}` + `forceFit:true` memotong header/nominal panjang. Pola fix: override CSS scoped via `<style>` yang di-inject JS (tidak menyentuh `ext-all.css`/`grddetail.css`/`mainCSS.css`), `forceFit:false` + `scrollOffset:0` (scroll horizontal alih-alih potong), `white-space:normal`/`text-overflow:clip`/`word-wrap:break-word`/`height:auto` pada hd-inner & cell-inner, align angka kanan tetap dihormati.
- **Status Verifikasi**: `node --check` lolos (`po.js`, `project.payment.js`, `project.payment.print.js`, `project.payment.list.print.js`); `php -l` bersih (2 view print). Audit murni presentasi; tidak ada perubahan backend/DB. Semua perubahan **Uncommitted**.
- **Laporan**: `docs/AUDIT_GRID_WRAP_REPORT.md` (bagian B). Temuan luar scope: `js/purchase.order.js` (menu legacy) & helper `stdGrid*` di `js-func/ExtExtend.js` masih `forceFit:true` global — dicatat, TIDAK diubah agar tidak merusak modul lain.

---
**Auto-Log (2026-11-17 09:50:49)**


---
**Auto-Log (2026-11-17 10:27:16)**


---
**Auto-Log (2026-11-17 11:30:37)**


## [2026-11-17] - Verifikasi & Perbaikan Pola Grid Wrap Gelombang 3 (bin.location, stock.opname, dashboard)

- **Tujuan / Permintaan**: cek `git diff` gelombang 3 (`js/purchase.order.js`, `js/warehouse.issuing.js`, `js/whrcv.js`), pastikan pola scoped grid text-wrap sudah diterapkan juga pada `js/bin.location.js` & Dashboard, lalu perbarui `docs/SESSION_LOG.md`.
- **File yang Diubah**:
  - `js/bin.location.js` — perbaikan BUG: injected CSS memakai kurawal ganda `{{`/`}}` (sisa gaya f-string Python) sehingga selector CSS tidak valid; selector juga menyasar `#editorFormGrid` (bukan grid Ext di halaman ini — editor hanya `#editorFormMain`) dan `#binDtlContainer` (adalah `<table>` HTML biasa, bukan grid ExtJS). Dikembalikan ke pola standar: IIFE `injectGridWrapCss()` scoped `#divMainGrid` saja, brace `{}` tunggal, `viewConfig: { forceFit: false, scrollOffset: 0 }` (sudah ada sejak diff sebelumnya).
  - `js/stock.opname.js` — perbaikan BUG `{{`/`}}` yang sama pada injected CSS; scope sudah benar (`#divMainGrid` + `#editorFormGrid` — keduanya memang grid Ext di halaman ini), hanya kurung kurawal yang dikoreksi.
  - `js/purchase.order.js`, `js/warehouse.issuing.js`, `js/whrcv.js`, `js/stock.card.js`, `views/dashboard.php` — sudah sesuai pola (terverifikasi, tidak diubah sesi ini).
  - `tests/run_grid_wrap_static.php` — **BARU**: uji statis wajib per CLAUDE.md §6 (ketersediaan kasus uji otomatis untuk setiap temuan). Mencakup: TC-GW-01 bebas `{{ }}` di literal string, TC-GW-02 brace seimbang pada CSS inject, TC-GW-03 `forceFit:false` & tiada `forceFit:true` sisa, TC-GW-04 keempat tabel dashboard ber-id + CSS scoped + `.text-right` tetap nowrap, TC-GW-05 `node --check` bersih.
  - `docs/AUDIT_GRID_WRAP_REPORT.md` — (laporan induk gelombang A/B sudah ada; temuan gelombang 3 dicatat di sini & AUDIT_CHANGELOG).
- **Ringkasan Teknis**: akar masalah sama dengan gelombang sebelumnya — rule global ExtJS 3.4.1 `.x-grid3-cell-inner,.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}` + `forceFit:true` memotong header/sel. Pola fix: override CSS **scoped** via `<style>` yang di-inject JS (IIFE `injectGridWrapCss()`), `forceFit:false` + `scrollOffset:0` (scroll horizontal alih-alih potong), `white-space:normal`/`text-overflow:clip`/`word-wrap:break-word`/`height:auto` pada hd-inner/cell-inner/row td/header-offset; align angka kanan tetap dihormati (hanya `white-space` yang berubah). CSS global (`ext-all.css`, `grddetail.css`, `mainCSS.css`, `js-func/ExtExtend.js`) TIDAK diubah. Dashboard: aturan `<style>` scoped per `id` tabel (`#profit_table`, `#aging_table`, `#tax_rate_table`, `#material_table`) dengan pengecualian `.text-right` tetap `nowrap`.
- **Status Verifikasi**: `php tests/run_grid_wrap_static.php` → **PASS=52 FAIL=0**; `php -l views/dashboard.php` bersih; `node --check` lolos untuk 6 file JS gelombang 3. Temuan luar scope: `js-func/ExtExtend.js` (`stdGrid*`) masih `forceFit:true` global — TIDAK diubah agar tidak merusak modul lain (sudah tercatat di laporan gelombang B).
- **Status Git**: semua perubahan **Uncommitted** (menunggu instruksi user untuk commit).

---
**Auto-Log (2026-11-17 12:05:00)**

---
**Auto-Log (2026-11-17 11:57:55)**

