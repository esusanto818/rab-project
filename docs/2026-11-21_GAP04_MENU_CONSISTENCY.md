# GAP-04: Konsistensi Pendaftaran Menu

Audit konsistensi pendaftaran menu dilakukan dengan memeriksa database `m_form_name`, `m_form_group`, `m_user_access_menu`, dan file fisik di `views/`.

## Ringkasan Temuan
1.  **Menu Terdaftar tapi View Tidak Ada**: Ditemukan 61 entri di `m_form_name` yang memiliki `php_url` namun file fisiknya tidak ditemukan di folder `views/`. Sebagian besar tampaknya terkait modul lama atau yang sudah tidak aktif/diubah penamaannya (misalnya `m.sales.group.php`, `sales.forecast.php`, dll.).
2.  **Struktur Database**: Struktur pendaftaran menu sudah mengikuti standar `m_form_group` dan `m_form_name`.
3.  **Konsistensi Rute**: Penamaan menu sudah konsisten, namun terdapat banyak "sampah" menu yang tidak memiliki file fisik yang valid.

## Tindakan yang Diambil
- Menganalisis tabel `m_form_name` dan membandingkannya dengan file fisik di `views/`.
- Memvalidasi entri terbaru (termasuk `6038` dan `6039`) yang ditambahkan melalui migrasi `2026-11-17.ar_ap_aging_card.sql`.

## Rekomendasi
- **Pembersihan**: Melakukan pembersihan data pada `m_form_name` untuk menu yang sudah tidak memiliki view fisik yang aktif agar tidak membingungkan pengguna/sistem.
- **Standarisasi**: Memastikan setiap penambahan modul baru mengikuti pola `INSERT ... ON CONFLICT DO NOTHING` atau `DO $$ ... $$` seperti yang sudah dilakukan pada migrasi `2026-11-17.ar_ap_aging_card.sql`.
- **Maintenance**: Audit rutin terhadap menu yang tidak aktif untuk menjaga kebersihan database.

Status: **Selesai (Audit Gap-04)**
