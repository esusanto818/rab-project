---
description: Audit menyeluruh pada satu modul spesifik (UI ExtJS, kontrak API, RBAC user.valid.php, transaksi PHP, dan integrasi DB) tanpa mengubah kode.
---

Lakukan audit mendalam pada modul: **$ARGUMENTS**

Gunakan agent `db-code-auditor`, `security-auditor-patcher`, dan `contract-debugger` dengan berpedoman pada `CLAUDE.md` v3.0 (§10 Master Audit Protocol — AUDIT FIRST, NO PATCH) serta skill `extjs-341-patterns` dan `rbac-endpoint-guard`.

Periksa secara spesifik:
1. **Pintu Masuk UI & ExtJS 3.4.1:** Apakah tombol aksi terlihat jelas, apakah memakai `EditorGridPanel` (bukan `EditorGridModel`), apakah checkbox grid berfungsi real-time, dan apakah `editorWdw`/`popWdw` tertutup saat awal load.
2. **RBAC & `user.valid.php`:** Apakah file CRD terdaftar di `$endpointAliases` dan menu terdaftar di `m_form_name`.
3. **Kontrak API & PHP 7.4:** Kecocokan parameter & JSON key (`totalCount`/`totalRecord`/`data`), kompatibilitas PHP 7.4, dan transaksi atomik `BEGIN`/`COMMIT`/`ROLLBACK`.
4. **Integrasi Lintas Modul:** Dampak ke stok, budget RAB/RAP, jurnal, dan saldo piutang/hutang.

Simpan hasil temuan berstruktur ke `docs/` dan **BERHENTI tanpa mengubah source code** sampai developer menyetujui rencana patch.
