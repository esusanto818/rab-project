---
name: gap-6-pph-supplier
description: Implementasi PPh Supplier (akun, rule, dan kolom)
metadata:
  type: project
---

Implementasi pemotongan PPh pada Invoice Supplier.

**Status:** Selesai.
**Perubahan:** 
- Penambahan kolom `pph_amount` (NUMERIC) dan `pph_rate` (NUMERIC) di `purchase_invoice_hdr`.
- Penambahan akun CoA `Hutang PPh Supplier` (2140).
- Penambahan rule jurnal `PPH_SUPPLIER`.
- Update `purchaseinvoiceCRD.php` untuk menyimpan PPh dan memposting jurnal otomatis.

**Why:** Agar sistem bisa memotong PPh Supplier secara otomatis saat pembuatan invoice.
**How to apply:** Pastikan migrasi database diterapkan; fitur PPh otomatis aktif di form invoice supplier.
[[gap-6-pph-supplier]]
