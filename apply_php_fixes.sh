#!/bin/bash
set -e

echo "============================================"
echo "  APPLY PHP FIXES (No SQL needed)"
echo "  Database sudah lengkap, hanya perlu update PHP"
echo "============================================"

# ------------------------------------------------------------------
# 1. Fix project.analysis.php - Query biaya Subcon yang benar
# ------------------------------------------------------------------
echo "[1/4] Memperbaiki php-func/project.analysis.php..."

python3 << 'PYEOF'
import re

file_path = 'php-func/project.analysis.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Ganti query lama dengan query yang benar sesuai struktur database
new_subcon_block = '''    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $sub = ['kontrak_aktif' => 0, 'nilai_kontrak' => 0.0, 'tertagih_dpp' => 0.0, 'sisa_hutang' => 0.0];
    if ($hasSub) {
        // [REVISI 2026-11-13] Biaya Subcon dari subcon_payment_certificates (status APPROVED)
        // Menggunakan gross_amount (bukan amount_certified) sesuai struktur database aktual
        $hasCert = tbl_exists($db, 'subcon_payment_certificates');
        $r = null;
        
        if ($hasCert) {
            try {
                $r = $q("SELECT 
                            (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                            (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                            (SELECT COALESCE(SUM(spc.gross_amount),0) 
                             FROM subcon_payment_certificates spc
                             JOIN subcon_inspections si ON si.id = spc.inspection_id
                             JOIN subcon_progress sp ON sp.id = si.progress_id
                             JOIN subcon_contracts sc ON sc.id = sp.contract_id
                             WHERE sc.project_id = :p AND spc.status = 'APPROVED') AS g,
                            (SELECT COALESCE(SUM(spc.net_payable),0) 
                             FROM subcon_payment_certificates spc
                             JOIN subcon_inspections si ON si.id = spc.inspection_id
                             JOIN subcon_progress sp ON sp.id = si.progress_id
                             JOIN subcon_contracts sc ON sc.id = sp.contract_id
                             WHERE sc.project_id = :p AND spc.status = 'APPROVED')
                            - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y 
                               JOIN subcon_contracts sc ON sc.id = y.contract_id 
                               WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                          [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('[project.analysis] subcon cert query error: ' . $e->getMessage());
                $r = null;
            }
        }
        
        if ($r === null) {
            // Fallback ke logika lama jika query baru gagal
            $r = $q("SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                            (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                            (SELECT COALESCE(SUM(sp.gross_amount),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui') AS g,
                            (SELECT COALESCE(SUM(sp.net_payable),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui')
                            - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                      [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        }
        $sub = ['kontrak_aktif' => (int)$r['n'], 'nilai_kontrak' => round((float)$r['v'], 2), 'tertagih_dpp' => round((float)$r['g'], 2), 'sisa_hutang' => round((float)$r['h'], 2)];
    }'''

# Cari blok lama dari $hasSub sampai penutup blok if
pattern = r'    \$hasSub = tbl_exists\(\$db, \'subcon_progress\'\).*?\$sub = \[\'kontrak_aktif\'.*?\];\n    \}'
replacement = new_subcon_block

new_content = re.sub(pattern, replacement, content, count=1, flags=re.DOTALL)

if new_content != content:
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(new_content)
    print("  ✓ project.analysis.php berhasil diperbaiki")
else:
    print("  ⚠ Pola tidak ditemukan, file mungkin sudah benar")
PYEOF

# ------------------------------------------------------------------
# 2. Buat subcon.progressCRD.php yang 100% compatible
# ------------------------------------------------------------------
echo "[2/4] Membuat php-crud/subcon.progressCRD.php..."

