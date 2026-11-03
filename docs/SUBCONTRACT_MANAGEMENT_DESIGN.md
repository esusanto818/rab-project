# Subcontract Management — Design Baseline
**Project:** RAB-Project  
**Status:** Approved design baseline; Step 1 audit in progress  
**Date:** 3 November 2026

## 1. Decisions
1. SPK supports both internal work and subcontracted work.
2. Subcontractor payment is based only on completed quantities that have passed inspection.
3. Leftover material ownership follows its source and can transfer only through a documented sale/purchase transaction.

## 2. Architecture principles
- Keep one SPK identity and workflow foundation for internal and subcontract work.
- Preserve the current Warehouse Issuing behavior. The existing `spk` module currently acts as a material-issue authorization; do not repurpose or break this contract.
- Separate work scope/measurement lines from warehouse material issue lines.
- Treat `subcon_contracts` as commercial terms for the subcontract branch of an SPK, not as a second unrelated SPK master.
- An approved inspection is the sole source for accepted work quantity. A progress claim alone never creates payable value.
- Separate progress certificate, supplier invoice, and cash/bank payment events to avoid double posting.
- Inventory ownership changes only through approved and posted inventory/purchase/sales transactions.
- All amendments, approvals, cancellations, and postings must be auditable. Never delete posted financial or stock transactions.

## 3. Existing repository findings (main)
- `spk` and `spk_items` are created in `spk_usage_ppn.sql`. The SPK backend `php-crud/spkCRD.php` currently requires at least one material line, validates against project RAB materials, checks project payment-based quota and physical stock, and uses statuses Draft / Diterbitkan / Selesai / Dibatalkan.
- `material_out_header.spk_id` ties warehouse issues to SPK. `php-func/function.spk.php` computes material quotas from paid project invoices, RAB quantities, active SPK allocations, posted issues, and on-hand inventory.
- `subcontractors`, `subcon_contracts`, `subcon_contract_tasks`, `subcon_progress`, `subcon_progress_dtl`, and `subcon_payments` are defined in `subcon.sql`.
- `subcon_invoices` and `subcon_invoice_payments` are defined in `subcon_invoice.sql`.
- `php-crud/subconCRD.php` implements a separate subcontract contract workflow. Its progress logic is cumulative percentage per task; it does not yet enforce quantity-level inspection acceptance as the payment basis.
- `js/spk.js` and `js/subcon.js` are separate front ends.
- Open PR #4 contains task/RAB allocation foundations, but is not part of main yet. Any dependency on its migrations must be reviewed and reconciled before implementation.

## 4. Target model
### 4.1 SPK header
Extend the existing SPK master additively with:
- `spk_type`: INTERNAL or SUBCON.
- `execution_party_id`: optional reference to the responsible internal party or subcontractor, with explicit party-type semantics.
- `work_start_date`, `work_end_date`.
- `parent_spk_id` for controlled package splits where needed.
- revision/addendum reference and approval audit fields.

Do not overload `foreman` as a subcontractor identity. Keep legacy fields during transition.

### 4.2 Work scope
Create `spk_work_items` for measurable scope:
- SPK, project task, optional RAB item references.
- description, unit, contract quantity, unit price, line amount.
- material supply mode COMPANY / SUBCON / MIXED.
- approved variation/addendum references.

Keep `spk_items` as warehouse material issue entitlement until a tested migration proves it can safely be consolidated.

### 4.3 Subcontract commercial terms
Retain `subcon_contracts` for subcontract-only commercial terms and link each record to exactly one SPK. Keep existing contract number/value, tax, retention, advance, schedule and status fields where possible. A migration must reconcile existing records before making the SPK link mandatory.

### 4.4 Progress and inspection
Retain existing progress header/detail as claim records, but add quantity-based claim lines where required. Add:
- `subcon_inspections`: SPK, claim, inspection date, inspector, status, notes, approval metadata.
- `subcon_inspection_items`: claim line, qty claimed, qty inspected, qty passed, qty rejected, rework flag, inspection evidence and notes.

Only approved `qty_passed` may flow to certified progress. Partial acceptance is supported. Rework requires a new inspection event and immutable history.

### 4.5 Payment certificate and invoice
Add:
- `subcon_payment_certificates`: contract, source claim/inspection, certificate number, gross accepted value, retention, advance recovery, deductions, tax, net payable, status and approval audit.
- `subcon_payment_certificate_items`: accepted quantities, unit prices and source inspection line snapshots.

