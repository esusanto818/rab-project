<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * ar.agingCRD.php
 * ============================================================
 * Backend Laporan Kartu Piutang & Aging AR per klien (read-only).
 * Front end: js/ar.aging.js, views/ar.aging.php. Menu: m_form_name seq 6038.
 *
 * Sumber data: view v_ar_card / v_ar_aging (sql/2026-11-17.ar_ap_aging_card.sql)
 *   - AR : faktur project_invoices RELEASED saja; aging = COALESCE(due_date, invoice_date) vs CURRENT_DATE.
 *   - Sisa AR = amount - GREATEST(paid_amount, total alokasi project_payment_items).
 *
 * Task : list_clients | list_cards | list_details | export_csv
 * Param: year, month (1-12) untuk kartu; client_id (0=semua) untuk detail;
 *        date_from/date_to (YYYY-MM-DD) untuk aging; query (cari).
 * Respons JSON standar {success, message, data, totalCount}; list_cards menambah 'totals'.
 * ============================================================
 */
ini_set('display_errors', '0');
ob_start();

require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

$db = new crud();
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    rpt_send(false, [], 'Sesi berakhir, silakan login ulang.');
}
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    rpt_send(false, [], 'Hak baca kartu piutang tidak tersedia.');
}

class RptError extends Exception {}

function rpt_send($success, $data = [], $message = '', $extra = []) {
    $resp = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $resp['data'] = $data;
        $resp['totalCount'] = is_array($data) ? count($data) : 0;
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rpt_f($v): float { return round((float)$v, 2); }

/** Rentang periode kartu: [awal bulan, awal bulan berikutnya). */
function rpt_period(): array {
    $y = (int)($_REQUEST['year'] ?? date('Y'));
    $m = (int)($_REQUEST['month'] ?? date('n'));
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) { throw new RptError('Periode tidak valid.'); }
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to   = date('Y-m-d', strtotime($from . ' +1 month'));
    return [$from, $to, $y, $m];
}

/** Rentang tanggal bebas untuk aging detail (default: 60 hari terakhir s.d hari ini). */
function rpt_range(): array {
    $rawFrom = trim((string)($_REQUEST['date_from'] ?? ''));
    $rawTo   = trim((string)($_REQUEST['date_to'] ?? ''));
    $to   = ($rawTo !== '') ? rpt_date($rawTo, 'Tanggal akhir') : date('Y-m-d');
    $from = ($rawFrom !== '') ? rpt_date($rawFrom, 'Tanggal awal') : date('Y-m-d', strtotime('-60 days'));
    if ($from > $to) { throw new RptError('Tanggal awal tidak boleh melebihi tanggal akhir.'); }
    return [$from, $to];
}

function rpt_date($s, $label): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new RptError($label . ' tidak valid (format harus YYYY-MM-DD).');
    }
    return $s;
}

