<?php
/**
* subcon.invoiceCRD.php
* ============================================================
* Backend Modul Invoice & Pembayaran Subkontraktor (2-Step Payment)
* 
* Alur:
*   Step 1: Terima Invoice dari Subkon (berdasarkan prestasi disetujui)
*           Draft -> Diajukan -> Disetujui -> (Dibatalkan)
*           Saat Disetujui: jurnal Dr WIP / Cr Hutang Subkon
*   
*   Step 2: Release Payment (bayar invoice)
*           Posted -> (Dibatalkan)
*           Saat Posted: jurnal Dr Hutang Subkon / Cr Kas-Bank
* 
* File related: - subcon.invoice.js
*               - subcon.invoice.php
* Migration: sql/2026-11-02.subcon_invoice.sql
* ============================================================
*/
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
$db = new crud();

const SC_INV_EPS = 0.01;
const SC_INV_REF = 'subcon_invoice';
const SC_INV_PAY_REF = 'subcon_invoice_pay';

class ScInvError extends Exception {}

function sc_inv_send($success, $data = [], $message = '', $extra = []) {
    $resp = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $resp['data'] = $data;
        if (!isset($resp['totalCount'])) $resp['totalCount'] = is_array($data) ? count($data) : 0;
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sc_inv_f($v): float { return round((float)$v, 2); }
function sc_inv_uid() { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }

function sc_inv_has_journal($db, string $ref, int $id): bool {
    $st = $db->prepare("SELECT 1 FROM journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => $ref, ':id' => $id]);
    return (bool)$st->fetchColumn();
}

function sc_inv_post($db, string $rule, string $ref, int $id, string $date, float $amount, string $desc, ?int $creditAccountId = null): void {
    if ($amount <= 0.004) return;
    $p = ['rule_code' => $rule, 'reference_type' => $ref, 'reference_id' => $id, 'journal_date' => $date, 'amount' => $amount, 'description' => $desc];
    if ($creditAccountId) $p['credit_account_id'] = $creditAccountId;
    $r = AutoPostJournal($db, $p);
    if (!$r['success']) throw new ScInvError('Gagal posting jurnal ' . $rule . ': ' . $r['message']);
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sc_inv_send(false, [], 'Sesi berakhir, silakan login ulang.');
}


$task = $_REQUEST['task'] ?? '';
// Otorisasi aksi: 1 baca, 2 tambah, 3 perubahan/persetujuan, 4 pembatalan transaksi.
$scInvAccess = (int)($_SESSION['FormAccess'] ?? 0);
$scInvRequired = 1;
if ($task === 'save_invoice' || $task === 'save_payment') {
    $scInvRequired = 2;
} elseif (in_array($task, ['update_invoice_status', 'approve_invoice'], true)) {
    $scInvRequired = 3;
} elseif (in_array($task, ['cancel_invoice', 'cancel_payment'], true)) {
    $scInvRequired = 4;
}
// save_invoice melayani create dan update; update invoice Draft wajib level 3.
if ($task === 'save_invoice' && (int)($_POST['id'] ?? 0) > 0) {
    $scInvRequired = 3;
}
if ($scInvAccess < $scInvRequired) {
    http_response_code(403);
    sc_inv_send(false, [], 'Hak akses tidak mencukupi untuk operasi invoice subkontraktor ini.');
}

try {
    switch ($task) {
        /* ------------------------------------------------------------------
         * MASTER: List kontrak subkon untuk combo
         * ------------------------------------------------------------------ */
        case 'list_contracts':
            $rows = $db->query("SELECT c.id, c.spk_no, s.name AS subcontractor_name, c.contract_value, c.status
                FROM subcon_contracts c
                JOIN subcontractors s ON s.id = c.subcontractor_id
                WHERE c.status = 'Disetujui'
                ORDER BY c.spk_no ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['contract_value'] = (float)$r['contract_value'];
            }
            sc_inv_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * STEP 1: LIST INVOICES (Grid utama invoice subkon)
         * ------------------------------------------------------------------ */
        case 'list_invoices':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $contractId = (int)($_REQUEST['contract_id'] ?? 0);
            
            $where = '';
            $params = [];
            if ($contractId > 0) {
                $where = "WHERE inv.contract_id = :cid";
                $params[':cid'] = $contractId;
            }
            
            $sql = "SELECT inv.id, inv.invoice_no, inv.contract_id, c.spk_no, s.name AS subcontractor_name,
                inv.progress_id, p.ref_no AS progress_ref_no,
                inv.invoice_date, inv.due_date, inv.total_amount, inv.paid_amount,
                (inv.total_amount - inv.paid_amount) AS unpaid_amount,
                inv.status, inv.reference_no, inv.notes,
                inv.created_by, cu.login_name AS created_by_name,
                inv.approved_by, au.login_name AS approved_by_name
                FROM subcon_invoices inv
                JOIN subcon_contracts c ON c.id = inv.contract_id
                JOIN subcontractors s ON s.id = c.subcontractor_id
                LEFT JOIN subcon_progress p ON p.id = inv.progress_id
                LEFT JOIN m_user_system cu ON cu.id = inv.created_by
                LEFT JOIN m_user_system au ON au.id = inv.approved_by
                {$where}
                ORDER BY inv.invoice_date DESC, inv.id DESC
                LIMIT :limit OFFSET :start";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->execute();
            
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['contract_id'] = (int)$r['contract_id'];
                $r['progress_id'] = $r['progress_id'] !== null ? (int)$r['progress_id'] : null;
                $r['total_amount'] = (float)$r['total_amount'];
                $r['paid_amount'] = (float)$r['paid_amount'];
                $r['unpaid_amount'] = (float)$r['unpaid_amount'];
                $r['created_by'] = (int)$r['created_by'];
                $r['approved_by'] = $r['approved_by'] !== null ? (int)$r['approved_by'] : null;
            }
            unset($r);
            
            sc_inv_send(true, $rows, '', ['totalCount' => count($rows)]);
            break;

        /* ------------------------------------------------------------------
         * STEP 1: LOAD INVOICE (untuk edit/detail)
         * ------------------------------------------------------------------ */
        case 'load_invoice':
            $id = (int)($_REQUEST['id'] ?? 0);
            $stmt = $db->prepare("SELECT inv.*, c.spk_no, s.name AS subcontractor_name, s.npwp_no,
                p.ref_no AS progress_ref_no, p.gross_amount, p.ppn_amount, p.pph_amount,
                p.retention_amount, p.dp_recovery, p.net_payable,
                cu.login_name AS created_by_name, au.login_name AS approved_by_name
                FROM subcon_invoices inv
                JOIN subcon_contracts c ON c.id = inv.contract_id
                JOIN subcontractors s ON s.id = c.subcontractor_id
                LEFT JOIN subcon_progress p ON p.id = inv.progress_id
                LEFT JOIN m_user_system cu ON cu.id = inv.created_by
                LEFT JOIN m_user_system au ON au.id = inv.approved_by
                WHERE inv.id = :id");
            $stmt->execute([':id' => $id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$inv) sc_inv_send(false, [], 'Invoice tidak ditemukan.');
            
            $inv['id'] = (int)$inv['id'];
            $inv['contract_id'] = (int)$inv['contract_id'];
            $inv['progress_id'] = $inv['progress_id'] !== null ? (int)$inv['progress_id'] : null;
            $inv['total_amount'] = (float)$inv['total_amount'];
            $inv['paid_amount'] = (float)$inv['paid_amount'];
            $inv['gross_amount'] = (float)($inv['gross_amount'] ?? 0);
            $inv['ppn_amount'] = (float)($inv['ppn_amount'] ?? 0);
            $inv['pph_amount'] = (float)($inv['pph_amount'] ?? 0);
            $inv['retention_amount'] = (float)($inv['retention_amount'] ?? 0);
            $inv['dp_recovery'] = (float)($inv['dp_recovery'] ?? 0);
            $inv['net_payable'] = (float)($inv['net_payable'] ?? 0);
            
            // Load pembayaran yang sudah ada
            $stmtPay = $db->prepare("SELECT pay.id, pay.payment_no, pay.payment_date, pay.amount, pay.status,
                a.account_code, a.account_name, pay.reference_no, pay.notes,
                pay.created_by, cu.login_name AS created_by_name
                FROM subcon_invoice_payments pay
                JOIN chart_of_accounts a ON a.id = pay.account_id
                LEFT JOIN m_user_system cu ON cu.id = pay.created_by
                WHERE pay.invoice_id = :id AND pay.status = 'Posted'
                ORDER BY pay.payment_date DESC");
            $stmtPay->execute([':id' => $id]);
            $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
            foreach ($payments as &$p) {
                $p['id'] = (int)$p['id'];
                $p['amount'] = (float)$p['amount'];
                $p['created_by'] = (int)$p['created_by'];
            }
            
            sc_inv_send(true, ['invoice' => $inv, 'payments' => $payments]);
            break;

        /* ------------------------------------------------------------------
         * STEP 1: LIST PROGRESS (untuk pilih prestasi saat buat invoice)
         * ------------------------------------------------------------------ */
        case 'list_progress':
            $contractId = (int)($_REQUEST['contract_id'] ?? 0);
            if ($contractId <= 0) sc_inv_send(true, []);
            
            $stmt = $db->prepare("SELECT p.id, p.ref_no, p.progress_date, p.gross_amount, p.ppn_amount,
                p.pph_amount, p.retention_amount, p.dp_recovery, p.net_payable, p.cum_pct, p.status,
                COALESCE((SELECT SUM(pay.amount) FROM subcon_invoice_payments pay 
                    JOIN subcon_invoices inv ON inv.id = pay.invoice_id 
                    WHERE inv.progress_id = p.id AND pay.status = 'Posted'), 0) AS paid_amount
                FROM subcon_progress p
                WHERE p.contract_id = :cid AND p.status = 'Disetujui'
                ORDER BY p.progress_date DESC");
            $stmt->execute([':cid' => $contractId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['gross_amount'] = (float)$r['gross_amount'];
                $r['ppn_amount'] = (float)$r['ppn_amount'];
                $r['pph_amount'] = (float)$r['pph_amount'];
                $r['retention_amount'] = (float)$r['retention_amount'];
                $r['dp_recovery'] = (float)$r['dp_recovery'];
                $r['net_payable'] = (float)$r['net_payable'];
                $r['cum_pct'] = (float)$r['cum_pct'];
                $r['paid_amount'] = (float)$r['paid_amount'];
                $r['unpaid_amount'] = round($r['net_payable'] - $r['paid_amount'], 2);
            }
            sc_inv_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * STEP 1: SAVE INVOICE (Create/Update)
         * ------------------------------------------------------------------ */
        case 'save_invoice':
            $id = (int)($_POST['id'] ?? 0);
            $contractId = (int)($_POST['contract_id'] ?? 0);
            $progressId = (int)($_POST['progress_id'] ?? 0) ?: null;
            $invoiceDate = (string)($_POST['invoice_date'] ?? '');
            $dueDate = (string)($_POST['due_date'] ?? '') ?: null;
            $totalAmount = sc_inv_f($_POST['total_amount'] ?? 0);
            $referenceNo = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            
            if ($contractId <= 0) throw new ScInvError('Kontrak subkon wajib dipilih.');
            if ($invoiceDate === '') throw new ScInvError('Tanggal invoice wajib diisi.');
            if ($totalAmount <= 0) throw new ScInvError('Total invoice harus lebih dari 0.');
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT status FROM subcon_contracts WHERE id = :id");
                $stmt->execute([':id' => $contractId]);
                $contract = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$contract || $contract['status'] !== 'Disetujui') {
                    throw new ScInvError('Kontrak subkon tidak ditemukan atau tidak berstatus Disetujui.');
                }
                
                if ($progressId) {
                    $stmt = $db->prepare("SELECT net_payable, status FROM subcon_progress WHERE id = :id");
                    $stmt->execute([':id' => $progressId]);
                    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$progress || $progress['status'] !== 'Disetujui') {
                        throw new ScInvError('Prestasi tidak ditemukan atau belum disetujui.');
                    }
                    $stmt = $db->prepare("SELECT 1 FROM subcon_invoices WHERE progress_id = :pid AND id <> :id AND status <> 'Dibatalkan'");
                    $stmt->execute([':pid' => $progressId, ':id' => $id]);
                    if ($stmt->fetchColumn()) {
                        throw new ScInvError('Prestasi ini sudah di-invoice.');
                    }
                }
                
                if ($id > 0) {
                    $stmt = $db->prepare("SELECT status FROM subcon_invoices WHERE id = :id FOR UPDATE");
                    $stmt->execute([':id' => $id]);
                    $cur = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$cur) throw new ScInvError('Invoice tidak ditemukan.');
                    if ($cur['status'] !== 'Draft') throw new ScInvError('Hanya invoice Draft yang bisa diubah.');
                    
                    $db->prepare("UPDATE subcon_invoices SET contract_id = :cid, progress_id = :pid,
                        invoice_date = CAST(:idate AS date), due_date = CAST(:ddate AS date),
                        total_amount = :amt, reference_no = :ref, notes = :notes, updated_at = NOW()
                        WHERE id = :id")
                        ->execute([':cid' => $contractId, ':pid' => $progressId, ':idate' => $invoiceDate,
                        ':ddate' => $dueDate, ':amt' => $totalAmount, ':ref' => $referenceNo,
                        ':notes' => $notes, ':id' => $id]);
                    
                    $invNo = $db->query("SELECT invoice_no FROM subcon_invoices WHERE id = {$id}")->fetchColumn();
                } else {
                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $invNo = CreateRefNo('subcon_invoices', 'invoice_no', 'INV-SC');
                    
                    $stmt = $db->prepare("INSERT INTO subcon_invoices (invoice_no, contract_id, progress_id, invoice_date, due_date,
                        total_amount, paid_amount, status, reference_no, notes, created_by)
                        VALUES (:no, :cid, :pid, CAST(:idate AS date), CAST(:ddate AS date), :amt, 0, 'Draft', :ref, :notes, :uid)
                        RETURNING id");
                    $stmt->execute([':no' => $invNo, ':cid' => $contractId, ':pid' => $progressId,
                        ':idate' => $invoiceDate, ':ddate' => $dueDate, ':amt' => $totalAmount,
                        ':ref' => $referenceNo, ':notes' => $notes, ':uid' => sc_inv_uid()]);
                    $id = (int)$stmt->fetchColumn();
                }
                
                $db->commit();
                sc_inv_send(true, ['id' => $id, 'invoice_no' => $invNo], 'Invoice berhasil disimpan.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * STEP 1: SUBMIT INVOICE (Draft -> Diajukan)
         * ------------------------------------------------------------------ */
        case 'update_invoice_status':
            $id = (int)($_POST['id'] ?? 0);
            $to = (string)($_POST['to'] ?? '');
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM subcon_invoices WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $id]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new ScInvError('Invoice tidak ditemukan.');
                
                $allowed = ['Draft' => ['Diajukan', 'Dibatalkan'], 'Diajukan' => ['Disetujui', 'Draft', 'Dibatalkan']];
                if (!in_array($to, $allowed[$inv['status']] ?? [])) {
                    throw new ScInvError("Status {$inv['status']} tidak dapat diubah ke {$to}.");
                }
                
                $set = "status = :s, updated_at = NOW()";
                $p = [':s' => $to, ':id' => $id];
                
                if ($to === 'Disetujui') {
                    $set .= ", approved_by = :uid, approved_at = NOW()";
                    $p[':uid'] = sc_inv_uid();
                }
                
                $db->prepare("UPDATE subcon_invoices SET {$set} WHERE id = :id")->execute($p);
                $db->commit();
                sc_inv_send(true, [], "Invoice sekarang berstatus {$to}.");
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * STEP 1: APPROVE INVOICE (Disetujui -> posting jurnal)
         * ------------------------------------------------------------------ */
        case 'approve_invoice':
            $id = (int)($_POST['id'] ?? 0);
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM subcon_invoices WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $id]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new ScInvError('Invoice tidak ditemukan.');
                if ($inv['status'] !== 'Diajukan') throw new ScInvError('Hanya invoice Diajukan yang bisa disetujui.');
                
                $db->prepare("UPDATE subcon_invoices SET status = 'Disetujui', approved_by = :uid, approved_at = NOW()
                    WHERE id = :id")->execute([':uid' => sc_inv_uid(), ':id' => $id]);
                
                $date = $inv['invoice_date'];
                $desc = "Invoice subkon {$inv['invoice_no']}";
                sc_inv_post($db, 'SUBCON_INVOICE', SC_INV_REF, $id, $date, (float)$inv['total_amount'], $desc);
                
                $db->commit();
                sc_inv_send(true, [], 'Invoice disetujui & dijurnal.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * STEP 1: CANCEL INVOICE
         * ------------------------------------------------------------------ */
        case 'cancel_invoice':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 5) throw new ScInvError('Alasan pembatalan minimal 5 karakter.');
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM subcon_invoices WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $id]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new ScInvError('Invoice tidak ditemukan.');
                if ($inv['status'] === 'Dibatalkan') throw new ScInvError('Invoice sudah dibatalkan.');
                
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM subcon_invoice_payments WHERE invoice_id = :id AND status = 'Posted'");
                $stmt->execute([':id' => $id]);
                $paid = (float)$stmt->fetchColumn();
                if ($paid > 0) throw new ScInvError('Invoice sudah ada pembayaran. Batalkan pembayaran dulu.');
                
                if ($inv['status'] === 'Disetujui' && sc_inv_has_journal($db, SC_INV_REF, $id)) {
                    ReverseJournalByReference($db, SC_INV_REF, $id);
                }
                
                $db->prepare("UPDATE subcon_invoices SET status = 'Dibatalkan', cancelled_by = :uid, 
                    cancelled_at = NOW(), cancel_reason = :reason WHERE id = :id")
                    ->execute([':uid' => sc_inv_uid(), ':reason' => $reason, ':id' => $id]);
                
                $db->commit();
                sc_inv_send(true, [], 'Invoice dibatalkan.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * STEP 2: LIST ACCOUNTS (untuk combo pembayaran)
         * ------------------------------------------------------------------ */
        case 'list_accounts':
            $rows = $db->query("SELECT id, account_code, account_name FROM chart_of_accounts
                WHERE account_type::text IN ('cash','bank') AND is_active
                ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['label'] = $r['account_code'] . ' - ' . $r['account_name'];
            }
            sc_inv_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * STEP 2: SAVE PAYMENT (Release Payment)
         * ------------------------------------------------------------------ */
        case 'save_payment':
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $contractId = (int)($_POST['contract_id'] ?? 0);
            $paymentDate = (string)($_POST['payment_date'] ?? '');
            $accountId = (int)($_POST['account_id'] ?? 0);
            $paymentMethod = trim((string)($_POST['payment_method'] ?? 'BANK_TRANSFER'));
            $amount = sc_inv_f($_POST['amount'] ?? 0);
            $referenceNo = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            
            if ($invoiceId <= 0) throw new ScInvError('Invoice wajib dipilih.');
            if ($accountId <= 0) throw new ScInvError('Akun kas/bank wajib dipilih.');
            if ($amount <= 0) throw new ScInvError('Jumlah pembayaran harus lebih dari 0.');
            if ($paymentDate === '') throw new ScInvError('Tanggal pembayaran wajib diisi.');
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM subcon_invoices WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $invoiceId]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new ScInvError('Invoice tidak ditemukan.');
                if ($inv['status'] !== 'Disetujui') throw new ScInvError('Hanya invoice Disetujui yang bisa dibayar.');
                if ((int)$inv['contract_id'] !== $contractId) throw new ScInvError('Invoice tidak sesuai kontrak.');
                
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM subcon_invoice_payments WHERE invoice_id = :id AND status = 'Posted'");
                $stmt->execute([':id' => $invoiceId]);
                $paid = (float)$stmt->fetchColumn();
                $remaining = round((float)$inv['total_amount'] - $paid, 2);
                
                if ($amount > $remaining + SC_INV_EPS) {
                    throw new ScInvError('Jumlah pembayaran melebihi sisa hutang invoice (sisa: ' . number_format($remaining, 2) . ').');
                }
                
                if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                $payNo = CreateRefNo('subcon_invoice_payments', 'payment_no', 'PAY-SC');
                
                $stmt = $db->prepare("INSERT INTO subcon_invoice_payments (payment_no, invoice_id, contract_id, payment_date,
                    account_id, payment_method, amount, reference_no, notes, status, created_by)
                    VALUES (:no, :iid, :cid, CAST(:pdate AS date), :aid, :method, :amt, :ref, :notes, 'Posted', :uid)
                    RETURNING id");
                $stmt->execute([':no' => $payNo, ':iid' => $invoiceId, ':cid' => $contractId,
                    ':pdate' => $paymentDate, ':aid' => $accountId, ':method' => $paymentMethod,
                    ':amt' => $amount, ':ref' => $referenceNo, ':notes' => $notes, ':uid' => sc_inv_uid()]);
                $payId = (int)$stmt->fetchColumn();
                
                $newPaid = round($paid + $amount, 2);
                $db->prepare("UPDATE subcon_invoices SET paid_amount = :paid WHERE id = :id")
                    ->execute([':paid' => $newPaid, ':id' => $invoiceId]);
                
                $desc = "Pembayaran invoice subkon {$inv['invoice_no']}";
                sc_inv_post($db, 'SUBCON_INVOICE_PAY', SC_INV_PAY_REF, $payId, $paymentDate, $amount, $desc, $accountId);
                
                $db->commit();
                sc_inv_send(true, ['id' => $payId, 'payment_no' => $payNo], 'Pembayaran berhasil disimpan & dijurnal.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * STEP 2: CANCEL PAYMENT
         * ------------------------------------------------------------------ */
        case 'cancel_payment':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 5) throw new ScInvError('Alasan pembatalan minimal 5 karakter.');
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM subcon_invoice_payments WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $id]);
                $pay = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new ScInvError('Pembayaran tidak ditemukan.');
                if ($pay['status'] !== 'Posted') throw new ScInvError('Pembayaran sudah dibatalkan.');
                
                if (sc_inv_has_journal($db, SC_INV_PAY_REF, $id)) {
                    ReverseJournalByReference($db, SC_INV_PAY_REF, $id);
                }
                
                $db->prepare("UPDATE subcon_invoice_payments SET status = 'Dibatalkan', cancelled_by = :uid,
                    cancelled_at = NOW(), cancel_reason = :reason WHERE id = :id")
                    ->execute([':uid' => sc_inv_uid(), ':reason' => $reason, ':id' => $id]);
                
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM subcon_invoice_payments 
                    WHERE invoice_id = :iid AND status = 'Posted'");
                $stmt->execute([':iid' => $pay['invoice_id']]);
                $newPaid = (float)$stmt->fetchColumn();
                $db->prepare("UPDATE subcon_invoices SET paid_amount = :paid WHERE id = :id")
                    ->execute([':paid' => $newPaid, ':id' => $pay['invoice_id']]);
                
                $db->commit();
                sc_inv_send(true, [], 'Pembayaran dibatalkan.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            break;

        default:
            sc_inv_send(false, [], 'Task tidak dikenali.');
            break;
    }
} catch (ScInvError $e) {
    sc_inv_send(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[subcon.invoiceCRD] PDO: ' . $e->getMessage());
    sc_inv_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[subcon.invoiceCRD] ' . $e->getMessage());
    sc_inv_send(false, [], 'System Error: ' . $e->getMessage());
}
