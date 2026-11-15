# Test Case End-to-End: Proyek → Invoice → Release → Pembayaran → Jurnal Otomatis

**Tanggal:** 2026-11-14
**Branch:** `dev` (baseline: commit `7cb757c`)
**Status:** ⚠️ BELUM DIEKSEKUSI — dokumen ini adalah rancangan; semua baris masih perlu dijalankan manual di UI / API (lihat bagian "Cara Menjalankan").

---

## Cara Menjalankan

1. Login sebagai user dengan **FormAccess level ≥ 3** (release) dan ≥ 4 (hapus).
2. Siapkan data uji: 1 client, 2 proyek milik client itu, 1 proyek milik client lain (untuk uji silang client).
3. Pastikan prasyarat akuntansi: `fiscal_config` rule `PROJECT_INVOICE`, `PROJECT_INVOICE_PPN`, `PRJ_PAY_AR`, `PRJ_PAY_DEP`, `PRJ_DEP_APPLY` aktif; periode akuntansi bulan berjalan **open**.
4. Jalankan tiap skenario sesuai urutan Langkah, cocokkan dengan Ekspektasi Hasil.
5. Untuk skenario kegagalan: pastikan **tidak ada** perubahan yang tersisa di DB (transaksi rollback) — cek `project_invoices.paid_amount`, `project_payments`, `journal_entries`.

Singkatan: "list_outstanding" = endpoint `list_outstanding` di menu Pembayaran Termin; "jurnal" = baris di `journal_entries` + `journal_lines`.

---

## A. Persiapan & Invoice (fase DRAFT)

| ID | Skenario | Langkah | Ekspektasi Hasil |
|----|----------|---------|------------------|
| TC-001 | Prasyarat sistem | Cek `fiscal_config` & `accounting_periods` | Semua rule penagihan/pembayaran aktif; periode bulan berjalan `is_closed = false`. |
| TC-002 | Simpan invoice baru = DRAFT | Tagihan ke Klien → Add → proyek uji, DPP 10.000.000, PPN 11% → Save | Tersimpan `release_status='draft'`; pesan: "Invoice … disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan resmi ke client."; **belum ada** `journal_entries` dengan `reference_type='project_invoice'`; **tidak muncul** di list_outstanding Pembayaran Termin. |
| TC-003 | Edit & hapus invoice DRAFT | Edit nama termin → Save; hapus draft lain | Berhasil (draft tanpa pembayaran masih boleh diubah/dihapus). |
| TC-004 | Edit invoice RELEASED | Buka invoice yang sudah di-release → Edit → Save | Ditolak: "Invoice … sudah diterbitkan (released); batalkan release dulu untuk mengubah." |
| TC-005 | Hapus invoice RELEASED | Hapus invoice released | Ditolak: "Invoice … sudah diterbitkan; batalkan release dulu sebelum menghapus." |
| TC-006 | Total DPP melebihi kontrak | Simpan invoice sehingga total DPP > contract_value | Ditolak: "Total termin DPP (…) melebihi nilai kontrak (…)". |

## B. Release (draft → released)

| ID | Skenario | Langkah | Ekspektasi Hasil |
|----|----------|---------|------------------|
| TC-010 | Release satu invoice | Pilih baris DRAFT → "Release Tagihan" → ya | `release_status='released'`; `release_no` = `TAG-YYMM-NNNNN`; `release_date` = hari ini; `released_by` = user login; jurnal **PROJECT_INVOICE** (Dr Piutang Usaha / Cr Pendapatan, senilai DPP) + **PROJECT_INVOICE_PPN** (Dr Piutang / Cr PPN Keluaran, senilai PPN) bila ppn>0; invoice muncul di list_outstanding. |
| TC-011 | Release dengan catatan | Isi catatan "Tagihan Nov 2026" saat release | `release_note` tersimpan; tampil di panel detail UI ("Catatan terbit: …") dan di PDF ("Catatan Terbit: …"). |
| TC-012 | Release invoice sudah released | Release ulang invoice yang sudah released | Ditolak: "Invoice … sudah diterbitkan (status: released)." |
| TC-013 | Release invoice punya pembayaran | Release invoice yang sudah ada alokasi pembayaran | Ditolak: "Invoice … sudah memiliki pembayaran, tidak dapat diterbitkan ulang." |
| TC-014 | Tanggal terbit tidak valid | `release_date` = "2026-13-01" | Ditolak: "Tanggal terbit tidak valid." |
| TC-015 | Bulk release (filter DRAFT) | Filter Status Dokumen = DRAFT → "Release Semua (filter)" → isi catatan → ya | Semua draft pada filter aktif di-release; nomor TAG berurutan tanpa ganda; jurnal posting hanya untuk invoice yang belum punya jurnal; invoice pra-migrasi yang sudah punya jurnal **tidak** diposting ulang. |
| TC-016 | Bulk release tanpa pilihan | Panggil `release_invoices` dengan `ids` kosong | Ditolak: "Tidak ada invoice yang dipilih untuk diterbitkan." |
| TC-017 | Unrelease sebelum bayar | Pilih invoice released tanpa pembayaran → "Batalkan Release" → ya | Kembali ke `draft`; `release_no/release_date/released_by` = NULL; `release_note=''`; jurnal penagihan di-reverse; invoice hilang dari list_outstanding. |
| TC-018 | Unrelease sudah ada pembayaran | Batalkan Release pada invoice lunas sebagian | Ditolak: "Invoice … sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan." |

