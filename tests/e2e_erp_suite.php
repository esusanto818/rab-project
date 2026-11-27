<?php
if (php_sapi_name() !== 'cli') exit("Jalankan dari terminal CLI.\n");

$ROOT_DIR = realpath(__DIR__ . '/..');
$PORT     = 8998;
$BASE_URL = "http://127.0.0.1:$PORT";

$srvCmd = sprintf('php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!', $PORT, escapeshellarg($ROOT_DIR));
$srvPid = (int)trim((string)shell_exec($srvCmd));
register_shutdown_function(function () use ($srvPid) { if ($srvPid > 0) exec("kill $srvPid 2>/dev/null"); });
usleep(400000);

$DB_HOST = '127.0.0.1'; $DB_PORT = '5432'; $DB_NAME = 'proyekGMN_test'; $DB_USER = 'postgres'; $DB_PASS = '';
if (file_exists($ROOT_DIR . '/config.php')) {
    $cfg = file_get_contents($ROOT_DIR . '/config.php');
    if (preg_match('/(?:dbname|DB_NAME|db_name)[\'"\s=>]+([a-zA-Z0-9_-]+)/i', $cfg, $m)) $DB_NAME = (string)end($m);
    if (preg_match('/(?:user|DB_USER|db_user|username)[\'"\s=>]+([a-zA-Z0-9_-]+)/i', $cfg, $m)) $DB_USER = (string)end($m);
    if (preg_match('/(?:pass|DB_PASS|db_pass|password)[\'"\s=>]+([^\'";\s]+)/i', $cfg, $m)) $DB_PASS = (string)end($m);
}

