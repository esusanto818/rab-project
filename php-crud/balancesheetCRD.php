<?php
/*
  Document      : Balance Sheet (Neraca) - CRUD & Report Handler
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/financial_balance_sheetCRD.php
=======
  Backend       : /rab-project/live/php-crud/financial_balance_sheetCRD.php
>>>>>>> Stashed changes
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task   = $_POST['task'] ?? $_REQUEST['task'] ?? 'read_balance_sheet';
$access = intval($_SESSION['FormAccess'] ?? 0);
$db     = new crud();

switch ($task) {

    case 'read_periods':
        $sql = "SELECT id, period_name, start_date, end_date FROM accounting_periods ORDER BY start_date DESC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'read_balance_sheet':
        $periodId = intval($_POST['period_id'] ?? $_REQUEST['period_id'] ?? 0);
        if ($periodId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Periode akuntansi wajib dipilih.']);
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

        // Saldo Akun Riil (Mencakup asset, bank, cash, liability, equity) s/d as_of_date
        $sql = "
            SELECT c.id, c.account_code, c.account_name, c.account_type,
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL THEN
                           CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                                WHEN c.account_type IN ('liability', 'equity') THEN l.credit - l.debit
                                ELSE 0 END
                       ELSE 0 END
                   ), 0) AS balance
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date <= :as_of_date
            WHERE c.is_active = true
              AND c.account_type IN ('asset', 'bank', 'cash', 'liability', 'equity')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type
            ORDER BY c.account_code ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['as_of_date' => $asOfDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Laba/Rugi Berjalan s/d $asOfDate
        $sqlPL = "
            SELECT COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL THEN
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                WHEN c.account_type = 'expense' THEN l.credit - l.debit   -- [FIX-LABA] beban MENGURANGI laba (sebelumnya debit - credit sehingga beban malah menambah laba)
                                ELSE 0 END
                       ELSE 0 END
                   ), 0) AS net_profit
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date <= :as_of_date
            WHERE c.account_type IN ('revenue', 'expense')
        ";
        $stmtPL = $db->prepare($sqlPL);
        $stmtPL->execute(['as_of_date' => $asOfDate]);
        $currentEarnings = floatval($stmtPL->fetch(PDO::FETCH_ASSOC)['net_profit'] ?? 0);

        $assets      = [];
        $liabilities = [];
        $equities    = [];
        
        $totalAsset     = 0;
        $totalLiability = 0;
        $totalEquity    = 0;

        foreach ($rows as $r) {
            $balance = floatval($r['balance']);
            $item = [
                'id'           => $r['id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'balance'      => $balance
            ];

            if (in_array($r['account_type'], ['asset', 'bank', 'cash'])) {
                $assets[]   = $item;
                $totalAsset += $balance;
            } else if ($r['account_type'] === 'liability') {
                $liabilities[]   = $item;
                $totalLiability += $balance;
            } else if ($r['account_type'] === 'equity') {
                $equities[]   = $item;
                $totalEquity += $balance;
            }
        }

        if (abs($currentEarnings) > 0.001) {
            $equities[] = [
                'id'           => 0,
                'account_code' => '-',
                'account_name' => 'Laba / (Rugi) Periode Berjalan',
                'balance'      => $currentEarnings
            ];
            $totalEquity += $currentEarnings;
        }

        $totalLiabilityEquity = $totalLiability + $totalEquity;

        echo json_encode([
            'success'                => true,
            'period_name'            => $period['period_name'],
            'as_of_date'             => $asOfDate,
            'assets'                 => $assets,
            'liabilities'            => $liabilities,
            'equities'               => $equities,
            'total_asset'            => $totalAsset,
            'total_liability'        => $totalLiability,
            'total_equity'           => $totalEquity,
            'total_liability_equity' => $totalLiabilityEquity,
            'is_balanced'            => abs($totalAsset - $totalLiabilityEquity) < 0.01
        ]);
        break;

    case 'create':
        if ($access < 2) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit; }
        $code = trim($_POST['account_code'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        $type = trim($_POST['account_type'] ?? '');
        if (empty($code) || empty($name) || !in_array($type, ['asset', 'bank', 'cash', 'liability', 'equity'])) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']); exit;
        }
        $db->insert('chart_of_accounts', ['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'is_active' => true]);
        echo json_encode(['success' => true, 'message' => 'Akun berhasil ditambahkan.']);
        break;

    case 'update':
        if ($access < 3) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit; }
        $id = intval($_POST['id'] ?? 0);
        $code = trim($_POST['account_code'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        if ($id <= 0 || empty($code) || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Data tidak valid.']); exit;
        }
        $db->update('chart_of_accounts', ['account_code' => $code, 'account_name' => $name], "id = {$id}");
        echo json_encode(['success' => true, 'message' => 'Akun berhasil diperbarui.']);
        break;

    case 'delete':
        if ($access < 4) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit; }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); exit; }
        $db->update('chart_of_accounts', ['is_active' => false], "id = {$id}");
        echo json_encode(['success' => true, 'message' => 'Akun dinonaktifkan.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
}