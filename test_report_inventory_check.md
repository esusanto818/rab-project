# Test Report Cek Inventory sebelum Purchase Planning / PO (TC-101 s/d TC-110)

**Tanggal eksekusi:** 2026-11-15 10:42:21  
**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `tests/run_e2e_inventory_check.php`  
**Ringkasan sementara:** PASS = 10, FAIL = 0, SKIP = 0

> Fitur: pengecekan inventory sebelum membuat purchase planning/PO. Stok yang dihitung hanya milik proyek
> sendiri (gudang `warehouses.project_id`) + gudang bersama (`project_id IS NULL`). Stok milik proyek lain
> tidak boleh dipinjam. Kebutuhan bersih (`qty_to_buy`) = sisa kuota RAB - stok tersedia.

<!-- BEGIN:Kelompok F — Cek Inventory sebelum Purchase Planning / PO -->
## Kelompok F — Cek Inventory sebelum Purchase Planning / PO

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-101 | load_planning: qty_on_hand & qty_to_buy sesuai stok proyek sendiri | PASS | M1 on_hand=40 remaining=100.000 to_buy=60 \| M2 on_hand=0 to_buy=50 |
| TC-102 | stok proyek lain (500 di WH-B) TIDAK dihitung; total DB 540, P1 hanya 40 | PASS | total_db=540 p1_on_hand=40 |
| TC-103 | check_inventory: qty sesuai kebutuhan bersih dinyatakan OK | PASS | Baut Hex E2E-INV: covered=40 to_buy=20 \| Mur Hex E2E-INV: covered=0 to_buy=50 |
| TC-104 | check_inventory: qty 101 > kuota RAB 100 ditolak (ok=false) | PASS | Baris 1: qty rencana 101 Buah melebihi sisa kuota RAB 100 Buah; stok tersedia 40 Buah, perlu beli 61 Buah. |
| TC-105 | dua baris RAB material sama: stok hanya dipakai sekali (tidak ditindih) | PASS | M1 baris1 cov=30 tb=0 \| M1 baris2 cov=10 tb=10 \| M2 tb=10 |
| TC-106 | poCRD list_rab_items: qty_on_hand=40 & qty_to_buy=60 untuk M1 | PASS | on_hand=40 to_buy=60 |
| TC-110 | kebutuhan bersih M1 = 60 meski proyek lain punya 500 (stok lintas proyek tidak dipinjam) | PASS | qty_to_buy=60 on_hand=40 |
| TC-108 | save_po: qty 100 (tanpa kurangi stok 40) ditolak "melebihi kebutuhan bersih" | PASS | Baut Hex E2E-INV: qty PO 100 melebihi kebutuhan bersih 60 Buah (sisa kuota RAB 100 - stok tersedia 40). |
| TC-109 | save_po: material tanpa stok (M2) qty 50 = kebutuhan bersih diterima | PASS | po_number=PO-2611-00001 |
| TC-107 | save_po: qty 60 (= sisa kuota 100 - stok 40) diterima | PASS | po_number=PO-2611-00002 |
<!-- END:Kelompok F — Cek Inventory sebelum Purchase Planning / PO -->
