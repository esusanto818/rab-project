#!/bin/bash
set -e

echo "============================================"
echo "  RAB-PROJECT UPGRADE SCRIPT"
echo "  Tanggal: 2026-11-13"
echo "============================================"

# Cek kita ada di folder yang benar
if [ ! -f "config.php" ] && [ ! -f "index.php" ]; then
    echo "ERROR: Jalankan script ini dari root folder proyek (yang ada config.php)"
    exit 1
fi

echo "[1/4] Membuat folder sql jika belum ada..."
mkdir -p sql

echo "[2/4] Membuat file SQL migration..."
cat > sql/2026-11-12.subcon_and_leftover_upgrade.sql << 'SQLEOF'
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
SQLEOF
echo "  -> sql/2026-11-12.subcon_and_leftover_upgrade.sql dibuat."

echo "[3/4] Membuat file subcon.progressCRD.php..."
cat > php-crud/subcon.progressCRD.php << 'PHPEOF'
<?php
/**
 * subcon.progressCRD.php
 * [REVISI 2026-11-12] Menerapkan alur "No Inspection, No Payment".
 * Progres Subcon tidak lagi langsung diterima sebagai persentase, melainkan 
 * membuat draft inspeksi yang wajib diisi qty_passed oleh inspektor lapangan.
 */
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}

$task = $_REQUEST['task'] ?? '';
$db = new crud();

