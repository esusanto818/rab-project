<?php
if (!isset($_SESSION)) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

// 1. Ambil parameter pencarian & paging secara aman
$startParam  = $_POST["start"] ?? 0;
$offset      = ($startParam == null) ? 0 : intval($startParam);

$queryParam  = $_POST["query"] ?? '';
$sResult     = searchData($queryParam, array("dt.code", "dt.name", "dt.description", "dt.remark"), 100);
$limit       = $sResult["limit"] ?? 100;
$searchField = $sResult["searchSQL"] ?? '';

$task  = $_POST['task'] ?? '';
$db    = new crud();
$table = "warehouses";

switch ($task) {

    // ==========================================
    // 1. READ (Ambil Data Gudang untuk Grid/Store)
    // ==========================================
    case ('read'):
        $sResult   = searchData($queryParam, array("dt.code", "dt.name", "dt.description", "dt.remark"), 250);
        $limit     = $sResult["limit"];
        $searchSQL = $sResult["searchSQL"];

        $fields = "
            dt.id, dt.code , dt.name, dt.description, coalesce(dt.remark,'') remark, 
            dt.total_row, dt.col_row, l.id as location_id, l.name as location_name, l.init as location_init
        ";
        $tableJoin = "
            warehouses dt
            left join m_location l on l.id = dt.location_id
        ";
        $where = "1=1 " . $searchSQL;
        $order = "dt.id DESC";

        // Pemanggilan select yang BENAR: ($table, $fields, $where, $order, $limit, $offset)
        $db->select($tableJoin, $fields, $where, $order, $limit, $offset);
        echo $db->getResult();
    break;

    // ==========================================
    // 2. READ EDIT (Ambil Data Tunggal Berdasarkan ID)
    // ==========================================
    case ('read_edit'):
        $id     = intval($_POST['id'] ?? 0);
        $fields = "id, location_id, code , name, total_row, col_row, description, coalesce(remark,'') remark";
        $where  = "id = " . $id;

        $db->select($table, $fields, $where);
        echo $db->getResult();
    break;

    // ==========================================
    // 3. HISTORY DATA (Log Perubahan Warehouse)
    // ==========================================
    case ('HistoryData'):
        $id     = intval($_POST['id'] ?? 0);
        $fields = "
            tx_id, date, count,
            case amend_name
                when 'code' then '[EDIT] - Code'
                when 'name' then '[EDIT] - Name'
                when 'description' then '[EDIT] - Description'
                when 'remark' then '[EDIT] - Remark'
                when 'location_id' then '[EDIT] - Location'
                when 'total_row' then '[EDIT] - Total Row'
                when 'col_row' then '[EDIT] - Columns/Row'
                else amend_name
            end ::character varying(200) AS amend_name,
            before_amendment, after_amendment, user_name, user_time
        ";
        $where   = "tx_id::int = " . $id . " and table_name = '" . $table . "'";
        $orderBy = "count desc";

        $db->select("view_single_table_log", $fields, $where, $orderBy);
        echo $db->getResult();
    break;

    // ==========================================
    // 4. CREATE / ADD (Tambah Gudang Baru)
    // ==========================================
    case ('create'):
    case ('add'):
        $location_id = intval($_POST['location_id'] ?? 0);
        $code        = $_POST['code'] ?? '';
        $name        = $_POST['name'] ?? '';
        $total_row   = floatval(str_replace(",", "", $_POST['total_row'] ?? 0));
        $col_row     = floatval(str_replace(",", "", $_POST['col_row'] ?? 0));
        $description = $_POST['description'] ?? '';
        $remark      = $_POST['remark'] ?? '';

        if (!empty($name)) {
            $data = array(
                "location_id" => $location_id,
                "code"        => checksinglequote($code),
                "name"        => checksinglequote($name),
                "total_row"   => $total_row,
                "col_row"     => $col_row,
                "description" => checksinglequote($description),
                "remark"      => checksinglequote($remark)
            );

            if ($db->insert($table, $data)) {
                echo $db->getResult();
            } else {
                echo $db->getResult();
            }
        } else {
            echo json_encode(array(
                "success" => "false",
                "status"  => "Error",
                "message" => "Nama Gudang (Name) wajib diisi."
            ));
        }
    break;

    // ==========================================
    // 5. UPDATE / EDIT (Ubah Data Gudang)
    // ==========================================
    case ('update'):
    case ('edit'):
        $id          = intval($_POST['id'] ?? 0);
        $location_id = intval($_POST['location_id'] ?? 0);
        $code        = $_POST['code'] ?? '';
        $name        = $_POST['name'] ?? '';
        $total_row   = floatval(str_replace(",", "", $_POST['total_row'] ?? 0));
        $col_row     = floatval(str_replace(",", "", $_POST['col_row'] ?? 0));
        $description = $_POST['description'] ?? '';
        $remark      = $_POST['remark'] ?? '';

        if ($id > 0) {
            $data = array(
                "location_id" => $location_id,
                "code"        => checksinglequote($code),
                "name"        => checksinglequote($name),
                "total_row"   => $total_row,
                "col_row"     => $col_row,
                "description" => checksinglequote($description),
                "remark"      => checksinglequote($remark)
            );
            $where = "id = " . $id;

            if ($db->update($table, $data, $where)) {
                echo $db->getResult();
            } else {
                echo $db->getResult();
            }
        } else {
            echo json_encode(array(
                "success" => "false",
                "status"  => "Error",
                "message" => "ID tidak valid untuk proses update."
            ));
        }
    break;

    // ==========================================
    // 6. DELETE (Hapus Data Gudang)
    // ==========================================
    case ('delete'):
        $deleteId = intval($_POST['id'] ?? 0);

        if ($deleteId > 0) {
            $where  = "id = " . $deleteId;
            $queryC = deleteQ($table, $where) . ";";

            $db->cmdQ($queryC);
            echo $db->getResult();
        } else {
            echo json_encode(array(
                "success" => "false",
                "status"  => "Error",
                "message" => "ID tidak valid untuk proses penghapusan data."
            ));
        }
    break;

    default:
        echo json_encode(array(
            "success" => "false",
            "status"  => "Error",
            "message" => "Task atau perintah tidak dikenali."
        ));
    break;
}
?>