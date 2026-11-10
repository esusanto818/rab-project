<?php
/**
 * project.usageCRD.php
 * ============================================================
 * Backend perbandingan PEMAKAIAN MATERIAL vs PROGRES proyek (read-only).
 * Sumber: view v_project_material_vs_progress (per proyek) dan v_project_material_detail_vs_progress (per material).
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'], respons JSON {success, message, data}.
 *
 * Definisi:
 *   % pemakaian = nilai issue proyek (posted, FIFO) / nilai RAB material x 100
 *   % progres   = projects.progress (progres terverifikasi)
 *   selisih     = % pemakaian - % progres  (poin)
 *   flag        = NORMAL (<= 10) | WASPADA (> 10) | KRITIS (> 20) | TANPA RAB
 *
 * Task: summary (opsional: only_active=1 untuk menyembunyikan proyek Selesai) | detail (project_id)
 * Butuh migrasi: sql/2026-10-19.spk_usage_ppn.sql
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

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
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) sendJsonResponse(false, [], 'Hak baca laporan pemakaian proyek tidak tersedia.');

try {
    switch ($task) {

        case 'summary':
            $onlyActive = ((int)($_REQUEST['only_active'] ?? 0) === 1);
            $sql = "SELECT project_id, project_name, client_name, project_status, progress_pct, rab_value, used_value, usage_pct, gap_pts, flag
                      FROM public.v_project_material_vs_progress " .
                   ($onlyActive ? "WHERE project_status <> 'Selesai' " : '') .
                   "ORDER BY (flag = 'KRITIS') DESC, (flag = 'WASPADA') DESC, gap_pts DESC NULLS LAST, project_name";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $cnt = ['KRITIS' => 0, 'WASPADA' => 0, 'NORMAL' => 0, 'TANPA RAB' => 0];
            foreach ($rows as &$r) {
                $r['project_id'] = (int)$r['project_id'];
                foreach (['progress_pct', 'rab_value', 'used_value'] as $k) { $r[$k] = (float)$r[$k]; }
                $r['usage_pct'] = $r['usage_pct'] === null ? null : (float)$r['usage_pct'];
                $r['gap_pts'] = $r['gap_pts'] === null ? null : (float)$r['gap_pts'];
                if (isset($cnt[$r['flag']])) { $cnt[$r['flag']]++; }
            }
            unset($r);
            sendJsonResponse(true, ['rows' => $rows, 'counts' => $cnt]);
            break;

        case 'detail':
            $pid = (int)($_REQUEST['project_id'] ?? 0);
            if ($pid <= 0) sendJsonResponse(false, [], 'Proyek tidak valid.');
            $st = $db->prepare("SELECT material_code, material_name, unit, rab_qty, used_qty, usage_pct, progress_pct, gap_pts, rab_value, used_value
                                  FROM public.v_project_material_detail_vs_progress WHERE project_id = :p
                                 ORDER BY gap_pts DESC NULLS LAST, material_name");
            $st->execute([':p' => $pid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                foreach (['rab_qty', 'used_qty', 'progress_pct', 'rab_value', 'used_value'] as $k) { $r[$k] = (float)$r[$k]; }
                $r['usage_pct'] = $r['usage_pct'] === null ? null : (float)$r['usage_pct'];
                $r['gap_pts'] = $r['gap_pts'] === null ? null : (float)$r['gap_pts'];
                // tanda per material memakai ambang yang sama dengan view proyek
                $g = $r['gap_pts'];
                $r['flag'] = ($g === null) ? 'TANPA RAB' : ($g > 20 ? 'KRITIS' : ($g > 10 ? 'WASPADA' : 'NORMAL'));
            }
            unset($r);
            sendJsonResponse(true, $rows);
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[project.usageCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[project.usageCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}