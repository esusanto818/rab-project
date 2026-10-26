<?php
/**
 * ppn.reportCRD.php
 * ============================================================
 * Backend Laporan PPN bulanan per proyek (PPN Keluaran vs PPN Masukan).  [PPN - TAHAP 3]
 * Front end: js/ppn.report.js, views/ppn.report.php. Menu: m_form_name seq 6037 (grup acct report).
 *
 * Sumber data:
 *  - PPN Keluaran : project_invoices (termin) dengan ppn_amount > 0; proyek = project_invoices.project_id.
 *  - PPN Masukan  : purchase_invoice_hdr dengan ppn_amount > 0; proyek diambil dari PO tiap GRN yang di-match
 *                   (purchase_invoice_dtl -> grn_hdr -> purchase_orders). Invoice yang mencakup lebih dari satu
 *                   proyek dialokasikan proporsional terhadap matched_amount; sisa pembulatan masuk ke baris terbesar
 *                   sehingga total alokasi = PPN invoice.
 *  - Masa pajak   : bulan dari tanggal faktur pajak; bila belum diisi memakai tanggal invoice.
 *
 * Task : list_projects | list_summary | list_keluaran | list_masukan | export_csv
 * Param: year, month (1-12), project_id (0 = semua), query (cari), type (export_csv: summary|keluaran|masukan)
 * Respons JSON standar {success, message, data, totalCount}; list_summary menambah 'totals'.
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

/** Rentang periode: [awal bulan, awal bulan berikutnya) dalam format YYYY-MM-DD. */
function rpt_period(): array {
    $y = (int)($_REQUEST['year'] ?? date('Y'));
    $m = (int)($_REQUEST['month'] ?? date('n'));
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) throw new RptError('Periode tidak valid.');
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to   = date('Y-m-d', strtotime($from . ' +1 month'));
    return [$from, $to, $y, $m];
}

function rpt_f($v): float { return round((float)$v, 2); }

