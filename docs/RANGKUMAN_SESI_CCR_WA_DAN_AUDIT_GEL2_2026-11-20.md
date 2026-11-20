# Rangkuman Sesi: Konfigurasi Multi-Model CCR, Notifikasi WA & Progres Audit Gelombang 2 (`proyekgmn-dev`)

**Tanggal:** Jumat, 20 November 2026 (13:42 WIB)  
**Proyek:** `proyekgmn-dev` / `rab-project` (`proyekGMN_test` — PHP Native, ExtJS 3.4.1, PostgreSQL)  
**Lingkungan:** Ubuntu Linux (RAM 6 GB) + Claude Code CLI (`v2.1.325`) + Claude Code Router (`127.0.0.1:3456` / UI `127.0.0.1:3458`) + Hermes Agent

---

## 1. Masalah & Solusi Konfigurasi Claude Code Router (CCR)

### A. Masalah yang Berhasil Diatasi
1. **Model Terkunci pada Model Berbayar (`claude-sonnet-4.6`) & `openrouter/free` (HTTP 400):**
   - **Penyebab:** CCR menyimpan profil default di SQLite (`default-claude-code` / `Claude Code`) yang mengarah ke `claude-sonnet-4.6`, sementara virtual model `openrouter/free` gagal memproses skema *tool calling* besar milik Claude Code (`HTTP 400`).
   - **Solusi:** Mengubah seluruh slot model pada **Agent Profiles > Claude Code** menjadi `GEMINI/gemini-3.1-flash-lite` dan mengarahkan **Global Routing (`agent-free`)** ke rantai model Gemini aktif.
2. **Database Statistik CCR Rusak (`database disk image is malformed`):**
   - **Penyebab:** File `~/.claude-code-router/app-data/usage.sqlite` (beserta `-wal` 4,1 MB dan `-shm`) mengalami kerusakan btree/index (`error code 11`).
   - **Solusi:** Memindahkan `usage.sqlite*` ke folder backup (`~/.claude-code-router/ccr-backup/corrupt-db/`) dan menjalankan `ccr restart` hingga database baru bersih.
3. **Verifikasi Model Gemini Gratis pada API Key (`AQ....`):**
   - **Hasil Uji:** `gemini-3.5-flash-lite` dan `gemini-3-flash-preview` terbukti merespons cepat (`200 OK`), didukung oleh `gemini-3.1-flash-lite` (dengan *prompt caching* aktif).
   - **Pembuangan Model Bermasalah:** Model `gemma-4-26b-a4b-it:free` dan `openrouter/free` yang memicu `429`/`400` serta melambatkan rantai *fallback* (`R5`) dibuang dari daftar routing.
   - **Rencana Malam Ini:** Menambahkan **GitHub Models** (`https://azure.com` — `gpt-4o-mini` / `DeepSeek-V3`) atau **Cerebras** (`https://cerebras.ai`) sebagai cadangan gratis tambahan di luar Gemini.

### B. Ringkasan Kode Error HTTP untuk Acuan Cepat
- **`200 OK`:** Permintaan sukses diproses.
- **`400 Bad Request`:** Nama model tidak terdaftar di provider atau format *tool calling* tidak didukung (contoh: `openrouter/free` atau salah ketik `GEMINI/GEMINI/...`).
- **`404 Not Found`:** URL endpoint atau ID model tidak valid/pensiun.
- **`429 Too Many Requests`:** Melebihi batas kuota gratis (RPM/TPM/RPD). **Solusi:** Tunggu 60 detik atau ketik `/exit` lalu buka sesi baru `claude` untuk mereset ukuran konteks.
- **`499 Client Closed Request`:** Koneksi dibatalkan dari terminal (`Esc` / `Ctrl+C`) sebelum selesai.
- **`503 Service Unavailable`:** Kepadatan sementara di server Google/upstream (pulih dalam 10–30 detik).

---

## 2. Status Progres Audit Gelombang 2 (`proyekgmn-dev`)

### Sudah Selesai & Terdokumentasi di `docs/`
1. **Fase 1–3 & Gap #2–#3 (Kartu Piutang/Aging AR & Kartu Hutang/Aging AP):**
   - Verifikasi tercatat pada `docs/AUDIT_GEL2_AR_AP_AGING_VERIFICATION_2026-11-19.md`.
