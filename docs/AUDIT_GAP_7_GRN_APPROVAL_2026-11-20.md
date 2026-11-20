# Audit Implementasi: Gap #7 - GRN Approval Gate

**Tanggal:** 2026-11-20
**Status:** Selesai

## 1. Tujuan
Menerapkan mekanisme Approval Gate pada Penerimaan Barang (GRN) untuk memastikan:
- Hanya GRN yang telah disetujui (`approval_status = 'APPROVED'`) yang dapat diproses ke tahap Warehouse Receiving atau dimatch ke Purchase Invoice.
- GRN berstatus `PENDING` tidak dapat menambah stok gudang atau ditarik menjadi tagihan.

## 2. Implementasi Teknis

### A. Database
- Menambahkan kolom `approval_status`, `approved_by`, `approved_at`, `approved_note` pada tabel `public.grn_hdr`.
- Migrasi `sql/2026-11-20_grn_approval_gate_gap7.sql` telah dijalankan, memastikan semua GRN lama yang sudah ada di sistem (berstatus 'partial'/'confirmed') secara otomatis berubah menjadi `APPROVED`, sisanya `PENDING`.
- Menambahkan constraint FK dan trigger guard untuk memastikan pembayaran supplier tidak melebihi nilai invoice.

### B. Backend (`php-crud/grnCRD.php`)
- Menambahkan task `approve_grn`, `reject_grn`, dan `unapprove_grn` untuk mengelola status approval.
- Memperbarui fungsi `create` untuk menyetel status default `PENDING`.
- Memperbarui fungsi `update` agar tidak mengizinkan GRN yang sudah `APPROVED` untuk diedit.
- Memperbarui fungsi `unapprove_grn` dengan guard: hanya bisa dibatalkan jika belum ada Warehouse Receiving atau Invoice terkait.

### C. Backend (`php-crud/whrcvCRD.php` & `purchaseinvoiceCRD.php`)
- Memperbarui query daftar GRN (task `list_grn_receivable` dan `PiGrnInfo`) untuk memfilter hanya GRN dengan `approval_status = 'APPROVED'`.

### D. Frontend (`js/grn.js`)
- Menambahkan kolom `Approval` pada grid utama dengan indikator status warna (Approved: hijau, Rejected: merah, Pending: oranye).
- Menambahkan Toolbar Approval (Approve/Reject/Batal) pada panel detail GRN.

## 3. Verifikasi
- Migrasi DB berhasil dijalankan.
- Backend logic untuk approval gate terverifikasi.
- UI ExtJS 3.4.1 diperbarui dan terintegrasi dengan workflow baru.

**Catatan:** Semua GRN baru kini wajib melalui proses approval sebelum bisa ditarik ke tahap berikutnya.
