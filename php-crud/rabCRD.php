<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
* rabCRD.php - CLEAN VERSION (Simplicity is Power)
* ============================================================
* Backend Controller untuk RAB Proyek (Material + Expense Master)
* 
* Fitur:
* - Multi-kategori: Material, Upah, Peralatan, Transport, Konsumsi, Lain-lain
* - Material: track by qty (qty_plan vs qty_out)
* - Expense: track by amount (budget only, actual via jurnal terpisah)
* - Expense Master: CRUD daftar jenis expense (seperti Master Material)
* - Budget control terintegrasi (satu plafon Nilai Kontrak)
* - Multi-currency support
* - Plafon & cadangan (addendum)
* 
* File related: rab.js, m.expenses.js
* Migration: sql/expense_master.sql
* ============================================================
*/
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../php-func/fx.func.php';

const RAB_REQUIRE_CONTRACT = true;
const RAB_EPS_VAL = 0.01;
const RAB_EPS_QTY = 0.00005;
const RAB_CATEGORIES = ['Material', 'Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'];

class RabBusinessError extends Exception {}

function mb_strlen_safe($str, $len) {
    $str = (string)$str;
    if (function_exists('mb_substr')) return mb_substr($str, 0, $len, 'UTF-8');
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    return $chars === false ? substr($str, 0, $len) : implode('', array_slice($chars, 0, $len));
}

function rab_fmt($n): string { return number_format((float)$n, 2, ',', '.'); }

// [AI-BOQ] Rentang harga MILIK MASTER (mata uang dasar): materials.estimated_price_min .. estimated_price (max),
// expense_master.default_price_min .. default_price (max). Harga satuan RAB default = max; user boleh mengubahnya di RAB
// selama masih di dalam rentang master. Rentang hanya berlaku bila master punya min > 0 dan max >= min.
// Butuh migrasi sql/2026-11-06.budget_items_price_range.sql; belum migrasi -> tanpa batas (perilaku lama).
function rab_has_master_range($db): bool {
    static $has = null;
    if ($has === null) {
        $st = $db->query("SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ANY (current_schemas(false)) AND table_name = 'materials' AND column_name = 'estimated_price_min')
                               + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ANY (current_schemas(false)) AND table_name = 'expense_master' AND column_name = 'default_price_min')");
        $has = ((int)$st->fetchColumn() === 2);
    }
    return $has;
}
function rab_master_limits($db, string $type, int $id): ?array {
    static $cache = [];
    $k = $type . $id;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $cache[$k] = null;
    if ($id <= 0 || !rab_has_master_range($db)) return null;
    if ($type === 'M') $st = $db->prepare("SELECT COALESCE(estimated_price_min,0) AS mn, COALESCE(estimated_price,0) AS mx, material_name AS nm FROM public.materials WHERE id = :id");
    else               $st = $db->prepare("SELECT COALESCE(default_price_min,0) AS mn, COALESCE(default_price,0) AS mx, expense_name AS nm FROM expense_master WHERE id = :id");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && (float)$r['mn'] > 0 && (float)$r['mx'] >= (float)$r['mn']) {
        $cache[$k] = ['min' => (float)$r['mn'], 'max' => (float)$r['mx'], 'name' => $r['nm']];
    }
    return $cache[$k];
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
$db = new crud();

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rab_currency_lock_reason($db, int $projectId): ?string {
    $st = $db->prepare("SELECT 1 FROM public.po_items poi JOIN public.purchase_orders po ON po.id = poi.po_id WHERE po.project_id = :p AND po.status <> 'Dibatalkan' LIMIT 1");
    $st->execute([':p' => $projectId]);
    if ($st->fetchColumn()) return 'Proyek ini sudah memiliki PO.';
    $st = $db->prepare("SELECT 1 FROM public.material_out_header WHERE project_id = :p AND status = 'posted' LIMIT 1");
    $st->execute([':p' => $projectId]);
    if ($st->fetchColumn()) return 'Proyek ini sudah memiliki pemakaian material.';
    return null;
}

// [FIX] HAPUS CTE expense_usage - tidak ada tabel project_expenses
const RAB_USAGE_CTE = "
WITH usage AS (
    SELECT moh.project_id, moi.material_id,
    SUM(moi.qty_out) AS qty_out,
    SUM(moi.qty_out * moi.unit_cost) AS actual_cost
    FROM material_out_items moi
    JOIN material_out_header moh ON moh.id = moi.material_out_id AND moh.status = 'posted'
    GROUP BY moh.project_id, moi.material_id
)
";

