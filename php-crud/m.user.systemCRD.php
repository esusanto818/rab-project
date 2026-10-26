<?php
set_time_limit(0);
if (!isset($_SESSION)) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$searchField = $_POST["query"] ?? $_REQUEST["query"] ?? '';
$offset      = isset($_POST["start"]) ? intval($_POST["start"]) : 0;
$limit       = isset($_POST["limit"]) ? intval($_POST["limit"]) : 250;

$itemInfoParam = $_POST["iteminfo"] ?? $_REQUEST["iteminfo"] ?? '';
$dataArray     = json2Array($itemInfoParam);
$CheckItemNum  = (empty($dataArray)) ? "[0]" : "[" . $dataArray . "]";
$CheckItemChar = (empty($dataArray)) ? "['0']" : "[" . $dataArray . "]";



// Tangkap task secara fleksibel dari POST maupun REQUEST
$task = $_POST['task'] ?? $_REQUEST['task'] ?? '';
$db   = new crud();

switch ($task) {

    // ==========================================
    // 1. READ MAIN GRID (Daftar Master User System)
    // ==========================================
    case ('read'):
        $sResult   = searchData($searchField, array("s.login_name", "e.employee_name"), 250);
        $limit     = $sResult["limit"];
        $searchSQL = $sResult["searchSQL"];

        $fields = "
            s.id, s.login_name, s.employee_id, s.active,
            '' as password, '' as confirm_password,
            e.employee_name, e.gender, e.from_date, 
            d.location_init||' ~ '||d.department_name as department_name, 
            d.status_name
        ";

        $table = "
            m_user_system s
            left join m_employee e on e.id = s.employee_id
            left join view_employee_depart_status d on coalesce(d.id, 0) = coalesce(e.id, 0)
        ";

        $where = "1=1 " . $searchSQL;
        $order = "e.employee_name ASC";

        $db->select($table, $fields, $where, $order, $limit, $offset);
        echo $db->getResult();
        break;

    // ==========================================
    // 2. READ DETAIL GRID (Rincian Hak Akses Menu User)
    // ==========================================
    case ("read_detail"):
        $id = intval($_POST['id'] ?? $_POST['user_id'] ?? $_REQUEST['id'] ?? $_REQUEST['user_id'] ?? 0);

        $fields = "
            u.id, u.form_id, u.access_level, u.user_id,
            fr.group_id, fr.seq, coalesce(fr.title,'') form_name, coalesce(fr.remark,'') remark,
            g.name as group_name, g.seq as group_seq, 'true' as active
        ";

        $table = "
            m_user_access_menu u
            left join m_form_name fr on fr.id = u.form_id
            left join (
                select id, name, coalesce(remark,'') remark, seq
                from (
                    (select d.id, h.name||'/'||d.name as name, d.remark, d.seq
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
                order by seq
            ) g on g.id = fr.group_id
        ";

        $where = "u.user_id = " . $id;
        $order = "g.seq ASC, fr.seq ASC";

        $db->select($table, $fields, $where, $order);
        echo $db->getResult();
        break;

    // ==========================================
    // 3. READ EDIT (Load Single Record untuk Dialog Edit)
    // ==========================================
    case ("read_edit"):
        $id = intval($_POST['id'] ?? $_REQUEST['id'] ?? 0);

        $fields = "
            s.id, s.login_name, s.employee_id, s.employee_id as name, s.active,
            '' as password, '' as confirm_password,
            e.employee_name, e.gender, e.from_date, 
            d.department_name, d.status_name
        ";

        $table = "
            m_user_system s
            left join m_employee e on e.id = s.employee_id
            left join view_employee_depart_status d on coalesce(d.id, 0) = coalesce(e.id, 0)
        ";

        $where = "s.id = " . $id;

        $db->select($table, $fields, $where);
        echo $db->getResult();
        break;

    // ==========================================
    // 4. ADD FORM (Load Daftar Form Pilihan)
    // ==========================================
    case ('addform'):
        $field = "f.id form_id, g.name as group_name, f.title form_name, f.remark, 1 as access_level, 'true' as active";
        $cleanItemNum = str_replace("'", "", $CheckItemNum);

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

        $where = "f.id <> all (array" . $cleanItemNum . ")";
        if (!empty($searchField) && trim($searchField) != "") {
            $where .= " " . $searchField;
        }

        $orderBy = "g.name ASC, f.seq ASC";

        $db->select($table, $field, $where, $orderBy);
        $result = $db->getResult();
        
        if (empty($result) || trim($result) == "") {
            echo json_encode(array("totalRecord" => 0, "data" => array()));
        } else {
            echo $result;
        }
        break;

    // ==========================================
    // 5. CREATE (Tambah User Baru)
    // ==========================================
    case ('create'):
    case ('add'):
        $employeeId = intval($_POST["employee_id"] ?? $_POST["name"] ?? 0);

        // Validasi Foreign Key Karyawan
        if ($employeeId <= 0) {
            echo json_encode([
                "success" => "false", 
                "status"  => "Error", 
                "message" => "Gagal: Pilih Karyawan (Name) yang valid terlebih dahulu!"
            ]);
            exit;
        }

        $queryC = '';
        $table  = "m_user_system";
        $data   = array(
            "login_name"  => checksinglequote($_POST["login_name"] ?? ''),
            "employee_id" => $employeeId,
            "password"    => EncodeData($_POST["password"] ?? ''),
            "active"      => ($_POST["u_active"] === 'true' || $_POST["active"] === 'true') ? 'true' : 'false'
        );
        $queryC .= insertQ($table, $data) . ";";

        $table = "m_user_login_status";
        $data  = array("user_id" => "currval('m_user_system_id_seq'::regclass)");
        $queryC .= insertQ($table, $data) . ";";

        // Insert Detail Hak Akses Menu (baris dari gridEditor saat New User)
        $formAccessPayload = $_POST['form_access'] ?? $_POST['arrForm'] ?? null;

        if (!empty($formAccessPayload)) {
            // Bersihkan quote escape dari ExtJS
            $cleanJson = stripslashes($formAccessPayload);
            $dataForm  = json_decode($cleanJson, true);

            if (is_array($dataForm) && count($dataForm) > 0) {
                foreach ($dataForm as $row) {
                    $formId    = intval($row['form_id'] ?? 0);
                    $accessLvl = intval($row['access_level'] ?? 1);

                    if ($formId > 0) {
                        $tableD = "m_user_access_menu";
                        $dataD  = array(
                            "user_id"      => "currval('m_user_system_id_seq'::regclass)",
                            "form_id"      => $formId,
                            "access_level" => $accessLvl
                        );
                        $queryC .= insertQ($tableD, $dataD) . ";";
                    }
                }
            }
        }

        $db->cmdQ($queryC);
        echo $db->getResult();
        break;

    // ==========================================
    // 6. UPDATE (Simpan Header User & Detail Hak Akses)
    // ==========================================
    case ('update'):
    case ('edit'):
        $queryC = '';
        $userId = intval($_POST['user_id'] ?? $_POST['id'] ?? 0);
        $employeeId = intval($_POST["employee_id"] ?? $_POST["name"] ?? 0);

        if ($userId > 0) {
            // Proteksi Fallback: Ambil employee_id dari DB jika nilainya 0
            if ($employeeId <= 0) {
                $checkStmt = $db->prepare("SELECT employee_id FROM m_user_system WHERE id = :id");
                $checkStmt->execute(['id' => $userId]);
                $existRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($existRow && !empty($existRow['employee_id'])) {
                    $employeeId = intval($existRow['employee_id']);
                }
            }

            if ($employeeId <= 0) {
                echo json_encode([
                    "success" => "false", 
                    "status"  => "Error", 
                    "message" => "Gagal Simpan: ID Karyawan tidak terdeteksi (Foreign Key Violation)."
                ]);
                exit;
            }

            // A. Update Master Header User System
            $table = "m_user_system";
            $data  = array(
                "login_name"  => checksinglequote($_POST["login_name"] ?? ''),
                "employee_id" => $employeeId,
                "active"      => ($_POST["u_active"] === 'true' || $_POST["u_active"] === true || $_POST["active"] === 'true') ? 'true' : 'false'
            );
            
            if (!empty($_POST["password"])) {
                $data["password"] = EncodeData($_POST["password"]);
            }

            $where = "id = " . $userId;
            $queryC .= updateQ($table, $data, $where) . ";";

            // B. Update / Insert Detail Hak Akses Menu
            $formAccessPayload = $_POST['form_access'] ?? $_POST['arrForm'] ?? null;

            if (!empty($formAccessPayload)) {
                // Bersihkan quote escape dari ExtJS
                $cleanJson = stripslashes($formAccessPayload);
                $dataForm  = json_decode($cleanJson, true);

                if (is_array($dataForm) && count($dataForm) > 0) {
                    $keepAccessIds = array();

                    foreach ($dataForm as $row) {
                        $accessId  = intval($row['id'] ?? 0);
                        $formId    = intval($row['form_id'] ?? 0);
                        $accessLvl = intval($row['access_level'] ?? 1);

                        if ($accessId > 0) {
                            // Update Detail Hak Akses Eksisting
                            $keepAccessIds[] = $accessId;
                            $tableD = "m_user_access_menu";
                            $dataD  = array("access_level" => $accessLvl);
                            $whereD = "id = " . $accessId;
                            $queryC .= updateQ($tableD, $dataD, $whereD) . ";";
                        } else if ($formId > 0) {
                            // Insert Detail Hak Akses Baru
                            // PENTING: dieksekusi langsung (di luar batch $queryC) dengan RETURNING id,
                            // supaya id barunya diketahui dan bisa dimasukkan ke $keepAccessIds SEBELUM
                            // statement DELETE cleanup di bawah dibangun. Kalau tidak, baris baru ini
                            // akan langsung ikut terhapus oleh DELETE ... id NOT IN (...) pada batch yang sama.
                            $insStmt = $db->prepare(
                                "INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, :fid, :lvl) RETURNING id"
                            );
                            $insStmt->execute([
                                'uid' => $userId,
                                'fid' => $formId,
                                'lvl' => $accessLvl
                            ]);
                            $newAccessId = $insStmt->fetchColumn();
                            if ($newAccessId) {
                                $keepAccessIds[] = intval($newAccessId);
                            }
                        }
                    }

                    // Hapus Detail Menu yang Dibuang/Dihapus pada Grid Editor
                    if (!empty($keepAccessIds)) {
                        $idsString = implode(',', $keepAccessIds);
                        $queryC .= "DELETE FROM m_user_access_menu WHERE user_id = " . $userId . " AND id NOT IN (" . $idsString . ");";
                    }
                }
            }

            $db->cmdQ($queryC);
            echo $db->getResult();
        } else {
            echo json_encode([
                "success" => "false", 
                "status"  => "Error", 
                "message" => "ID User tidak valid untuk proses update."
            ]);
        }
        break;

    // ==========================================
    // 7. DELETE (Hapus User System)
    // ==========================================
    case ('delete'):
        $deleteId = intval($_POST['id'] ?? $_REQUEST['id'] ?? 0);

        if ($deleteId > 0) {
            $table  = "m_user_system";
            $data   = array("active" => 'false');
            $where  = "id = " . $deleteId;
            $queryC = updateQ($table, $data, $where) . ";";

            $db->cmdQ($queryC);
            echo $db->getResult();
        } else {
            echo json_encode([
                "success" => "false", 
                "status"  => "Error", 
                "message" => "ID User tidak valid untuk proses penghapusan."
            ]);
        }
        break;

    default:
        echo json_encode([
            "success" => "false", 
            "status"  => "Error", 
            "message" => "Perintah task '" . htmlspecialchars($task) . "' tidak valid."
        ]);
        break;
}
?>