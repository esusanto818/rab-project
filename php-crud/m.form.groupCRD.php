<?php
if (!isset($_SESSION)) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

// 1. Ambil parameter pencarian & paging
$startParam  = $_POST["start"] ?? 0;
$offset      = ($startParam == null) ? 0 : intval($startParam);

$queryParam  = $_POST["query"] ?? '';
$sResult     = searchData($queryParam, array("seq", "name", "remark"), 100);
$limit       = $sResult["limit"] ?? 100;
$searchField = $sResult["searchSQL"] ?? '';

$task  = $_POST['task'] ?? '';
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Sesi berakhir. Silakan login kembali."]);
    exit;
}
$formAdminAccess = (int)($_SESSION['FormAccess'] ?? 0);
$formRequiredAccess = 1;
if (in_array($task, ['create', 'add', 'update', 'edit', 'delete'], true)) $formRequiredAccess = 4;
if ($formAdminAccess < $formRequiredAccess) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Hak akses tidak mencukupi untuk pengelolaan menu."]);
    exit;
}

$db    = new crud();
$table = "m_form_group";

switch ($task) {

    // ==========================================
    // 1. READ (Ambil Data untuk Grid / List)
    // ==========================================
    case ('read'):
        $sResult   = searchData($queryParam, array("seq", "name", "remark"), 250);
        $limit     = $sResult["limit"];
        $searchSQL = $sResult["searchSQL"];

        $fields = "id, seq, name as grp_name, coalesce(remark,'') remark, coalesce(active, true) as active";
        $where  = "1=1 " . $searchSQL;
        $order  = "seq ASC, name ASC";

        // Pemanggilan yang BENAR: (tabel, fields, where, order, limit, offset)
        $db->select($table, $fields, $where, $order, $limit, $offset);
        echo $db->getResult();
    break;

    // ==========================================
    // 2. READ EDIT (Ambil Data Berdasarkan ID)
    // ==========================================
    case ('read_edit'):
        $id     = intval($_POST['id'] ?? 0);
        $fields = "id, seq, name as grp_name, coalesce(remark,'') remark, coalesce(active, true) as active";
        $where  = "id = " . $id;

        $db->select($table, $fields, $where);
        echo $db->getResult();
    break;

    // ==========================================
    // 3. HISTORY DATA (Log Perubahan)
    // ==========================================
    case ('HistoryData'):
        $id     = intval($_POST['id'] ?? 0);
        $fields = "
            tx_id, date, count,
            case amend_name
                when 'seq' then '[EDIT] - Sequence'
                when 'name' then '[EDIT] - Name'
                when 'remark' then '[EDIT] - Remark'
                else amend_name
            end ::character varying(200) AS amend_name,
            before_amendment,
            after_amendment,
            user_name, user_time
        ";
        $where   = "tx_id::int = " . $id . " and table_name = '" . $table . "'";
        $orderBy = "count desc";

        $db->select("view_single_table_log", $fields, $where, $orderBy);
        echo $db->getResult();
    break;

    // ==========================================
    // 4. CREATE / ADD
    // ==========================================
    case ('create'):
    case ('add'):
        $seq    = $_POST['seq'] ?? '';
        $name   = $_POST['grp_name'] ?? $_POST['name'] ?? '';
        $remark = $_POST['remark'] ?? '';
        // PDO pgsql tidak bisa bind boolean PHP native -- wajib string 't'/'f'.
        $active = ($_POST['active'] ?? 'true') === 'true' ? 't' : 'f';

        if (!empty($name)) {
            $data = array(
                "seq"    => checksinglequote($seq),
                "name"   => checksinglequote($name),
                "remark" => checksinglequote($remark),
                "active" => $active
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
                "message" => "Nama Group wajib diisi."
            ));
        }
    break;

    // ==========================================
    // 5. UPDATE / EDIT
    // ==========================================
    case ('update'):
    case ('edit'):
        $formId = intval($_POST['id'] ?? 0);
        $seq    = $_POST['seq'] ?? '';
        $name   = $_POST['grp_name'] ?? $_POST['name'] ?? '';
        $remark = $_POST['remark'] ?? '';
        // PDO pgsql tidak bisa bind boolean PHP native -- wajib string 't'/'f'.
        $active = ($_POST['active'] ?? 'true') === 'true' ? 't' : 'f';

        if ($formId > 0) {
            $data = array(
                "seq"    => checksinglequote($seq),
                "name"   => checksinglequote($name),
                "remark" => checksinglequote($remark),
                "active" => $active
            );
            $where = "id = " . $formId;

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
    // 6. DELETE
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
                "message" => "ID tidak valid untuk proses penghapusan."
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