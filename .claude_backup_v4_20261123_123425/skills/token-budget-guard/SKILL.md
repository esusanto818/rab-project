---
name: token-budget-guard
description: Protokol efisiensi token wajib untuk mencegah HTTP 400 (context overflow) dan HTTP 429 (rate limit) pada provider Groq / OpenRouter.
---

# Token Budget & Incremental Checkpoint Guard

Model backend memiliki batas konteks dan kuota token per menit (TPM). Terapkan aturan berikut pada SETIAP tugas audit atau patching:

1. **Pencarian Terarah (Dilarang Baca File Utuh > 300 Baris Tanpa Keperluan):**
   - Gunakan `rg` / `Grep` dengan pola spesifik terlebih dahulu sebelum membuka file.
   - Baca hanya rentang baris (`offset` & `limit`) yang relevan di sekitar hasil `rg`.
   - Jangan pernah membaca folder `ext-3.4.1/`, `assets/`, `*.bak*`, atau file log besar.

2. **Aturan Batch Maksimal 3–5 File per Siklus:**
   - Proses maksimal **3 hingga 5 file** atau **1 sub-modul** dalam satu siklus baca-analisa.
   - Jangan mengumpulkan semua temuan di memori percakapan sampai akhir sesi.

3. **Wajib Tulis Checkpoint ke `docs/` Secara Bertahap:**
   - Setiap selesai memeriksa 1 batch (3–5 file), **LANGSUNG append/update** temuan ke file laporan di `docs/` (misal `docs/AUDIT_FINDINGS_WIP.md`) dan catat daftar file yang sudah diperiksa di bagian atas file tersebut.
   - Dengan cara ini, jika terjadi `HTTP 429` atau user menjalankan `/compact`, progres tidak akan pernah hilang.

4. **Output Ringkas & Padat:**
   - Jangan mengulang isi file kode panjang di dalam respons chat. Cukup tulis lokasi `file:baris`, bukti singkat (1–3 baris), dampak, dan rekomendasi.
