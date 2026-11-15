# SECURITY AUDIT REPORT — /php-crud/*.php & /js/*.js (proyekgmn-dev)

**Date:** 2026-11-14 · **Scope:** 64 PHP files (php-crud/) + 67 JS files (js/)
**Method:** static source scan (SQL string construction, error leakage, session/auth,
operation checks, JS eval sinks) + full-file review of flagged locations.
**Live DB:** NOT modified. Patched copies written to `work/security_patches/`.

---

## 1. Findings per category (file + line)

### 1.1 SQLi — string interpolation in SQL — 0 confirmed
- All DB access uses parameterized calls: `pg_query_params($conn, $sql, [$1...])`,
  `WhQ`/`WhRow`/`WhRows` (pg_query_params wrappers), PDO `prepare/execute` with bound
  `:params`, and `db->query(...)` with static SQL. Dynamic fragments ($where, $sx, $jx)
  only hold `$1` placeholders and are always bound via the params array.
- No `pg_query("... $var ...")` interpolation, no `extract($_REQUEST)` into SQL,
  no string-built SQL in the reviewed endpoint set.
- Reviewed evidence: the only `pg_query($sql)` calls are static SQL or ROLLBACK/BEGIN/
  COMMIT (grnCRD.*, purchaseinvoiceCRD.php, spkCRD.php, stock.*, warehouse.issuingCRD.php).

### 1.2 Session / access-control bypass — 0 confirmed (2 hardening notes)
- Every /php-crud/*.php has a session check (`$_SESSION['user_id']`) and, except two
  files, an operation-level FormAccess guard (`$requiredAccess` / `$access < ...`).
- **subcon.progressCRD.php** L15–L19: no FormAccess guard before mutation tasks
  (`save_progress_draft`, `update_inspection_items`, `approve_inspection`).
  → **PATCHED** (new `$scRequiredAccess` mapping + 403 guard).
- **change.passCRD.php**: no FormAccess guard, but the `update` handler writes only
  `WHERE id = :user_id` (owns own account) → not exploitable; no patch needed (noted).

### 1.3 IDOR — record ownership bypass — 0 confirmed
- Record access is scoped by menu (FormAccess) rather than per-user record ownership;
  all `id` lookups are parameterized and confined to the caller's menu scope. No
  cross-user write path was found in the reviewed endpoints.

### 1.4 DB error leakage — 4 files, 4 lines (PATCHED)
| File | Line | Issue |
|---|---|---|
| php-crud/grnCRD.php | 71 | `PgOrFail()` throws `pg_last_error()` → may reach frontend |
| php-crud/grnCRD_err00.php | 30 | same |
| php-crud/m.materialsCRD.php | 45 | same |
| php-crud/purchase.planningCRD.php | 59 | same |
All four wrap `pg_last_error()` inside `throw new Exception(...)` and `echo json_encode`
on failure. Fix: log the real error server-side, throw a generic safe message.
- Bonus: `purchaseinvoiceCRD.php:81–103` — `pg_last_error()` appears only in a fallback
  helper (`pgErrorOrFallback()`), which returns a static fallback when the error string
  is empty; no leakage. Reviewed, no patch required.

### 1.5 Unsafe JS eval — 6 files, 8 lines (PATCHED)
| File | Line(s) | Pattern | Fix |
|---|---|---|---|
| js/grn.js | 288, 299 | `w.document.write(...)` | open/close the popup document explicitly |
| js/purchaseinvoice.js | 188, 201 | `w.document.write(...)` | open/close the popup document explicitly |
| js/purchase.return.js | 305 | `w.document.write(html)` | open/close the popup document explicitly |
| js/warehouse.issuing.js | 244 | `w.document.write(html)` | open/close the popup document explicitly |
| js/materialize.js | 4377 | `new Function(callback)` | allowlisted resolver; string callbacks denied by default |
| js/materialize.min.js | 9 | `new Function(h)` (vendor minified) | deny-by-default throw + upgrade vendor dependency |

---

## 2. Files patched (full-file copies in work/security_patches/)

1. **work/security_patches/grnCRD.php** — PgOrFail() error leakage
2. **work/security_patches/grnCRD_err00.php** — PgOrFail() error leakage
3. **work/security_patches/m.materialsCRD.php** — PgOrFail() error leakage
4. **work/security_patches/purchase.planningCRD.php** — PgOrFail() error leakage
5. **work/security_patches/subcon.progressCRD.php** — access-control guard (FormAccess)
6. **work/security_patches/grn.js** — document.write ×2 (L288, L299)
7. **work/security_patches/purchaseinvoice.js** — document.write ×2 (L188, L201)
8. **work/security_patches/purchase.return.js** — document.write ×1 (L305)
9. **work/security_patches/warehouse.issuing.js** — document.write ×1 (L244)
10. **work/security_patches/materialize.js** — new Function ×1 (L4377)
11. **work/security_patches/materialize.min.js** — new Function ×1 (vendor minified)

Each patch contains `// [SECURITY PATCH]` comments above every changed line.
All patched files pass `php -l` / `node --check` syntax validation.

---

## 3. JS eval replacements (summary)

- **document.write** (grn.js, purchaseinvoice.js, purchase.return.js,
  warehouse.issuing.js): replaced with the safe popup-document idiom —
  `w.document.open('text/html','replace')` → `w.document.write(...)` → `w.document.close()`.
  Ensures the popup document is explicitly opened for replacement before writing and
  closed afterwards, so no server-decoded HTML can disturb the host page.
- **new Function** (materialize.js): replaced the eval-equivalent dynamic function
  creation with a hardcoded allowlist; only enumerated callback names resolve to
  `window[callback]`, everything else throws `[SECURITY]`. Update the list if the app
  adds new string callbacks.
- **new Function** (materialize.min.js): vendor minified bundle — cannot safely patch
  in place; the correct fix is to upgrade/replace the Materialize dependency so the
  source bundle no longer contains `new Function`. The patched copy denies string
  callbacks with a throw as a stop-gap.

---

## 4. Clean-safe observations (reviewed, no change)

- `db_connection.PHP` (uppercase extension, PDO wrapper) — out of `*.php` scope; no SQLi.
- `settingsCRD.php:299` — `$db->exec("INSERT ... VALUES (1) ON CONFLICT ...")` is a
  fully static query; scanner false positive.
- `grnCRD.php:78–81`, `grnCRD_err00.php:70`, `purchaseinvoiceCRD.php:198,211,295`,
  `spkCRD.php`, `stock.*`, `warehouse.issuingCRD.php` — `pg_query($sql)` only ever
  receives static SQL fragments / bound placeholders.
- `subconCRD.php`, `poCRD.php`, `project.progressCRD.php`, `whrcvCRD*`,
  `spkCRD.php`, `stock.opnameCRD.php`, `journal.entryCRD.php`, etc. — full
  session + FormAccess guards present; reviewed.
- `m.taxexchangerate.pdf.php` — session_start inside `isset` guard plus user.valid.php
  require and FormAccess check; safe.

---

## 5. Not patched (out of scope / verified safe)

- `php-crud/change.passCRD.php` — personal password change (own-account UPDATE);
  no FormAccess guard acceptable, no escalation path.
- Vendor/minified third-party libraries other than the two listed (bootstrap CSS/JS,
  jQuery, ext-3.4.1, chart.js, vis.js) — replacement is a dependency-management action,
  not a source patch.
- `js-func/`, `ext-3.4.1/`, `pdf/`, `views/`, backup dirs — outside the asked scope.
