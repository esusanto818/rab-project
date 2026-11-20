<?php
// php-crud/dashboardCRD.php
// Versi diperbaiki: menggunakan nama tabel/kolom yang sesuai skema DB Anda
// [REVISI 2026-10-30] disesuaikan dengan alur proyek:
//   - Material Out / Performa Keuangan TIDAK lagi menghitung issue Retur Supplier (material_out_header.issue_type = 'SUPPLIER_RETURN').
//   - Invoice pipeline & Action Center memakai rumus sisa tagihan yang SAMA dengan Aging (paid_amount vs alokasi pembayaran termin).
//   - Action Center ditulis ulang: daftar dinamis (items) sesuai alur PO -> GRN -> Invoice -> Retur -> Termin -> Subkon -> Opname.
//   - 'Material Butuh PO' memakai po_items (sebelumnya purchase_order_items yang tidak ada -> selalu 0 / error log).
//   - 'PO Menunggu Approval' = status Diajukan (bukan Draft) dan ikut filter proyek.
//   - Profitabilitas dihitung dari biaya riil (material out proyek + pengeluaran + prestasi subkon), bukan kolom projects.spent.
//   - Aging Hutang (AP) menyertakan hutang prestasi subkon. Milestone menampilkan task terlambat.
//   - Action baru read_extra: saldo kas & bank, PPN bulan berjalan, ringkasan subkon.
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

// A valid login is not sufficient if the account has since been deactivated.
if (function_exists('app_require_active_user')) {
    app_require_active_user();
}
require_once __DIR__ . '/../php-func/project.analysis.php';     // [AI] mesin analisis bersama (dipakai juga oleh project_paymentCRD.php)
require_once __DIR__ . '/../cron/fetch_kurs_pajak.php';

