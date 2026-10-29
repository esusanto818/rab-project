<?php
// php-crud/dashboardCRD.php
// Versi diperbaiki: menggunakan nama tabel/kolom yang sesuai skema DB Anda
// + tambahan: Material Butuh PO & Proyek Tanpa Material di Action Center
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../cron/fetch_kurs_pajak.php';

function out(array $data, int $flags = 0): void {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    out(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
}

/*
* Sumber data "Performa Keuangan". Matikan salah satu jika ternyata
* tabelnya sudah saling mirror (mencegah hitung ganda).
*/
const SRC_FIN_INCOME   = true;  // financial_incomes
const SRC_PRJ_PAYMENT  = true;  // project_payment_items (pembayaran termin klien)
const SRC_FIN_EXPENSE  = true;  // financial_expenses
const SRC_MATERIAL_OUT = true;  // material_out_items x unit_cost (biaya material ke proyek)
const SRC_MATERIAL_IN  = false; // material_receipt_items (aktifkan bila ingin pengeluaran dihitung saat barang MASUK)

$db     = new crud();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ============================================================
// 1. DAFTAR PROYEK (dropdown filter)
// ============================================================
case 'get_projects':
    try {
        $stmt = $db->prepare("SELECT id, name AS project_name FROM projects ORDER BY name ASC");
        $stmt->execute();
        out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        out(['success' => false, 'message' => $e->getMessage()]);
    }
    break;

// ============================================================
// 2. KURS PAJAK KMK TERBARU
// ============================================================
case 'read_tax_rates':
    try {
        $stmt = $db->prepare("SELECT id, kmk_no, start_date, end_date FROM tax_exchange_rate_hdr ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            out(['success' => true, 'kmk_number' => '-', 'start_date' => '-', 'end_date' => '-', 'rates' => []]);
        }
        $stmt = $db->prepare("
            SELECT currency_code, currency_name, unit_multiplier, rate, change_amount, change_direction
            FROM tax_exchange_rate_dtl
            WHERE hdr_id = :hdr
            ORDER BY currency_code ASC
        ");
        $stmt->bindValue(':hdr', (int)$header['id'], PDO::PARAM_INT);
        $stmt->execute();
        out([
            'success'    => true,
            'kmk_number' => $header['kmk_no'],
            'start_date' => date('d/m/Y', strtotime($header['start_date'])),
            'end_date'   => date('d/m/Y', strtotime($header['end_date'])),
            'rates'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal membaca kurs pajak: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 3. SYNC KURS DARI BEA CUKAI
// ============================================================
case 'sync_tax_rates':
    try {
        if (!function_exists('fetchAndStoreTaxExchangeRates')) {
            out(['success' => false, 'message' => 'Fungsi fetchAndStoreTaxExchangeRates tidak ditemukan.']);
        }
        out((array)fetchAndStoreTaxExchangeRates($db));
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal mengambil data dari server Bea Cukai: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 4. FINANCIAL AGING (AR & AP)
//    AR = project_invoices (sisa tagihan termin klien)
//    AP = purchase_invoice_hdr (sisa invoice supplier)
// ============================================================
case 'read_financial_aging':
    $projectId = (int)($_GET['project_id'] ?? 0);
    $bucketSql = "
        SELECT COALESCE(SUM(CASE WHEN age <= 30 THEN os END), 0)               AS age_30,
               COALESCE(SUM(CASE WHEN age BETWEEN 31 AND 60 THEN os END), 0)   AS age_60,
               COALESCE(SUM(CASE WHEN age BETWEEN 61 AND 90 THEN os END), 0)   AS age_90,
               COALESCE(SUM(CASE WHEN age > 90 THEN os END), 0)                AS age_over_90,
               COALESCE(SUM(os), 0)                                            AS total,
               COUNT(*)                                                        AS cnt
        FROM (%s) t";

    // AR: sisa = amount - GREATEST(paid_amount, total alokasi pembayaran)
    $arInner = "
        SELECT (pinv.amount - GREATEST(pinv.paid_amount,
                COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) AS os,
               (CURRENT_DATE - pinv.invoice_date) AS age
        FROM project_invoices pinv
        WHERE (pinv.amount - GREATEST(pinv.paid_amount,
                COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) > 0.005"
        . ($projectId ? " AND pinv.project_id = :pid" : "");

    $apStatus = "COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')";
    if ($projectId) {
        // porsi invoice supplier yang menjadi milik proyek terpilih (berdasar nilai matched per PO.project_id)
        $apInner = "
            SELECT ((h.total_amount - h.paid_amount) * COALESCE(sh.share, 0)) AS os,
                   (CURRENT_DATE - h.invoice_date) AS age
            FROM purchase_invoice_hdr h
            JOIN (
                SELECT d.invoice_hdr_id,
                       SUM(CASE WHEN po.project_id = :pid THEN d.matched_amount ELSE 0 END)
                       / NULLIF(SUM(d.matched_amount), 0) AS share
                FROM purchase_invoice_dtl d
                JOIN grn_hdr g ON g.id = d.grn_hdr_id
                JOIN purchase_orders po ON po.id = g.po_id
                GROUP BY d.invoice_hdr_id
            ) sh ON sh.invoice_hdr_id = h.id
            WHERE {$apStatus}
            AND (h.total_amount - h.paid_amount) > 0.005";
    } else {
        $apInner = "
            SELECT (h.total_amount - h.paid_amount) AS os,
                   (CURRENT_DATE - h.invoice_date) AS age
            FROM purchase_invoice_hdr h
            WHERE {$apStatus}
            AND (h.total_amount - h.paid_amount) > 0.005";
    }

    $fetchBucket = function (string $inner) use ($db, $bucketSql, $projectId) {
        $stmt = $db->prepare(sprintf($bucketSql, $inner));
        if ($projectId) {
            $stmt->bindValue(':pid', $projectId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'age_30'      => (float)($r['age_30'] ?? 0),
            'age_60'      => (float)($r['age_60'] ?? 0),
            'age_90'      => (float)($r['age_90'] ?? 0),
            'age_over_90' => (float)($r['age_over_90'] ?? 0),
            'total'       => (float)($r['total'] ?? 0),
            'count'       => (int)($r['cnt'] ?? 0),
        ];
    };

    try {
        out([
            'success' => true,
            'as_of'   => date('Y-m-d'),
            'ar'      => $fetchBucket($arInner),
            'ap'      => $fetchBucket($apInner),
        ]);
    } catch (Exception $e) {
        error_log('[dashboardCRD] financial_aging: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query aging gagal: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 5. PERFORMA KEUANGAN BULANAN (Pendapatan vs Pengeluaran)
// ============================================================
case 'read_financial_performance':
    $year      = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $projectId = (int)($_GET['project_id'] ?? 0);
    $from      = sprintf('%04d-01-01', $year);
    $to        = sprintf('%04d-01-01', $year + 1);
    $cancelled = "('VOID','CANCELLED','CANCELED','BATAL')";
    $n = 0;
    $mk = function ($v, array &$bag) use (&$n) {
        $k = ':p' . ($n++);
        $bag[$k] = $v;
        return $k;
    };

    $sources = [];
    if (SRC_FIN_INCOME) {
        $bag = [];
        $sources[] = ['type' => 'inc', 'name' => 'financial_incomes', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM income_date)::int AS bln, amount
             FROM financial_incomes
             WHERE income_date >= CAST({$mk($from, $bag)} AS date) AND income_date < CAST({$mk($to, $bag)} AS date)
             AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
            . ($projectId ? " AND project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_PRJ_PAYMENT) {
        $bag = [];
        $sources[] = ['type' => 'inc', 'name' => 'project_payment_items', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM pp.payment_date)::int AS bln, ppi.amount_paid AS amount
             FROM project_payment_items ppi
             JOIN project_payments pp ON pp.id = ppi.payment_id
             JOIN project_invoices pinv ON pinv.id = ppi.invoice_id
             WHERE pp.payment_date >= CAST({$mk($from, $bag)} AS date) AND pp.payment_date < CAST({$mk($to, $bag)} AS date)"
            . ($projectId ? " AND pinv.project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_FIN_EXPENSE) {
        $bag = [];
        $sources[] = ['type' => 'exp', 'name' => 'financial_expenses', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM expense_date)::int AS bln, amount
             FROM financial_expenses
             WHERE expense_date >= CAST({$mk($from, $bag)} AS date) AND expense_date < CAST({$mk($to, $bag)} AS date)
             AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
            . ($projectId ? " AND project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_MATERIAL_OUT) {
        $bag = [];
        $sources[] = ['type' => 'exp', 'name' => 'material_out', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM h.issue_date)::int AS bln, (i.qty_out * i.unit_cost) AS amount
             FROM material_out_items i
             JOIN material_out_header h ON h.id = i.material_out_id
             WHERE h.issue_date >= CAST({$mk($from, $bag)} AS date) AND h.issue_date < CAST({$mk($to, $bag)} AS date)
             AND h.status = 'posted' AND h.reversed_at IS NULL"
            . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_MATERIAL_IN) {
        $bag = [];
        $sources[] = ['type' => 'exp', 'name' => 'material_receipt', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM h.receipt_date)::int AS bln, (i.qty_in * i.unit_cost) AS amount
             FROM material_receipt_items i
             JOIN material_receipt_header h ON h.id = i.receipt_id
             WHERE h.receipt_date >= CAST({$mk($from, $bag)} AS date) AND h.receipt_date < CAST({$mk($to, $bag)} AS date)
             AND h.status = 'posted' AND h.reversed_at IS NULL"
            . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }

    $empty = "SELECT 0::int AS bln, 0::numeric AS amount WHERE false";
    $incParts = []; $expParts = []; $params = [];
    foreach ($sources as $s) {
        if ($s['type'] === 'inc') { $incParts[] = $s['sql']; } else { $expParts[] = $s['sql']; }
        $params += $s['params'];
    }
    $incSql = $incParts ? implode(' UNION ALL ', $incParts) : $empty;
    $expSql = $expParts ? implode(' UNION ALL ', $expParts) : $empty;

    $sql = "
        WITH inc AS ({$incSql}), exp AS ({$expSql})
        SELECT g.bln,
               (SELECT COALESCE(SUM(amount),0) FROM inc WHERE inc.bln = g.bln) AS total_inc,
               (SELECT COALESCE(SUM(amount),0) FROM exp WHERE exp.bln = g.bln) AS total_exp
        FROM generate_series(1,12) AS g(bln)
        ORDER BY g.bln
    ";

    $pendapatan  = array_fill(0, 12, 0.0);
    $pengeluaran = array_fill(0, 12, 0.0);
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idx = (int)$r['bln'] - 1;
            $pendapatan[$idx]  = (float)$r['total_inc'];
            $pengeluaran[$idx] = (float)$r['total_exp'];
        }
    } catch (Exception $e) {
        error_log('[dashboardCRD] financial_performance: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query performa keuangan gagal: ' . $e->getMessage()]);
    }

    // Daftar tahun yang punya transaksi
    $years = [(int)date('Y'), $year];
    try {
        $yrSql = "SELECT DISTINCT y FROM (
            SELECT EXTRACT(YEAR FROM income_date)::int AS y FROM financial_incomes
            UNION SELECT EXTRACT(YEAR FROM expense_date)::int FROM financial_expenses
            UNION SELECT EXTRACT(YEAR FROM payment_date)::int FROM project_payments
            UNION SELECT EXTRACT(YEAR FROM issue_date)::int FROM material_out_header
        ) t WHERE y IS NOT NULL ORDER BY y DESC";
        foreach ($db->query($yrSql)->fetchAll(PDO::FETCH_COLUMN) as $y) {
            $years[] = (int)$y;
        }
    } catch (Exception $e) {
        error_log('[dashboardCRD] available_years: ' . $e->getMessage());
    }
    $years = array_values(array_unique($years));
    rsort($years);

    out([
        'success'         => true,
        'year'            => $year,
        'labels'          => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        'datasets'        => ['pendapatan' => $pendapatan, 'pengeluaran' => $pengeluaran],
        'available_years' => $years,
    ], JSON_NUMERIC_CHECK);
    break;

// ============================================================
// 6. MONITORING MATERIAL (RAB vs Material Out)
//    Tabel: budget_items + material_out_items/material_out_header
// ============================================================
case 'read_material_monitoring':
    $projectId = (int)($_GET['project_id'] ?? 0);
    $params = [];
    $P = function ($v) use (&$params) {
        $k = ':p' . count($params);
        $params[$k] = $v;
        return $k;
    };

    $budWhere = "(LOWER(b.category) = 'material' OR b.material_id IS NOT NULL)"
              . ($projectId ? " AND b.project_id = {$P($projectId)}" : "");
    $outWhere = "h.status = 'posted' AND h.reversed_at IS NULL"
              . ($projectId ? " AND h.project_id = {$P($projectId)}" : "");

    $sql = "
        WITH bud AS (
            SELECT b.project_id,
                   b.material_id,
                   MAX(b.item_name)                       AS item_name,
                   MAX(NULLIF(b.unit,''))                 AS unit,
                   SUM(b.qty_plan)                        AS qty_plan,
                   SUM(b.qty_plan * b.unit_price_plan)    AS budget_value,
                   MAX(b.tolerance_pct)                   AS tolerance_pct
            FROM budget_items b
            WHERE {$budWhere}
            GROUP BY b.project_id, COALESCE(b.material_id, -b.id), b.material_id
        ),
        outs AS (
            SELECT h.project_id,
                   i.material_id,
                   SUM(i.qty_out)                AS qty_out,
                   SUM(i.qty_out * i.unit_cost)  AS out_value
            FROM material_out_items i
            JOIN material_out_header h ON h.id = i.material_out_id
            WHERE {$outWhere}
            GROUP BY h.project_id, i.material_id
        )
        SELECT COALESCE(b.project_id, o.project_id)          AS project_id,
               COALESCE(b.material_id, o.material_id)        AS material_id,
               COALESCE(m.material_code, '')                 AS item_code,
               COALESCE(m.material_name, b.item_name, '(Tanpa nama)') AS item_name,
               COALESCE(b.unit, NULLIF(m.unit,''), '')       AS unit,
               COALESCE(b.qty_plan, 0)                       AS budget_qty,
               COALESCE(b.budget_value, 0)                   AS budget_value,
               COALESCE(b.tolerance_pct, 0)                  AS tolerance_pct,
               COALESCE(o.qty_out, 0)                        AS out_qty,
               COALESCE(o.out_value, 0)                      AS out_value
        FROM bud b
        FULL OUTER JOIN outs o
          ON o.project_id = b.project_id AND o.material_id = b.material_id
        LEFT JOIN materials m ON m.id = COALESCE(b.material_id, o.material_id)
        ORDER BY item_name ASC
    ";

    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[dashboardCRD] material_monitoring: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query monitoring material gagal: ' . $e->getMessage()]);
    }

    $kpi    = ['budget_rab' => 0.0, 'material_out' => 0.0, 'sisa_budget' => 0.0, 'persentase' => 0.0];
    $counts = ['ON_TRACK' => 0, 'WARNING' => 0, 'OVERBUDGET' => 0];
    $items  = [];

    foreach ($rows as $r) {
        $plan  = (float)$r['budget_qty'];
        $used  = (float)$r['out_qty'];
        $tol   = (float)$r['tolerance_pct'];
        $noRab = $plan <= 0;

        $kpi['budget_rab']   += (float)$r['budget_value'];
        $kpi['material_out'] += (float)$r['out_value'];

        $usage = $noRab ? ($used > 0 ? 100.0 : 0.0) : ($used / $plan) * 100;

        if ($noRab && $used > 0)                    $status = 'OVERBUDGET';
        elseif ($used > $plan * (1 + $tol / 100))   $status = 'OVERBUDGET';
        elseif ($usage >= 80)                       $status = 'WARNING';
        else                                        $status = 'ON_TRACK';

        $counts[$status]++;
        $items[] = [
            'item_code'     => $r['item_code'],
            'item_name'     => $r['item_name'],
            'unit'          => $r['unit'],
            'budget_qty'    => $plan,
            'budget_value'  => (float)$r['budget_value'],
            'out_qty'       => $used,
            'out_value'     => (float)$r['out_value'],
            'remaining_qty' => $plan - $used,
            'usage_pct'     => round($usage, 1),
            'no_rab'        => $noRab,
            'status'        => $status,
        ];
    }

    usort($items, fn($a, $b) => $b['usage_pct'] <=> $a['usage_pct']);

    $kpi['sisa_budget'] = $kpi['budget_rab'] - $kpi['material_out'];
    $kpi['persentase']  = $kpi['budget_rab'] > 0 ? round($kpi['material_out'] / $kpi['budget_rab'] * 100, 2) : 0.0;

    out(['success' => true, 'kpi' => $kpi, 'status_counts' => $counts, 'items' => $items], JSON_NUMERIC_CHECK);
    break;

// ============================================================
// 7. INVOICE PIPELINE
// ============================================================
case 'read_invoice_pipeline':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT
            CASE
                WHEN amount - paid_amount <= 0.005 THEN 'Lunas'
                WHEN due_date < CURRENT_DATE AND amount - paid_amount > 0.005 THEN 'Jatuh Tempo (Overdue)'
                WHEN paid_amount > 0.005 THEN 'Sebagian'
                ELSE 'Belum Bayar'
            END AS status,
            COUNT(*) AS count,
            COALESCE(SUM(amount - paid_amount), 0) AS outstanding_value
        FROM project_invoices
        WHERE (:pid = 0 OR project_id = :pid)
        GROUP BY status
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':pid' => $pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = ['Lunas' => 0, 'Jatuh Tempo (Overdue)' => 0, 'Sebagian' => 0, 'Belum Bayar' => 0];
    $values = ['Lunas' => 0, 'Jatuh Tempo (Overdue)' => 0, 'Sebagian' => 0, 'Belum Bayar' => 0];
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int)$r['count'];
            $values[$r['status']] = (float)$r['outstanding_value'];
        }
    }
    out(['success' => true, 'data' => ['counts' => $counts, 'values' => $values]]);
    break;

// ============================================================
// 8. ACTION CENTER (termasuk Material Butuh PO & Proyek Tanpa Material)
// ============================================================
case 'read_action_center':
    $pid = (int)($_REQUEST['project_id'] ?? 0);

    $overdueInvoices  = 0;
    $pendingPOs       = 0;
    $unbilledProjects = 0;
    $materialNeedPO   = 0;
    $projectsNoMat    = 0;

    // 1. Invoice Overdue
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM project_invoices WHERE due_date < CURRENT_DATE AND amount - paid_amount > 0.005 AND (:pid = 0 OR project_id = :pid)");
        $st->execute([':pid' => $pid]);
        $overdueInvoices = (int)$st->fetchColumn();
    } catch (Exception $e) { $overdueInvoices = 0; }

    // 2. PO Menunggu Approval (status Draft/Pending)
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Draft', 'Pending', 'Waiting Approval')");
        $st->execute();
        $pendingPOs = (int)$st->fetchColumn();
    } catch (Exception $e) { $pendingPOs = 0; }

    // 3. Proyek Berjalan yang Belum Ditagih
    try {
        $st = $db->prepare("
            SELECT COUNT(*) FROM projects p
            WHERE p.status = 'Berjalan'
              AND p.contract_value > 0
              AND NOT EXISTS (SELECT 1 FROM project_invoices WHERE project_id = p.id)
              AND (:pid = 0 OR p.id = :pid)
        ");
        $st->execute([':pid' => $pid]);
        $unbilledProjects = (int)$st->fetchColumn();
    } catch (Exception $e) { $unbilledProjects = 0; }

    // 4. [BARU] Material yang belum ada PO
    //    Material di RAB (budget_items) yang belum pernah dipesan di purchase_order_items
    try {
        $st = $db->prepare("
            SELECT COUNT(DISTINCT b.material_id)
            FROM budget_items b
            JOIN projects p ON p.id = b.project_id
            WHERE p.status IN ('Belum Mulai', 'Berjalan')
              AND (LOWER(b.category) = 'material' OR b.material_id IS NOT NULL)
              AND b.material_id IS NOT NULL
              AND b.material_id NOT IN (SELECT DISTINCT item_id FROM purchase_order_items WHERE item_id IS NOT NULL)
              AND (:pid = 0 OR p.id = :pid)
        ");
        $st->execute([':pid' => $pid]);
        $materialNeedPO = (int)$st->fetchColumn();
    } catch (Exception $e) {
        error_log('[dashboardCRD] material_need_po: ' . $e->getMessage());
        $materialNeedPO = 0;
    }

    // 5. [BARU] Proyek yang belum ada pengeluaran material
    try {
        $st = $db->prepare("
            SELECT COUNT(*) FROM projects p
            WHERE p.status IN ('Belum Mulai', 'Berjalan')
              AND NOT EXISTS (SELECT 1 FROM material_out_header WHERE project_id = p.id)
              AND (:pid = 0 OR p.id = :pid)
        ");
        $st->execute([':pid' => $pid]);
        $projectsNoMat = (int)$st->fetchColumn();
    } catch (Exception $e) { $projectsNoMat = 0; }

    out(['success' => true, 'data' => [
        'overdue_invoices'    => $overdueInvoices,
        'pending_pos'         => $pendingPOs,
        'unbilled_projects'   => $unbilledProjects,
        'material_need_po'    => $materialNeedPO,
        'projects_no_material'=> $projectsNoMat,
    ]]);
    break;

// ============================================================
// 9. AI MILESTONES (14 hari ke depan)
// ============================================================
case 'read_upcoming_milestones':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT p.name AS project_name, t.task_name, t.end_date AS deadline, t.status
        FROM public.project_tasks t
        JOIN public.projects p ON p.id = t.project_id
        WHERE t.end_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '14 days'
          AND t.status NOT IN ('Selesai', 'Closed', 'Done')
          AND (:pid = 0 OR t.project_id = :pid)
        ORDER BY t.end_date ASC
        LIMIT 10
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':pid' => $pid]);
    out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ============================================================
// 10. PROFITABILITAS PROYEK
// ============================================================
case 'read_project_profitability':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT p.name, p.contract_value, COALESCE(p.spent, 0) AS spent,
               (p.contract_value - COALESCE(p.spent, 0)) AS margin_val,
               CASE WHEN p.contract_value > 0
                    THEN ((p.contract_value - COALESCE(p.spent, 0)) / p.contract_value) * 100
                    ELSE 0 END AS margin_pct
        FROM projects p
        WHERE p.status = 'Berjalan' AND p.contract_value > 0
          AND (:pid = 0 OR p.id = :pid)
        ORDER BY margin_pct ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':pid' => $pid]);
    out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ============================================================
default:
    out(['success' => false, 'message' => 'Aksi tidak ditemukan']);
}