<?php
set_time_limit(0);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$searchField = $_POST["query"] ?? '';
$offset      = isset($_POST["start"]) ? intval($_POST["start"]) : 0;
$limit       = isset($_POST["limit"]) ? intval($_POST["limit"]) : 250;

$task       = $_POST['task'] ?? '';

// Otorisasi backend mandiri: login wajib dan hak operasi mengikuti FormAccess menu.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
$requiredAccess = 1;
if (in_array($task, ['create', 'add', 'save'], true)) $requiredAccess = 2;
if (in_array($task, ['update', 'edit'], true)) $requiredAccess = 2;
if (in_array($task, ['delete'], true)) $requiredAccess = 3;
if ((int)($_SESSION['FormAccess'] ?? 0) < $requiredAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi ini.']);
    exit;
}
$updateFoto = 0;
$foto       = $_POST['photo'] ?? '';

if (!empty($foto) && strpos($foto, ';') !== false) {
    list($type, $foto) = explode(';', $foto);
    list(, $foto)      = explode(',', $foto);
    $foto              = base64_decode($foto);
}

$updateFoto = (empty($foto)) ? 0 : 1;
$dirPhoto   = '../doc/hr/photo/';
$photoName  = $_POST['photoname'] ?? 'default.jpg';

switch ($task) {
  // ==========================================
  // 1. CREATE
  // ==========================================
  case ("create"):
    $NikNO = CreateNikNo($_POST["department_id"], $_POST["status_id"], $_POST["from_date"]);
    $queryC = '';
    if ($updateFoto == 1) {
      file_put_contents($dirPhoto.$photoName, $foto);
      $table = "m_employee";
      $data = array(
        "nik"                => $NikNO,
        "employee_name"     => checksinglequote($_POST["employee_name"]),
        "address"           => checksinglequote($_POST["address"]),
        "id_number"         => checksinglequote($_POST["id_number"]),
        "id_address"        => checksinglequote($_POST["id_address"]),
        "npwp_no"           => checksinglequote($_POST["npwp_no"]),
        "bank_account_no"   => checksinglequote($_POST["bank_account_no"]),
        "place_birth"       => checksinglequote($_POST["place_birth"]),
        "phone_number"      => checksinglequote($_POST["phone_number"]),
        "birth_date"        => date('Y-m-d', strtotime($_POST["birth_date"])),
        "gender"            => checksinglequote($_POST["genderValue"]),
        "martial_status"    => checksinglequote($_POST["martialValue"]),
        "emergency_name"    => checksinglequote($_POST["emergency_name"]),
        "emergency_phone"   => checksinglequote($_POST["emergency_phone"]),
        "emergency_relation"=> checksinglequote($_POST["emergency_relation"]),
        "emergency_address" => checksinglequote($_POST["emergency_address"]),
        "from_date"          => date('Y-m-d', strtotime($_POST["from_date"])),
        "remark"            => checksinglequote($_POST["remark"]),
        "email"             => checksinglequote($_POST["email"]),
        "active"            => true,
        "photo"             => $_POST['photo'],
        "photo_url"         => $dirPhoto.$photoName
      );
      $queryC .= insertQ($table, $data) . ";";
    } else {
      $table = "m_employee";
      $data = array(
        "nik"                => $NikNO,
        "employee_name"     => checksinglequote($_POST["employee_name"]),
        "address"           => checksinglequote($_POST["address"]),
        "id_number"         => checksinglequote($_POST["id_number"]),
        "id_address"        => checksinglequote($_POST["id_address"]),
        "npwp_no"           => checksinglequote($_POST["npwp_no"]),
        "bank_account_no"   => checksinglequote($_POST["bank_account_no"]),
        "place_birth"       => checksinglequote($_POST["place_birth"]),
        "phone_number"      => checksinglequote($_POST["phone_number"]),
        "birth_date"        => date('Y-m-d', strtotime($_POST["birth_date"])),
        "gender"            => checksinglequote($_POST["genderValue"]),
        "martial_status"    => checksinglequote($_POST["martialValue"]),
        "emergency_name"    => checksinglequote($_POST["emergency_name"]),
        "emergency_phone"   => checksinglequote($_POST["emergency_phone"]),
        "emergency_relation"=> checksinglequote($_POST["emergency_relation"]),
        "emergency_address" => checksinglequote($_POST["emergency_address"]),
        "from_date"          => date('Y-m-d', strtotime($_POST["from_date"])),
        "active"            => true,
        "remark"            => checksinglequote($_POST["remark"]),
        "email"             => checksinglequote($_POST["email"])
      );
      $queryC .= insertQ($table, $data) . ";";
    }

    $table = "employee_department";
    $data = array(
      "employee_id"   => "currval('m_employee_id_seq')",
      "department_id" => $_POST["department_id"],
      "from_date"     => date('Y-m-d', strtotime($_POST["from_date"]))
    );
    $queryC .= insertQ($table, $data) . ";";

    if (!empty($_POST["section_id"])) {
      $table = "employee_section";
      $data = array(
        "employee_id" => "currval('m_employee_id_seq')",
        "section_id"  => $_POST["section_id"],
        "from_date"   => date('Y-m-d', strtotime($_POST["from_date"]))
      );
      $queryC .= insertQ($table, $data) . ";";
    }

    $table = "employee_status";
    $data = array(
      "employee_id" => "currval('m_employee_id_seq')",
      "status_id"   => $_POST["status_id"],
      "from_date"   => date('Y-m-d', strtotime($_POST["from_date"]))
    );
    $queryC .= insertQ($table, $data) . ";";

    $db = new crud();
    $db->cmdQ($queryC);
    echo $db->getResult();
  break;

  // ==========================================
  // 2. READ (Grid Utama Karyawan)
  // ==========================================
  case ('read'):
    $db = new crud();

    $field = "
        dt.id, dt.nik, dt.employee_name, dt.birth_date, dt.gender, dt.from_date, dt.place_birth, dt.id_address, dt.bank_account_no,
        dt.to_date, dt.remark, dt.address, dt.id_number, dt.phone_number, dt.martial_status,
        dt.emergency_name, dt.emergency_phone, dt.emergency_relation, dt.emergency_address,
        d.location_init, d.location_name, d.employee_department_id, d.department_id, d.department_name, d.department_init,
        d.employee_section_id, d.section_id, d.section_name, d.employee_status_id, d.status_id, d.status_name, dt.email,
        dt.photo_url
    ";

    $table = "
        m_employee dt
        left join view_employee_depart_status d on coalesce(d.id, 0) = coalesce(dt.id, 0)
    ";

    $where = "lower(dt.employee_name) LIKE '%" . strtolower((string)$searchField) . "%'";
    $order = "dt.employee_name ASC";

    $db->select($table, $field, $where, $order, $limit, $offset);
    echo $db->getResult();
  break;

  // ==========================================
  // 3. READ EDIT (Load Data Detail Edit Form)
  // ==========================================
  case ("read_edit"):
    $db = new crud();
    $id = intval($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(["success" => "false", "status" => "Error", "message" => "Parameter ID karyawan tidak valid."]);
        break;
    }

    $table = "
        m_employee dt
        left join view_employee_depart_status d on coalesce(d.id, 0) = coalesce(dt.id, 0)
    ";
    
    $field = "
        dt.id, dt.nik, dt.employee_name, dt.birth_date, dt.gender, dt.from_date, dt.place_birth, dt.id_address, dt.bank_account_no,
        dt.to_date, dt.remark, dt.address, dt.id_number, dt.phone_number, dt.martial_status,
        dt.emergency_name, dt.emergency_phone, dt.emergency_relation, dt.emergency_address,
        d.location_init, d.location_name, d.employee_department_id, d.department_id, d.department_name, d.department_init,
        d.employee_section_id, d.section_id, d.section_name, d.employee_status_id, d.status_id, d.status_name, dt.email,
        dt.photo_url
    ";
    
    $where = "dt.id = " . $id;

    // Perbaikan: Gunakan method select() standar
    $db->select($table, $field, $where);
    echo $db->getResult();
  break;

  // ==========================================
  // 4. HISTORY DATA (Audit Log Update)
  // ==========================================
  case ('HistoryData'):
    $db = new crud();
    
    $table = "
      (
      select row_number() OVER (
          PARTITION BY table_name, tx_id ORDER BY table_name, tx_id, date, user_time
        ) AS count,
          table_name, tx_id, action, amend_name, before_amendment, after_amendment, user_name, date, user_time
      from (
          select table_name, tx_id, action, amend_name, before_amendment, after_amendment,
            user_name, date, user_time
          from view_single_table_log
          union
          select * from view_employee_status_log
          union
          select * from view_employee_department_log
      ) d
      order by table_name, tx_id, date, user_time
      ) a
    ";
    
    $field = "
      tx_id, date, count,
      case amend_name
        when 'employee_name' then '[EDIT] - Name'
        when 'address' then '[EDIT] - Address'
        when 'id_number' then '[EDIT] - ID Number'
        when 'phone_number' then '[EDIT] - Phone Number'
        when 'birth_date' then '[EDIT] - Birth Date'
        when 'gender' then '[EDIT] - Gender'
        when 'martial_status' then '[EDIT] - Martial Status'
        when 'emergency_name' then '[EDIT] - Emergency Contact Name'
        when 'emergency_phone' then '[EDIT] - Emergency Contact Phone'
        when 'emergency_address' then '[EDIT] - Emergency Address'
        when 'emergency_relation' then '[EDIT] - Emergency Relation'
        when 'from_date' then '[EDIT] - Hire Date'
        when 'remark' then '[EDIT] - Remarks'
        when 'email' then '[EDIT] - Email'
        when 'department' then '[EDIT] - Department'
        when 'status' then '[EDIT] - Employee Status'
        else amend_name
      end ::character varying(200) AS amend_name,
      CASE
        WHEN amend_name = 'department'
          then (select name from m_department where id::int = before_amendment::int)
        WHEN amend_name = 'status'
          then (select name from m_employee_status where id::int = before_amendment::int)
        else before_amendment
      end as before_amendment,
      CASE
        WHEN amend_name = 'department'
          then (select name from m_department where id::int = after_amendment::int)
        WHEN amend_name = 'status'
          then (select name from m_employee_status where id::int = after_amendment::int)
          else after_amendment
      end as after_amendment,
      user_name, user_time
    ";
    
    $where   = "tx_id::int = " . intval($_POST['id'] ?? 0) . " and table_name = 'm_employee'";
    $orderBy = "count desc";

    // Perbaikan: Gunakan method select() standar
    $db->select($table, $field, $where, $orderBy);
    echo $db->getResult();
  break;

  // ==========================================
  // 5. UPDATE
  // ==========================================
  case ('update'):
    $queryC = '';
    $table = "m_employee";
    $data = array(
      "employee_name"     => checksinglequote($_POST["employee_name"]),
      "address"           => checksinglequote($_POST["address"]),
      "id_number"         => checksinglequote($_POST["id_number"]),
      "id_address"        => checksinglequote($_POST["id_address"]),
      "npwp_no"           => checksinglequote($_POST["npwp_no"]),
      "bank_account_no"   => checksinglequote($_POST["bank_account_no"]),
      "place_birth"       => checksinglequote($_POST["place_birth"]),
      "phone_number"      => checksinglequote($_POST["phone_number"]),
      "birth_date"        => date('Y-m-d', strtotime($_POST["birth_date"])),
      "gender"            => checksinglequote($_POST["genderValue"]),
      "martial_status"    => checksinglequote($_POST["martialValue"]),
      "emergency_name"    => checksinglequote($_POST["emergency_name"]),
      "emergency_phone"   => checksinglequote($_POST["emergency_phone"]),
      "emergency_relation"=> checksinglequote($_POST["emergency_relation"]),
      "emergency_address" => checksinglequote($_POST["emergency_address"]),
      "from_date"          => date('Y-m-d', strtotime($_POST["from_date"])),
      "remark"            => checksinglequote($_POST["remark"]),
      "email"             => checksinglequote($_POST["email"])
    );
    $where = "id = " . intval($_POST['id']);
    $queryC .= updateQ($table, $data, $where) . ";";

    if ($_POST["department_id"] != $_POST["transferDepartTo"]) {
      $table = "employee_department";
      $data = array("to_date" => date('Y-m-d', strtotime('-1 day', strtotime($_POST["transferDepartStart"]))));
      $where = "id = " . intval($_POST["employee_department_id"]);
      $queryC .= updateQ($table, $data, $where) . ";";

      $table = "employee_department";
      $data = array(
        "employee_id"   => intval($_POST['id']),
        "department_id" => intval($_POST["transferDepartTo"]),
        "from_date"     => date('Y-m-d', strtotime($_POST["transferDepartStart"]))
      );
      $queryC .= insertQ($table, $data) . ";";
    }

    if ($_POST["section_id"] != $_POST["transferSectionTo"]) {
      if (!empty($_POST["employee_section_id"])) {
        $table = "employee_section";
        $data = array("to_date" => date('Y-m-d', strtotime('-1 day', strtotime($_POST["transferDepartStart"]))));
        $where = "id = " . intval($_POST["employee_section_id"]);
        $queryC .= updateQ($table, $data, $where) . ";";
      }
      $table = "employee_section";
      $data = array(
        "employee_id" => intval($_POST['id']),
        "section_id"  => intval($_POST["transferSectionTo"]),
        "from_date"   => date('Y-m-d', strtotime($_POST["transferDepartStart"]))
      );
      $queryC .= insertQ($table, $data) . ";";
    }

    if ($_POST["status_id"] != $_POST["changeStatusTo"]) {
      $table = "employee_status";
      $data = array("to_date" => date('Y-m-d', strtotime('-1 day', strtotime($_POST["changeStatusStart"]))));
      $where = "id = " . intval($_POST["employee_status_id"]);
      $queryC .= updateQ($table, $data, $where) . ";";

      $table = "employee_status";
      $data = array(
        "employee_id" => intval($_POST['id']),
        "status_id"   => intval($_POST["changeStatusTo"]),
        "from_date"   => date('Y-m-d', strtotime($_POST["changeStatusStart"]))
      );
      $queryC .= insertQ($table, $data) . ";";
    }

    if ($updateFoto == 1) {
      file_put_contents($dirPhoto.$photoName, $foto);
      $table = "m_employee";
      $data = array(
        "photo"     => $_POST['photo'],
        "photo_url" => $dirPhoto.$photoName
      );
      $where = "id = " . intval($_POST["id"]);
      $queryC .= updateQ($table, $data, $where) . ";";
    }

    $db = new crud();
    $db->cmdQ($queryC);
    echo $db->getResult();
  break;

  // ==========================================
  // 6. DELETE
  // ==========================================
  case ('delete'):
    $queryC = '';
    $table = "m_employee";
    $data = array("active" => 'false');
    $where = "id = " . intval($_POST["id"]);
    $queryC .= updateQ($table, $data, $where) . ";";

    $db = new crud();
    $db->cmdQ($queryC);
    echo $db->getResult();
  break;

  // ==========================================
  // 7. READ EMPLOYEE (Dropdown Combo / Lookup Store)
  // ==========================================
  case ('readEmployee'):
    $db = new crud();
    $field = "id employee_id, nik, employee_name, location_name, department_name, section_name";
    $table = "mat_view_employee_active";
    $where = "1=1";
    $order = "employee_name ASC";

    // Perbaikan: Gunakan method select() standar
    $db->select($table, $field, $where, $order, $limit, $offset);
    echo $db->getResult();
  break;

  default:
    echo json_encode(["success" => false, "message" => "Task tidak dikenali."]);
  break;
}
?>