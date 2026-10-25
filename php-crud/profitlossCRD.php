<?php
/*
  Document      : Profit & Loss (Rugi Laba) - CRUD & Report Handler
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/financial_profit_lossCRD.php
=======
  Backend       : /rab-project/live/php-crud/financial_profit_lossCRD.php
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

$task   = $_POST['task'] ?? $_REQUEST['task'] ?? 'read_profit_loss';
$access = intval($_SESSION['FormAccess'] ?? 0);
$db     = new crud();

switch ($task) {

    case 'read_periods':
        $sql = "SELECT id, period_name, start_date, end_date FROM accounting_periods ORDER BY start_date DESC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'read_profit_loss':
        $periodId = intval($_POST['period_id'] ?? $_REQUEST['period_id'] ?? 0);
        if ($periodId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Periode akuntansi wajib dipilih.']);
            exit;
        }

        $stmtP = $db->prepare("SELECT period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
        $stmtP->execute(['id' => $periodId]);
        $period = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            echo json_encode(['success' => false, 'message' => 'Periode tidak ditemukan.']);
            exit;
        }

        // [YTD] Awal tahun berjalan = 1 Januari tahun akhir periode (bila periode mulai sebelum itu, pakai awal periode)
        $ytdStart = date('Y', strtotime($period['end_date'])) . '-01-01';
        if ($period['start_date'] < $ytdStart) { $ytdStart = $period['start_date']; }

        $sql = "
            SELECT c.id, c.account_code, c.account_name, c.account_type,
                   -- [YTD] amount = mutasi DALAM periode; amount_cum = tahun berjalan (YTD): 1 Jan tahun periode s/d akhir periode
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL AND j.journal_date BETWEEN :start_date AND :end_date THEN
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                WHEN c.account_type = 'expense' THEN l.debit - l.credit
                                ELSE 0 END
                       ELSE 0 END
                   ), 0) AS amount,
                   COALESCE(SUM(
                       CASE WHEN j.id IS NOT NULL THEN
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                WHEN c.account_type = 'expense' THEN l.debit - l.credit
                                ELSE 0 END
                       ELSE 0 END
                   ), 0) AS amount_cum
            FROM chart_of_accounts c
            LEFT JOIN journal_lines l ON l.account_id = c.id
            LEFT JOIN journal_entries j ON j.id = l.journal_id
                AND j.status IN ('posted', 'reversed')
                AND j.journal_date BETWEEN :ytd_start AND :cum_end
                AND j.reference_type::text <> 'closing_entry'      -- [CLOSING] jurnal penutup tahunan tidak ikut dihitung di Laba Rugi (supaya tahun yang ditutup tetap menampilkan pendapatan & beban)
            WHERE c.is_active = true
              AND c.account_type IN ('revenue', 'expense')
            GROUP BY c.id, c.account_code, c.account_name, c.account_type
            ORDER BY c.account_code ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'start_date' => $period['start_date'],
            'end_date'   => $period['end_date'],
            'ytd_start'  => $ytdStart,               // [YTD]
            'cum_end'    => $period['end_date']      // [YTD] nama parameter dibedakan (PDO pgsql tidak mengizinkan nama yang sama dipakai dua kali)
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $revenues = [];
        $expenses = [];
        $totalRevenue = 0;
        $totalExpense = 0;
        $totalRevenueCum = 0;      // [CUM]
        $totalExpenseCum = 0;      // [CUM]

        foreach ($rows as $r) {
            $amount = floatval($r['amount']);
            $amountCum = floatval($r['amount_cum']);      // [CUM]
            $item = [
                'id'           => $r['id'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'amount'       => $amount,
                'amount_cum'   => $amountCum               // [CUM]
            ];

            if ($r['account_type'] === 'revenue') {
                $revenues[] = $item;
                $totalRevenue += $amount;
                $totalRevenueCum += $amountCum;            // [CUM]
            } else if ($r['account_type'] === 'expense') {
                $expenses[] = $item;
                $totalExpense += $amount;
                $totalExpenseCum += $amountCum;            // [CUM]
            }
        }

        $netProfitLoss = $totalRevenue - $totalExpense;

        echo json_encode([
            'success'        => true,
            'period_name'    => $period['period_name'],
            'start_date'     => $period['start_date'],
            'end_date'       => $period['end_date'],
            'ytd_start'      => $ytdStart,      // [YTD]
            'revenues'       => $revenues,
            'expenses'       => $expenses,
            'total_revenue'  => $totalRevenue,
            'total_expense'  => $totalExpense,
            'net_profit_loss'=> $netProfitLoss,
            // [CUM] total kumulatif s/d akhir periode
            'total_revenue_cum'   => $totalRevenueCum,
            'total_expense_cum'   => $totalExpenseCum,
            'net_profit_loss_cum' => $totalRevenueCum - $totalExpenseCum
        ]);
        break;

    case 'create':
        if ($access < 2) { echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit; }
        $code = trim($_POST['account_code'] ?? '');
        $name = trim($_POST['account_name'] ?? '');
        $type = trim($_POST['account_type'] ?? '');
        if (empty($code) || empty($name) || !in_array($type, ['revenue', 'expense'])) {
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