try {
    switch ($task) {
        case 'save_progress_draft':
            $spk_id = (int)($_POST['spk_id'] ?? 0);
            $progress_date = $_POST['progress_date'] ?? date('Y-m-d');
            $items = json_decode($_POST['items'] ?? '[]', true);

            if ($spk_id <= 0 || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'SPK dan item pekerjaan wajib diisi.']);
                break;
            }

            $db->beginTransaction();
            try {
                // 1. Buat Header Inspeksi (Status awal DRAFT)
                $stmt = $db->prepare("
                    INSERT INTO subcon_inspections (spk_id, inspection_date, status, created_at)
                    VALUES (:spk_id, :inspection_date, 'DRAFT', NOW())
                    RETURNING id
                ");
                $stmt->execute([':spk_id' => $spk_id, ':inspection_date' => $progress_date]);
                $inspection_id = (int)$stmt->fetchColumn();

                // 2. Simpan Detail Item Inspeksi (Default: semua claimed dianggap belum lolos sampai diperiksa)
                $stmt_item = $db->prepare("
                    INSERT INTO subcon_inspection_items 
                    (inspection_id, spk_work_item_id, qty_claimed, qty_passed, qty_rejected, rework_required)
                    VALUES (:inspection_id, :work_item_id, :qty_claimed, :qty_passed, :qty_rejected, :rework)
                ");

                foreach ($items as $item) {
                    $work_item_id = (int)($item['spk_work_item_id'] ?? 0);
                    $qty_claimed = (float)($item['qty_claimed'] ?? 0);
                    
                    // DEFAULT: qty_passed = 0 sampai inspektor lapangan mengupdate dan mengubah status ke PASSED/PARTIAL
                    $qty_passed = 0.0; 
                    $qty_rejected = $qty_claimed; 

                    $stmt_item->execute([
                        ':inspection_id' => $inspection_id,
                        ':work_item_id' => $work_item_id,
                        ':qty_claimed' => $qty_claimed,
                        ':qty_passed' => $qty_passed,
                        ':qty_rejected' => $qty_rejected,
                        ':rework' => true
                    ]);
                }

                $db->commit();
                echo json_encode([
                    'success' => true, 
                    'inspection_id' => $inspection_id,
                    'message' => 'Draft inspeksi berhasil dibuat. Silakan lengkapi hasil pemeriksaan fisik (qty_passed) sebelum diajukan.'
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        case 'approve_inspection':
            $inspection_id = (int)($_POST['inspection_id'] ?? 0);
            $status = $_POST['status'] ?? 'DRAFT';
            $items = json_decode($_POST['items'] ?? '[]', true);
            
            if ($inspection_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID inspeksi tidak valid.']);
                break;
            }
            
            $db->beginTransaction();
            try {
                // Update header inspeksi
                $db->cmdQ("UPDATE subcon_inspections SET status = '{$status}', approved_at = NOW() WHERE id = {$inspection_id}");
                
                // Update detail item jika ada data qty_passed dari UI
                if (!empty($items)) {
                    $stmt_upd = $db->prepare("
                        UPDATE subcon_inspection_items 
                        SET qty_passed = :qty_passed, 
                            qty_rejected = :qty_rejected,
                            rework_required = :rework,
                            inspection_notes = :notes
                        WHERE id = :item_id AND inspection_id = :inspection_id
                    ");
                    foreach ($items as $item) {
                        $stmt_upd->execute([
                            ':qty_passed' => (float)($item['qty_passed'] ?? 0),
                            ':qty_rejected' => (float)($item['qty_rejected'] ?? 0),
                            ':rework' => !empty($item['rework_required']),
                            ':notes' => $item['inspection_notes'] ?? '',
                            ':item_id' => (int)$item['id'],
                            ':inspection_id' => $inspection_id
                        ]);
                    }
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Inspeksi berhasil diperbarui.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        case 'load_inspection':
            $inspection_id = (int)($_GET['id'] ?? 0);
            if ($inspection_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                break;
            }
            $hdr = $db->query("SELECT * FROM subcon_inspections WHERE id = {$inspection_id}")->fetch(PDO::FETCH_ASSOC);
            $dtl = $db->query("
                SELECT sii.*, swi.description as work_description, swi.unit, swi.unit_price, swi.contract_qty
                FROM subcon_inspection_items sii
                LEFT JOIN spk_work_items swi ON swi.id = sii.spk_work_item_id
                WHERE sii.inspection_id = {$inspection_id}
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => ['header' => $hdr, 'items' => $dtl]]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Throwable $e) {
    error_log('[subcon.progressCRD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal memproses: ' . $e->getMessage()]);
}
?>
PHPEOF
echo "  -> php-crud/subcon.progressCRD.php dibuat."

echo "[4/4] Memodifikasi php-func/project.analysis.php..."
python3 << 'PYEOF'
import re

file_path = 'php-func/project.analysis.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Pola lama yang akan diganti
old_block = """    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $sub = ['kontrak_aktif' => 0, 'nilai_kontrak' => 0.0, 'tertagih_dpp' => 0.0, 'sisa_hutang' => 0.0];
    if ($hasSub) {
        $r = $q("SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                        (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                        (SELECT COALESCE(SUM(sp.gross_amount),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui') AS g,
                        (SELECT COALESCE(SUM(sp.net_payable),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui')
                        - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                  [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        $sub = ['kontrak_aktif' => (int)$r['n'], 'nilai_kontrak' => round((float)$r['v'], 2), 'tertagih_dpp' => round((float)$r['g'], 2), 'sisa_hutang' => round((float)$r['h'], 2)];
    }"""

# Pola baru
new_block = """    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $sub = ['kontrak_aktif' => 0, 'nilai_kontrak' => 0.0, 'tertagih_dpp' => 0.0, 'sisa_hutang' => 0.0];
    if ($hasSub) {
        // [REVISI 2026-11-12] Biaya Subcon sekarang diambil dari subcon_payment_certificates
        // (yang sudah APPROVED/PAID) agar hanya pekerjaan lolos inspeksi yang masuk biaya riil.
        $hasCert = tbl_exists($db, 'subcon_payment_certificates');
        if ($hasCert) {
            $r = $q("SELECT 
                        (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                        (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                        (SELECT COALESCE(SUM(gross_approved),0) FROM subcon_payment_certificates spc JOIN spk s ON s.id = spc.spk_id WHERE s.project_id = :p AND spc.status IN ('APPROVED', 'INVOICED', 'PAID')) AS g,
                        (SELECT COALESCE(SUM(net_payable),0) FROM subcon_payment_certificates spc JOIN spk s ON s.id = spc.spk_id WHERE s.project_id = :p AND spc.status IN ('APPROVED', 'INVOICED', 'PAID')) 
                        - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                      [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        } else {
            // Fallback ke logika lama jika tabel sertifikat belum di-migrate
            $r = $q("SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                            (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                            (SELECT COALESCE(SUM(sp.gross_amount),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui') AS g,
                            (SELECT COALESCE(SUM(sp.net_payable),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui')
                            - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                      [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        }
        $sub = ['kontrak_aktif' => (int)$r['n'], 'nilai_kontrak' => round((float)$r['v'], 2), 'tertagih_dpp' => round((float)$r['g'], 2), 'sisa_hutang' => round((float)$r['h'], 2)];
    }"""

if old_block in content:
    content = content.replace(old_block, new_block)
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("  -> project.analysis.php berhasil dimodifikasi.")
else:
    print("  -> PERINGATAN: Pola lama tidak ditemukan. File mungkin sudah dimodifikasi sebelumnya.")
    print("     Silakan cek manual bagian '$hasSub = tbl_exists' di php-func/project.analysis.php")
PYEOF

echo ""
echo "============================================"
echo "  SELESAI!"
echo "============================================"
echo ""
echo "Langkah berikutnya:"
echo "1. Jalankan SQL migration:"
echo "   psql -U <user> -d <database> -f sql/2026-11-12.subcon_and_leftover_upgrade.sql"
echo ""
echo "2. Commit perubahan:"
echo "   git add -A && git commit -m 'feat: subcon inspection, payment cert, leftover tables + dashboard fix'"
echo ""