/** Daftar klien untuk combo filter (klien dengan aktivitas piutang saja). */
function rpt_clients($db): array {
    $rows = $db->query("SELECT client_id AS id, client_name AS name FROM public.v_ar_card
                         ORDER BY client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    unset($r);
    array_unshift($rows, ['id' => 0, 'name' => '(Semua klien)']);
    return $rows;
}

/** Kartu piutang per klien pada periode (filter project_id untuk AP tidak dipakai di AR). */
function rpt_cards($db, string $from, string $to): array {
    // View v_ar_card memakai periode bulan berjalan; untuk periode lain hitung ulang
    // dengan formula identik (faktur RELEASED, alokasi item pembayaran, header pembayaran).
    $st = $db->prepare("
        WITH per AS (SELECT CAST(:f AS date) AS ps, CAST(:t AS date) AS pe),
        inv_agg AS (
            SELECT p.client_id,
                   COALESCE(SUM(CASE WHEN v.invoice_date < per.ps THEN v.amount END), 0) AS amt_before,
                   COALESCE(SUM(CASE WHEN v.invoice_date >= per.ps AND v.invoice_date < per.pe THEN v.amount END), 0) AS amt_period,
                   COALESCE(SUM(v.amount), 0) AS amt_total,
                   COUNT(*) AS n_total,
                   MIN(v.invoice_date) AS first_date,
                   MAX(v.invoice_date) AS last_date
              FROM per, public.project_invoices v
              JOIN public.projects p ON p.id = v.project_id
             WHERE v.release_status = 'released'
             GROUP BY p.client_id
        ),
        alloc_agg AS (
            SELECT p.client_id,
                   COALESCE(SUM(CASE WHEN py.payment_date < per.ps THEN i.amount_paid END), 0) AS alloc_before,
                   COALESCE(SUM(CASE WHEN py.payment_date >= per.ps AND py.payment_date < per.pe THEN i.amount_paid END), 0) AS alloc_period,
                   COALESCE(SUM(i.amount_paid), 0) AS alloc_total
              FROM per, public.project_payment_items i
              JOIN public.project_payments py ON py.id = i.payment_id
              JOIN public.project_invoices v  ON v.id = i.invoice_id
              JOIN public.projects p          ON p.id = v.project_id
             WHERE v.release_status = 'released'
             GROUP BY p.client_id
        ),
        pay_agg AS (
            SELECT py.client_id, COALESCE(SUM(py.total_amount), 0) AS paid_period
              FROM per, public.project_payments py
             WHERE py.payment_date >= per.ps AND py.payment_date < per.pe
             GROUP BY py.client_id
        ),
        bucket_agg AS (
            SELECT p.client_id,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) <= 30
                                     THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_0_30,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 31 AND 60
                                     THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_31_60,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 61 AND 90
                                     THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_61_90,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) > 90
                                     THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_90_plus
              FROM public.project_invoices v
              JOIN public.projects p ON p.id = v.project_id
              LEFT JOIN (SELECT invoice_id, SUM(amount_paid) AS alloc FROM public.project_payment_items GROUP BY invoice_id) a
                     ON a.invoice_id = v.id
             WHERE v.release_status = 'released'
             GROUP BY p.client_id
        )
        SELECT c.id AS client_id, c.name AS client_name, per.ps AS period_start, per.pe AS period_end,
               COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0) AS saldo_awal,
               COALESCE(inv.amt_period, 0) AS faktur,
               COALESCE(pay.paid_period, 0) AS pembayaran,
               COALESCE(al.alloc_period, 0) AS alokasi,
               COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0)
                 + COALESCE(inv.amt_period, 0) - COALESCE(al.alloc_period, 0) AS sisa,
               COALESCE(inv.n_total, 0) AS invoice_count,
               inv.first_date AS first_invoice_date, inv.last_date AS last_invoice_date,
               COALESCE(bk.bucket_0_30, 0) AS bucket_0_30,
               COALESCE(bk.bucket_31_60, 0) AS bucket_31_60,
               COALESCE(bk.bucket_61_90, 0) AS bucket_61_90,
               COALESCE(bk.bucket_90_plus, 0) AS bucket_90_plus,
               COALESCE(inv.amt_total, 0) - COALESCE(al.alloc_total, 0) AS outstanding_all
          FROM public.clients c
          CROSS JOIN per
          LEFT JOIN inv_agg   inv ON inv.client_id = c.id
          LEFT JOIN alloc_agg al  ON al.client_id  = c.id
          LEFT JOIN pay_agg   pay ON pay.client_id = c.id
          LEFT JOIN bucket_agg bk ON bk.client_id  = c.id
         WHERE COALESCE(inv.amt_total, 0) <> 0
            OR COALESCE(al.alloc_total, 0) <> 0
            OR COALESCE(pay.paid_period, 0) <> 0
            OR COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0) <> 0
            OR COALESCE(bk.bucket_0_30, 0) + COALESCE(bk.bucket_31_60, 0)
             + COALESCE(bk.bucket_61_90, 0) + COALESCE(bk.bucket_90_plus, 0) <> 0
         ORDER BY c.name ASC");
    $st->execute([':f' => $from, ':t' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['client_id'] = (int)$r['client_id'];
        foreach (['saldo_awal','faktur','pembayaran','alokasi','sisa','bucket_0_30','bucket_31_60','bucket_61_90','bucket_90_plus','outstanding_all'] as $k) {
            $r[$k] = rpt_f($r[$k]);
        }
        $r['invoice_count'] = (int)$r['invoice_count'];
    }
    unset($r);
    return $rows;
}

