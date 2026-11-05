<?php
/**
 * Document      : m.taxexchangerateCRD.php
 * Description   : CRUD Backend Controller for Master Kurs Pajak Kemenkeu
 */
set_time_limit(0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');

// Validasi login dan hak akses form EXCHANGE RATE.
require_once __DIR__ . '/../php-func/user.valid.php';

// PDO untuk operasi data kurs.
require_once __DIR__ . '/db_connection.PHP';

$formAccess = (int)($_SESSION['FormAccess'] ?? 0);

function taxExchangeRequireAccess($minimum, $message)
{
    $currentAccess = (int)($_SESSION['FormAccess'] ?? 0);

    if ($currentAccess < $minimum) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}

$task   = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
$start  = intval($_POST['start'] ?? $_REQUEST['start'] ?? 0);
$limit  = intval($_POST['limit'] ?? $_REQUEST['limit'] ?? 250);
$id     = intval($_POST['id'] ?? $_REQUEST['id'] ?? 0);

switch ($task) {

    // ------------------------------------------------------------
    // 1. READ HEADER GRID (Daftar Periode KMK)
    // ------------------------------------------------------------
    case 'read':
        try {
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM tax_exchange_rate_hdr");
            $totalCount = $stmtCount->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT id, kmk_no, start_date, end_date, file_url, remark, created_at
                FROM tax_exchange_rate_hdr
                ORDER BY start_date DESC, id DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'totalRecord' => $totalCount,
                'data' => $data
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 2. READ DETAIL GRID (Rincian Kurs per Mata Uang)
    // ------------------------------------------------------------
    case 'read_detail':
        try {
            $hdrId = intval($_POST['hdr_id'] ?? $_REQUEST['hdr_id'] ?? 0);
            if ($hdrId <= 0) {
                // Default ke header terbaru jika tidak spesifik
                $hdrId = $pdo->query("SELECT id FROM tax_exchange_rate_hdr ORDER BY start_date DESC LIMIT 1")->fetchColumn();
            }

            if (!$hdrId) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    d.id, d.hdr_id, d.currency_id, d.currency_code, d.currency_name, 
                    d.unit_multiplier, d.rate AS rate_kmk, d.rate_per_unit AS rate_effective,
                    d.prev_rate, d.change_amount, d.change_direction
                FROM tax_exchange_rate_dtl d
                WHERE d.hdr_id = :hdr_id
                ORDER BY d.currency_code ASC
            ");
            $stmt->execute(['hdr_id' => $hdrId]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'hdr_id' => $hdrId,
                'data' => $data
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 3. FETCH NOW (Tarik Otomatis dari Kemenkeu)
    // ------------------------------------------------------------
    case 'fetch_now':
    case 'sync':
        taxExchangeRequireAccess(
            3,
            'Anda tidak memiliki hak untuk memperbarui Kurs Pajak.'
        );
        try {
            require_once __DIR__ . '/../cron/fetch_kurs_pajak.php';
            $res = fetchAndStoreTaxExchangeRates($pdo);

            if ($res['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => "Berhasil memperbarui Kurs Pajak! KMK: {$res['kmk_no']} ({$res['period']}) - Total {$res['items_count']} mata uang."
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $res['message']
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => "Error sync: " . $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 4. DELETE RECORD
    // ------------------------------------------------------------
    case 'delete':
        taxExchangeRequireAccess(
            4,
            'Anda tidak memiliki hak untuk menghapus Kurs Pajak.'
        );
        try {
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID data tidak valid.']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM tax_exchange_rate_hdr WHERE id = :id");
            $stmt->execute(['id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Data Kurs Pajak berhasil dihapus.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
        break;
}
