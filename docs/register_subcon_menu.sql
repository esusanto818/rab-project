-- Pendaftaran Menu Subkontraktor (Subcon Progress)
-- Jalankan pada database yang sesuai (gunakan psql)

INSERT INTO m_form_name (id, title, php_url, group_id, remark)
SELECT 
    COALESCE((SELECT MAX(id)+1 FROM m_form_name), 100), 
    'Prestasi Subkontraktor', 
    'subcon.progress.php', 
    (SELECT id FROM m_form_group WHERE name LIKE '%Subkon%' LIMIT 1), 
    'Modul Monitoring & Inspeksi Subkon'
WHERE NOT EXISTS (SELECT 1 FROM m_form_name WHERE php_url = 'subcon.progress.php');

-- Berikan hak akses default untuk user admin (misal user_id = 1)
INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT 
    1, 
    (SELECT id FROM m_form_name WHERE php_url = 'subcon.progress.php'), 
    3
WHERE NOT EXISTS (
    SELECT 1 FROM m_user_access_menu 
    WHERE user_id = 1 
    AND form_id = (SELECT id FROM m_form_name WHERE php_url = 'subcon.progress.php')
);

