# PRE_DELIVERY_04_PATCH_PLAN.md

## Rencana Perbaikan (Patch Plan)

Daftar perbaikan disusun berdasarkan prioritas risiko (P0 = Kritis, P1 = Tinggi, P2 = Medium).

### P0: Kritis (Wajib Sebelum Serah-Terima)
1.  **KI-05 (User Role Sync):** Memperbaiki prosedur `sync_role_to_users` agar tidak menghapus *permission* manual.
    - *Rencana:* Ubah query `DELETE` menjadi `UPSERT` yang hanya memperbarui role terkait tanpa menghapus *permission* manual lainnya (atau tambah flag `manual_grant`).
    - *Backup:* `php-crud/m.user.roleCRD.php`

### P1: Tinggi
2.  **SEC-02 (Integrasi View):** Sinkronisasi view `v_project_actual_cost` dengan perubahan skema terbaru.
    - *Rencana:* Tambahkan pemeriksaan *view* pada setiap migrasi database dan buat *alert* log jika *view* gagal ter-load.

### P2: Medium
3.  **SEC-01 (Pembersihan Workspace):** Menghapus file sampah.
    - *Rencana:* Hapus daftar file sampah (sesuai `PRE_DELIVERY_03_CONSOLIDATED_REPORT.md`) dari direktori kerja.
    - *Risiko:* Tidak ada, file tersebut tidak digunakan oleh sistem.

---
## Ringkasan Persetujuan
Apakah Anda menyetujui rencana perbaikan di atas untuk dijalankan pada batch berikutnya?
- Jika setuju, akan dilanjutkan ke Tahap 5 (Persetujuan Pemilik Proyek/Developer) dan kemudian Tahap 6 (Eksekusi Batch).