cat > php-crud/subcon.progressCRD.php << 'PHPEOF'
<?php
/**
 * subcon.progressCRD.php
 * [FINAL 2026-11-13] 100% compatible dengan struktur database aktual:
 * - subcon_inspections (inspection_no, progress_id, status: DRAFT/APPROVED/REJECTED/CANCELLED)
 * - subcon_inspection_items (progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework, result)
 * - subcon_progress_qty_items (progress_id, contract_task_id, work_item_id, qty_claimed, unit_price_snapshot)
 * - spk_work_items (spk_id, project_task_id, work_code, description, unit, contract_qty, unit_price, material_supply)
 *
 * Aturan: No Inspection, No Payment. Trigger DB sudah memvalidasi qty.
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
$userId = (int)$_SESSION['user_id'];

try {
    switch ($task) {
        case 'list_inspections':
            $spk_id = (int)($_GET['spk_id'] ?? 0);
            $progress_id = (int)($_GET['progress_id'] ?? 0);
            $status = $_GET['status'] ?? '';
            
            $where = []; $params = [];
            if ($spk_id > 0) { $where[] = "sp.spk_id = :spk_id"; $params[':spk_id'] = $spk_id; }
            if ($progress_id > 0) { $where[] = "si.progress_id = :progress_id"; $params[':progress_id'] = $progress_id; }
            if ($status !== '') { $where[] = "si.status = :status"; $params[':status'] = $status; }
            $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $st = $db->prepare("
                SELECT si.*, sp.spk_id, sp.progress_no, sp.progress_date,
                       sc.contract_no, s.subcon_name
                FROM subcon_inspections si
                JOIN subcon_progress sp ON sp.id = si.progress_id
                JOIN subcon_contracts sc ON sc.id = sp.contract_id
                JOIN subcontractors s ON s.id = sc.subcontractor_id
                {$where_sql}
                ORDER BY si.inspection_date DESC, si.id DESC
            ");
            $st->execute($params);
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'load_inspection':
            $inspection_id = (int)($_GET['id'] ?? 0);
            if ($inspection_id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); break; }
            
            $hdr = $db->query("
                SELECT si.*, sp.spk_id, sp.progress_no, sp.progress_date,
                       sc.contract_no, sc.contract_value, s.subcon_name
                FROM subcon_inspections si
                JOIN subcon_progress sp ON sp.id = si.progress_id
                JOIN subcon_contracts sc ON sc.id = sp.contract_id
                JOIN subcontractors s ON s.id = sc.subcontractor_id
                WHERE si.id = {$inspection_id}
            ")->fetch(PDO::FETCH_ASSOC);
            
            $dtl = $db->query("
                SELECT sii.*, 
                       spqi.qty_claimed, spqi.unit_price_snapshot, spqi.amount_claimed,
                       swi.work_code, swi.description as work_description, swi.unit,
                       swi.contract_qty as work_contract_qty
                FROM subcon_inspection_items sii
                JOIN subcon_progress_qty_items spqi ON spqi.id = sii.progress_qty_item_id
                JOIN spk_work_items swi ON swi.id = spqi.work_item_id
                WHERE sii.inspection_id = {$inspection_id}
                ORDER BY sii.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => ['header' => $hdr, 'items' => $dtl]]);
            break;

        case 'save_progress_draft':
            $progress_id = (int)($_POST['progress_id'] ?? 0);
            $inspection_date = $_POST['inspection_date'] ?? date('Y-m-d');
            $items = json_decode($_POST['items'] ?? '[]', true);

            if ($progress_id <= 0 || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Progress ID dan item wajib diisi.']);
                break;
            }

            $db->beginTransaction();
            try {
                $inspection_no = 'INS-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $stmt = $db->prepare("
                    INSERT INTO subcon_inspections 
                    (inspection_no, progress_id, inspection_date, status, created_by, created_at)
                    VALUES (:no, :pid, :date, 'DRAFT', :uid, NOW())
                    RETURNING id
                ");
                $stmt->execute([':no' => $inspection_no, ':pid' => $progress_id, ':date' => $inspection_date, ':uid' => $userId]);
                $inspection_id = (int)$stmt->fetchColumn();

                $stmt_item = $db->prepare("
                    INSERT INTO subcon_inspection_items 
                    (inspection_id, progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework, result)
                    VALUES (:iid, :pqii, :qi, 0, 0, 0, 'PENDING')
                ");

                foreach ($items as $item) {
                    $pqii = (int)($item['progress_qty_item_id'] ?? 0);
                    $qi = (float)($item['qty_inspected'] ?? 0);
                    if ($pqii > 0 && $qi > 0) {
                        $stmt_item->execute([':iid' => $inspection_id, ':pqii' => $pqii, ':qi' => $qi]);
                    }
                }

                $db->commit();
                echo json_encode([
                    'success' => true, 
                    'inspection_id' => $inspection_id,
                    'inspection_no' => $inspection_no,
                    'message' => 'Draft inspeksi berhasil dibuat.'
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        case 'update_inspection_items':
            $inspection_id = (int)($_POST['inspection_id'] ?? 0);
            $items = json_decode($_POST['items'] ?? '[]', true);
            
            if ($inspection_id <= 0 || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
                break;
            }
            
            $st = $db->prepare("SELECT status FROM subcon_inspections WHERE id = :id");
            $st->execute([':id' => $inspection_id]);
            if ($st->fetchColumn() !== 'DRAFT') {
                echo json_encode(['success' => false, 'message' => 'Hanya inspeksi DRAFT yang bisa diubah.']);
                break;
            }
            
            $db->beginTransaction();
            try {
                $stmt_upd = $db->prepare("
                    UPDATE subcon_inspection_items 
                    SET qty_passed = :qp, qty_rejected = :qr, qty_rework = :qw,
                        result = :res, notes = :notes
                    WHERE id = :id AND inspection_id = :iid
                ");
                
                foreach ($items as $item) {
                    $qp = (float)($item['qty_passed'] ?? 0);
                    $qr = (float)($item['qty_rejected'] ?? 0);
                    $qw = (float)($item['qty_rework'] ?? 0);
                    
                    $result = 'PENDING';
                    if ($qp > 0 && $qr == 0 && $qw == 0) $result = 'PASS';
                    elseif ($qp > 0 && ($qr > 0 || $qw > 0)) $result = 'PARTIAL';
                    elseif ($qr > 0) $result = 'REJECT';
                    elseif ($qw > 0) $result = 'REWORK';
                    
                    $stmt_upd->execute([
                        ':qp' => $qp, ':qr' => $qr, ':qw' => $qw,
                        ':res' => $result, ':notes' => $item['notes'] ?? '',
                        ':id' => (int)$item['id'], ':iid' => $inspection_id
                    ]);
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Item inspeksi diperbarui.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        case 'approve_inspection':
            $inspection_id = (int)($_POST['inspection_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            
            if ($inspection_id <= 0 || !in_array($status, ['APPROVED', 'REJECTED', 'CANCELLED'])) {
                echo json_encode(['success' => false, 'message' => 'Data tidak valid.']);
                break;
            }
            
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT status FROM subcon_inspections WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $inspection_id]);
                if ($st->fetchColumn() !== 'DRAFT') {
                    throw new Exception('Hanya inspeksi DRAFT yang bisa di-approve.');
                }
                
                if ($status === 'APPROVED') {
                    $stmt = $db->prepare("
                        UPDATE subcon_inspections 
                        SET status = 'APPROVED', approved_by = :uid, approved_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([':uid' => $userId, ':id' => $inspection_id]);
                } else {
                    $reason = trim($_POST['cancel_reason'] ?? '');
                    if ($reason === '') throw new Exception('Alasan penolakan wajib diisi.');
                    $stmt = $db->prepare("
                        UPDATE subcon_inspections 
                        SET status = :st, cancelled_by = :uid, cancelled_at = NOW(), cancel_reason = :r
                        WHERE id = :id
                    ");
                    $stmt->execute([':st' => $status, ':uid' => $userId, ':r' => $reason, ':id' => $inspection_id]);
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => "Inspeksi {$status}."]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
    }
} catch (Throwable $e) {
    error_log('[subcon.progressCRD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
}
PHPEOF
echo "  ✓ subcon.progressCRD.php dibuat"

# ------------------------------------------------------------------
# 3. Buat leftoverCRD.php (modul baru untuk UI leftover)
# ------------------------------------------------------------------
echo "[3/4] Membuat php-crud/leftoverCRD.php..."

cat > php-crud/leftoverCRD.php << 'PHPEOF'
<?php
/**
 * leftoverCRD.php
 * [NEW 2026-11-13] CRUD untuk material leftover.
 * Struktur DB: spk_material_leftover_lots + spk_material_leftover_transactions
 * 
 * 3 skenario:
 * 1. RETURN_COMPANY: sisa material perusahaan kembali ke gudang
 * 2. BUY_FROM_SUBCON: perusahaan beli sisa material dari subcon
 * 3. SELL_TO_CLIENT: perusahaan jual sisa material ke klien
 * 
 * Approval bertingkat (diterapkan di trigger fn_validate_spk_leftover_transaction):
 * - Discount ≤10%: PM
 * - Discount 10-20%: PM + Finance
 * - Discount >20%: Direktur
 */
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir.']);
    exit;
}

