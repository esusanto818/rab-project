-- [MENU-RENAME 2026-11-15] Penyesuaian penamaan menu: "TAGIHAN KE KLIEN" -> "TAGIHAN KLIEN".
-- ID menu (156), seq (6026), grup (75/TRANSACTION), php_url & js_url TIDAK berubah -> rute lama tetap aman.
-- Idempoten: UPDATE berbasis title lama; bila sudah "TAGIHAN KLIEN" tidak ada baris yang ter-update.
-- Jalankan di DB uji proyekGMN_test (dan DB produksi saat rilis):
--   psql -U postgres -d proyekGMN_test -v ON_ERROR_STOP=1 -f sql/2026-11-15.menu_tagihan_klien_rename.sql

UPDATE m_form_name
   SET title = 'TAGIHAN KLIEN'
 WHERE id = 156
   AND title = 'TAGIHAN KE KLIEN';

-- Verifikasi (opsional, jalankan manual):
-- SELECT id, seq, title, php_url, js_url FROM m_form_name WHERE id = 156;