$task = $_REQUEST['task'] ?? '';

// Wajib login. RAB dibatasi berdasarkan hak baca/buat/ubah menu aktif.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401); sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}
$rabRequiredAccess = 1;
if ($task === 'save_rab') {
    $rabProjectId = (int)($_REQUEST['project_id'] ?? 0);
    $rabExistingStmt = $db->prepare('SELECT COUNT(*) FROM public.budget_items WHERE project_id = :pid');
    $rabExistingStmt->execute([':pid' => $rabProjectId]);
    $rabRequiredAccess = ((int)$rabExistingStmt->fetchColumn() > 0) ? 3 : 2;
}
if ((int)($_SESSION['FormAccess'] ?? 0) < $rabRequiredAccess) {
    http_response_code(403); sendJsonResponse(false, [], 'Hak akses tidak mencukupi untuk membaca atau menyimpan RAB.');
}

try {
    switch ($task) {
        /* ============================================================
         * LIST_CATEGORIES - Return available categories
         * ============================================================ */
        case 'list_categories':
            sendJsonResponse(true, RAB_CATEGORIES);
            break;

        /* ============================================================
         * LIST_PROJECTS - Grid utama proyek
         * [FIX] Hapus referensi expense_usage, expense_budget, expense_actual
         * ============================================================ */
        case 'list_projects':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');
            $whereClauses = [];
            $params = [];
            if ($query !== '') {
                $whereClauses[] = "(p.name ILIKE :query OR c.name ILIKE :query)";
                $params[':query'] = '%' . $query . '%';
            }
            $searchWhere = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM public.projects p LEFT JOIN public.clients c ON c.id = p.client_id {$searchWhere}");
            $stmtCount->execute($params);
            $totalCount = (int)$stmtCount->fetchColumn();
            
            $sql = RAB_USAGE_CTE . "
            SELECT p.id, p.name, c.name AS client_name, p.status,
            COALESCE(p.contract_value, 0) AS contract_value,
            COALESCE(p.budget, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_base,
            COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
            COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_exch_rate,
            COALESCE(agg.material_count, 0) AS material_count,
            COALESCE(agg.total_budget_cost, 0) AS total_budget_cost,
            COALESCE(agg.total_budget_cost, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS total_budget_base,
            COALESCE(agg.total_actual_cost, 0) / COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS total_actual_cost,
            COALESCE(exp_agg.expense_budget, 0) AS expense_budget,
            CASE COALESCE(agg.status_rank, 0)
                WHEN 3 THEN 'OVERBUDGET'
                WHEN 2 THEN 'WARNING'
                WHEN 1 THEN 'ON TRACK'
                ELSE 'NO BUDGET'
            END AS rab_status
            FROM public.projects p
            LEFT JOIN public.clients c ON c.id = p.client_id
            LEFT JOIN public.m_currency cur ON cur.id = p.budget_currency_id
            LEFT JOIN (
                SELECT bi.project_id,
                COUNT(*) FILTER (WHERE COALESCE(bi.qty_plan,0) > 0 AND bi.category = 'Material') AS material_count,
                SUM(bi.qty_plan * bi.unit_price_plan) FILTER (WHERE bi.category = 'Material') AS total_budget_cost,
                SUM(COALESCE(u.actual_cost, 0)) AS total_actual_cost,
                MAX(CASE WHEN bi.category = 'Material' THEN
                    CASE
                        WHEN COALESCE(u.qty_out,0) > bi.qty_plan * (1 + COALESCE(bi.tolerance_pct,0)/100) THEN 3
                        WHEN COALESCE(u.qty_out,0) > bi.qty_plan THEN 2
                        ELSE 1
                    END
                ELSE 1 END) AS status_rank
                FROM public.budget_items bi
                LEFT JOIN usage u ON u.project_id = bi.project_id AND u.material_id = bi.material_id
                WHERE bi.category = 'Material'
                GROUP BY bi.project_id
            ) agg ON agg.project_id = p.id
            LEFT JOIN (
                SELECT bi2.project_id,
                SUM(bi2.qty_plan * bi2.unit_price_plan) AS expense_budget
                FROM public.budget_items bi2
                WHERE bi2.category <> 'Material'
                GROUP BY bi2.project_id
            ) exp_agg ON exp_agg.project_id = p.id
            {$searchWhere}
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :start";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($projects as &$p) {
                $p['id'] = (int)$p['id'];
                $p['material_count'] = (int)$p['material_count'];
                $p['total_budget_cost'] = (float)$p['total_budget_cost'];
                $p['total_budget_base'] = (float)$p['total_budget_base'];
                $p['total_actual_cost'] = (float)$p['total_actual_cost'];
                $p['budget_exch_rate'] = (float)$p['budget_exch_rate'];
                $p['contract_value'] = (float)$p['contract_value'];
                $p['budget_base'] = (float)$p['budget_base'];
                $p['expense_budget'] = (float)$p['expense_budget'];
            }
            sendJsonResponse(true, ['data' => $projects, 'totalCount' => $totalCount]);
            break;

        /* ============================================================
         * LIST_CURRENCIES + GET_EXCHANGE_RATE
         * ============================================================ */
        case 'list_currencies':
            $rows = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            $baseCur = fx_base_currency($db);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['is_base'] = $baseCur['id'] !== null && $r['id'] === $baseCur['id'];
            }
            sendJsonResponse(true, $rows);
            break;
            
        case 'get_exchange_rate':
            $cid = (int)($_REQUEST['currency_id'] ?? 0);
            if ($cid <= 0) sendJsonResponse(false, [], 'Mata uang tidak valid.');
            sendJsonResponse(true, fx_default_rate($db, $cid, (string)($_REQUEST['date'] ?? '')));
            break;

        /* ============================================================
         * LIST_MATERIALS
         * ============================================================ */
        case 'list_materials':
            $stmt = $db->query("SELECT id, material_code, material_name, unit, COALESCE(estimated_price,0) AS estimated_price, " . (rab_has_master_range($db) ? 'COALESCE(estimated_price_min,0)' : '0') . " AS estimated_price_min FROM public.materials WHERE active IS TRUE ORDER BY material_name ASC");
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($materials as &$m) {
                $m['id'] = (int)$m['id'];
                $m['estimated_price'] = (float)$m['estimated_price'];
                $m['estimated_price_min'] = (float)$m['estimated_price_min'];      // [AI-BOQ]
            }
            sendJsonResponse(true, $materials);
            break;

        /* ============================================================
         * LIST_EXPENSE_OPTIONS - Untuk combo di RAB editor
         * ============================================================ */
        case 'list_expense_options':
            $category = trim($_REQUEST['category'] ?? '');
            $where = "WHERE active = true";
            $params = [];
            if ($category !== '' && $category !== 'All') {
                $where .= " AND category = :cat";
                $params[':cat'] = $category;
            }
            $stmt = $db->prepare("
                SELECT id, expense_code, expense_name, category, unit, default_price, " . (rab_has_master_range($db) ? 'COALESCE(default_price_min,0)' : '0') . " AS default_price_min
                FROM expense_master
                {$where}
                ORDER BY category, expense_name
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['default_price'] = (float)$r['default_price'];
                $r['default_price_min'] = (float)$r['default_price_min'];      // [AI-BOQ]
                $r['label'] = $r['expense_name'] . ' (' . $r['unit'] . ')';
            }
            sendJsonResponse(true, $rows);
            break;

        /* ============================================================
         * LOAD_RAB - Extended with all categories
         * [FIX] Hapus referensi expense_usage, expense_actual
         * ============================================================ */
        case 'load_rab':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) sendJsonResponse(false, [], 'ID Proyek tidak valid.');
            
            $stmtP = $db->prepare("SELECT p.name, p.budget_currency_id, COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_exch_rate,
                p.budget_rate_date, UPPER(TRIM(cur.init)) AS currency_code,
                p.status AS project_status, COALESCE(p.budget, 0) AS budget, COALESCE(p.contract_value, 0) AS contract_value
                FROM public.projects p LEFT JOIN public.m_currency cur ON cur.id = p.budget_currency_id WHERE p.id = :id");
            $stmtP->execute([':id' => $projectId]);
            $proj = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$proj) sendJsonResponse(false, [], 'Proyek tidak ditemukan.');
            
            $projectName = $proj['name'];
            $baseCur = fx_base_currency($db);
            $curId = $proj['budget_currency_id'] !== null ? (int)$proj['budget_currency_id'] : (int)$baseCur['id'];
            $currencyInfo = [
                'id' => $curId, 'code' => $proj['currency_code'] ?: ($baseCur['code'] ?: 'IDR'),
                'is_base' => $baseCur['id'] !== null && $curId === $baseCur['id'],
                'base_code' => $baseCur['code'] ?: 'IDR',
                'exch_rate' => (float)$proj['budget_exch_rate'],
                'rate_date' => $proj['budget_rate_date'],
                'locked_reason' => rab_currency_lock_reason($db, $projectId),
            ];
            
            $hasMR = rab_has_master_range($db);                                                      // [AI-BOQ] rentang harga dari master
            $mnSel = $hasMR ? "CASE WHEN bi.category = 'Material' THEN m.estimated_price_min ELSE em.default_price_min END" : 'NULL';
            $mxSel = "CASE WHEN bi.category = 'Material' THEN m.estimated_price ELSE em.default_price END";
            // Load ALL categories (Material + Non-Material)
            // [FIX] Hapus LEFT JOIN expense_usage - tidak ada tabel project_expenses
            $sql = RAB_USAGE_CTE . "
            SELECT bi.id, bi.project_id, bi.material_id, bi.expense_id, bi.category, bi.item_name,
            m.material_code, m.material_name,
            COALESCE(bi.qty_plan, 0) AS budget_qty,
            COALESCE(bi.unit_price_plan, 0) AS unit_price,
            COALESCE({$mnSel}, 0) AS master_min, COALESCE({$mxSel}, 0) AS master_max,
            COALESCE(bi.tolerance_pct, 0) AS tolerance_pct,
            bi.is_addendum, bi.addendum_reason, bi.addendum_at,
            COALESCE(u.qty_out, 0) AS total_qty_out,
            COALESCE(u.actual_cost, 0) AS total_actual_cost,
            (COALESCE(bi.qty_plan,0) - COALESCE(u.qty_out, 0)) AS remaining_qty,
            CASE WHEN COALESCE(bi.qty_plan,0) > 0 THEN (COALESCE(u.qty_out, 0) / bi.qty_plan) * 100 ELSE 0 END AS usage_pct,
            CASE
                WHEN bi.category = 'Material' THEN
                    CASE
                        WHEN COALESCE(u.qty_out,0) > COALESCE(bi.qty_plan,0) * (1 + COALESCE(bi.tolerance_pct,0)/100) THEN 'OVERBUDGET'
                        WHEN COALESCE(u.qty_out,0) > COALESCE(bi.qty_plan,0) THEN 'WARNING'
                        ELSE 'ON TRACK'
                    END
                ELSE
                    'ON TRACK'
            END AS status
            FROM public.budget_items bi
            LEFT JOIN public.materials m ON m.id = bi.material_id
            LEFT JOIN expense_master em ON em.id = bi.expense_id
            LEFT JOIN usage u ON u.project_id = bi.project_id AND u.material_id = bi.material_id
            WHERE bi.project_id = :project_id
            ORDER BY bi.category, COALESCE(m.material_name, bi.item_name) ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':project_id' => $projectId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as &$item) {
                $item['id'] = (int)$item['id'];
                $item['material_id'] = $item['material_id'] !== null ? (int)$item['material_id'] : null;
                $item['expense_id'] = $item['expense_id'] !== null ? (int)$item['expense_id'] : null;
                $item['budget_qty'] = (float)$item['budget_qty'];
                $item['unit_price'] = (float)$item['unit_price'];
                $item['master_min'] = (float)$item['master_min'];       // [AI-BOQ] mata uang dasar
                $item['master_max'] = (float)$item['master_max'];
                $item['tolerance_pct'] = (float)$item['tolerance_pct'];
                $item['total_qty_out'] = (float)$item['total_qty_out'];
                $item['total_actual_cost'] = (float)$item['total_actual_cost'];
                $item['remaining_qty'] = (float)$item['remaining_qty'];
                $item['usage_pct'] = (float)$item['usage_pct'];
                $item['is_addendum'] = ($item['is_addendum'] === true || $item['is_addendum'] === 't' || $item['is_addendum'] === '1');
                if (!in_array($item['category'], RAB_CATEGORIES)) $item['category'] = 'Lain-lain';
                if (empty($item['item_name'])) {
                    $item['item_name'] = $item['material_name'] ?: $item['category'];
                }
            }
            
            // Plafon calculation
            $rate = (float)$proj['budget_exch_rate'];
            $contract = (float)$proj['contract_value'];
            $budgetCur = (float)$proj['budget'];
            $budgetBase = round($budgetCur * $rate, 2);
            $rabCur = 0.0;
            foreach ($items as $it) { $rabCur += round($it['budget_qty'] * $it['unit_price'], 2); }
            $rabBase = round($rabCur * $rate, 2);
            $plafon = [
                'contract_value' => $contract, 'budget' => $budgetCur, 'budget_base' => $budgetBase,
                'project_status' => $proj['project_status'],
                'phase' => ($proj['project_status'] === 'Belum Mulai') ? 'planning' : 'running',
                'reserve_total' => $contract > 0 ? max($contract - $budgetBase, 0) : 0,
                'rab_total' => $rabCur, 'rab_total_base' => $rabBase,
                'reserve_used' => $budgetCur > 0 ? max($rabBase - $budgetBase, 0) : 0,
                'reserve_remaining' => $contract > 0 ? max($contract - max($budgetBase, $rabBase), 0) : 0,
                'require_contract' => RAB_REQUIRE_CONTRACT,
            ];
            
            sendJsonResponse(true, ['name' => $projectName, 'items' => $items, 'currency' => $currencyInfo, 'plafon' => $plafon]);
            break;

        /* ============================================================
         * SAVE_RAB - Extended for all categories
         * ============================================================ */
        case 'save_rab':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $items = json_decode($_POST['items'] ?? '[]', true);
            if ($projectId <= 0) sendJsonResponse(false, [], 'ID Proyek tidak valid.');
            if (!is_array($items)) sendJsonResponse(false, [], 'Format data item RAB tidak valid.');
            
            $stmtCur = $db->prepare("SELECT budget_currency_id FROM public.projects WHERE id = :id");
            $stmtCur->execute([':id' => $projectId]);
            $storedCur = $stmtCur->fetchColumn();
            if ($storedCur === false) sendJsonResponse(false, [], 'Proyek tidak ditemukan.');
            $storedCur = $storedCur !== null ? (int)$storedCur : null;
            $baseCur = fx_base_currency($db);
            $currencyId = (int)($_POST['currency_id'] ?? 0);
            if ($currencyId <= 0) $currencyId = $storedCur ?: (int)$baseCur['id'];
            if ($currencyId <= 0) sendJsonResponse(false, [], 'Mata uang anggaran belum dapat ditentukan.');
            
            $stmtChk = $db->prepare("SELECT 1 FROM public.m_currency WHERE id = :id");
            $stmtChk->execute([':id' => $currencyId]);
            if (!$stmtChk->fetchColumn()) sendJsonResponse(false, [], 'Mata uang anggaran tidak ditemukan.');
            
            $exchRate = (float)($_POST['exch_rate'] ?? 1);
            if ($baseCur['id'] !== null && $currencyId === $baseCur['id']) $exchRate = 1.0;
            if ($exchRate <= 0) sendJsonResponse(false, [], 'Kurs harus lebih besar dari 0.');
            
            $rateDate = (string)($_POST['rate_date'] ?? '');
            $rd = DateTime::createFromFormat('Y-m-d', $rateDate);
            if (!$rd || $rd->format('Y-m-d') !== $rateDate) $rateDate = date('Y-m-d');
            
            if ($storedCur !== null && $currencyId !== $storedCur) {
                $lock = rab_currency_lock_reason($db, $projectId);
                if ($lock !== null) sendJsonResponse(false, [], 'Mata uang anggaran tidak dapat diubah. ' . $lock);
            }
            
            $db->beginTransaction();
            try {
                $stP = $db->prepare("SELECT status, COALESCE(budget, 0) AS budget, COALESCE(contract_value, 0) AS contract_value FROM public.projects WHERE id = :id FOR UPDATE");
                $stP->execute([':id' => $projectId]);
                $pj = $stP->fetch(PDO::FETCH_ASSOC);
                $contract = (float)$pj['contract_value'];
                $budgetCur = (float)$pj['budget'];
                $running = ($pj['status'] !== 'Belum Mulai');
                
                // Load existing items (for comparison)
                $stOld = $db->prepare("SELECT id, material_id, expense_id, category, qty_plan, unit_price_plan FROM public.budget_items WHERE project_id = :p");
                $stOld->execute([':p' => $projectId]);
                $old = [];
                foreach ($stOld->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $key = ($o['category'] === 'Material' && $o['material_id']) ? 'M' . $o['material_id'] : 'E' . $o['category'] . '_' . ($o['expense_id'] ?: $o['id']);
                    $old[$key] = ['id' => (int)$o['id'], 'qty' => (float)$o['qty_plan'], 'price' => (float)$o['unit_price_plan'], 'category' => $o['category']];
                }
                
                $totalCur = 0.0; $flags = [];
                foreach ($items as $item) {
                    $cat = in_array($item['category'] ?? '', RAB_CATEGORIES) ? $item['category'] : 'Lain-lain';
                    $mid = (int)($item['material_id'] ?? 0);
                    $eid = (int)($item['expense_id'] ?? 0);
                    $q = (float)($item['budget_qty'] ?? 0);
                    $pr = (float)($item['unit_price'] ?? 0);
                    if ($cat === 'Material' && ($mid <= 0 || $q <= 0)) continue;
                    if ($cat !== 'Material' && ($eid <= 0 || $q <= 0)) continue;
                    $val = round($q * $pr, 2);
                    $totalCur += $val;
                    $key = ($cat === 'Material' && $mid > 0) ? 'M' . $mid : 'E' . $cat . '_' . $eid;
                    $oldVal = isset($old[$key]) ? round($old[$key]['qty'] * $old[$key]['price'], 2) : 0.0;
                    $flags[$key] = !isset($old[$key]) || $q > $old[$key]['qty'] + RAB_EPS_QTY || $val > $oldVal + RAB_EPS_VAL;
                    // [AI-BOQ] harga satuan harus di dalam rentang min-max MASTER (diperiksa hanya untuk item baru / harga yang diubah,
                    // sehingga data lama yang sudah di luar rentang tidak memblokir simpan). Perbandingan dalam mata uang dasar.
                    if (!isset($old[$key]) || abs($pr - $old[$key]['price']) > 0.005) {
                        $lim = ($cat === 'Material') ? rab_master_limits($db, 'M', $mid) : rab_master_limits($db, 'E', $eid);
                        if ($lim) {
                            $pBase = $pr * $exchRate;
                            if ($pBase < $lim['min'] * 0.9999 - 0.5 || $pBase > $lim['max'] * 1.0001 + 0.5) {
                                throw new RabBusinessError('Harga satuan ' . $lim['name'] . ' (' . rab_fmt($pBase) . ') di luar batas master: min ' . rab_fmt($lim['min']) .
                                                           ' - max ' . rab_fmt($lim['max']) . '. Ubah harga atau perbarui rentang harga di master terlebih dahulu.');
                            }
                        }
                    }
                }
                
                $totalBase = round($totalCur * $exchRate, 2);
                $budgetBase = round($budgetCur * $exchRate, 2);
                
                if ($contract <= 0 && RAB_REQUIRE_CONTRACT) {
                    throw new RabBusinessError('Nilai Kontrak (plafon) proyek belum diisi.');
                }
                if ($contract > 0) {
                    if ($budgetBase > $contract + RAB_EPS_VAL) {
                        throw new RabBusinessError('Budget proyek (setara ' . rab_fmt($budgetBase) . ') melebihi Nilai Kontrak (' . rab_fmt($contract) . ').');
                    }
                    if ($totalBase > $contract + RAB_EPS_VAL) {
                        throw new RabBusinessError('Total RAB setara ' . rab_fmt($totalBase) . ' melebihi Nilai Kontrak (' . rab_fmt($contract) . ').');
                    }
                }
                if (!$running && $budgetCur > 0 && $totalBase > $budgetBase + RAB_EPS_VAL) {
                    throw new RabBusinessError('Total RAB melebihi Budget proyek. Cadangan baru bisa dipakai setelah proyek Berjalan.');
                }
                
                $usesReserve = $running && $budgetCur > 0 && $totalBase > $budgetBase + RAB_EPS_VAL;
                $userId = (int)($_SESSION['user_id'] ?? 0);
                $addInfo = [];
                foreach ($items as $item) {
                    $cat = in_array($item['category'] ?? '', RAB_CATEGORIES) ? $item['category'] : 'Lain-lain';
                    $mid = (int)($item['material_id'] ?? 0);
                    $eid = (int)($item['expense_id'] ?? 0);
                    $q = (float)($item['budget_qty'] ?? 0);
                    if ($cat === 'Material' && $mid <= 0) continue;
                    if ($cat !== 'Material' && ($eid <= 0 || $q <= 0)) continue;
                    $reason = trim((string)($item['addendum_reason'] ?? ''));
                    $key = ($cat === 'Material' && $mid > 0) ? 'M' . $mid : 'E' . $cat . '_' . $eid;
                    $isAdd = $usesReserve && !empty($flags[$key]);
                    if ($isAdd && $reason === '') {
                        throw new RabBusinessError('Penambahan RAB yang memakai cadangan wajib diisi alasannya.');
                    }
                    $addInfo[$key] = ['flag' => $isAdd ? 1 : 0, 'reason' => $isAdd ? mb_strlen_safe($reason, 500) : '', 'category' => $cat];
                }
                
                $stmtProj = $db->prepare("UPDATE public.projects SET budget_currency_id = :c, budget_exch_rate = :r, budget_rate_date = CAST(:d AS date) WHERE id = :id");
                $stmtProj->execute([':c' => $currencyId, ':r' => $exchRate, ':d' => $rateDate, ':id' => $projectId]);
                
                $activeKeys = [];
                
                foreach ($items as $item) {
                    $cat = in_array($item['category'] ?? '', RAB_CATEGORIES) ? $item['category'] : 'Lain-lain';
                    $materialId = (int)($item['material_id'] ?? 0);
                    $expenseId = (int)($item['expense_id'] ?? 0);
                    $itemName = trim((string)($item['item_name'] ?? ''));
                    $budgetQty = (float)($item['budget_qty'] ?? 0);
                    $unitPrice = (float)($item['unit_price'] ?? 0);
                    $tolerance = (float)($item['tolerance_pct'] ?? 0);
                    $unitName = trim((string)($item['unit'] ?? 'Unit'));
                    
                    if ($cat === 'Material' && $materialId <= 0) continue;
                    if ($cat !== 'Material' && ($expenseId <= 0 || $budgetQty <= 0)) continue;
                    
                    $key = ($cat === 'Material' && $materialId > 0) ? 'M' . $materialId : 'E' . $cat . '_' . $expenseId;
                    $activeKeys[] = $key;
                    
                    if ($cat === 'Material') {
                        // UPSERT untuk Material
                        $stmtUpsert = $db->prepare("
                            INSERT INTO public.budget_items (project_id, material_id, category, item_name, unit, qty_plan, unit_price_plan, tolerance_pct, created_at, is_addendum, addendum_reason, addendum_by, addendum_at)
                            SELECT :project_id, :material_id, 'Material', m.material_name, COALESCE(m.unit, :unit_name), :budget_qty, :unit_price, :tolerance_pct, NOW(),
                            (CAST(:is_add AS integer) = 1), NULLIF(:reason, ''), CAST(NULLIF(:by, '') AS integer), CASE WHEN CAST(:is_add AS integer) = 1 THEN NOW() END
                            FROM public.materials m WHERE m.id = :material_id
                            ON CONFLICT (project_id, material_id) WHERE category = 'Material' AND material_id IS NOT NULL
                            DO UPDATE SET qty_plan = EXCLUDED.qty_plan, unit_price_plan = EXCLUDED.unit_price_plan, tolerance_pct = EXCLUDED.tolerance_pct,
                            item_name = EXCLUDED.item_name, unit = EXCLUDED.unit,
                            is_addendum = (budget_items.is_addendum OR EXCLUDED.is_addendum),
                            addendum_reason = CASE WHEN EXCLUDED.is_addendum THEN EXCLUDED.addendum_reason ELSE budget_items.addendum_reason END,
                            addendum_by = CASE WHEN EXCLUDED.is_addendum THEN EXCLUDED.addendum_by ELSE budget_items.addendum_by END,
                            addendum_at = CASE WHEN EXCLUDED.is_addendum THEN EXCLUDED.addendum_at ELSE budget_items.addendum_at END
                        ");
                        $stmtUpsert->execute([
                            ':project_id' => $projectId, ':material_id' => $materialId, ':unit_name' => $unitName,
                            ':budget_qty' => $budgetQty, ':unit_price' => $unitPrice, ':tolerance_pct' => $tolerance,
                            ':is_add' => $addInfo[$key]['flag'] ?? 0, ':reason' => $addInfo[$key]['reason'] ?? '',
                            ':by' => $userId > 0 ? (string)$userId : ''
                        ]);
                    } else {
                        // Non-Material: ambil nama dari expense_master
                        $stExp = $db->prepare("SELECT expense_name, unit FROM expense_master WHERE id = :id AND active = true");
                        $stExp->execute([':id' => $expenseId]);
                        $expRow = $stExp->fetch(PDO::FETCH_ASSOC);
                        if (!$expRow) throw new RabBusinessError("Expense ID {$expenseId} tidak ditemukan atau tidak aktif.");
                        $finalItemName = $itemName ?: $expRow['expense_name'];
                        $finalUnit = $unitName !== 'Unit' ? $unitName : $expRow['unit'];
                        
                        // Cek apakah sudah ada (berdasarkan project_id + category + expense_id)
                        $stChk = $db->prepare("SELECT id FROM budget_items WHERE project_id = :p AND category = :cat AND expense_id = :eid");
                        $stChk->execute([':p' => $projectId, ':cat' => $cat, ':eid' => $expenseId]);
                        $existingId = $stChk->fetchColumn();
                        
                        if ($existingId) {
                            $db->prepare("UPDATE budget_items SET item_name = :name, unit = :unit, qty_plan = :qty, unit_price_plan = :price, tolerance_pct = :tol, updated_at = NOW() WHERE id = :id")
                                ->execute([':name' => $finalItemName, ':unit' => $finalUnit, ':qty' => $budgetQty, ':price' => $unitPrice, ':tol' => $tolerance, ':id' => $existingId]);
                        } else {
                            $db->prepare("INSERT INTO budget_items (project_id, material_id, expense_id, category, item_name, unit, qty_plan, unit_price_plan, tolerance_pct, created_at)
                                VALUES (:p, NULL, :eid, :cat, :name, :unit, :qty, :price, :tol, NOW())")
                                ->execute([':p' => $projectId, ':eid' => $expenseId, ':cat' => $cat, ':name' => $finalItemName, ':unit' => $finalUnit, ':qty' => $budgetQty, ':price' => $unitPrice, ':tol' => $tolerance]);
                        }
                    }
                }
                
                // Delete items yang tidak ada di grid
                if ($activeKeys) {
                    $materialIds = []; $expenseIds = [];
                    foreach ($activeKeys as $k) {
                        if (strpos($k, 'M') === 0) {
                            $materialIds[] = (int)substr($k, 1);
                        } else {
                            $parts = explode('_', substr($k, 1), 2);
                            if (count($parts) === 2) {
                                $expenseIds[] = (int)$parts[1];
                            }
                        }
                    }
                    
                    // Delete Material yang tidak ada
                    if ($materialIds) {
                        $ph = [];
                        $params = [':project_id' => $projectId];
                        foreach ($materialIds as $i => $mid) { $ph[] = ":m{$i}"; $params[":m{$i}"] = $mid; }
                        $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id AND category = 'Material' AND material_id NOT IN (" . implode(',', $ph) . ")")
                            ->execute($params);
                    } else {
                        $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id AND category = 'Material'")
                            ->execute([':project_id' => $projectId]);
                    }
                    
                    // Delete Non-Material yang tidak ada
                    if ($expenseIds) {
                        $ph2 = [];
                        $params2 = [':project_id' => $projectId];
                        foreach ($expenseIds as $i => $eid) { $ph2[] = ":e{$i}"; $params2[":e{$i}"] = $eid; }
                        $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id AND category <> 'Material' AND expense_id NOT IN (" . implode(',', $ph2) . ")")
                            ->execute($params2);
                    } else {
                        $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id AND category <> 'Material'")
                            ->execute([':project_id' => $projectId]);
                    }
                } else {
                    $db->prepare("DELETE FROM public.budget_items WHERE project_id = :project_id")->execute([':project_id' => $projectId]);
                }
                
                $db->commit();
                $msg = 'RAB berhasil disimpan.';
                if ($usesReserve) $msg .= ' Cadangan terpakai ' . rab_fmt($totalBase - $budgetBase) . ' dari ' . rab_fmt(max($contract - $budgetBase, 0)) . '.';
                sendJsonResponse(true, [], $msg);
            } catch (RabBusinessError $e) {
                if ($db->inTransaction()) $db->rollBack();
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                sendJsonResponse(false, [], 'Gagal menyimpan RAB: ' . $e->getMessage());
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal: ' . htmlspecialchars($task));
            break;
    }
} catch (PDOException $e) {
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}