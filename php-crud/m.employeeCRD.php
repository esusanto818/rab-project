<?php
require_once __DIR__ . '/../php-func/user.valid.php';
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
if (in_array($task, ['update', 'edit'], true)) $requiredAccess = 3;
if (in_array($task, ['delete'], true)) $requiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $requiredAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi ini.']);
    exit;
}
$photoData = (string)($_POST['photo'] ?? '');
$foto = '';
$updateFoto = 0;
$photoName = 'default.jpg';
$dirPhoto = '../doc/hr/photo/';       // Relative URL/path stored for legacy views.
$photoFsDir = __DIR__ . '/../doc/hr/photo/'; // Filesystem path is anchored to this endpoint.

if ($photoData !== '' && strpos($photoData, ';') !== false) {
    if (!preg_match('~^data:image/(jpeg|png|gif|webp);base64,([A-Za-z0-9+/]+={0,2})$~D', $photoData, $photoMatch)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Format foto tidak valid.']);
        exit;
    }

    $foto = base64_decode($photoMatch[2], true);
    if ($foto === false || strlen($foto) > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Foto tidak valid atau melebihi batas 5 MB.']);
        exit;
    }

    $imageInfo = getimagesizefromstring($foto);
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $declaredMime = 'image/' . strtolower($photoMatch[1]);
    if (!$imageInfo || !isset($mimeToExt[$declaredMime]) || ($imageInfo['mime'] ?? '') !== $declaredMime) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Isi file bukan gambar yang sesuai.']);
        exit;
    }

    // Never use a client-supplied filename as a filesystem path.
    $photoName = 'emp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $mimeToExt[$declaredMime];
    $updateFoto = 1;
}

