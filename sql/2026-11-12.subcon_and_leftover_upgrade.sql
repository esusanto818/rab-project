BEGIN;

-- 1. ENHANCEMENT TABEL SPK
ALTER TABLE spk 
  ADD COLUMN IF NOT EXISTS spk_type VARCHAR(20) DEFAULT 'INTERNAL' CHECK (spk_type IN ('INTERNAL', 'SUBCON')),
  ADD COLUMN IF NOT EXISTS subcontractor_id INT REFERENCES subcontractors(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS parent_spk_id INT REFERENCES spk(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS revision_no INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS approved_scope_value NUMERIC(15,2) DEFAULT 0;

-- 2. TABEL BARU: spk_work_items
CREATE TABLE IF NOT EXISTS spk_work_items (
    id SERIAL PRIMARY KEY,
    spk_id INT REFERENCES spk(id) ON DELETE CASCADE,
    task_id INT REFERENCES project_tasks(id) ON DELETE SET NULL,
    budget_item_id INT REFERENCES budget_items(id) ON DELETE SET NULL,
    description TEXT,
    unit VARCHAR(50),
    contract_qty NUMERIC(15,3) DEFAULT 0,
    unit_price NUMERIC(15,2) DEFAULT 0,
    line_amount NUMERIC(15,2) DEFAULT 0,
    material_supply_mode VARCHAR(20) DEFAULT 'COMPANY' CHECK (material_supply_mode IN ('COMPANY', 'SUBCON', 'MIXED')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. TABEL BARU: subcon_inspections & subcon_inspection_items
CREATE TABLE IF NOT EXISTS subcon_inspections (
    id SERIAL PRIMARY KEY,
    spk_id INT REFERENCES spk(id) ON DELETE CASCADE,
    progress_id INT REFERENCES subcon_progress(id) ON DELETE SET NULL,
    inspection_date DATE NOT NULL,
    inspector_id INT REFERENCES users(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'PASSED', 'PARTIAL', 'REJECTED')),
    notes TEXT,
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subcon_inspection_items (
    id SERIAL PRIMARY KEY,
    inspection_id INT REFERENCES subcon_inspections(id) ON DELETE CASCADE,
    spk_work_item_id INT REFERENCES spk_work_items(id) ON DELETE SET NULL,
    qty_claimed NUMERIC(15,3) DEFAULT 0,
    qty_passed NUMERIC(15,3) DEFAULT 0,
    qty_rejected NUMERIC(15,3) DEFAULT 0,
    rework_required BOOLEAN DEFAULT FALSE,
    inspection_notes TEXT,
    CHECK (qty_passed + qty_rejected <= qty_claimed + 0.001)
);

-- 4. TABEL BARU: subcon_payment_certificates
CREATE TABLE IF NOT EXISTS subcon_payment_certificates (
    id SERIAL PRIMARY KEY,
    contract_id INT REFERENCES subcon_contracts(id) ON DELETE CASCADE,
    spk_id INT REFERENCES spk(id) ON DELETE CASCADE,
    progress_id INT REFERENCES subcon_progress(id) ON DELETE SET NULL,
    certificate_no VARCHAR(50) UNIQUE NOT NULL,
    certificate_date DATE NOT NULL,
    gross_approved NUMERIC(15,2) DEFAULT 0,
    retention_amount NUMERIC(15,2) DEFAULT 0,
    dp_recovery NUMERIC(15,2) DEFAULT 0,
    other_deductions NUMERIC(15,2) DEFAULT 0,
    tax_amount NUMERIC(15,2) DEFAULT 0,
    net_payable NUMERIC(15,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'INVOICED', 'PAID', 'CANCELLED')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subcon_payment_certificate_items (
    id SERIAL PRIMARY KEY,
    certificate_id INT REFERENCES subcon_payment_certificates(id) ON DELETE CASCADE,
    inspection_item_id INT REFERENCES subcon_inspection_items(id) ON DELETE SET NULL,
    qty_approved NUMERIC(15,3) DEFAULT 0,
    unit_price NUMERIC(15,2) DEFAULT 0,
    line_amount NUMERIC(15,2) DEFAULT 0
);

-- 5. TABEL BARU: material_leftovers & Transaksi
CREATE TABLE IF NOT EXISTS material_leftovers (
    id SERIAL PRIMARY KEY,
    project_id INT REFERENCES projects(id) ON DELETE CASCADE,
    spk_id INT REFERENCES spk(id) ON DELETE SET NULL,
    material_id INT REFERENCES materials(id) ON DELETE RESTRICT,
    source_owner_type VARCHAR(20) CHECK (source_owner_type IN ('COMPANY', 'SUBCON')),
    source_owner_id INT,
    qty_detected NUMERIC(15,3) DEFAULT 0,
    qty_available NUMERIC(15,3) DEFAULT 0,
    condition VARCHAR(50) DEFAULT 'GOOD',
    current_owner_type VARCHAR(20) CHECK (current_owner_type IN ('COMPANY', 'SUBCON', 'CLIENT')),
    location VARCHAR(100),
    status VARCHAR(20) DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'PROCESSED', 'CANCELLED')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS leftover_price_approvals (
    id SERIAL PRIMARY KEY,
    leftover_id INT REFERENCES material_leftovers(id) ON DELETE CASCADE,
    transaction_type VARCHAR(30) CHECK (transaction_type IN ('BUY_FROM_SUBCON', 'SELL_TO_CLIENT')),
    market_price NUMERIC(15,2) DEFAULT 0,
    proposed_price NUMERIC(15,2) DEFAULT 0,
    discount_pct NUMERIC(5,2) DEFAULT 0,
    reason TEXT,
    approved_by_role VARCHAR(50),
    approved_at TIMESTAMP,
    status VARCHAR(20) DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED'))
);

CREATE TABLE IF NOT EXISTS leftover_transactions (
    id SERIAL PRIMARY KEY,
    leftover_id INT REFERENCES material_leftovers(id) ON DELETE CASCADE,
    transaction_type VARCHAR(30) CHECK (transaction_type IN ('RETURN_TO_WAREHOUSE', 'PURCHASE', 'SALE')),
    qty_processed NUMERIC(15,3) DEFAULT 0,
    unit_price NUMERIC(15,2) DEFAULT 0,
    total_amount NUMERIC(15,2) DEFAULT 0,
    reference_doc_no VARCHAR(50),
    transaction_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_spk_work_items_spk ON spk_work_items(spk_id);
CREATE INDEX IF NOT EXISTS idx_subcon_inspections_spk ON subcon_inspections(spk_id);
CREATE INDEX IF NOT EXISTS idx_subcon_payment_cert_status ON subcon_payment_certificates(status);
CREATE INDEX IF NOT EXISTS idx_material_leftovers_project ON material_leftovers(project_id);

COMMIT;
