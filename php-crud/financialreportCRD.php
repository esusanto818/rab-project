<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/*
  Document      : Financial Report - Neraca & Laba Rugi
  Called from   : js/m.financialreport.js
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once  '../php-func/function.all.php';
require_once  '../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read_report';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca laporan keuangan tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    case 'read_periods':
        $sql = "SELECT id, period_name, start_date, end_date FROM accounting_periods ORDER BY start_date DESC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'read_report':
        $periodId = intval($_POST['period_id'] ?? 0);
        if ($periodId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Periode wajib dipilih.']);
            exit;
        }

        $stmtP = $db->prepare("SELECT period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
        $stmtP->execute(['id' => $periodId]);
        $period = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            echo json_encode(['success' => false, 'message' => 'Periode tidak ditemukan.']);
            exit;
        }
        $startDate = $period['start_date'];
        $endDate   = $period['end_date'];

        // NERACA (Kumulatif s/d end_date) - Mencakup asset, bank, cash, liability, equity
        $sqlBS = "
            SELECT c.account_code, c.account_name, c.account_type,
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL THEN
                           CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                                ELSE l.credit - l.debit END
                       ELSE 0 END
                   ), 0) AS balance
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date <= :end_date
            WHERE c.is_active = true 
              AND c.account_type IN ('asset', 'bank', 'cash', 'liability', 'equity')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type
            HAVING COALESCE(SUM(
                CASE WHEN j.id IS NOT NULL THEN
                    CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                         ELSE l.credit - l.debit END
                ELSE 0 END
            ), 0) != 0
            ORDER BY c.account_code ASC
        ";
        $stmtBS = $db->prepare($sqlBS);
        $stmtBS->execute(['end_date' => $endDate]);
        $bsRows = $stmtBS->fetchAll(PDO::FETCH_ASSOC);

        $asset = []; $liability = []; $equity = [];
        $assetTotal = 0; $liabilityTotal = 0; $equityTotal = 0;
        foreach ($bsRows as $r) {
            $bal = floatval($r['balance']);
            $item = ['account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'balance' => $bal];
            if (in_array($r['account_type'], ['asset', 'bank', 'cash'])) { 
                $asset[] = $item; 
                $assetTotal += $bal; 
            } else if ($r['account_type'] === 'liability')  { 
                $liability[] = $item; 
                $liabilityTotal += $bal; 
            } else if ($r['account_type'] === 'equity')     { 
                $equity[] = $item;    
                $equityTotal += $bal; 
            }
        }

        // Laba/Rugi Kumulatif s/d end_date
        $sqlNI = "
            SELECT COALESCE(SUM(
                CASE WHEN j.id IS NOT NULL THEN
                    CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                         ELSE -(l.debit - l.credit) END
                ELSE 0 END
            ), 0) AS net_income
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id 
                AND j.status IN ('posted','reversed') 
                AND j.journal_date <= :end_date
            WHERE c.account_type IN ('revenue', 'expense')
        ";
        $stmtNI = $db->prepare($sqlNI);
        $stmtNI->execute(['end_date' => $endDate]);
        $netIncomeCumulative = floatval($stmtNI->fetch(PDO::FETCH_ASSOC)['net_income'] ?? 0);
        $equityTotal += $netIncomeCumulative;

        // LABA RUGI (Mutasi dalam periode terpilih)
        // [YTD] Awal tahun berjalan = 1 Januari tahun akhir periode (bila periode mulai sebelum itu, pakai awal periode)
        $ytdStart = date('Y', strtotime($endDate)) . '-01-01';
        if ($startDate < $ytdStart) { $ytdStart = $startDate; }

        // LABA RUGI: amount = mutasi DALAM periode; amount_cum = tahun berjalan (YTD) s/d akhir periode
        $sqlIS = "
            SELECT c.account_code, c.account_name, c.account_type,
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL AND j.journal_date BETWEEN :start_date AND :end_date THEN
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                ELSE l.debit - l.credit END
                       ELSE 0 END
                   ), 0) AS amount,
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL THEN
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                ELSE l.debit - l.credit END
                       ELSE 0 END
                   ), 0) AS amount_cum
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date BETWEEN :ytd_start AND :cum_end
            WHERE c.is_active = true
              AND c.account_type IN ('revenue', 'expense')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type
            HAVING COALESCE(SUM(
                CASE WHEN j.id IS NOT NULL AND j.journal_date BETWEEN :h_start AND :h_end THEN
                    CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                         ELSE l.debit - l.credit END
                ELSE 0 END
            ), 0) != 0
               OR COALESCE(SUM(
                CASE WHEN j.id IS NOT NULL THEN
                    CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                         ELSE l.debit - l.credit END
                ELSE 0 END
            ), 0) != 0
            ORDER BY c.account_code ASC
        ";
        $stmtIS = $db->prepare($sqlIS);
        // [YTD] nama parameter dibedakan (PDO pgsql tidak mengizinkan nama yang sama dipakai dua kali)
        $stmtIS->execute(['start_date' => $startDate, 'end_date' => $endDate, 'ytd_start' => $ytdStart, 'cum_end' => $endDate,
                          'h_start' => $startDate, 'h_end' => $endDate]);
        $isRows = $stmtIS->fetchAll(PDO::FETCH_ASSOC);

        $revenue = []; $expense = [];
        $revenueTotal = 0; $expenseTotal = 0;
        $revenueTotalYtd = 0; $expenseTotalYtd = 0;      // [YTD]
        foreach ($isRows as $r) {
            $amt = floatval($r['amount']);
            $amtYtd = floatval($r['amount_cum']);          // [YTD]
            $item = ['account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'amount' => $amt, 'amount_ytd' => $amtYtd];
            if ($r['account_type'] === 'revenue') {
                $revenue[] = $item;
                $revenueTotal += $amt; $revenueTotalYtd += $amtYtd;
            } else if ($r['account_type'] === 'expense')  {
                $expense[] = $item;
                $expenseTotal += $amt; $expenseTotalYtd += $amtYtd;
            }
        }
        $netIncomePeriod = $revenueTotal - $expenseTotal;
        $netIncomeYtd = $revenueTotalYtd - $expenseTotalYtd;      // [YTD]

        echo json_encode([
            'success'     => true,
            'period_name' => $period['period_name'],
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'balance_sheet' => [
                'asset' => $asset, 'liability' => $liability, 'equity' => $equity,
                'asset_total' => $assetTotal, 'liability_total' => $liabilityTotal, 'equity_total' => $equityTotal,
                'net_income_cumulative' => $netIncomeCumulative,
                'is_balance' => abs($assetTotal - ($liabilityTotal + $equityTotal)) < 0.01
            ],
            'income_statement' => [
                'revenue' => $revenue, 'expense' => $expense,
                'revenue_total' => $revenueTotal, 'expense_total' => $expenseTotal,
                'net_income_period' => $netIncomePeriod,
                // [YTD] tahun berjalan (1 Jan s/d akhir periode)
                'ytd_start' => $ytdStart,
                'revenue_total_ytd' => $revenueTotalYtd, 'expense_total_ytd' => $expenseTotalYtd,
                'net_income_ytd' => $netIncomeYtd
            ]
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
}