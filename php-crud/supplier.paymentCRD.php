<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/*
  Document      : Supplier Payment - Backend Controller (Full CRUD + AutoPostJournal Engine)
  Called from   : js/purchase_payment.js
  Note          : Pola mengikuti paymentCRD.php (AR) persis, dibalik sisi
                  debit-kreditnya untuk AP:
                    Payment Entry (AR): Debit Kas/Bank (override) / Kredit Piutang
                    Supplier Payment (AP): Debit Hutang Usaha / Kredit Kas/Bank (override)
                  reference_type = 'supplier_payment' (dipakai utk rule AP
                  MAUPUN Advance -- tidak ada enum terpisah spt customer_deposit)
*/
/*
  PERUBAHAN (perbaikan dari versi yang sudah berjalan)
  - create/update/delete kini SATU transaksi (sebelumnya create autocommit: bila jurnal gagal,
    header + item + paid_amount tetap tersimpan dan user melihat error -> retry = pembayaran dobel).
  - validasi server: invoice ada, milik supplier yang dipilih, nominal <= sisa tagihan, tidak dobel.
  - paid_amount dihitung ulang dari supplier_payment_items (bukan +/- manual dengan GREATEST).
  - saldo deposit tidak boleh negatif (hapus/ubah pembayaran yang uang mukanya sudah terpakai ditolak).
  - nomor SPAY & JV satu-transaksi (butuh CreateRefNoDb di function.all.php baru; ada fallback).
  - task baru read_payment_method; task read_* membaca $_REQUEST (POST maupun GET).
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
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca pembayaran supplier tidak tersedia.']); exit; }
if (in_array($task, ['create', 'update', 'delete'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode harus POST untuk transaksi pembayaran supplier.']);
    exit;
}
$db   = new crud();

switch ($task) {

    // ------------------------------------------------------------
    // 1. READ (Grid Utama)
    // ------------------------------------------------------------
    case 'read':
        $sql = "
            SELECT p.id, p.payment_no, p.payment_date, p.supplier_id,
                   s.name AS supplier_name,
                   p.account_id, (coa.account_code || ' - ' || coa.account_name) AS account_name,
                   p.payment_method, p.total_amount, COALESCE(p.allocated_amount, 0) AS allocated_amount,
                   COALESCE(p.excess_amount, 0) AS excess_amount,
                   COALESCE(p.deposit_applied, 0) AS deposit_applied,
                   p.reference_no, p.notes
            FROM supplier_payments p
            LEFT JOIN m_suppliers s ON s.id = p.supplier_id
            LEFT JOIN chart_of_accounts coa ON coa.id = p.account_id
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 250
        ";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;
// ------------------------------------------------------------
    // 1B. READ PAYMENT DETAIL (Alokasi Invoice yang Dibayar)
    // ------------------------------------------------------------
    case 'read_payment_detail':
        $paymentId = intval($_REQUEST['payment_id'] ?? $_REQUEST['payment_id'] ?? 0);

        if ($paymentId <= 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $sql = "
            SELECT 
                pi.inv_no AS invoice_no,
                pi.supplier_invoice_no,
                TO_CHAR(pi.invoice_date, 'DD-MM-YYYY') AS invoice_date,
                COALESCE(pi.total_amount, 0) AS total_amount,
                COALESCE(pi.total_amount - pi.paid_amount, 0) AS unpaid_amount,
                COALESCE(spi.amount_paid, 0) AS amount_paid
            FROM supplier_payment_items spi
            INNER JOIN purchase_invoice_hdr pi ON spi.invoice_id = pi.id
            WHERE spi.supplier_payment_id = :payment_id
            ORDER BY pi.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['payment_id' => $paymentId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $data]);
        break;



    // ------------------------------------------------------------
    // 2. READ SINGLE (Form Edit)
    // ------------------------------------------------------------
    case 'read_edit':
        $id = intval($_REQUEST['id'] ?? 0);
        $stmt = $db->prepare("
            SELECT p.*, (p.payment_date)::text AS payment_date
            FROM supplier_payments p
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => (bool)$row, 'data' => $row ?: new stdClass()]);
        break;

    // ------------------------------------------------------------
    // 3. READ COMBO & UNPAID PURCHASE INVOICES
    // ------------------------------------------------------------
    case 'read_supplier':
        $sql = "SELECT id, name FROM m_suppliers WHERE active = true ORDER BY name ASC";
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'read_account':
        $sql = "
            SELECT id, (account_code || ' - ' || account_name) AS account_name
            FROM chart_of_accounts
            WHERE is_active = true
              AND parent_id IS NOT NULL
              AND (
                  account_type IN ('cash', 'bank')
                  OR parent_id = (SELECT id FROM chart_of_accounts WHERE account_code = '1100' LIMIT 1)
              )
            ORDER BY account_code ASC
        ";
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ------------------------------------------------------------
    // 3B. READ METODE PEMBAYARAN
    //     Bila kolom payment_method bertipe ENUM, nilainya diambil dari DB
    //     (supaya combo di form tidak pernah mengirim nilai yang ditolak enum).
    //     Bila bukan enum, dipakai daftar default.
    // ------------------------------------------------------------
    case 'read_payment_method':
        $rows = [];
        try {
            $rows = $db->query("
                SELECT e.enumlabel AS value
                FROM pg_attribute a
                JOIN pg_type t ON t.oid = a.atttypid
                JOIN pg_enum e ON e.enumtypid = t.oid
                WHERE a.attrelid = 'supplier_payments'::regclass
                  AND a.attname = 'payment_method'
                ORDER BY e.enumsortorder
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = [];
        }
        if (empty($rows)) {
            $rows = [['value' => 'BANK_TRANSFER'], ['value' => 'CASH'], ['value' => 'CHEQUE']];
        }
        foreach ($rows as &$mr) {
            $mr['label'] = ucwords(strtolower(str_replace('_', ' ', $mr['value'])));
        }
        unset($mr);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'read_unpaid_invoices':
        $supplierId = intval($_REQUEST['supplier_id'] ?? 0);
        $paymentId  = intval($_REQUEST['payment_id'] ?? 0);

        if ($supplierId <= 0) {
            echo json_encode(['success' => true, 'totalCount' => 0, 'data' => []]);
            exit;
        }

        // payment_id = 0 → mode tambah: amount_paid default ke unpaid_amount (sisa hutang)
        // payment_id > 0 → mode edit: amount_paid dari supplier_payment_items (alokasi tersimpan)
        $sql = "
            SELECT
                i.id AS invoice_id,
                i.inv_no AS invoice_no,
                i.supplier_invoice_no,
                i.invoice_date,
                i.total_amount,
                i.paid_amount,
                ((COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) + COALESCE(pi.amount_paid, 0)) AS unpaid_amount,
                CASE
                    WHEN :payment_id2 = 0
                        THEN ((COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) + COALESCE(pi.amount_paid, 0))
                    ELSE COALESCE(pi.amount_paid, 0)
                END AS amount_paid
            FROM purchase_invoice_hdr i
            LEFT JOIN supplier_payment_items pi ON pi.invoice_id = i.id AND pi.supplier_payment_id = :payment_id
            WHERE i.supplier_id = :supplier_id
              AND i.status = 'posted'
              AND (((COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) + COALESCE(pi.amount_paid, 0)) > 0.01 OR pi.id IS NOT NULL)
            ORDER BY i.invoice_date ASC, i.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['supplier_id' => $supplierId, 'payment_id' => $paymentId, 'payment_id2' => $paymentId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;

    // ------------------------------------------------------------
    // Saldo Deposit di Supplier -- dihitung dari journal_lines (net
    // debit-kredit akun 1400 Uang Muka Pembelian) terkait supplier_payments
    // milik supplier ini. Tidak ada tabel ledger baru.
    // ------------------------------------------------------------
    case 'read_supplier_deposit_balance':
        $supplierId = intval($_REQUEST['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            echo json_encode(['success' => true, 'balance' => 0]);
            exit;
        }
        $balance = GetSupplierDepositBalance($db, $supplierId);
        echo json_encode(['success' => true, 'balance' => $balance]);
        break;

    // ------------------------------------------------------------
    // 4. CREATE  (satu transaksi: header + item + paid_amount + jurnal)
    // ------------------------------------------------------------
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $rawTotal   = str_replace(',', '', $_POST['total_amount'] ?? '0');
        $totalPaid  = round(floatval($rawTotal), 2);
        $depositApplied = round(floatval($_POST['deposit_applied'] ?? 0), 2);
        $supplierId = intval($_POST['supplier_id'] ?? 0);
        $accountId  = intval($_POST['account_id'] ?? 0);
        $payDate    = trim($_POST['payment_date'] ?? '');
        $method     = trim($_POST['payment_method'] ?? 'BANK_TRANSFER');
        $refNo      = trim($_POST['reference_no'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $items      = json_decode($_POST['arrItems'] ?? '[]', true);

        if ($supplierId <= 0 || empty($payDate) || ($totalPaid <= 0 && $depositApplied <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Lengkapi data pembayaran. Total dibayar atau saldo deposit yang dipakai harus > 0.']);
            exit;
        }
        if ($totalPaid > 0 && $accountId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Pilih akun Kas/Bank sumber dana.']);
            exit;
        }
        $dtChk = DateTime::createFromFormat('Y-m-d', $payDate);
        if (!$dtChk || $dtChk->format('Y-m-d') !== $payDate) {
            echo json_encode(['success' => false, 'message' => 'Format Tanggal Bayar tidak valid (harus YYYY-MM-DD).']);
            exit;
        }

        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (Exception $ex) {}
        }

        try {
            $db->beginTransaction();

            // Serialisasi per supplier: dua pembayaran bersamaan tidak bisa
            // sama-sama memakai saldo deposit / melunasi invoice yang sama.
            SpLockSupplier($db, $supplierId);
            $before = [$supplierId => GetSupplierDepositBalance($db, $supplierId)];

            $alloc = SpNormalizeItems($items);
            $allocatedTotal = round(array_sum($alloc), 2);

            if ($depositApplied > 0) {
                $availableDeposit = $before[$supplierId];
                if ($depositApplied > $availableDeposit + 0.01) {
                    throw new Exception("Saldo deposit yang dipakai (Rp " . number_format($depositApplied, 2) . ") melebihi saldo tersedia (Rp " . number_format($availableDeposit, 2) . ").");
                }
                if ($depositApplied > $allocatedTotal + 0.01) {
                    throw new Exception('Saldo deposit yang dipakai tidak boleh melebihi total alokasi ke invoice.');
                }
            }

            $cashNeeded = round($allocatedTotal - $depositApplied, 2);
            if ($totalPaid + 0.01 < $cashNeeded) {
                throw new Exception("Total dibayar + saldo deposit belum cukup menutupi alokasi invoice.");
            }
            $excessAmount = round($totalPaid - $cashNeeded, 2);

            // invoice ada, milik supplier ini, nominal <= sisa tagihan (baris dikunci)
            SpValidateAllocations($db, $supplierId, $alloc);

            $paymentNo = SpNextRefNo($db, 'supplier_payments', 'payment_no', 'SPAY');

            $createdBy = null;
            $rawUserId = intval($_SESSION['user_id'] ?? 0);
            if ($rawUserId > 0) {
                $stmtUser = $db->prepare("SELECT id FROM m_user_system WHERE id = :uid LIMIT 1");
                $stmtUser->execute(['uid' => $rawUserId]);
                if ($stmtUser->fetchColumn()) {
                    $createdBy = $rawUserId;
                }
            }

            $okHeader = $db->insert('supplier_payments', [
                'payment_no'       => $paymentNo,
                'payment_date'     => $payDate,
                'supplier_id'      => $supplierId,
                'account_id'       => $totalPaid > 0 ? $accountId : null,
                'payment_method'   => $method,
                'total_amount'     => $totalPaid,
                'allocated_amount' => $allocatedTotal,
                'excess_amount'    => $excessAmount,
                'deposit_applied'  => $depositApplied,
                'reference_no'     => $refNo,
                'notes'            => $notes,
                'created_by'       => $createdBy
            ]);
            if ($okHeader === false) {
                throw new Exception('ERROR ASLI DB HEADER: ' . $db->getResult());
            }

            $stmtP = $db->prepare("SELECT id FROM supplier_payments WHERE payment_no = :no ORDER BY id DESC LIMIT 1");
            $stmtP->execute(['no' => $paymentNo]);
            $paymentId = intval($stmtP->fetchColumn());
            if ($paymentId <= 0) {
                throw new Exception('ERROR ASLI DB HEADER: id pembayaran tidak terbaca setelah insert.');
            }

            foreach ($alloc as $invId => $amt) {
                $okItem = $db->insert('supplier_payment_items', [
                    'supplier_payment_id' => $paymentId,
                    'invoice_id'          => $invId,
                    'amount_paid'         => $amt
                ]);
                if ($okItem === false) {
                    throw new Exception("ERROR ASLI DB ITEM (Invoice ID {$invId}): " . $db->getResult());
                }
            }

            // paid_amount dihitung dari item (sumber kebenaran), bukan ditambah manual
            SpRecalcInvoicePaid($db, array_keys($alloc));

            try {
                PostSupplierPaymentJournal($db, $paymentId, $paymentNo, $payDate, $accountId, $allocatedTotal, $excessAmount, $depositApplied);
            } catch (Exception $e) {
                throw new Exception('ERROR ASLI POST JURNAL: ' . $e->getMessage());
            }

            SpAssertDepositNotNegative($db, $before);

            $db->commit();
            echo json_encode(['success' => true, 'id' => $paymentId, 'payment_no' => $paymentNo, 'message' => "Pembayaran {$paymentNo} berhasil disimpan."]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                try { $db->rollBack(); } catch (Exception $ex) {}
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        break;

    // ------------------------------------------------------------
    // 5. UPDATE
    // ------------------------------------------------------------
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }

        $id          = intval($_POST['id'] ?? 0);
        $supplierId  = intval($_POST['supplier_id'] ?? 0);
        $accountId   = intval($_POST['account_id'] ?? 0);
        $payDate     = trim($_POST['payment_date'] ?? '');
        $method      = trim($_POST['payment_method'] ?? 'BANK_TRANSFER');
        $refNo       = trim($_POST['reference_no'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $items       = json_decode($_POST['arrItems'] ?? '[]', true);

        $rawTotal  = str_replace(',', '', $_POST['total_amount'] ?? '0');
        $totalPaid = round(floatval($rawTotal), 2);
        $depositApplied = round(floatval($_POST['deposit_applied'] ?? 0), 2);

        if ($id <= 0 || $supplierId <= 0 || empty($payDate) || ($totalPaid <= 0 && $depositApplied <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Data edit tidak valid.']);
            exit;
        }
        if ($totalPaid > 0 && $accountId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Pilih akun Kas/Bank sumber dana.']);
            exit;
        }
        $dtChk = DateTime::createFromFormat('Y-m-d', $payDate);
        if (!$dtChk || $dtChk->format('Y-m-d') !== $payDate) {
            echo json_encode(['success' => false, 'message' => 'Format Tanggal Bayar tidak valid (harus YYYY-MM-DD).']);
            exit;
        }

        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (Exception $ex) {}
        }

        try {
            $db->beginTransaction();

            $stmtOldPay = $db->prepare("SELECT payment_no, supplier_id FROM supplier_payments WHERE id = :id FOR UPDATE");
            $stmtOldPay->execute(['id' => $id]);
            $oldPay = $stmtOldPay->fetch(PDO::FETCH_ASSOC);
            if (!$oldPay) {
                throw new Exception("Data pembayaran tidak ditemukan.");
            }
            $oldPayNo       = $oldPay['payment_no'];
            $oldSupplierId  = intval($oldPay['supplier_id']);

            // kunci supplier lama & baru (urut id supaya tidak deadlock) + catat saldo deposit awal
            $sids = array_values(array_unique([$oldSupplierId, $supplierId]));
            sort($sids);
            $before = [];
            foreach ($sids as $sid) {
                SpLockSupplier($db, $sid);
                $before[$sid] = GetSupplierDepositBalance($db, $sid);
            }

            $stmtOldItems = $db->prepare("SELECT invoice_id FROM supplier_payment_items WHERE supplier_payment_id = :id");
            $stmtOldItems->execute(['id' => $id]);
            $oldInvoiceIds = array_map('intval', $stmtOldItems->fetchAll(PDO::FETCH_COLUMN));

            $alloc = SpNormalizeItems($items);
            SpLockInvoices($db, array_merge($oldInvoiceIds, array_keys($alloc)));

            ReverseSupplierPaymentJournal($db, $id);

            $stmtDelItems = $db->prepare("DELETE FROM supplier_payment_items WHERE supplier_payment_id = :id");
            $stmtDelItems->execute(['id' => $id]);

            $allocatedTotal = round(array_sum($alloc), 2);

            if ($depositApplied > 0) {
                // dihitung SETELAH jurnal lama dibalik -> pemakaian deposit oleh pembayaran ini sendiri sudah kembali
                $availableDeposit = GetSupplierDepositBalance($db, $supplierId);
                if ($depositApplied > $availableDeposit + 0.01) {
                    throw new Exception("Saldo deposit yang dipakai melebihi saldo tersedia (Rp " . number_format($availableDeposit, 2) . ").");
                }
                if ($depositApplied > $allocatedTotal + 0.01) {
                    throw new Exception('Saldo deposit yang dipakai tidak boleh melebihi total alokasi ke invoice.');
                }
            }

            $cashNeeded = round($allocatedTotal - $depositApplied, 2);
            if ($totalPaid + 0.01 < $cashNeeded) {
                throw new Exception('Total dibayar + saldo deposit belum cukup menutupi alokasi invoice.');
            }
            $excessAmount = round($totalPaid - $cashNeeded, 2);

            // item lama sudah dihapus -> "pembayaran lain" saja yang dihitung sebagai terbayar
            SpValidateAllocations($db, $supplierId, $alloc);

            $okUpd = $db->update('supplier_payments', [
                'payment_date'     => $payDate,
                'supplier_id'      => $supplierId,
                'account_id'       => $totalPaid > 0 ? $accountId : null,
                'payment_method'   => $method,
                'total_amount'     => $totalPaid,
                'allocated_amount' => $allocatedTotal,
                'excess_amount'    => $excessAmount,
                'deposit_applied'  => $depositApplied,
                'reference_no'     => $refNo,
                'notes'            => $notes
            ], "id = " . intval($id));

            if ($okUpd === false) {
                throw new Exception("GAGAL UPDATE HEADER: " . $db->getResult());
            }

            foreach ($alloc as $invId => $amt) {
                $okInsItem = $db->insert('supplier_payment_items', ['supplier_payment_id' => $id, 'invoice_id' => $invId, 'amount_paid' => $amt]);
                if ($okInsItem === false) {
                    throw new Exception("GAGAL INSERT ITEM UPDATE (Invoice ID {$invId}): " . $db->getResult());
                }
            }

            SpRecalcInvoicePaid($db, array_merge($oldInvoiceIds, array_keys($alloc)));

            PostSupplierPaymentJournal($db, $id, $oldPayNo, $payDate, $accountId, $allocatedTotal, $excessAmount, $depositApplied);

            SpAssertDepositNotNegative($db, $before);

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Pembayaran {$oldPayNo} berhasil diperbarui."]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                try { $db->rollBack(); } catch (Exception $ex) {}
            }
            echo json_encode(['success' => false, 'message' => 'ERROR UPDATE: ' . $e->getMessage()]);
            exit;
        }
        break;

    // ------------------------------------------------------------
    // 6. DELETE
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

        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (Exception $ex) {}
        }

        try {
            $db->beginTransaction();

            $stmtOldPay = $db->prepare("SELECT payment_no, supplier_id FROM supplier_payments WHERE id = :id FOR UPDATE");
            $stmtOldPay->execute(['id' => $id]);
            $oldPay = $stmtOldPay->fetch(PDO::FETCH_ASSOC);
            if (!$oldPay) {
                throw new Exception("Data pembayaran tidak ditemukan.");
            }
            $oldPayNo = $oldPay['payment_no'];
            $delSupplierId = intval($oldPay['supplier_id']);

            SpLockSupplier($db, $delSupplierId);
            $before = [$delSupplierId => GetSupplierDepositBalance($db, $delSupplierId)];

            $stmtOldItems = $db->prepare("SELECT invoice_id FROM supplier_payment_items WHERE supplier_payment_id = :id");
            $stmtOldItems->execute(['id' => $id]);
            $oldInvoiceIds = array_map('intval', $stmtOldItems->fetchAll(PDO::FETCH_COLUMN));
            SpLockInvoices($db, $oldInvoiceIds);

            ReverseSupplierPaymentJournal($db, $id);

            $stmtDelItems = $db->prepare("DELETE FROM supplier_payment_items WHERE supplier_payment_id = :id");
            $stmtDelItems->execute(['id' => $id]);

            $stmtDelHeader = $db->prepare("DELETE FROM supplier_payments WHERE id = :id");
            $stmtDelHeader->execute(['id' => $id]);

            SpRecalcInvoicePaid($db, $oldInvoiceIds);

            // uang muka dari pembayaran ini mungkin sudah dipakai pembayaran lain
            SpAssertDepositNotNegative($db, $before);

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Pembayaran {$oldPayNo} berhasil dihapus & jurnal dibatalkan."]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                try { $db->rollBack(); } catch (Exception $ex) {}
            }
            echo json_encode(['success' => false, 'message' => 'ERROR DELETE: ' . $e->getMessage()]);
            exit;
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
}

// ==================================================================
// HELPER LOKAL
// ==================================================================
function PostSupplierPaymentJournal($db, $paymentId, $paymentNo, $payDate, $cashAccountId, $allocatedTotal, $excessAmount, $depositApplied = 0) {
    $cashPortionAp = round($allocatedTotal - $depositApplied, 2);

    // (0) Porsi yang dilunasi pakai saldo deposit di supplier -> Debit
    //     Hutang Usaha (2100, fixed) / Kredit Uang Muka Pembelian (1400,
    //     fixed). Tidak ada akun yang perlu di-override di sini.
    if ($depositApplied > 0) {
        $resultDep = AutoPostJournal($db, [
            'rule_code'      => 'SUP_PAY_DEP',
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'journal_date'   => $payDate,
            'amount'         => $depositApplied,
            'description'    => 'Pelunasan Hutang Pakai Deposit ' . $paymentNo,
        ]);
        if (!$resultDep['success']) {
            throw new Exception($resultDep['message']);
        }
    }

    // (1) Porsi yang mengurangi Hutang Usaha pakai kas/bank -> Debit Hutang Usaha (fixed) / Kredit Kas-Bank (override)
    if ($cashPortionAp > 0) {
        $resultAp = AutoPostJournal($db, [
            'rule_code'         => 'SUP_PAY_AP',
            'reference_type'    => 'supplier_payment',
            'reference_id'      => $paymentId,
            'journal_date'      => $payDate,
            'amount'            => $cashPortionAp,
            'description'       => 'Pembayaran Hutang ' . $paymentNo,
            'credit_account_id' => $cashAccountId, // akun Kas/Bank pilihan user
        ]);
        if (!$resultAp['success']) {
            throw new Exception($resultAp['message']);
        }
    }

    // (2) Porsi lebih bayar -> Debit Uang Muka Pembelian (fixed) / Kredit Kas-Bank (override)
    if ($excessAmount > 0) {
        $resultAdv = AutoPostJournal($db, [
            'rule_code'         => 'SUP_PAY_ADV',
            'reference_type'    => 'supplier_payment',
            'reference_id'      => $paymentId,
            'journal_date'      => $payDate,
            'amount'            => $excessAmount,
            'description'       => 'Uang Muka Pembelian dari Pembayaran ' . $paymentNo,
            'credit_account_id' => $cashAccountId, // akun Kas/Bank pilihan user
        ]);
        if (!$resultAdv['success']) {
            throw new Exception($resultAdv['message']);
        }
    }

    if ($allocatedTotal <= 0 && $excessAmount <= 0 && $depositApplied <= 0) {
        throw new Exception('Tidak ada nominal untuk diposting ke jurnal.');
    }
}

function ReverseSupplierPaymentJournal($db, $paymentId) {
    // Kedua rule (AP & Advance) sama-sama pakai reference_type
    // 'supplier_payment' -- satu panggilan ini menghapus keduanya sekaligus.
    ReverseJournalByReference($db, 'supplier_payment', $paymentId);
}

/*
  Hitung saldo deposit tersedia di seorang supplier, langsung dari
  journal_lines (net debit-kredit akun 1400 Uang Muka Pembelian), dilacak
  lewat reference_type='supplier_payment' + reference_id = id di
  supplier_payments milik supplier ini.
*/
function GetSupplierDepositBalance($db, $supplierId) {
    $sql = "
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_id
        JOIN chart_of_accounts coa ON coa.id = jl.account_id AND coa.account_code = '1400'
        WHERE je.reference_type = 'supplier_payment'
          AND je.reference_id IN (
              SELECT id FROM supplier_payments WHERE supplier_id = :supplier_id
          )
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(['supplier_id' => $supplierId]);
    $balance = floatval($stmt->fetchColumn());
    return round($balance, 2);
}


