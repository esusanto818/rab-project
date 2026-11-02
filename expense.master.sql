-- ============================================================
-- MASTER EXPENSE (Sederhana seperti Master Material)
-- Hanya berisi daftar jenis expense & harga default.
-- Tidak ada workflow, tidak ada jurnal, tidak ada budget control.
-- ============================================================

CREATE TABLE IF NOT EXISTS expense_master (
    id SERIAL PRIMARY KEY,
    expense_code VARCHAR(30) NOT NULL UNIQUE,
    expense_name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    default_price NUMERIC(15,2) NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP
);

/* Audit columns used by php-crud/m.expensesCRD.php.
   Safe for existing installations: rerunning this migration is idempotent. */
ALTER TABLE expense_master
    ADD COLUMN IF NOT EXISTS created_by INTEGER,
    ADD COLUMN IF NOT EXISTS updated_by INTEGER;

CREATE INDEX idx_expense_master_category ON expense_master(category);
CREATE INDEX idx_expense_master_active ON expense_master(active);

COMMENT ON TABLE expense_master IS 'Master Expense - daftar jenis expense (Upah, Transport, Peralatan, dll)';

-- Sample Data
INSERT INTO expense_master (expense_code, expense_name, category, unit, default_price) VALUES
('UPH-001', 'Upah Tukang Harian', 'Upah', 'hari', 150000),
('UPH-002', 'Upah Mandor', 'Upah', 'hari', 250000),
('TRP-001', 'Sewa Truk Engkel', 'Transport', 'trip', 500000),
('TRP-002', 'Sewa Truk Fuso', 'Transport', 'trip', 800000),
('ALT-001', 'Sewa Excavator', 'Peralatan', 'hari', 1500000),
('ALT-002', 'Sewa Crane', 'Peralatan', 'hari', 2500000),
('KON-001', 'Makan Tukang', 'Konsumsi', 'paket', 25000),
('LNL-001', 'Biaya Lain-lain', 'Lain-lain', 'unit', 0)
ON CONFLICT (expense_code) DO NOTHING;
