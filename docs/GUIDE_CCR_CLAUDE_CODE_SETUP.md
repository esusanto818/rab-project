# Panduan Perbaikan, Konfigurasi & Pemecahan Masalah CCR (`claude-code-router`)

**Tanggal Pembaruan:** 16 November 2026  
**Lingkungan:** Ubuntu Linux (RAM 6 GB) — Direktori Proyek `~/proyekgmn-dev`  
**Tujuan:** Menjalankan Claude Code CLI secara lokal melalui *gateway* `claude-code-router` (CCR) dengan penyedia **OpenRouter** (`openrouter/free`) dan **Groq** (`qwen/qwen3-32b`) tanpa galat autentikasi atau bentrok *port*.

---

## 1. Arsitektur & Cara Kerja CCR Versi Terbaru
1. **Penyimpanan Konfigurasi Utama:** CCR versi terbaru tidak lagi membaca `config.json` secara langsung setelah inisialisasi awal. Seluruh pengaturan *provider*, *router*, dan *profile* disimpan di database SQLite internal:
   - `~/.claude-code-router/config.sqlite`
   - `~/.claude-code-router/profiles/default-claude-code/`
   - `~/.claude-code-router/service.json`
2. **Alur Jalur Permintaan (Request Flow):**
   `Claude Code CLI` ➔ `CCR Gateway (127.0.0.1:3458)` ➔ `OpenRouter / Groq API (/chat/completions)`
3. **Alasan Wajib Menggunakan CCR untuk OpenRouter Gratis:**
   - Jika Claude Code dihubungkan langsung ke `https://openrouter.ai` tanpa CCR, OpenRouter mendeteksi header native Anthropic (`anthropic-beta`) dan memaksa aturan `provider.only: ["anthropic"]`, yang menyebabkan galat **400** pada semua model `:free`.
   - Melalui CCR, permintaan diterjemahkan ke format standar OpenAI Chat Completions sehingga model `openrouter/free` berjalan normal.

---

## 2. Prosedur Menjalankan CCR & Claude Code Sehari-hari

### Langkah A: Pastikan Daemon Gateway CCR Berjalan
Jalankan perintah berikut sebelum membuka Claude Code:
```bash
ccr start --daemon --gateway --no-open
```
*Catatan: Perintah ini akan menyalakan layanan CCR di port `3458` dan mencetak URL Web UI beserta `ccr_web_token`.*

### Langkah B: Jalankan Claude Code
Masuk ke direktori proyek dan jalankan `claude` (karena profil `default-claude-code` sudah disetel sebagai *System default* di CCR):
```bash
cd ~/proyekgmn-dev
claude
```

### Langkah C: Aturan Wajib Saat Bekerja di dalam Sesi Claude Code
1. **Gunakan `manual mode on` (Matikan `auto mode`):**
   - Tekan **`Shift + Tab`** jika indikator bawah bertuliskan `>> auto mode on`.
   - *Alasan:* Fitur `auto mode` memanggil API *Stage 2 classifier* milik Anthropic yang tidak tersedia pada provider pihak ketiga (OpenRouter/Groq) dan akan memblokir eksekusi *tool* shell/database.
2. **Hindari Banyak Background Agent Bersamaan:**
   - Selalu instruksikan agen bekerja secara sekuensial di sesi utama dan gunakan *tool* `Edit`/`Write` bawaan (hindari skrip *inline* `python3` panjang) agar konsumsi RAM 6 GB tetap stabil dan tidak *hang*.

---

## 3. Cara Menambah atau Mengubah Provider (OpenRouter / Groq)

Karena pengaturan utama berada di `config.sqlite`, cara teraman dan paling akurat mengubah provider adalah melalui **Web UI CCR**:

1. Cek URL Web UI beserta token aktif di terminal:
   ```bash
   cat ~/.claude-code-router/service.json
   ```