// ==================================================================
// HELPER TAMBAHAN (validasi server, penguncian, hitung ulang paid_amount)
// ==================================================================

/** Placeholder :p0,:p1,... untuk klausa IN. */
function SpInList(array $ids, $prefix, array &$params) {
    $ph = [];
    foreach (array_values($ids) as $i => $v) {
        $ph[] = ':' . $prefix . $i;
        $params[$prefix . $i] = (int)$v;
    }
    return implode(',', $ph);
}

/** Nomor referensi satu-transaksi (lihat CreateRefNoDb di function.all.php); fallback ke CreateRefNo. */
function SpNextRefNo($db, $table, $column, $prefix) {
    $no = function_exists('CreateRefNoDb')
        ? CreateRefNoDb($db, $table, $column, $prefix)
        : CreateRefNo($table, $column, $prefix);
    if ($no === '' || $no === null) {
        throw new Exception('Gagal generate nomor dokumen (' . $prefix . ').');
    }
    return $no;
}

/** Kunci per supplier selama transaksi (advisory lock, lepas otomatis saat COMMIT/ROLLBACK). */
function SpLockSupplier($db, $supplierId) {
    $supplierId = intval($supplierId);
    if ($supplierId <= 0) { return; }
    $st = $db->prepare("SELECT pg_advisory_xact_lock(7101, CAST(:sid AS integer))");
    $st->execute(['sid' => $supplierId]);
}

