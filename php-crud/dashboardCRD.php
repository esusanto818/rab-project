<?php
// php-crud/dashboardCRD.php
// Versi diperbaiki: menggunakan nama tabel/kolom yang sesuai skema DB Anda
// [REVISI 2026-10-30] disesuaikan dengan alur proyek:
//   - Material Out / Performa Keuangan TIDAK lagi menghitung issue Retur Supplier (material_out_header.issue_type = 'SUPPLIER_RETURN').
//   - Invoice pipeline & Action Center memakai rumus sisa tagihan yang SAMA dengan Aging (paid_amount vs alokasi pembayaran termin).
//   - Action Center ditulis ulang: daftar dinamis (items) sesuai alur PO -> GRN -> Invoice -> Retur -> Termin -> Subkon -> Opname.
//     Tabel modul baru (subcon_*, stock_opname_hdr) dicek keberadaannya; bila belum dimigrasi, indikatornya otomatis disembunyikan.
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
require_once __DIR__ . '/../php-func/project.analysis.php';     // [AI] mesin analisis bersama (dipakai juga oleh project_paymentCRD.php)
require_once __DIR__ . '/../cron/fetch_kurs_pajak.php';

function out(array $data, int $flags = 0): void {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Kebijakan dashboard: setiap akun yang login boleh membaca ringkasan SELURUH proyek perusahaan.
// Jangan gunakan FormAccess modul aktif untuk menyaring data dashboard; hak tulis tetap diperiksa per aksi.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
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

const SRC_SUBCON       = true;  // subcon_progress (prestasi subkon disetujui = biaya proyek, basis akrual)

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
    // Dashboard API tidak melalui otorisasi menu biasa; cek hak khusus Master Kurs Pajak
    // langsung dari tabel hak akses user agar FormAccess menu sebelumnya tidak bisa dipakai ulang.
    $taxAccessStmt = $db->prepare("
        SELECT COALESCE(MAX(u.access_level), 0)
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

    // [REVISI] hutang prestasi subkon (nilai bayar - yang sudah dibayar) ikut ke Hutang (AP)
    if (tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_payments')) {
        $apInner .= "
            UNION ALL
            SELECT (sp.net_payable - COALESCE((SELECT SUM(y.amount) FROM subcon_payments y WHERE y.progress_id = sp.id AND y.status = 'Posted'), 0)) AS os,
                   (CURRENT_DATE - sp.progress_date) AS age
            FROM subcon_progress sp
            JOIN subcon_contracts sc ON sc.id = sp.contract_id
            WHERE sp.status = 'Disetujui'
              AND (sp.net_payable - COALESCE((SELECT SUM(y.amount) FROM subcon_payments y WHERE y.progress_id = sp.id AND y.status = 'Posted'), 0)) > 0.005"
            . ($projectId ? " AND sc.project_id = :pid" : "");
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
             AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type, 'PROJECT') = 'PROJECT'"
            . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
        unset($bag);
    }
    if (SRC_SUBCON && tbl_exists($db, 'subcon_progress')) {      // biaya subkon = prestasi disetujui (akrual)
        $bag = [];
        $sources[] = ['type' => 'exp', 'name' => 'subcon_progress', 'params' => &$bag, 'sql' =>
            "SELECT EXTRACT(MONTH FROM sp.progress_date)::int AS bln, sp.gross_amount AS amount
             FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
             WHERE sp.status = 'Disetujui' AND sp.progress_date >= CAST({$mk($from, $bag)} AS date) AND sp.progress_date < CAST({$mk($to, $bag)} AS date)"
            . ($projectId ? " AND sc.project_id = {$mk($projectId, $bag)}" : "")];
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
// 7. INVOICE PIPELINE (tagihan termin klien) -- rumus sisa SAMA dengan Aging
// ============================================================
case 'read_invoice_pipeline':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT st AS status, COUNT(*) AS count, COALESCE(SUM(os), 0) AS outstanding_value
        FROM (
            SELECT " . AR_OS . " AS os,
                   CASE
                       WHEN " . AR_OS . " <= 0.005 THEN 'Lunas'
                       WHEN pinv.due_date IS NOT NULL AND pinv.due_date < CURRENT_DATE THEN 'Jatuh Tempo (Overdue)'
                       WHEN (pinv.amount - " . AR_OS . ") > 0.005 THEN 'Sebagian'
                       ELSE 'Belum Bayar'
                   END AS st
            FROM project_invoices pinv
            WHERE (:pid = 0 OR pinv.project_id = :pid)
        ) t
        GROUP BY st
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[dashboardCRD] invoice_pipeline: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query invoice pipeline gagal: ' . $e->getMessage()]);
    }
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
// 8. ACTION CENTER -- daftar indikator dinamis sesuai alur proyek
//    level: danger (harus segera) | warn (menunggu keputusan / dokumen) | info (perlu dilengkapi)
// ============================================================
case 'read_action_center':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $items = [];
    $add = function (string $key, string $label, string $level, string $sql, array $params, string $hint = '') use ($db, &$items) {
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            $items[] = ['key' => $key, 'label' => $label, 'level' => $level, 'count' => (int)$st->fetchColumn(), 'hint' => $hint];
        } catch (Exception $e) {
            error_log('[dashboardCRD] action_center ' . $key . ': ' . $e->getMessage());
        }
    };
    $P = [':pid' => $pid];

    // --- Penjualan / termin klien
    $add('inv_overdue', 'Invoice Klien Jatuh Tempo', 'danger',
        "SELECT COUNT(*) FROM project_invoices pinv WHERE pinv.due_date < CURRENT_DATE AND " . AR_OS . " > 0.005 AND (:pid = 0 OR pinv.project_id = :pid)", $P,
        'Sisa tagihan termin sudah lewat jatuh tempo');
    $add('inv_due_soon', 'Invoice Klien Jatuh Tempo 7 Hari', 'warn',
        "SELECT COUNT(*) FROM project_invoices pinv WHERE pinv.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 7 AND " . AR_OS . " > 0.005 AND (:pid = 0 OR pinv.project_id = :pid)", $P,
        'Siapkan penagihan');
    $add('unbilled', 'Proyek Berjalan Belum Ditagih', 'info',
        "SELECT COUNT(*) FROM projects p WHERE p.status = 'Berjalan' AND p.contract_value > 0
            AND NOT EXISTS (SELECT 1 FROM project_invoices WHERE project_id = p.id) AND (:pid = 0 OR p.id = :pid)", $P,
        'Belum ada termin yang diterbitkan');
    $add('faktur_missing', 'Faktur Pajak Belum Diisi', 'info',
        "SELECT (SELECT COUNT(*) FROM project_invoices WHERE COALESCE(ppn_amount,0) > 0 AND COALESCE(faktur_pajak_no,'') = '' AND (:pid = 0 OR project_id = :pid))
              + (SELECT COUNT(*) FROM purchase_invoice_hdr h WHERE COALESCE(h.ppn_amount,0) > 0 AND COALESCE(h.faktur_pajak_no,'') = ''
                    AND COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
                    AND (:pid = 0 OR EXISTS (SELECT 1 FROM purchase_invoice_dtl d JOIN grn_hdr g ON g.id = d.grn_hdr_id JOIN purchase_orders po ON po.id = g.po_id
                                              WHERE d.invoice_hdr_id = h.id AND po.project_id = :pid)))", $P,
        'Invoice ber-PPN (keluaran & masukan) tanpa nomor faktur pajak');

    // --- Pembelian: PO -> GRN -> Invoice -> Retur
    $add('rab_need_po', 'Baris RAB Material Belum di-PO', 'warn',
        "SELECT COUNT(*) FROM budget_items b JOIN projects p ON p.id = b.project_id
          WHERE p.status IN ('Belum Mulai', 'Berjalan') AND b.material_id IS NOT NULL AND COALESCE(b.qty_plan,0) > 0
            AND (LOWER(b.category) = 'material' OR b.material_id IS NOT NULL)
            AND COALESCE(b.qty_plan,0) - COALESCE((SELECT SUM(i.qty) FROM po_items i JOIN purchase_orders po ON po.id = i.po_id
                                                   WHERE i.budget_item_id = b.id AND po.status <> 'Dibatalkan'), 0) > 0.0005
            AND (:pid = 0 OR p.id = :pid)", $P,
        'Qty RAB belum seluruhnya dipesan lewat PO');
    $add('po_waiting', 'PO Menunggu Persetujuan', 'warn',
        "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Diajukan' AND (:pid = 0 OR project_id = :pid)", $P,
        'Status Diajukan, menunggu disetujui');
    $add('po_draft', 'PO Masih Draft', 'info',
        "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Draft' AND (:pid = 0 OR project_id = :pid)", $P,
        'Belum diajukan');
    $add('po_unreceived', 'PO Disetujui Belum Diterima', 'info',
        "SELECT COUNT(*) FROM purchase_orders po WHERE po.status = 'Disetujui' AND (:pid = 0 OR po.project_id = :pid)
            AND NOT EXISTS (SELECT 1 FROM grn_hdr g WHERE g.po_id = po.id AND COALESCE(LOWER(g.status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed'))", $P,
        'Belum ada GRN untuk PO ini');
    $add('grn_uninvoiced', 'GRN Belum Di-invoice Supplier', 'warn',
        "SELECT COUNT(*) FROM grn_hdr g JOIN purchase_orders po ON po.id = g.po_id
          WHERE COALESCE(LOWER(g.status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')
            AND NOT EXISTS (SELECT 1 FROM purchase_invoice_dtl d WHERE d.grn_hdr_id = g.id)
            AND (:pid = 0 OR po.project_id = :pid)", $P,
        'Barang sudah diterima, tagihan supplier belum dicatat');
    if (tbl_exists($db, 'supplier_return_hdr')) {
        $add('return_open', 'Retur Supplier Belum Diselesaikan', 'warn',
            "SELECT COUNT(*) FROM supplier_return_hdr WHERE status = 'Dikirim' AND (:pid = 0 OR project_id = :pid)", $P,
            'Sudah dikirim, belum diselesaikan (ganti barang / kredit)');
    }

    // --- Subkon & stok (modul baru)
    if (tbl_exists($db, 'subcon_contracts') && tbl_exists($db, 'subcon_progress')) {
        $add('subcon_pending', 'SPK / Prestasi Subkon Menunggu Persetujuan', 'warn',
            "SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE status = 'Diajukan' AND (:pid = 0 OR project_id = :pid))
                  + (SELECT COUNT(*) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sp.status = 'Draft' AND (:pid = 0 OR sc.project_id = :pid))", $P,
            'SPK Diajukan atau prestasi Draft');
    }
    if (tbl_exists($db, 'stock_opname_hdr')) {
        $add('opname_pending', 'Stock Opname Menunggu Persetujuan', 'warn',
            "SELECT COUNT(*) FROM stock_opname_hdr WHERE status = 'Diajukan'", [], 'Selisih belum diposting');
    }

    // --- Pelaksanaan proyek
    if (tbl_exists($db, 'project_tasks')) {
        $add('tasks_late', 'Task Proyek Terlambat', 'danger',
            "SELECT COUNT(*) FROM project_tasks t JOIN projects p ON p.id = t.project_id
              WHERE t.end_date < CURRENT_DATE AND COALESCE(t.status,'') NOT IN ('Selesai','Closed','Done') AND p.status = 'Berjalan' AND (:pid = 0 OR t.project_id = :pid)", $P,
            'Lewat tanggal selesai, belum Selesai');
    }
    $add('no_material', 'Proyek Berjalan Belum Ada Pengeluaran Material', 'info',
        "SELECT COUNT(*) FROM projects p WHERE p.status = 'Berjalan' AND (p.start_date IS NULL OR p.start_date <= CURRENT_DATE)
            AND NOT EXISTS (SELECT 1 FROM material_out_header h WHERE h.project_id = p.id AND h.status = 'posted' AND COALESCE(h.issue_type,'PROJECT') = 'PROJECT')
            AND (:pid = 0 OR p.id = :pid)", $P,
        'Sudah mulai, tetapi belum ada issue material');

    out(['success' => true, 'items' => $items]);
    break;

// ============================================================
// 9. MILESTONE (task proyek) 14 hari ke depan + yang terlambat
// ============================================================
case 'read_upcoming_milestones':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $sql = "
        SELECT p.name AS project_name, t.task_name, t.end_date AS deadline, COALESCE(t.status, '-') AS status,
               (t.end_date - CURRENT_DATE) AS days_left,
               (t.end_date < CURRENT_DATE) AS late
        FROM public.project_tasks t
        JOIN public.projects p ON p.id = t.project_id
        WHERE t.end_date <= CURRENT_DATE + INTERVAL '14 days'
          AND COALESCE(t.status, '') NOT IN ('Selesai', 'Closed', 'Done')
          AND p.status = 'Berjalan'
          AND (:pid = 0 OR t.project_id = :pid)
        ORDER BY t.end_date ASC
        LIMIT 12
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['days_left'] = (int)$r['days_left']; $r['late'] = ($r['late'] === true || $r['late'] === 't' || $r['late'] === '1'); }
        unset($r);
        out(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        error_log('[dashboardCRD] milestones: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query milestone gagal: ' . $e->getMessage()]);
    }
    break;

// ============================================================
// 10. PROFITABILITAS PROYEK -- dari biaya riil
//     biaya = material out proyek (tanpa retur supplier) + pengeluaran (financial_expenses) + prestasi subkon disetujui
//     kontrak (DPP) = nilai kontrak, dibagi (1 + PPN) bila proyek 'sudah termasuk PPN'
//     margin = (tertagih DPP - biaya) / tertagih DPP ; biaya/kontrak = biaya / kontrak DPP
// ============================================================
case 'read_project_profitability':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $subSql = $hasSub
        ? "COALESCE((SELECT SUM(sp.gross_amount) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
                      WHERE sc.project_id = p.id AND sp.status = 'Disetujui'), 0)"
        : "0";
    $sql = "
        SELECT x.*,
               (x.cost_material + x.cost_expense + x.cost_subcon) AS cost_total
        FROM (
            SELECT p.id, p.name, p.status, COALESCE(p.progress, 0) AS progress, p.end_date,
                   CASE WHEN COALESCE(p.ppn_inclusive, false) AND COALESCE(p.ppn_rate, 0) > 0
                        THEN p.contract_value / (1 + p.ppn_rate / 100) ELSE p.contract_value END AS contract_dpp,
                   COALESCE((SELECT SUM(COALESCE(v.dpp_amount, v.amount)) FROM project_invoices v WHERE v.project_id = p.id), 0) AS billed_dpp,
                   COALESCE((SELECT SUM(ppi.amount_paid) FROM project_payment_items ppi JOIN project_invoices v ON v.id = ppi.invoice_id WHERE v.project_id = p.id), 0) AS collected,
                   COALESCE((SELECT SUM(i.qty_out * i.unit_cost) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id
                              WHERE h.project_id = p.id AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type,'PROJECT') = 'PROJECT'), 0) AS cost_material,
                   COALESCE((SELECT SUM(e.amount) FROM financial_expenses e WHERE e.project_id = p.id
                              AND COALESCE(UPPER(e.status),'') NOT IN ('VOID','CANCELLED','CANCELED','BATAL')), 0) AS cost_expense,
                   {$subSql} AS cost_subcon
            FROM projects p
            WHERE p.status IN ('Berjalan', 'Selesai') AND p.contract_value > 0 AND (:pid = 0 OR p.id = :pid)
        ) x
        ORDER BY (x.cost_material + x.cost_expense + x.cost_subcon) / NULLIF(x.contract_dpp, 0) DESC NULLS LAST
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[dashboardCRD] profitability: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query profitabilitas gagal: ' . $e->getMessage()]);
    }
    foreach ($rows as &$r) {
        $contract = (float)$r['contract_dpp']; $billed = (float)$r['billed_dpp']; $cost = (float)$r['cost_total'];
        $r['contract_dpp'] = round($contract, 2); $r['billed_dpp'] = round($billed, 2); $r['cost_total'] = round($cost, 2);
        $r['collected'] = round((float)$r['collected'], 2);
        foreach (['cost_material', 'cost_expense', 'cost_subcon'] as $k) $r[$k] = round((float)$r[$k], 2);
        $r['cost_pct']   = $contract > 0 ? round($cost / $contract * 100, 1) : 0;
        $r['margin_pct'] = $billed > 0 ? round(($billed - $cost) / $billed * 100, 1) : null;     // null = belum ada tagihan
        $r['progress']   = (int)$r['progress'];
    }
    unset($r);
    out(['success' => true, 'data' => $rows]);
    break;