2. Buka nilai `"url"` (contoh: `http://127.0.0...`) di browser.
3. **Konfigurasi Provider OpenRouter:**
   - **Name:** `openrouter`
   - **Base URL:** `https://openrouter.ai/v1/chat/completions`
   - **API Key:** `sk-or-v1-...`
   - **Models:** `openrouter/free`
   - **Transformer:** `openrouter`
4. **Konfigurasi Provider Groq (Opsional / Cadangan):**
   - **Name:** `groq`
   - **Base URL:** `https://groq.com`
   - **API Key:** `gsk_...`
   - **Models:** `qwen/qwen3-32b`, `llama-3.3-70b-versatile`
   - **Transformer:** `groq`
5. **Pengaturan Profil (`Connect agent`):**
   - Pilih **Agent:** `Claude Code` | **Effect scope:** `System default`
   - Pilih **Default model** secara eksplisit dari daftar dropdown (misalnya `openrouter/openrouter/free`), lalu isi juga **Sonnet**, **Haiku**, dan **Opus** ke model yang sama agar sub-agen tidak memanggil model berbayar.
   - Klik **Next step >** hingga selesai dan simpan.

---

## 4. Kamus Pemecahan Masalah (Troubleshooting)

| Gejala / Pesan Error | Penyebab Utama | Solusi Cepat |
| :--- | :--- | :--- |
| **`API Error: 405 Method not allowed`** | `claude` dijalankan manual menggunakan flag `--settings` lama yang menembak endpoint UI tanpa jalur gateway aktif, atau `api_base_url` kurang `/chat/completions`. | Hentikan proses lama, jalankan `ccr start --daemon --gateway --no-open`, lalu jalankan `claude` biasa tanpa flag `--settings` manual. |
| **`Port http://127.0.0.1:3458 is already in use by a non-CCR service`** | Sisa proses CCR sebelumnya masih menggantung di port `3458`. | Jalankan:<br>`ccr stop && fuser -k 3458/tcp 2>/dev/null \|\| true`<br>lalu nyalakan ulang dengan `ccr start --daemon --gateway --no-open`. |
| **`Auto mode: Stage 2 classifier error`** | `auto mode on` aktif saat menggunakan model non-Anthropic (OpenRouter/Groq). | Tekan **`Shift + Tab`** di terminal Claude Code untuk beralih ke **`manual mode on`**. |
| **`API Error: 400 All target providers failed`** | Model yang diminta sesi Claude Code (misalnya `groq,...`) belum didaftarkan di Web UI CCR (`config.sqlite`). | Pastikan nama model di Claude Code (`/model`) sesuai dengan provider yang sudah disimpan di Web UI CCR. |
| **`API Error: 400 ... provider.only permits only: anthropic`** | Claude Code memanggil `https://openrouter.ai` secara langsung tanpa melewati CCR lokal. | Pastikan `claude` terhubung melalui CCR (`127.0.0.1:3458`) yang menggunakan endpoint `/api/v1/chat/completions`. |
| **`API error · Retrying in 0s · attempt 1/10`** | Antrean *upstream* model gratis di `openrouter/free` sedang padat sesaat. | Tunggu 10–15 detik hingga *auto-retry* menyambung ke model cadangan. Jika berhenti, ketik `lanjutkan` dan tekan **Enter**. |
| **Reset Total CCR (Jika Database Rusak)** | File `config.sqlite` korup atau konfigurasi tidak sinkron. | Jalankan:<br>`ccr stop && rm -f ~/.claude-code-router/config.sqlite*`<br>lalu jalankan `ccr start --daemon --gateway --no-open` dan ulangi setup lewat Web UI. |

---

## 5. Catatan Keamanan API Key
- Jangan pernah membagikan atau menyimpan kunci API asli (`sk-or-v1-...` atau `gsk_...`) ke dalam repositori Git proyek (`proyekgmn-dev`).
- Jika kunci API sempat terlihat di riwayat terminal atau tangkapan layar publik, segera lakukan **Revoke / Rotate Key** melalui dasbor penyedia (OpenRouter / Groq Console) dan perbarui kunci baru di Web UI CCR.
