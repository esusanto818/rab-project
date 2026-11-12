<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * poCRD.php [VERSI BARU - sinkron po.js: kontrol nilai RAB + mata uang anggaran + PPN]
 * Lihat komentar lengkap pada versi yang diserahkan di percakapan.
 */
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
$db = new crud();
const PO_EPS = 0.0001;
const PO_VEPS = 0.01;
const PPN_DEFAULT_RATE = 11.0;
const PPN_MODES = ['NONE', 'EXCL', 'INCL'];
const PO_TRANSITIONS = [
    'Draft'      => ['Diajukan', 'Dibatalkan'],
    'Diajukan'   => ['Disetujui', 'Draft', 'Dibatalkan'],
    'Disetujui'  => ['Selesai', 'Dibatalkan'],
    'Selesai'    => [],
    'Dibatalkan' => [],
];
class PoBusinessError extends Exception {}
function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else { $response['data'] = $data; }
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function po_fmt($n): string { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }
function po_ppn_mode($m): string { $m = strtoupper(trim((string)$m)); return in_array($m, PPN_MODES, true) ? $m : 'NONE'; }
function po_ppn_calc(float $subtotal, string $mode, float $rate): array {
    $subtotal = round($subtotal, 2); $mode = po_ppn_mode($mode);
    if ($mode === 'NONE' || $rate <= 0) return ['subtotal'=>$subtotal,'dpp'=>$subtotal,'ppn'=>0.0,'total'=>$subtotal];
    if ($mode === 'INCL') { $dpp = round($subtotal/(1+$rate/100),2); return ['subtotal'=>$subtotal,'dpp'=>$dpp,'ppn'=>round($subtotal-$dpp,2),'total'=>$subtotal]; }
    $ppn = round($subtotal*$rate/100,2); return ['subtotal'=>$subtotal,'dpp'=>$subtotal,'ppn'=>$ppn,'total'=>round($subtotal+$ppn,2)];
}
function po_line_dpp(float $qty, float $price, string $mode, float $rate): float {
    $sub = round($qty*$price,2);
    if ($mode === 'INCL' && $rate > 0) return round($sub/(1+$rate/100),2);
    return $sub;
}
function po_base_currency($db): array {
    $r = $db->query("SELECT fs.base_currency_id AS id, c.init FROM public.finance_settings fs JOIN public.m_currency c ON c.id = fs.base_currency_id ORDER BY fs.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) $r = $db->query("SELECT id, init FROM public.m_currency WHERE UPPER(TRIM(init))='IDR' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Mata uang dasar belum dikonfigurasi (finance_settings.base_currency_id).');
    return ['id'=>(int)$r['id'], 'code'=>strtoupper(trim((string)$r['init']))];
}
function po_currency_code($db, int $id): string {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $st = $db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id = :id"); $st->execute([':id'=>$id]);
    $c = (string)$st->fetchColumn(); $cache[$id] = $c !== '' ? $c : 'IDR'; return $cache[$id];
}
function po_sql_line_dpp(string $i, string $po): string {
    return "ROUND(CASE WHEN {$po}.ppn_mode='INCL' AND {$po}.ppn_rate>0 THEN ROUND({$i}.qty*{$i}.unit_price,2)/(1+{$po}.ppn_rate/100.0) ELSE ROUND({$i}.qty*{$i}.unit_price,2) END,2)";
}
function po_sql_budget_factor(int $baseId, string $po, string $p): string {
    $effCur = "COALESCE({$po}.currency_id, {$baseId})";
    $effBud = "COALESCE({$po}.budget_currency_id, {$p}.budget_currency_id, {$baseId})";
    $rate = "COALESCE(NULLIF({$po}.exch_rate,0),1)";
    $budR = "COALESCE(NULLIF({$po}.budget_exch_rate,0), NULLIF({$p}.budget_exch_rate,0),1)";
    return "CASE WHEN {$effCur}={$effBud} THEN 1.0 WHEN {$effBud}={$baseId} THEN {$rate} ELSE {$rate}/{$budR} END";
}
function po_ordered_map($db, int $projectId, int $exclPoId = 0): array {
    $st = $db->prepare("SELECT poi.budget_item_id, SUM(poi.qty) AS q FROM po_items poi JOIN purchase_orders po ON po.id=poi.po_id WHERE po.project_id=:p AND po.status<>'Dibatalkan' AND po.id<>:x AND poi.budget_item_id IS NOT NULL GROUP BY poi.budget_item_id");
    $st->execute([':p'=>$projectId, ':x'=>$exclPoId]);
    $map = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['budget_item_id']] = (float)$r['q']; return $map;
}
function po_committed_map($db, int $projectId, int $exclPoId, int $baseId): array {
    $dpp = po_sql_line_dpp('poi','po'); $fac = po_sql_budget_factor($baseId,'po','p');
    $st = $db->prepare("SELECT poi.budget_item_id, SUM({$dpp}*{$fac}) AS v FROM po_items poi JOIN purchase_orders po ON po.id=poi.po_id JOIN public.projects p ON p.id=po.project_id WHERE po.project_id=:p AND po.status<>'Dibatalkan' AND po.id<>:x GROUP BY poi.budget_item_id");
    $st->execute([':p'=>$projectId, ':x'=>$exclPoId]);
    $map = []; $total = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $v = round((float)$r['v'],2); $total += $v; if ($r['budget_item_id'] !== null) $map[(int)$r['budget_item_id']] = $v; }
    return ['map'=>$map, 'total'=>round($total,2)];
}
function po_project_budget($db, int $projectId, array $base): array {
    $st = $db->prepare("SELECT p.budget_currency_id, p.budget_exch_rate, p.budget, COALESCE(UPPER(TRIM(c.init)),'') AS budget_code FROM public.projects p LEFT JOIN public.m_currency c ON c.id=p.budget_currency_id WHERE p.id=:p");
    $st->execute([':p'=>$projectId]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new PoBusinessError('Proyek tidak ditemukan.');
    $curId = (int)$r['budget_currency_id'] > 0 ? (int)$r['budget_currency_id'] : $base['id'];
    $code = $curId === $base['id'] ? $base['code'] : ($r['budget_code'] !== '' ? $r['budget_code'] : po_currency_code($db,$curId));
    $st = $db->prepare("SELECT COALESCE(SUM(ROUND(ROUND(qty_plan*COALESCE(unit_price_plan,0),2)*(1+COALESCE(tolerance_pct,0)/100.0),2)),0) FROM public.budget_items WHERE project_id=:p AND category='Material' AND COALESCE(qty_plan,0)>0");
    $st->execute([':p'=>$projectId]);
    $ceil = (float)$r['budget'];
    return ['currency_id'=>$curId,'code'=>$code,'is_base'=>$curId===$base['id'],'proj_rate'=>(float)$r['budget_exch_rate'],'ceiling'=>$ceil>0?round($ceil,2):null,'total_limit'=>round((float)$st->fetchColumn(),2)];
}
function po_received_total($db, int $poId): float {
    $st = $db->prepare("SELECT COALESCE(SUM(d.qty_received),0) FROM grn_dtl d JOIN grn_hdr h ON h.id=d.grn_hdr_id WHERE h.po_id=:id AND COALESCE(LOWER(h.status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')");
    $st->execute([':id'=>$poId]); return (float)$st->fetchColumn();
}
if (empty($_SESSION['user_id'])) { http_response_code(401); sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.'); }
$task = $_REQUEST['task'] ?? '';
$poAccess = (int)($_SESSION['FormAccess'] ?? 0);
$poRequired = 1;
if ($task === 'save_po') $poRequired = 2;
if ($task === 'change_status') $poRequired = 3;
if ($task === 'delete_po') $poRequired = 4;
if ($poAccess < $poRequired) { http_response_code(403); sendJsonResponse(false, [], 'Hak akses tidak mencukupi untuk operasi purchase order ini.'); }
if (in_array($task, ['save_po','change_status','delete_po'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); sendJsonResponse(false, [], 'Metode harus POST untuk perubahan purchase order.'); }
try {
    switch ($task) {
        case 'list_project_options':
            $base = po_base_currency($db);
            $rows = $db->query("SELECT p.id, p.name, COALESCE(p.budget_currency_id, {$base['id']}) AS budget_currency_id, COALESCE(UPPER(TRIM(c.init)), '{$base['code']}') AS budget_code, (COALESCE(p.budget_currency_id, {$base['id']}) = {$base['id']}) AS budget_is_base FROM public.projects p LEFT JOIN public.m_currency c ON c.id=p.budget_currency_id ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['budget_currency_id']=(int)$r['budget_currency_id']; $r['budget_is_base']=in_array($r['budget_is_base'],[true,'t','true',1,'1'],true); } unset($r);
            sendJsonResponse(true, $rows); break;
        case 'list_suppliers':
            $rows = $db->query("SELECT id, name, COALESCE(is_pkp,false) AS is_pkp, npwp_no FROM public.m_suppliers WHERE active IS NOT FALSE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['is_pkp']=in_array($r['is_pkp'],[true,'t','true',1,'1'],true); } unset($r);
            sendJsonResponse(true, $rows); break;
        case 'list_currencies':
            $base = po_base_currency($db);
            $rows = $db->query("SELECT id, init, name, (id = {$base['id']}) AS is_base FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['is_base']=in_array($r['is_base'],[true,'t','true',1,'1'],true); } unset($r);
            sendJsonResponse(true, $rows); break;
        case 'get_exchange_rate':
            $base = po_base_currency($db);
            $cid = (int)($_REQUEST['currency_id'] ?? 0); $date = (string)($_REQUEST['date'] ?? '');
            $dt = DateTime::createFromFormat('Y-m-d', $date); if (!$dt || $dt->format('Y-m-d') !== $date) $date = date('Y-m-d');
            if ($cid === $base['id']) sendJsonResponse(true, ['rate'=>1,'source'=>'BASE','base_code'=>$base['code']]);
            $st = $db->prepare("SELECT init FROM public.m_currency WHERE id=:id"); $st->execute([':id'=>$cid]);
            $curRow = $st->fetch(PDO::FETCH_ASSOC); if (!$curRow) sendJsonResponse(false, [], 'Mata uang tidak ditemukan.');
            $code = strtoupper(trim((string)$curRow['init']));
            $baseSql = "SELECT COALESCE(d.rate_per_unit, d.rate/NULLIF(d.unit_multiplier,0)) AS rate, h.kmk_no, h.start_date, h.end_date FROM public.tax_exchange_rate_dtl d JOIN public.tax_exchange_rate_hdr h ON h.id=d.hdr_id WHERE (d.currency_id=:cid OR UPPER(d.currency_code)=:code)";
            $st = $db->prepare($baseSql." AND CAST(:d AS date) BETWEEN h.start_date AND h.end_date ORDER BY h.start_date DESC, h.id DESC LIMIT 1");
            $st->execute([':cid'=>$cid, ':code'=>$code, ':d'=>$date]); $r = $st->fetch(PDO::FETCH_ASSOC); $source='periode';
            if (!$r || $r['rate'] === null) { $st = $db->prepare($baseSql." ORDER BY h.end_date DESC, h.id DESC LIMIT 1"); $st->execute([':cid'=>$cid, ':code'=>$code]); $r = $st->fetch(PDO::FETCH_ASSOC); $source='terakhir'; }
            if (!$r || $r['rate'] === null) sendJsonResponse(true, ['rate'=>null,'source'=>'none']);
            sendJsonResponse(true, ['rate'=>round((float)$r['rate'],4),'source'=>$source,'kmk_no'=>$r['kmk_no'],'start_date'=>date('d/m/Y',strtotime($r['start_date'])),'end_date'=>date('d/m/Y',strtotime($r['end_date']))]); break;
        case 'list_rab_items':
            $prj = (int)($_REQUEST['project_id'] ?? 0); $excl = (int)($_REQUEST['po_id'] ?? 0);
            if ($prj <= 0) sendJsonResponse(true, [], '', ['summary'=>null]);
            $base = po_base_currency($db); $pb = po_project_budget($db, $prj, $base);
            $st = $db->prepare("SELECT bi.id AS budget_item_id, bi.material_id, m.material_code, m.material_name, COALESCE(NULLIF(bi.unit,''),m.unit,'') AS unit, bi.qty_plan, COALESCE(bi.tolerance_pct,0) AS tolerance_pct, COALESCE(bi.unit_price_plan,0) AS unit_price_plan FROM public.budget_items bi JOIN public.materials m ON m.id=bi.material_id WHERE bi.project_id=:p AND bi.category='Material' AND COALESCE(bi.qty_plan,0)>0 ORDER BY m.material_name ASC");
            $st->execute([':p'=>$prj]);
            $ordered = po_ordered_map($db, $prj, $excl); $committed = po_committed_map($db, $prj, $excl, $base['id']);
            $data = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bid=(int)$r['budget_item_id']; $plan=(float)$r['qty_plan']; $tol=(float)$r['tolerance_pct']; $ord=$ordered[$bid]??0.0;
                $vPlan=round($plan*(float)$r['unit_price_plan'],2); $vLimit=round($vPlan*(1+$tol/100),2); $vComm=$committed['map'][$bid]??0.0;
                $data[] = ['budget_item_id'=>$bid,'material_id'=>(int)$r['material_id'],'material_code'=>$r['material_code'],'material_name'=>$r['material_name'],'unit'=>$r['unit'],'qty_plan'=>$plan,'tolerance_pct'=>$tol,'unit_price_plan'=>(float)$r['unit_price_plan'],'qty_ordered'=>$ord,'qty_remaining'=>max($plan-$ord,0),'qty_quota'=>max($plan*(1+$tol/100)-$ord,0),'value_plan'=>$vPlan,'value_limit'=>$vLimit,'value_committed'=>$vComm,'value_remaining'=>max(round($vLimit-$vComm,2),0)];
            }
            $summary = ['budget_currency_id'=>$pb['currency_id'],'budget_code'=>$pb['code'],'budget_is_base'=>$pb['is_base'],'base_code'=>$base['code'],'total_limit'=>$pb['total_limit'],'total_committed'=>$committed['total'],'ceiling'=>$pb['ceiling']];
            sendJsonResponse(true, $data, '', ['summary'=>$summary]); break;
        case 'list_pos':
            $base = po_base_currency($db);
            $start=(int)($_REQUEST['start']??0); $limit=(int)($_REQUEST['limit']??250); $query=trim($_REQUEST['query']??'');
            $where=''; $params=[];
            if ($query !== '') { $where="WHERE (po.po_number ILIKE :query OR p.name ILIKE :query OR s.name ILIKE :query)"; $params[':query']='%'.$query.'%'; }
            $st = $db->prepare("SELECT COUNT(*) FROM purchase_orders po JOIN public.projects p ON p.id=po.project_id LEFT JOIN public.m_suppliers s ON s.id=po.supplier_id {$where}"); $st->execute($params); $totalCount=(int)$st->fetchColumn();
            $dpp2 = po_sql_line_dpp('i2','po'); $fac2 = po_sql_budget_factor($base['id'],'po','p');
            $st = $db->prepare("SELECT po.id, po.po_number, po.po_date, po.project_id, p.name AS project_name, po.supplier_id, s.name AS supplier_name, po.status, po.notes, po.currency_id, c.init AS currency_code, po.exch_rate, po.ppn_mode, po.ppn_rate, COALESCE(po.budget_currency_id,p.budget_currency_id,{$base['id']}) AS budget_currency_id, bc.init AS budget_code, COUNT(i.id) AS item_count, COALESCE(SUM(i.qty*i.unit_price),0) AS total_amount, COALESCE(SUM(ROUND(i.qty*i.unit_price,2)),0) AS subtotal_r, COALESCE((SELECT SUM({$dpp2}*{$fac2}) FROM po_items i2 WHERE i2.po_id=po.id),0) AS total_budget FROM purchase_orders po JOIN public.projects p ON p.id=po.project_id LEFT JOIN public.m_suppliers s ON s.id=po.supplier_id LEFT JOIN public.m_currency c ON c.id=po.currency_id LEFT JOIN public.m_currency bc ON bc.id=COALESCE(po.budget_currency_id,p.budget_currency_id,{$base['id']}) LEFT JOIN po_items i ON i.po_id=po.id {$where} GROUP BY po.id, p.name, s.name, c.init, bc.init, p.budget_currency_id, p.budget_exch_rate ORDER BY po.po_date DESC, po.id DESC LIMIT :limit OFFSET :start");
            foreach ($params as $k=>$v) $st->bindValue($k,$v);
            $st->bindValue(':limit',$limit,PDO::PARAM_INT); $st->bindValue(':start',$start,PDO::PARAM_INT); $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id']=(int)$r['id']; $r['project_id']=(int)$r['project_id']; $r['supplier_id']=$r['supplier_id']!==null?(int)$r['supplier_id']:null; $r['currency_id']=$r['currency_id']!==null?(int)$r['currency_id']:null; $r['budget_currency_id']=(int)$r['budget_currency_id']; $r['exch_rate']=(float)$r['exch_rate']; $r['item_count']=(int)$r['item_count']; $r['total_amount']=(float)$r['total_amount']; $r['total_budget']=round((float)$r['total_budget'],2);
                $r['ppn_mode']=po_ppn_mode($r['ppn_mode']); $r['ppn_rate']=(float)$r['ppn_rate'];
                $calc=po_ppn_calc((float)$r['subtotal_r'],$r['ppn_mode'],$r['ppn_rate']); unset($r['subtotal_r']);
                $r['dpp']=$calc['dpp']; $r['ppn_amount']=$calc['ppn']; $r['grand_total']=$calc['total']; $r['ppn_idr']=round($calc['ppn']*$r['exch_rate'],2);
            } unset($r);
            sendJsonResponse(true, ['data'=>$rows,'totalCount'=>$totalCount]); break;
        case 'load_po':
            $base = po_base_currency($db); $id=(int)($_REQUEST['id']??0);
            $st = $db->prepare("SELECT po.id, po.po_number, po.po_date, po.project_id, p.name AS project_name, po.supplier_id, s.name AS supplier_name, po.status, po.notes, po.currency_id, c.init AS currency_code, po.exch_rate, po.ppn_mode, po.ppn_rate, po.budget_currency_id, po.budget_exch_rate, p.budget_currency_id AS prj_budget_currency_id, p.budget_exch_rate AS prj_budget_exch_rate, bc.init AS budget_code FROM purchase_orders po JOIN public.projects p ON p.id=po.project_id LEFT JOIN public.m_suppliers s ON s.id=po.supplier_id LEFT JOIN public.m_currency c ON c.id=po.currency_id LEFT JOIN public.m_currency bc ON bc.id=COALESCE(po.budget_currency_id,p.budget_currency_id,{$base['id']}) WHERE po.id=:id");
            $st->execute([':id'=>$id]); $hdr=$st->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) sendJsonResponse(false, [], 'PO tidak ditemukan.');
            $hdr['exch_rate']=(float)$hdr['exch_rate'];
            $curId=(int)$hdr['currency_id']>0?(int)$hdr['currency_id']:$base['id'];
            $budId=(int)$hdr['budget_currency_id']>0?(int)$hdr['budget_currency_id']:((int)$hdr['prj_budget_currency_id']>0?(int)$hdr['prj_budget_currency_id']:$base['id']);
            if ($budId===$base['id']) $budRate=1.0; elseif ($budId===$curId) $budRate=$hdr['exch_rate'];
            else $budRate=(float)$hdr['budget_exch_rate']>0?(float)$hdr['budget_exch_rate']:((float)$hdr['prj_budget_exch_rate']>0?(float)$hdr['prj_budget_exch_rate']:1.0);
            $factor=($curId===$budId)?1.0:($budRate>0?$hdr['exch_rate']/$budRate:0.0);
            $hdr['ppn_mode']=po_ppn_mode($hdr['ppn_mode']); $hdr['ppn_rate']=(float)$hdr['ppn_rate'];
            $st = $db->prepare("SELECT i.id, i.budget_item_id, i.material_id, m.material_code, i.description, i.unit, i.qty, i.unit_price, i.currency_id, COALESCE(UPPER(TRIM(ic.init)),'IDR') AS item_currency_code, COALESCE(NULLIF(i.exch_rate,0),1) AS exch_rate, COALESCE(g.q,0) AS qty_received FROM po_items i LEFT JOIN public.materials m ON m.id=i.material_id LEFT JOIN public.m_currency ic ON ic.id=i.currency_id LEFT JOIN (SELECT d.po_item_id, SUM(d.qty_received) AS q FROM grn_dtl d JOIN grn_hdr h ON h.id=d.grn_hdr_id WHERE COALESCE(LOWER(h.status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed') GROUP BY d.po_item_id) g ON g.po_item_id=i.id WHERE i.po_id=:id ORDER BY i.id");
            $st->execute([':id'=>$id]); $items=$st->fetchAll(PDO::FETCH_ASSOC);
            $received=0.0; $subItems=0.0; $totBud=0.0; $totBase=0.0;
            foreach ($items as &$it) {
                $it['id']=(int)$it['id']; $it['budget_item_id']=$it['budget_item_id']!==null?(int)$it['budget_item_id']:null; $it['material_id']=$it['material_id']!==null?(int)$it['material_id']:null; $it['currency_id']=$it['currency_id']!==null?(int)$it['currency_id']:null; $it['qty']=(float)$it['qty']; $it['unit_price']=(float)$it['unit_price']; $it['exch_rate']=(float)$it['exch_rate']; $it['qty_received']=(float)$it['qty_received']; $received+=$it['qty_received'];
                $lineDpp=po_line_dpp($it['qty'],$it['unit_price'],$hdr['ppn_mode'],$hdr['ppn_rate']);
                $it['amount_budget']=round($lineDpp*$factor,2); $it['amount_base']=round($lineDpp*$hdr['exch_rate'],2);
                $totBud+=$it['amount_budget']; $totBase+=$it['amount_base']; $subItems+=round($it['qty']*$it['unit_price'],2);
            } unset($it);
            $calc=po_ppn_calc($subItems,$hdr['ppn_mode'],$hdr['ppn_rate']);
            $hdr['subtotal']=$calc['subtotal']; $hdr['dpp']=$calc['dpp']; $hdr['ppn_amount']=$calc['ppn']; $hdr['grand_total']=$calc['total']; $hdr['ppn_idr']=round($calc['ppn']*$hdr['exch_rate'],2);
            $hdr['currency_id']=$curId; $hdr['budget_currency_id']=$budId; $hdr['budget_code']=$hdr['budget_code']!==null?strtoupper(trim((string)$hdr['budget_code'])):po_currency_code($db,$budId); $hdr['budget_is_base']=$budId===$base['id']; $hdr['budget_exch_rate']=$budRate; $hdr['total_budget']=round($totBud,2); $hdr['total_base']=round($totBase,2);
            unset($hdr['prj_budget_currency_id'],$hdr['prj_budget_exch_rate']);
            $allowed=PO_TRANSITIONS[$hdr['status']]??[];
            if ($received>PO_EPS) $allowed=array_values(array_diff($allowed,['Dibatalkan','Draft']));
            sendJsonResponse(true, ['header'=>$hdr,'items'=>$items,'allowed_actions'=>$allowed]); break;
        case 'save_po':
            $id=(int)($_POST['id']??0);
            if ($id>0 && $poAccess<3) { http_response_code(403); sendJsonResponse(false,[],'Hak ubah Purchase Order tidak tersedia.'); }
            $base=po_base_currency($db);
            $prj=(int)($_POST['project_id']??0); $sup=(int)($_POST['supplier_id']??0)?:null; $cur=(int)($_POST['currency_id']??0)?:$base['id'];
            $rate=(float)($_POST['exch_rate']??1); $budRateIn=(float)($_POST['budget_exch_rate']??0);
            $date=(string)($_POST['po_date']??''); $notes=trim((string)($_POST['notes']??''))?:null;
            $items=json_decode($_POST['items']??'[]',true);
            if ($rate<=0) $rate=1;
            $ppnMode=po_ppn_mode($_POST['ppn_mode']??'NONE'); $ppnRate=round((float)($_POST['ppn_rate']??PPN_DEFAULT_RATE),2);
            try {
                $d=DateTime::createFromFormat('Y-m-d',$date);
                if (!$d || $d->format('Y-m-d')!==$date) throw new PoBusinessError('Tanggal PO tidak valid.');
                if ($prj<=0) throw new PoBusinessError('Proyek wajib dipilih.');
                if (!is_array($items)||!count($items)) throw new PoBusinessError('Minimal satu material harus diisi.');
                if ($ppnMode==='NONE') $ppnRate=0.0; elseif ($ppnRate<=0||$ppnRate>100) throw new PoBusinessError('Tarif PPN harus lebih dari 0 dan maksimal 100%.');
                $pb=po_project_budget($db,$prj,$base);
                $st=$db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id=:id"); $st->execute([':id'=>$cur]); $curCode=$st->fetchColumn();
                if (!$curCode) throw new PoBusinessError('Mata uang PO tidak ditemukan.');
                if ($cur===$base['id']) $rate=1.0;
                if ($pb['is_base']) $budRate=1.0; elseif ($cur===$pb['currency_id']) $budRate=$rate;
                else { $budRate=$budRateIn; if ($budRate<=0) throw new PoBusinessError('Kurs '.$pb['code'].' (mata uang anggaran) wajib diisi.'); }
                $factor=($cur===$pb['currency_id'])?1.0:$rate/$budRate;
                $db->beginTransaction();
                try {
                    if ($id>0) { $st=$db->prepare("SELECT status FROM purchase_orders WHERE id=:id FOR UPDATE"); $st->execute([':id'=>$id]); $cur0=$st->fetch(PDO::FETCH_ASSOC); if (!$cur0) throw new PoBusinessError('PO tidak ditemukan.'); if ($cur0['status']!=='Draft') throw new PoBusinessError('Hanya PO berstatus Draft yang dapat diubah.'); }
                    if ($sup) { $st=$db->prepare("SELECT 1 FROM public.m_suppliers WHERE id=:id"); $st->execute([':id'=>$sup]); if (!$st->fetchColumn()) throw new PoBusinessError('Supplier tidak ditemukan.'); }
                    $ordered=po_ordered_map($db,$prj,$id); $committed=po_committed_map($db,$prj,$id,$base['id']);
                    $stBi=$db->prepare("SELECT bi.id, bi.material_id, bi.qty_plan, COALESCE(bi.tolerance_pct,0) AS tol, COALESCE(bi.unit_price_plan,0) AS price_plan, m.material_name, COALESCE(NULLIF(bi.unit,''),m.unit,'') AS unit FROM public.budget_items bi JOIN public.materials m ON m.id=bi.material_id WHERE bi.id=:id AND bi.project_id=:p AND bi.category='Material'");
                    $tallyQty=[]; $tallyVal=[]; $clean=[]; $totBud=0.0; $totBase=0.0;
                    foreach ($items as $n=>$it) {
                        $bid=(int)($it['budget_item_id']??0); $qty=round((float)($it['qty']??0),3); $price=round((float)($it['unit_price']??0),2); $no='Baris '.($n+1);
                        if ($bid<=0||$qty<=0) throw new PoBusinessError("{$no}: material dan qty (> 0) wajib diisi.");
                        if ($price<0) throw new PoBusinessError("{$no}: harga satuan tidak valid.");
                        $stBi->execute([':id'=>$bid,':p'=>$prj]); $bi=$stBi->fetch(PDO::FETCH_ASSOC);
                        if (!$bi) throw new PoBusinessError("{$no}: material tidak ada di RAB proyek ini.");
                        $qtyLimit=(float)$bi['qty_plan']*(1+(float)$bi['tol']/100);
                        $valLimit=round(round((float)$bi['qty_plan']*(float)$bi['price_plan'],2)*(1+(float)$bi['tol']/100),2);
                        $ordQty=$ordered[$bid]??0.0; $ordVal=$committed['map'][$bid]??0.0;
                        $tallyQty[$bid]=($tallyQty[$bid]??0)+$qty;
                        if ($ordQty+$tallyQty[$bid]>$qtyLimit+PO_EPS) throw new PoBusinessError("{$bi['material_name']}: qty PO ".po_fmt($tallyQty[$bid])." melebihi sisa kuota RAB (".po_fmt(max($qtyLimit-$ordQty,0))." {$bi['unit']}).");
                        $lineDpp=po_line_dpp($qty,$price,$ppnMode,$ppnRate); $amtBud=round($lineDpp*$factor,2); $amtBase=round($lineDpp*$rate,2);
                        $tallyVal[$bid]=round(($tallyVal[$bid]??0)+$amtBud,2);
                        if ($ordVal+$tallyVal[$bid]>$valLimit+PO_VEPS) throw new PoBusinessError("{$bi['material_name']}: nilai PO ".po_fmt($tallyVal[$bid])." {$pb['code']} melebihi sisa anggaran RAB baris ini (".po_fmt(max(round($valLimit-$ordVal,2),0))." {$pb['code']}).");
                        $totBud+=$amtBud; $totBase+=$amtBase;
                        $clean[]=['bid'=>$bid,'mid'=>(int)$bi['material_id'],'name'=>$bi['material_name'],'unit'=>$bi['unit'],'qty'=>$qty,'price'=>$price,'amt_base'=>$amtBase,'amt_budget'=>$amtBud];
                    }
                    $totBud=round($totBud,2); $totBase=round($totBase,2); $after=round($committed['total']+$totBud,2);
                    if ($after>$pb['total_limit']+PO_VEPS) throw new PoBusinessError('Total PO proyek setara '.po_fmt($after).' '.$pb['code'].' melebihi total RAB ('.po_fmt($pb['total_limit']).' '.$pb['code'].').');
                    if ($pb['ceiling']!==null && $after>$pb['ceiling']+PO_VEPS) throw new PoBusinessError('Total PO proyek setara '.po_fmt($after).' '.$pb['code'].' melebihi budget proyek ('.po_fmt($pb['ceiling']).' '.$pb['code'].').');
                    if ($id>0) {
                        $st=$db->prepare("UPDATE purchase_orders SET project_id=:p, supplier_id=:s, po_date=CAST(:d AS date), notes=:n, currency_id=:c, exch_rate=:r, budget_currency_id=:bc, budget_exch_rate=:br, total_base=:tb, total_budget=:tbud, ppn_mode=:pm, ppn_rate=:prt, updated_at=NOW() WHERE id=:id");
                        $st->execute([':p'=>$prj,':s'=>$sup,':d'=>$date,':n'=>$notes,':c'=>$cur,':r'=>$rate,':bc'=>$pb['currency_id'],':br'=>$budRate,':tb'=>$totBase,':tbud'=>$totBud,':pm'=>$ppnMode,':prt'=>$ppnRate,':id'=>$id]);
                        $poNumber=$db->query("SELECT po_number FROM purchase_orders WHERE id=".$id)->fetchColumn();
                        $db->prepare("DELETE FROM po_items WHERE po_id=:id")->execute([':id'=>$id]);
                    } else {
                        if (!function_exists('CreateRefNoDb')) throw new Exception('CreateRefNoDb() tidak tersedia.');
                        $poNumber=CreateRefNoDb($db,'purchase_orders','po_number','PO');
                        $st=$db->prepare("INSERT INTO purchase_orders (po_number, project_id, supplier_id, po_date, status, notes, currency_id, exch_rate, budget_currency_id, budget_exch_rate, total_base, total_budget, ppn_mode, ppn_rate) VALUES (:no,:p,:s,CAST(:d AS date),'Draft',:n,:c,:r,:bc,:br,:tb,:tbud,:pm,:prt) RETURNING id");
                        $st->execute([':no'=>$poNumber,':p'=>$prj,':s'=>$sup,':d'=>$date,':n'=>$notes,':c'=>$cur,':r'=>$rate,':bc'=>$pb['currency_id'],':br'=>$budRate,':tb'=>$totBase,':tbud'=>$totBud,':pm'=>$ppnMode,':prt'=>$ppnRate]);
                        $id=(int)$st->fetchColumn();
                    }
                    $stIns=$db->prepare("INSERT INTO po_items (po_id, budget_item_id, material_id, description, qty, unit, unit_price, currency_id, exch_rate, amount_base, amount_budget) VALUES (:po,:b,:m,:desc,:q,:u,:pr,:ic,:ir,:ab,:abud)");
                    foreach ($clean as $c) $stIns->execute([':po'=>$id,':b'=>$c['bid'],':m'=>$c['mid'],':desc'=>$c['name'],':q'=>$c['qty'],':u'=>$c['unit'],':pr'=>$c['price'],':ic'=>$cur,':ir'=>$rate,':ab'=>$c['amt_base'],':abud'=>$c['amt_budget']]);
                    $db->commit();
                    sendJsonResponse(true, ['id'=>$id,'po_number'=>$poNumber], "PO {$poNumber} berhasil disimpan.");
                } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            } catch (PoBusinessError $e) { sendJsonResponse(false, [], $e->getMessage()); }
            break;
        case 'change_status':
            $id=(int)($_POST['id']??0); $to=(string)($_POST['to']??'');
            $db->beginTransaction();
            try {
                $st=$db->prepare("SELECT po_number, status, supplier_id FROM purchase_orders WHERE id=:id FOR UPDATE"); $st->execute([':id'=>$id]); $po=$st->fetch(PDO::FETCH_ASSOC);
                if (!$po) throw new PoBusinessError('PO tidak ditemukan.');
                if (!in_array($to, PO_TRANSITIONS[$po['status']]??[], true)) throw new PoBusinessError("Status {$po['status']} tidak dapat diubah ke {$to}.");
                if (in_array($to,['Dibatalkan','Draft'],true) && po_received_total($db,$id)>PO_EPS) throw new PoBusinessError('PO ini sudah ada barang yang diterima (GRN), tidak dapat dibatalkan / dikembalikan ke Draft.');
                if (in_array($to,['Diajukan','Disetujui'],true)) { if (!$po['supplier_id']) throw new PoBusinessError('Supplier wajib diisi sebelum PO diajukan / disetujui.'); $st=$db->prepare("SELECT COUNT(*) FROM po_items WHERE po_id=:id"); $st->execute([':id'=>$id]); if ((int)$st->fetchColumn()===0) throw new PoBusinessError('PO belum memiliki item.'); }
                $st=$db->prepare("UPDATE purchase_orders SET status=:s, updated_at=NOW() WHERE id=:id"); $st->execute([':s'=>$to,':id'=>$id]);
                $db->commit(); sendJsonResponse(true, [], "PO {$po['po_number']} sekarang berstatus {$to}.");
            } catch (PoBusinessError $e) { if ($db->inTransaction()) $db->rollBack(); sendJsonResponse(false,[],$e->getMessage()); }
            catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;
        case 'delete_po':
            $id=(int)($_POST['id']??0);
            $db->beginTransaction();
            try {
                $st=$db->prepare("SELECT po_number, status FROM purchase_orders WHERE id=:id FOR UPDATE"); $st->execute([':id'=>$id]); $po=$st->fetch(PDO::FETCH_ASSOC);
                if (!$po) throw new PoBusinessError('PO tidak ditemukan.');
                if ($po['status']!=='Draft') throw new PoBusinessError('Hanya PO berstatus Draft yang dapat dihapus. Gunakan Batalkan untuk PO lain.');
                $st=$db->prepare("SELECT COUNT(*) FROM grn_hdr WHERE po_id=:id"); $st->execute([':id'=>$id]);
                if ((int)$st->fetchColumn()>0) throw new PoBusinessError('PO sudah memiliki GRN, tidak dapat dihapus.');
                $db->prepare("DELETE FROM po_items WHERE po_id=:id")->execute([':id'=>$id]);
                $db->prepare("DELETE FROM purchase_orders WHERE id=:id")->execute([':id'=>$id]);
                $db->commit(); sendJsonResponse(true, [], "PO {$po['po_number']} dihapus.");
            } catch (PoBusinessError $e) { if ($db->inTransaction()) $db->rollBack(); sendJsonResponse(false,[],$e->getMessage()); }
            catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;
        default: sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.'); break;
    }
} catch (PoBusinessError $e) { sendJsonResponse(false, [], $e->getMessage()); }
catch (PDOException $e) { error_log('[poCRD] '.$e->getMessage()); sendJsonResponse(false, [], 'Database Error: '.$e->getMessage()); }
catch (Throwable $e) { error_log('[poCRD] '.$e->getMessage()); sendJsonResponse(false, [], 'System Error: '.$e->getMessage()); }
