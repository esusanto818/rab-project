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
 * [PENGECEKAN INVENTORY 2026-11-15]
 * - load_planning kini juga mengembalikan qty_on_hand (saldo stok yang
 *   DAPAT DIPAKAI proyek ini: gudang milik proyek + gudang bersama
 *   project_id IS NULL) dan qty_to_buy = GREATEST(qty_remaining -
 *   qty_on_hand, 0). Material yang stoknya sudah mencukupi kebutuhan
 *   sisa tidak perlu dibeli lagi; stok milik PROYEK LAIN tidak dihitung.
 * - Task baru 'check_inventory' memvalidasi rencana beli sebelum PO
 *   dibuat: sisa kuota RAB, stok tersedia, dan ketersediaan setelah
 *   dipakai bersama (dua baris RAB material sama tidak boleh saling
 *   menindih stok yang sama). Dipakai tombol "Cek Inventory" di UI.
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
        $err = pg_last_error();
        // [SECURITY PATCH] jangan sampai pesan database bocor ke frontend;
        // log detail ke server saja, lempar pesan umum yang aman.
        error_log('purchase.planningCRD PgOrFail [' . $context . ']: ' . $err);
        throw new Exception(($context ? "[{$context}] " : '') . 'Terjadi kesalahan pada database. Silakan hubungi administrator.');
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
    // [INVENTORY] helper stok butuh koneksi PDO crudClass (GetProjectStockMap).
    $db = new crud();

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
                           (GREATEST(bi.qty_plan - COALESCE(po_sum.qty_ordered, 0), 0) * COALESCE(bi.unit_price_plan, 0)) AS remaining_value,
                           0 AS qty_on_hand,
                           GREATEST(bi.qty_plan - COALESCE(po_sum.qty_ordered, 0), 0) AS qty_to_buy
                      FROM public.budget_items bi
                      JOIN public.materials m ON m.id = bi.material_id
                      LEFT JOIN (" . PLAN_PO_SUM . ") po_sum ON po_sum.budget_item_id = bi.id
                     WHERE bi.project_id = $1 AND bi.category = 'Material' AND COALESCE(bi.qty_plan, 0) > 0
                     ORDER BY m.material_name";
            $result = PgOrFail(pg_query_params($sql, [$projectId]), 'load_planning');
            $rows = pg_fetch_all($result) ?: [];

            // [INVENTORY] kaya tiap baris dg saldo stok yang dapat dipakai proyek ini.
            // Baris RAB ganda utk material sama dijumlahkan stoknya sekali saja per material.
            $stockMap = GetProjectStockMap($db, $projectId);
            $seenMat  = [];
            foreach ($rows as &$row) {
                $mid = (int)$row['material_id'];
                $onHand = $stockMap[$mid] ?? 0.0;
                $row['qty_on_hand'] = $onHand;
                // material sama muncul di >1 baris RAB: stok hanya boleh dipakai sekali
                if (isset($seenMat[$mid])) {
                    $row['qty_on_hand'] = 0.0;
                    $row['qty_to_buy']  = (float)$row['qty_remaining'];
                } else {
                    $seenMat[$mid] = true;
                    $row['qty_to_buy'] = max((float)$row['qty_remaining'] - $onHand, 0.0);
                }
            }
            unset($row);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ==========================================================
        // CHECK INVENTORY -- validasi rencana beli sebelum PO dibuat.
        // Input: project_id + items[] (rab_material_id, material_id, qty).
        // Output per baris: sisa kuota RAB, stok tersedia, kekurangan
        // (qty_to_buy). Stok dipakai bersama antarbaris RAB material sama.
        // ==========================================================
        case 'check_inventory':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) throw new Exception('Proyek belum dipilih.');

            $rawItems = $_REQUEST['items'] ?? $_POST['items'] ?? '[]';
            $items = is_string($rawItems) ? json_decode($rawItems, true) : $rawItems;
            if (!is_array($items) || !$items) throw new Exception('Tidak ada material yang diperiksa.');

            $stockMap = GetProjectStockMap($db, $projectId);

            // Sisa kuota RAB per budget_item_id (PO Dibatalkan tidak dihitung)
            $poSum = [];
            $rs = PgOrFail(pg_query_params("
                SELECT poi.budget_item_id, SUM(poi.qty) AS qty_ordered
                  FROM po_items poi
                  JOIN purchase_orders po ON po.id = poi.po_id
                 WHERE po.status <> 'Dibatalkan' AND poi.budget_item_id IS NOT NULL
                 GROUP BY poi.budget_item_id", []), 'check_inventory:po_sum');
            foreach (pg_fetch_all($rs) ?: [] as $r) {
                $poSum[(int)$r['budget_item_id']] = (float)$r['qty_ordered'];
            }

            $rabById = [];
            $rs2 = PgOrFail(pg_query_params("
                SELECT bi.id, bi.material_id, bi.qty_plan, COALESCE(bi.tolerance_pct, 0) AS tol,
                       COALESCE(NULLIF(bi.unit, ''), m.unit, '') AS unit, m.material_name
                  FROM public.budget_items bi
                  JOIN public.materials m ON m.id = bi.material_id
                 WHERE bi.project_id = $1 AND bi.category = 'Material' AND COALESCE(bi.qty_plan, 0) > 0", [$projectId]), 'check_inventory:rab');
            foreach (pg_fetch_all($rs2) ?: [] as $r) {
                $rabById[(int)$r['id']] = $r;
            }

            $usedStock = [];   // material_id => stok yang sudah diklaim baris sebelumnya
            $data = [];
            foreach ($items as $n => $it) {
                $no   = 'Baris ' . ($n + 1);
                $bid  = (int)($it['rab_material_id'] ?? $it['budget_item_id'] ?? 0);
                $mid  = (int)($it['material_id'] ?? 0);
                $qty  = round((float)($it['qty'] ?? 0), 4);
                if ($bid <= 0 || $mid <= 0 || $qty <= 0) {
                    $data[] = ['line' => $no, 'rab_material_id' => $bid, 'material_id' => $mid, 'qty' => $qty,
                               'ok' => false, 'message' => $no . ': material dan qty (> 0) wajib diisi.'];
                    continue;
                }
                $bi = $rabById[$bid] ?? null;
                if (!$bi || (int)$bi['material_id'] !== $mid) {
                    $data[] = ['line' => $no, 'rab_material_id' => $bid, 'material_id' => $mid, 'qty' => $qty,
                               'ok' => false, 'message' => $no . ': material tidak ada di RAB proyek ini.'];
                    continue;
                }
                $remaining = max((float)$bi['qty_plan'] - ($poSum[$bid] ?? 0.0), 0.0);
                $quota     = (float)$bi['qty_plan'] * (1 + (float)$bi['tol'] / 100) - ($poSum[$bid] ?? 0.0);
                $onHand    = $stockMap[$mid] ?? 0.0;
                $claimed   = $usedStock[$mid] ?? 0.0;
                $free      = max($onHand - $claimed, 0.0);        // stok belum diklaim baris lain
                $covered   = min($qty, $free);
                $toBuy     = max($qty - $free, 0.0);
                $usedStock[$mid] = $claimed + $covered;

                $msgs = [];
                $err = false;
                if ($qty > $quota + 0.0001) {
                    $msgs[] = 'qty rencana ' . $qty . ' ' . $bi['unit'] . ' melebihi sisa kuota RAB ' . max($quota, 0.0) . ' ' . $bi['unit'];
                    $err = true;
                }
                if ($toBuy > 0.0001) {
                    // bukan error: stok tidak mencukupi -> sisa kebutuhan harus dibeli
                    $msgs[] = 'stok tersedia ' . $free . ' ' . $bi['unit'] . ', perlu beli ' . $toBuy . ' ' . $bi['unit'];
                }
                $data[] = [
                    'line' => $no, 'rab_material_id' => $bid, 'material_id' => $mid,
                    'material_name' => $bi['material_name'] ?? '', 'unit' => $bi['unit'],
                    'qty' => $qty, 'qty_remaining' => $remaining, 'quota_remaining' => max($quota, 0.0),
                    'qty_on_hand' => $onHand, 'qty_covered' => $covered, 'qty_to_buy' => $toBuy,
                    'ok' => !$err,
                    'message' => $msgs ? $no . ': ' . implode('; ', $msgs) . '.' : 'OK'
                ];
            }
            echo json_encode(['success' => true, 'project_id' => $projectId, 'data' => $data]);
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}