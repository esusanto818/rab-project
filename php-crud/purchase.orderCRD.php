<?php
require_once __DIR__ . '/../php-func/user.valid.php';
if (!isset($_SESSION)) {
    if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

// 1. Amankan penangkapan parameter awal & paging
$startParam  = $_POST["start"] ?? 0;
$offset      = ($startParam === null || $startParam === '') ? 0 : intval($startParam);

$queryParam  = $_POST["query"] ?? '';
$sResult     = searchData($queryParam, array("supplier_name", "ref_no"), 250);
$limit       = intval($sResult["limit"] ?? 250);
$searchSQL   = is_array($sResult["searchSQL"]) ? '' : strval($sResult["searchSQL"]);

$rawItemInfo = $_POST["iteminfo"] ?? '';
$dataArray   = !empty($rawItemInfo) ? json2Array($rawItemInfo) : [];

if (empty($dataArray)) {
    $CheckItemNum  = "[0]";
    $CheckItemChar = "['0']";
} else {
    $formattedData = is_array($dataArray) ? implode(",", $dataArray) : $dataArray;
    $CheckItemNum  = "[" . $formattedData . "]";
    $CheckItemChar = "['" . $formattedData . "']";
}

$db   = new crud();
$task = $_POST['task'] ?? 'read';

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

switch ($task) {

    // ==========================================
    // 1. CREATE / ADD (Tambah Purchase Order)
    // ==========================================
    case ("create"):
    case ("add"):
        $AddRefNo = CreateRefNo('po_hdr', 'ref_no', 'KMGI/PO');
        $ETADate  = empty($_POST["eta_date"]) ? null : date('Y-m-d', strtotime($_POST["eta_date"]));
        $queryC   = '';

        // Master PO Header
        $table = "po_hdr";
        $data  = array(
            "po_ppn"            => $_POST["ppn"] ?? 'false',
            "price_include_ppn" => $_POST["include_ppn"] ?? 'false',
            "ref_no"            => $AddRefNo,
            "tx_date"           => "now()",
            "eta_date"          => $ETADate,
            "supplier_id"       => intval($_POST["supplier_id"] ?? 0),
            "payment_period"    => floatval(str_replace(",", "", $_POST["payment_period"] ?? 0)),
            "curr_id"           => intval($_POST["currency_id"] ?? 0),
            "exch_rate"         => floatval(str_replace(",", "", $_POST["exch_rate"] ?? 1)),
            "disc"              => floatval(str_replace(",", "", $_POST["disc_po"] ?? 0)),
            "disc_percent"      => $_POST["disc_pcnt"] ?? 'false',
            "allowance"         => floatval(str_replace(",", "", $_POST["allowance"] ?? 0)),
            "allow_percent"     => $_POST["allow_pcnt"] ?? 'false',
            "remark"            => checksinglequote($_POST["remark"] ?? '')
        );
        $queryC .= insertQ($table, $data) . ";";

        // Biaya Tambahan (Costs)
        $rawCost  = $_POST["arrCostDtl"] ?? '[]';
        $dataCost = !empty($rawCost) ? json_decode($rawCost) : [];
        if (is_array($dataCost)) {
            foreach ($dataCost as $cost) {
                if (isset($cost->cost_id)) {
                    $table = "po_costs";
                    $data  = array(
                        "po_id"      => "currval('po_hdr_id_seq'::regclass)",
                        "cost_id"    => intval($cost->cost_id),
                        "amount"     => floatval(str_replace(",", "", $cost->amount ?? 0)),
                        "po_include" => $cost->po_include ?? 'false'
                    );
                    $queryC .= insertQ($table, $data) . ";";
                }
            }
        }

        // Detail Item PO
        $rawItem  = $_POST["arrItemDtl"] ?? '[]';
        $dataItem = !empty($rawItem) ? json_decode($rawItem) : [];
        if (is_array($dataItem)) {
            foreach ($dataItem as $item) {
                $itemId = intval($item->item_id ?? 0);
                if ($itemId > 0) {
                    $table = "po_dtl";
                    $data  = array(
                        "po_hdr_id"         => "currval('po_hdr_id_seq'::regclass)",
                        "qty"               => floatval(str_replace(",", "", $item->qty ?? 0)),
                        "unit_price"        => floatval(str_replace(",", "", $item->unit_price ?? 0)),
                        "remark"            => checksinglequote($item->remark ?? ''),
                        "additional_qty"    => floatval(str_replace(",", "", $item->additional_qty ?? 0)),
                        "disc_item"         => floatval(str_replace(",", "", $item->disc_item ?? 0)),
                        "disc_item_percent" => $item->disc_item_percent ?? 'false'
                    );
                    $queryC .= insertQ($table, $data) . ";";

                    // Cek Binding Document / Purchase Request via PDO select()
                    $checkReqDb = new crud();
                    $reqField   = "b.id";
                    $reqTable   = "view_item_req_status s left join item_req_binding_doc b on b.hdr_tx_id = s.hdr_tx_id and b.dtl_tx_id = s.dtl_tx_id";
                    $reqWhere   = "s.item_id = " . $itemId . " and coalesce(b.id::bigint, 0) > 0";
                    
                    $checkReqDb->select($reqTable, $reqField, $reqWhere);
                    $resReqJSON = $checkReqDb->getResult();
                    $resReqArr  = json_decode($resReqJSON, true);
                    $reqList    = $resReqArr["data"] ?? [];

                    foreach ($reqList as $reqObj) {
                        $reqBindingId = intval($reqObj["id"] ?? 0);
                        if ($reqBindingId > 0) {
                            $table = "po_dtl_doc_req";
                            $data  = array(
                                "po_dtl_id"      => "currval('po_dtl_id_seq'::regclass)",
                                "req_binding_id" => $reqBindingId
                            );
                            $queryC .= insertQ($table, $data) . ";";
                        }
                    }
                }
            }
        }

        $db->cmdQ($queryC);
        echo $db->getResult();
    break;

    // ==========================================
    // 2. READ (Daftar PO Header)
    // ==========================================
  // ==========================================
    // 1. READ (Daftar Purchase Order Main Grid)
    // ==========================================
    case ('read'):
        $queryParam = $_POST["query"] ?? '';
        $sResult    = searchData($queryParam, array("supplier_name", "ref_no"), 250);
        
        $limit     = intval($sResult["limit"] ?? 250);
        $searchSQL = is_array($sResult["searchSQL"]) ? '' : strval($sResult["searchSQL"]);
        
        $startParam = $_POST["start"] ?? 0;
        $offset     = ($startParam === null || $startParam === '') ? 0 : intval($startParam);

        $field = "
            id, ref_no, tx_date, eta_date, po_ppn, price_include_ppn, supplier_id, curr_id as currency_id, payment_period, payment, currency, currency_init,
            supplier_name as supplier, address, office_phone, office_fax, office_email, costs_list as cost_name, cost_amt, coalesce(remark, '') remark,
            allowance, allow_percent, disc as disc_po, disc_percent,
            (case when disc_percent = 't' then to_char(disc, '999D99') || ' %' else '0' end)::varchar(100) as disc, disc_amt as disc_payment,
            total_amt as total_amount, dpp,
            round((
                case when coalesce(dpp, 0) = 0 then total_amt - disc_amt + cost_amt
                else (((dpp * .1) + dpp) - disc_amt) + cost_amt
                end), CASE WHEN lower(currency_init) ~~ '%idr%'::text THEN 0 else 2 end) as total_payment
        ";
        $table = "view_po_hdr";
        $where = "1=1 " . $searchSQL;
        $order = "ref_no DESC";

        $db->select($table, $field, $where, $order, $limit, $offset);
        echo $db->getResult();
    break;

    // ==========================================
    // 2. READ EDIT (Header Single PO untuk Edit)
    // ==========================================
    case ("read_edit"):
    case ("readedit"):
        $poId  = intval($_POST['id'] ?? 0);
        $field = "
            id, ref_no, tx_date, eta_date, po_ppn, price_include_ppn, supplier_id, curr_id as currency_id, payment_period, payment, currency, currency_init,
            supplier_name as supplier, address, office_phone, office_fax, office_email, costs_list as cost_name, cost_amt, coalesce(remark, '') remark,
            allowance, allow_percent, disc as disc_po, disc_percent,
            (case when disc_percent = 't' then to_char(disc, '999D99') || ' %' else '0' end)::varchar(100) as disc, disc_amt as disc_payment,
            total_amt as total_amount, dpp,
            round((
                case when coalesce(dpp, 0) = 0 then total_amt - disc_amt + cost_amt
                else (((dpp * .1) + dpp) - disc_amt) + cost_amt
                end), CASE WHEN lower(currency_init) ~~ '%idr%'::text THEN 0 else 2 end) as total_payment
        ";
        $table = "view_po_hdr";
        $where = "id = " . $poId;

        $db->select($table, $field, $where);
        echo $db->getResult();
    break;

    // ==========================================
    // 3. READ DETAIL (List Item PO)
    // ==========================================
    case ("read_detail"):
        $poHdrId = intval($_POST['po_hdr_id'] ?? 0);
        $field   = "
            po_hdr_id, po_dtl_id, item_id, incl_ppn, item_name, um_unit, curr,
            coalesce(additional_qty, 0) as additional_qty, coalesce(req_qty, 0) as req_qty, po_qty as total_qty,
            unit_price, disc_item, disc_percent as disc_item_percent, disc, disc_amount as item_disc,
            line_total as total_item, coalesce(remark, '') remark
        ";
        $table   = "view_po_dtl";
        $where   = "po_hdr_id = " . $poHdrId;
        $order   = "item_name ASC";

        $db->select($table, $field, $where, $order);
        echo $db->getResult();
    break;

    // ==========================================
    // 4. ADD REQ (Lookup Item Request)
    // ==========================================
    case ('addreq'):
        $queryParam = $_POST["query"] ?? '';
        $sResult    = searchData($queryParam, array("dt.item_name"), $limit);
        $searchSQL  = is_array($sResult["searchSQL"]) ? '' : strval($sResult["searchSQL"]);

        $startParam = $_POST["start"] ?? 0;
        $offset     = ($startParam === null || $startParam === '') ? 0 : intval($startParam);

        $field = "item_id, item_name, size, um_unit, req_qty, issue_qty, os_qty";
        $table = "
            (
                select s.item_id, s.item_name, s.size, s.um_unit,
                    sum(coalesce(s.order_qty, 0)) as req_qty,
                    sum(coalesce(p.po_qty, 0)) as issue_qty,
                    (sum(coalesce(s.order_qty, 0)) - sum(coalesce(p.po_qty, 0))) as os_qty
                from view_item_req_status s
                left join item_req_binding_doc b on b.hdr_tx_id = s.hdr_tx_id and b.dtl_tx_id = s.dtl_tx_id
                left join (
                    select pr.req_binding_id, sum(coalesce(dt.qty, 0)) as po_qty
                    from po_dtl dt
                    left join po_dtl_doc_req pr ON dt.id = pr.po_dtl_id
                    group by pr.req_binding_id
                ) p on coalesce(p.req_binding_id, 0) = coalesce(b.id, 0)
                where b.purchase_status = true and b.completed = false
                group by s.item_id, s.item_name, s.size, s.um_unit
            ) dt
        ";
        $where = "
            dt.item_id <> all (array" . str_replace("'", "", $CheckItemNum) . ")
            and dt.os_qty > 0 " . $searchSQL;
        $order = "lower(dt.item_name) ASC";

        $db->select($table, $field, $where, $order, $limit, $offset);
        echo $db->getResult();
    break;

    // ==========================================
    // 5. READ COSTS (Pilihan Biaya Tambahan)
    // ==========================================
    case ('readcosts'):
        $costStatus = $_POST['costStatus'] ?? 'local';
        $poId       = intval($_POST['po_id'] ?? 0);
        $where      = ($costStatus === 'local') ? "cs.purchase_local is true" : "cs.purchase_overseas is true";

        $field = "cs.id as cost_id, cs.name as cost_name, dt.po_id, dt.po_cost_id, dt.amount, dt.po_include";
        $table = "
            m_costs cs
            left join (
                select c.id, c.name, p.po_id, p.id as po_cost_id, p.amount, p.po_include
                from m_costs c
                left join po_costs p on c.id = p.cost_id and p.po_id = " . $poId . "
            ) dt on coalesce(cs.id, 0) = coalesce(dt.id, 0)
        ";
        $order = "cs.name ASC";

        $db->select($table, $field, $where, $order);
        echo $db->getResult();
    break;
    // ==========================================
    // 7. HISTORY DATA LOG
    // ==========================================
    case ('HistoryData'):
        $poId  = intval($_POST['id'] ?? 0);
        $field = "
            tx_id, date, count,
            case amend_name
                when 'ref_no' then '[EDIT] - Ref No'
                when 'supplier_id' then '[EDIT] - Supplier'
                when 'remark' then '[EDIT] - Remark'
                else amend_name
            end ::character varying(200) AS amend_name,
            before_amendment, after_amendment, user_name, user_time
        ";
        $table   = "view_single_table_log";
        $where   = "tx_id::int = " . $poId . " and table_name = 'po_hdr'";
        $orderBy = "count desc";

        $db->select('history', $table, $field, $where, $orderBy);
        echo $db->getResult();
    break;

    // ==========================================
    // 8. UPDATE (Ubah / Edit PO)
    // ==========================================
    case ('update'):
    case ('edit'):
        $poId     = intval($_POST['id'] ?? 0);
        $ETADate  = empty($_POST["eta_date"]) ? null : date('Y-m-d', strtotime($_POST["eta_date"]));
        $queryC   = '';

        if ($poId <= 0) {
            echo json_encode(["success" => "false", "status" => "Error", "message" => "ID PO tidak valid."]);
            break;
        }

        $rawItem  = $_POST["arrItemDtl"] ?? '[]';
        $dataItem = !empty($rawItem) ? json_decode($rawItem) : [];
        $rawCost  = $_POST["arrCostDtl"] ?? '[]';
        $dataCost = !empty($rawCost) ? json_decode($rawCost) : [];

        // 1. Cek & Hapus Item PO yang dihilangkan
        $checkItemDb = new crud();
        $checkItemDb->select("po_dtl", "id", "po_hdr_id = " . $poId, "id ASC");
        $resItemJSON = $checkItemDb->getResult();
        $resItemArr  = json_decode($resItemJSON, true);
        $dataCheckItem = $resItemArr["data"] ?? [];

        foreach ($dataCheckItem as $rowOld) {
            $oldDtlId = $rowOld['id'];
            $found    = false;

            if (is_array($dataItem)) {
                foreach ($dataItem as $item) {
                    if (isset($item->po_dtl_id) && intval($item->po_dtl_id) === intval($oldDtlId)) {
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                $queryC .= deleteQ("po_dtl_doc_req", "po_dtl_id = " . intval($oldDtlId)) . ";";
                $queryC .= deleteQ("po_dtl", "id = " . intval($oldDtlId)) . ";";
            }
        }

        // 2. Cek & Hapus Biaya (Costs) PO yang dihilangkan
        $checkCostDb = new crud();
        $checkCostDb->select("po_costs", "id", "po_id = " . $poId, "id ASC");
        $resCostJSON = $checkCostDb->getResult();
        $resCostArr  = json_decode($resCostJSON, true);
        $dataCheckCost = $resCostArr["data"] ?? [];

        foreach ($dataCheckCost as $rowOld) {
            $oldCostId = $rowOld['id'];
            $found     = false;

            if (is_array($dataCost)) {
                foreach ($dataCost as $cost) {
                    if (isset($cost->po_cost_id) && intval($cost->po_cost_id) === intval($oldCostId)) {
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                $queryC .= deleteQ("po_costs", "id = " . intval($oldCostId)) . ";";
            }
        }

        // 3. Update Header PO
        $table = "po_hdr";
        $data  = array(
            "po_ppn"            => $_POST["ppn"] ?? 'false',
            "price_include_ppn" => $_POST["include_ppn"] ?? 'false',
            "eta_date"          => $ETADate,
            "supplier_id"       => intval($_POST["supplier_id"] ?? 0),
            "payment_period"    => floatval(str_replace(",", "", $_POST["payment_period"] ?? 0)),
            "curr_id"           => intval($_POST["currency_id"] ?? 0),
            "exch_rate"         => floatval(str_replace(",", "", $_POST["exch_rate"] ?? 1)),
            "disc"              => floatval(str_replace(",", "", $_POST["disc_po"] ?? 0)),
            "disc_percent"      => $_POST["disc_pcnt"] ?? 'false',
            "allowance"         => floatval(str_replace(",", "", $_POST["allowance"] ?? 0)),
            "allow_percent"     => $_POST["allow_pcnt"] ?? 'false',
            "remark"            => checksinglequote($_POST["remark"] ?? '')
        );
        $where = "id = " . $poId;
        $queryC .= updateQ($table, $data, $where) . ";";

        // 4. Save / Update Detail Item PO
        if (is_array($dataItem)) {
            foreach ($dataItem as $item) {
                $poDtlId = intval($item->po_dtl_id ?? 0);
                $itemId  = intval($item->item_id ?? 0);

                if ($poDtlId <= 0) {
                    // Tambah Item Baru
                    $table = "po_dtl";
                    $data  = array(
                        "po_hdr_id"         => $poId,
                        "qty"               => floatval(str_replace(",", "", $item->qty ?? 0)),
                        "unit_price"        => floatval(str_replace(",", "", $item->unit_price ?? 0)),
                        "remark"            => checksinglequote($item->remark ?? ''),
                        "additional_qty"    => floatval(str_replace(",", "", $item->additional_qty ?? 0)),
                        "disc_item"         => floatval(str_replace(",", "", $item->disc_item ?? 0)),
                        "disc_item_percent" => $item->disc_item_percent ?? 'false'
                    );
                    $queryC .= insertQ($table, $data) . ";";

                    // Bind Item Request
                    if ($itemId > 0) {
                        $checkReqDb = new crud();
                        $reqField   = "b.id";
                        $reqTable   = "view_item_req_status s left join item_req_binding_doc b on b.hdr_tx_id = s.hdr_tx_id and b.dtl_tx_id = s.dtl_tx_id";
                        $reqWhere   = "s.item_id = " . $itemId . " and coalesce(b.id::bigint, 0) > 0";

                        $checkReqDb->select($reqTable, $reqField, $reqWhere);
                        $resReqJSON = $checkReqDb->getResult();
                        $resReqArr  = json_decode($resReqJSON, true);
                        $reqList    = $resReqArr["data"] ?? [];

                        foreach ($reqList as $reqObj) {
                            $reqBindingId = intval($reqObj["id"] ?? 0);
                            if ($reqBindingId > 0) {
                                $table = "po_dtl_doc_req";
                                $data  = array(
                                    "po_dtl_id"      => "currval('po_dtl_id_seq'::regclass)",
                                    "req_binding_id" => $reqBindingId
                                );
                                $queryC .= insertQ($table, $data) . ";";
                            }
                        }
                    }
                } else {
                    // Update Item yang Ada
                    $table = "po_dtl";
                    $data  = array(
                        "qty"               => floatval(str_replace(",", "", $item->qty ?? 0)),
                        "unit_price"        => floatval(str_replace(",", "", $item->unit_price ?? 0)),
                        "remark"            => checksinglequote($item->remark ?? ''),
                        "additional_qty"    => floatval(str_replace(",", "", $item->additional_qty ?? 0)),
                        "disc_item"         => floatval(str_replace(",", "", $item->disc_item ?? 0)),
                        "disc_item_percent" => $item->disc_item_percent ?? 'false'
                    );
                    $where  = "id = " . $poDtlId . " AND po_hdr_id = " . $poId;
                    $queryC .= updateQ($table, $data, $where) . ";";
                }
            }
        }

        // 5. Save / Update Detail Costs PO
        if (is_array($dataCost)) {
            foreach ($dataCost as $cost) {
                $poCostId = intval($cost->po_cost_id ?? 0);

                if ($poCostId <= 0) {
                    // Tambah Cost Baru
                    $table = "po_costs";
                    $data  = array(
                        "po_id"      => $poId,
                        "cost_id"    => intval($cost->cost_id ?? 0),
                        "amount"     => floatval(str_replace(",", "", $cost->amount ?? 0)),
                        "po_include" => $cost->po_include ?? 'false'
                    );
                    $queryC .= insertQ($table, $data) . ";";
                } else {
                    // Update Cost yang Ada
                    $table = "po_costs";
                    $data  = array(
                        "amount"     => floatval(str_replace(",", "", $cost->amount ?? 0)),
                        "po_include" => $cost->po_include ?? 'false'
                    );
                    $where  = "id = " . $poCostId . " AND po_id = " . $poId;
                    $queryC .= updateQ($table, $data, $where) . ";";
                }
            }
        }

        $db->cmdQ($queryC);
        echo $db->getResult();
    break;

    // ==========================================
    // 9. DELETE (Hapus Master PO Beserta Relasinya)
    // ==========================================
    case ('delete'):
        $poId   = intval($_POST['id'] ?? 0);
        $queryC = '';

        if ($poId > 0) {
            // Hapus Biaya
            $table  = "po_costs";
            $where  = "po_id = " . $poId;
            $queryC .= deleteQ($table, $where) . ";";

            // Hapus Relasi Item Req & Detail Item
            $queryC .= "DELETE FROM po_dtl_doc_req WHERE po_dtl_id IN (SELECT id FROM po_dtl WHERE po_hdr_id = " . $poId . ");";
            $queryC .= deleteQ("po_dtl", "po_hdr_id = " . $poId) . ";";

            // Hapus Header
            $queryC .= deleteQ("po_hdr", "id = " . $poId) . ";";

            $db->cmdQ($queryC);
            echo $db->getResult();
        } else {
            echo json_encode([
                "success" => "false",
                "status"  => "Error",
                "message" => "ID PO tidak valid untuk proses penghapusan."
            ]);
        }
    break;

    default:
        echo json_encode([
            "success" => "false",
            "status"  => "Error",
            "message" => "Task atau perintah tidak dikenali oleh sistem."
        ]);
    break;
}
?>