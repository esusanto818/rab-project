-- 1. Pastikan data karyawan (employee) dummy atau master tersedia, atau gunakan employee_id yang valid (misal ID 1 atau buat baru)
-- Jika ingin membuat record employee khusus admin terlebih dahulu:
INSERT INTO m_employee (id, employee_name, gender, from_date, active) 
VALUES (88888, 'demo user', 'M', CURRENT_DATE, true)
ON CONFLICT (id) DO NOTHING;
--insert admin to it department;
INSERT INTO employee_department (employee_id, department_id, from_date) 
VALUES (1, ,1,CURRENT_DATE)
ON CONFLICT (id) DO NOTHING;

-- 2. Insert ke tabel m_user_system
-- Catatan: Password '789' dienkripsikan menggunakan md5 atau format hash yang sesuai dengan validator login. 
-- Di bawah ini menggunakan MD5('789') atau plain text terenkripsi sesuai fungsi aplikasi:
INSERT INTO m_user_system (id, login_name, employee_id, password, active)
VALUES (
    DEFAULT, 
    'demo', 
    88888, -- Sesuaikan dengan id employee yang ada di tabel m_employee
    'xyz789', -- Contoh hash MD5 dari '789' (atau ganti dengan fungsi encode sistem)
    true
);

-- 3. Inisialisasi status login user (relasi m_user_login_status)
INSERT INTO m_user_login_status (user_id, login_status, f_count, f_lock)
SELECT id, false, 0, 0 
FROM m_user_system 
WHERE login_name = 'demo';

-- 4. Berikan Hak Akses Penuh (Access Level 4 = Read/Create/Update/Delete) untuk semua Form ke User Admin baru
INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT 
    (SELECT id FROM m_user_system WHERE login_name = 'demo'),
    id as form_id,
    4 as access_level
FROM m_form_name
ON CONFLICT DO NOTHING;
