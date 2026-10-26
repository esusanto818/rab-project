-- ============================================================
-- Menu Laporan PPN (PPN - TAHAP 3)
-- Grup   : acct report (dicari dari m_form_group.name)
-- Seq    : 6037
-- File   : views/ppn.report.php, js/ppn.report.js, php-crud/ppn.reportCRD.php
-- Aman dijalankan ulang (tidak membuat menu ganda).
-- ============================================================
INSERT INTO public.m_form_name (group_id, seq, title, php_url, js_url, remark, main_table, tna, active)
SELECT g.id, '6037', 'LAPORAN PPN', 'ppn.report.php', 'ppn.report.js',
       'Laporan PPN bulanan (keluaran vs masukan) per proyek', 'project_invoices', false, true
  FROM public.m_form_group g
 WHERE (g.name ILIKE '%acct%report%' OR g.name ILIKE '%accounting%report%')
   AND NOT EXISTS (SELECT 1 FROM public.m_form_name f WHERE f.seq = '6037' OR f.php_url = 'ppn.report.php')
 ORDER BY g.id
 LIMIT 1;

-- Verifikasi: harus 1 baris. Bila 0 baris, nama grup tidak cocok -> cek: SELECT id, seq, name FROM public.m_form_group ORDER BY seq;
-- lalu ganti kondisi WHERE g.name di atas dengan g.id = <id grup acct report>.
SELECT id, group_id, seq, title, php_url FROM public.m_form_name WHERE seq = '6037';

-- Hak akses menu (access_level 1 = lihat saja) TIDAK diberikan otomatis. Contoh untuk satu user:
-- INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
-- SELECT <user_id>, f.id, 1 FROM public.m_form_name f WHERE f.seq = '6037';
