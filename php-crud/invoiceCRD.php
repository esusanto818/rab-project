<?php
/*
  Document      : Sales Invoice - CRUD Handler
  Called from   : js/m.proyekoice.js
  Note          : - Status: draft -> final. Finalisasi terjadi otomatis saat
                    tombol Print diklik (task 'finalize', dipanggil sebelum
                    window print dibuka).
                  - Saat finalize, jurnal otomatis di-generate via
                    AutoPostJournal() (rule 'SLSCREDIT': Debit Piutang Usaha,
                    Kredit Pendapatan Penjualan) -- lihat function.all.php.
                  - Invoice draft masih bisa diedit/dihapus bebas. Invoice
                    final terkunci (tidak bisa edit/hapus lagi).
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
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca invoice tidak tersedia.']); exit; }
if (in_array($task, ['create', 'update', 'finalize', 'delete'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode harus POST untuk perubahan invoice.']);
    exit;
}
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read':
        $query = $_POST['query'] ?? null;
        $srch  = searchData($query, ['i.proyekoice_no']);

        $where = "1=1" . ($srch['searchSQL'] ?? '');
        $limit = $srch['limit'] ?? 250;

        $sql = "
            SELECT i.id, i.proyekoice_no, i.so_id, so.ref_no AS so_ref_no,
                   i.customer_id, c.name AS customer_name,
                   i.proyekoice_date, i.due_date, i.total_amount, i.paid_amount, i.status
            FROM proyekoices i
            LEFT JOIN sales_order so ON so.id = i.so_id
            LEFT JOIN m_customer c ON c.id = i.customer_id
            WHERE {$where}
            ORDER BY i.proyekoice_date DESC, i.id DESC
            LIMIT " . intval($limit);

        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'status' => 'Success', 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    case 'read_edit':
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM proyekoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => (bool)$row, 'data' => $row ?: new stdClass()]);
        break;

    // ==============================================================
    case 'read_items':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => true, 'totalCount' => 0, 'data' => []]);
            exit;
        }
        $stmt = $db->prepare("SELECT id AS line_id, description, qty, unit_price, subtotal FROM proyekoice_items WHERE proyekoice_id = :id ORDER BY id ASC");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    // Combo Sales Order (untuk field so_id, wajib diisi)
    case 'read_so':
        $sql = "
            SELECT so.id, so.ref_no, so.tx_date, mc.name AS legacy_customer_name
            FROM sales_order so 
            LEFT JOIN m_customer mc ON mc.id = so.customer_id
            WHERE (so.active = true or so.active IS null) 
                and so.id NOT IN (
            	    SELECT distinct i.so_id FROM proyekoice_items t 
            	    left join proyekoices i on i.id=t.proyekoice_id
            	    WHERE t.so_item_id IS NOT NULL
              	)
            ORDER BY so.tx_date DESC
            LIMIT 200
        ";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    // Item dari Sales Order terpilih yang BELUM pernah dipakai di proyekoice
    // manapun (so_item_id belum muncul di proyekoice_items manapun).
    case 'read_so_items':
        $soId = intval($_POST['so_id'] ?? 0);
        if ($soId <= 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        $sql = "
      SELECT soi.id AS so_item_id, soi.qty_unit as qty, 
       case
                when soi.disc_percent is true then (coalesce(soi.unit_price,0) - (coalesce(soi.unit_price,0) * (coalesce(soi.disc,0) / 100)))
                else (coalesce(soi.unit_price,0) - coalesce(soi.disc,0))
            end::numeric(14,2) as unit_price,
        case
                when soi.disc_percent is true then (coalesce(soi.unit_price,0) - (coalesce(soi.unit_price,0) * (coalesce(soi.disc,0) / 100))) * coalesce(soi.qty_unit,0)
                else (coalesce(soi.unit_price,0) - coalesce(soi.disc,0)) * soi.qty_unit
            end::numeric(14,2) as subtotal,
       i.name AS product_name
            FROM sales_order_item soi
            LEFT JOIN items i  ON i.id = soi.item_id
            WHERE soi.order_id= :so_id
              AND soi.id NOT IN (
                  SELECT so_item_id FROM proyekoice_items WHERE so_item_id IS NOT NULL
              )
            ORDER BY soi.id asc
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['so_id' => $soId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Fallback deskripsi: pakai nama produk kalau kolom description kosong
        foreach ($data as &$d) {
            if (empty($d['description'])) $d['description'] = $d['product_name'];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    // Combo Customer (untuk pilih manual kalau perlu override)
    case 'read_customer':
        $sql = "SELECT id, name FROM m_customer WHERE active = true ORDER BY name ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        saveInvoice($db, null, $_POST);
        break;

    // ==============================================================
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID proyekoice tidak valid.']);
            exit;
        }
        $stmtChk = $db->prepare("SELECT status FROM proyekoices WHERE id = :id");
        $stmtChk->execute(['id' => $id]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] !== 'draft') {
            echo json_encode(['success' => false, 'message' => 'Hanya proyekoice berstatus Draft yang boleh diedit.']);
            exit;
        }
        saveInvoice($db, $id, $_POST);
        break;

    // ==============================================================
    // Finalize -- dipanggil otomatis oleh JS saat tombol Print diklik.
    // Ubah status draft -> final, DAN generate jurnal otomatis (Tahap 6).
    case 'finalize':
        // Finalisasi mengunci invoice Draft dan memicu posting jurnal otomatis; wajib hak ubah (level 3).
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID invoice tidak valid.']);
            exit;
        }

        // Status invoice dan jurnal harus berubah bersama-sama; bila posting gagal, transaksi di-rollback.
        $db->beginTransaction();
        try {
            $stmtInv = $db->prepare("SELECT * FROM proyekoices WHERE id = :id FOR UPDATE");
            $stmtInv->execute(['id' => $id]);
            $proyekoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$proyekoice) {
                throw new Exception('Invoice tidak ditemukan.');
            }

            // Kalau sudah final sebelumnya, tidak perlu proses ulang (hindari jurnal dobel).
            if ($proyekoice['status'] === 'final') {
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Invoice sudah final.', 'already_final' => true]);
                break;
            }

            $stmtUpd = $db->prepare("UPDATE proyekoices SET status = 'final' WHERE id = :id AND status = 'draft'");
            $stmtUpd->execute(['id' => $id]);
            if ($stmtUpd->rowCount() === 0) {
                throw new Exception('Gagal finalisasi -- status invoice sudah berubah, silakan reload.');
            }

            // ---- Auto-posting jurnal ----
            $jr = AutoPostJournal($db, [
                'rule_code'      => 'SLSCREDIT',
                'reference_type' => 'sales_proyekoice',
                'reference_id'   => $id,
                'journal_date'   => $proyekoice['proyekoice_date'],
                'amount'         => $proyekoice['total_amount'],
                'description'    => 'Invoice ' . $proyekoice['proyekoice_no']
            ]);
            if (!$jr['success']) {
                throw new Exception('Jurnal otomatis gagal dibuat: ' . $jr['message']);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Invoice berhasil difinalisasi. ' . $jr['message']]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[invoiceCRD][finalize] ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ==============================================================
    case 'delete':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);

        $stmtChk = $db->prepare("SELECT status FROM proyekoices WHERE id = :id");
        $stmtChk->execute(['id' => $id]);
        $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['status'] !== 'draft') {
            echo json_encode(['success' => false, 'message' => 'Hanya proyekoice berstatus Draft yang boleh dihapus.']);
            exit;
        }

        $db->query("DELETE FROM proyekoice_items WHERE proyekoice_id = {$id}");
        $db->query("DELETE FROM proyekoices WHERE id = {$id}");
        echo json_encode(['success' => true, 'message' => 'Invoice berhasil dihapus.']);
        break;

    // ==============================================================
    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}

function saveInvoice($db, $id, $post) {
    $soId       = intval($post['so_id'] ?? 0);
    $customerId = intval($post['customer_id'] ?? 0);
    $proyekDate    = trim($post['proyekoice_date'] ?? '');
    $dueDate    = trim($post['due_date'] ?? '') ?: null;
    $itemsJson  = $post['arrItems'] ?? '[]';
    $items      = json_decode($itemsJson, true);

    if ($soId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Sales Order wajib dipilih.']);
        return;
    }
    if ($customerId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Customer wajib dipilih.']);
        return;
    }
    if (empty($proyekDate)) {
        echo json_encode(['success' => false, 'message' => 'Tanggal proyekoice wajib diisi.']);
        return;
    }
    if (!is_array($items) || count($items) < 1) {
        echo json_encode(['success' => false, 'message' => 'Invoice minimal harus punya 1 item.']);
        return;
    }

    $validItems = [];
    $totalAmount = 0;
    foreach ($items as $it) {
        $desc = trim($it['description'] ?? '');
        $qty = Str2N($it['qty'] ?? 0);
        $price = Str2N($it['unit_price'] ?? 0);
        $soItemId = !empty($it['so_item_id']) ? intval($it['so_item_id']) : null;
        if (empty($desc) || $qty <= 0) continue;
        $subtotal = $qty * $price;
        $totalAmount += $subtotal;
        $validItems[] = ['description' => $desc, 'qty' => $qty, 'unit_price' => $price, 'subtotal' => $subtotal, 'so_item_id' => $soItemId];
    }
    if (count($validItems) < 1) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada item valid (deskripsi & qty wajib diisi).']);
        return;
    }

    $headerData = [
        'so_id'        => $soId,
        'customer_id'  => $customerId,
        'proyekoice_date' => $proyekDate,
        'due_date'     => $dueDate,
        'total_amount' => $totalAmount
    ];

    if ($id === null) {
        $proyekoiceNo = CreateRefNo('proyekoices', 'proyekoice_no', 'INV');
        $headerData['proyekoice_no']  = $proyekoiceNo;
        $headerData['paid_amount'] = 0;
        $headerData['status']      = 'draft';
        $headerData['created_by']  = $_SESSION['user_id'];

        $db->insert('proyekoices', $headerData);

        $stmtId = $db->prepare("SELECT id FROM proyekoices WHERE proyekoice_no = :no");
        $stmtId->execute(['no' => $proyekoiceNo]);
        $newId = $stmtId->fetch(PDO::FETCH_ASSOC)['id'] ?? null;

        if ($newId) insertItems($db, $newId, $validItems);
        echo json_encode(['success' => true, 'message' => "Invoice {$proyekoiceNo} berhasil disimpan."]);
    } else {
        $db->update('proyekoices', $headerData, "id = {$id}");
        $db->query("DELETE FROM proyekoice_items WHERE proyekoice_id = {$id}");
        insertItems($db, $id, $validItems);
        echo json_encode(['success' => true, 'message' => 'Invoice berhasil diperbarui.']);
    }
}

function insertItems($db, $proyekoiceId, $items) {
    foreach ($items as $it) {
        $db->insert('proyekoice_items', [
            'proyekoice_id'  => $proyekoiceId,
            'so_item_id'  => $it['so_item_id'] ?? null,
            'description' => $it['description'],
            'qty'         => $it['qty'],
            'unit_price'  => $it['unit_price'],
            'subtotal'    => $it['subtotal']
        ]);
    }
}