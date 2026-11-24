---
description: Audit logika bisnis lintas modul RAB-Project secara bertahap dan hemat token tanpa mengubah kode.
---

Jalankan audit logika bisnis untuk cakupan: **$ARGUMENTS** (jika kosong, audit seluruh alur inti ERP secara bertahap per modul).

Gunakan agent `business-gap-analyst` dengan skill `token-budget-guard` dan `erp-business-invariants`.
Patuhi **AUDIT FIRST — NO PATCH** (`CLAUDE.md` §10):
1. Periksa satu sub-alur bisnis per siklus (RAB -> PO/GRN -> SPK/Subkon -> Progress/Retensi -> Invoice/AR/AP/Jurnal).
2. Gunakan `rg` terarah; hindari membaca seluruh file besar sekaligus.
3. Segera tulis temuan (Finding ID, Severity, File:Baris, Bukti, Dampak, Rekomendasi) ke file laporan di `docs/` setelah tiap sub-alur selesai.
4. Setelah seluruh sub-alur selesai, tampilkan ringkasan prioritas dan berhenti menunggu persetujuan developer.
