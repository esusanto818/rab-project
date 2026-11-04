# SPK Implementation Audit — RAB-Project

**Repository:** esusanto818/rab-project  
**Audit basis:** `main` and PR #4 branch `fix/spk-inventory-task-and-rab-expenses-2026-11`  
**Status:** Code/schema review; not a runtime test  
**Related design:** `docs/SUBCONTRACT_MANAGEMENT_DESIGN.md` and `docs/PROJECT_MODULE_GUIDELINES.md`

## 1. Executive finding

The current SPK is still primarily a warehouse-material authorization document. It can link project tasks, but it is not yet a unified work-order system for internal and subcontract execution.

The implementation must be additive. Keep the existing SPK identity, material issue authorization, quota calculation, and Warehouse Issuing contract intact while adding a distinct work-scope and execution-party layer.

## 2. Verified current behavior in main

### SPK entry and save
- `php-crud/spkCRD.php` supports project/task/material lookup, save, issue, close, cancel and delete.
- Save requires at least one material detail and at least one project task.
- Material details must exist in `budget_items` for the selected project.
- Material quantities are checked against payment-based RAB quota and physical stock.
- SPK status is Draft, Diterbitkan, Selesai, or Dibatalkan.
- The SPK header stores legacy `foreman`, `description`, and `remark`; there is no explicit INTERNAL/SUBCON type or subcontractor party field in the inspected save path.
- Issuing an SPK requires project payment and validates material quota. This rule is appropriate for warehouse issue authorization, but must not be applied blindly to a work-only internal SPK or a subcontractor-supplied material scope.

### Existing task relationship
- The SPK detail and task lookup read `spk_tasks` joined to `project_tasks`.
- The save path stores selected task IDs in `spk_tasks`.
- The current SPK detail view lists tasks separately from the material list.
- The inspected main save path does not persist an explicit mapping of each SPK task to its material allocation.

## 3. PR #4 branch findings

PR #4 adds:
- `spk_tasks`: links SPK to one or more project tasks.
- `task_materials`: links RAB material items to project tasks.
- `spk_task_materials`: allocates task material quantities to an SPK task.
- `task_expenses`, `spk_task_expenses`, and financial expense allocation structures.
- Database triggers to check project consistency and quantity/value caps.

Important limitations in the inspected code:
1. The SPK editor shows a task selector and a separate SPK-wide material grid. It does not yet provide a per-task material allocation editor.
2. The inspected SPK save path requires a non-empty material detail list, so a work-only SPK is still impossible.
3. The inspected `fn_validate_spk_task_material()` requires the material to exist in `spk_items` at SPK header level. This creates a two-level allocation rule, but the UI/save flow must actually write both levels consistently.
4. Legacy SPK material lines are deliberately not auto-assigned to tasks. Existing records need explicit allocation by a user; the system must not guess the task split.
5. The PR #4 SQL is not part of main unless and until the PR is merged. Do not deploy dependent PHP/JS code against main before the migration is reconciled and installed.

## 4. Gaps against the approved design

| Requirement | Current state | Required work |
|---|---|---|
| SPK Internal and SPK Subcon | No explicit type in inspected SPK save path | Add explicit type and execution party |
| Work-only SPK | Blocked by mandatory material detail | Permit zero warehouse material lines when valid work scope exists |
| Task-based work scope | Task links exist | Add measurable SPK work-item quantities/rates |
| Material per Task | Task allocation tables proposed in PR #4 | Add editor and save/detail/print support |
| Subcon commercial terms | Separate Subcon module exists | Link one Subcon contract to the SPK |
| Payment based on inspected work | Not enforced in existing SPK path | Add quantity claim, inspection and certificate workflow |
| Material ownership | Not represented in existing SPK editor | Track COMPANY/SUBCON/MIXED source |
| Warehouse issue protection | Existing quota/status behavior exists | Preserve and regression-test unchanged |

## 5. Target entry experience

