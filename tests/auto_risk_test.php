<?php
if (php_sapi_name() !== 'cli') {
    exit("Jalankan dari terminal CLI.\n");
}

$ROOT_DIR = is_dir(__DIR__ . '/../php-crud') ? realpath(__DIR__ . '/..') : realpath(__DIR__ . '/../..');
$PORT     = 8999;
$BASE_URL = "http://127.0.0.1:$PORT";

// Otomatis jalankan PHP built-in server sementara selama skrip berjalan
$srvCmd = sprintf('php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!', $PORT, escapeshellarg($ROOT_DIR));
$srvPid = (int)trim((string)shell_exec($srvCmd));
register_shutdown_function(function () use ($srvPid) {
    if ($srvPid > 0) {
        exec("kill $srvPid 2>/dev/null");
    }
});
usleep(400000); // Tunggu 0.4 detik hingga server siap

$DB_HOST = '127.0.0.1';
$DB_PORT = '5432';
$DB_NAME = 'proyekGMN_test';
$DB_USER = 'postgres';
$DB_PASS = '';

if (file_exists($ROOT_DIR . '/config.php')) {
    $cfgContent = file_get_contents($ROOT_DIR . '/config.php');
    if (preg_match('/(?:dbname|DB_NAME|db_name)[\'"\s=>]+([a-zA-Z0-9_-]+)/i', $cfgContent, $m)) $DB_NAME = $m;
    if (preg_match('/(?:user|DB_USER|db_user|username)[\'"\s=>]+([a-zA-Z0-9_-]+)/i', $cfgContent, $m)) $DB_USER = $m;
    if (preg_match('/(?:pass|DB_PASS|db_pass|password)[\'"\s=>]+([^\'";\s]+)/i', $cfgContent, $m)) $DB_PASS = $m;
}

$passed = 0;
$failed = 0;
$logs   = [];

function report(bool $ok, string $kode, string $pesan): void {
    global $passed, $failed, $logs;
    if ($ok) {
        echo "\033[32m[PASS]\033[0m [$kode] $pesan\n";
        $logs[] = "- **[PASS]** `[$kode]` $pesan";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m [$kode] $pesan\n";
        $logs[] = "- **[FAIL]** `[$kode]` $pesan";
        $failed++;
    }
}

function sendPost(string $url, array $payload = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 8,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

echo "=== MEMINDAI STRUKTUR REPO: $ROOT_DIR ===\n";

$crudFiles = glob($ROOT_DIR . '/php-crud/*.php') ?: [];
echo "Ditemukan " . count($crudFiles) . " file endpoint di php-crud/\n\n";

// [RISIKO 1] Fail-Closed DB Identity
$safeEnv = (stripos($DB_NAME, 'test') !== false || stripos($DB_NAME, 'dev') !== false);
report($safeEnv, 'RISIKO-01', "Database target terisolasi di environment uji ($DB_NAME)");

// [RISIKO 2] Hak Akses Lintas Modul
$unprotected = [];
foreach ($crudFiles as $filepath) {
    $filename = basename($filepath);
    if (preg_match('/(login|logout|auth|public)/i', $filename)) {
        continue;
    }
    $res = sendPost("$BASE_URL/php-crud/$filename", ['task' => 'delete', 'action' => 'delete', 'id' => 999999]);
    $isRejected = ($res['code'] > 0 && $res['code'] !== 404) &&
                  ($res['code'] === 401 || $res['code'] === 403 || stripos($res['body'], '"success":true') === false);
    if (!$isRejected) {
        $unprotected[] = "$filename (HTTP {$res['code']})";
    }
}
report(count($crudFiles) > 0 && count($unprotected) === 0, 'RISIKO-02',
    count($unprotected) === 0
        ? "Seluruh " . count($crudFiles) . " endpoint menolak akses tanpa otorisasi"
        : "Endpoint bermasalah/rentan: " . implode(', ', array_slice($unprotected, 0, 5)));

// [RISIKO 3 & 8] Concurrency Test
$targetConcurrency = null;
foreach ($crudFiles as $filepath) {
    $name = basename($filepath);
    if (preg_match('/(payment|grn|receive|stock|issue|whrcv)/i', $name)) {
        $targetConcurrency = $name;
        break;
    }
}

if ($targetConcurrency) {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 5; $i++) {
        $ch = curl_init("$BASE_URL/php-crud/$targetConcurrency");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['task' => 'save', 'action' => 'create', 'id' => 999999, 'amount' => 999999999]),
            CURLOPT_TIMEOUT        => 8,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status == CURLM_OK);

    $okCount = 0;
    foreach ($handles as $ch) {
        $out = (string)curl_multi_getcontent($ch);
        if (stripos($out, '"success":true') !== false) $okCount++;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    report($okCount <= 1, 'RISIKO-03/08', "Concurrency test pada $targetConcurrency: lolos $okCount/5 request paralel");
}

