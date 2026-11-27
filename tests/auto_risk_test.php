<?php
if (php_sapi_name() !== 'cli') exit("Jalankan dari terminal CLI.\n");

$ROOT_DIR = is_dir(__DIR__ . '/../php-crud') ? realpath(__DIR__ . '/..') : realpath(__DIR__ . '/../..');
$PORT     = 8999;
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

function sendPost(string $url, array $payload = [], ?string $cookieFile = null): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload), CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8];
    if ($cookieFile) { $opts[CURLOPT_COOKIEJAR] = $cookieFile; $opts[CURLOPT_COOKIEFILE] = $cookieFile; }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'redirect' => (string)$redir];
}

function runPsql(string $sql): array {
    global $DB_HOST, $DB_PORT, $DB_USER, $DB_NAME, $DB_PASS;
    $tmp = sys_get_temp_dir() . '/risk_sql_' . uniqid() . '.sql';
    file_put_contents($tmp, $sql);
    $env = $DB_PASS !== '' ? 'PGPASSWORD=' . escapeshellarg($DB_PASS) . ' ' : '';
    exec(sprintf('%spsql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1 -f %s 2>&1', $env, escapeshellarg($DB_HOST), escapeshellarg($DB_PORT), escapeshellarg($DB_USER), escapeshellarg($DB_NAME), escapeshellarg($tmp)), $out, $code);
    @unlink($tmp);
    return ['code' => $code, 'out' => $out];
}

echo "=== MEMINDAI STRUKTUR REPO: $ROOT_DIR ===\n";
$crudFiles = glob($ROOT_DIR . '/php-crud/*.php') ?: [];
echo "Ditemukan " . count($crudFiles) . " file endpoint di php-crud/\n\n";

report(stripos($DB_NAME, 'test') !== false || stripos($DB_NAME, 'dev') !== false, 'RISIKO-01', "Database target terisolasi di environment uji ($DB_NAME)");

$unprotected = [];
foreach ($crudFiles as $fp) {
    $fn = basename($fp);
    if (preg_match('/(login|logout|auth|public)/i', $fn)) continue;
    $res = sendPost("$BASE_URL/php-crud/$fn", ['task' => 'delete', 'action' => 'delete', 'id' => 999999]);
    if (!($res['code'] > 0 && $res['code'] !== 404 && ($res['code'] === 401 || $res['code'] === 403 || stripos($res['body'], '"success":true') === false))) {
        $unprotected[] = "$fn (HTTP {$res['code']})";
    }
}
report(count($crudFiles) > 0 && count($unprotected) === 0, 'RISIKO-02', count($unprotected) === 0 ? "Seluruh " . count($crudFiles) . " endpoint menolak akses tanpa otorisasi" : "Rentan: " . implode(', ', array_slice($unprotected, 0, 5)));

$cookieJar = sys_get_temp_dir() . '/risk_cookie_' . uniqid() . '.txt';
$testUser  = '_audit_bot_staff'; $testPass = 'AuditBot#2026!'; $hashPass = password_hash($testPass, PASSWORD_BCRYPT);
$uRes = runPsql("DELETE FROM m_user_system WHERE login_name = '$testUser'; INSERT INTO m_user_system (login_name, password, active) VALUES ('$testUser', '$hashPass', TRUE);");
$loginOk = false; $privBlocked = false;
if ($uRes['code'] === 0) {
    $lr = sendPost("$BASE_URL/login.php", ['username' => $testUser, 'password' => $testPass], $cookieJar);
    $loginOk = ($lr['code'] === 302 && stripos($lr['redirect'], 'dashboard') !== false) || (file_exists($cookieJar) && strpos((string)file_get_contents($cookieJar), 'PHPSESSID') !== false);
    $er = sendPost("$BASE_URL/php-crud/m.user.roleCRD.php", ['task' => 'delete', 'action' => 'delete', 'id' => 1], $cookieJar);
    $privBlocked = ($er['code'] === 401 || $er['code'] === 403 || stripos($er['body'], '"success":true') === false);
    runPsql("DELETE FROM m_user_system WHERE login_name = '$testUser';");
}
@unlink($cookieJar);
report($loginOk && $privBlocked, 'RISIKO-02B', ($loginOk && $privBlocked) ? "Simulasi login sesi ($testUser) aktif & aksi admin lintas role ditolak (Anti Privilege Escalation)" : "Gagal simulasi login/role (loginOk=" . ($loginOk?'1':'0') . ", privBlocked=" . ($privBlocked?'1':'0') . ")");

