<?php
/**
 * scripts/reaudit_fase3.php
 *
 * Fase 3 #1+#5 — Re-audit otomatis terhadap proyekGMN_test setelah migrasi
 * gelombang 1-4 diterapkan dan rule PRJ_DEP_APPLY dibuat.
 * Read-only kecuali uji fungsional AutoPostJournal / trigger guard yang
 * dijalankan di dalam transaksi + ROLLBACK (tidak meninggalkan data).
 *
 * Cara jalan: RAB_DB_NAME=proyekGMN_test php scripts/reaudit_fase3.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

// Paksa target DB uji (jangan pernah ke DB default aplikasi).
putenv('RAB_DB_NAME=proyekGMN_test');
putenv('DB_NAME=proyekGMN_test');

define('PHP_SIDE_TEST', true);
require_once __DIR__ . '/../php-class/crudClass.php';

$db = new crud();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Paksa PDO koneksi ke proyekGMN_test (proteksi lapis kedua):
$dsnDb = $db->query("SELECT current_database()")->fetchColumn();
echo "Connected DB: {$dsnDb}\n";
if ($dsnDb !== 'proyekGMN_test') { fwrite(STDERR, "ABORT: bukan proyekGMN_test\n"); exit(1); }

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else     { $fail++; echo "FAIL  $label  $detail\n"; }
}
function scalar($db, string $sql, $params = []) {
    $st = $db->prepare($sql); $st->execute($params); return $st->fetchColumn();
}
function colExists($db, string $table, string $col): bool {
    return (int) scalar($db,
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?",
        [$table, $col]) === 1;
}
function trigExists($db, string $trg): bool {
    return (int) scalar($db, "SELECT COUNT(*) FROM pg_trigger WHERE tgname=? AND NOT tgisinternal", [$trg]) >= 1;
}
function conExists($db, string $con): bool {
    return (int) scalar($db, "SELECT COUNT(*) FROM pg_constraint WHERE conname=?", [$con]) >= 1;
}

echo "--- Fase 3a: struktur (migrasi #1) ---\n";
check('project_invoices.retention_pct ada', colExists($db, 'project_invoices', 'retention_pct'));
check('project_invoices.retention_amount ada', colExists($db, 'project_invoices', 'retention_amount'));
check('project_payments.currency_id ada', colExists($db, 'project_payments', 'currency_id'));
check('project_payments.exch_rate ada', colExists($db, 'project_payments', 'exch_rate'));
check('project_payments.total_amount_base ada', colExists($db, 'project_payments', 'total_amount_base'));
check('project_payments.deposit_applied_base ada', colExists($db, 'project_payments', 'deposit_applied_base'));
check('grn_hdr.approval_status ada', colExists($db, 'grn_hdr', 'approval_status'));
check('grn_hdr.approved_at ada', colExists($db, 'grn_hdr', 'approved_at'));
check('grn_hdr.approved_note ada', colExists($db, 'grn_hdr', 'approved_note'));
check('budget_items.material_source ada (G2)', colExists($db, 'budget_items', 'material_source'));
check('budget_items.updated_at ada (G1 F-5.3)', colExists($db, 'budget_items', 'updated_at'));
check('year_closing: FK fk_year_closing_journal', conExists($db, 'fk_year_closing_journal'));
check('year_closing: unique fiscal_year', conExists($db, 'uq_year_closing_fiscal_year'));
check('supplier_payment_items: FK fk_spi_invoice', conExists($db, 'fk_spi_invoice'));
check('subcon_payment_certificates: chk net_payable', conExists($db, 'chk_subcon_cert_net_payable'));
check('view public.v_project_actual_cost ada', (int) scalar($db, "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='public' AND table_name='v_project_actual_cost'") === 1);
check('enum reference_type: material_leftover', (int) scalar($db, "SELECT COUNT(*) FROM pg_enum e JOIN pg_type t ON t.oid=e.enumtypid WHERE t.typname='reference_type' AND e.enumlabel='material_leftover'") === 1);
check('enum reference_type: subcon_invoice', (int) scalar($db, "SELECT COUNT(*) FROM pg_enum e JOIN pg_type t ON t.oid=e.enumtypid WHERE t.typname='reference_type' AND e.enumlabel='subcon_invoice'") === 1);

echo "--- Fase 3a: trigger guard #1 ---\n";
foreach ([
    'trg_check_project_payment_within_invoice',
    'trg_check_project_payment_items_within_header',
    'trg_check_supplier_payment_within_invoice',
    'trg_check_task_material_category',
    'trg_check_spk_task_material_alloc',
    'trg_check_subcon_cert_item_within_passed',
    'trg_block_journal_for_closed_period',
    'trg_block_journallines_for_closed_period',
    'trg_budget_items_touch',
] as $t) { check("trigger {$t} terpasang", trigExists($db, $t)); }

echo "--- Fase 3a: menu & posting awal ---\n";
check('menu 156 = TAGIHAN KLIEN', (string) scalar($db, "SELECT title FROM m_form_name WHERE id=156") === 'TAGIHAN KLIEN');
check('ada minimal 1 periode akuntansi untuk hari ini & open',
      (int) scalar($db, "SELECT COUNT(*) FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date AND is_closed=false") >= 1);

echo "--- Fase 3b (#5): rule PRJ_DEP_APPLY ---\n";
$row = $db->prepare("SELECT f.rule_code, d.account_code dr, c.account_code cr, f.is_active
                       FROM fiscal_config f
                       JOIN chart_of_accounts d ON d.id=f.debit_account_id
                       JOIN chart_of_accounts c ON c.id=f.credit_account_id
                      WHERE f.rule_code='PRJ_DEP_APPLY'");
$row->execute(); $dep = $row->fetch(PDO::FETCH_ASSOC);
check('PRJ_DEP_APPLY Dr 2200 / Cr 1200 & aktif',
      $dep && $dep['dr'] === '2200' && $dep['cr'] === '1200' && ($dep['is_active'] === true || $dep['is_active'] === 't' || $dep['is_active'] === 1),
      json_encode($dep));

echo "--- Fase 3b (#5): uji fungsional AutoPostJournal (transaksi + ROLLBACK) ---\n";
try {
    $db->beginTransaction();
    $today = date('Y-m-d');
    $amount = 1234.56;
    $jr = AutoPostJournal($db, [
        'rule_code'      => 'PRJ_DEP_APPLY',
        'reference_type' => 'project_payment',
        'reference_id'   => 999999001,
        'journal_date'   => $today,
        'amount'         => $amount,
        'description'    => 'REAUDIT-F3 deposit client apply (uji, rollback)',
    ]);
    check('AutoPostJournal PRJ_DEP_APPLY sukses', !empty($jr['success']), $jr['message'] ?? '');
    if (!empty($jr['success'])) {
        $jid = (int) $jr['journal_id'];
        $debitAcc = (int) scalar($db, "SELECT account_id FROM journal_lines WHERE journal_id=? AND debit>0", [$jid]);
        $creditAcc = (int) scalar($db, "SELECT account_id FROM journal_lines WHERE journal_id=? AND credit>0", [$jid]);
        $drCode = (string) scalar($db, "SELECT account_code FROM chart_of_accounts WHERE id=?", [$debitAcc]);
        $crCode = (string) scalar($db, "SELECT account_code FROM chart_of_accounts WHERE id=?", [$creditAcc]);
        $sumDr = (float) scalar($db, "SELECT COALESCE(SUM(debit),0) FROM journal_lines WHERE journal_id=?", [$jid]);
        $sumCr = (float) scalar($db, "SELECT COALESCE(SUM(credit),0) FROM journal_lines WHERE journal_id=?", [$jid]);
        check('Jurnal Dr 2200 (Uang Muka Penjualan)', $drCode === '2200', "got {$drCode}");
        check('Jurnal Cr 1200 (Piutang Usaha)', $crCode === '1200', "got {$crCode}");
        check('Journal balance Dr==Cr==amount', abs($sumDr - $amount) < 0.005 && abs($sumCr - $amount) < 0.005, "Dr={$sumDr} Cr={$sumCr}");
        check('Debit != kas (bukan akun kas bank)', $drCode !== '1100');
    }
    $db->rollBack();
    echo "(transaksi uji di-ROLLBACK — tidak ada jurnal baru tertinggal)\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('uji fungsional PRJ_DEP_APPLY', false, $e->getMessage());
}

echo "--- Fase 3b (#1): uji trigger guard (transaksi + ROLLBACK) ---\n";
try {
    $db->beginTransaction();
    // cari invoice released dengan saldo, else skip
    $invId = scalar($db, "SELECT id FROM project_invoices WHERE COALESCE(release_status,'draft')='released' ORDER BY id LIMIT 1");
    if (!$invId) { echo "SKIP  uji trigger project_payment (tidak ada invoice released di DB uji)\n"; $db->rollBack(); }
    else {
        // alokasi sengaja di atas saldo: amount_paid sangat besar
        $bal = (float) scalar($db, "SELECT COALESCE(amount,0)-COALESCE(paid_amount,0)-COALESCE(retention_amount,0) FROM project_invoices WHERE id=?", [$invId]);
        $over = $bal + 1000.00;
        // perlu payment buatan; gunakan payment existing bila ada, else skip
        $payId = scalar($db, "SELECT id FROM project_payments ORDER BY id LIMIT 1");
        if (!$payId) { echo "SKIP  uji trigger project_payment (tidak ada project_payments utk FK)\n"; $db->rollBack(); }
        else {
            $threw = false;
            try {
                $st = $db->prepare("INSERT INTO project_payment_items (payment_id, invoice_id, amount_paid) VALUES (?, ?, ?)");
                $st->execute([$payId, $invId, $over]);
            } catch (Throwable $e) { $threw = true; }
            check('trigger guard menolak alokasi > saldo invoice', $threw, "over={$over}");
            $db->rollBack();
            echo "(transaksi uji trigger di-ROLLBACK)\n";
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('uji trigger project_payment_items', false, $e->getMessage());
}

echo "--- Fase 3: saldo jurnal historis tetap seimbang ---\n";
$imbalance = (int) scalar($db, "SELECT COUNT(*) FROM (SELECT journal_id FROM journal_lines GROUP BY journal_id HAVING ABS(SUM(debit)-SUM(credit)) >= 0.01) x");
check('semua jurnal historis Debit==Kredit', $imbalance === 0, "imbalance={$imbalance}");
$orphan = (int) scalar($db, "SELECT COUNT(*) FROM journal_entries je WHERE NOT EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.journal_id=je.id)");
check('tidak ada journal_entries tanpa baris journal_lines', $orphan === 0, "orphan={$orphan}");

echo "=== HASIL: PASS={$pass} FAIL={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