function out(array $data, int $flags = 0): void {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Kebijakan dashboard: setiap akun yang login boleh membaca ringkasan SELURUH proyek perusahaan.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    out(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
}

const SRC_FIN_INCOME   = true;  // financial_incomes
const SRC_PRJ_PAYMENT  = true;  // project_payment_items (pembayaran termin klien)
const SRC_FIN_EXPENSE  = true;  // financial_expenses
const SRC_MATERIAL_OUT = true;  // material_out_items x unit_cost (biaya material ke proyek)
const SRC_MATERIAL_IN  = false; // material_receipt_items
const SRC_SUBCON       = true;  // subcon_progress

$db     = new crud();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

// ============================================================
// 1. DAFTAR PROYEK (dropdown filter)
//    Mengembalikan key: id, project_name (atau name)
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
// 2. KURS PAJAK KMK TERBARU (Action: get_tax_rates)
//    Mendukung parameter year via GET
// ============================================================
case 'get_tax_rates':
case 'read_tax_rates':
    try {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $taxReadAccessStmt = $db->prepare("
            SELECT COALESCE(MIN(u.access_level), 0)
              FROM m_form_name f
              JOIN m_user_access_menu u ON u.form_id = f.id
             WHERE u.user_id = :uid
               AND LOWER(REGEXP_REPLACE(f.php_url, '^.*/', '')) = 'm.taxexchangerate.php'
        ");
        $taxReadAccessStmt->execute([':uid' => (int)$_SESSION['user_id']]);
        $canSyncTax = ((int)$taxReadAccessStmt->fetchColumn() >= 3);

        $stmt = $db->prepare("SELECT id, kmk_no, start_date, end_date FROM tax_exchange_rate_hdr WHERE EXTRACT(YEAR FROM start_date) = :yr ORDER BY id DESC LIMIT 1");
        $stmt->execute([':yr' => $year]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            // Coba ambil header terakhir tanpa filter tahun jika kosong
            $stmt = $db->prepare("SELECT id, kmk_no, start_date, end_date FROM tax_exchange_rate_hdr ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$header) {
            out(['success' => true, 'kmk_number' => '-', 'start_date' => '-', 'end_date' => '-', 'data' => [], 'rates' => [], 'can_sync' => $canSyncTax]);
        }
        $stmt = $db->prepare("
            SELECT currency_code, currency_name, unit_multiplier, rate AS rate_idr, change_amount, change_direction
            FROM tax_exchange_rate_dtl
            WHERE hdr_id = :hdr
            ORDER BY currency_code ASC
        ");
        $stmt->bindValue(':hdr', (int)$header['id'], PDO::PARAM_INT);
        $stmt->execute();
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        out([
            'success'     => true,
            'kmk_number'  => $header['kmk_no'],
            'sk_minister_no' => $header['kmk_no'],
            'start_date'  => date('Y-m-d', strtotime($header['start_date'])),
            'end_date'    => date('Y-m-d', strtotime($header['end_date'])),
            'data'        => $rates,
            'rates'       => $rates,
            'can_sync'    => $canSyncTax,
        ], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal membaca kurs pajak: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 3. SYNC KURS DARI BEA CUKAI
// ============================================================
case 'sync_tax_rates':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        out(['success' => false, 'message' => 'Sinkronisasi kurs pajak wajib menggunakan POST.']);
    }
    $taxAccessStmt = $db->prepare("
        SELECT COALESCE(MIN(u.access_level), 0)
          FROM m_form_name f
          JOIN m_user_access_menu u ON u.form_id = f.id
         WHERE u.user_id = :uid
           AND LOWER(REGEXP_REPLACE(f.php_url, '^.*/', '')) = 'm.taxexchangerate.php'
    ");
    $taxAccessStmt->execute([':uid' => (int)$_SESSION['user_id']]);
    if ((int)$taxAccessStmt->fetchColumn() < 3) {
        http_response_code(403);
        out(['success' => false, 'message' => 'Anda tidak memiliki hak untuk memperbarui Kurs Pajak.']);
    }
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
// 4. FINANCIAL AGING (AR & AP) / get_aging_data
// ============================================================
case 'get_aging_data':
case 'read_financial_aging':
    $projectId = (int)($_GET['project_id'] ?? 0);
    try {
        $rows = [];
        // AR from project_invoices
        $arSql = "
            SELECT 'AR' AS doc_type,
                   COALESCE(c.name, 'Klien Umum') AS partner_name,
                   pinv.invoice_no AS doc_no,
                   pinv.invoice_date AS doc_date,
                   pinv.due_date AS due_date,
                   pinv.amount AS total_amount,
                   (pinv.amount - GREATEST(pinv.paid_amount, COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) AS outstanding_amount,
                   (CURRENT_DATE - pinv.invoice_date) AS aging_days,
                   CASE
                       WHEN (CURRENT_DATE - pinv.invoice_date) <= 30 THEN '0-30 Hari'
                       WHEN (CURRENT_DATE - pinv.invoice_date) <= 60 THEN '31-60 Hari'
                       WHEN (CURRENT_DATE - pinv.invoice_date) <= 90 THEN '61-90 Hari'
                       ELSE '> 90 Hari'
                   END AS aging_bucket
            FROM project_invoices pinv
            LEFT JOIN projects pr ON pr.id = pinv.project_id
            LEFT JOIN clients c ON c.id = pr.client_id
            WHERE (pinv.amount - GREATEST(pinv.paid_amount, COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) > 0.005
              AND pinv.release_status = 'released'
        " . ($projectId ? " AND pinv.project_id = :pid" : "");

        $stmtAr = $db->prepare($arSql);
        if ($projectId) $stmtAr->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stmtAr->execute();
        $rows = array_merge($rows, $stmtAr->fetchAll(PDO::FETCH_ASSOC));

        // AP from purchase_invoice_hdr
        $apSql = "
            SELECT 'AP' AS doc_type,
                   COALESCE(s.supplier_name, 'Supplier Umum') AS partner_name,
                   h.invoice_no AS doc_no,
                   h.invoice_date AS doc_date,
                   h.due_date AS due_date,
                   h.total_amount AS total_amount,
                   (h.total_amount - h.paid_amount) AS outstanding_amount,
                   (CURRENT_DATE - h.invoice_date) AS aging_days,
                   CASE
                       WHEN (CURRENT_DATE - h.invoice_date) <= 30 THEN '0-30 Hari'
                       WHEN (CURRENT_DATE - h.invoice_date) <= 60 THEN '31-60 Hari'
                       WHEN (CURRENT_DATE - h.invoice_date) <= 90 THEN '61-90 Hari'
                       ELSE '> 90 Hari'
                   END AS aging_bucket
            FROM purchase_invoice_hdr h
            LEFT JOIN suppliers s ON s.id = h.supplier_id
            WHERE (h.total_amount - h.paid_amount) > 0.005
              AND COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
        ";
        $stmtAp = $db->prepare($apSql);
        $stmtAp->execute();
        $rows = array_merge($rows, $stmtAp->fetchAll(PDO::FETCH_ASSOC));

        out(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal membaca data aging: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 5. PERFORMA KEUANGAN BULANAN (Pendapatan vs Pengeluaran)
// ============================================================
case 'get_financial_performance':
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
             AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type, 'PROJECT') = 'PROJECT'"
            . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_SUBCON && tbl_exists($db, 'subcon_progress')) {
        $bag = [];
        $sources[] = ['type' => 'exp', 'name' => 'subcon_progress', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM sp.progress_date)::int AS bln, sp.gross_amount AS amount
             FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
             WHERE sp.status = 'Disetujui' AND sp.progress_date >= CAST({$mk($from, $bag)} AS date) AND sp.progress_date < CAST({$mk($to, $bag)} AS date)"
            . ($projectId ? " AND sc.project_id = {$mk($projectId, $bag)}" : "")];
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
        out(['success' => false, 'message' => 'Query performa keuangan gagal: ' . $e->getMessage()]);
    }

    out([
        'success'         => true,
        'year'            => $year,
        'labels'          => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        'datasets'        => ['pendapatan' => $pendapatan, 'pengeluaran' => $pengeluaran],
    ], JSON_NUMERIC_CHECK);
    break;

// ============================================================
// 6. MONITORING MATERIAL (RAB vs Material Out) / get_material_monitoring
// ============================================================
case 'get_material_monitoring':
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
    $outWhere = "h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type, 'PROJECT') = 'PROJECT'"
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
               COALESCE(b.unit, NULLIF(m.unit,''), '')       AS uom,
               COALESCE(b.qty_plan, 0)                       AS vol_rab,
               COALESCE(b.budget_value, 0)                   AS budget_value,
               COALESCE(o.qty_out, 0)                        AS vol_po,
               COALESCE(o.qty_out, 0)                        AS vol_grn,
               COALESCE(b.budget_value / NULLIF(b.qty_plan, 0), 0) AS unit_price,
               COALESCE(o.out_value, 0)                      AS total_amount,
               'ACTIVE'                                      AS status
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
        out(['success' => true, 'data' => $rows], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Query monitoring material gagal: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 7. ACTION CENTER / get_action_center
// ============================================================
case 'get_action_center':
case 'read_action_center':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $items = [];
    try {
        $st = $db->prepare("SELECT count(*) FROM project_invoices pinv WHERE pinv.due_date < CURRENT_DATE AND pinv.release_status = 'released' AND (pinv.amount - GREATEST(pinv.paid_amount, COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) > 0.005 AND (:pid = 0 OR pinv.project_id = :pid)");
        $st->execute([':pid' => $pid]);
        $cnt = (int)$st->fetchColumn();
        if ($cnt > 0) {
            $items[] = ['title' => 'Invoice Klien Jatuh Tempo', 'description' => "$cnt invoice klien telah melewati tanggal jatuh tempo dan belum lunas.", 'date' => date('Y-m-d')];
        }

        $st = $db->prepare("SELECT count(*) FROM purchase_orders WHERE status = 'Diajukan' AND (:pid = 0 OR project_id = :pid)");
        $st->execute([':pid' => $pid]);
        $cnt = (int)$st->fetchColumn();
        if ($cnt > 0) {
            $items[] = ['title' => 'PO Menunggu Approval', 'description' => "$cnt Purchase Order berstatus Diajukan menunggu persetujuan manajemen.", 'date' => date('Y-m-d')];
        }

        if (tbl_exists($db, 'project_tasks')) {
            $st = $db->prepare("SELECT count(*) FROM project_tasks t JOIN projects p ON p.id = t.project_id WHERE t.end_date < CURRENT_DATE AND COALESCE(t.status,'') NOT IN ('Selesai','Closed','Done') AND p.status = 'Berjalan' AND (:pid = 0 OR t.project_id = :pid)");
            $st->execute([':pid' => $pid]);
            $cnt = (int)$st->fetchColumn();
            if ($cnt > 0) {
                $items[] = ['title' => 'Task Proyek Terlambat', 'description' => "$cnt task proyek melewati tenggat waktu dan belum selesai.", 'date' => date('Y-m-d')];
            }
        }
        out(['success' => true, 'data' => $items]);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal membaca action center: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 8. MILESTONE / get_milestones
// ============================================================
case 'get_milestones':
case 'read_upcoming_milestones':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT t.task_name AS name, t.end_date AS target_date, COALESCE(t.status, 'PENDING') AS status
        FROM public.project_tasks t
        JOIN public.projects p ON p.id = t.project_id
        WHERE (:pid = 0 OR t.project_id = :pid)
        ORDER BY t.end_date ASC
        LIMIT 10
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        out(['success' => false, 'data' => []]);
    }
    break;

// ============================================================
// 9. PROFITABILITAS / get_profitability
// ============================================================
case 'get_profitability':
case 'read_project_profitability':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT b.category AS category_name,
               SUM(b.qty_plan * b.unit_price_plan) AS rab_amount,
               COALESCE((SELECT SUM(i.qty_out * i.unit_cost) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id WHERE h.project_id = b.project_id AND h.status = 'posted'), 0) AS realized_amount,
               (SUM(b.qty_plan * b.unit_price_plan) - COALESCE((SELECT SUM(i.qty_out * i.unit_cost) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id WHERE h.project_id = b.project_id AND h.status = 'posted'), 0)) AS variance
        FROM budget_items b
        WHERE (:pid = 0 OR b.project_id = :pid)
        GROUP BY b.project_id, b.category
        ORDER BY b.category ASC
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => 'Gagal membaca profitabilitas: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 10. KESEHATAN PROYEK / get_project_health
// ============================================================
case 'get_project_health':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    try {
        // Ambil data proyek
        $st = $db->prepare("SELECT * FROM projects WHERE id = :pid");
        $st->execute([':pid' => $pid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            out(['success' => false, 'message' => 'Proyek tidak ditemukan']);
        }

        // Total RAB
        $stRab = $db->prepare("SELECT COALESCE(SUM(qty_plan * unit_price_plan), 0) FROM budget_items WHERE project_id = :pid");
        $stRab->execute([':pid' => $pid]);
        $totalRab = (float)$stRab->fetchColumn();

        // Total Pengeluaran (Realized)
        $stExp = $db->prepare("SELECT COALESCE(SUM(i.qty_out * i.unit_cost), 0) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id WHERE h.project_id = :pid AND h.status = 'posted'");
        $stExp->execute([':pid' => $pid]);
        $totalExp = (float)$stExp->fetchColumn();

        $remaining = $totalRab - $totalExp;
        $physProg = (float)($p['progress'] ?? 0);

        // Hitung count PO, GRN, Inv
        $stPo = $db->prepare("SELECT count(*) FROM purchase_orders WHERE project_id = :pid");
        $stPo->execute([':pid' => $pid]);
        $poCount = (int)$stPo->fetchColumn();

        $stGrn = $db->prepare("SELECT count(*) FROM grn_hdr g JOIN purchase_orders po ON po.id = g.po_id WHERE po.project_id = :pid");
        $stGrn->execute([':pid' => $pid]);
        $grnCount = (int)$stGrn->fetchColumn();

        $stInv = $db->prepare("SELECT count(*) FROM project_invoices WHERE project_id = :pid");
        $stInv->execute([':pid' => $pid]);
        $invCount = (int)$stInv->fetchColumn();

        $healthStatus = 'HEALTHY';
        if ($totalExp > $totalRab && $totalRab > 0) {
            $healthStatus = 'CRITICAL';
        } elseif ($totalExp > $totalRab * 0.85) {
            $healthStatus = 'WARNING';
        }

        out([
            'success' => true,
            'data' => [
                'total_rab' => $totalRab,
                'total_exp' => $totalExp,
                'remaining' => $remaining,
                'physical_progress' => $physProg,
                'po_count' => $poCount,
                'grn_count' => $grnCount,
                'inv_count' => $invCount,
                'health_status' => $healthStatus
            ]
        ], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => $e->getMessage()]);
    }
    break;

// ============================================================
// 11. EXTRA FINANCIALS / get_extra_financials
// ============================================================
case 'get_extra_financials':
    try {
        $stAr = $db->query("SELECT COALESCE(SUM(amount - paid_amount), 0) FROM project_invoices WHERE release_status = 'released'");
        $totalAr = (float)$stAr->fetchColumn();

        $stAp = $db->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM purchase_invoice_hdr WHERE COALESCE(LOWER(status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')");
        $totalAp = (float)$stAp->fetchColumn();

        $netCash = $totalAr - $totalAp;

        out([
            'success' => true,
            'data' => [
                'total_ar' => $totalAr,
                'total_ap' => $totalAp,
                'net_cash' => $netCash
            ]
        ], JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        out(['success' => false, 'message' => $e->getMessage()]);
    }
    break;

default:
    out(['success' => false, 'message' => 'Aksi tidak ditemukan: ' . $action]);
}