### Common SPK header
1. Select project.
2. Select SPK type: INTERNAL or SUBCON.
3. Enter SPK date, work period, description and notes.
4. Select one or more project tasks.
5. For each task, define measurable work scope, unit, quantity and unit rate where applicable.
6. Add task materials from the project's RAB allocation.
7. Choose material source per relevant work/material scope: COMPANY, SUBCON, or MIXED.
8. Review validation and save as Draft.

### INTERNAL
- Execution party is the internal team/mandor/employee according to the application's party model.
- Company-supplied material may use the existing warehouse issue flow.
- A work-only internal SPK is valid when it has a work scope, even if it has no warehouse material issue lines.
- Existing material quota/payment checks apply only to company warehouse material entitlement, not to the mere existence of a work order.

### SUBCON
- Select a registered subcontractor.
- Create/link commercial subcontract contract terms to the SPK.
- Specify task scope, contract quantity and rates.
- Declare which materials are supplied by the company, subcontractor, or both.
- Subcontractor progress claims are measured by quantity and evidence.
- Only quantities passed by inspection may be certified and become payable.

## 6. Required data invariants

- Every selected task belongs to the SPK project.
- A task can be split across multiple SPKs only within the remaining approved work quantity.
- Every task material allocation references an existing RAB material allocation for that same task.
- The sum of SPK task-material allocations cannot exceed task material allocation.
- SPK warehouse material entitlement remains separate from work quantity.
- Company stock changes only through the existing posted inventory movement workflow.
- Subcontractor-supplied materials do not change company stock unless formally purchased/received.
- Work accepted for payment cannot exceed inspected quantity or approved SPK work quantity plus approved variations.
- Existing SPK issue history and posted stock transactions remain immutable.

## 7. Implementation sequence

### Phase A — reconcile PR #4 foundation
- Confirm PR #4 status and branch head.
- Confirm all required tables, indexes and triggers exist in the target database.
- Resolve any duplicate/overlapping DDL and verify trigger function definitions.
- Add explicit schema/version checks to the deployment checklist.
- Do not merge or deploy blindly.

### Phase B — SPK work-scope foundation
- Add additive SPK type and execution-party fields.
- Add `spk_work_items` for work quantity, unit, unit price and task/RAB references.
- Update SPK save/detail/list/print.
- Allow work-only SPK while preserving legacy material-only SPK behavior.
- Add server-side project/task and cumulative quantity validation.

### Phase C — task material entry
- Add per-task material editor.
- Display RAB allocation, already allocated to other SPKs, available quantity, and current SPK allocation.
- Persist `spk_task_materials` and synchronize SPK-level warehouse entitlement only for COMPANY-supplied material.
- Require explicit allocation of legacy SPK material lines; never auto-infer task splits.

### Phase D — subcontract workflow
- Link `subcon_contracts` to one SPK.
- Add quantity-based progress claims and inspection.
- Create payment certificates from approved inspection quantities only.
- Reconcile existing invoice/payment posting paths before enabling financial posting.

### Phase E — material ownership and leftovers
- Track company/subcontractor/mixed supply.
- Add leftover registration and sale/purchase/return transactions with approvals.
- Connect company inventory only through official stock movement posting.

## 8. Staging test checklist

1. Existing Draft SPK with material lines can still be edited.
2. Existing issued SPK can still be selected by Warehouse Issuing.
3. Material quota and physical stock caps remain unchanged.
4. Internal work-only SPK can be saved without material issue lines.
5. Internal SPK with company material enforces existing material quota.
6. Subcon SPK with subcontractor-supplied materials does not reserve company stock.
7. One SPK with two tasks can allocate different materials to each task.
8. The same material can be allocated to two tasks without duplicate-material false errors, within each task/RAB cap.
9. SPK task-material allocations cannot exceed task allocation or SPK entitlement.
10. Legacy SPKs remain readable and their historical material lines remain unchanged.
11. Only inspection-passed quantities can enter payment certificates.
12. Existing project, payment, inventory and accounting regressions pass.

## 9. Verification limits

This audit is based on repository source review only. No PHP runtime, PostgreSQL migration, browser, inventory posting, or accounting integration test was executed as part of this audit. Database screenshots showing successful DDL execution are useful evidence that commands completed, but do not by themselves prove every table constraint, trigger body, application query, or workflow is correct.
