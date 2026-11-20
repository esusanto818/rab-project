<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * ap.agingCRD.php
 * ============================================================
 * Backend Laporan Kartu Hutang & Aging AP per supplier (read-only).
 * Front end: js/ap.aging.js, views/ap.aging.php. Menu: m_form_name seq 6039.
 *
 * Sumber data: view v_ap_card / v_ap_aging (sql/2026-11-17.ar_ap_aging_card.sql)
 *   - AP : invoice purchase_invoice_hdr (status bukan void/cancel/reverse); aging = invoice_date vs CURRENT_DATE.
 *   - Sisa AP = total_amount - GREATEST(paid_amount, total alokasi supplier_payment_items).
 *
 * Task : list_suppliers | list_cards | list_details | export_csv
 * Param: year, month (1-12) untuk kartu; supplier_id (0=semua) untuk detail;
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
    rpt_send(false, [], 'Hak baca kartu hutang tidak tersedia.');
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

/** Daftar supplier untuk combo filter (supplier dengan aktivitas hutang saja). */
function rpt_suppliers($db): array {
    $rows = $db->query("SELECT supplier_id AS id, supplier_name AS name FROM public.v_ap_card
                         ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    unset($r);
    array_unshift($rows, ['id' => 0, 'name' => '(Semua supplier)']);
    return $rows;
}

/** Kartu piutang per klien pada periode (filter project_id untuk AP tidak dipakai di AR). */
function rpt_cards($db, string $from, string $to): array {
    // View v_ap_card memakai periode bulan berjalan; untuk periode lain hitung ulang
    // dengan formula identik (invoice aktif, alokasi item pembayaran, header pembayaran).
    $activeStatus = "COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')";
    $st = $db->prepare("
        WITH per AS (SELECT CAST(:f AS date) AS ps, CAST(:t AS date) AS pe),
        inv_agg AS (
            SELECT h.supplier_id,
                   COALESCE(SUM(CASE WHEN h.invoice_date < per.ps THEN h.total_amount END), 0) AS amt_before,
                   COALESCE(SUM(CASE WHEN h.invoice_date >= per.ps AND h.invoice_date < per.pe THEN h.total_amount END), 0) AS amt_period,
                   COALESCE(SUM(h.total_amount), 0) AS amt_total,
                   COUNT(*) AS n_total,
                   MIN(h.invoice_date) AS first_date,
                   MAX(h.invoice_date) AS last_date
              FROM per, public.purchase_invoice_hdr h
             WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
             GROUP BY h.supplier_id
        ),
        alloc_agg AS (
            SELECT h.supplier_id,
                   COALESCE(SUM(CASE WHEN py.payment_date < per.ps THEN i.amount_paid END), 0) AS alloc_before,
                   COALESCE(SUM(CASE WHEN py.payment_date >= per.ps AND py.payment_date < per.pe THEN i.amount_paid END), 0) AS alloc_period,
                   COALESCE(SUM(i.amount_paid), 0) AS alloc_total
              FROM per, public.supplier_payment_items i
              JOIN public.supplier_payments py ON py.id = i.supplier_payment_id
              JOIN public.purchase_invoice_hdr h ON h.id = i.invoice_id
             WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
             GROUP BY h.supplier_id
        ),
        pay_agg AS (
            SELECT py.supplier_id, COALESCE(SUM(py.total_amount), 0) AS paid_period
              FROM per, public.supplier_payments py
             WHERE py.payment_date >= per.ps AND py.payment_date < per.pe
             GROUP BY py.supplier_id
        ),
        bucket_agg AS (
            SELECT h.supplier_id,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - h.invoice_date) <= 30
                                     THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_0_30,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 31 AND 60
                                     THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_31_60,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 61 AND 90
                                     THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_61_90,
                   COALESCE(SUM(CASE WHEN (CURRENT_DATE - h.invoice_date) > 90
                                     THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END), 0) AS bucket_90_plus
              FROM public.purchase_invoice_hdr h
              LEFT JOIN (SELECT invoice_id, SUM(amount_paid) AS alloc FROM public.supplier_payment_items GROUP BY invoice_id) a
                     ON a.invoice_id = h.id
             WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
             GROUP BY h.supplier_id
        )
        SELECT s.id AS supplier_id, s.name AS supplier_name, per.ps AS period_start, per.pe AS period_end,
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
          FROM public.m_suppliers s
          CROSS JOIN per
          LEFT JOIN inv_agg   inv ON inv.supplier_id = s.id
          LEFT JOIN alloc_agg al  ON al.supplier_id  = s.id
          LEFT JOIN pay_agg   pay ON pay.supplier_id = s.id
          LEFT JOIN bucket_agg bk ON bk.supplier_id  = s.id
         WHERE COALESCE(inv.amt_total, 0) <> 0
            OR COALESCE(al.alloc_total, 0) <> 0
            OR COALESCE(pay.paid_period, 0) <> 0
            OR COALESCE(inv.amt_before, 0) - COALESCE(al.alloc_before, 0) <> 0
            OR COALESCE(bk.bucket_0_30, 0) + COALESCE(bk.bucket_31_60, 0)
             + COALESCE(bk.bucket_61_90, 0) + COALESCE(bk.bucket_90_plus, 0) <> 0
         ORDER BY s.name ASC");
    $st->execute([':f' => $from, ':t' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['supplier_id'] = (int)$r['supplier_id'];
        foreach (['saldo_awal','faktur','pembayaran','alokasi','sisa','bucket_0_30','bucket_31_60','bucket_61_90','bucket_90_plus','outstanding_all'] as $k) {
            $r[$k] = rpt_f($r[$k]);
        }
        $r['invoice_count'] = (int)$r['invoice_count'];
    }
    unset($r);
    return $rows;
}

/** Aging detail per invoice supplier (periode bebas), opsional filter supplier. */
function rpt_details($db, string $from, string $to, int $supplierId): array {
    $sql = "
        SELECT s.id AS supplier_id, s.name AS supplier_name,
               h.id AS invoice_id, h.inv_no, h.supplier_invoice_no, h.invoice_date,
               h.invoice_date AS aging_date, h.status,
               h.total_amount AS invoice_amount,
               GREATEST(h.paid_amount, COALESCE(a.alloc, 0)) AS paid_amount,
               (h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0))) AS outstanding,
               (CURRENT_DATE - h.invoice_date) AS age_days,
               CASE WHEN (CURRENT_DATE - h.invoice_date) <= 30
                    THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_0_30,
               CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 31 AND 60
                    THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_31_60,
               CASE WHEN (CURRENT_DATE - h.invoice_date) BETWEEN 61 AND 90
                    THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_61_90,
               CASE WHEN (CURRENT_DATE - h.invoice_date) > 90
                    THEN GREATEST(h.total_amount - GREATEST(h.paid_amount, COALESCE(a.alloc, 0)), 0) ELSE 0 END AS bucket_90_plus
          FROM public.purchase_invoice_hdr h
          LEFT JOIN public.m_suppliers s ON s.id = h.supplier_id
          LEFT JOIN (SELECT invoice_id, SUM(amount_paid) AS alloc
                       FROM public.supplier_payment_items GROUP BY invoice_id) a ON a.invoice_id = h.id
         WHERE COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')
           AND h.invoice_date >= CAST(:f AS date) AND h.invoice_date < CAST(:t AS date)";
    $params = [':f' => $from, ':t' => $to];
    if ($supplierId > 0) {
        $sql .= " AND h.supplier_id = :sid";
        $params[':sid'] = $supplierId;
    }
    $sql .= " ORDER BY s.name, h.invoice_date, h.id";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['supplier_id'] = (int)$r['supplier_id'];
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
        case 'list_suppliers':
            rpt_send(true, rpt_suppliers($db));
            break;

        case 'list_cards':
            list ($from, $to) = rpt_period();
            $rows = rpt_cards($db, $from, $to);
            $q = trim((string)($_REQUEST['query'] ?? ''));
            if ($q !== '') {
                $rows = array_values(array_filter($rows, function ($r) use ($q) {
                    return strpos(mb_strtolower((string)$r['supplier_name']), mb_strtolower($q)) !== false;
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
            $sid = (int)($_REQUEST['supplier_id'] ?? 0);
            $rows = rpt_details($db, $from, $to, $sid);
            $q = trim((string)($_REQUEST['query'] ?? ''));
            if ($q !== '') {
                $rows = array_values(array_filter($rows, function ($r) use ($q) {
                    $hay = strtolower(implode(' ', [$r['supplier_name'], $r['inv_no'], $r['supplier_invoice_no'] ?? '', $r['status'] ?? '']));
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
                $sid = (int)($_REQUEST['supplier_id'] ?? 0);
                $rows = rpt_details($db, $df, $dt, $sid);
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [$r['supplier_name'], $r['inv_no'], $r['supplier_invoice_no'], $r['status'], $r['invoice_date'],
                              $r['aging_date'], $r['invoice_amount'], $r['paid_amount'], $r['outstanding'], $r['age_days'],
                              $r['bucket_0_30'], $r['bucket_31_60'], $r['bucket_61_90'], $r['bucket_90_plus']];
                }
                rpt_csv_out("ap-aging-detail-{$stamp}.csv",
                    ['Supplier', 'No Invoice', 'Inv. Supplier', 'Status', 'Tgl Invoice', 'Tgl Aging', 'Nilai', 'Dibayar', 'Sisa',
                     'Umur (hari)', '0-30', '31-60', '61-90', '>90'], $out);
            }
            $rows = rpt_cards($db, $from, $to);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['supplier_name'], $r['period_start'], $r['period_end'], $r['saldo_awal'], $r['faktur'],
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
            rpt_csv_out("ap-aging-card-{$stamp}.csv",
                ['Supplier', 'Awal Periode', 'Akhir Periode', 'Saldo Awal', 'Faktur', 'Pembayaran', 'Alokasi', 'Sisa',
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
