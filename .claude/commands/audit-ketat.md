---
description: Forensic Deep-Dive Audit per batch (termasuk opsi khusus auto-posting keuangan & pajak) tanpa mengubah kode
argument-hint: "[batch1 | batch2 | batch3 | batch4 | keuangan | nama_modul]"
---

Jalankan **FORENSIC SYSTEM AUDIT** terhadap target: `$ARGUMENTS`

Wajib memuat dan mematuhi `CLAUDE.md` (v3.0) serta skill `forensic-audit-guard` dan `token-budget-guard`.

## 1. ATURAN MUTLAK (NO SHORTCUT)
1. **AUDIT FIRST — NO PATCH:** DILARANG mengubah source code aplikasi, konfigurasi, atau schema database. Gunakan `proyekGMN_test` hanya untuk pengujian yang memodifikasi data.
2. **ANTI-LAPORAN MANIS:** DILARANG menulis kata `PASS` secara normatif tanpa menyertakan bukti nyata (`nama_file:nomor_baris`, snippet kode/query, dan hasil pengujian).
3. **BATAS TOKEN PER SIKLUS:** Periksa maksimal **4 file** per siklus baca. Setelah selesai 1 batch, **LANGSUNG append** temuan ke `docs/DEEP_AUDIT_REGISTER.md` agar tersimpan permanen.

## 2. PANDUAN TARGET BATCH (`$ARGUMENTS`)
- Jika `$ARGUMENTS` kosong atau `batch1`:
  Audit keamanan & RBAC: `php-func/user.valid.php` (`$endpointAliases`), `js/m.user.role.js`, `php-crud/m.user.roleCRD.php`, `js/m.user.system.js`, `php-crud/m.user.systemCRD.php`, serta sinkronisasi `m_form_name`.
- Jika `$ARGUMENTS` = `batch2`:
  Audit RAB/RAP & Procurement/Inventory: perhitungan RAB/RAP, `CLIENT_SUPPLIED` (Rp 0), `php-crud/poCRD.php`, `js/po.js`, GRN, `stock_movements`, penomoran `CreateRefNo` / `ti_next_release_no`, komentar `//` dalam SQL, dan regex titik `.`.
- Jika `$ARGUMENTS` = `batch3`:
  Audit Subcontract & Opname: SPK Internal, SPK Subcon, mekanisme *Subcon Buy-Back*, validasi akumulasi opname progress `<= 100%` di backend, dan pemotongan uang muka (DP).
- Jika `$ARGUMENTS` = `batch4`:
  Audit UI/UX, Dashboard, PDF, & Release Gate: `views/dashboard.php`, `php-crud/dashboardCRD.php`, mobile views (`*.m.php` / `stock.card.m.js`), modul `pdf/` (akses tanpa session & pembagian nol), file `*.PHP` huruf besar, serta evaluasi **Release Readiness Gate** (`CLAUDE.md` §12).
- Jika `$ARGUMENTS` = `keuangan`:
  **FORENSIC AUDIT KHUSUS AUTO-POSTING INVENTORY, KEUANGAN, & PAJAK (INDONESIA):**
  1. **Pencarian Helper Jurnal:** Telusuri seluruh fungsi posting jurnal di `php-func/`, `php-class/`, dan seluruh pemanggilnya di `php-crud/` menggunakan `rg`.
  2. **Trigger Status & Atomisitas:** Pastikan auto-posting pada GRN, Pemakaian/Mutasi Stok, Retur, Purchase Invoice, Opname Subkon & Buy-Back, Tagihan Klien, dan Kas/Bank HANYA berjalan saat status berubah ke `Approved`/`Released`/`Posted` (bukan `Draft`), di dalam 1 blok `BEGIN ... COMMIT / ROLLBACK` + `FOR UPDATE`.
  3. **Material `CLIENT_SUPPLIED` (Rp 0) & Retur:** Pastikan `CLIENT_SUPPLIED` memperbarui kuantitas stok fisik tanpa merusak nilai nominal Persediaan/Biaya/Hutang, dan pastikan Pemakaian Stok / Retur menghasilkan jurnal biaya proyek serta jurnal pembalik (*reversal*) saat `Void`/`Unpost`.
  4. **Keseimbangan Jurnal & Mapping COA:** Buktikan validasi `SUM(debit) == SUM(credit)` sebelum `COMMIT`, serta penanganan aman jika mapping COA (`fiscal_config`, `PRJ_DEP_APPLY`, master kategori) kosong/NULL.
  5. **Perpajakan Indonesia & Retensi:** Verifikasi pemisahan jurnal DPP, PPN Masukan/Keluaran, potongan PPh Pasal 4(2) Jasa Konstruksi / PPh 23 (jika berlaku), dan pemisahan piutang/hutang **Retensi** ke akun COA Retensi tersendiri.
  6. **Penguncian Tutup Buku (`accounting_periods`):** Pastikan setiap auto-posting dan pembatalan transaksi menolak tanggal dokumen pada periode berstatus `Closed`.
  7. **Anti Double-Posting & Rekonsiliasi Sub-Ledger:** Buktikan klik Approve/Post dua kali tidak menghasilkan jurnal ganda, dan rekonsiliasi logika saldo `ar.agingCRD.php`, `ap.agingCRD.php`, dan `stock.cardCRD.php` terhadap Buku Besar.
  8. **Output Matriks Wajib:** Sertakan tabel matriks status auto-posting per modul (`Modul | Trigger Status | Debit/Kredit COA | Cek Periode | Anti-Double Post | Bukti Baris Kode`) di `docs/DEEP_AUDIT_REGISTER.md`.
- Jika `$ARGUMENTS` berupa nama modul/file spesifik:
  Audit lintas-lapisan (View -> JS -> PHP CRD -> SQL/DB -> PDF) khusus untuk modul tersebut.

## 3. FORMAT OUTPUT KE `docs/DEEP_AUDIT_REGISTER.md`
Untuk setiap temuan wajib cantumkan:
- **Finding ID** (misal `AUD-001`, `FIN-001`)
- **Severity** (`CRITICAL` / `HIGH` / `MEDIUM` / `LOW`)
- **Lokasi Kode** (`path/file:baris`)
- **Bukti Aktual** (cuplikan kode/query/output uji)
- **Akar Masalah & Dampak Bisnis/Keuangan**
- **Rekomendasi Perbaikan**
- **Test Case & Status** (`FAIL` / `NOT TESTED` / `VERIFIED`)
