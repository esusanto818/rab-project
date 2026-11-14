# Layer "Release Tagihan" — Dokumentasi Implementasi

**Tanggal:** 2026-11-14
**Branch:** `dev`
**Status:** Implementasi selesai, migrasi DB dev sudah diterapkan

---

## 1. Latar Belakang Masalah

Sebelum perubahan ini, setiap invoice/termin yang disimpan di menu **Tagihan ke Klien**
langsung dianggap tagihan aktif:

- `php-crud/project.paymentCRD.php` → `list_outstanding` mengambil **semua** invoice
  yang masih punya sisa tagihan, tanpa memedulikan apakah dokumen tagihannya sudah
  **diterbitkan/diserahkan ke klien**.
- Jurnal penagihan juga langsung diposting saat invoice disimpan.

Akibatnya user melihat terlalu banyak tagihan di **Pembayaran Termin** — termasuk yang
belum pernah dibuatkan dokumen tagihan untuk klien — sehingga membingungkan.

## 2. Solusi: Siklus Dokumen DRAFT → RELEASED

| Status | Arti | Efek |
|--------|------|------|
| `draft` | Invoice baru diketik (internal) | **Tidak** muncul di Pembayaran Termin; jurnal **belum** posting; masih boleh diubah/dihapus |
| `released` | Dokumen tagihan sudah diterbitkan (nomor `TAG-YYMM-NNNNN`) | Muncul di Pembayaran Termin; bisa dibayar; jurnal penagihan **posting**; bisa dicetak untuk diserahkan ke klien |

### Alur

```
Save Invoice (draft)
      ↓
Release Invoice → set release_no (TAG-YYMM-NNNNN), release_date, released_by
      ↓
Jurnal Penagihan diposting (PROJECT_INVOICE + PROJECT_INVOICE_PPN)
      ↓
Invoice masuk list_outstanding (Pembayaran Termin)
      ↓
Payment klien → alokasi → Lunas / Sebagian / deposit
```

- **Unrelease** (batalkan terbit): hanya bila belum ada pembayaran →
  kembali ke draft + reverse jurnal.
- **Bulk release**: filter Status Dokumen = DRAFT → tombol "Release Semua (filter)".

### Keputusan user

- **Data lama: semua invoice menjadi `draft`** (12 baris per saat migrasi) — harus
  di-release manual atau bulk dari menu Tagihan ke Klien.

## 3. Migrasi Database

File: `sql/2026-11-14.project_invoice_release.sql`

Kolom baru di `public.project_invoices`:

| Kolom | Tipe | Default | Keterangan |
|-------|------|---------|------------|
| `release_status` | text NOT NULL | `'draft'` | draft \| released |
| `release_no` | varchar(30) | NULL | nomor dokumen tagihan (TAG-yymm-00001) |
| `release_date` | date | NULL | tanggal dokumen diterbitkan |
| `released_by` | integer | NULL | m_user_system.id yang men-release |
| `release_note` | text NOT NULL | `''` | catatan penerbitan (opsional) |

Plus index `idx_project_invoices_release_status` dan unique constraint
`project_invoices_release_no_key` (boleh banyak NULL).

Cara menjalankan:

```bash
psql -h <host> -U postgres -d proyekGMN -v ON_ERROR_STOP=1 \
  -f sql/2026-11-14.project_invoice_release.sql
```

## 4. File yang Diubah / Dibuat

| File | Perubahan |
|------|-----------|
| `sql/2026-11-14.project_invoice_release.sql` | **Baru** — migrasi kolom release + index + constraint |
| `php-crud/project.invoiceCRD.php` | **Edit incremental** (penanda `[RELEASE-LAYER]`) — task `release_invoice`, `release_invoices` (bulk), `unrelease_invoice`; filter `doc_status`; kolom grid release; jurnal dipindah dari save ke release; parameter `release_note` opsional pada release |
| `php-crud/project.paymentCRD.php` | `list_outstanding` + ringkasan hanya `released`; `save_termin` membuat DRAFT tanpa jurnal; validasi `save_payment` menolak termin draft |
| `js/project.invoice.js` | **Edit incremental** (penanda `[RELEASE-LAYER]`) — filter Status Dokumen, tombol Release / Batalkan Release / Release Semua (filter), kartu "Belum Diterbitkan", kolom "Dokumen Tagihan", input catatan penerbitan (opsional) saat release |
| `js/project.payment.js` | Kolom "No. Dokumen" (`release_no`) di grid termin, info draft di ringkasan kontrak, empty text arahan release |
| `pdf/project.invoice.php` | Header cetak menampilkan No. Dokumen Tagihan + tanggal terbit, atau status DRAFT |
| `php-crud/m.proyekCRD.php` | Invoice otomatis (rencana tagihan) dibuat DRAFT; jurnal hanya untuk invoice released; sync tanggal tidak posting jurnal draft |
| `php-crud/dashboardCRD.php` | AR aging, invoice pipeline, action center (overdue/due soon/faktur missing) hanya `released` |
| `php-func/project.analysis.php` | Konteks analisis AI (`dash_project_context`) hanya `released` |
| `php-crud/ppn.reportCRD.php` | Laporan PPN Keluaran hanya `released` |

