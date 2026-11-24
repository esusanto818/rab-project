-- Idempotent index creation for Phase 10 audit findings
-- DB-001 & DB-002 (Migrasi Indeks Produksi)

-- Create indexes if they don't exist
CREATE INDEX IF NOT EXISTS idx_journal_entries_date_status ON journal_entries(journal_date, status);
CREATE INDEX IF NOT EXISTS idx_project_invoices_release_status ON project_invoices(release_status);
CREATE INDEX IF NOT EXISTS idx_purchase_invoice_hdr_status ON purchase_invoice_hdr(status);

-- ROLLBACK / DOWN MIGRATION
-- DROP INDEX IF EXISTS idx_journal_entries_date_status;
-- DROP INDEX IF EXISTS idx_project_invoices_release_status;
-- DROP INDEX IF EXISTS idx_purchase_invoice_hdr_status;