/** Aging detail per faktur (periode bebas), opsional filter klien. */
function rpt_details($db, string $from, string $to, int $clientId): array {
    $sql = "
        SELECT c.id AS client_id, c.name AS client_name, p.id AS project_id, p.name AS project_name,
               v.id AS invoice_id, v.inv_no, v.termin_name, v.release_no, v.invoice_date,
               COALESCE(v.due_date, v.invoice_date) AS aging_date, v.due_date,
               v.amount AS invoice_amount,
               GREATEST(v.paid_amount, COALESCE(a.alloc, 0)) AS paid_amount,
               (v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0))) AS outstanding,
               (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) AS age_days,
               CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) <= 30
                    THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_0_30,
               CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 31 AND 60
                    THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_31_60,
               CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) BETWEEN 61 AND 90
                    THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_61_90,
               CASE WHEN (CURRENT_DATE - COALESCE(v.due_date, v.invoice_date)) > 90
                    THEN GREATEST(v.amount - GREATEST(v.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_90_plus
          FROM public.project_invoices v
          JOIN public.projects p ON p.id = v.project_id
          LEFT JOIN public.clients c ON c.id = p.client_id
          LEFT JOIN (SELECT invoice_id, SUM(amount_paid) AS alloc
                       FROM public.project_payment_items GROUP BY invoice_id) a ON a.invoice_id = v.id
         WHERE v.release_status = 'released'
           AND v.invoice_date >= CAST(:f AS date) AND v.invoice_date < CAST(:t AS date)";
    $params = [':f' => $from, ':t' => $to];
    if ($clientId > 0) {
        $sql .= " AND p.client_id = :cid";
        $params[':cid'] = $clientId;
    }
    $sql .= " ORDER BY c.name, p.name, v.invoice_date, v.id";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['client_id'] = (int)$r['client_id'];
        $r['project_id'] = (int)$r['project_id'];
        $r['invoice_id'] = (int)$r['invoice_id'];
        $r['age_days'] = (int)$r['age_days'];
        foreach (['invoice_amount','paid_amount','outstanding','bucket_0_30','bucket_31_60','bucket_61_90','bucket_90_plus'] as $k) {
            $r[$k] = rpt_f($r[$k]);
        }
    }
    unset($r);
    return $rows;
}