Link `subcon_invoices` to a certificate. Invoice amount cannot exceed the approved certificate balance. Keep `subcon_invoice_payments` as payment execution records. Reconcile existing `subcon_payments` with invoice payments before deciding which legacy payment path to retire; do not run both as parallel posting paths.

### 4.6 Material supply and ownership
For each work/material line, record supply source:
- COMPANY: company stock issue/return through existing inventory workflow.
- SUBCON: no company stock increase or decrease merely because the subcontractor brings material.
- MIXED: split quantities into source-specific supply records.

Create source-level material supply records with material, work item, supplier/owner, planned/received/accepted quantities and ownership-transfer rule. If the company purchases subcontractor material, use a separate purchase/receipt transaction before adding it to company inventory.

### 4.7 Leftovers
Create `material_leftovers` as the traceable leftover lot record: project, originating SPK/work item, material, source owner, current owner, detected/available quantity, condition, location and status.

Use separate documented transactions for:
1. Return of company-owned material to company inventory.
2. Purchase of subcontractor-owned leftovers by the company.
3. Sale of company-owned leftovers to the client.

Each transaction must record seller, buyer, quantity, unit price, market reference price/source/date, discount or premium, reason, approval and linked inventory movement. Ownership must not change by editing a field alone.

## 5. Workflow
1. Create project SPK and select INTERNAL or SUBCON.
2. Define task/work scope, quantities, rates, material source and commercial terms.
3. Validate project/task/RAB scope, committed quantities, contract limits and stock for COMPANY-supplied material.
4. Approve and issue SPK.
5. Submit progress claim with measured quantities and evidence.
6. Inspect each line; pass, partially pass, reject or require rework.
7. Update official project progress only from approved accepted quantities.
8. Generate payment certificate only from passed quantities; apply contract-specific tax, retention, advance recovery and approved deductions.
9. Match subcontractor invoice to certificate.
10. Finance pays approved invoice and posts accounting once.
11. Close SPK after final inspection, outstanding claims, material reconciliation and retention conditions are satisfied.
12. Register leftovers and process return/purchase/sale according to ownership.

## 6. Core invariants
- Accepted quantity <= inspected quantity <= claimed quantity.
- Cumulative accepted quantity <= approved SPK work quantity plus approved variations.
- Certificate value <= value of passed, not-yet-certified work.
- Invoice value <= approved certificate balance.
- Payments <= approved invoice balance.
- Retention release only after contractual release conditions.
- Company stock changes only through posted warehouse/purchase/sales movement.
- Subcontractor-owned material cannot be sold by the company until ownership transfers.
- SPK and task/project references must belong to the same project.
- Concurrent claims/certificates must lock the relevant contract/work item to prevent over-certification.

## 7. Step plan
- Step 1: Consolidate SPK Internal and SPK Subcon architecture; audit current schema and preserve Warehouse Issuing.
- Step 2: Implement quantity-based progress claims and inspections.
- Step 3: Implement payment certificates and reconcile invoice/payment posting.
- Step 4: Implement material supply source, leftover ownership and transactions.
- Step 5: Reports, audit trail, role permissions and staging regression.

## 8. Step 1 audit observations and blockers
- Current SPK is material-issue-centric and cannot currently save a work-only SPK because backend requires material lines.
- Current Subcon workflow is a separate contract module and uses percentage-based task progress, not quantity-based inspection acceptance.
- Do not simply merge the two screens or replace the SPK status values: Warehouse Issuing relies on the current status and quota semantics.
- The exact production schema, foreign-key types, journal posting rules and inventory movement APIs still require validation against a current schema export and staging database.
- PR #4 is open and not merged. Its task-allocation migrations are a dependency candidate, not an assumed deployed schema.
- No SQL migration or application code change has been applied by this design document commit.

## 9. Acceptance criteria for Step 1
- Both INTERNAL and SUBCON work packages can be represented without requiring a material issue line.
- Existing material-only SPKs retain their current behavior.
- Warehouse Issuing still requires an eligible issued SPK and enforces the existing material quota and remaining quantity.
- Subcon commercial details are linked to the SPK without duplicating SPK identity.
- Work quantity and material issue quantity remain distinct.
- Existing SPK and Subcon records remain readable and traceable.
- Migration is additive, reversible where practical, and verified on a staging copy before production.
