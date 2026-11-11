<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/*
  Document      : General Ledger - Report Handler
  Called from   : js/m.generalledger.js
  Note          : - Laporan read-only, TIDAK ada create/update/delete.
                  - Filter Akun & Periode OPSIONAL:
                      account_id kosong/0 -> tampilkan SEMUA akun (dikelompokkan)
                      period_id  kosong/0 -> tampilkan SEMUA histori (tanpa
                                             batas tanggal, saldo awal = 0)
                  - Hanya jurnal berstatus 'posted' ATAU 'reversed' yang
                    dihitung. Mencakup SEMUA reference_type.
                  - Saldo berjalan dihitung PER AKUN pakai window function
                    (PARTITION BY account_id), ditambah saldo awal per akun
                    (0 kalau periode tidak difilter).
  [FIX-BANKCASH] Akun bank & cash diperlakukan debit-normal (sama dengan asset),
                    konsisten dengan Neraca. Sebelumnya saldo berjalan akun
                    Kas/Bank tampil terbalik (minus).
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read_ledger';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca buku besar tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read_accounts':
        $sql = "SELECT id, account_code, account_name, account_type
                FROM chart_of_accounts
                WHERE is_active = true
                ORDER BY account_code ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    case 'read_periods':
        $sql = "SELECT id, period_name, start_date, end_date FROM accounting_periods ORDER BY start_date DESC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    // Laporan utama -- filter account_id & period_id keduanya OPSIONAL
    case 'read_ledger':
        $accountId = intval($_POST['account_id'] ?? 0);
        $periodId  = intval($_POST['period_id'] ?? 0);

        $startDate = null;
        $endDate   = null;
        $periodName = 'Semua Periode';

        if ($periodId > 0) {
            $stmtP = $db->prepare("SELECT period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
            $stmtP->execute(['id' => $periodId]);
            $period = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                echo json_encode(['success' => false, 'message' => 'Periode tidak ditemukan.']);
                exit;
            }
            $startDate  = $period['start_date'];
            $endDate    = $period['end_date'];
            $periodName = $period['period_name'];
        }

        $accountLabel = 'Semua Akun';
        if ($accountId > 0) {
            $stmtAcc = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = :id");
            $stmtAcc->execute(['id' => $accountId]);
            $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                echo json_encode(['success' => false, 'message' => 'Akun tidak ditemukan.']);
                exit;
            }
            $accountLabel = $acc['account_code'] . ' - ' . $acc['account_name'];
        }

        // Bangun kondisi WHERE secara dinamis
        $conds  = ["j.status IN ('posted', 'reversed')"];
        $params = [];
        if ($accountId > 0) {
            $conds[] = "l.account_id = :acc";
            $params['acc'] = $accountId;
        }
        if ($startDate !== null) {
            $conds[] = "j.journal_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $where = implode(' AND ', $conds);

        // Saldo awal per akun -- hanya relevan kalau periode difilter.
        // Kalau tidak difilter, seluruh histori sudah masuk ke query utama,
        // jadi saldo awal = 0 (tidak ada "sebelum" yang perlu diakumulasi).
        $openingSubquery = "0";
        if ($startDate !== null) {
            $openingCond = "l2.account_id = l.account_id AND j2.status IN ('posted','reversed') AND j2.journal_date < :ob_start_date";
            if ($accountId > 0) {
                $openingCond .= " AND l2.account_id = :ob_acc";
            }
            $openingSubquery = "(
                SELECT COALESCE(SUM(CASE WHEN c2.account_type IN ('asset','bank','cash','expense') THEN l2.debit - l2.credit ELSE l2.credit - l2.debit END), 0)
                FROM journal_lines l2
                JOIN journal_entries j2 ON j2.id = l2.journal_id
                JOIN chart_of_accounts c2 ON c2.id = l2.account_id
                WHERE {$openingCond}
            )";
            $params['ob_start_date'] = $startDate;
            if ($accountId > 0) $params['ob_acc'] = $accountId;
        }

        $sql = "
            SELECT j.journal_date, j.ref_no, l.description AS line_desc, j.description AS header_desc,
                   l.debit, l.credit, l.account_id, c.account_code, c.account_name, c.account_type,
                   {$openingSubquery} AS opening_balance,
                   SUM(CASE WHEN c.account_type IN ('asset','bank','cash','expense') THEN l.debit - l.credit ELSE l.credit - l.debit END)
                     OVER (PARTITION BY l.account_id ORDER BY j.journal_date, j.id, l.id
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cum_in_range
            FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_id
            JOIN chart_of_accounts c ON c.id = l.account_id
            WHERE {$where}
            ORDER BY c.account_code ASC, j.journal_date ASC, j.id ASC, l.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'account_id'      => $r['account_id'],
                'account_code'    => $r['account_code'],
                'account_name'    => $r['account_name'],
                'account_label'   => $r['account_code'] . ' - ' . $r['account_name'],
                'journal_date'    => $r['journal_date'],
                'ref_no'      => $r['ref_no'],
                'description'     => $r['line_desc'] ?: $r['header_desc'],
                'debit'           => floatval($r['debit']),
                'credit'          => floatval($r['credit']),
                'balance'         => floatval($r['opening_balance']) + floatval($r['cum_in_range'])
            ];
        }

        echo json_encode([
            'success'      => true,
            'account_label'=> $accountLabel,
            'period_name'  => $periodName,
            'totalCount'   => count($data),
            'data'         => $data
        ]);
        break;

    // ==============================================================
    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}