# Panduan Penggunaan Multi-Agent & Slash Command (`proyekgmn-dev`)

Dokumen ini berisi panduan praktis cara menjalankan Claude Code beserta seluruh sub-agent otomatis untuk audit, pembangunan fitur, analisis celah bisnis, hingga pemotongan modul untuk proyek baru.

---

## 1. Perintah Dasar Membuka Claude Code

Pastikan Anda berada di dalam direktori proyek (`cd ~/proyekgmn-dev`), lalu gunakan perintah berikut:

* **Memulai sesi baru:**
  ```bash
  claude
  ```
* **Melanjutkan sesi terakhir yang terputus/selesai:**
  ```bash
  claude --continue
  ```
* **Memilih dari daftar riwayat sesi sebelumnya:**
  ```bash
  claude --resume
  ```
* **Mengecek status tugas aktif atau sesi:**
  Ketik `/tasks` atau `/status` di dalam kolom chat Claude Code.

---

## 2. Daftar Lengkap Slash Command & Sub-Agent

### A. Business Gap Analysis (`business-gap-analyst`)
Digunakan saat Anda ingin mengecek modul/alur bisnis ERP apa saja yang masih kurang atau terlupa (misal: AR, AP, Retensi, Aging, Jurnal Otomatis), memilih modul untuk dibangun, dan langsung mengaudit hasilnya.

* **Langkah 1 — Minta analisis & daftar saran pilihan modul:**
  ```text
  /business-gap
  ```
* **Langkah 2 — Pilih modul untuk langsung dibangun + re-audit otomatis:**
  ```text
  /business-gap eksekusi pilihan 1 dan 2
  ```
  *(Atau sebutkan langsung nama fiturnya: `/business-gap tambah modul AP Kontrabon Supplier dan Laporan Aging AR/AP`)*

---

### B. Pemotong & Penggabung Modul (`module-extractor-merger`)
Digunakan untuk mengambil satu modul utuh ("tangan beserta seluruh jari-jarinya": UI ExtJS, PHP backend, skema & seed SQL, dropdown lookup, hingga file laporan PDF/Excel) dari proyek lama untuk dijadikan fondasi proyek baru atau disuntikkan ke proyek lain.

* **Mode 1 — Membuat pondasi proyek baru (`mode=init`):**
  ```text
  /potong-modul source=~/proyekgmn-dev target=~/newproject modul="Menu, Role, dan User Management" mode=init
  ```
* **Mode 2 — Menyuntikkan modul + laporan ke proyek lain (`mode=inject`):**
  ```text
  /potong-modul source=~/proyekgmn-dev target=~/newproject modul="Accounting dan Laporan Keuangan" mode=inject
  ```

---

### C. Audit & Perbaikan Tampilan Grid (`grid-wrap-auditor`)
Digunakan untuk memindai tabel/grid ExtJS 3.4.1 maupun HTML agar teks header dan isi kolom turun ke baris baru (*text-wrap*) dan tidak terpotong tanda titik-titik (`...`).

* **Audit seluruh modul yang grid-nya masih terpotong:**
  ```text
  /audit-grid-wrap
  ```
* **Audit khusus satu modul tertentu:**
  ```text
  /audit-grid-wrap fokus pada modul Pembayaran Termin dan Warehouse
  ```

---

### D. Audit Kode, Keamanan & Tambal Bug (`db-code-auditor` & `fullstack-patcher`)
Digunakan untuk memeriksa celah keamanan SQL (`pg_query_params`), transaksi `BEGIN/COMMIT`, serta memperbaiki alur logika bisnis yang error.

* **Audit satu fitur spesifik:**
  ```text
  /audit-fitur cek alur release PO dan update stok GRN
  ```
* **Audit sekaligus langsung menambal SQL, Backend PHP, dan UI:**
  ```text
  /audit-dan-tambal perbaiki validasi sisa budget RAB saat pengajuan material
  ```

---

## 3. Alur Kerja Harian yang Disarankan (Best Practice)

1. **Sebelum Mulai Kerja:** Jalankan `git status` untuk memastikan tidak ada perubahan menggantung dari sesi sebelumnya.
2. **Saat Membangun Fitur Baru:** Gunakan `/business-gap` untuk melihat saran modul atau langsung bangun fitur, lalu biarkan agen melakukan re-audit otomatis.
3. **Verifikasi Browser:** Setelah agen selesai mengubah UI/JS, buka browser dan tekan **`Ctrl + Shift + R`** (*hard refresh*) agar cache JavaScript lama terhapus.
4. **Simpan ke Git per Tugas:** Lakukan `git add` dan `git commit` setiap kali 1 modul selesai diuji agar riwayat kode tetap bersih dan mudah di-*rollback* jika perlu.
5. **Jika Layanan Router (`ccr`) Macet:** Jalankan perintah berikut di terminal:
   ```bash
   ccr stop && ccr start --daemon
   ```

---

### E. Pengubah & Penyeragam Tema Warna (`theme-styler-auditor`)
Digunakan untuk mengubah tampilan dan tema warna aplikasi (ExtJS 3.4.1, Tailwind CSS, halaman Login, Dashboard, dan Grid) secara terpusat melalui file `css/custom-theme.css` tanpa merusak kode inti ExtJS maupun aturan *grid text-wrap*.

* **Mengubah tema dengan kode HEX spesifik:**
  ```text
  /update-theme Primary Burgundy (#7F1D1D), Accent Kuning Emas (#F59E0B), Background Abu Terang (#F8FAFC)
  ```
* **Mengubah tema dengan deskripsi gaya warna:**
  ```text
  /update-theme Nuansa Corporate Navy Blue (#1E3A8A) dan Teal (#0D9488) dengan kontras teks tinggi untuk modul operasional dan login
  ```
* **Menyesuaikan detail warna tertentu setelah cek di browser:**
  ```text
  /update-theme Perjelas kontras header grid ExtJS menjadi teks putih dan haluskan warna hover baris tabel
  ```

---

### E. Pengubah & Penyeragam Tema Warna (`theme-styler-auditor`)
Digunakan untuk mengubah tampilan dan tema warna aplikasi (ExtJS 3.4.1, Tailwind CSS, halaman Login, Dashboard, dan Grid) secara terpusat melalui file `css/custom-theme.css` tanpa merusak kode inti ExtJS maupun aturan *grid text-wrap*.

* **Mengubah tema dengan kode HEX spesifik:**
  ```text
  /update-theme Primary Burgundy (#7F1D1D), Accent Kuning Emas (#F59E0B), Background Abu Terang (#F8FAFC)
  ```
* **Mengubah tema dengan deskripsi gaya warna:**
  ```text
  /update-theme Nuansa Corporate Navy Blue (#1E3A8A) dan Teal (#0D9488) dengan kontras teks tinggi untuk modul operasional dan login
  ```
* **Menyesuaikan detail warna tertentu setelah cek di browser:**
  ```text
  /update-theme Perjelas kontras header grid ExtJS menjadi teks putih dan haluskan warna hover baris tabel
  ```