2. **Gap #4 (Retensi Klien AR):**
   - Skrip migrasi `scripts/apply_retention_migration.php` selesai dibuat dan diverifikasi.
3. **Gap #6 (PPh Supplier) — SELESAI TUNTAS:**
   - **Database:** Penambahan `pph_amount` dan `pph_rate` pada `purchase_invoice_hdr`, akun CoA baru, serta aturan jurnal `PPH_SUPPLIER`.
   - **Backend & Frontend:** Pembaruan `php-crud/purchaseinvoiceCRD.php` dan `js/purchaseinvoice.js`.
   - **Dokumentasi:** `docs/AUDIT_GAP_6_PPH_SUPPLIER_2026-11-20.md`.
4. **Gap #7 (GRN Approval Gate) — SELESAI TUNTAS:**
   - **Database:** Migrasi idempoten `sql/2026-11-20_grn_approval_gate_gap7.sql` (menambahkan `approval_status`, `approved_by`, `approved_at`, `approved_note` pada `public.grn_hdr`).
   - **Backend & Frontend:** GRN baru otomatis berstatus `PENDING` (tidak menambah stok gudang & tidak bisa ditagih vendor sebelum `APPROVED`), tombol aksi Approve/Reject/Unapprove di `js/grn.js`, sementara alur *release* PO tetap langsung ke `'Released'`.
   - **Dokumentasi:** `docs/AUDIT_GAP_7_GRN_APPROVAL_2026-11-20.md` dan `docs/SESSION_LOG.md`.

### Sisa Pekerjaan Berikutnya (Dilanjutkan Nanti)
- **Gap #8:** Tutup Periode (*Period Closing*).
- **Gap #10 & #12:** *Code & Database Hygiene* serta *Subledger Integrity*.
- **Gap #11:** *End-to-End Verification & Final Audit Report*.

---

## 3. Setup Notifikasi WhatsApp (Hermes Agent -> Claude Code)

- **Skrip Hook:** `/home/myadmin/.claude/notify-wa.sh` (terpasang pada event `TaskCompleted`, `SubagentStop`, dan `Stop` di `settings.groq.json`, `settings.local.json`, dan `settings.json`).
- **Target WhatsApp:** `whatsapp:222329082802344@lid` (Eddy).
- **Langkah Pengecekan Saat Lanjut Nanti:**
  Jalankan perintah berikut untuk memastikan gateway WhatsApp Hermes sedang berjalan dan mengecek log hook:
  ```bash
  /home/myadmin/.npm-global/bin/hermes gateway status
  /home/myadmin/.npm-global/bin/hermes send --to "whatsapp:222329082802344@lid" "Tes WA"
  cat /tmp/claude-wa-hook.log 2>/dev/null
  ```

---

## 4. Prompt Siap Pakai untuk Sesi Berikutnya (Gap #8: Tutup Periode)

Pastikan memulai sesi bersih (`claude` di `/home/myadmin/proyekgmn-dev`) lalu tempelkan perintah berikut:

```text
Baca docs/SESSION_LOG.md dan docs/BUSINESS_GAP_REPORT.md. Fokus kerjakan hanya Gap #8 (Tutup Periode / Period Closing) sampai selesai.

Lakukan langkah berikut secara berurutan:
1. Lakukan analisis mendalam terhadap struktur periode akuntansi, status buka/tutup buku bulanan atau tahunan, serta mekanisme pemblokiran transaksi pada periode yang sudah ditutup sebelum mengubah kode.
2. Implementasikan validasi Tutup Periode pada backend PHP (seluruh modul jurnal, AR/AP, kas/bank, dan inventory) serta antarmuka pengelolaan periode di ExtJS 3.4.1 dengan transaksi database yang aman.
3. Pastikan halaman atau sub-modul Tutup Periode otomatis terdaftar ke dalam sistem menu aplikasi.
4. Simpan laporan audit & implementasi lengkap Gap #8 dalam format Markdown (.md) serta perbarui docs/SESSION_LOG.md di folder /home/myadmin/proyekgmn-dev/docs.
```
