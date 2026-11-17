<?php
/**
 * tests/run_grid_wrap_static.php
 *
 * Uji statis pola "scoped grid text-wrap" (gelombang 3, 2026-11-17):
 *   - Setiap file JS yang menyuntikkan override CSS grid wrap WAJIB:
 *     (a) tidak memakai kurung kurawal ganda "{{"/"}}" (sisa gaya f-string Python -> CSS tidak valid),
 *     (b) brace "{}" seimbang di dalam string CSS yang di-inject,
 *     (c) syntax JS valid (node --check bila tersedia),
 *     (d) viewConfig grid memakai forceFit:false bila ada grid wrap.
 *   - Dashboard: keempat tabel ber-id wajib punya aturan wrap scoped + pengecualian .text-right nowrap.
 *
 * Tidak butuh DB / server HTTP (murni statis). Jalankan: php tests/run_grid_wrap_static.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($id, $cond, $msg) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $id: $msg\n"; }
    else       { $fail++; echo "FAIL $id: $msg\n"; }
}

/* ---------- TC-GW-01: tidak ada kurawal ganda di DALAM literal string (CSS) file JS ---------- */
$jsFiles = [
    'js/bin.location.js', 'js/stock.opname.js', 'js/stock.card.js',
    'js/purchase.order.js', 'js/warehouse.issuing.js', 'js/whrcv.js',
    'js/po.js', 'js/project.payment.js', 'js/project.invoice.js',
];
foreach ($jsFiles as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) { ok('TC-GW-01', false, "$f tidak ditemukan"); continue; }
    $src = file_get_contents($p);
    // hanya periksa isi literal string single-quote (tempat CSS ditulis), bukan kode JS seperti {params:{...}}
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
    $bad = false;
    foreach ($m[1] as $lit) { if (strpos($lit, '{{') !== false || strpos($lit, '}}') !== false) { $bad = true; break; } }
    ok('TC-GW-01', !$bad, "$f: literal string bebas kurawal ganda {{ }} (bug f-string)");
}

/* ---------- TC-GW-02: brace seimbang pada CSS yang di-inject (ekstrak string IIFE injectGridWrapCss) ---------- */
foreach ($jsFiles as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) continue;
    $src = file_get_contents($p);
    if (strpos($src, 'injectGridWrapCss') === false) continue;
    // ambil semua literal string single-quote di dalam blok IIFE
    $start = strpos($src, '(function injectGridWrapCss()');
    $end   = strpos($src, '})();', $start);
    $block = ($start !== false && $end !== false) ? substr($src, $start, $end - $start) : '';
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $block, $m);
    $css = implode('', $m[1]);
    $bal = 0; $min = 0;
    for ($i = 0, $n = strlen($css); $i < $n; $i++) {
        if ($css[$i] === '{') $bal++;
        if ($css[$i] === '}') { $bal--; if ($bal < 0) $min = $bal; }
    }
    ok('TC-GW-02', $bal === 0 && $min === 0 && strlen($css) > 200, "$f: CSS inject brace-seimbang (net=$bal, len=" . strlen($css) . ")");
}

/* ---------- TC-GW-03: forceFit:false pada grid yang dapat wrap ---------- */
$ffChecks = [
    'js/bin.location.js' => 'viewConfig: { forceFit: false',
    'js/stock.opname.js' => 'forceFit: false',
    'js/stock.card.js'   => 'forceFit: false',
    'js/warehouse.issuing.js' => 'forceFit: false',
    'js/whrcv.js'        => 'forceFit: false',
    'js/purchase.order.js' => 'forceFit: false',
    'js/po.js'           => 'forceFit: false',
    'js/project.payment.js' => 'forceFit: false',
    'js/project.invoice.js' => 'forceFit: false',
];
foreach ($ffChecks as $f => $needle) {
    $p = $root . '/' . $f;
    if (!is_file($p)) { ok('TC-GW-03', false, "$f tidak ditemukan"); continue; }
    $src = file_get_contents($p);
    ok('TC-GW-03', strpos($src, $needle) !== false, "$f memakai $needle");
    // tidak boleh ada sisa forceFit:true
    ok('TC-GW-03', strpos($src, 'forceFit: true') === false, "$f tidak lagi memakai forceFit:true");
}

/* ---------- TC-GW-04: dashboard wrap scoped + angka tetap nowrap ---------- */
$d = file_get_contents($root . '/views/dashboard.php');
foreach (['profit_table', 'aging_table', 'tax_rate_table', 'material_table'] as $tid) {
    ok('TC-GW-04', strpos($d, 'id="' . $tid . '"') !== false, "dashboard punya tabel #$tid");
    ok('TC-GW-04', strpos($d, '#' . $tid . ' th, #' . $tid . ' td') !== false, "dashboard CSS scoped #$tid th/td wrap");
}
ok('TC-GW-04', preg_match('/#profit_table th\.text-right.*nowrap/s', $d) === 1, 'dashboard kolom .text-right tetap nowrap');

/* ---------- TC-GW-05: node --check bila tersedia ---------- */
$nodeOk = false;
exec('command -v node', $o, $rc);
if ($rc === 0 && !empty($o)) { $nodeOk = true; }
foreach ($jsFiles as $f) {
    $p = $root . '/' . $f;
    if (!is_file($p)) continue;
    if (!$nodeOk) { ok('TC-GW-05', true, "$f: node tidak tersedia, skip syntax check"); continue; }
    exec('node --check ' . escapeshellarg($p) . ' 2>&1', $out, $rc2);
    ok('TC-GW-05', $rc2 === 0, "$f: node --check bersih" . ($rc2 === 0 ? '' : ' -> ' . implode(' ', $out)));
    $out = [];
}

echo "\nHasil: PASS=$pass FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