// [RISIKO 12] Validasi Keamanan Upload
$uploadEndpoint = null;
foreach ($crudFiles as $filepath) {
    $name = basename($filepath);
    if (preg_match('/(upload|evidence|attachment|file|progress)/i', $name)) {
        $uploadEndpoint = $name;
        break;
    }
}

if ($uploadEndpoint) {
    $fakeFile = sys_get_temp_dir() . '/exploit_test.php.jpg';
    file_put_contents($fakeFile, '<?php echo "vuln"; ?>');
    $ch = curl_init("$BASE_URL/php-crud/$uploadEndpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($fakeFile, 'image/jpeg', 'shell.php')],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = (string)curl_exec($ch);
    curl_close($ch);
    @unlink($fakeFile);
    report(stripos($resp, '"success":true') === false, 'RISIKO-12', "Upload guard pada $uploadEndpoint menolak payload PHP bermuatan palsu");
}

// [RISIKO DB] Audit PostgreSQL via TCP (-h 127.0.0.1)
$sqlCheck = <<<SQL
DO \$\$
DECLARE
    v_tbl RECORD;
BEGIN
    IF current_database() NOT ILIKE '%test%' AND current_database() NOT ILIKE '%dev%' THEN
        RAISE EXCEPTION 'DB bukan environment test/dev: %', current_database();
    END IF;

    FOR v_tbl IN SELECT table_name FROM information_schema.views WHERE table_schema = 'public' LOOP
        EXECUTE format('SELECT 1 FROM %I LIMIT 1', v_tbl.table_name);
    END LOOP;
END \$\$;
SQL;

$tmpSql = sys_get_temp_dir() . '/audit_invariant.sql';
file_put_contents($tmpSql, $sqlCheck);

$envPass = $DB_PASS !== '' ? 'PGPASSWORD=' . escapeshellarg($DB_PASS) . ' ' : '';
$cmd = sprintf(
    '%spsql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1 -f %s 2>&1',
    $envPass,
    escapeshellarg($DB_HOST),
    escapeshellarg($DB_PORT),
    escapeshellarg($DB_USER),
    escapeshellarg($DB_NAME),
    escapeshellarg($tmpSql)
);
exec($cmd, $dbOut, $dbCode);
@unlink($tmpSql);

report($dbCode === 0, 'RISIKO-DB', $dbCode === 0
    ? "Seluruh database views & koneksi PostgreSQL ($DB_NAME) valid tanpa broken dependency"
    : "Error pada audit DB: " . implode(' | ', $dbOut));

// Simpan laporan otomatis ke docs/RISK_REGRESSION_REPORT.md
if (is_dir($ROOT_DIR . '/docs')) {
    $reportMd = "# Laporan Automated Risk Regression Test\n\n"
              . "- **Waktu Eksekusi:** " . date('Y-m-d H:i:s') . "\n"
              . "- **Database Uji:** `$DB_NAME`\n"
              . "- **Ringkasan:** `$passed PASS` | `$failed FAIL`\n\n"
              . "## Detail Hasil Pengujian\n" . implode("\n", $logs) . "\n";
    file_put_contents($ROOT_DIR . '/docs/RISK_REGRESSION_REPORT.md', $reportMd);
}

echo "\n=== HASIL AKHIR: $passed PASS | $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);
