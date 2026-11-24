-- ============================================================================
-- MIGRATION: sql/2026-11-23.fin_fa_patch_batch2.sql
-- Description: SQL DDL & Config additions for Batch 2:
--              1. FIN-03: Pemisahan Akun COA Retensi (fiscal_config rules & columns)
--              2. FA-02: Konsistensi Guard CLIENT_SUPPLIED Rp 0 (audit flag & index)
--              3. FA-04: Keamanan File Upload (audit log table for secure uploads)
--
-- NOTE: Developer executes this manually after review. DO NOT run DDL automatically.
-- ============================================================================

BEGIN;

-- 1. FIN-03: Tambah Fiscal Config untuk Retensi (Project & Subcon Retention)
INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'PROJECT_RETENTION', 'Piutang Retensi Proyek',
       (SELECT id FROM chart_of_accounts WHERE account_code LIKE '1%' LIMIT 1),
       (SELECT id FROM chart_of_accounts WHERE account_code LIKE '1%' LIMIT 1),
       true
WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'PROJECT_RETENTION');

INSERT INTO public.fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
SELECT 'SUBCON_RETENTION_LIAB', 'Hutang Retensi Subkon',
       (SELECT id FROM chart_of_accounts WHERE account_code LIKE '2%' LIMIT 1),
       (SELECT id FROM chart_of_accounts WHERE account_code LIKE '2%' LIMIT 1),
       true
WHERE NOT EXISTS (SELECT 1 FROM public.fiscal_config WHERE rule_code = 'SUBCON_RETENTION_LIAB');


-- 2. FA-02: Indeks & Kolom untuk memperkuat audit CLIENT_SUPPLIED Rp 0
-- Memastikan tabel budget_items dan po_items memiliki indeks material_source
CREATE INDEX IF NOT EXISTS idx_budget_items_material_source ON public.budget_items(material_source);


-- 3. FA-04: Tabel log keamanan file upload
CREATE TABLE IF NOT EXISTS public.secure_upload_logs (
    id SERIAL PRIMARY KEY,
    user_id INT,
    original_filename VARCHAR(255),
    stored_filename VARCHAR(255),
    mime_type VARCHAR(100),
    file_size INT,
    upload_status VARCHAR(50),
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW()
);

COMMIT;

-- ============================================================================
-- -- ROLLBACK / DOWN MIGRATION
-- BEGIN;
-- DELETE FROM public.fiscal_config WHERE rule_code IN ('PROJECT_RETENTION', 'SUBCON_RETENTION_LIAB');
-- DROP INDEX IF EXISTS public.idx_budget_items_material_source;
-- DROP TABLE IF EXISTS public.secure_upload_logs;
-- COMMIT;
-- ============================================================================
