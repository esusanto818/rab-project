# Test Report Pengecekan PO Sebelum Release (TC-201 s/d TC-210)

**Tanggal eksekusi:** 2026-11-15 14:05:46  
**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `docs/HOWTO_PO_PRERELEASE_CHECK_2026-11-15.md`  
**Ringkasan sementara:** PASS = 10, FAIL = 0, SKIP = 0

> Fitur: validasi real-time saat release PO (`change_status` ke Diajukan/Disetujui) di dalam
> transaksi ACID: baca ulang `GetProjectStockMap()` & `po_net_need()`, blokir bila stok proyek
> bertambah / kuota RAB habis / periode tutup / supplier non-aktif / item tidak valid.
> Stok milik proyek lain (`warehouses.project_id <> proyek ini`) tidak pernah dihitung.

<!-- BEGIN:Kelompok G — Pengecekan PO Sebelum Release (validasi real-time) -->
## Kelompok G — Pengecekan PO Sebelum Release (validasi real-time)

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-201 | release normal: Draft -> Diajukan lolos | PASS | status='Diajukan' msg=PO PO-2611-00001 sekarang berstatus Diajukan. |
| TC-202 | blokir release: stok proyek bertambah sebelum release (qty PO > kebutuhan bersih) | PASS | status='Draft' msg=PO PO-2611-00001: Baut Hex E2E-POREL qty PO 60 melebihi kebutuhan bersih 40 Buah (sisa kuota RAB 100 - stok tersedia 60) |
| TC-203 | blokir release: kuota RAB habis dipakai PO lain (PO A Diajukan, PO B ditolak) | PASS | A='Diajukan' B='Draft' msgB=PO E2E-POREL-POB: Baut Hex E2E-POREL qty PO 10 melebihi kebutuhan bersih 0 Buah (sisa kuota RAB 40 - |
| TC-204 | blokir double-release: Diajukan -> Diajukan ditolak state machine | PASS | status='Diajukan' msg=Status Diajukan tidak dapat diubah ke Diajukan. |
| TC-205 | blokir release: periode akuntansi tutup | PASS | status='Draft' msg=PO PO-2611-00001: periode akuntansi untuk tanggal 15/11/2026 sudah ditutup; release PO dibatalkan. |
| TC-206 | stok proyek lain (500 di WH-B) tidak dipinjam; P1 lihat 40 & butuh beli 60 | PASS | total_db=540 p1_on_hand=40 to_buy=60 |
| TC-207 | blokir release: supplier non-aktif | PASS | status='Draft' msg=PO PO-2611-00001: supplier tidak aktif; release ditolak. Pilih supplier aktif. |
| TC-208 | blokir release: PO tanpa item | PASS | status='Draft' msg=PO E2E-POREL-NOITEM: tidak memiliki item; release ditolak. |
| TC-209 | blokir release: integritas item (qty = 0) | PASS | status='Draft' msg=PO PO-2611-00001: Mur Hex E2E-POREL qty harus > 0. |
| TC-210 | alur lengkap: Draft -> Diajukan -> Disetujui lolos validasi real-time | PASS | status='Disetujui' msg1=PO PO-2611-00001 sekarang berstatus Diaj msg2=PO PO-2611-00001 sekarang berstatus Dise |
<!-- END:Kelompok G — Pengecekan PO Sebelum Release (validasi real-time) -->