function rpt_csv_out(string $filename, array $head, array $rows): void {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF" . "sep=;\r\n";
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $head, ';');
    foreach ($rows as $r) { fputcsv($fh, $r, ';'); }
    fclose($fh);
    exit;
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {
        case 'list_clients':
            rpt_send(true, rpt_clients($db));
            break;

        case 'list_cards':
            list ($from, $to) = rpt_period();
            $rows = rpt_cards($db, $from, $to);
            $q = trim((string)($_REQUEST['query'] ?? ''));
            if ($q !== '') {
                $rows = array_values(array_filter($rows, function ($r) use ($q) {
                    return strpos(mb_strtolower((string)$r['client_name']), mb_strtolower($q)) !== false;
                }));
            }
            $tot = ['saldo_awal' => 0.0, 'faktur' => 0.0, 'pembayaran' => 0.0, 'alokasi' => 0.0, 'sisa' => 0.0,
                    'bucket_0_30' => 0.0, 'bucket_31_60' => 0.0, 'bucket_61_90' => 0.0, 'bucket_90_plus' => 0.0,
                    'outstanding_all' => 0.0];
            foreach ($rows as $r) { foreach (array_keys($tot) as $k) { $tot[$k] += $r[$k]; } }
            foreach ($tot as $k => $v) { $tot[$k] = rpt_f($v); }
            rpt_send(true, $rows, '', ['totals' => $tot, 'period' => ['from' => $from, 'to' => $to]]);
            break;

        case 'list_details':
            list ($from, $to) = rpt_range();
            $cid = (int)($_REQUEST['client_id'] ?? 0);
            $rows = rpt_details($db, $from, $to, $cid);
            $q = trim((string)($_REQUEST['query'] ?? ''));
            if ($q !== '') {
                $rows = array_values(array_filter($rows, function ($r) use ($q) {
                    $hay = strtolower(implode(' ', [$r['client_name'], $r['project_name'], $r['inv_no'], $r['termin_name'] ?? '']));
                    return strpos($hay, strtolower($q)) !== false;
                }));
            }
            rpt_send(true, $rows, '', ['period' => ['from' => $from, 'to' => $to]]);
            break;

        case 'export_csv':
            list ($from, $to) = rpt_period();
            $type = (string)($_REQUEST['type'] ?? 'cards');
            $stamp = date('Ym', strtotime($from));
            if ($type === 'details') {
                list ($df, $dt) = rpt_range();
                $cid = (int)($_REQUEST['client_id'] ?? 0);
                $rows = rpt_details($db, $df, $dt, $cid);
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [$r['client_name'], $r['project_name'], $r['inv_no'], $r['termin_name'], $r['invoice_date'],
                              $r['aging_date'], $r['invoice_amount'], $r['paid_amount'], $r['outstanding'], $r['age_days'],
                              $r['bucket_0_30'], $r['bucket_31_60'], $r['bucket_61_90'], $r['bucket_90_plus']];
                }
                rpt_csv_out("ar-aging-detail-{$stamp}.csv",
                    ['Klien', 'Proyek', 'No Invoice', 'Termin', 'Tgl Invoice', 'Tgl Aging', 'Nilai', 'Dibayar', 'Sisa',
                     'Umur (hari)', '0-30', '31-60', '61-90', '>90'], $out);
            }
            $rows = rpt_cards($db, $from, $to);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['client_name'], $r['period_start'], $r['period_end'], $r['saldo_awal'], $r['faktur'],
                          $r['pembayaran'], $r['alokasi'], $r['sisa'], $r['invoice_count'],
                          $r['bucket_0_30'], $r['bucket_31_60'], $r['bucket_61_90'], $r['bucket_90_plus'], $r['outstanding_all']];
            }
            $totRow = ['TOTAL', '', '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
            foreach ($rows as $r) {
                $totRow[3] += $r['saldo_awal']; $totRow[4] += $r['faktur']; $totRow[5] += $r['pembayaran'];
                $totRow[6] += $r['alokasi']; $totRow[7] += $r['sisa']; $totRow[9] += $r['bucket_0_30'];
                $totRow[10] += $r['bucket_31_60']; $totRow[11] += $r['bucket_61_90']; $totRow[12] += $r['bucket_90_plus'];
                $totRow[13] += $r['outstanding_all'];
            }
            $out[] = $totRow;
            rpt_csv_out("ar-aging-card-{$stamp}.csv",
                ['Klien', 'Awal Periode', 'Akhir Periode', 'Saldo Awal', 'Faktur', 'Pembayaran', 'Alokasi', 'Sisa',
                 'Jml Faktur', '0-30', '31-60', '61-90', '>90', 'Outstanding Semua Periode'], $out);
            break;

        default:
            rpt_send(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (RptError $e) {
    rpt_send(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[ar.agingCRD] ' . $e->getMessage());
    rpt_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[ar.agingCRD] ' . $e->getMessage());
    rpt_send(false, [], 'System Error: ' . $e->getMessage());
}