// ============================================================
// 11. RINGKASAN TAMBAHAN: saldo kas & bank, PPN bulan berjalan, subkon
// ============================================================
case 'read_extra':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $res = ['cash' => null, 'ppn' => null, 'subcon' => null];
    try {      // saldo kas & bank dari jurnal terposting (semua proyek; kas tidak per proyek)
        $st = $db->query("SELECT COALESCE(SUM(l.debit - l.credit), 0)
                            FROM journal_lines l
                            JOIN journal_entries j ON j.id = l.journal_id
                            JOIN chart_of_accounts a ON a.id = l.account_id
                           WHERE a.account_type::text IN ('cash', 'bank') AND j.status::text NOT IN ('draft', 'void', 'reversed')");
        $res['cash'] = (float)$st->fetchColumn();
    } catch (Exception $e) { error_log('[dashboardCRD] extra cash: ' . $e->getMessage()); }
    try {      // PPN bulan berjalan menurut tanggal faktur (bila kosong tanggal invoice), sama dengan Laporan PPN
        $from = date('Y-m-01'); $to = date('Y-m-d', strtotime($from . ' +1 month'));
        $st = $db->prepare("SELECT COALESCE(SUM(ppn_amount), 0) FROM project_invoices
                             WHERE ppn_amount > 0 AND COALESCE(faktur_pajak_date, invoice_date) >= CAST(:f AS date) AND COALESCE(faktur_pajak_date, invoice_date) < CAST(:t AS date)
                               AND (:pid = 0 OR project_id = :pid)");
        $st->execute([':f' => $from, ':t' => $to, ':pid' => $pid]);
        $keluaran = (float)$st->fetchColumn();
        $st = $db->prepare("
            SELECT COALESCE(SUM(h.ppn_amount * COALESCE(sh.share, 1)), 0)
              FROM purchase_invoice_hdr h
              LEFT JOIN (SELECT d.invoice_hdr_id,
                                SUM(CASE WHEN po.project_id = :pid THEN d.matched_amount ELSE 0 END) / NULLIF(SUM(d.matched_amount), 0) AS share
                           FROM purchase_invoice_dtl d JOIN grn_hdr g ON g.id = d.grn_hdr_id JOIN purchase_orders po ON po.id = g.po_id
                          GROUP BY d.invoice_hdr_id) sh ON sh.invoice_hdr_id = h.id
             WHERE h.ppn_amount > 0 AND COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
               AND COALESCE(h.faktur_pajak_date, h.invoice_date) >= CAST(:f AS date) AND COALESCE(h.faktur_pajak_date, h.invoice_date) < CAST(:t AS date)
               AND (:pid = 0 OR COALESCE(sh.share, 0) > 0)");
        $st->execute([':f' => $from, ':t' => $to, ':pid' => $pid]);
        $masukan = (float)$st->fetchColumn();
        $res['ppn'] = ['keluaran' => round($keluaran, 2), 'masukan' => round($masukan, 2), 'net' => round($keluaran - $masukan, 2), 'periode' => date('m/Y')];
    } catch (Exception $e) { error_log('[dashboardCRD] extra ppn: ' . $e->getMessage()); }
    if (tbl_exists($db, 'subcon_contracts') && tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_payments')) {
        try {
            $st = $db->prepare("
                SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE status IN ('Disetujui','Selesai') AND (:pid = 0 OR project_id = :pid)) AS spk_aktif,
                       (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE status IN ('Disetujui','Selesai') AND (:pid = 0 OR project_id = :pid)) AS nilai_spk,
                       (SELECT COALESCE(SUM(sp.gross_amount),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
                         WHERE sp.status = 'Disetujui' AND (:pid = 0 OR sc.project_id = :pid)) AS tertagih,
                       (SELECT COALESCE(SUM(sp.net_payable),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
                         WHERE sp.status = 'Disetujui' AND (:pid = 0 OR sc.project_id = :pid))
                       - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id
                           WHERE y.status = 'Posted' AND y.pay_type = 'PROGRESS' AND (:pid = 0 OR sc.project_id = :pid)) AS sisa_hutang");
            $st->execute([':pid' => $pid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $res['subcon'] = ['spk_aktif' => (int)$r['spk_aktif'], 'nilai_spk' => (float)$r['nilai_spk'], 'tertagih' => (float)$r['tertagih'], 'sisa_hutang' => (float)$r['sisa_hutang']];
        } catch (Exception $e) { error_log('[dashboardCRD] extra subcon: ' . $e->getMessage()); }
    }
    out(['success' => true, 'data' => $res]);
    break;

// ============================================================
// 12. KESEHATAN PROYEK (kartu dashboard) -- status = angka (gap biaya, indeks biaya CPI, jadwal) + HASIL AI TERAKHIR (ai_variance_logs)
//     Aturan tunggal: dash_card_level() di php-func/project.analysis.php. Anggaran memakai kurs anggaran (sama dengan modul Project Progress).
// ============================================================
case 'read_project_health':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $subSql = $hasSub
        ? "COALESCE((SELECT SUM(sp.gross_amount) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id
                      WHERE sc.project_id = p.id AND sp.status = 'Disetujui'), 0)" : "0";
    $sql = "
        SELECT p.id, p.name, COALESCE(p.progress, 0) AS progress, p.start_date, p.end_date,
               COALESCE(NULLIF(p.budget * COALESCE(NULLIF(p.budget_exch_rate, 0), 1), 0),
                        (SELECT SUM(b.qty_plan * b.unit_price_plan) FROM budget_items b WHERE b.project_id = p.id), 0) AS budget,
               COALESCE((SELECT SUM(i.qty_out * i.unit_cost) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id
                          WHERE h.project_id = p.id AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type,'PROJECT') = 'PROJECT'), 0) AS cost_material,
               COALESCE((SELECT SUM(e.amount) FROM financial_expenses e WHERE e.project_id = p.id
                          AND COALESCE(UPPER(e.status),'') NOT IN ('VOID','CANCELLED','CANCELED','BATAL')), 0) AS cost_expense,
               {$subSql} AS cost_subcon
        FROM projects p
        WHERE p.status = 'Berjalan' AND (:pid = 0 OR p.id = :pid)
    ";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $pid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[dashboardCRD] project_health: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Query kesehatan proyek gagal: ' . $e->getMessage()]);
    }
    $aiMap = [];       // hasil AI terbaru per proyek (hanya baris format baru, masih berlaku AI_CARD_VALID_DAYS hari)
    if (avl_has_cols($db)) {
        try {
            $st = $db->prepare("SELECT DISTINCT ON (project_id) project_id, risk_level, created_at FROM ai_variance_logs
                                 WHERE analysis IS NOT NULL AND created_at > NOW() - (:d || ' days')::interval
                                 ORDER BY project_id, created_at DESC, id DESC");
            $st->execute([':d' => (string)AI_CARD_VALID_DAYS]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $aiMap[(int)$r['project_id']] = $r;
        } catch (Exception $e) { error_log('[dashboardCRD] health ai map: ' . $e->getMessage()); }
    }
    $data = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; $progress = (int)$r['progress']; $budget = (float)$r['budget'];
        $cm = (float)$r['cost_material']; $ce = (float)$r['cost_expense']; $cs = (float)$r['cost_subcon'];
        $cost = $cm + $ce + $cs;
        $lv = dash_card_level($progress, $budget, $cost, $r['end_date'], $aiMap[$id]['risk_level'] ?? null);
        $elapsed = null;
        if ($r['start_date'] && $r['end_date']) {
            $sd = new DateTime($r['start_date']); $ed = new DateTime($r['end_date']); $span = (int)$sd->diff($ed)->format('%r%a');
            if ($span > 0) $elapsed = max(0, min(100, ((int)$sd->diff(new DateTime('today'))->format('%r%a')) / $span * 100));
        }
        $data[] = ['id' => $id, 'name' => $r['name'], 'progress' => $progress, 'usage_pct' => $lv['usage'], 'gap' => $lv['gap'], 'level' => $lv['level'],
                   'reasons' => $lv['reasons'], 'cpi' => $lv['cpi'], 'late_days' => $lv['late_days'],
                   'ai_risk' => $aiMap[$id]['risk_level'] ?? null, 'ai_at' => $aiMap[$id]['created_at'] ?? null,
                   'budget' => round($budget, 2), 'cost_total' => round($cost, 2), 'cost_material' => round($cm, 2), 'cost_expense' => round($ce, 2),
                   'cost_subcon' => round($cs, 2), 'elapsed_pct' => $elapsed === null ? null : round($elapsed, 1)];
    }
    $order = ['KRITIS' => 0, 'WASPADA' => 1, 'NA' => 2, 'NORMAL' => 3];
    usort($data, function ($a, $b) use ($order) { return ($order[$a['level']] <=> $order[$b['level']]) ?: (($b['gap'] ?? -999) <=> ($a['gap'] ?? -999)); });
    out(['success' => true, 'data' => $data, 'thresholds' => ['warn' => HEALTH_WARN_GAP, 'crit' => HEALTH_CRIT_GAP, 'cpi_red' => 0.80, 'cpi_yellow' => 0.90]]);
    break;

// ============================================================
// 13. REKOMENDASI AI PER PROYEK (dipanggil saat kartu Kesehatan Proyek diklik / Analisis Ulang)
//     Param: project_id (wajib), refresh=1 (analisis ulang). Hasil SELALU disimpan ke ai_variance_logs (sumber tunggal dashboard + Pembayaran Termin).
// ============================================================
case 'read_project_ai':
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $refresh = (int)($_REQUEST['refresh'] ?? 0) === 1;
    if ($pid <= 0) out(['success' => false, 'message' => 'project_id wajib diisi.']);

    // Melihat hasil analisis tersimpan adalah hak baca dashboard. Membuat analisis
    // baru / analisis ulang menyimpan ai_variance_logs, sehingga memerlukan level 3
    // pada menu Pembayaran Proyek. Hak dicek dari tabel user-menu, bukan FormAccess
    // sesi yang dapat berasal dari menu terakhir dibuka.
    $aiAccessStmt = $db->prepare("
        SELECT COALESCE(MAX(u.access_level), 0)
          FROM m_form_name f
          JOIN m_user_access_menu u ON u.form_id = f.id
         WHERE u.user_id = :uid
           AND LOWER(REGEXP_REPLACE(f.php_url, '^.*/', '')) = 'project.payment.php'
    ");
    $aiAccessStmt->execute([':uid' => (int)$_SESSION['user_id']]);
    $aiAccessLevel = (int)$aiAccessStmt->fetchColumn();
    if ($refresh && $aiAccessLevel < 3) {
        http_response_code(403);
        out(['success' => false, 'message' => 'Anda dapat melihat hasil analisis yang tersimpan. Hak Analisis Ulang memerlukan level 3 pada menu Pembayaran Proyek.']);
    }

    try {
        if (!$refresh) {
            // Mode baca murni: jangan memicu analisis baru untuk pengguna dashboard read-only.
            $hasAnalysis = avl_has_cols($db);
            $analysisCol = $hasAnalysis ? ', analysis' : '';
            $st = $db->prepare("SELECT id, risk_level, ai_summary, recommendations, created_at{$analysisCol}
                                  FROM ai_variance_logs
                                 WHERE project_id = :pid
                                 ORDER BY created_at DESC, id DESC
                                 LIMIT 1");
            $st->execute([':pid' => $pid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                out(['success' => false, 'message' => 'Belum ada hasil analisis tersimpan untuk proyek ini. Pengguna dengan level 3 pada menu Pembayaran Proyek dapat menjalankan Analisis Ulang.']);
            }

            $result = [];
            if ($hasAnalysis && !empty($row['analysis'])) {
                $decoded = json_decode((string)$row['analysis'], true);
                if (is_array($decoded)) $result = $decoded;
            }
            if (!$result) {
                $projectStmt = $db->prepare("SELECT name FROM projects WHERE id = :pid");
                $projectStmt->execute([':pid' => $pid]);
                $projectName = (string)($projectStmt->fetchColumn() ?: 'Proyek');
                $actions = json_decode((string)($row['recommendations'] ?? '[]'), true);
                if (!is_array($actions)) $actions = [];
                $result = [
                    'project_id' => $pid, 'project_name' => $projectName,
                    'risk' => strtoupper((string)($row['risk_level'] ?? 'LOW')),
                    'headline' => (string)($row['ai_summary'] ?? 'Hasil analisis tersimpan'),
                    'summary' => (string)($row['ai_summary'] ?? ''),
                    'actions' => $actions, 'questions' => [], 'findings' => [],
                    'ai_used' => false, 'ai_note' => 'Menampilkan ringkasan tersimpan dari format log lama.',
                ];
            }
            $result['log_id'] = (int)$row['id'];
            $result['generated_at'] = $row['created_at'];
            $result['cached'] = true;
            $result['can_refresh'] = ($aiAccessLevel >= 3);
            out(['success' => true, 'data' => $result]);
        }

        $result = dash_run_analysis($db, $pid, true, 'dashboard', (int)($_SESSION['user_id'] ?? 0) ?: null);
        $result['can_refresh'] = true;
        out(['success' => true, 'data' => $result]);
    } catch (Exception $e) {
        error_log('[dashboardCRD] project_ai: ' . $e->getMessage());
        out(['success' => false, 'message' => 'Analisis proyek gagal: ' . $e->getMessage()]);
    }
    break;

// ============================================================
default:
    out(['success' => false, 'message' => 'Aksi tidak ditemukan']);
}