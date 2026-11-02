<?php
/*
Document      : m.expensesCRD.php
Description   : Backend CRUD Master Expense (sama pola dengan m.materialsCRD.php)
                - list: grid utama dengan paging & search
                - read_edit: load data untuk form edit
                - save: create / update
                - delete: soft-delete (set active=false)
Backend class : crud (PDO wrapper)
*/
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

session_start();
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';
$db = new crud();

function exp_send($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    switch ($task) {
        /* --------------------------------------------------------------
         * LIST - Grid utama
         * -------------------------------------------------------------- */
        case 'list':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $where = '';
            $params = [];
            if ($query !== '') {
                $where = "WHERE (expense_code ILIKE :q OR expense_name ILIKE :q OR category ILIKE :q)";
                $params[':q'] = '%' . $query . '%';
            }

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM expense_master {$where}");
            $stmtCount->execute($params);
            $totalCount = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT id, expense_code, expense_name, category, unit, default_price, active,
                       created_by, created_at, updated_by, updated_at
                FROM expense_master
                {$where}
                ORDER BY category, expense_name
                LIMIT :limit OFFSET :start
            ");
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['default_price'] = (float)$r['default_price'];
                $r['active'] = ($r['active'] === true || $r['active'] === 't' || $r['active'] === '1');
                $r['created_by'] = $r['created_by'] ? (int)$r['created_by'] : null;
                $r['updated_by'] = $r['updated_by'] ? (int)$r['updated_by'] : null;
            }
            unset($r);

            exp_send(true, ['data' => $rows, 'totalCount' => $totalCount]);
            break;

        /* --------------------------------------------------------------
         * READ_EDIT - Load data untuk form edit
         * -------------------------------------------------------------- */
        case 'read_edit':
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) exp_send(false, [], 'ID expense tidak valid.');

            $stmt = $db->prepare("
                SELECT id, expense_code, expense_name, category, unit, default_price, active
                FROM expense_master
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) exp_send(false, [], 'Expense tidak ditemukan.');

            $row['id'] = (int)$row['id'];
            $row['default_price'] = (float)$row['default_price'];

            exp_send(true, $row);
            break;

        /* --------------------------------------------------------------
         * SAVE - Create / Update
         * -------------------------------------------------------------- */
        case 'save':
        case 'create':
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['expense_code'] ?? ''));
            $name = trim((string)($_POST['expense_name'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? ''));
            $price = round((float)($_POST['default_price'] ?? 0), 2);
            $active = ($_POST['is_active'] === 'true' || $_POST['is_active'] === '1' || $_POST['is_active'] === true);

            // Validasi
            if ($code === '') exp_send(false, [], 'Kode Expense wajib diisi.');
            if (strlen($code) > 30) exp_send(false, [], 'Kode Expense maksimal 30 karakter.');
            if ($name === '') exp_send(false, [], 'Nama Expense wajib diisi.');
            if (strlen($name) > 150) exp_send(false, [], 'Nama Expense maksimal 150 karakter.');
            if ($category === '') exp_send(false, [], 'Kategori wajib dipilih.');
            if ($unit === '') exp_send(false, [], 'Satuan wajib diisi.');
            if ($price < 0) exp_send(false, [], 'Harga Satuan tidak boleh negatif.');

            // Cek duplikasi kode (kecuali saat edit record yang sama)
            $stmtChk = $db->prepare("SELECT id FROM expense_master WHERE expense_code = :code AND id <> :id");
            $stmtChk->execute([':code' => $code, ':id' => $id]);
            if ($stmtChk->fetchColumn()) {
                exp_send(false, [], 'Kode Expense "' . $code . '" sudah digunakan.');
            }

            $userId = (int)($_SESSION['user_id'] ?? 0);

            if ($id > 0) {
                // UPDATE
                $stmt = $db->prepare("
                    UPDATE expense_master
                    SET expense_code = :code,
                        expense_name = :name,
                        category = :category,
                        unit = :unit,
                        default_price = :price,
                        active = :active,
                        updated_by = :uid,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':code' => $code, ':name' => $name, ':category' => $category,
                    ':unit' => $unit, ':price' => $price, ':active' => $active ? 't' : 'f',
                    ':uid' => $userId, ':id' => $id
                ]);
                exp_send(true, ['id' => $id, 'expense_code' => $code], 'Expense berhasil diupdate.');
            } else {
                // CREATE
                $stmt = $db->prepare("
                    INSERT INTO expense_master
                    (expense_code, expense_name, category, unit, default_price, active, created_by)
                    VALUES (:code, :name, :category, :unit, :price, :active, :uid)
                    RETURNING id
                ");
                $stmt->execute([
                    ':code' => $code, ':name' => $name, ':category' => $category,
                    ':unit' => $unit, ':price' => $price, ':active' => $active ? 't' : 'f',
                    ':uid' => $userId
                ]);
                $newId = (int)$stmt->fetchColumn();
                exp_send(true, ['id' => $newId, 'expense_code' => $code], 'Expense baru berhasil disimpan.');
            }
            break;

        /* --------------------------------------------------------------
         * DELETE - Soft delete (set active = false)
         * -------------------------------------------------------------- */
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) exp_send(false, [], 'ID expense tidak valid.');

            $userId = (int)($_SESSION['user_id'] ?? 0);

            $stmt = $db->prepare("
                UPDATE expense_master
                SET active = false, updated_by = :uid, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':uid' => $userId, ':id' => $id]);

            exp_send(true, [], 'Expense berhasil dinonaktifkan.');
            break;

        /* --------------------------------------------------------------
         * LIST_OPTIONS - Untuk combo di RAB / modul lain
         * -------------------------------------------------------------- */
        case 'list_options':
            $category = trim($_REQUEST['category'] ?? '');
            $where = "WHERE active = true";
            $params = [];
            if ($category !== '' && $category !== 'All') {
                $where .= " AND category = :cat";
                $params[':cat'] = $category;
            }
            $stmt = $db->prepare("
                SELECT id, expense_code, expense_name, category, unit, default_price
                FROM expense_master
                {$where}
                ORDER BY category, expense_name
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['default_price'] = (float)$r['default_price'];
                $r['label'] = $r['expense_name'] . ' (' . $r['unit'] . ')';
            }
            unset($r);
            exp_send(true, $rows);
            break;

        default:
            exp_send(false, [], 'Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (PDOException $e) {
    error_log('[m.expensesCRD] PDO: ' . $e->getMessage());
    exp_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[m.expensesCRD] ' . $e->getMessage());
    exp_send(false, [], 'System Error: ' . $e->getMessage());
}