/** Baris items dari browser -> [invoice_id => nominal]. Baris kosong dilewati; invoice dobel ditolak. */
function SpNormalizeItems($items) {
    $alloc = [];
    if (!is_array($items)) { return $alloc; }
    foreach ($items as $it) {
        $invId = intval($it['invoice_id'] ?? 0);
        $amt   = round(floatval($it['amount_paid'] ?? 0), 2);
        if ($invId <= 0 || $amt <= 0) { continue; }
        if (isset($alloc[$invId])) {
            throw new Exception('Invoice yang sama tidak boleh muncul dua kali dalam satu pembayaran.');
        }
        $alloc[$invId] = $amt;
    }
    return $alloc;
}

/** Kunci baris invoice (urut id). */
function SpLockInvoices($db, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) { return; }
    sort($ids);
    $params = [];
    $in = SpInList($ids, 'l', $params);
    $st = $db->prepare("SELECT id FROM purchase_invoice_hdr WHERE id IN ($in) ORDER BY id FOR UPDATE");
    $st->execute($params);
}

/**
 * Invoice harus ada, milik supplier yang dipilih, dan nominal <= sisa tagihan.
 * Sisa = total - SUM(item pembayaran yang ada di DB saat ini); pada update, item lama
 * pembayaran ini sudah dihapus lebih dulu, jadi hanya pembayaran LAIN yang dihitung.
 */
