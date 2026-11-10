<?php
/**
 * clientsCRD.php
 * ============================================================
 * Backend Controller untuk Master Klien (public.clients).
 * Dipulihkan untuk clients.js -- kontrak task & field mengikuti
 * persis apa yang sudah dipanggil di JS tsb:
 *   list, read_edit, create, update, delete
 *
 * Catatan: projects.client_id punya ON DELETE SET NULL, jadi hapus
 * klien yang masih dipakai proyek AMAN di level database (proyeknya
 * tidak ikut terhapus, cuma client_id-nya jadi kosong) -- sesuai
 * peringatan yang sudah ditampilkan clients.js sebelum submit delete.
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';

// Endpoint harus memiliki sesi login dan hak minimal baca dari router.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
$access = (int)($_SESSION['FormAccess'] ?? 0);
if ($access < 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk membaca data klien.']);
    exit;
}

$db   = new crud();

function PrepOrFailCL($stmt, $context = '') {
    if ($stmt === false) {
        throw new Exception(($context ? "[{$context}] " : '') . 'Query gagal dijalankan.');
    }
    return $stmt;
}
function clFetchAll($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailCL($stmt, $ctx);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function clFetchOne($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailCL($stmt, $ctx);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function clExec($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailCL($stmt, $ctx);
    $stmt->execute($params);
    return $stmt;
}

$access = (int)($_SESSION['FormAccess'] ?? 0);

try {
    switch ($task) {

        case 'list':
            $sql = "SELECT c.id, c.name, c.contact_person, c.phone, c.email, c.address, c.created_at,
                           (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS project_count
                    FROM clients c
                    ORDER BY c.name ASC";
            $data = clFetchAll($db, $sql, [], 'list');
            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
            break;

        case 'read_edit':
            $id = (int)($_REQUEST['id'] ?? 0);
            $row = clFetchOne($db, "SELECT * FROM clients WHERE id = :id", ['id' => $id], 'read_edit');
            if (!$row) throw new Exception('Data klien tidak ditemukan.');
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // --------------------------------------------------------------
        case 'create':
        case 'update':
            if ($task === 'create' && $access < 2) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menambah klien.']);
                exit;
            }
            if ($task === 'update' && $access < 2) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk mengubah klien.']);
                exit;
            }

            $id             = (int)($_REQUEST['id'] ?? 0);
            $name           = trim($_REQUEST['name'] ?? '');
            $contact_person = trim($_REQUEST['contact_person'] ?? '');
            $phone          = trim($_REQUEST['phone'] ?? '');
            $email          = trim($_REQUEST['email'] ?? '');
            $address        = $_REQUEST['address'] ?? '';

            if ($name === '') throw new Exception('Nama Klien wajib diisi.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Format email tidak valid.');
            }

            if ($task === 'create') {
                $newId = (int)clFetchOne($db,
                    "INSERT INTO clients (name, contact_person, phone, email, address, created_at)
                     VALUES (:name, :cp, :phone, :email, :address, NOW())
                     RETURNING id",
                    ['name' => $name, 'cp' => $contact_person, 'phone' => $phone, 'email' => $email, 'address' => $address],
                    'insert clients')['id'];
                echo json_encode(['success' => true, 'id' => $newId, 'name' => $name, 'message' => "Klien '{$name}' berhasil ditambahkan."]);
            } else {
                if (!$id) throw new Exception('ID klien tidak valid.');
                clExec($db,
                    "UPDATE clients SET name = :name, contact_person = :cp, phone = :phone, email = :email, address = :address
                     WHERE id = :id",
                    ['name' => $name, 'cp' => $contact_person, 'phone' => $phone, 'email' => $email, 'address' => $address, 'id' => $id],
                    'update clients');
                echo json_encode(['success' => true, 'name' => $name, 'message' => "Klien '{$name}' berhasil diperbarui."]);
            }
            break;

        case 'delete':
            if ($access < 3) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus klien.']);
                exit;
            }
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID klien tidak valid.');

            clExec($db, "DELETE FROM clients WHERE id = :id", ['id' => $id], 'delete clients');
            echo json_encode(['success' => true, 'message' => 'Klien berhasil dihapus.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi kesalahan pada server.']);
}