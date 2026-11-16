# Test Report Progress Cost Control (F-1.1 & F-1.2) (TC-PC-01 s/d TC-PC-13)

**Tanggal eksekusi:** 2026-11-17 01:12:03  
**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `tests/run_e2e_progress_cost_control.php`  
**Ringkasan sementara:** PASS = 13, FAIL = 0, SKIP = 0

> Fitur: kontrol progress fisik vs realisasi biaya proyek.
> F-1.1: hard-block klaim 100% tanpa issue material; verify memaksa ulang; manual progress non-admin ditolak.
> F-1.2: satu rumus realisasi `v_project_actual_cost` (material + expense + subkon tersertifikasi)
> dipakai konsisten di prog_metrics, dash_project_context, wr_calc, dan gate issuing.

<!-- BEGIN:Kelompok PC — Kontrol Progress Proyek vs Realisasi Biaya (F-1.1 & F-1.2) -->
## Kelompok PC — Kontrol Progress Proyek vs Realisasi Biaya (F-1.1 & F-1.2)

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-PC-01 | view v_project_actual_cost ada + berisi proyek uji | PASS | cost_total=0.00 rab_budget=500000000.000000 progress=20 |
| TC-PC-02 | cost_material (view) == SUM manual material posted | PASS | manual=0 view=0 |
| TC-PC-03 | list_projects mengembalikan metrik realisasi terpadu (material+expense+subkon) | PASS | cost_actual=0 cost_material=0 cost_other=0 cost_subcon=0 cpi_status=- |
| TC-PC-04 | submit_claim 100% tanpa issue material DITOLAK (hard-block) | PASS | Klaim 100% DITOLAK: belum ada realisasi pemakaian material (issue material Rp 0) untuk proyek ini. Catat issue material di modul Warehouse Issuing terlebih dahu |
| TC-PC-05 | issue PROJECT 10 Buah terindeks realisasi (OUT 10) | PASS | mv qty=10.0000 cost=100000.0000 |
| TC-PC-06 | submit_claim 30% setelah ada issue material diterima | PASS | Klaim progress 30% untuk Proyek P1 E2E-PC diajukan, menunggu verifikasi. PERHATIAN: klaim 30% melampaui realisasi biaya 0.2% (gap +30). Pastikan bukti lapangan  |
| TC-PC-07 | verifikator tidak boleh = pelapor sendiri | PASS | Klaim tidak boleh diverifikasi oleh pelapornya sendiri. Minta PM / QS lain memverifikasi. |
| TC-PC-08 | verifikasi [PASS] snap_cost_base > 0 (realisasi tercatat) | PASS | snap_cost_base=1000000.00 snap_cpi=150.0000 status=Diverifikasi |
| TC-PC-09 | cost_expense (view) = 2.500.000 setelah expense PAID | PASS | view=2500000 |
| TC-PC-10 | list_projects cost_other = 2.500.000 (expense masuk realisasi) | PASS | cost_other=2500000 cost_actual=3500000 |
| TC-PC-11 | preview WIP memuat field realisasi terpadu (actual_cost/expense/remaining) | PASS | wip_cumulative=1000000 actual_cost=3500000 actual_cost_expense=2500000 cost_remaining_wip=1000000 |
| TC-PC-12 | dashboard profitability memuat cost_total terpadu | PASS | cost_total=3500000 cost_material=1000000 cost_expense=2500000 cost_subcon=0 |
| TC-PC-13 | issue di atas plafon progress-gated DITOLAK | PASS | TRANSAKSI DITOLAK: Pemakaian anggaran melampaui batas progress fisik saat ini! Progress fisik: 30%. Plafon diizinkan: Rp 157.500.000,00. Total pengajuan: Rp 203 |
<!-- END:Kelompok PC — Kontrol Progress Proyek vs Realisasi Biaya (F-1.1 & F-1.2) -->
