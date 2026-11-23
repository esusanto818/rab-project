# Test Report Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 (TC-CS-01 s/d TC-CS-12)

**Tanggal eksekusi:** 2026-11-23 14:19:59  
**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `tests/run_e2e_client_supplied.php`  
**Ringkasan sementara:** PASS = 3, FAIL = 9, SKIP = 0

> Fitur: material titipan klien (budget_items.material_source = 'CLIENT_SUPPLIED') Rp 0.
> qty_to_buy wajib 0 di Planning/PO, save_po menolak, GRN/WH Rcv memaksa biaya 0,
> fisik masuk/keluar stok tercatat di stock_movements tanpa jurnal rupiah.

<!-- BEGIN:Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 -->
## Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-CS-01 | RAB tersimpan: budget_items.material_source = CLIENT_SUPPLIED | PASS | material_source='CLIENT_SUPPLIED' |
| TC-CS-02 | RAB kontrol: material_source = COMPANY (default) | PASS | material_source='COMPANY' |
| TC-CS-03 | load_planning berhasil | FAIL | http=0 raw= |
| TC-CS-04 | load_planning: COMPANY (M2) qty_to_buy = 50 normal (bukan 0) | FAIL | M2 tidak ada |
| TC-CS-05 | list_rab_items berhasil | FAIL |  |
| TC-CS-06 | save_po: budget_item CLIENT_SUPPLIED DITOLAK (tidak boleh masuk PO pembelian) | PASS |  |
| TC-CS-07 | save_po: COMPANY (M2) qty 10 diterima (alur normal tidak terganggu) | FAIL |  |
| TC-CS-08 | grnCRD create: unit_cost DIPAKSA 0 untuk CLIENT_SUPPLIED (kiriman 12345 diabaikan) | FAIL | unit_cost_tersimpan=NULL \|  |
| TC-CS-09 | whrcvCRD: fisik IN 100 @ Rp 0 di stock_movements + TANPA jurnal GRN_RCV | FAIL | mv=null jurnal_rows=-1 |
| TC-CS-10 | issuing: OUT 30 @ Rp 0 (saldo 70) + material_out_items Rp 0 + TANPA jurnal ISSUE_PROJECT | FAIL | mv=null item_cost=n/a jurnal_rows=-1 saldo=0 |
| TC-CS-11 | load_planning pasca-issue: CLIENT_SUPPLIED to_buy tetap 0 (stok fisik 70 tidak mengubah kebutuhan beli) | FAIL | M1 tidak ada |
| TC-CS-12 | check_inventory: baris CLIENT_SUPPLIED -> qty_to_buy 0 + pesan "titipan klien" | FAIL | to_buy= msg= |
<!-- END:Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 -->
