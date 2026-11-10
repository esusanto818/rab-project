<?php
/**
 * inventory.reconCRD.php
 * ============================================================
 * Backend Rekonsiliasi Persediaan (inventory.recon.js). Read-only.
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'], respons JSON.
 * Logika perhitungan ada di php-func/function.inventory.recon.php (dipakai juga oleh pdf/inventoryrecon.php).
 *
 * Task: list_accounts | reconcile (as_of=YYYY-MM-DD, account_id) | fix_missing_journals (as_of, account_id; akses >= 3)
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../php-func/function.inventory.recon.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

function sendJsonResponse($success, $data = [], $message = '') {
    $response = ['success' => $success, 'message' => $message];
    if ($success) { $response['data'] = $data; }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); sendJsonResponse(false, [], 'Hak baca rekonsiliasi persediaan tidak tersedia.'); }

try {
    switch ($task) {
        case 'list_accounts':
            sendJsonResponse(true, ir_accounts($db));
            break;

        case 'reconcile':
            $asOf = (string)($_REQUEST['as_of'] ?? date('Y-m-d'));
            $accId = (int)($_REQUEST['account_id'] ?? 0);
            $res = ir_reconcile($db, $asOf, $accId);
            unset($res['fixes']);          // daftar internal untuk perbaikan, tidak perlu dikirim ke klien
            sendJsonResponse(true, $res);
            break;

        /* [PERBAIKAN] satu tombol: posting jurnal yang kurang untuk dokumen stok yang belum berjurnal */
        case 'fix_missing_journals':
            if (intval($_SESSION['FormAccess'] ?? 0) < 4) { http_response_code(403); sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk memproses perbaikan.'); }
            $asOf = (string)($_POST['as_of'] ?? date('Y-m-d'));
            $accId = (int)($_POST['account_id'] ?? 0);
            $fx = ir_fix_missing($db, $asOf, $accId);
            sendJsonResponse(true, $fx, $fx['count'] > 0
                ? ($fx['count'] . ' jurnal diposting untuk: ' . implode(', ', $fx['posted']) . '.')
                : 'Tidak ada jurnal yang perlu diposting.');
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[inventory.reconCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[inventory.reconCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], $e->getMessage());
}