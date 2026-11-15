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

// [SECURITY PATCH] Otorisasi operasi per task. FormAccess (menu access) saja
// TIDAK cukup -- setiap aksi dicek levelnya: list/read=1, simpan draft=2,
// ubah item/approve=3. (Pola sama dengan modul subkon lainnya.)
$scRequiredAccess = array(
    'list_inspections'     => 1,
    'load_inspection'      => 1,
    'save_progress_draft'  => 2,
    'update_inspection_items' => 3,
    'approve_inspection'   => 3,
);
if (isset($scRequiredAccess[$task]) && (int)($_SESSION['FormAccess'] ?? 0) < $scRequiredAccess[$task]) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi inspeksi ini.']);
    exit;
}

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