/** Baris PPN Keluaran (termin proyek) pada periode. */
function rpt_keluaran($db, string $from, string $to): array {
    $st = $db->prepare("
        SELECT v.id, COALESCE(v.faktur_pajak_date, v.invoice_date) AS tax_date, v.invoice_date, v.inv_no, v.termin_name,
               v.faktur_pajak_no, v.faktur_pajak_date, v.dpp_amount, v.ppn_rate, v.ppn_amount, v.amount AS total_amount,
               p.id AS project_id, p.name AS project_name, c.name AS counterparty
          FROM public.project_invoices v
          JOIN public.projects p ON p.id = v.project_id
          LEFT JOIN public.clients c ON c.id = p.client_id
         WHERE v.ppn_amount > 0
           AND COALESCE(v.faktur_pajak_date, v.invoice_date) >= CAST(:f AS date)
           AND COALESCE(v.faktur_pajak_date, v.invoice_date) <  CAST(:t AS date)
         ORDER BY tax_date, v.id");
    $st->execute([':f' => $from, ':t' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['project_id'] = (int)$r['project_id'];
        $r['dpp_amount'] = rpt_f($r['dpp_amount']);
        $r['ppn_rate'] = (float)$r['ppn_rate'];
        $r['ppn_amount'] = rpt_f($r['ppn_amount']);
        $r['total_amount'] = rpt_f($r['total_amount']);
    }
    unset($r);
    return $rows;
}

/** Baris PPN Masukan (invoice supplier) pada periode, sudah dialokasikan per proyek. */
function rpt_masukan($db, string $from, string $to): array {
    $st = $db->prepare("
        SELECT h.id, COALESCE(h.faktur_pajak_date, h.invoice_date) AS tax_date, h.invoice_date, h.inv_no,
               h.supplier_invoice_no, h.faktur_pajak_no, h.faktur_pajak_date,
               h.dpp_amount, h.ppn_rate, h.ppn_amount, h.total_amount,
               s.name AS counterparty, s.npwp_no,
               COALESCE(po.project_id, 0) AS project_id, COALESCE(p.name, '(Tanpa proyek)') AS project_name,
               SUM(d.matched_amount) AS dpp_proj
          FROM public.purchase_invoice_hdr h
          LEFT JOIN public.m_suppliers s ON s.id = h.supplier_id
          LEFT JOIN public.purchase_invoice_dtl d ON d.invoice_hdr_id = h.id
          LEFT JOIN public.grn_hdr g ON g.id = d.grn_hdr_id
          LEFT JOIN public.purchase_orders po ON po.id = g.po_id
          LEFT JOIN public.projects p ON p.id = po.project_id
         WHERE h.ppn_amount > 0
           AND LOWER(h.status) NOT IN ('cancelled','canceled','dibatalkan','void','reversed')
           AND COALESCE(h.faktur_pajak_date, h.invoice_date) >= CAST(:f AS date)
           AND COALESCE(h.faktur_pajak_date, h.invoice_date) <  CAST(:t AS date)
         GROUP BY h.id, s.name, s.npwp_no, po.project_id, p.name
         ORDER BY tax_date, h.id, project_name");
    $st->execute([':f' => $from, ':t' => $to]);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);

    // kelompokkan per invoice lalu alokasikan PPN per proyek (proporsional matched_amount)
    $byInv = [];
    foreach ($raw as $r) $byInv[(int)$r['id']][] = $r;

    $out = [];
    foreach ($byInv as $lines) {
        $ppn = rpt_f($lines[0]['ppn_amount']);
        $dppInv = rpt_f($lines[0]['dpp_amount']);
        $sum = 0.0;
        foreach ($lines as $l) $sum += (float)$l['dpp_proj'];
        $alloc = [];
        $allocSum = 0.0; $maxIdx = 0; $maxVal = -1.0;
        foreach ($lines as $i => $l) {
            $dppP = ($sum > 0) ? rpt_f($l['dpp_proj']) : $dppInv;     // invoice tanpa detail -> seluruh nilai ke satu baris
            $ppnP = ($sum > 0) ? round($ppn * (float)$l['dpp_proj'] / $sum, 2) : $ppn;
            $alloc[$i] = [$dppP, $ppnP];
            $allocSum += $ppnP;
            if ($dppP > $maxVal) { $maxVal = $dppP; $maxIdx = $i; }
        }
        $alloc[$maxIdx][1] = round($alloc[$maxIdx][1] + ($ppn - $allocSum), 2);      // sisa pembulatan ke baris terbesar
        foreach ($lines as $i => $l) {
            $out[] = [
                'id' => (int)$l['id'], 'tax_date' => $l['tax_date'], 'invoice_date' => $l['invoice_date'],
                'inv_no' => $l['inv_no'], 'supplier_invoice_no' => $l['supplier_invoice_no'],
                'faktur_pajak_no' => $l['faktur_pajak_no'], 'faktur_pajak_date' => $l['faktur_pajak_date'],
                'counterparty' => $l['counterparty'], 'npwp_no' => $l['npwp_no'],
                'project_id' => (int)$l['project_id'], 'project_name' => $l['project_name'],
                'ppn_rate' => (float)$l['ppn_rate'],
                'dpp_amount' => $alloc[$i][0], 'ppn_amount' => $alloc[$i][1],
                'total_amount' => round($alloc[$i][0] + $alloc[$i][1], 2),
            ];
        }
    }
    return $out;
}

/** Filter proyek + kata kunci (dilakukan setelah alokasi agar alokasi per invoice tetap utuh). */
function rpt_filter(array $rows): array {
    $pid = (int)($_REQUEST['project_id'] ?? 0);
    $q = trim((string)($_REQUEST['query'] ?? ''));
    if ($pid <= 0 && $q === '') return $rows;
    $res = [];
    foreach ($rows as $r) {
        if ($pid > 0 && (int)$r['project_id'] !== $pid) continue;
        if ($q !== '') {
            $hay = strtolower(implode(' ', [$r['inv_no'] ?? '', $r['project_name'] ?? '', $r['counterparty'] ?? '',
                                            $r['faktur_pajak_no'] ?? '', $r['supplier_invoice_no'] ?? '', $r['termin_name'] ?? '']));
            if (strpos($hay, strtolower($q)) === false) continue;
        }
        $res[] = $r;
    }
    return $res;
}

/** Ringkasan per proyek + total (kurang / lebih bayar = Keluaran - Masukan). */
function rpt_summary(array $kel, array $mas): array {
    $map = [];
    $touch = function ($r) use (&$map) {
        $k = (int)$r['project_id'];
        if (!isset($map[$k])) $map[$k] = ['project_id' => $k, 'project_name' => $r['project_name'],
            'keluaran_dpp' => 0.0, 'keluaran_ppn' => 0.0, 'masukan_dpp' => 0.0, 'masukan_ppn' => 0.0];
        return $k;
    };
    foreach ($kel as $r) { $k = $touch($r); $map[$k]['keluaran_dpp'] += $r['dpp_amount']; $map[$k]['keluaran_ppn'] += $r['ppn_amount']; }
    foreach ($mas as $r) { $k = $touch($r); $map[$k]['masukan_dpp']  += $r['dpp_amount']; $map[$k]['masukan_ppn']  += $r['ppn_amount']; }

    $tot = ['keluaran_dpp' => 0.0, 'keluaran_ppn' => 0.0, 'masukan_dpp' => 0.0, 'masukan_ppn' => 0.0];
    $rows = [];
    foreach ($map as $r) {
        foreach (['keluaran_dpp', 'keluaran_ppn', 'masukan_dpp', 'masukan_ppn'] as $f) { $r[$f] = round($r[$f], 2); $tot[$f] += $r[$f]; }
        $r['net_ppn'] = round($r['keluaran_ppn'] - $r['masukan_ppn'], 2);
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return strcmp((string)$a['project_name'], (string)$b['project_name']); });
    foreach ($tot as $f => $v) $tot[$f] = round($v, 2);
    $tot['net_ppn'] = round($tot['keluaran_ppn'] - $tot['masukan_ppn'], 2);    // > 0 kurang bayar, < 0 lebih bayar
    return [$rows, $tot];
}

function rpt_csv_out(string $filename, array $head, array $rows): void {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF" . "sep=;\r\n";                                   // BOM + petunjuk pemisah agar Excel (locale apa pun) membuka rapi
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $head, ';');
    foreach ($rows as $r) fputcsv($fh, $r, ';');
    fclose($fh);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    rpt_send(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        case 'list_projects':
            $rows = $db->query("SELECT id, name FROM public.projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            array_unshift($rows, ['id' => 0, 'name' => '(Semua proyek)']);
            rpt_send(true, $rows);
            break;

        case 'list_summary':
            list($from, $to) = rpt_period();
            list($rows, $tot) = rpt_summary(rpt_filter(rpt_keluaran($db, $from, $to)), rpt_filter(rpt_masukan($db, $from, $to)));
            rpt_send(true, $rows, '', ['totals' => $tot]);
            break;

        case 'list_keluaran':
            list($from, $to) = rpt_period();
            rpt_send(true, rpt_filter(rpt_keluaran($db, $from, $to)));
            break;

        case 'list_masukan':
            list($from, $to) = rpt_period();
            rpt_send(true, rpt_filter(rpt_masukan($db, $from, $to)));
            break;

        case 'export_csv':
            list($from, $to, $y, $m) = rpt_period();
            $type = (string)($_REQUEST['type'] ?? 'summary');
            $stamp = sprintf('%04d-%02d', $y, $m);
            if ($type === 'keluaran') {
                $rows = rpt_filter(rpt_keluaran($db, $from, $to));
                $out = [];
                foreach ($rows as $r) $out[] = [$r['tax_date'], $r['inv_no'], $r['project_name'], $r['counterparty'], $r['termin_name'],
                                                $r['faktur_pajak_no'], $r['dpp_amount'], $r['ppn_rate'], $r['ppn_amount'], $r['total_amount']];
                rpt_csv_out("ppn-keluaran-{$stamp}.csv",
                    ['Tgl Pajak', 'No Invoice', 'Proyek', 'Customer', 'Termin', 'No Faktur Pajak', 'DPP', 'Tarif %', 'PPN', 'Total'], $out);
            } elseif ($type === 'masukan') {
                $rows = rpt_filter(rpt_masukan($db, $from, $to));
                $out = [];
                foreach ($rows as $r) $out[] = [$r['tax_date'], $r['inv_no'], $r['supplier_invoice_no'], $r['project_name'], $r['counterparty'],
                                                $r['npwp_no'], $r['faktur_pajak_no'], $r['dpp_amount'], $r['ppn_rate'], $r['ppn_amount'], $r['total_amount']];
                rpt_csv_out("ppn-masukan-{$stamp}.csv",
                    ['Tgl Pajak', 'No Invoice', 'Invoice Supplier', 'Proyek', 'Supplier', 'NPWP', 'No Faktur Pajak', 'DPP', 'Tarif %', 'PPN', 'Total'], $out);
            } else {
                list($rows, $tot) = rpt_summary(rpt_filter(rpt_keluaran($db, $from, $to)), rpt_filter(rpt_masukan($db, $from, $to)));
                $out = [];
                foreach ($rows as $r) $out[] = [$r['project_name'], $r['keluaran_dpp'], $r['keluaran_ppn'], $r['masukan_dpp'], $r['masukan_ppn'], $r['net_ppn']];
                $out[] = ['TOTAL', $tot['keluaran_dpp'], $tot['keluaran_ppn'], $tot['masukan_dpp'], $tot['masukan_ppn'], $tot['net_ppn']];
                rpt_csv_out("ppn-ringkasan-{$stamp}.csv",
                    ['Proyek', 'DPP Keluaran', 'PPN Keluaran', 'DPP Masukan', 'PPN Masukan', 'PPN Kurang(+)/Lebih(-) Bayar'], $out);
            }
            break;

        default:
            rpt_send(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (RptError $e) {
    rpt_send(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[ppn.reportCRD] ' . $e->getMessage());
    rpt_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[ppn.reportCRD] ' . $e->getMessage());
    rpt_send(false, [], 'System Error: ' . $e->getMessage());
}