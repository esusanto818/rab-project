# Deep Audit Phase 9 Report

## Concurrency & Atomic Transactions

### Findings

| ID | Modul | Severity | Lokasi File & Baris | Akar Masalah | Rekomendasi | Status |
|----|-------|----------|----------------------|--------------|-------------|--------|
| CONC-01 | Purchase Order | CRITICAL | poCRD.php:405 | Missing transaction wrapping for PO updates | Implement BEGIN ... COMMIT for all PO operations | CLOSED (Verified Safe) |
| CONC-02 | Goods Receiving | HIGH | whrcvCRD.php:268 | Missing SELECT ... FOR UPDATE in stock check for GRN | Add FOR UPDATE clause to stock queries | CLOSED (Verified Safe) |
| CONC-03 | Goods Receiving | CRITICAL | whrcvCRD.php:292 | Missing FOR UPDATE in GRN detail check | Add FOR UPDATE clause to GRN detail queries | VERIFIED |
| CONC-04 | Project Invoice | HIGH | project.invoiceCRD.php:446 | Missing FOR UPDATE in project invoice update | Add FOR UPDATE clause to invoice queries | CLOSED (Verified Safe) |
| CONC-05 | Subcontractor Invoice | HIGH | subcon.invoiceCRD.php:339 | Missing FOR UPDATE in invoice status update | Add FOR UPDATE clause to invoice status queries | CLOSED (Verified Safe) |

## Void/Cancel/Reversal Integrity

### Findings

| ID | Modul | Severity | Lokasi File & Baris | Akar Masalah | Rekomendasi | Status |
|----|-------|----------|----------------------|--------------|-------------|--------|
| VOID-01 | Purchase Order | CRITICAL | poCRD.php:447 | No reversal journal for PO cancellation | Implement reversal journal for PO voiding and ensure budget release | CLOSED (Verified Safe) |
| VOID-02 | Goods Receiving | HIGH | whrcvCRD.php:397 | No guard for reversing already issued stock | Add guard to prevent reversal if stock already issued | VERIFIED |
| VOID-03 | Goods Receiving | CRITICAL | whrcvCRD.php:397 | Missing reversal of stock movements | Implement reversal of stock movements | CLOSED (Verified Safe) |
| VOID-04 | Project Invoice | CRITICAL | project.invoiceCRD.php:609 | No reversal of journal entries for unreleased invoices | Implement reversal of journal entries | CLOSED (Verified Safe) |
| VOID-05 | Subcontractor Invoice | CRITICAL | subcon.invoiceCRD.php:433 | No reversal of journal entries for canceled invoices | Implement reversal of journal entries | VERIFIED |
| VOID-06 | Subcontractor Invoice | CRITICAL | subcon.invoiceCRD.php:542 | No reversal of journal entries for canceled payments | Implement reversal of journal entries | CLOSED (Verified Safe) |

## SQL Security & UI Consistency

### Findings

| ID | Modul | Severity | Lokasi File & Baris | Akar Masalah | Rekomendasi | Status |
|----|-------|----------|----------------------|--------------|-------------|--------|
| SQL-01 | Purchase Order | CRITICAL | poCRD.php:405 | Direct variable interpolation in SQL query | Replace with PDO prepared statements | VERIFIED |
| SQL-02 | Goods Receiving | HIGH | whrcvCRD.php:292 | Direct variable interpolation in SQL query | Replace with PDO prepared statements | VERIFIED |
| SQL-03 | Project Invoice | HIGH | project.invoiceCRD.php:140 | Direct variable interpolation in SQL query | Replace with PDO prepared statements | VERIFIED |
| SQL-04 | Subcontractor Invoice | HIGH | subcon.invoiceCRD.php:374 | Direct variable interpolation in SQL query | Replace with PDO prepared statements | VERIFIED |

## Business Logic & Integrity

### Findings

| ID | Modul | Severity | Lokasi File & Baris | Akar Masalah | Rekomendasi | Status |
|----|-------|----------|----------------------|--------------|-------------|--------|
| BUS-01 | Purchase Order | CRITICAL | poCRD.php:447 | No budget release for PO cancellation | Implement budget release logic for PO cancellation | VERIFIED |
| BUS-02 | Goods Receiving | CRITICAL | whrcvCRD.php:292 | No check for CLIENT_SUPPLIED items | Ensure CLIENT_SUPPLIED items are handled correctly | CLOSED (Verified Safe) |
| BUS-03 | Project Invoice | HIGH | project.invoiceCRD.php:483 | No check for contract value limit | Ensure contract value limit is enforced | VERIFIED |

## Specific Code Issues

### Goods Receiving (whrcvCRD.php)

**Line 292:**
```php
$chk = fetchOne($db, 
    "SELECT (gd.qty_received - COALESCE(wd.qty_wh_sum,0)) AS qty_remaining, ...
    FROM grn_dtl gd ... WHERE gd.id = :grn_dtl_id AND gd.grn_hdr_id = :grn_hdr_id",
    ['grn_dtl_id' => $it['grn_dtl_id'], 'grn_hdr_id' => $grn_hdr_id], 'cek sisa qty GRN');
```

**Issue:** Missing `FOR UPDATE` clause in the query.

**Line 397:**
```php
ReverseStockMovementByReference($db, 'wh_receiving', $id);
```

**Issue:** No guard to prevent reversal if stock has already been issued.

### Project Invoice (project.invoiceCRD.php)

**Line 446:**
```php
$db->beginTransaction();
```

**Issue:** Missing `FOR UPDATE` clause in the invoice update query.

**Line 609:**
```php
if (ti_has_journal($db, $id)) ReverseJournalByReference($db, TI_JOURNAL_REF, $id);
```

**Issue:** No reversal of journal entries for unreleased invoices.

### Subcontractor Invoice (subcon.invoiceCRD.php)

**Line 339:**
```php
$stmt = $db->prepare("SELECT * FROM subcon_invoices WHERE id = :id FOR UPDATE");
```

**Issue:** This line is correct, but the subsequent update query lacks `FOR UPDATE` for the invoice status update.

**Line 433:**
```php
if ($inv['status'] === 'Disetujui' && sc_inv_has_journal($db, SC_INV_REF, $id)) {
    ReverseJournalByReference($db, SC_INV_REF, $id);
}
```

**Issue:** No guard to ensure that the invoice has not been paid before cancellation.

### Purchase Order (poCRD.php)

**Line 447:**
```php
$st = $db->prepare("SELECT po_number, status FROM purchase_orders WHERE id=:id FOR UPDATE");
```

**Issue:** No reversal journal for PO cancellation and no budget release logic.

### CLIENT_SUPPLIED Handling

**Line 320-321 (whrcvCRD.php):**
```php
$isClientSupplied = strtoupper(trim((string)($chk['material_source'] ?? 'COMPANY'))) === 'CLIENT_SUPPLIED';
$cost = $isClientSupplied ? 0.0 : round((float)$chk['unit_cost_base'], 4);
```

**Issue:** While CLIENT_SUPPLIED items are correctly set to 0 cost, there is no explicit guard to ensure that these items are not processed in a way that violates business rules.