## C. Pembayaran Termin & Jurnal Otomatis

| ID | Skenario | Langkah | Ekspektasi Hasil |
|----|----------|---------|------------------|
| TC-020 | Bayar termin released (IDR, lunas) | Pembayaran Termin → pilih client → centang termin released → kas/bank + metode BANK_TRANSFER + jumlah pas → Save | Pembayaran `PPAY-…` tersimpan; `paid_amount = amount`; status "Lunas"; jurnal **PRJ_PAY_AR** (Dr Kas/Bank / Cr Piutang Usaha) dengan `reference_type='project_payment'`. |
| TC-021 | Bayar sebagian | Bayar 50% dari sisa tagihan | Status "Sebagian"; sisa = amount − paid_amount benar. |
| TC-022 | Lebih bayar → deposit | Bayar lebih dari total alokasi | `excess_amount > 0`; jurnal **PRJ_PAY_DEP** (kredit Uang Muka Penjualan); saldo deposit client bertambah; pesan "Lebih bayar … masuk deposit client." |
| TC-023 | Pakai deposit | Pembayaran berikutnya: isi "pakai deposit" | Jurnal **PRJ_DEP_APPLY** (Dr Uang Muka Penjualan / Cr Piutang Usaha) **tanpa** kas/bank; saldo deposit berkurang. |
| TC-024 | Lunas penuh dari deposit (tanpa kas) | Bayar penuh pakai deposit, akun kas dikosongkan | Diterima (akun kas tidak wajib bila seluruhnya dari deposit). |
| TC-025 | Multi-termin lintas proyek | Alokasikan 1 pembayaran ke termin 2 proyek milik client yang sama | Semua termin ter-update; satu nomor pembayaran; jurnal PRJ_PAY_AR untuk total kas. |
| TC-026 | Multi-currency | Bayar pakai USD + kurs hari itu | `payIdr = pay × rate`; rounding sesuai toleransi; jurnal dalam IDR. |
| TC-027 | Hapus pembayaran | delete_payment pada pembayaran biasa | Jurnal di-reverse (`ReverseJournalByReference('project_payment')`); `paid_amount` kembali. |
| TC-028 | Hapus pembayaran sumber deposit terpakai | delete_payment bila deposit-nya sudah dipakai pembayaran lain | Ditolak: "Pembayaran ini tidak bisa dihapus: deposit yang dihasilkannya sudah dipakai pembayaran lain…". |
| TC-029 | Cetak invoice released | Buka `pdf/project.invoice.php?id=…` | Header: "No. Dokumen Tagihan: TAG-… | Tgl Terbit: …"; bila DRAFT: "STATUS: DRAFT - dokumen tagihan belum diterbitkan ke client". |
| TC-030 | Faktur pajak duplikat | Isi no. faktur pajak yang sama di 2 invoice | Ditolak: "No. faktur pajak … sudah dipakai invoice …". |

## D. Skenario Kegagalan Pembayaran (semua harus rollback — tanpa jurnal, tanpa perubahan DB)