switch ($task) {
  // ==========================================
  // 1. CREATE
  // ==========================================
    case ("create"):
    global $conn;
    $deptId   = intval($_POST["department_id"] ?? 0);
    $statusId = intval($_POST["status_id"] ?? 0);
    $secId    = intval($_POST["section_id"] ?? 0);
    $fromDate = date('Y-m-d', strtotime($_POST["from_date"] ?? 'now'));
    $birthDate = date('Y-m-d', strtotime($_POST["birth_date"] ?? 'now'));
    $NikNO = CreateNikNo($deptId, $statusId, $fromDate);

    if ($updateFoto == 1) {
      if (!is_dir($photoFsDir) || !is_writable($photoFsDir)
          || file_put_contents($photoFsDir . $photoName, $foto, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Foto gagal disimpan. Periksa folder foto karyawan.']);
        exit;
      }
    }

    pg_query($conn, "BEGIN");
    try {
      $empCols = "nik, employee_name, address, id_number, id_address, npwp_no, bank_account_no, place_birth, phone_number, birth_date, gender, martial_status, emergency_name, emergency_phone, emergency_relation, emergency_address, from_date, active, remark, email";
      $empPlaceholders = "$1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, TRUE, $18, $19";
      $empParams = [
        $NikNO,
        (string)($_POST["employee_name"] ?? ''),
        (string)($_POST["address"] ?? ''),
        (string)($_POST["id_number"] ?? ''),
        (string)($_POST["id_address"] ?? ''),
        (string)($_POST["npwp_no"] ?? ''),
        (string)($_POST["bank_account_no"] ?? ''),
        (string)($_POST["place_birth"] ?? ''),
        (string)($_POST["phone_number"] ?? ''),
        $birthDate,
        (string)($_POST["genderValue"] ?? ''),
        (string)($_POST["martialValue"] ?? ''),
        (string)($_POST["emergency_name"] ?? ''),
        (string)($_POST["emergency_phone"] ?? ''),
        (string)($_POST["emergency_relation"] ?? ''),
        (string)($_POST["emergency_address"] ?? ''),
        $fromDate,
        (string)($_POST["remark"] ?? ''),
        (string)($_POST["email"] ?? '')
      ];
      if ($updateFoto == 1) {
        $empCols .= ", photo, photo_url";
        $empPlaceholders .= ", $20, $21";
        $empParams[] = $photoData;
        $empParams[] = $dirPhoto . $photoName;
      }
      $resEmp = pg_query_params($conn, "INSERT INTO m_employee ($empCols) VALUES ($empPlaceholders) RETURNING id", $empParams);
      if (!$resEmp) throw new Exception(pg_last_error($conn));
      $newEmpId = intval(pg_fetch_result($resEmp, 0, 'id'));

      $rDept = pg_query_params($conn, "INSERT INTO employee_department (employee_id, department_id, from_date) VALUES ($1, $2, $3)", [$newEmpId, $deptId, $fromDate]);
      if (!$rDept) throw new Exception(pg_last_error($conn));

      if ($secId > 0) {
        $rSec = pg_query_params($conn, "INSERT INTO employee_section (employee_id, section_id, from_date) VALUES ($1, $2, $3)", [$newEmpId, $secId, $fromDate]);
        if (!$rSec) throw new Exception(pg_last_error($conn));
      }

      $rStat = pg_query_params($conn, "INSERT INTO employee_status (employee_id, status_id, from_date) VALUES ($1, $2, $3)", [$newEmpId, $statusId, $fromDate]);
      if (!$rStat) throw new Exception(pg_last_error($conn));

      pg_query($conn, "COMMIT");
      echo json_encode(["success" => true, "status" => "Success", "message" => "Data karyawan berhasil disimpan."]);
    } catch (Throwable $e) {
      pg_query($conn, "ROLLBACK");
      http_response_code(500);
      echo json_encode(["success" => false, "status" => "Error", "message" => "Gagal menyimpan karyawan: " . $e->getMessage()]);
    }
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

    $where = "lower(dt.employee_name) LIKE " . pg_escape_literal($conn, '%' . strtolower((string)$searchField) . '%');
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
    global $conn;
    $empId     = intval($_POST['id'] ?? 0);
    $birthDate = date('Y-m-d', strtotime($_POST["birth_date"] ?? 'now'));
    $fromDate  = date('Y-m-d', strtotime($_POST["from_date"] ?? 'now'));

    pg_query($conn, "BEGIN");
    try {
      $updSql = "UPDATE m_employee SET employee_name=$1, address=$2, id_number=$3, id_address=$4, npwp_no=$5, bank_account_no=$6, place_birth=$7, phone_number=$8, birth_date=$9, gender=$10, martial_status=$11, emergency_name=$12, emergency_phone=$13, emergency_relation=$14, emergency_address=$15, from_date=$16, remark=$17, email=$18 WHERE id=$19";
      $updParams = [
        (string)($_POST["employee_name"] ?? ''),
        (string)($_POST["address"] ?? ''),
        (string)($_POST["id_number"] ?? ''),
        (string)($_POST["id_address"] ?? ''),
        (string)($_POST["npwp_no"] ?? ''),
        (string)($_POST["bank_account_no"] ?? ''),
        (string)($_POST["place_birth"] ?? ''),
        (string)($_POST["phone_number"] ?? ''),
        $birthDate,
        (string)($_POST["genderValue"] ?? ''),
        (string)($_POST["martialValue"] ?? ''),
        (string)($_POST["emergency_name"] ?? ''),
        (string)($_POST["emergency_phone"] ?? ''),
        (string)($_POST["emergency_relation"] ?? ''),
        (string)($_POST["emergency_address"] ?? ''),
        $fromDate,
        (string)($_POST["remark"] ?? ''),
        (string)($_POST["email"] ?? ''),
        $empId
      ];
      if (!pg_query_params($conn, $updSql, $updParams)) throw new Exception(pg_last_error($conn));

      if (($_POST["department_id"] ?? '') != ($_POST["transferDepartTo"] ?? '')) {
        $tDeptStart = date('Y-m-d', strtotime($_POST["transferDepartStart"] ?? 'now'));
        $tDeptPrev  = date('Y-m-d', strtotime('-1 day', strtotime($tDeptStart)));
        if (!pg_query_params($conn, "UPDATE employee_department SET to_date=$1 WHERE id=$2", [$tDeptPrev, intval($_POST["employee_department_id"] ?? 0)])) throw new Exception(pg_last_error($conn));
        if (!pg_query_params($conn, "INSERT INTO employee_department (employee_id, department_id, from_date) VALUES ($1, $2, $3)", [$empId, intval($_POST["transferDepartTo"] ?? 0), $tDeptStart])) throw new Exception(pg_last_error($conn));
      }

      if (($_POST["section_id"] ?? '') != ($_POST["transferSectionTo"] ?? '')) {
        $tSecStart = date('Y-m-d', strtotime($_POST["transferDepartStart"] ?? 'now'));
        $tSecPrev  = date('Y-m-d', strtotime('-1 day', strtotime($tSecStart)));
        if (!empty($_POST["employee_section_id"])) {
          if (!pg_query_params($conn, "UPDATE employee_section SET to_date=$1 WHERE id=$2", [$tSecPrev, intval($_POST["employee_section_id"])])) throw new Exception(pg_last_error($conn));
        }
        if (!pg_query_params($conn, "INSERT INTO employee_section (employee_id, section_id, from_date) VALUES ($1, $2, $3)", [$empId, intval($_POST["transferSectionTo"] ?? 0), $tSecStart])) throw new Exception(pg_last_error($conn));
      }

      if (($_POST["status_id"] ?? '') != ($_POST["changeStatusTo"] ?? '')) {
        $cStatStart = date('Y-m-d', strtotime($_POST["changeStatusStart"] ?? 'now'));
        $cStatPrev  = date('Y-m-d', strtotime('-1 day', strtotime($cStatStart)));
        if (!pg_query_params($conn, "UPDATE employee_status SET to_date=$1 WHERE id=$2", [$cStatPrev, intval($_POST["employee_status_id"] ?? 0)])) throw new Exception(pg_last_error($conn));
        if (!pg_query_params($conn, "INSERT INTO employee_status (employee_id, status_id, from_date) VALUES ($1, $2, $3)", [$empId, intval($_POST["changeStatusTo"] ?? 0), $cStatStart])) throw new Exception(pg_last_error($conn));
      }

      if ($updateFoto == 1) {
        file_put_contents($photoFsDir . $photoName, $foto, LOCK_EX);
        if (!pg_query_params($conn, "UPDATE m_employee SET photo=$1, photo_url=$2 WHERE id=$3", [$photoData, $dirPhoto . $photoName, $empId])) throw new Exception(pg_last_error($conn));
      }

      pg_query($conn, "COMMIT");
      echo json_encode(["success" => true, "status" => "Success", "message" => "Data karyawan berhasil diperbarui."]);
    } catch (Throwable $e) {
      pg_query($conn, "ROLLBACK");
      http_response_code(500);
      echo json_encode(["success" => false, "status" => "Error", "message" => "Gagal memperbarui karyawan: " . $e->getMessage()]);
    }
  break;

  // ==========================================
  // 6. DELETE
  // ==========================================
  case ('delete'):
    global $conn;
    $empId = intval($_POST["id"] ?? 0);
    $resDel = pg_query_params($conn, "UPDATE m_employee SET active = FALSE WHERE id = $1", [$empId]);
    if ($resDel) {
      echo json_encode(["success" => true, "status" => "Success", "message" => "Data karyawan dinonaktifkan."]);
    } else {
      http_response_code(500);
      echo json_encode(["success" => false, "status" => "Error", "message" => pg_last_error($conn)]);
    }
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