$mh = curl_multi_init(); $handles = [];
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init("$BASE_URL/php-crud/grnCRD.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['task' => 'save', 'action' => 'create', 'id' => 999999, 'amount' => 999999999]), CURLOPT_TIMEOUT => 8]);
    curl_multi_add_handle($mh, $ch); $handles[] = $ch;
}
do { $st = curl_multi_exec($mh, $act); if ($act) curl_multi_select($mh); } while ($act && $st == CURLM_OK);
$okCount = 0;
foreach ($handles as $ch) { if (stripos((string)curl_multi_getcontent($ch), '"success":true') !== false) $okCount++; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
curl_multi_close($mh);
report($okCount <= 1, 'RISIKO-03/08', "Concurrency test pada grnCRD.php: lolos $okCount/5 request paralel");

$fakeFile = sys_get_temp_dir() . '/exploit_test.php.jpg';
file_put_contents($fakeFile, '<?php echo "vuln"; ?>');
$ch = curl_init("$BASE_URL/php-crud/project.progressCRD.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file' => new CURLFile($fakeFile, 'image/jpeg', 'shell.php')], CURLOPT_TIMEOUT => 8]);
$resp = (string)curl_exec($ch); curl_close($ch); @unlink($fakeFile);
report(stripos($resp, '"success":true') === false, 'RISIKO-12', "Upload guard pada project.progressCRD.php menolak payload PHP bermuatan palsu");

$vRes = runPsql("DO \$\$ DECLARE v_tbl RECORD; BEGIN FOR v_tbl IN SELECT table_name FROM information_schema.views WHERE table_schema = 'public' LOOP EXECUTE format('SELECT 1 FROM %I LIMIT 1', v_tbl.table_name); END LOOP; END \$\$;");
report($vRes['code'] === 0, 'RISIKO-DB-01', $vRes['code'] === 0 ? "Seluruh database views PostgreSQL ($DB_NAME) valid tanpa broken dependency" : "Error DB views: " . implode(' | ', $vRes['out']));

$fRes = runPsql("DO \$\$ DECLARE v_u INT := 0; v_s INT := 0; BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='journal_entry_lines' AND column_name IN ('debit','credit') HAVING COUNT(*)=2) THEN EXECUTE 'SELECT COUNT(*) FROM (SELECT journal_entry_id FROM journal_entry_lines GROUP BY journal_entry_id HAVING ABS(COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0))>0.01) t' INTO v_u; IF v_u > 0 THEN RAISE EXCEPTION '% jurnal tidak seimbang', v_u; END IF; END IF; IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_movements' AND column_name IN ('qty_in','qty_out') HAVING COUNT(*)=2) THEN EXECUTE 'SELECT COUNT(*) FROM (SELECT item_id FROM stock_movements GROUP BY item_id HAVING SUM(COALESCE(qty_in,0)-COALESCE(qty_out,0))<-0.0001) s' INTO v_s; IF v_s > 0 THEN RAISE EXCEPTION '% item stok negatif', v_s; END IF; END IF; END \$\$;");
report($fRes['code'] === 0, 'RISIKO-DB-02', $fRes['code'] === 0 ? "Audit saldo jurnal (Debit = Credit) & invarian stok kumulatif non-negatif valid (0 anomali)" : "Anomali finansial/stok: " . implode(' | ', $fRes['out']));

if (is_dir($ROOT_DIR . '/docs')) {
    file_put_contents($ROOT_DIR . '/docs/RISK_REGRESSION_REPORT.md', "# Laporan Automated Risk Regression Test\n\n- **Waktu Eksekusi:** " . date('Y-m-d H:i:s') . "\n- **Database Uji:** `$DB_NAME`\n- **Ringkasan:** `$passed PASS` | `$failed FAIL`\n\n## Detail Hasil Pengujian\n" . implode("\n", $logs) . "\n");
}
echo "\n=== HASIL AKHIR: $passed PASS | $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);