function SpValidateAllocations($db, $supplierId, array $alloc) {
    if (empty($alloc)) { return; }
    $ids = array_keys($alloc);
    SpLockInvoices($db, $ids);

    $params = [];
    $in = SpInList($ids, 'v', $params);
    $st = $db->prepare("
        SELECT inv.id, inv.inv_no, inv.supplier_id,
               COALESCE(inv.total_amount, 0) AS total_amount,
               COALESCE((SELECT SUM(i.amount_paid) FROM supplier_payment_items i WHERE i.invoice_id = inv.id), 0) AS paid_now
        FROM purchase_invoice_hdr inv
        WHERE inv.id IN ($in)
    ");
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $map[intval($r['id'])] = $r; }

    foreach ($alloc as $invId => $amt) {
        if (!isset($map[$invId])) {
            throw new Exception("Invoice (id {$invId}) tidak ditemukan.");
        }
        $r = $map[$invId];
        if (intval($r['supplier_id']) !== intval($supplierId)) {
            throw new Exception("Invoice {$r['inv_no']} milik supplier yang berbeda dengan pembayaran ini.");
        }
        $outstanding = round((float)$r['total_amount'] - (float)$r['paid_now'], 2);
        if ($amt > $outstanding + 0.01) {
            throw new Exception("Pembayaran untuk invoice {$r['inv_no']} (Rp " . number_format($amt, 2) . ") melebihi sisa tagihan (Rp " . number_format($outstanding, 2) . ").");
        }
    }
}

