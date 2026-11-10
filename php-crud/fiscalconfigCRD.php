<?php
/*
  Document      : Fiscal Config - CRUD Handler
  Called from   : js/m.fiscalconfig.js
  Note          : Tabel ini jadi rujukan AutoPostJournal() -- rule_code
                  dipakai modul transaksi (Sales Invoice, dll) untuk cari
                  akun debit/kredit yang benar saat generate jurnal otomatis.
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once   '../php-func/function.all.php';
require_once   '../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca konfigurasi fiskal tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read':
        $query = $_POST['query'] ?? null;
        $srch  = searchData($query, ['f.rule_code', 'f.rule_name']);

        $where = "1=1" . ($srch['searchSQL'] ?? '');
        $limit = $srch['limit'] ?? 250;

        $sql = "
            SELECT f.id, f.rule_code, f.rule_name, f.debit_account_id, f.credit_account_id, f.is_active,
                   d.account_code AS debit_code, d.account_name AS debit_name,
                   c.account_code AS credit_code, c.account_name AS credit_name
            FROM fiscal_config f
            LEFT JOIN chart_of_accounts d ON d.id = f.debit_account_id
            LEFT JOIN chart_of_accounts c ON c.id = f.credit_account_id
            WHERE {$where}
            ORDER BY f.rule_code ASC
            LIMIT " . intval($limit);

        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'status' => 'Success', 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    case 'read_edit':
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM fiscal_config WHERE id = :id");
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

        $ruleCode = trim($_POST['rule_code'] ?? '');
        if (empty($ruleCode)) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Rule Code wajib diisi.']);
            exit;
        }
        if (strlen($ruleCode) > 15) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Rule Code maksimal 15 karakter.']);
            exit;
        }

        $stmtChk = $db->prepare("SELECT id FROM fiscal_config WHERE rule_code = :code");
        $stmtChk->execute(['code' => $ruleCode]);
        if ($stmtChk->fetch()) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Rule Code ini sudah ada.']);
            exit;
        }

        if (empty($_POST['debit_account_id']) || empty($_POST['credit_account_id'])) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akun Debit dan Akun Kredit wajib dipilih.']);
            exit;
        }

        $db->insert('fiscal_config', [
            'rule_code'         => $ruleCode,
            'rule_name'         => trim($_POST['rule_name'] ?? ''),
            'debit_account_id'  => intval($_POST['debit_account_id']),
            'credit_account_id' => intval($_POST['credit_account_id']),
            'is_active'         => isset($_POST['is_active']) && $_POST['is_active'] === 'true'
        ]);
        echo $db->getResult();
        break;

        // ==============================================================
    // READ DETAIL (untuk panel detail di layar utama)
    // ==============================================================
    case 'read_detail':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => true, 'data' => new stdClass()]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT f.id, f.rule_code, f.rule_name, f.is_active,
                   d.account_code AS debit_code, d.account_name AS debit_name,
                   c.account_code AS credit_code, c.account_name AS credit_name
            FROM fiscal_config f
            LEFT JOIN chart_of_accounts d ON d.id = f.debit_account_id
            LEFT JOIN chart_of_accounts c ON c.id = f.credit_account_id
            WHERE f.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data ?: new stdClass()]);
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
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'ID tidak valid.']);
            exit;
        }

        $ruleCode = trim($_POST['rule_code'] ?? '');
        if (strlen($ruleCode) > 15) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Rule Code maksimal 15 karakter.']);
            exit;
        }

        $db->update('fiscal_config', [
            'rule_code'         => $ruleCode,
            'rule_name'         => trim($_POST['rule_name'] ?? ''),
            'debit_account_id'  => intval($_POST['debit_account_id']),
            'credit_account_id' => intval($_POST['credit_account_id']),
            'is_active'         => isset($_POST['is_active']) && $_POST['is_active'] === 'true'
        ], "id = {$id}");
        echo $db->getResult();
        break;

    // ==============================================================
    case 'delete':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $db->query("DELETE FROM fiscal_config WHERE id = {$id}");
        echo json_encode(['success' => true, 'message' => 'Rule berhasil dihapus.']);
        break;

    // ==============================================================
    default:
        echo json_encode(['success' => false, 'status' => 'Error', 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}