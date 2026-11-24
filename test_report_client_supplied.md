# Test Report Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 (TC-CS-01 s/d TC-CS-12)

**Tanggal eksekusi:** 2026-11-24 10:49:42  
**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `tests/run_e2e_client_supplied.php`  
**Ringkasan sementara:** PASS = 12, FAIL = 0, SKIP = 0

> Fitur: material titipan klien (budget_items.material_source = 'CLIENT_SUPPLIED') Rp 0.
> qty_to_buy wajib 0 di Planning/PO, save_po menolak, GRN/WH Rcv memaksa biaya 0,
> fisik masuk/keluar stok tercatat di stock_movements tanpa jurnal rupiah.

<!-- BEGIN:Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 -->
## Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-CS-01 | RAB tersimpan: budget_items.material_source = CLIENT_SUPPLIED | PASS | material_source='CLIENT_SUPPLIED' |
| TC-CS-02 | RAB kontrol: material_source = COMPANY (default) | PASS | material_source='COMPANY' |
| TC-CS-03 | load_planning: CLIENT_SUPPLIED qty_to_buy = 0 & flag material_source tampil | PASS | to_buy=0 src=CLIENT_SUPPLIED |
| TC-CS-04 | load_planning: COMPANY (M2) qty_to_buy = 50 normal (bukan 0) | PASS | to_buy=50 src=COMPANY |
| TC-CS-05 | poCRD list_rab_items: M1 qty_to_buy = 0 + material_source = CLIENT_SUPPLIED | PASS | to_buy=0 src=CLIENT_SUPPLIED |
| TC-CS-06 | save_po: budget_item CLIENT_SUPPLIED DITOLAK (tidak boleh masuk PO pembelian) | PASS | Baut Titipan E2E-CS: qty PO 10 melebihi sisa kuota RAB (0 Buah). |
| TC-CS-07 | save_po: COMPANY (M2) qty 10 diterima (alur normal tidak terganggu) | PASS | po_number=PO-2611-00001 |
| TC-CS-08 | grnCRD create: unit_cost DIPAKSA 0 untuk CLIENT_SUPPLIED (kiriman 12345 diabaikan) | PASS | unit_cost_tersimpan='0.0000' \| GRN GRN-2611-00001 berhasil disimpan (status approval: PENDING). |
| TC-CS-09 | whrcvCRD: fisik IN 100 @ Rp 0 di stock_movements + TANPA jurnal GRN_RCV | PASS | mv=IN qty=100.0000 cost=0.0000 jurnal_rows=0 msg=Penerimaan gudang WHR-2611-00001 berhasil disimpan. |
| TC-CS-10 | issuing: OUT 30 @ Rp 0 (saldo 70) + material_out_items Rp 0 + TANPA jurnal ISSUE_PROJECT | PASS | mv=OUT qty=30.0000 cost=0.0000 item_cost=0.00 jurnal_rows=0 saldo=70 |
| TC-CS-11 | load_planning pasca-issue: CLIENT_SUPPLIED to_buy tetap 0 (stok fisik 70 tidak mengubah kebutuhan beli) | PASS | to_buy=0 on_hand=70 |
| TC-CS-12 | check_inventory: baris CLIENT_SUPPLIED -> qty_to_buy 0 + pesan "titipan klien" | PASS | to_buy=0 msg=Baris 1: qty rencana 10 Buah melebihi sisa kuota RAB 0 Buah; material titipan klien (CLIEN |
<!-- END:Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 -->
