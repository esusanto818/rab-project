<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * purchase.planningCRD.php
 * ============================================================
 * Laporan Purchase Planning: untuk tiap proyek, bandingkan Budget RAB
 * (budget_items, category 'Material') vs yang SUDAH di-PO-kan
 * (po_items.budget_item_id -> budget_items.id, tidak termasuk PO 'Dibatalkan'),
 * supaya kelihatan sisa material yang masih perlu dibeli.
 *
 * PERBAIKAN dari versi sebelumnya:
 * - [FIX FATAL] Query memakai rab_materials & m_materials yang SUDAH TIDAK ADA
 *   (dihapus saat konsolidasi ke materials/budget_items) ->
 *   'relation "rab_materials" does not exist'. Ditulis ulang memakai
 *   budget_items + materials. Jembatan lama (m_materials.material_code ->
 *   materials -> budget_items) tidak diperlukan lagi.
 * - Kontrak JSON dipertahankan PERSIS (rab_material_id, budget_qty, unit_price,
 *   qty_ordered, qty_remaining, remaining_value, dst) -- purchase.planning.js
 *   tidak perlu diubah. rab_material_id kini = budget_items.id, sehingga
 *   budget_item_id yang dikirim ke halaman PO langsung valid.
 *
 * TIDAK menulis apa pun ke PO -- murni laporan. Tombol "Buat PO dari
 * Terpilih" di frontend hanya membawa data terpilih ke halaman PO lewat
 * sessionStorage; PO-nya sendiri baru dibuat di sana.
 *
 * File related : - purchase.planning.php
 *                - purchase.planning.js
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';

// Otorisasi backend mandiri: login wajib dan hak operasi mengikuti FormAccess menu.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
$requiredAccess = 1;
if (in_array($task, ['create', 'add', 'save'], true)) $requiredAccess = 2;
if (in_array($task, ['update', 'edit'], true)) $requiredAccess = 3;
if (in_array($task, ['delete'], true)) $requiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $requiredAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi ini.']);
    exit;
}

function PgOrFail($result, $context = '') {
    if ($result === false) {
        throw new Exception(($context ? "[{$context}] " : '') . pg_last_error());
    }
    return $result;
}

// Qty yang sudah di-PO-kan per baris RAB (PO Dibatalkan tidak dihitung).
// Dipakai di dua task supaya hitungannya selalu konsisten.
const PLAN_PO_SUM = "
    SELECT poi.budget_item_id, SUM(poi.qty) AS qty_ordered
      FROM po_items poi
      JOIN purchase_orders po ON po.id = poi.po_id
     WHERE po.status <> 'Dibatalkan' AND poi.budget_item_id IS NOT NULL
     GROUP BY poi.budget_item_id
";

try {
    switch ($task) {

        // ==========================================================
        // LIST PROJECTS -- Grid Utama (Proyek + Ringkasan Sisa Beli)
        // ==========================================================
        case 'list_projects':
            $searchQuery = trim($_REQUEST['query'] ?? '');
            $searchWhere = '';
            $searchParams = [];
            if ($searchQuery !== '') {
                $searchWhere = " WHERE p.name ILIKE $1";
                $searchParams[] = '%' . $searchQuery . '%';
            }

            $sql = "SELECT p.id, p.name,
                           COALESCE(agg.total_rab_count, 0) AS total_rab_count,
                           COALESCE(agg.material_count, 0) AS material_count,
                           COALESCE(agg.total_remaining_value, 0) AS total_remaining_value
                    FROM public.projects p
                    LEFT JOIN (
                        SELECT bi.project_id,
                               COUNT(*) AS total_rab_count,
                               COUNT(*) FILTER (WHERE (bi.qty_plan - COALESCE(po_sum.qty_ordered, 0)) > 0.0001) AS material_count,
                               SUM(GREATEST(bi.qty_plan - COALESCE(po_sum.qty_ordered, 0), 0) * COALESCE(bi.unit_price_plan, 0)) AS total_remaining_value
                          FROM public.budget_items bi
                          JOIN public.materials m ON m.id = bi.material_id
                          LEFT JOIN (" . PLAN_PO_SUM . ") po_sum ON po_sum.budget_item_id = bi.id
                         WHERE bi.category = 'Material' AND COALESCE(bi.qty_plan, 0) > 0
                         GROUP BY bi.project_id
                    ) agg ON agg.project_id = p.id
                    {$searchWhere}
                    ORDER BY p.created_at DESC, p.id DESC";
            $result = PgOrFail(pg_query_params($sql, $searchParams), 'list_projects');
            $rows = pg_fetch_all($result) ?: [];
            echo json_encode(['success' => true, 'data' => $rows, 'totalCount' => count($rows)]);
            break;

        // ==========================================================
        // LOAD PLANNING -- Detail Sisa Beli per Material utk 1 Proyek
        // ==========================================================
        case 'load_planning':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) throw new Exception('Proyek belum dipilih');

            $sql = "SELECT bi.id AS rab_material_id, bi.material_id, m.material_code, m.material_name,
                           COALESCE(NULLIF(bi.unit, ''), m.unit, '') AS unit,
                           bi.qty_plan AS budget_qty,
                           COALESCE(bi.unit_price_plan, 0) AS unit_price,
                           COALESCE(po_sum.qty_ordered, 0) AS qty_ordered,
                           GREATEST(bi.qty_plan - COALESCE(po_sum.qty_ordered, 0), 0) AS qty_remaining,
                           (GREATEST(bi.qty_plan - COALESCE(po_sum.qty_ordered, 0), 0) * COALESCE(bi.unit_price_plan, 0)) AS remaining_value
                      FROM public.budget_items bi
                      JOIN public.materials m ON m.id = bi.material_id
                      LEFT JOIN (" . PLAN_PO_SUM . ") po_sum ON po_sum.budget_item_id = bi.id
                     WHERE bi.project_id = $1 AND bi.category = 'Material' AND COALESCE(bi.qty_plan, 0) > 0
                     ORDER BY m.material_name";
            $result = PgOrFail(pg_query_params($sql, [$projectId]), 'load_planning');
            echo json_encode(['success' => true, 'data' => pg_fetch_all($result) ?: []]);
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}