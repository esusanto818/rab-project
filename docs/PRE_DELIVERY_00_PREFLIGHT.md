# PRE_DELIVERY_00_PREFLIGHT.md

## 1. Lokasi Kerja & Branch
- **Path:** /home/myadmin/proyekgmn-dev
- **Branch:** dev (terverifikasi)

## 2. Status Git
- **HEAD Commit:** 62c95bee5307ec58acb4dfb6abd8e3f9d2d6d37f
- **Status:** (clean)

## 3. Konfigurasi Database
- **Source:** php-func/function.all.php
- **Status:** Koneksi PostgreSQL diinisialisasi melalui variabel lingkungan (`RAB_DB_HOST`, dll). Database aktual aplikasi disajikan melalui koneksi global \$dbconn / \$conn.

## 4. Verifikasi Docroot & URL
- **Kondisi:** Folder kerja `/home/myadmin/proyekgmn-dev` sesuai dengan URL development `http://127.0.0.1/rab-project/dev/`.

## 5. Keterbatasan
- Tidak ditemukan konflik instruksi yang menghambat dimulainya audit tahap 1.
- Tidak ada perubahan file aplikasi atau database yang dilakukan pada sesi ini.

## Gate 0 DoD
- [x] CLAUDE.md v2 dibaca dan dipahami.
- [x] Folder kerja = docroot URL terverifikasi.
- [x] Branch = dev.
- [x] DB test = proyekGMN_test (diketahui dari lingkungan pengembangan).
- [x] PRE_DELIVERY_00_PREFLIGHT.md dibuat.
