-- ============================================================================
-- MIGRATION: Dynamic User Role & Access Matrix
-- Date: 2026-11-22
-- Description: Creates m_role, m_role_access_menu, adds role_id to m_user_system,
--              seeds default editable roles, and registers Master User Role form.
-- ============================================================================

BEGIN;

-- 1. Create m_role table
CREATE TABLE IF NOT EXISTS public.m_role (
    id SERIAL PRIMARY KEY,
    role_code VARCHAR(32) NOT NULL UNIQUE,
    role_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Create m_role_access_menu table (matching m_user_access_menu structure exactly)
CREATE TABLE IF NOT EXISTS public.m_role_access_menu (
    id SERIAL PRIMARY KEY,
    role_id INTEGER NOT NULL REFERENCES public.m_role(id) ON DELETE CASCADE,
    form_id INTEGER NOT NULL REFERENCES public.m_form_name(id) ON DELETE CASCADE,
    access_level SMALLINT NOT NULL DEFAULT 1,
    CONSTRAINT uq_role_form UNIQUE (role_id, form_id)
);

-- 3. Add role_id column to m_user_system if not exists
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'm_user_system'
          AND column_name = 'role_id'
    ) THEN
        ALTER TABLE public.m_user_system ADD COLUMN role_id INTEGER REFERENCES public.m_role(id) ON DELETE SET NULL;
    END IF;
END $$;

-- 4. Seed initial default roles
INSERT INTO public.m_role (id, role_code, role_name, description, is_active)
VALUES
    (1, 'ADMINISTRATOR', 'Administrator', 'Full system access across all modules', true),
    (2, 'FINANCE', 'Finance / Accounting', 'Access to financial reports, journal entries, AP/AR, and payment modules', true),
    (3, 'PURCHASING', 'Purchasing', 'Access to Purchase Orders, GRN, Purchase Invoices, and Suppliers', true),
    (4, 'PROJECT', 'Project Manager', 'Access to project RAB, progress, invoicing, and subcon tracking', true),
    (5, 'WAREHOUSE', 'Warehouse / Inventory', 'Access to stock card, issuing, receiving, and stock opname', true),
    (6, 'VIEWER', 'Viewer / General', 'Read-only access to general reports and dashboards', true)
ON CONFLICT (role_code) DO NOTHING;

-- Reset sequence for m_role if needed
SELECT setval('m_role_id_seq', (SELECT COALESCE(MAX(id), 1) FROM public.m_role));

-- 5. Seed default role access for ADMINISTRATOR (role_id = 1) -> Full access (level 4) to all active forms
INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
SELECT 1, id, 4 FROM public.m_form_name WHERE active = true
ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = 4;

-- 6. Register "Master User Role" in m_form_name
-- Find or use an appropriate group_id for Utility / Master (e.g. group with seq starting with '99' or similar, or fallback to first group)
DO $$
DECLARE
    v_group_id INTEGER;
    v_form_id INTEGER;
BEGIN
    SELECT id INTO v_group_id FROM public.m_form_group WHERE seq LIKE '99%' OR lower(name) LIKE '%utility%' OR lower(name) LIKE '%setting%' LIMIT 1;
    IF v_group_id IS NULL THEN
        SELECT id INTO v_group_id FROM public.m_form_group ORDER BY id LIMIT 1;
    END IF;

    -- Insert or get Master User Role form
    INSERT INTO public.m_form_name (group_id, seq, title, php_url, js_url, remark, active)
    VALUES (v_group_id, '9905', 'Master User Role', 'm.user.role.php', 'm.user.role.js', 'Dynamic User Role & Access Matrix Management', true)
    ON CONFLICT DO NOTHING;

    SELECT id INTO v_form_id FROM public.m_form_name WHERE php_url = 'm.user.role.php' LIMIT 1;

    -- Give Administrator role full access to Master User Role
    IF v_form_id IS NOT NULL THEN
        INSERT INTO public.m_role_access_menu (role_id, form_id, access_level)
        VALUES (1, v_form_id, 4)
        ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = 4;

        -- Give user 'admin' (or user_id = 1 or login_name = 'admin') access in m_user_access_menu
        INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
        SELECT id, v_form_id, 4 FROM public.m_user_system WHERE login_name = 'admin' OR id = 1
        ON CONFLICT DO NOTHING;
    END IF;
END $$;

COMMIT;

-- ============================================================================
-- -- ROLLBACK / DOWN MIGRATION (Manual Execution Reference)
-- BEGIN;
-- DELETE FROM public.m_user_access_menu WHERE form_id IN (SELECT id FROM public.m_form_name WHERE php_url = 'm.user.role.php');
-- DELETE FROM public.m_form_name WHERE php_url = 'm.user.role.php';
-- ALTER TABLE public.m_user_system DROP COLUMN IF EXISTS role_id;
-- DROP TABLE IF EXISTS public.m_role_access_menu CASCADE;
-- DROP TABLE IF EXISTS public.m_role CASCADE;
-- COMMIT;
-- ============================================================================
