<?php
/*
  Document      : Trial Balance - Report Handler
  Called from   : js/m.trialbalance.js
  Note          : - Laporan read-only.
                  - Saldo dihitung sebagai akumulasi SEMUA transaksi posted/
                    reversed sampai dengan tanggal akhir periode terpilih
                    (bukan cuma mutasi dalam periode itu saja -- trial balance
                    adalah saldo KUMULATIF per titik waktu tertentu).
                  - Saldo ditampilkan di kolom Debit kalau positif untuk akun
                    normal-debit (asset/expense), sebaliknya di kolom Kredit.
                    Kalau saldo "terbalik" (kontra), tetap ditampilkan di
                    kolom lawan supaya total tetap balance secara matematis.
                  - [FIX-BANKCASH] Akun bank & cash diperlakukan debit-normal
                    (konsisten dengan Neraca).
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once   '../php-func/function.all.php';
require_once   '../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read_trial_balance';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca neraca saldo tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read_periods':
        $sql = "SELECT id, period_name, start_date, end_date FROM accounting_periods ORDER BY start_date DESC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ==============================================================
    case 'read_trial_balance':
        $periodId = intval($_POST['period_id'] ?? 0);
        if ($periodId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Periode wajib dipilih.']);
            exit;
        }

        $stmtP = $db->prepare("SELECT period_name, end_date FROM accounting_periods WHERE id = :id");
        $stmtP->execute(['id' => $periodId]);
        $period = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            echo json_encode(['success' => false, 'message' => 'Periode tidak ditemukan.']);
            exit;
        }
        $asOfDate = $period['end_date'];

        $sql = "
            SELECT c.id, c.account_code, c.account_name, c.account_type,
                   COALESCE(SUM(
                       CASE WHEN c.account_type IN ('asset','bank','cash','expense') THEN l.debit - l.credit
                            ELSE l.credit - l.debit END
                   ), 0) AS balance
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date <= :as_of_date
            WHERE c.is_active = true
            GROUP BY c.id, c.account_code, c.account_name, c.account_type
            ORDER BY c.account_code ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['as_of_date' => $asOfDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($rows as $r) {
            $isDebitNormal = in_array($r['account_type'], ['asset', 'bank', 'cash', 'expense']);   // [FIX-BANKCASH] bank & cash = debit-normal
            $balance = floatval($r['balance']);

            $debitCol = 0;
            $creditCol = 0;
            if ($isDebitNormal) {
                if ($balance >= 0) { $debitCol = $balance; } else { $creditCol = abs($balance); }
            } else {
                if ($balance >= 0) { $creditCol = $balance; } else { $debitCol = abs($balance); }
            }

            $totalDebit += $debitCol;
            $totalCredit += $creditCol;

            $data[] = [
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'account_type' => $r['account_type'],
                'debit'        => $debitCol,
                'credit'       => $creditCol
            ];
        }

        echo json_encode([
            'success'      => true,
            'period_name'  => $period['period_name'],
            'as_of_date'   => $asOfDate,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balance'   => abs($totalDebit - $totalCredit) < 0.01,
            'totalCount'   => count($data),
            'data'         => $data
        ]);
        break;

    // ==============================================================
    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}