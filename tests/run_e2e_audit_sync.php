<?php
declare(strict_types=1);

/**
 * Suite Penyelaras Audit Manual & Otomatis (PHP 7.4 Compatible)
 * Memverifikasi cakupan lintas lapisan, keamanan dasar, penomoran dokumen,
 * dan 6 migrasi aturan bisnis (sql/20261115.*.sql).
 */

$root = dirname(__DIR__);
$dbHost = getenv('RAB_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('RAB_DB_PORT') ?: '5432';
$dbName = getenv('RAB_DB_NAME') ?: 'proyekGMN_test';
$dbUser = getenv('RAB_DB_USER') ?: 'postgres';
$dbPass = getenv('RAB_DB_PASS') ?: '';

$results = [];
function rec(string $id, string $title, bool $ok, string $detail = ''): void {
    global $results;
    $results[] = ['id' => $id, 'title' => $title, 'ok' => $ok, 'detail' => $detail];
    $tag = $ok ? '[PASS]' : '[FAIL]';
    echo "  {$tag} {$id}  {$title}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "=== Audit Sync & Cross-Layer Verification ({$dbName}) ===\n";

// TC-AUD-01: Syntax check lintas lapisan (php-crud, php-func, views, pdf, *.PHP)
$dirs = ['php-crud', 'php-func', 'views', 'pdf'];
$files = glob($root . '/*.PHP') ?: [];
foreach ($dirs as $d) {
    if (is_dir($root . '/' . $d)) {
        foreach (glob($root . '/' . $d . '/*.php') ?: [] as $f) {
            $files[] = $f;
        }
    }
}
$lintErrors = [];
foreach ($files as $f) {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $lintErrors[] = basename($f) . ': ' . implode(' ', $out);
    }
}
rec('TC-AUD-01', 'Lint PHP lintas lapisan (php-crud, php-func, views, pdf, *.PHP)', count($lintErrors) === 0,
    'total_file=' . count($files) . (count($lintErrors) ? ' | err=' . implode('; ', $lintErrors) : ''));

// TC-AUD-02: Deteksi komentar // di dalam string SQL multi-baris
$sqlCommentHits = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (preg_match_all('/"(?:SELECT|INSERT|UPDATE|DELETE|WITH)\b[^"]*?\/\/[^\n"]*"/is', $src, $m)) {
        $sqlCommentHits[] = basename($f) . ' (' . count($m[0]) . 'x)';
    }
}
rec('TC-AUD-02', 'Bebas komentar // di dalam string query SQL', count($sqlCommentHits) === 0,
    count($sqlCommentHits) ? 'temuan=' . implode(', ', $sqlCommentHits) : 'bersih di ' . count($files) . ' file');

// TC-AUD-03: Pastikan tidak ada throw Exception(pg_last_error()) mentah di php-crud
$rawPgLeaks = [];
foreach (glob($root . '/php-crud/*.php') ?: [] as $f) {
    $src = file_get_contents($f);
    if (preg_match('/throw\s+new\s+Exception\s*\(\s*pg_last_error/i', $src)) {
        $rawPgLeaks[] = basename($f);
    }
}
rec('TC-AUD-03', 'Bebas kebocoran pg_last_error() langsung di Exception', count($rawPgLeaks) === 0,
    count($rawPgLeaks) ? 'temuan=' . implode(', ', $rawPgLeaks) : 'semua endpoint aman');

// Koneksi DB uji
try {
    $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Koneksi DB gagal: " . $e->getMessage() . "\n");
    exit(1);
}

// TC-AUD-04: Verifikasi struktur kolom & fungsi dari 6 migrasi sql/20261115.*.sql
$checks = [
    ['TC-AUD-04', 'Migrasi 1: Kolom approval_status pada grn_hdr',
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='grn_hdr' AND column_name IN ('approval_status','approved_by','approved_at')", 3],
    ['TC-AUD-05', 'Migrasi 2: Kolom retensi pada project_invoices',
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='project_invoices' AND column_name IN ('retention_pct','retention_amount')", 2],
    ['TC-AUD-06', 'Migrasi 3: Kolom FX & alokasi pada project_payments',
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='project_payments' AND column_name IN ('currency_id','exch_rate','total_amount_base','allocated_amount_base')", 4],
    ['TC-AUD-07', 'Migrasi 4: Kolom active pada task_materials',
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='task_materials' AND column_name='active'", 1],
    ['TC-AUD-08', 'Migrasi 5: Kolom rework_required pada subcon_inspection_items',
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='subcon_inspection_items' AND column_name='rework_required'", 1],
    ['TC-AUD-09', 'Migrasi 6: Fungsi & trigger year_close terpasang',
        "SELECT COUNT(*) FROM pg_proc WHERE proname ILIKE '%year_close%' OR proname ILIKE '%closed_period%'", 1],
];

foreach ($checks as $c) {
    $cnt = (int)$pdo->query($c[2])->fetchColumn();
    rec($c[0], $c[1], $cnt >= $c[3], "actual={$cnt}, min_harap={$c[3]}");
}

$pass = count(array_filter($results, function ($r) { return $r['ok']; }));
$fail = count($results) - $pass;
echo "\nHasil Audit Sync: PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
