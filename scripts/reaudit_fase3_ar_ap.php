<?php
/**
 * scripts/reaudit_fase3_ar_ap.php
 *
 * Fase 3 #2 + #3 — Re-audit otomatis terhadap proyekGMN_test setelah Kartu & Aging
 * AR/AP dibangun (view v_ar_aging/v_ar_card/v_ap_aging/v_ap_card, backend ar/ap.agingCRD.php,
 * UI js/ar.aging.js + js/ap.aging.js, views/ar.aging.php + views/ap.aging.php, menu 6038/6039).
 *
 * Read-only kecuali setup fixture user/login + uji fungsional yang semuanya dibungkus
 * transaksi + ROLLBACK (tidak meninggalkan data di proyekGMN_test).
 *
 * Cara jalan: php scripts/reaudit_fase3_ar_ap.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

// Paksa target DB uji (jangan pernah ke DB default aplikasi).
putenv('RAB_DB_NAME=proyekGMN_test');
putenv('DB_NAME=proyekGMN_test');

define('PHP_SIDE_TEST', true);
require_once __DIR__ . '/../php-class/crudClass.php';

$root = dirname(__DIR__);
$db = new crud();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dsnDb = $db->query("SELECT current_database()")->fetchColumn();
echo "Connected DB: {$dsnDb}\n";
if ($dsnDb !== 'proyekGMN_test') { fwrite(STDERR, "ABORT: bukan proyekGMN_test\n"); exit(1); }

const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';
const CFG_TAG      = 'E2E-ARAP';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $label\n"; }
    else     { $fail++; echo "FAIL  $label  $detail\n"; }
}
function scalar($db, string $sql, $params = []) {
    $st = $db->prepare($sql); $st->execute($params); return $st->fetchColumn();
}
function row($db, string $sql, $params = []) {
    $st = $db->prepare($sql); $st->execute($params); return $st->fetch(PDO::FETCH_ASSOC);
}
function viewExists($db, string $view): bool {
    return (int) scalar($db,
        "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='public' AND table_name = ?",
        [$view]) === 1;
}
function viewCol($db, string $view, string $col): bool {
    return (int) scalar($db,
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name = ? AND column_name = ?",
        [$view, $col]) === 1;
}

echo "--- A. Struktur & view ---\n";
foreach (['v_ar_aging', 'v_ar_card', 'v_ap_aging', 'v_ap_card'] as $v) {
    check("view {$v} terbit", viewExists($db, $v));
}
foreach (['client_id', 'period_start', 'period_end', 'saldo_awal', 'faktur', 'pembayaran',
          'alokasi', 'sisa', 'outstanding_all', 'bucket_0_30', 'bucket_31_60', 'bucket_61_90', 'bucket_90_plus'] as $c) {
    check("v_ar_card.{$c} ada", viewCol($db, 'v_ar_card', $c));
}
foreach (['supplier_id', 'period_start', 'period_end', 'saldo_awal', 'faktur', 'pembayaran',
          'alokasi', 'sisa', 'outstanding_all', 'bucket_0_30', 'bucket_31_60', 'bucket_61_90', 'bucket_90_plus'] as $c) {
    check("v_ap_card.{$c} ada", viewCol($db, 'v_ap_card', $c));
}
check('v_ar_aging.aging_date ada', viewCol($db, 'v_ar_aging', 'aging_date'));
check('v_ar_aging.age_days ada',   viewCol($db, 'v_ar_aging', 'age_days'));
check('v_ap_aging.aging_date ada', viewCol($db, 'v_ap_aging', 'aging_date'));
check('v_ap_aging.age_days ada',   viewCol($db, 'v_ap_aging', 'age_days'));

echo "--- A2. Konsistensi bucket vs outstanding (view) ---\n";
$arBucket = (float) scalar($db, "SELECT COALESCE(SUM(bucket_0_30 + bucket_31_60 + bucket_61_90 + bucket_90_plus), 0)
                                 FROM public.v_ar_aging WHERE outstanding > 0");
$arOut    = (float) scalar($db, "SELECT COALESCE(SUM(outstanding), 0)
                                 FROM public.v_ar_aging WHERE outstanding > 0");
check('v_ar_aging: SUM(bucket) == SUM(outstanding)', abs($arBucket - $arOut) < 0.01,
      "bucket={$arBucket} outstanding={$arOut}");
$apBucket = (float) scalar($db, "SELECT COALESCE(SUM(bucket_0_30 + bucket_31_60 + bucket_61_90 + bucket_90_plus), 0)
                                 FROM public.v_ap_aging WHERE outstanding > 0");
$apOut    = (float) scalar($db, "SELECT COALESCE(SUM(outstanding), 0)
                                 FROM public.v_ap_aging WHERE outstanding > 0");
check('v_ap_aging: SUM(bucket) == SUM(outstanding)', abs($apBucket - $apOut) < 0.01,
      "bucket={$apBucket} outstanding={$apOut}");

echo "--- A3. Konsistensi kartu (view) ---\n";
$arCardDiff = scalar($db, "SELECT COUNT(*) FROM public.v_ar_card
                           WHERE abs((saldo_awal + faktur - alokasi) - sisa) > 0.01");
check('v_ar_card: saldo_awal + faktur - alokasi == sisa', (int) $arCardDiff === 0);
$apCardDiff = scalar($db, "SELECT COUNT(*) FROM public.v_ap_card
                           WHERE abs((saldo_awal + faktur - alokasi) - sisa) > 0.01");
check('v_ap_card: saldo_awal + faktur - alokasi == sisa', (int) $apCardDiff === 0);
$arOutAll = (float) scalar($db, "SELECT COALESCE(SUM(outstanding_all), 0) FROM public.v_ar_card");
$arOutView= (float) scalar($db, "SELECT COALESCE(SUM(outstanding), 0) FROM public.v_ar_aging");
check('v_ar_card: SUM(outstanding_all) == SUM(v_ar_aging.outstanding)', abs($arOutAll - $arOutView) < 0.01,
      "card={$arOutAll} aging={$arOutView}");
$apOutAll = (float) scalar($db, "SELECT COALESCE(SUM(outstanding_all), 0) FROM public.v_ap_card");
$apOutView= (float) scalar($db, "SELECT COALESCE(SUM(outstanding), 0) FROM public.v_ap_aging");
check('v_ap_card: SUM(outstanding_all) == SUM(v_ap_aging.outstanding)', abs($apOutAll - $apOutView) < 0.01,
      "card={$apOutAll} aging={$apOutView}");

echo "--- B. Data readiness & menu ---\n";
check('m_form_name seq 6038 terdaftar aktif', (int) scalar($db,
    "SELECT COUNT(*) FROM public.m_form_name WHERE seq = '6038' AND active = true") === 1);
check('m_form_name seq 6039 terdaftar aktif', (int) scalar($db,
    "SELECT COUNT(*) FROM public.m_form_name WHERE seq = '6039' AND active = true") === 1);
check('6038 -> ar.aging.php/ar.aging.js', (int) scalar($db,
    "SELECT COUNT(*) FROM public.m_form_name WHERE seq = '6038' AND php_url = 'ar.aging.php' AND js_url = 'ar.aging.js'") === 1);
check('6039 -> ap.aging.php/ap.aging.js', (int) scalar($db,
    "SELECT COUNT(*) FROM public.m_form_name WHERE seq = '6039' AND php_url = 'ap.aging.php' AND js_url = 'ap.aging.js'") === 1);
foreach (['6038', '6039'] as $seq) {
    $got = (int) scalar($db,
        "SELECT COUNT(*) FROM (SELECT DISTINCT user_id FROM public.m_user_access_menu a
                                 JOIN public.m_form_name f ON f.id = a.form_id WHERE f.seq = ?) x", [$seq]);
    $exp = (int) scalar($db,
        "SELECT COUNT(DISTINCT user_id) FROM public.m_user_access_menu WHERE form_id = 155");
    check("akses menu {$seq} sejajar LAPORAN PPN (155)", $got === $exp, "got={$got} exp={$exp}");
}
foreach (['php-crud/ar.agingCRD.php', 'php-crud/ap.agingCRD.php',
          'js/ar.aging.js', 'js/ap.aging.js',
          'views/ar.aging.php', 'views/ap.aging.php'] as $f) {
    check("file ada: {$f}", file_exists($root . '/' . $f));
}

echo "--- C. Logika bisnis (view & filter) ---\n";
check('v_ar_aging: hanya release_status released', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ar_aging a
      JOIN public.project_invoices v ON v.id = a.invoice_id
     WHERE v.release_status <> 'released'") === 0);
check('v_ap_aging: tanpa void/cancel/reverse', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ap_aging a
      JOIN public.purchase_invoice_hdr h ON h.id = a.invoice_id
     WHERE COALESCE(LOWER(h.status),'') IN ('void','cancelled','canceled','dibatalkan','reversed')") === 0);
check('v_ar_aging: age_days = CURRENT_DATE - aging_date', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ar_aging WHERE age_days <> (CURRENT_DATE - aging_date)") === 0);
check('v_ap_aging: age_days = CURRENT_DATE - aging_date', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ap_aging WHERE age_days <> (CURRENT_DATE - aging_date)") === 0);
check('v_ar_aging: bucket tidak negatif', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ar_aging WHERE bucket_0_30 < 0 OR bucket_31_60 < 0
        OR bucket_61_90 < 0 OR bucket_90_plus < 0") === 0);
check('v_ap_aging: bucket tidak negatif', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ap_aging WHERE bucket_0_30 < 0 OR bucket_31_60 < 0
        OR bucket_61_90 < 0 OR bucket_90_plus < 0") === 0);
check('v_ar_aging: outstanding == amount - paid', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ar_aging WHERE abs(outstanding - (invoice_amount - paid_amount)) > 0.01") === 0);
check('v_ap_aging: outstanding == total - paid', (int) scalar($db,
    "SELECT COUNT(*) FROM public.v_ap_aging WHERE abs(outstanding - (invoice_amount - paid_amount)) > 0.01") === 0);
check('v_ar_card: sisa tidak NULL', (int) scalar($db, "SELECT COUNT(*) FROM public.v_ar_card WHERE sisa IS NULL") === 0);
check('v_ap_card: sisa tidak NULL', (int) scalar($db, "SELECT COUNT(*) FROM public.v_ap_card WHERE sisa IS NULL") === 0);

echo "--- D. Endpoint HTTP (web 127.0.0.1:8098) ---\n";
function http_post(string $path, array $fields, string $jar): array {
    $ch = curl_init(CFG_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => ['Origin: ' . CFG_ORIGIN, 'X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'body' => (string)$body, 'err' => $err];
}
function http_get(string $path, string $jar): array {
    $ch = curl_init(CFG_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'body' => (string)$body, 'err' => $err];
}
function api(int $userId, string $crud, string $task, array $fields = []): array {
    $fields['task'] = $task;
    $r = http_post('/php-crud/' . $crud, $fields, '/tmp/e2e_cookie_' . $userId);
    $json = json_decode($r['body'], true);
    return ['http' => $r['http'], 'json' => is_array($json) ? $json : null, 'raw' => $r['body'], 'err' => $r['err']];
}
function login_ar_ap(int $userId, string $loginName, string $password): bool {
    $jar = '/tmp/e2e_cookie_' . $userId;
    if (is_file($jar)) { unlink($jar); }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => CFG_BASE_URL . '/login.php', CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $loginName, 'password' => $password]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => ['Origin: ' . CFG_ORIGIN, 'X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 302) { fwrite(STDERR, "[LOGIN] user={$loginName} http={$code} body0=" . substr((string)$body, 0, 120) . "\n"); return false; }
    $chk = api($userId, 'ar.agingCRD.php', 'list_clients', []);
    return $chk['http'] === 200 && !empty($chk['json']['success']);
}

// User uji: level 4 (admin) supaya berhak baca kedua laporan (menu 6038/6039 level 4).
$uname = CFG_TAG . '-ADMIN-' . date('His');
$stmt = $db->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (?, ?, true) RETURNING id");
$stmt->execute([$uname, $uname . '-pass']);
$uid = (int) $db->lastInsertId();
$fmtAr = scalar($db, "SELECT id FROM public.m_form_name WHERE seq = '6038'");
$fmtAp = scalar($db, "SELECT id FROM public.m_form_name WHERE seq = '6039'");
$db->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (?, ?, 4)")->execute([$uid, $fmtAr]);
$db->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (?, ?, 4)")->execute([$uid, $fmtAp]);
check('user uji dibuat + diberi akses 6038/6039', $uid > 0);
$loginOk = login_ar_ap($uid, $uname, $uname . '-pass');
check('login user uji + list_clients OK', $loginOk);

// Tanpa sesi -> 401/403
$r0 = http_get('/php-crud/ar.agingCRD.php?task=list_clients', '/tmp/e2e_cookie_0');
check('ar.agingCRD tanpa sesi ditolak (401/403)', in_array($r0['http'], [401, 403], true), "code={$r0['http']}");
$r0 = http_get('/php-crud/ap.agingCRD.php?task=list_suppliers', '/tmp/e2e_cookie_0');
check('ap.agingCRD tanpa sesi ditolak (401/403)', in_array($r0['http'], [401, 403], true), "code={$r0['http']}");

if ($loginOk) {
    $r = api($uid, 'ar.agingCRD.php', 'list_clients', []);
    check('AR list_clients sukses + data array', $r['http'] === 200 && !empty($r['json']['success']) && is_array($r['json']['data']),
          substr($r['raw'], 0, 160));
    $r = api($uid, 'ap.agingCRD.php', 'list_suppliers', []);
    check('AP list_suppliers sukses + data array', $r['http'] === 200 && !empty($r['json']['success']) && is_array($r['json']['data']),
          substr($r['raw'], 0, 160));
    $r = api($uid, 'ar.agingCRD.php', 'list_cards', ['year' => (int)date('Y'), 'month' => (int)date('n')]);
    $hasTotals = !empty($r['json']['totals']);
    check('AR list_cards sukses + totals', $r['http'] === 200 && !empty($r['json']['success']) && $hasTotals, substr($r['raw'], 0, 160));
    $r = api($uid, 'ap.agingCRD.php', 'list_cards', ['year' => (int)date('Y'), 'month' => (int)date('n')]);
    $hasTotals = !empty($r['json']['totals']);
    check('AP list_cards sukses + totals', $r['http'] === 200 && !empty($r['json']['success']) && $hasTotals, substr($r['raw'], 0, 160));
    $df = date('Y-m-d', strtotime('-365 days')); $dt = date('Y-m-d');
    $r = api($uid, 'ar.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt]);
    check('AR list_details sukses (rentang bebas)', $r['http'] === 200 && !empty($r['json']['success']) && is_array($r['json']['data']),
          substr($r['raw'], 0, 160));
    $r = api($uid, 'ap.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt]);
    check('AP list_details sukses (rentang bebas)', $r['http'] === 200 && !empty($r['json']['success']) && is_array($r['json']['data']),
          substr($r['raw'], 0, 160));
    // Filter klien/supplier: bandingkan jumlah baris dengan filter vs tanpa filter
    $all = api($uid, 'ar.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt]);
    $first = isset($all['json']['data'][0]) ? $all['json']['data'][0] : null;
    if ($first) {
        $flt = api($uid, 'ar.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt, 'client_id' => (int)$first['client_id']]);
        $okF = true;
        foreach ($flt['json']['data'] as $row) { if ((int)$row['client_id'] !== (int)$first['client_id']) { $okF = false; break; } }
        check('AR list_details filter client_id memfilter dengan benar',
              $okF && count($flt['json']['data']) <= count($all['json']['data']));
    } else {
        check('AR list_details filter client_id memfilter dengan benar', false, 'tidak ada data detail');
    }
    $allA = api($uid, 'ap.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt]);
    $firstA = isset($allA['json']['data'][0]) ? $allA['json']['data'][0] : null;
    if ($firstA) {
        $flt = api($uid, 'ap.agingCRD.php', 'list_details', ['date_from' => $df, 'date_to' => $dt, 'supplier_id' => (int)$firstA['supplier_id']]);
        $okF = true;
        foreach ($flt['json']['data'] as $row) { if ((int)$row['supplier_id'] !== (int)$firstA['supplier_id']) { $okF = false; break; } }
        check('AP list_details filter supplier_id memfilter dengan benar',
              $okF && count($flt['json']['data']) <= count($allA['json']['data']));
    } else {
        check('AP list_details filter supplier_id memfilter dengan benar', false, 'tidak ada data detail');
    }
    // Tanggal tidak valid -> error eksplisit (bukan SQL error mentah)
    $bad = api($uid, 'ar.agingCRD.php', 'list_details', ['date_from' => '2026-13-40', 'date_to' => $dt]);
    check('AR list_details menolak tanggal tidak valid', $bad['http'] === 200 && empty($bad['json']['success']),
          substr($bad['raw'], 0, 120));
    // Export CSV header
    $r = http_get('/php-crud/ar.agingCRD.php?task=export_csv&type=cards&year=' . date('Y') . '&month=' . date('n'), '/tmp/e2e_cookie_' . $uid);
    check('AR export_csv mengirim header CSV', $r['http'] === 200 && strpos(ltrim($r['body'], "\xEF\xBB\xBF"), 'sep=;') === 0,
          "code={$r['http']} head0=" . substr($r['body'], 0, 40));
    $r = http_get('/php-crud/ap.agingCRD.php?task=export_csv&type=details&date_from=' . $df . '&date_to=' . $dt, '/tmp/e2e_cookie_' . $uid);
    check('AP export_csv details mengirim header CSV', $r['http'] === 200 && strpos(ltrim($r['body'], "\xEF\xBB\xBF"), 'sep=;') === 0,
          "code={$r['http']} head0=" . substr($r['body'], 0, 40));
}

echo "--- E. Uji fungsional (fixture + ROLLBACK) ---\n";
try {
    $db->beginTransaction();
    // Fixture AR: proyek + klien + invoice released + pembayaran + alokasi sebagian.
    $pid = scalar($db, "SELECT id FROM public.projects ORDER BY id LIMIT 1");
    $cid = scalar($db, "SELECT client_id FROM public.projects WHERE id = ?", [$pid]);
    $stInv = $db->prepare("INSERT INTO public.project_invoices
                  (project_id, inv_no, termin_name, invoice_date, percentage, amount, paid_amount, release_status, release_no, release_date, released_by, auto_generated)
                  VALUES (?, CONCAT('E2E-ARAP-', nextval('project_invoices_id_seq')), 'Termin Uji', CURRENT_DATE, 10, 1000000, 0, 'released', 'REL-E2E', CURRENT_DATE, 1, true) RETURNING id");
    $stInv->execute([$pid]);
    $invId = (int) $stInv->fetchColumn();
    // Pembayaran proyek + item alokasi 250.000
    $stPay = $db->prepare("INSERT INTO public.project_payments
                  (payment_no, client_id, account_id, payment_date, payment_method, total_amount, allocated_amount, excess_amount, reference_no, notes, created_by, created_at, currency_id, exch_rate, total_amount_cur, total_amount_base, allocated_amount_base, excess_amount_base, deposit_applied, deposit_applied_base)
                  VALUES (CONCAT('E2E-PAY-', nextval('project_payments_id_seq')), ?, 1, CURRENT_DATE, 'TRANSFER', 250000, 250000, 0, 'E2E-ARAP-PAY', 'fixture re-audit', 1, CURRENT_TIMESTAMP, (SELECT id FROM public.m_currency ORDER BY id LIMIT 1), 1, 250000, 250000, 250000, 0, 0, 0) RETURNING id");
    $stPay->execute([$cid]);
    $payId = (int) $stPay->fetchColumn();
    $db->prepare("INSERT INTO public.project_payment_items (payment_id, invoice_id, amount_paid) VALUES (?, ?, 250000)")
       ->execute([$payId, $invId]);
    // Fixture AP: supplier + invoice aktif + pembayaran supplier + item
    $sid = scalar($db, "SELECT id FROM public.m_suppliers ORDER BY id LIMIT 1");
    $pinvId = (int) scalar($db, "INSERT INTO public.purchase_invoice_hdr
                  (inv_no, supplier_invoice_no, invoice_date, supplier_id, status, total_amount, paid_amount, dpp_amount, ppn_rate, ppn_amount, created_by, created_at)
                  VALUES ('E2E-AP-' || nextval('purchase_invoice_hdr_id_seq'), 'SUP-E2E', CURRENT_DATE, ?, 'posted', 500000, 0, 450450.45, 11, 49504.55, 1, CURRENT_TIMESTAMP) RETURNING id", [$sid]);
    // FIX: Insert dengan id yang sudah diketahui sebelum TRY block
    $seqVal = (int) $db->query("SELECT nextval('supplier_payments_id_seq')")->fetchColumn();
    $stSpay = $db->prepare("INSERT INTO public.supplier_payments
                  (id, payment_no, payment_date, supplier_id, account_id, payment_method, total_amount, allocated_amount, excess_amount, reference_no, notes, created_by, created_at, currency_id, exch_rate, total_amount_base, allocated_amount_base, excess_amount_base, deposit_applied, deposit_applied_base)
                  VALUES (?, CONCAT('E2E-AP-PAY', ?::text), CURRENT_DATE, ?, 1, 'TRANSFER', 200000, 200000, 0, 'E2E-AP', 'fixture re-audit', 1, CURRENT_TIMESTAMP, (SELECT id FROM public.m_currency ORDER BY id LIMIT 1), 1, 200000, 200000, 0, 0, 0)");
    $stSpay->execute([$seqVal, $seqVal, $sid]);
    $spayId = $seqVal;
    // Verifikasi satu baris masuk di tabel supplier_payments sebelum insert item
    $chkRows = (int) scalar($db, "SELECT COUNT(*) FROM public.supplier_payments WHERE id = ?", [$seqVal]);
    if ($chkRows === 0) {
        // Jika gagal, catat error dan lewati insert items
        error_log('[reaudit_fase3_ar_ap] Gagal membuat supplier_payments dengan id=' . $seqVal);
        $spayId = 0; // Tandai agar insert items dilewati
    }
    if ($spayId > 0) {
        $db->prepare("INSERT INTO public.supplier_payment_items (supplier_payment_id, invoice_id, amount_paid) VALUES (?, ?, 200000)")
           ->execute([$spayId, $pinvId]);
    }

    // Verifikasi AR: invoice muncul di v_ar_aging dengan outstanding 750.000
    $o = scalar($db, "SELECT outstanding FROM public.v_ar_aging WHERE invoice_id = ?", [$invId]);
    check('fixture AR: outstanding 750.000 di v_ar_aging', $o !== false && abs((float)$o - 750000) < 0.01, "got={$o}");
    $s = scalar($db, "SELECT sisa FROM public.v_ar_card WHERE client_id = ?", [$cid]);
    $expSisa = 750000; // hanya fixture (saldo awal/faktur lain di bulan ini bisa menambah)
    check('fixture AR: sisa kartu >= outstanding fixture', $s !== false && (float)$s >= 750000 - 0.01, "sisa={$s}");
    // Verifikasi AP
    $o = scalar($db, "SELECT outstanding FROM public.v_ap_aging WHERE invoice_id = ?", [$pinvId]);
    check('fixture AP: outstanding 300.000 di v_ap_aging', $o !== false && abs((float)$o - 300000) < 0.01, "got={$o}");
    $s = scalar($db, "SELECT sisa FROM public.v_ap_card WHERE supplier_id = ?", [$sid]);
    check('fixture AP: sisa kartu >= outstanding fixture', $s !== false && (float)$s >= 300000 - 0.01, "sisa={$s}");
    // Aging bucket: invoice hari ini -> bucket 0-30
    $b = scalar($db, "SELECT bucket_0_30 FROM public.v_ar_aging WHERE invoice_id = ?", [$invId]);
    check('fixture AR: bucket 0-30 = outstanding (invoice baru)', abs((float)$b - 750000) < 0.01, "b={$b}");
    $db->rollBack();
    check('fixture ROLLBACK: tidak ada data uji tersisa',
          (int) scalar($db, "SELECT COUNT(*) FROM public.project_invoices WHERE inv_no LIKE 'E2E-ARAP-%'") === 0 &&
          (int) scalar($db, "SELECT COUNT(*) FROM public.purchase_invoice_hdr WHERE inv_no = 'E2E-AP-1'") === 0);
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    check('fixture fungsional AR/AP', false, $e->getMessage());
}

// Cleanup user uji (data m_user_system tidak dibungkus transaksi di atas)
if (!empty($uid)) {
    $db->prepare("DELETE FROM public.m_user_access_menu WHERE user_id = ?")->execute([$uid]);
    $db->prepare("DELETE FROM public.m_user_system WHERE id = ?")->execute([$uid]);
}

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