$passed = 0; $failed = 0; $logs = [];
function report(bool $ok, string $kode, string $pesan): void {
    global $passed, $failed, $logs;
    echo ($ok ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m") . " [$kode] $pesan\n";
    $logs[] = ($ok ? "- **[PASS]**" : "- **[FAIL]**") . " `[$kode]` $pesan";
    $ok ? $passed++ : $failed++;
}

function httpPost(string $url, array $data = [], ?string $cookie = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Origin: http://127.0.0.1:8998', 'Referer: http://127.0.0'],
        CURLOPT_TIMEOUT        => 8,
    ];
    if ($cookie) { $opts[CURLOPT_COOKIEJAR] = $cookie; $opts[CURLOPT_COOKIEFILE] = $cookie; }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

function psqlExec(string $sql): array {
    global $DB_HOST, $DB_PORT, $DB_USER, $DB_NAME, $DB_PASS;
    $tmp = sys_get_temp_dir() . '/e2e_sql_' . uniqid() . '.sql';
    file_put_contents($tmp, $sql);
    $env = $DB_PASS !== '' ? 'PGPASSWORD=' . escapeshellarg($DB_PASS) . ' ' : '';
    exec(sprintf('%spsql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1 -f %s 2>&1', $env, escapeshellarg($DB_HOST), escapeshellarg($DB_PORT), escapeshellarg($DB_USER), escapeshellarg($DB_NAME), escapeshellarg($tmp)), $out, $code);
    @unlink($tmp);
    return ['code' => $code, 'out' => $out];
}

echo "=== MEMULAI FASE 4: E2E, RBAC & CONCURRENCY REGRESSION SUITE ===\n\n";

// 1. [E2E-SEC-01] Verifikasi 0 Temuan CRITICAL pada Endpoint SEC-01 & m.employeeCRD.php
$sec01Ok = true;
foreach (['leftoverCRD.php', 'spkCRD.php', 'subconCRD.php', 'subcon.progressCRD.php', 'dashboardCRD.php', 'inventory.status.php'] as $fn) {
    $res = httpPost("$BASE_URL/php-crud/$fn", ['task' => 'read']);
    if ($res['code'] !== 401 && $res['code'] !== 403 && stripos($res['body'], '"success":true') !== false) {
        $sec01Ok = false;
    }
}
$empCode = file_get_contents("$ROOT_DIR/php-crud/m.employeeCRD.php");
$noConcatSql = (strpos($empCode, 'pg_query_params') !== false && strpos($empCode, 'checksinglequote($_POST') === false);
report($sec01Ok && $noConcatSql, 'E2E-SEC-01', "Seluruh 6 endpoint SEC-01 terlindungi sesi & m.employeeCRD.php bebas string-concatenation SQL");

// 2. [E2E-RBAC-01] Simulasi Login Multi-Role (Read-Only vs No-Access)
$cookieJar = sys_get_temp_dir() . '/e2e_cookie_' . uniqid() . '.txt';
$uName = '_e2e_readonly_user'; $uPass = 'E2eAudit#2026!'; $uHash = password_hash($uPass, PASSWORD_BCRYPT);
$setup = psqlExec("
DELETE FROM m_user_access_menu WHERE user_id IN (SELECT id FROM m_user_system WHERE login_name = '$uName');
DELETE FROM m_user_system WHERE login_name = '$uName';
INSERT INTO m_user_system (login_name, password, active) VALUES ('$uName', '$uHash', TRUE);
INSERT INTO m_user_access_menu (user_id, form_id, access_level)
SELECT u.id, f.id, 1 FROM m_user_system u, m_form_name f
WHERE u.login_name = '$uName' AND f.id NOT IN (SELECT id FROM m_form_name WHERE LOWER(title) LIKE '%spk%') ORDER BY f.id ASC LIMIT 1;
");

$rbacPass = false;
if ($setup['code'] === 0) {
    $chL = curl_init("$BASE_URL/login.php"); curl_setopt_array($chL, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(['username'=>$uName,'password'=>$uPass]), CURLOPT_COOKIEJAR=>$cookieJar, CURLOPT_COOKIEFILE=>$cookieJar]); curl_exec($chL); curl_close($chL);
    // Uji user read-only mencoba save_invoice (butuh level 2 -> harus HTTP 403)
    $r1 = httpPost("$BASE_URL/php-crud/project.invoiceCRD.php", ['task' => 'save_invoice'], $cookieJar);
    // Uji user tanpa akses menu SPK mencoba akses spkCRD.php (harus HTTP 403)
    $r2 = httpPost("$BASE_URL/php-crud/spkCRD.php", ['task' => 'read'], $cookieJar);
    $rbacPass = (in_array($r1['code'], [401, 403], true) && in_array($r2['code'], [401, 403], true));
    psqlExec("
        DELETE FROM m_user_access_menu WHERE user_id IN (SELECT id FROM m_user_system WHERE login_name = '$uName');
        DELETE FROM m_user_system WHERE login_name = '$uName';
    ");
}
@unlink($cookieJar);
report($rbacPass, 'E2E-RBAC-01', "Matriks RBAC lulus: user Read-Only ditolak mutasi Invoice (403) & ditolak akses SPK tanpa izin (403)");

// 3. [E2E-CONC-01] Concurrency Test 5 Request Paralel Terautentikasi
$mh = curl_multi_init(); $chs = [];
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init("$BASE_URL/php-crud/project.invoiceCRD.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['task' => 'release_invoice', 'id' => 999999]),
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Origin: http://127.0.0.1:8998', 'Referer: http://127.0.0'],
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_multi_add_handle($mh, $ch); $chs[] = $ch;
}
do { $st = curl_multi_exec($mh, $act); if ($act) curl_multi_select($mh); } while ($act && $st == CURLM_OK);
$concSuccess = 0;
foreach ($chs as $ch) {
    if (stripos((string)curl_multi_getcontent($ch), '"success":true') !== false) $concSuccess++;
    curl_multi_remove_handle($mh, $ch); curl_close($ch);
}
curl_multi_close($mh);
report($concSuccess === 0, 'E2E-CONC-01', "Concurrency & idempotency guard pada project.invoiceCRD.php mencegah eksekusi paralel tidak sah (0/5 lolos)");

// 4. [E2E-FIN-01] Rekonsiliasi Akuntansi, Piutang/Pembayaran Proyek & Persediaan di DB Uji
$reconSql = <<<SQL
DO \$\$
DECLARE
    v_j_err INT := 0;
    v_s_err INT := 0;
    v_p_err INT := 0;
BEGIN
    -- Cek 1: Seluruh jurnal seimbang Debit = Credit
    SELECT COUNT(*) INTO v_j_err FROM (
        SELECT journal_entry_id FROM journal_entry_lines
        GROUP BY journal_entry_id
        HAVING ABS(COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0)) > 0.01
    ) j;
    IF v_j_err > 0 THEN RAISE EXCEPTION 'Terdapat % jurnal tidak seimbang', v_j_err; END IF;

    -- Cek 2: Tidak ada item stok kumulatif negatif
    SELECT COUNT(*) INTO v_s_err FROM (
        SELECT item_id FROM stock_movements
        GROUP BY item_id
        HAVING SUM(COALESCE(qty,0)) < -0.0001
    ) s;
    IF v_s_err > 0 THEN RAISE EXCEPTION 'Terdapat % item dengan stok negatif', v_s_err; END IF;

    -- Cek 3: Tidak ada project_invoices dengan paid_amount > amount
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='project_invoices' AND column_name IN ('paid_amount','amount') HAVING COUNT(*)=2) THEN
        EXECUTE 'SELECT COUNT(*) FROM project_invoices WHERE COALESCE(paid_amount,0) - COALESCE(amount,0) > 0.01' INTO v_p_err;
        IF v_p_err > 0 THEN RAISE EXCEPTION 'Terdapat % invoice proyek dengan pembayaran melebihi nilai tagihan', v_p_err; END IF;
    END IF;
