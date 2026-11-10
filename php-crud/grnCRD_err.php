<?php
/*
  Document      : Purchase GRN (Goods Receipt Note) - Backend Controller
  Called from   : js/m.grn.js (belum dibuat -- controller ini duluan)
  Depends on    : grn_schema.sql (grn_hdr, grn_dtl, view_po_dtl_receipt_status,
                   view_po_hdr_outstanding), fiscal_config rule 'GRN_RCV'

  Alur singkat  :
    1. User pilih PO yang masih outstanding (task=read_po)
    2. Sistem tampilkan baris item PO yang masih bisa diterima (task=read_po_items)
       -- termasuk sisa jatah toleransi over-receipt 10%
    3. User input qty_received per item (boleh < qty PO / partial, atau
       > qty PO maksimal +10%)
    4. Simpan (task=create) -> insert grn_hdr + grn_dtl, lalu AutoPostJournal:
         Debit Persediaan / Kredit GRN Clearing  (rule_code = 'GRN_RCV')
    5. Purchase Invoice (modul terpisah, menyusul) yang nanti akan:
         Debit GRN Clearing / Kredit Hutang Usaha

  Catatan toleransi 10%: divalidasi dengan membaca ulang
  view_po_dtl_receipt_status.qty_receivable_max tepat sebelum insert, di
  dalam transaksi yang sama. Ini BUKAN row-lock penuh (belum pakai
  SELECT ... FOR UPDATE), jadi kalau ada 2 user submit GRN untuk po_dtl
  yang sama persis di detik yang sama, race condition kecil masih mungkin.
  Cukup aman untuk pemakaian normal; kalau volume transaksi GRN Anda
  tinggi & sering barengan, kasih tahu saya untuk ditambah locking.
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
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca GRN tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ------------------------------------------------------------
    // 1. READ (Grid Utama)
    // ------------------------------------------------------------
    case 'read':
        $statusFilter = trim($_POST['status_filter'] ?? '');
        $sql = "
            SELECT
                g.id, g.ref_no, g.grn_date, g.po_hdr_id, ph.ref_no AS po_ref_no,
                g.supplier_id, s.name AS supplier_name, g.status, g.remark,
                COALESCE((SELECT SUM(gd.qty_received * gd.unit_cost) FROM grn_dtl gd WHERE gd.grn_hdr_id = g.id), 0) AS total_amount,
                COALESCE((SELECT SUM(gd.qty_received) FROM grn_dtl gd WHERE gd.grn_hdr_id = g.id), 0) AS total_qty
            FROM grn_hdr g
            LEFT JOIN po_hdr ph ON ph.id = g.po_hdr_id
            LEFT JOIN m_suppliers s ON s.id = g.supplier_id
        ";
        $params = [];
        if ($statusFilter !== '') {
            $sql .= " WHERE g.status = :status_filter ";
            $params['status_filter'] = $statusFilter;
        }
        $sql .= " ORDER BY g.grn_date DESC, g.id DESC LIMIT 250 ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;

    // ------------------------------------------------------------
    // 2. READ SINGLE (Header + Items, untuk Form Edit)
    // ------------------------------------------------------------
    case 'read_edit':
        $id = intval($_POST['id'] ?? 0);

        $stmtH = $db->prepare("
            SELECT g.*, (g.grn_date)::text AS grn_date
            FROM grn_hdr g
            WHERE g.id = :id
        ");
        $stmtH->execute(['id' => $id]);
        $header = $stmtH->fetch(PDO::FETCH_ASSOC);

        if (!$header) {
            echo json_encode(['success' => false, 'data' => new stdClass()]);
            exit;
        }

        $stmtD = $db->prepare("
            SELECT gd.id, gd.po_dtl_id, gd.item_id, gd.item_name,
                   gd.qty_received, gd.unit_cost, gd.remark,
                   pd.qty AS po_qty_ordered, pd.unit_price AS po_unit_price
            FROM grn_dtl gd
            LEFT JOIN po_dtl pd ON pd.id = gd.po_dtl_id
            WHERE gd.grn_hdr_id = :id
            ORDER BY gd.id ASC
        ");
        $stmtD->execute(['id' => $id]);
        $header['items'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $header]);
        break;

    // ------------------------------------------------------------
    // 3. READ COMBO: PO yang masih outstanding (buat form GRN baru)
    // ------------------------------------------------------------
    case 'read_po':
        $sql = "
            SELECT o.id, o.ref_no, o.tx_date, o.supplier_id, s.name AS supplier_name
            FROM view_po_hdr_outstanding o
            LEFT JOIN m_suppliers s ON s.id = o.supplier_id
            ORDER BY o.tx_date ASC, o.ref_no ASC
        ";
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'read_warehouse':
        $sql = "SELECT id, name FROM m_warehouse WHERE active = true ORDER BY name ASC";
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ------------------------------------------------------------
    // 4. READ ITEM PO yang masih bisa diterima (termasuk jatah toleransi 10%)
    //    grn_id (opsional, dikirim saat EDIT) -- baris yang SUDAH di-input
    //    oleh GRN ini sendiri ditambahkan balik ke qty_receivable_max &
    //    diekspos lewat qty_received_this_grn, supaya baris itu tetap
    //    muncul di grid gabungan dengan nilai aslinya -- persis pola
    //    invoice_id di read_grn_outstanding milik Purchase Invoice.
    // ------------------------------------------------------------
    case 'read_po_items':
        $poHdrId = intval($_POST['po_hdr_id'] ?? 0);
        $grnId   = intval($_POST['grn_id'] ?? 0);
        if ($poHdrId <= 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $sql = "
            SELECT
                s.po_dtl_id, s.item_id, s.item_name,
                s.qty_ordered, s.unit_price,
                s.qty_received, s.qty_outstanding,
                s.qty_max_allowed,
                (s.qty_receivable_max + COALESCE(gd.qty_received, 0)) AS qty_receivable_max,
                COALESCE(gd.qty_received, 0) AS qty_received_this_grn,
                COALESCE(gd.unit_cost, s.unit_price) AS unit_cost_this_grn
            FROM view_po_dtl_receipt_status s
            LEFT JOIN grn_dtl gd ON gd.po_dtl_id = s.po_dtl_id AND gd.grn_hdr_id = :grn_id
            WHERE s.po_hdr_id = :po_hdr_id
              AND (s.qty_receivable_max > 0 OR gd.id IS NOT NULL)
            ORDER BY s.po_dtl_id ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['po_hdr_id' => $poHdrId, 'grn_id' => $grnId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ------------------------------------------------------------
    // 5. CREATE
    // ------------------------------------------------------------
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $poHdrId     = intval($_POST['po_hdr_id'] ?? 0);
        $supplierId  = intval($_POST['supplier_id'] ?? 0);
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $grnDate     = trim($_POST['grn_date'] ?? '');
        $remark      = trim($_POST['remark'] ?? '');
        $items       = json_decode($_POST['arrItems'] ?? '[]', true);

        if ($poHdrId <= 0 || $supplierId <= 0 || $warehouseId <= 0 || empty($grnDate) || !is_array($items) || count($items) === 0) {
            echo json_encode(['success' => false, 'message' => 'Lengkapi data GRN (termasuk Gudang Tujuan) dan minimal 1 item.']);
            exit;
        }

        try {
            $db->beginTransaction();

            $grnNo = CreateRefNo('grn_hdr', 'ref_no', 'GRN');

            DbOrFail($db, $db->insert('grn_hdr', [
                'ref_no'       => $grnNo,
                'grn_date'     => $grnDate,
                'po_hdr_id'    => $poHdrId,
                'supplier_id'  => $supplierId,
                'warehouse_id' => $warehouseId,
                'status'       => 'draft',  // menunggu konfirmasi fisik dari gudang -- BELUM ada jurnal/stok
                'remark'       => $remark,
                'created_by'   => $_SESSION['user_id'] ?? null
            ]), 'insert grn_hdr');

            $grnId = $db->lastInsertId();
            if (!$grnId) {
                $stmtG = $db->prepare("SELECT id FROM grn_hdr WHERE ref_no = :no");
                $stmtG->execute(['no' => $grnNo]);
                $grnId = $stmtG->fetch(PDO::FETCH_ASSOC)['id'] ?? 0;
            }

            // Jurnal & stock movement SENGAJA TIDAK diposting di sini --
            // GRN dari purchasing cuma "expected receipt" (draft). Jurnal
            // & stok baru kebentuk saat gudang konfirmasi fisik lewat
            // task 'confirm_receipt'.
            InsertGrnItems($db, $grnId, $items);

            $db->commit();
            echo json_encode(['success' => true, 'message' => "GRN {$grnNo} (draft) berhasil disimpan. Menunggu konfirmasi fisik dari gudang."]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Gagal simpan data: ' . $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 5b. CONFIRM RECEIPT (konfirmasi fisik dari gudang)
    //     Ini yang BENERAN posting jurnal (GRN_RCV) & stock movement (IN).
    //     Hanya bisa dipanggil sekali -- GRN harus masih status 'draft'.
    // ------------------------------------------------------------
    case 'confirm_receipt':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $grnId = intval($_POST['id'] ?? 0);
        if ($grnId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID GRN tidak valid.']);
            exit;
        }

        try {
            $db->beginTransaction();

            $stmtG = $db->prepare("SELECT ref_no, grn_date, warehouse_id, status FROM grn_hdr WHERE id = :id FOR UPDATE");
            $stmtG->execute(['id' => $grnId]);
            $grn = $stmtG->fetch(PDO::FETCH_ASSOC);

            if (!$grn) {
                throw new Exception("GRN tidak ditemukan.");
            }
            if ($grn['status'] !== 'draft') {
                throw new Exception("GRN ini sudah berstatus '{$grn['status']}' -- tidak bisa dikonfirmasi lagi (mungkin sudah pernah dikonfirmasi atau dibatalkan).");
            }

            $stmtItems = $db->prepare("SELECT item_id, qty_received, unit_cost FROM grn_dtl WHERE grn_hdr_id = :id");
            $stmtItems->execute(['id' => $grnId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                throw new Exception("GRN ini tidak punya item, tidak ada yang bisa dikonfirmasi.");
            }

            $totalAmount = 0.0;
            foreach ($items as $it) {
                $totalAmount += round(floatval($it['qty_received']) * floatval($it['unit_cost']), 2);
            }

            // --- AUTO POST JOURNAL: Debit Persediaan / Kredit GRN Clearing ---
            $result = AutoPostJournal($db, [
                'rule_code'      => 'GRN_RCV',
                'reference_type' => 'purchase_grn',
                'reference_id'   => $grnId,
                'journal_date'   => $grn['grn_date'],
                'amount'         => round($totalAmount, 2),
                'description'    => 'Penerimaan Barang PO via ' . $grn['ref_no'] . ' (konfirmasi gudang)',
            ]);
            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            // --- AUTO POST STOCK MOVEMENT (IN) ke WMS ---
            PostGrnStockMovements($db, $grnId, $grn['ref_no'], $grn['grn_date'], intval($grn['warehouse_id']), $items);

            DbOrFail($db, $db->update('grn_hdr', [
                'status'        => 'confirmed',
                'confirmed_by'  => $_SESSION['user_id'] ?? null,
                'confirmed_at'  => date('Y-m-d H:i:s')
            ], "id = " . intval($grnId)), 'update grn_hdr confirm');

            $db->commit();
            echo json_encode(['success' => true, 'message' => "GRN {$grn['ref_no']} dikonfirmasi. Jurnal & stok masuk berhasil diposting."]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Gagal konfirmasi: ' . $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 6. UPDATE
    // ------------------------------------------------------------
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $id          = intval($_POST['id'] ?? 0);
        $poHdrId     = intval($_POST['po_hdr_id'] ?? 0);
        $supplierId  = intval($_POST['supplier_id'] ?? 0);
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $grnDate     = trim($_POST['grn_date'] ?? '');
        $remark      = trim($_POST['remark'] ?? '');
        $items       = json_decode($_POST['arrItems'] ?? '[]', true);

        if ($id <= 0 || $poHdrId <= 0 || $supplierId <= 0 || $warehouseId <= 0 || empty($grnDate) || !is_array($items) || count($items) === 0) {
            echo json_encode(['success' => false, 'message' => 'Data edit GRN tidak valid.']);
            exit;
        }

        try {
            $db->beginTransaction();

            $stmtOld = $db->prepare("SELECT ref_no, status FROM grn_hdr WHERE id = :id");
            $stmtOld->execute(['id' => $id]);
            $grnOld = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$grnOld) {
                throw new Exception("Data GRN tidak ditemukan.");
            }
            $grnNo = $grnOld['ref_no'];
            $wasConfirmed = ($grnOld['status'] === 'confirmed');

            // 1. Hapus jurnal & stock movement lama (no-op aman kalau
            //    memang belum pernah diposting / masih draft)
            ReverseJournalByReference($db, 'purchase_grn', $id);
            // Catatan: kalau barang dari GRN ini SUDAH terlanjur keluar
            // lewat Sales Delivery, menghapus/mengurangi qty di sini bisa
            // bikin saldo stok jadi negatif di laporan -- sistem tidak
            // otomatis menolaknya (sama seperti reversal jurnal), jadi
            // pastikan cek dulu di kartu stok sebelum edit GRN yang barangnya
            // sudah bergerak.
            ReverseStockMovementByReference($db, 'grn', $id);

            // 2. Hapus item lama DULU -- supaya view_po_dtl_receipt_status
            //    otomatis tidak menghitung qty GRN ini lagi saat validasi
            //    toleransi 10% dijalankan ulang di InsertGrnItems()
            $stmtDel = $db->prepare("DELETE FROM grn_dtl WHERE grn_hdr_id = :id");
            $stmtDel->execute(['id' => $id]);

            // 3. Update header
            DbOrFail($db, $db->update('grn_hdr', [
                'grn_date'     => $grnDate,
                'po_hdr_id'    => $poHdrId,
                'supplier_id'  => $supplierId,
                'warehouse_id' => $warehouseId,
                'remark'       => $remark
            ], "id = " . intval($id)), 'update grn_hdr');

            // 4. Insert item baru (tervalidasi ulang thd toleransi 10%)
            $totalAmount = InsertGrnItems($db, $id, $items);

            // 5. Re-post jurnal & stok HANYA kalau GRN ini SEBELUMNYA sudah
            //    dikonfirmasi gudang -- kalau masih draft, edit tetap
            //    membuatnya draft (menunggu konfirmasi ulang), TIDAK
            //    otomatis posting jurnal/stok.
            if ($wasConfirmed) {
                $result = AutoPostJournal($db, [
                    'rule_code'      => 'GRN_RCV',
                    'reference_type' => 'purchase_grn',
                    'reference_id'   => $id,
                    'journal_date'   => $grnDate,
                    'amount'         => $totalAmount,
                    'description'    => 'Penerimaan Barang PO via ' . $grnNo . ' (revisi setelah konfirmasi)',
                ]);
                if (!$result['success']) {
                    throw new Exception($result['message']);
                }

                // 6. Re-post stock movement (IN) baru
                PostGrnStockMovements($db, $id, $grnNo, $grnDate, $warehouseId, $items);

                // Status TETAP 'confirmed' (sudah di-set sebelumnya, tidak
                // perlu diubah lagi)
                $statusMsg = "GRN {$grnNo} berhasil diperbarui (status tetap confirmed, jurnal & stok sudah di-repost).";
            } else {
                // Masih draft -- pastikan statusnya tetap draft (jaga-jaga
                // kalau sebelumnya sempat ke-set status lain)
                DbOrFail($db, $db->update('grn_hdr', ['status' => 'draft'], "id = " . intval($id)), 'update grn_hdr status');
                $statusMsg = "GRN {$grnNo} (draft) berhasil diperbarui. Masih menunggu konfirmasi fisik dari gudang.";
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => $statusMsg]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Gagal update data: ' . $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------
    // 7. DELETE
    // ------------------------------------------------------------
    case 'delete':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID data tidak valid.']);
            exit;
        }

        try {
            $db->beginTransaction();

            $stmtOld = $db->prepare("SELECT ref_no FROM grn_hdr WHERE id = :id");
            $stmtOld->execute(['id' => $id]);
            $grnNo = $stmtOld->fetchColumn();
            if (!$grnNo) {
                throw new Exception("Data GRN tidak ditemukan.");
            }

            // TODO: kalau modul Purchase Invoice sudah ada, tolak delete
            // di sini kalau GRN ini sudah pernah dipakai/di-matching ke
            // invoice supplier (supaya GRN Clearing tidak jadi tidak balance).

            ReverseJournalByReference($db, 'purchase_grn', $id);
            ReverseStockMovementByReference($db, 'grn', $id);

            $stmtDelD = $db->prepare("DELETE FROM grn_dtl WHERE grn_hdr_id = :id");
            $stmtDelD->execute(['id' => $id]);

            $stmtDelH = $db->prepare("DELETE FROM grn_hdr WHERE id = :id");
            $stmtDelH->execute(['id' => $id]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => "GRN {$grnNo} berhasil dihapus & jurnal/stok dibatalkan."]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
}

// ==================================================================
// HELPER LOKAL: InsertGrnItems
// Validasi tiap item terhadap sisa jatah toleransi 10% (baca ulang
// view_po_dtl_receipt_status di dalam transaksi yang sama), lalu insert
// ke grn_dtl. Return total nilai (qty * unit_cost) untuk posting jurnal.
// ==================================================================
// ==================================================================
// HELPER: PostGrnStockMovements
// Catat 1 baris stock_movements (IN) per item GRN ke WMS, lewat
// PostStockMovement() generic di function.all.php.
// ==================================================================
function PostGrnStockMovements($db, $grnId, $grnNo, $grnDate, $warehouseId, array $items) {
    foreach ($items as $it) {
        $itemId = intval($it['item_id'] ?? 0);
        $qty    = round(floatval($it['qty_received'] ?? 0), 4);
        $cost   = round(floatval($it['unit_cost'] ?? 0), 4);

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        PostStockMovement($db, [
            'movement_date'  => $grnDate,
            'item_id'        => $itemId,
            'warehouse_id'   => $warehouseId,
            'movement_type'  => 'IN',
            'qty'            => $qty,
            'unit_cost'      => $cost,
            'reference_type' => 'grn',
            'reference_id'   => $grnId,
            'reference_no'   => $grnNo,
        ]);
    }
}

function InsertGrnItems($db, $grnId, array $items) {
    $totalAmount = 0.0;

    $stmtCheck = $db->prepare("
        SELECT qty_ordered, qty_receivable_max, item_name
        FROM view_po_dtl_receipt_status
        WHERE po_dtl_id = :po_dtl_id
    ");

    foreach ($items as $it) {
        $poDtlId  = intval($it['po_dtl_id'] ?? 0);
        $itemId   = intval($it['item_id'] ?? 0);
        $itemName = trim($it['item_name'] ?? '');
        $qty      = round(floatval($it['qty_received'] ?? 0), 4);
        $cost     = round(floatval($it['unit_cost'] ?? 0), 4);
        $remark   = trim($it['remark'] ?? '');

        if ($poDtlId <= 0 || $itemId <= 0 || $qty <= 0) {
            continue; // baris kosong/tidak valid, skip
        }

        $stmtCheck->execute(['po_dtl_id' => $poDtlId]);
        $status = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$status) {
            throw new Exception("Baris PO (po_dtl_id={$poDtlId}) tidak ditemukan.");
        }

        if ($qty > floatval($status['qty_receivable_max']) + 0.0001) {
            throw new Exception(
                "Qty diterima ({$qty}) untuk item id {$itemId} melebihi batas toleransi 10% dari PO. " .
                "Qty PO: {$status['qty_ordered']}, sisa yang masih boleh diterima: {$status['qty_receivable_max']}."
            );
        }

        DbOrFail($db, $db->insert('grn_dtl', [
            'grn_hdr_id'   => $grnId,
            'po_dtl_id'    => $poDtlId,
            'item_id'      => $itemId,
            'item_name'    => $itemName !== '' ? $itemName : ($status['item_name'] ?? null),
            'qty_received' => $qty,
            'unit_cost'    => $cost,
            'remark'       => $remark
        ]), 'insert grn_dtl');

        $totalAmount += round($qty * $cost, 2);
    }

    if ($totalAmount <= 0) {
        throw new Exception('Tidak ada item valid untuk diposting (total nilai GRN = 0).');
    }

    return round($totalAmount, 2);
}