| ID | Skenario | Langkah | Ekspektasi Hasil |
|----|----------|---------|------------------|
| TC-040 | Bayar termin DRAFT | Alokasikan pembayaran ke termin belum di-release | Ditolak: "…belum diterbitkan (status draft). Release tagihan dulu di menu Tagihan ke Klien." |
| TC-041 | Termin silang client | Alokasikan termin milik client lain | Ditolak: "…bukan milik client ini." |
| TC-042 | Bayar melebihi sisa | Nilai bayar > sisa tagihan | Ditolak: "…nilai bayar … melebihi sisa tagihan (…)". |
| TC-043 | Termin dobel | Pilih termin yang sama 2× | Ditolak: "…termin dobel." |
| TC-044 | Kas/bank kosong | Tanpa akun kas & tanpa deposit | Ditolak: "Akun Kas/Bank wajib dipilih." |
| TC-045 | Metode tidak valid | method = "TRANSFER" | Ditolak: "Metode pembayaran tidak valid." (valid: BANK_TRANSFER, CASH, GIRO, CEK) |
| TC-046 | Kurs kosong untuk non-IDR | currency=USD, exch_rate=0 | Ditolak: "Kurs USD wajib diisi." |
| TC-047 | Deposit tidak cukup | depIn > saldo deposit | Ditolak: "Deposit client tidak cukup (tersedia …)". |
| TC-048 | Deposit > alokasi | depIn > total alokasi | Ditolak: "Deposit yang dipakai melebihi total alokasi termin." |
| TC-049 | Kas + deposit < alokasi | Dana lebih kecil dari alokasi | Ditolak: "Kas diterima + deposit lebih kecil dari total alokasi termin." |
| TC-050 | Jumlah diterima ≤ 0 | total = 0 | Ditolak: "Jumlah diterima harus lebih dari 0." |
| TC-051 | Jumlah diterima < alokasi | total < alokasi | Ditolak: "Jumlah diterima lebih kecil dari total alokasi termin." |
| TC-052 | Tanpa alokasi | items kosong | Ditolak: "Alokasikan pembayaran ke minimal satu termin." |
| TC-053 | Periode akuntansi tutup | Tutup periode tanggal bayar → Save pembayaran | AutoPostJournal gagal ("Periode … sudah ditutup") → pembayaran batal, ROLLBACK: tidak ada PPAY baru, `paid_amount` tidak berubah. |
| TC-054 | Rule fiscal nonaktif | Nonaktifkan rule PRJ_PAY_AR → Save | Gagal posting jurnal → ROLLBACK; pesan error asli AutoPostJournal tampil. |
| TC-055 | Akses level rendah | User level < 3 panggil `release_invoice` | HTTP 403: "Hak akses tidak mencukupi untuk operasi invoice proyek ini." |
| TC-056 | Metode bukan POST | GET `release_invoice` | HTTP 405: "Metode harus POST untuk perubahan invoice proyek." |
| TC-057 | release_note > 200 karakter | Release dengan catatan 201 karakter | Ditolak: "Catatan penerbitan terlalu panjang (maksimal 200 karakter)." |

## E. Integrasi Pelaporan (hanya RELEASED yang dihitung)

| ID | Skenario | Langkah | Ekspektasi Hasil |
|----|----------|---------|------------------|
| TC-060 | Dashboard AR aging | Buka dashboard setelah ada draft + released | Aging hanya menghitung released; draft tidak muncul. |
| TC-061 | Invoice pipeline & action center | Buka dashboard | inv_overdue / inv_due_soon / faktur_missing hanya menghitung released. |
| TC-062 | Laporan PPN Keluaran | Jalankan rpt_keluaran | Hanya released; DRAFT tidak muncul meski ada ppn_amount. |
| TC-063 | Konteks AI | `dash_project_context` | Hanya released yang masuk konteks analisis. |
| TC-064 | Invoice otomatis dari rencana tagihan | Apply plan di m.proyekCRD | Invoice otomatis = DRAFT tanpa jurnal; sync tanggal tidak posting jurnal bila masih draft. |
| TC-065 | Ringkasan kontrak di Pembayaran | Deskripsi client | total_termin/total_paid hanya released; draft dilaporkan terpisah ("draft N x (nilai)"). |

---

## Data Uji yang Disarankan

| Data | Nilai |
|------|-------|
| Client A | punya 2 proyek (untuk TC-025) |
| Client B | punya 1 proyek (untuk TC-041) |
| Invoice 1 | DPP 10.000.000 + PPN 11% (TC-002..TC-018) |
| Invoice 2 | DPP tanpa PPN (ppn_rate=0) — cek jurnal hanya PROJECT_INVOICE |
| Invoice 3 | Pra-migrasi yang sudah punya jurnal (TC-015: tidak diposting ulang) |
| Pembayaran | IDR lunas, sebagian, lebih bayar, pakai deposit, USD berkurs (TC-020..TC-026) |

## Hasil Eksekusi (isi saat menjalankan)

| Tanggal | Pelaksana | TC | Hasil | Bukti |
|---------|-----------|-----|-------|-------|
| … | … | … | … | … |