## 5. API Endpoint Baru (project.invoiceCRD.php)

| Task | Metode | Akses | Fungsi |
|------|--------|-------|--------|
| `release_invoice` | POST | level 3 | Terbitkan satu invoice (draft → released) |
| `release_invoices` | POST | level 3 | Bulk release; `ids` = JSON array / CSV |
| `unrelease_invoice` | POST | level 3 | Batalkan terbit (released → draft, reverse jurnal) |

Aturan:

- Release/release bulk/unrelease hanya POST.
- Nomor dokumen dibuat dalam transaksi PDO yang sama + `pg_advisory_xact_lock`
  (format `TAG-YYMM-NNNNN`).
- `release_note` opsional pada `release_invoice` (maks 200 karakter, trim); nilai kosong
  disimpan sebagai `''`. Pada `release_invoices` (bulk) catatan dibuat per-invoice
  (bukan satu note untuk semua).
- Jurnal posting saat release hanya bila invoice **belum punya** jurnal
  (`ti_has_journal`) — mencegah jurnal ganda untuk invoice lama pra-migrasi.
- Untuk invoice lama yang sudah punya jurnal saat masih draft: saat diedit jurnal lama
  di-reverse, lalu diposting ulang saat release.

## 6. Verifikasi yang Sudah Dilakukan

- Migrasi DB dev diterapkan: 12 invoice lama berstatus `draft`.
- Kolom baru terverifikasi via `information_schema`.
- `php -l` semua file PHP yang berubah: No syntax errors.
- `node --check` untuk `js/project.invoice.js` dan `js/project.payment.js`: OK.

## 7. Yang Telah Dilakukan & Langkah Berikutnya

### ✅ Telah diselesaikan (2026-11-14)

1. **Perbaikan dokumentasi**: "Rewrite full" dikoreksi menjadi "Edit incremental"
   (penanda `[RELEASE-LAYER]`); `release_note` didokumentasikan sebagai parameter opsional.
2. **Dukungan `release_note`**: parameter `release_note` (maks 200 karakter, opsional, di-trim)
   ditambahkan ke API `release_invoice` & `release_invoices` (bulk) via helper `ti_read_release_note()`;
   kolom ikut terpilih di `list_invoices` / `load_invoice`, ditampilkan di panel detail UI
   ("Catatan terbit:"), di header PDF ("Catatan Terbit:"), dan ada input prompt di UI saat
   release tunggal maupun bulk. `unrelease_invoice` mengosongkan kembali ke `''`.
3. **Verifikasi statis**: `php -l` semua file PHP yang berubah + `node --check` kedua file JS: lolos.
   Uji unit helper `ti_read_release_note` + `ti_valid_date` (tanpa DB): lolos.

### ⏳ Belum selesai / terkendala

1. **Uji end-to-end di UI & DB** (belum bisa dijalankan dari sesi ini):
   - Kredensial/koneksi DB tidak dapat diakses dari tool yang tersedia (pembacaan config
     ditolak classifier), dan server web lokal tidak dikonfirmasi menjangkau DB yang sama.
   - Checklist yang masih perlu dijalankan manual oleh user (atau oleh sesi dengan akses DB):
     - Buat invoice → muncul DRAFT, tidak ada di Pembayaran Termin.
     - Release → nomor TAG muncul, jurnal posting, muncul di Pembayaran Termin.
     - Pembayaran → alokasi berhasil; termin draft ditolak saat `save_payment`.
     - Unrelease → kembali draft, jurnal reverse.
     - `release_note` tersimpan & tampil di detail UI dan PDF.
2. **Bulk release** data lama (12 invoice draft): filter Status Dokumen = DRAFT → "Release Semua (filter)".
3. **Commit** perubahan ke branch `dev` / PR.
4. **Backup DB** sebelum diterapkan di produksi.

### 🔲 Langkah berikutnya (produksi)

1. Backup DB → terapkan migrasi + kode.
2. Pantau dashboard AR & laporan PPN pasca-release data lama.
3. Sosialisasikan ke user: alur baru Simpan → Release → Bayar.

## 8. Catatan Konsekuensi Data Lama

Karena semua data lama dijadikan DRAFT:

- **Pembayaran Termin kosong** sampai invoice di-release.
- **Dashboard AR & Laporan PPN** hanya menghitung tagihan released, sehingga angka
  bisa menurun sampai proses release data lama selesai.
- Untuk mempercepat: gunakan tombol **Release Semua (filter)** per client/filter.
