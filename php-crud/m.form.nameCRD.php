<?php
if (!isset($_SESSION)) { 
    session_start(); 
}
date_default_timezone_set('Asia/Jakarta');

// Mengaktifkan pelaporan error kecuali Notice selama masa transisi
error_reporting(E_ALL & ~E_NOTICE);

include_once "../php-class/crudClass.php";

header('Content-Type: application/json; charset=utf-8');
try {
    // 1. Amankan parameter input pagination & pencarian dari ExtJS
    $startParam = $_POST["start"] ?? 0;
    $offset     = (empty($startParam)) ? 0 : intval($startParam);

    $queryParam  = $_POST["query"] ?? '';
    $sResult     = searchData($queryParam, array("dt.title", "dt.php_url", "dt.js_url", "g.name"), 100);
    $limit       = isset($sResult["limit"]) ? intval($sResult["limit"]) : 100;
    $searchField = isset($sResult["searchSQL"]) ? $sResult["searchSQL"] : '';

    // 2. Amankan konversi data array iteminfo
    $itemInfoParam = $_POST["iteminfo"] ?? '';
    $dataArray = '';
    if (!empty($itemInfoParam)) {
        $decodedInfo = json_decode($itemInfoParam, true);
        if (is_array($decodedInfo)) {
            $dataArray = implode(",", array_map('intval', $decodedInfo));
        }
    }
    $cleanItemNum = (empty($dataArray)) ? "0" : $dataArray;

    $task = $_POST['task'] ?? 'read';

    switch ($task) {
        case "create":
            $queryC = '';
            $rawForm = $_POST["arrForm"] ?? '[]';
            $dataForm = !empty($rawForm) ? json_decode($rawForm) : [];

            $table = "m_form_name";
            $data = array(
                "tna"           => $_POST["_tna"] ?? null,
                "group_id"      => intval($_POST["group_id"] ?? 0),
                "seq"           => checksinglequote($_POST["seq"] ?? ''),
                "title"         => checksinglequote($_POST["title"] ?? ''),
                "php_url"       => checksinglequote($_POST["php_url"] ?? ''),
                "js_url"        => checksinglequote($_POST["js_url"] ?? ''),
                "department_id" => intval($_POST["department_id"] ?? 0),
                "main_table"    => checksinglequote($_POST["main_table"] ?? ''),
                "remark"        => checksinglequote($_POST["remark"] ?? ''),
                "active"           => $_POST["_active"] ?? null,

            );
            $queryC .= insertQ($table, $data) . ";";

            if (is_array($dataForm)) {
                foreach ($dataForm as $form) {
                    $table = "m_form_links";
                    $data = array(
                        "form_id"      => "currval('m_form_name_id_seq'::regclass)",
                        "link_form_id" => intval($form->link_form_id ?? 0)
                    );
                    $queryC .= insertQ($table, $data) . ";";
                }
            }

            $db = new crud();
            $db->cmdQ($queryC);
            echo $db->getResult();
            break;

        case 'read':
            $db = new crud();
            $field = "
                dt.id, dt.group_id, dt.seq, dt.title, dt.php_url, dt.js_url, dt.remark, g.name as group_name,
                g.seq as group_seq, dt.tna, dt.active
            ";
            $table = "
                (
                    select fr.id, fr.group_id, fr.seq, coalesce(fr.title,'') title,
                        coalesce(fr.php_url,'') php_url, coalesce(fr.js_url,'') js_url,
                        coalesce(fr.remark,'') remark, tna, active
                    from m_form_name fr
                ) dt
                left join (
                    select id, name, coalesce(remark,'') remark, seq
                    from (
                        (select d.id, h.name||' -- '||d.name as name, d.remark, d.seq
                        from m_form_group d
                        left join (
                            select name, seq from m_form_group where substring(seq,3,2)='00'
                        ) h on substring(h.seq,1,2)=substring(d.seq,1,2)
                        where substring(d.seq,3,2)<>'00' and substring(d.seq,1,2)<>'00')
                        union
                        (select h.id, h.name, h.remark, seq
                        from m_form_group h
                        where substring(seq,3,2)='00' and substring(seq,1,2)<>'00')
                    ) data
                    order by seq,name
                ) g on g.id = dt.group_id
            ";
            
            //$where = "length(btrim(dt.title)) > 0";
            $where = "coalesce(trim(dt.title), '') <> ''";
            if (!empty($searchField) && trim($searchField) != "") {
                $where .= " " . $searchField;
            }
            
            $order = "g.seq ASC, g.name ASC, dt.seq ASC";

            $db->select($table, $field, $where, $order, $limit, $offset);
            echo $db->getResult();
            break;

        case "read_edit":
            $db = new crud();
            $id = intval($_POST['id'] ?? 0);
            $table = "m_form_name";
            $field = "id, group_id, seq, title, php_url, js_url, remark, main_table, department_id, tna, active";
            $where = "id = " . $id;

            $db->select($table, $field, $where);
            echo $db->getResult();
            break;

        case "read_detail":
            $db = new crud();
            $formId = intval($_POST['form_id'] ?? 0);
            $field = "l.id link_id, l.link_form_id, f.id form_id, g.name as group_name, f.title, f.remark";
            $table = "
                m_form_links l
                left join m_form_name f on f.id = l.link_form_id
                left join (
                    select id, name, coalesce(remark,'') remark, seq
                    from (
                        (select d.id, h.name||' -- '||d.name as name, d.remark, d.seq
                        from m_form_group d
                        left join (
                            select name, seq from m_form_group where substring(seq,3,2)='00'
                        ) h on substring(h.seq,1,2)=substring(d.seq,1,2)
                        where substring(d.seq,3,2)<>'00' and substring(d.seq,1,2)<>'00')
                        union
                        (select h.id, h.name, h.remark, seq
                        from m_form_group h
                        where substring(seq,3,2)='00' and substring(seq,1,2)<>'00')
                    ) data
                ) g on g.id = f.group_id
            ";
            $where = "l.form_id = " . $formId;
            $order = "g.name ASC, f.seq ASC";

            $db->select($table, $field, $where, $order);
            echo $db->getResult();
            break;

        case 'addform':
            $db = new crud();
            $field = "f.id link_form_id, g.name as group_name, f.title, f.remark";
            $table = "
                m_form_name f
                left join (
                    select id, name, coalesce(remark,'') remark, seq
                    from (
                        (select d.id, h.name||' -- '||d.name as name, d.remark, d.seq
                        from m_form_group d
                        left join (
                            select name, seq from m_form_group where substring(seq,3,2)='00'
                        ) h on substring(h.seq,1,2)=substring(d.seq,1,2)
                        where substring(d.seq,3,2)<>'00' and substring(d.seq,1,2)<>'00')
                        union
                        (select h.id, h.name, h.remark, seq
                        from m_form_group h
                        where substring(seq,3,2)='00' and substring(seq,1,2)<>'00')
                    ) data
                ) g on g.id = f.group_id
            ";
            
            $where = "f.id NOT IN (" . $cleanItemNum . ")";
            if (!empty($searchField) && trim($searchField) != "") {
                $where .= " " . $searchField;
            }
            $order = "g.name ASC, f.seq ASC";

            $db->select($table, $field, $where, $order);
            echo $db->getResult();
            break;

        case 'HistoryData':
            $db = new crud();
            $id = intval($_POST['id'] ?? 0);
            $table = "view_single_table_log";
            $field = "
                tx_id, date, count,
                case amend_name
                    when 'group_id' then '[EDIT] - Group'
                    when 'seq' then '[EDIT] - Seq'
                    when 'title' then '[EDIT] - Title'
                    when 'php_url' then '[EDIT] - PHP URL'
                    when 'js_url' then '[EDIT] - JS URL'
                    when 'remark' then '[EDIT] - Remark'
                    else amend_name
                end ::character varying(200) AS amend_name,
                CASE
                    WHEN amend_name = 'group_id'
                        then (select name from view_form_group where id::int = before_amendment::int)
                    else before_amendment
                end as before_amendment,
                CASE
                    WHEN amend_name = 'group_id'
                        then (select name from view_form_group where id::int = after_amendment::int)
                    else after_amendment
                end as after_amendment,
                user_name, user_time
            ";
            $where = "tx_id::int = " . $id . " and table_name = 'm_form_name'";
            $orderBy = "count desc";

            $db->select('history', $table, $field, $where, $orderBy);
            echo $db->getResult();
            break;

        case 'update':
            $queryC = '';
            $formId = intval($_POST['id'] ?? 0);
            $rawForm = $_POST["arrForm"] ?? '[]';
            $dataForm = !empty($rawForm) ? json_decode($rawForm) : [];

            // Memeriksa dan membersihkan relasi form_links lama
            $dbCheck = new crud();
            $dbCheck->select('m_form_links', 'id, form_id', "form_id = " . $formId, "id ASC");
            $resCheckJSON = $dbCheck->getResult();
            $resCheckArr = json_decode($resCheckJSON, true);
            $dataCheck = $resCheckArr["data"] ?? [];

            for ($c = 0; $c < count($dataCheck); $c++) {
                $dataFound = 0;
                $cId = $dataCheck[$c]['id'];
                for ($a = 0; $a < count($dataForm); $a++) {
                    if (isset($dataForm[$a]->link_id) && $dataForm[$a]->link_id == $cId) {
                        $dataFound = 1;
                        break;
                    }
                }
                if ($dataFound == 0) {
                    $queryC .= deleteQ("m_form_links", "id = " . intval($cId)) . ";";
                }
            }

            $table = "m_form_name";
            $data = array(
                "tna"           => $_POST["_tna"] ?? null,
                "group_id"      => intval($_POST["group_id"] ?? 0),
                "seq"           => checksinglequote($_POST["seq"] ?? ''),
                "title"         => checksinglequote($_POST["title"] ?? ''),
                "php_url"       => checksinglequote($_POST["php_url"] ?? ''),
                "js_url"        => checksinglequote($_POST["js_url"] ?? ''),
                "department_id" => intval($_POST["department_id"] ?? 0),
                "main_table"    => checksinglequote($_POST["main_table"] ?? ''),
                "remark"        => checksinglequote($_POST["remark"] ?? ''),
                "active"        => $_POST["_active"] ?? null,

            );
            $where = "id = " . $formId;
            $queryC .= updateQ($table, $data, $where) . ";";

            if (is_array($dataForm)) {
                foreach ($dataForm as $form) {
                    if (empty($form->link_id)) {
                        $table = "m_form_links";
                        $data = array(
                            "form_id"      => $formId,
                            "link_form_id" => intval($form->link_form_id ?? 0)
                        );
                        $queryC .= insertQ($table, $data) . ";";
                    }
                }
            }

            $db = new crud();
            $db->cmdQ($queryC);
            echo $db->getResult();
            break;

        case 'delete':
            $queryC = '';
            $deleteId = intval($_POST['id'] ?? 0);

            if ($deleteId > 0) {
                $queryC .= deleteQ("m_form_links", "form_id = " . $deleteId) . ";";
                $queryC .= deleteQ("m_form_links", "link_form_id = " . $deleteId) . ";";
                $queryC .= deleteQ("m_user_access_menu", "form_id = " . $deleteId) . ";";
                $queryC .= deleteQ("m_form_name", "id = " . $deleteId) . ";";

                $db = new crud();
                $db->cmdQ($queryC);
                echo $db->getResult();
            } else {
                echo json_encode(["success" => "false", "status" => "Error", "message" => "ID Form tidak valid."]);
            }
            break;

        default:
            echo json_encode(["success" => "false", "status" => "Error", "message" => "Task tidak dikenali."]);
            break;
    }

} catch (Exception $e) {
    echo json_encode([
        "success" => false, 
        "status"  => "Error", 
        "message" => "Terjadi kesalahan sistem: " . $e->getMessage()
    ]);
}
?>