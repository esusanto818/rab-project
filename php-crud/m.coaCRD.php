<?php
/*
  Document      : Chart of Accounts - CRUD Handler
  Called from   : js/m.coa.js
  Note          : task 'read_parent' melayani combo Akun Induk (self-referencing)
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca Chart of Accounts tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read':
        $query = $_POST['query'] ?? null;
        $srch  = searchData($query, ['c.account_code', 'c.account_name', 'c.account_type']);

        $where = "1=1" . ($srch['searchSQL'] ?? '');
        $limit = $srch['limit'] ?? 250;

        $sql = "
            SELECT c.id, c.account_code, c.account_name, c.account_type,
                   c.parent_id, p.account_name AS parent_name, c.is_active
            FROM chart_of_accounts c
            LEFT JOIN chart_of_accounts p ON p.id = c.parent_id
            WHERE {$where}
            ORDER BY c.account_code ASC
            LIMIT " . intval($limit);

        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'status' => 'Success', 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    // Combo store utk pilihan Akun Induk (semua akun aktif)
    case 'read_parent':
        $sql = "SELECT id, account_code, account_name
                FROM chart_of_accounts
                WHERE is_active = true
                ORDER BY account_code ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    // Load 1 record utk form Edit -- Ext.form.BasicForm.load() butuh
    // `data` sebagai OBJEK tunggal, bukan array
    case 'read_edit':
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            SELECT c.*, p.account_name AS parent_name
            FROM chart_of_accounts c
            LEFT JOIN chart_of_accounts p ON p.id = c.parent_id
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => (bool)$row, 'data' => $row ?: new stdClass()]);
        break;

    // ==============================================================
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            exit;
        }

        if (empty(trim($_POST['account_code'] ?? ''))) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Kode akun wajib diisi.']);
            exit;
        }
        if (empty(trim($_POST['account_name'] ?? ''))) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Nama akun wajib diisi.']);
            exit;
        }

        $data = [
            'account_code' => trim($_POST['account_code']),
            'account_name' => trim($_POST['account_name']),
            'account_type' => $_POST['account_type'] ?? null,
            'parent_id'    => !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null,
            // FIX: sebelumnya cuma cocok kalau nilainya persis string 'true'
            // (strict ===), padahal JS sekarang kirim 1/0. filter_var dengan
            // FILTER_VALIDATE_BOOLEAN menerima 1/0/'true'/'false'/'on'/'off' dll.
            'is_active'    => filter_var($_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];

        $db->insert('chart_of_accounts', $data);
        echo $db->getResult();
        break;

    // ==============================================================
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'ID akun tidak valid.']);
            exit;
        }

        $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        if ($parentId === $id) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akun tidak boleh menjadi induk dari dirinya sendiri.']);
            exit;
        }

        $data = [
            'account_code' => trim($_POST['account_code'] ?? ''),
            'account_name' => trim($_POST['account_name'] ?? ''),
            'account_type' => $_POST['account_type'] ?? null,
            'parent_id'    => $parentId,
            // FIX: sama seperti task 'create' -- terima 1/0, bukan cuma
            // string 'true' persis.
            'is_active'    => filter_var($_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];

        $db->update('chart_of_accounts', $data, "id = {$id}");
        echo $db->getResult();
        break;

    // ==============================================================
    // Soft-delete only -- akun yang direferensikan fiscal_config atau
    // punya sub-akun sebaiknya tidak dihapus permanen, cukup nonaktifkan.
    case 'delete':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM chart_of_accounts WHERE parent_id = :id");
        $stmt->execute(['id' => $id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akun ini masih memiliki sub-akun. Nonaktifkan sub-akun terlebih dahulu.']);
            exit;
        }

        $db->update('chart_of_accounts', ['is_active' => false], "id = {$id}");
        echo $db->getResult();
        break;

    // ==============================================================
    default:
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}