/** paid_amount = SUM(item pembayaran). Idempotent, tidak ada GREATEST(0, ...) yang menyembunyikan selisih. */
function SpRecalcInvoicePaid($db, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) { return; }
    sort($ids);
    $params = [];
    $in = SpInList($ids, 'r', $params);
    $st = $db->prepare("
        UPDATE purchase_invoice_hdr AS h
           SET paid_amount = COALESCE((SELECT SUM(i.amount_paid) FROM supplier_payment_items i WHERE i.invoice_id = h.id), 0)
         WHERE h.id IN ($in)
    ");
    $st->execute($params);
}

/**
 * Saldo deposit supplier tidak boleh menjadi negatif akibat operasi ini.
 * $before = [supplier_id => saldo sebelum operasi]. Bila saldo sudah negatif sebelumnya dan
 * tidak bertambah buruk, operasi tetap diizinkan.
 */
function SpAssertDepositNotNegative($db, array $before) {
    foreach ($before as $sid => $b) {
        $after = GetSupplierDepositBalance($db, $sid);
        if ($after < -0.01 && $after < $b - 0.01) {
            throw new Exception("Saldo deposit supplier akan menjadi negatif (Rp " . number_format($after, 2) . "). Uang muka dari pembayaran ini sudah dipakai pembayaran lain -- batalkan/ubah pembayaran yang memakainya terlebih dahulu.");
        }
    }
}