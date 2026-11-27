<?php
// [SEC-01 PATCH] Validasi sesi & otorisasi menu terpusat
require_once __DIR__ . '/../php-func/user.valid.php';
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
