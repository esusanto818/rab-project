# Master Log & Progress Checkpoint Audit Sistem ERP (`proyekgmn-dev`)

**Terakhir Diperbarui:** 2026-11-16 07:17 WIB  
**Database Uji Terisolasi:** `proyekGMN_test` (`127.0.0.1:5432`) | **Server Uji PHP:** `http://127.0.0.1:8098`

---

## 1. Optimasi Lingkungan & Infrastruktur Agen
- **Manajemen Memori (RAM 6 GB):** Menghentikan layanan lokal `ollama` (`sudo systemctl stop ollama`) saat menjalankan Claude Code untuk mencegah *swap thrashing*.
- **Pemasangan 4 Project Skills (`.claude/skills/`):** `db-schema-map`, `erp-business-invariants`, `e2e-test-template`, dan `fast-scoped-patch`.
- **Konfigurasi Claude Agent Suite (`.claude/agents/`):** 6 sub-agen spesialis (`db-code-auditor`, `feature-architect-builder`, `fullstack-patcher`, `security-auditor-patcher`, `qa-test-engineer`, `tech-writer`).

---

## 2. Riwayat Fitur & Patch yang Sudah Terkomit (`FAIL=0`)
1. **Validasi Pre-Release Purchase Order (`0fd331e`)** — `tests/run_e2e_po_prerelease.php` (`PASS=10 FAIL=0`) & `docs/HOWTO_PO_PRERELEASE_CHECK_2026-11-15.md`.
2. **Pemisahan Layer Monitoring & Release Tagihan Klien (`4101e71`)** — `tests/run_e2e_release.php` (`PASS=55 FAIL=0`) & `docs/HOWTO_TAGIHAN_KLIEN_RELEASE_2026-11-15.md`.
3. **Audit Menyeluruh Hulu-ke-Hilir Proyek** — `docs/AUDIT_HULU_HILIR_PROYEK_2026-11-15.md`.
4. **Patch Gelombang 1: Data Readiness, Leftover Return, & Guard RAB (`a105548`)** — `tests/run_e2e_leftover_and_rab_guard.php` (`PASS=11 FAIL=0`) & `docs/HOWTO_LEFTOVER_AND_RAB_GUARD_2026-11-15.md`.
5. **Patch Gelombang 2: Material Titipan Klien `CLIENT_SUPPLIED` Rp 0 (`4816957`)** — `tests/run_e2e_client_supplied.php` (`PASS=12 FAIL=0`) & `docs/HOWTO_MATERIAL_TITIPAN_KLIEN_2026-11-15.md`.

---

## 3. Tahap Final yang Tersisa: Patch Gelombang 3
1. **Subcon Material Supply & Buy-Back (`F-2.1`):** Pemisahan material pasokan subkon (`SUBCON`) serta fitur pembelian sisa material subkon (`subcon_leftover_buyback`) masuk ke `stock_movements` (`IN`) + jurnal Persediaan vs Hutang Subkon/Kas.
2. **Kontrol Progress Proyek vs Realisasi Biaya (`F-1.1` & `F-1.2`):** Validasi perbandingan HPP aktual terhadap RAB dan persentase progress fisik proyek.
