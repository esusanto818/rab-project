Baca docs/SESSION_LOG.md, docs/AUDIT_CHANGELOG.md, dan docs/BUSINESS_GAP_REPORT.md. Identifikasi seluruh gap yang masih tersisa dan urutkan berdasarkan prioritas tertinggi hingga terendah.

Kerjakan SEMUA gap yang tersisa secara berurutan (loop satu per satu dari awal sampai seluruh daftar selesai) tanpa berhenti menunggu persetujuan antar-gap. Untuk SETIAP gap yang dikerjakan, wajib jalankan siklus lengkap berikut sebelum lanjut ke gap berikutnya:

1. ANALISIS: Periksa struktur tabel PostgreSQL, file backend PHP, dan file frontend ExtJS terkait untuk memastikan tidak ada duplikasi atau perubahan yang merusak modul lain.
2. IMPLEMENTASI: Tulis kode dengan standar stack proyek (PHP Native, pg_query_params, transaksi BEGIN/COMMIT, dan ExtJS 3.4.1). Pastikan seluruh periode akuntansi tetap terbuka (is_closed = false).
3. MENU & INTEGRASI: Jika ada halaman/sub-modul baru, wajib daftarkan otomatis ke dalam sistem menu aplikasi (menudata.php / tabel menu database).
4. DOKUMENTASI: Buat laporan audit & implementasi lengkap dalam format Markdown (.md) di /home/myadmin/proyekgmn-dev/docs, serta perbarui docs/SESSION_LOG.md dan docs/AUDIT_CHANGELOG.md.
5. GIT CHECKPOINT: Jalankan git status -s (pastikan tidak ada file sementara/sampah), lalu lakukan git add, git commit dengan pesan deskriptif (sebutkan nomor dan nama Gap), dan git push origin dev.

Baru lanjutkan ke gap berikutnya setelah poin 1 sampai 5 untuk gap saat ini benar-benar tuntas. Jika seluruh gap di laporan sudah selesai 100%, tampilkan ringkasan akhir seluruh perubahan dan daftar commit hash-nya.

---
**WAJIB NOTIFIKASI WHATSAPP (EKSEKUSI BASH ASLI):** Setelah tugas selesai (atau setiap selesai 1 Gap dalam mode loop), wajib jalankan perintah bash asli:
`hermes send --to whatsapp:6281231788177 "✅ [proyekgmn-dev] Tugas/Gap selesai. Commit: <hash>"`
(Jika gagal karena gateway mati, jalankan `hermes gateway restart && sleep 3` lalu kirim ulang). Dilarang simulasi teks!
