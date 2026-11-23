BEGIN;

-- 1. Seed 6 Master Role Standar (Editable & Dinamis)
INSERT INTO public.m_role (role_code, role_name, description, is_active)
VALUES
  ('SYSADMIN',  'System Administrator',        'Akses penuh (Read/Create/Update/Delete) ke seluruh modul master, transaksi, laporan, dan pengaturan user/role.', TRUE),
  ('DIRECTOR',  'Direktur / Manajemen',        'Monitoring penuh (Read) seluruh proyek & keuangan, serta wewenang approval pada proyek, RAB, dan PO.', TRUE),
  ('PM',        'Project Manager / Site Eng.', 'Pengelolaan proyek, WBS/task, RAB & addendum, serta opname progres proyek (desktop & mobile).', TRUE),
  ('PURCH',     'Purchasing / Procurement',    'Pengelolaan supplier, purchase planning, PR, dan PO hingga release pembelian.', TRUE),
  ('WAREHOUSE', 'Warehouse / Logistik',        'Penerimaan barang (GRN), titipan klien, pengeluaran material (Issue/Out), retur supplier, dan kartu stok.', TRUE),
  ('FINANCE',   'Finance & Accounting',        'Pengelolaan COA, faktur pembelian (AP), penagihan termin klien (AR), kas/bank, pajak/PPN, dan laporan keuangan.', TRUE)
ON CONFLICT (role_code) DO UPDATE SET
  role_name   = EXCLUDED.role_name,
  description = EXCLUDED.description,
  is_active   = TRUE,
  updated_at  = NOW();

-- 2. SYSADMIN: Akses Level 4 (Full) untuk seluruh form aktif
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id, 4
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'SYSADMIN'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

-- 3. DIRECTOR: Read (1) semua form, Level 3 untuk Proyek, RAB, PO, dan Dashboard/Laporan
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id,
  CASE
    WHEN COALESCE(f.php_url,'') ~* '(proyek|project|rab|po\.php|purchase\.order)' THEN 3
    ELSE 1
  END
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'DIRECTOR'
  AND COALESCE(f.php_url,'') !~* '(user\.system|user\.role|form\.name)'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

-- 4. PM (Project Manager): Full (4) Proyek, RAB, Progress; Read (1) Master, PO, & Stok
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id,
  CASE
    WHEN COALESCE(f.php_url,'') ~* '(proyek|project|rab|progress|subkon|subcontract|task)' THEN 4
    ELSE 1
  END
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'PM'
  AND COALESCE(f.php_url,'') ~* '(proyek|project|rab|progress|subkon|subcontract|task|material|item|supplier|client|po\.php|stock|warehouse|dashboard)'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

-- 5. PURCH (Purchasing): Full (4) Supplier, Purchase Planning, PR, PO; Read (1) RAB, Material, GRN
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id,
  CASE
    WHEN COALESCE(f.php_url,'') ~* '(supplier|purch|planning|\bpr\b|\bpo\b)' AND COALESCE(f.php_url,'') !~* '(payment|invoice)' THEN 4
    ELSE 1
  END
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'PURCH'
  AND COALESCE(f.php_url,'') ~* '(supplier|purch|planning|\bpr\b|\bpo\b|material|item|uom|unit|rab|grn|receive|proyek|project|dashboard)'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

-- 6. WAREHOUSE (Gudang & Logistik): Full (4) GRN, Receive, Issue/Out, Retur, Stock Card; Read (1) PO & Material
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id,
  CASE
    WHEN COALESCE(f.php_url,'') ~* '(grn|receive|issue|out|retur|return|stock|warehouse|gudang|mutasi)' THEN 4
    ELSE 1
  END
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'WAREHOUSE'
  AND COALESCE(f.php_url,'') ~* '(grn|receive|issue|out|retur|return|stock|warehouse|gudang|mutasi|material|item|uom|unit|\bpo\b|proyek|project|dashboard)'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

-- 7. FINANCE (Keuangan & Akuntansi): Full (4) COA, Invoice AP/AR, Payment, Kas/Bank, Jurnal, Pajak, Laporan; Read (1) PO/GRN/Proyek
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT r.id, f.id,
  CASE
    WHEN COALESCE(f.php_url,'') ~* '(coa|account|invoice|bill|payment|bayar|kas|cash|bank|jurnal|journal|ppn|tax|pajak|ledger|neraca|balance|profit|loss|laba|rugi|aging|report|laporan)' THEN 4
    ELSE 1
  END
FROM public.m_role r
CROSS JOIN public.m_form_name f
WHERE r.role_code = 'FINANCE'
  AND COALESCE(f.php_url,'') ~* '(coa|account|invoice|bill|payment|bayar|kas|cash|bank|jurnal|journal|ppn|tax|pajak|ledger|neraca|balance|profit|loss|laba|rugi|aging|report|laporan|currency|kurs|supplier|client|\bpo\b|grn|proyek|project|rab|dashboard)'
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = EXCLUDED.access_level;

COMMIT;

-- Verifikasi hasil seed role & jumlah form per role
SELECT r.id, r.role_code, r.role_name, r.is_active, COUNT(m.form_id) AS jumlah_form
FROM public.m_role r
LEFT JOIN public.m_role_access_menu m ON m.role_id = r.id
GROUP BY r.id, r.role_code, r.role_name, r.is_active
ORDER BY r.id;