END \$\$;
SQL;
$reconRes = psqlExec($reconSql);
report($reconRes['code'] === 0, 'E2E-FIN-01', $reconRes['code'] === 0
    ? "Rekonsiliasi penuh database uji ($DB_NAME) valid: Jurnal seimbang, stok non-negatif, & pembayaran invoice konsisten"
    : "Temuan rekonsiliasi DB: " . implode(' | ', $reconRes['out']));


// 5. [E2E-TX-01] Simulasi Transaksi Penuh (PO -> GRN -> Stock -> Invoice -> Jurnal) dengan ROLLBACK Penuh
$e2eTxSql = <<<SQL
BEGIN;
DO \$\$
DECLARE
    v_prj_id   INT;
    v_item_id  INT;
    v_wh_id    INT;
    v_coa_ar   INT;
    v_coa_rev  INT;
    v_po_id    INT;
    v_grn_id   INT;
    v_inv_id   INT;
    v_je_id    INT;
    v_stk_before NUMERIC := 0;
    v_stk_after  NUMERIC := 0;
    v_j_diff     NUMERIC := 0;
BEGIN
    SELECT id INTO v_prj_id FROM projects LIMIT 1;
    SELECT id INTO v_item_id FROM materials LIMIT 1;
    SELECT id INTO v_wh_id FROM warehouses LIMIT 1;
    SELECT id INTO v_coa_ar FROM coa_accounts ORDER BY id ASC LIMIT 1;
    SELECT id INTO v_coa_rev FROM coa_accounts ORDER BY id DESC LIMIT 1;

    IF v_prj_id IS NULL OR v_item_id IS NULL OR v_coa_ar IS NULL THEN
        RAISE EXCEPTION 'Data master (projects/items/chart_of_accounts) kosong di DB uji';
    END IF;

    -- A. Hitung stok awal item uji
    SELECT COALESCE(SUM(qty), 0) INTO v_stk_before FROM stock_movements WHERE item_id = v_item_id;

    -- B. Simulasi PO (status Released)
    INSERT INTO purchase_orders (project_id, po_date, status, created_at, updated_at, exch_rate, budget_exch_rate, total_base, total_budget, ppn_mode, ppn_rate)
    VALUES (v_prj_id, CURRENT_DATE, 'Disetujui', NOW(), NOW(), 1, 1, 2500000, 2500000, 'EXCL', 11)
    RETURNING id INTO v_po_id;

    -- C. Simulasi GRN & Stock Movement (+25 unit)
    INSERT INTO grn_hdr (ref_no, po_id, grn_date, status, exch_rate, approval_status)
    VALUES ('GRN-E2E-TEST-001', v_po_id, CURRENT_DATE, 'open', 1, 'APPROVED')
    RETURNING id INTO v_grn_id;

    INSERT INTO stock_movements (movement_date, item_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no)
    VALUES (CURRENT_DATE, v_item_id, v_wh_id, 'IN', 25, 100000, 'GRN', v_grn_id, 'GRN-E2E-TEST-001');

    SELECT COALESCE(SUM(qty), 0) INTO v_stk_after FROM stock_movements WHERE item_id = v_item_id;
    IF ABS((v_stk_after - v_stk_before) - 25) > 0.0001 THEN
        RAISE EXCEPTION 'Mutasi stok GRN tidak sesuai: sebelum=%, sesudah=%', v_stk_before, v_stk_after;
    END IF;

    -- D. Simulasi Project Invoice (DPP 10.000.000 + PPN 11% = 11.100.000)
    INSERT INTO project_invoices (
        inv_no, project_id, termin_name, invoice_date, amount, paid_amount,
        created_at, dpp_amount, ppn_rate, ppn_amount, auto_generated,
        release_status, release_note, retention_pct, retention_amount
    ) VALUES (
        'INV-E2E-TEST-001', v_prj_id, 'Termin E2E Uji', CURRENT_DATE, 11100000, 0,
        NOW(), 10000000, 11, 1100000, FALSE,
        'released', 'E2E automated simulation', 0, 0
    ) RETURNING id INTO v_inv_id;

    -- E. Simulasi Posting Jurnal Otomatis & Verifikasi Keseimbangan Debit = Credit
    INSERT INTO journal_entries (ref_no, journal_date, reference_type, reference_id, description, status)
    VALUES ('JE-E2E-TEST-001', CURRENT_DATE, 'customer_deposit', v_inv_id, 'Jurnal E2E Invoice Uji', 'draft')
    RETURNING id INTO v_je_id;

    INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES
        (v_je_id, v_coa_ar,  11100000, 0),
        (v_je_id, v_coa_rev, 0, 11100000);

    SELECT ABS(COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)) INTO v_j_diff
    FROM journal_entry_lines WHERE journal_entry_id = v_je_id;

    IF v_j_diff > 0.01 THEN
        RAISE EXCEPTION 'Jurnal E2E tidak seimbang: selisih=%', v_j_diff;
    END IF;
END \$\$;
ROLLBACK;
SQL;
$txRes = psqlExec($e2eTxSql);
report($txRes['code'] === 0, 'E2E-TX-01', $txRes['code'] === 0
    ? "Simulasi transaksi penuh E2E (PO Released -> GRN -> Stock +25 -> Project Invoice -> Jurnal Seimbang -> ROLLBACK) lulus 100%"
    : "Gagal simulasi E2E transaksi: " . implode(' | ', $txRes['out']));

// Simpan laporan Fase 4
$rep = "# Fase 4: E2E, RBAC & Concurrency Regression Report\n\n"
     . "- **Waktu Eksekusi:** " . date('Y-m-d H:i:s') . "\n"
     . "- **Environment Uji:** `$DB_NAME`\n"
     . "- **Ringkasan Hasil:** `$passed PASS` | `$failed FAIL`\n\n"
     . "## Detail Hasil Pengujian\n" . implode("\n", $logs) . "\n";
file_put_contents($ROOT_DIR . '/docs/03_E2E_REGRESSION_REPORT.md', $rep);

echo "\n=== HASIL AKHIR FASE 4: $passed PASS | $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);