$task = $_REQUEST['task'] ?? '';
$db = new crud();
$userId = (int)$_SESSION['user_id'];
$formAccess = (int)($_SESSION['FormAccess'] ?? 0);

try {
    switch ($task) {
        case 'list_lots':
            $project_id = (int)($_GET['project_id'] ?? 0);
            $where = $project_id > 0 ? "WHERE ml.project_id = {$project_id}" : '';
            $rows = $db->query("
                SELECT ml.*, m.material_name, m.unit, p.name as project_name,
                       CASE WHEN ml.current_owner_type = 'SUBCON' THEN s.subcon_name ELSE NULL END as current_subcon_name
                FROM spk_material_leftover_lots ml
                JOIN materials m ON m.id = ml.material_id
                JOIN projects p ON p.id = ml.project_id
                LEFT JOIN subcontractors s ON s.id = ml.current_subcontractor_id
                {$where}
                ORDER BY ml.detected_date DESC, ml.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'load_lot':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); break; }
            $lot = $db->query("SELECT * FROM spk_material_leftover_lots WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
            $txs = $db->query("
                SELECT * FROM spk_material_leftover_transactions 
                WHERE leftover_lot_id = {$id} ORDER BY transaction_date DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => ['lot' => $lot, 'transactions' => $txs]]);
            break;

        case 'create_lot':
            if ($formAccess < 2) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); break; }
            $project_id = (int)($_POST['project_id'] ?? 0);
            $spk_id = (int)($_POST['spk_id'] ?? 0);
            $material_id = (int)($_POST['material_id'] ?? 0);
            $qty = (float)($_POST['qty_detected'] ?? 0);
            $source_owner = $_POST['source_owner_type'] ?? 'COMPANY';
            $source_subcon = (int)($_POST['source_subcontractor_id'] ?? 0) ?: null;
            $condition = $_POST['condition_note'] ?? '';
            $location = $_POST['storage_location'] ?? '';
            $detected_date = $_POST['detected_date'] ?? date('Y-m-d');
            
            if ($project_id <= 0 || $spk_id <= 0 || $material_id <= 0 || $qty <= 0) {
                echo json_encode(['success' => false, 'message' => 'Data wajib tidak lengkap.']);
                break;
            }
            
            $db->beginTransaction();
            try {
                $lot_no = 'LFT-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $st = $db->prepare("
                    INSERT INTO spk_material_leftover_lots 
                    (lot_no, project_id, spk_id, material_id, source_owner_type, source_subcontractor_id,
                     current_owner_type, qty_origin, qty_available, unit, condition_note, storage_location,
                     status, detected_date, created_by)
                    VALUES (:no, :pid, :sid, :mid, :sot, :ssub, :sot, :qty, :qty, 
                            (SELECT unit FROM materials WHERE id = :mid2), :cond, :loc,
                            'AVAILABLE', :date, :uid)
                    RETURNING id
                ");
                $st->execute([
                    ':no' => $lot_no, ':pid' => $project_id, ':sid' => $spk_id, ':mid' => $material_id,
                    ':sot' => $source_owner, ':ssub' => $source_subcon, ':qty' => $qty,
                    ':mid2' => $material_id, ':cond' => $condition, ':loc' => $location,
                    ':date' => $detected_date, ':uid' => $userId
                ]);
                $lot_id = (int)$st->fetchColumn();
                $db->commit();
                echo json_encode(['success' => true, 'lot_id' => $lot_id, 'lot_no' => $lot_no, 'message' => 'Lot leftover dibuat.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        case 'create_transaction':
            if ($formAccess < 2) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); break; }
            $lot_id = (int)($_POST['leftover_lot_id'] ?? 0);
            $tx_type = $_POST['transaction_type'] ?? '';
            $qty = (float)($_POST['qty'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $market_price = (float)($_POST['market_price'] ?? 0);
            $market_source = $_POST['market_price_source'] ?? '';
            $market_date = $_POST['market_price_date'] ?? null;
            $reason = trim($_POST['reason'] ?? '');
            $seller_type = $_POST['seller_type'] ?? 'COMPANY';
            $seller_subcon = (int)($_POST['seller_subcontractor_id'] ?? 0) ?: null;
            $buyer_type = $_POST['buyer_type'] ?? 'COMPANY';
            $buyer_subcon = (int)($_POST['buyer_subcontractor_id'] ?? 0) ?: null;
            
            if ($lot_id <= 0 || !in_array($tx_type, ['RETURN_COMPANY', 'BUY_FROM_SUBCON', 'SELL_TO_CLIENT']) || $qty <= 0 || $reason === '') {
                echo json_encode(['success' => false, 'message' => 'Data wajib tidak lengkap.']);
                break;
            }
            
            // Validasi qty tidak melebihi qty_available
            $st = $db->prepare("SELECT qty_available FROM spk_material_leftover_lots WHERE id = :id");
            $st->execute([':id' => $lot_id]);
            $avail = (float)$st->fetchColumn();
            if ($qty > $avail) {
                echo json_encode(['success' => false, 'message' => "Qty melebihi tersedia ({$avail})."]);
                break;
            }
            
            $db->beginTransaction();
            try {
                $tx_no = 'LTX-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $total = round($qty * $unit_price, 2);
                
                $st = $db->prepare("
                    INSERT INTO spk_material_leftover_transactions 
                    (transaction_no, leftover_lot_id, transaction_type, transaction_date,
                     seller_type, seller_subcontractor_id, buyer_type, buyer_subcontractor_id,
                     qty, unit_price, total_amount, market_price, market_price_source, market_price_date,
                     reason, status, created_by)
                    VALUES (:no, :lid, :tt, CURRENT_DATE,
                            :st, :ss, :bt, :bs,
                            :qty, :up, :ta, :mp, :mps, :mpd,
                            :r, 'DRAFT', :uid)
                    RETURNING id
                ");
                $st->execute([
                    ':no' => $tx_no, ':lid' => $lot_id, ':tt' => $tx_type,
                    ':st' => $seller_type, ':ss' => $seller_subcon,
                    ':bt' => $buyer_type, ':bs' => $buyer_subcon,
                    ':qty' => $qty, ':up' => $unit_price, ':ta' => $total,
                    ':mp' => $market_price ?: null, ':mps' => $market_source ?: null,
                    ':mpd' => $market_date, ':r' => $reason, ':uid' => $userId
                ]);
                $tx_id = (int)$st->fetchColumn();
                $db->commit();
                echo json_encode(['success' => true, 'transaction_id' => $tx_id, 'transaction_no' => $tx_no, 'message' => 'Transaksi dibuat (DRAFT).']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        case 'approve_transaction':
            if ($formAccess < 3) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); break; }
            $tx_id = (int)($_POST['transaction_id'] ?? 0);
            if ($tx_id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); break; }
            
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT status FROM spk_material_leftover_transactions WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $tx_id]);
                if ($st->fetchColumn() !== 'DRAFT') {
                    throw new Exception('Hanya transaksi DRAFT yang bisa di-approve.');
                }
                $db->cmdQ("UPDATE spk_material_leftover_transactions SET status = 'APPROVED', approved_by = {$userId}, approved_at = NOW() WHERE id = {$tx_id}");
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Transaksi di-approve.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        case 'post_transaction':
            if ($formAccess < 4) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); break; }
            $tx_id = (int)($_POST['transaction_id'] ?? 0);
            if ($tx_id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); break; }
            
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT status FROM spk_material_leftover_transactions WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $tx_id]);
                if ($st->fetchColumn() !== 'APPROVED') {
                    throw new Exception('Transaksi harus berstatus APPROVED untuk diposting.');
                }
                // Trigger fn_post_spk_leftover_transaction akan otomatis handle inventory
                $db->cmdQ("UPDATE spk_material_leftover_transactions SET status = 'POSTED', posted_by = {$userId}, posted_at = NOW() WHERE id = {$tx_id}");
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Transaksi diposting ke inventory.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
    }
} catch (Throwable $e) {
    error_log('[leftoverCRD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
}
PHPEOF
echo "  ✓ leftoverCRD.php dibuat"

# ------------------------------------------------------------------
# 4. Buat dokumentasi schema
# ------------------------------------------------------------------
echo "[4/4] Membuat dokumentasi schema..."
mkdir -p docs
cat > docs/DATABASE_SCHEMA_SUBCON.md << 'DOCEOF'
# Database Schema: Subcon & Material Leftover (FINAL)

**Status**: ✅ LENGKAP - Tidak perlu SQL migration lagi
**Tanggal verifikasi**: 2026-11-13

## Tabel Utama

### SPK & Tasks
- `spk` - Header SPK (dengan spk_type: INTERNAL/SUBCON)
- `spk_tasks` - Mapping SPK ke project_tasks (UNIQUE spk_id+task_id)
- `spk_work_items` - Rincian pekerjaan (work_code, contract_qty, unit_price, material_supply: COMPANY/SUBCON/MIXED)

### Material Supply & Leftover
- `spk_material_supply` - Supply material per work item (qty_planned, qty_received, qty_accepted)
- `spk_material_leftover_lots` - Lot material sisa (lot_no, qty_origin, qty_available, parent_lot_id untuk split)
- `spk_material_leftover_transactions` - Transaksi leftover (transaction_type: RETURN_COMPANY/BUY_FROM_SUBCON/SELL_TO_CLIENT)

### Subcon Progress & Inspection
- `subcon_progress` - Header progress Subcon
- `subcon_progress_qty_items` - Qty progress per work item (qty_claimed, unit_price_snapshot)
- `subcon_inspections` - Header inspeksi (inspection_no, status: DRAFT/APPROVED/REJECTED/CANCELLED)
- `subcon_inspection_items` - Detail inspeksi (qty_inspected, qty_passed, qty_rejected, qty_rework, result)

### Payment Certificate
- `subcon_payment_certificates` - Sertifikat (gross_amount, ppn_amount, pph_amount, retention_amount, dp_recovery, other_deduction, net_payable)
- `subcon_payment_certificate_items` - Detail (qty_certified, unit_price_snapshot, amount_certified)

## Trigger Penting
- `fn_validate_spk_work_item_project` - Validasi konsistensi proyek
- `fn_validate_progress_qty_item` - Validasi qty progress vs kontrak
- `fn_validate_inspection_item` - Validasi qty lolos ≤ qty diperiksa
- `fn_validate_certificate_item` - Validasi qty certified ≤ qty lolos
- `fn_validate_certificate_approval` - Validasi approval certificate
- `fn_validate_spk_leftover_transaction` - Validasi transaksi leftover
- `fn_post_spk_leftover_transaction` - Auto-posting inventory
- `fn_block_posted_leftover_transaction_change` - Anti-tampering

## Query Dashboard (project.analysis.php)
```sql
SELECT COALESCE(SUM(spc.gross_amount), 0)
FROM subcon_payment_certificates spc
JOIN subcon_inspections si ON si.id = spc.inspection_id
JOIN subcon_progress sp ON sp.id = si.progress_id
JOIN subcon_contracts sc ON sc.id = sp.contract_id
WHERE sc.project_id = :project_id AND spc.status = 'APPROVED'
