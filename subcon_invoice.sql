-- ============================================================
-- SUBCONTRAKTOR INVOICE & PAYMENT (2-Step Payment)
-- Migration Date: 2026-11-02
-- ============================================================

-- Tabel Invoice Subkontraktor
CREATE TABLE IF NOT EXISTS subcon_invoices (
    id SERIAL PRIMARY KEY,
    invoice_no VARCHAR(30) NOT NULL UNIQUE,
    contract_id INT NOT NULL REFERENCES subcon_contracts(id) ON DELETE CASCADE,
    progress_id INT REFERENCES subcon_progress(id),
    invoice_date DATE NOT NULL,
    due_date DATE,
    total_amount NUMERIC(15,2) NOT NULL DEFAULT 0,
    paid_amount NUMERIC(15,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Draft',
    reference_no VARCHAR(100),
    notes TEXT,
    created_by INT REFERENCES m_user_system(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP,
    approved_by INT REFERENCES m_user_system(id),
    approved_at TIMESTAMP,
    cancelled_by INT REFERENCES m_user_system(id),
    cancelled_at TIMESTAMP,
    cancel_reason TEXT,
    CONSTRAINT chk_subcon_inv_status CHECK (status IN ('Draft', 'Diajukan', 'Disetujui', 'Dibatalkan')),
    CONSTRAINT chk_subcon_inv_amount CHECK (total_amount >= 0 AND paid_amount >= 0)
);

CREATE INDEX idx_subcon_invoices_contract ON subcon_invoices(contract_id);
CREATE INDEX idx_subcon_invoices_progress ON subcon_invoices(progress_id);
CREATE INDEX idx_subcon_invoices_status ON subcon_invoices(status);

COMMENT ON TABLE subcon_invoices IS 'Invoice subkontraktor (step 1: terima invoice dari subkon)';

-- Tabel Pembayaran Invoice Subkontraktor
CREATE TABLE IF NOT EXISTS subcon_invoice_payments (
    id SERIAL PRIMARY KEY,
    payment_no VARCHAR(30) NOT NULL UNIQUE,
    invoice_id INT NOT NULL REFERENCES subcon_invoices(id) ON DELETE CASCADE,
    contract_id INT NOT NULL REFERENCES subcon_contracts(id) ON DELETE CASCADE,
    payment_date DATE NOT NULL,
    account_id INT NOT NULL REFERENCES chart_of_accounts(id),
    payment_method VARCHAR(20) NOT NULL DEFAULT 'BANK_TRANSFER',
    amount NUMERIC(15,2) NOT NULL DEFAULT 0,
    reference_no VARCHAR(100),
    notes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Posted',
    created_by INT REFERENCES m_user_system(id),
    created_at TIMESTAMP DEFAULT NOW(),
    cancelled_by INT REFERENCES m_user_system(id),
    cancelled_at TIMESTAMP,
    cancel_reason TEXT,
    CONSTRAINT chk_subcon_inv_pay_status CHECK (status IN ('Posted', 'Dibatalkan')),
    CONSTRAINT chk_subcon_inv_pay_amount CHECK (amount > 0)
);

CREATE INDEX idx_subcon_inv_payments_invoice ON subcon_invoice_payments(invoice_id);
CREATE INDEX idx_subcon_inv_payments_contract ON subcon_invoice_payments(contract_id);

COMMENT ON TABLE subcon_invoice_payments IS 'Pembayaran invoice subkontraktor (step 2: release payment)';

-- Tambah reference type jurnal (jika pakai enum)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'journal_reference_type') THEN
        ALTER TYPE journal_reference_type ADD VALUE IF NOT EXISTS 'subcon_invoice';
        ALTER TYPE journal_reference_type ADD VALUE IF NOT EXISTS 'subcon_invoice_pay';
    END IF;
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
