<?php
/**
 * tests/run_e2e_inventory_check.php
 *
 * Runner E2E layer "Cek Inventory sebelum Purchase Planning / PO":
 *   - purchase.planningCRD.php: load_planning mengembalikan qty_on_hand & qty_to_buy;
 *     task check_inventory memvalidasi stok yang dapat dipakai proyek.
 *   - poCRD.php: list_rab_items menampilkan stok + kebutuhan bersih (qty_to_buy);
 *     save_po menolak qty yang melebihi kebutuhan bersih (stok proyek sendiri saja yang dihitung;
 *     stok milik proyek LAIN tidak boleh dipakai).
 *
 * Data uji (semua ditandai CFG_TAG dan dibersihkan di cleanup):
 *   - Proyek P1 punya RAB material M1 (qty_plan 100) & M2 (qty_plan 50).
 *   - Gudang WH-A milik P1 berisi stok M1 = 40 (IN via stock_movements fixture).
 *   - Gudang WH-B milik proyek LAIN (P9) berisi stok M1 = 500 -> TIDAK boleh dipakai P1.
 *     Maka kebutuhan bersih M1 = 100 - 40 = 60; PO qty 61 harus ditolak, 60 diterima.
 *   - M2 tanpa stok sama sekali -> kebutuhan bersih 50.
 *
 * Pakai: php tests/run_e2e_inventory_check.php [--setup|--cleanup] [--keep] [--only=TC-101,TC-103]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi (sama dg run_e2e_release.php) ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_REPORT   = __DIR__ . '/../test_report_inventory_check.md';
const CFG_TAG      = 'E2E-INV';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';

/* ---------------- State global ---------------- */
$g = [
    'db' => null,
    'projP1' => 0, 'projOther' => 0,
    'matM1' => 0, 'matM2' => 0,
    'whA' => 0, 'whB' => 0,
    'user_admin' => 0,
    'created' => ['project' => [], 'material' => [], 'warehouse' => [], 'user' => [], 'po' => []],
    'results' => [],
];

/* ---------------- Konfigurasi DB (sama dg run_e2e_release.php) ---------------- */
function db(): PDO
{
    if ($GLOBALS['g']['db']) { return $GLOBALS['g']['db']; }
    $pass = getenv('E2E_DB_PASSWORD') ?: '';
    $dsn = 'pgsql:host=' . CFG_DB_HOST . ';port=' . CFG_DB_PORT . ';dbname=' . CFG_DB_NAME;
    try {
        $pdo = new PDO($dsn, CFG_DB_USER, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, '[DB] koneksi gagal: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $GLOBALS['g']['db'] = $pdo;
    return $pdo;
}
function row(string $sql, array $p = []): ?array
{
    $st = db()->prepare($sql); $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
function rows(string $sql, array $p = []): array
{
    $st = db()->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function val(string $sql, array $p = [])
{
    $r = row($sql, $p);
    return $r ? array_values($r)[0] : null;
}

/* ============================================================
 * 2. HELPER HTTP (Origin header CSRF + cookie jar per user)
 * ==========================================================*/
function curl_base(array $extra, int $userId = 0): array
{
    $ch = curl_init();
    curl_setopt_array($ch, $extra + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEFILE     => '/tmp/e2e_inv_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_inv_cookie_' . $userId,
        CURLOPT_HTTPHEADER     => ['Origin: ' . CFG_ORIGIN, 'X-Requested-With: XMLHttpRequest'],
    ]);
    return [$ch, CFG_BASE_URL];
}
function http_post(string $path, array $fields, int $userId = 0): array
{
    list($ch,) = curl_base([
        CURLOPT_URL        => CFG_BASE_URL . $path,
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ], $userId);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'body' => (string)$body, 'err' => $err];
}
function api(int $userId, string $crud, string $task, array $fields = []): array
{
    $fields['task'] = $task;
    $r = http_post('/php-crud/' . $crud, $fields, $userId);
    $json = json_decode($r['body'], true);
    return ['http' => $r['http'], 'json' => is_array($json) ? $json : null, 'raw' => $r['body'], 'err' => $r['err']];
}
function login(int $userId, string $loginName, string $password): bool
{
    $jar = '/tmp/e2e_inv_cookie_' . $userId;
    if (is_file($jar)) { unlink($jar); }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => CFG_BASE_URL . '/login.php',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => $loginName, 'password' => $password]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => ['Origin: ' . CFG_ORIGIN, 'X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 302 || stripos($body, 'Username atau Password salah') !== false || $err !== '') {
        fwrite(STDERR, "[LOGIN] user={$loginName} http={$code} err={$err}\n");
        return false;
    }
    $chk = api($userId, 'poCRD.php', 'list_project_options', []);
    return $chk['http'] === 200 && !empty($chk['json']['success']);
}

/* ============================================================
 * 3. SETUP DATA UJI
 * ==========================================================*/
function ensure_user(int $level, string $purpose): int
{
    $uname = CFG_TAG . '-' . strtolower($purpose) . '-lv' . $level . '-' . date('His');
    db()->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (:u, :p, true) RETURNING id")
        ->execute(['u' => $uname, 'p' => $uname . '-pass']);
    $uid = (int)db()->lastInsertId();
    // form 138 = PURCHASE PLANNING, form 24 = PURCHASE ORDER
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 138, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 24, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    $GLOBALS['g']['created']['user'][] = $uid;
    return $uid;
}

/** Buat material uji (kembalikan id). */
function ensure_material(string $code, string $name, string $unit): int
{
    $r = row("SELECT id FROM materials WHERE material_code = :c", ['c' => $code]);
    if ($r) { return (int)$r['id']; }
    db()->prepare("INSERT INTO materials (material_code, material_name, unit, category, active) VALUES (:c, :n, :u, 'Material', true) RETURNING id")
        ->execute(['c' => $code, 'n' => $name, 'u' => $unit]);
    return (int)db()->lastInsertId();
}

function setup_test_data(): void
{
    $g = &$GLOBALS['g'];
    $db = db();

    // [IDEMPOTEN] bersihkan sisa fixture run sebelumnya (urutan FK)
    foreach (rows("SELECT id FROM projects WHERE name LIKE :n", ['n' => '%' . CFG_TAG . '%']) as $old) {
        $pid = $old['id'];
        foreach (rows("SELECT id FROM purchase_orders WHERE project_id = :p", ['p' => $pid]) as $po) {
            $db->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(['i' => $po['id']]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(['i' => $po['id']]);
        }
        $db->prepare("DELETE FROM budget_items WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM projects WHERE id = :p")->execute(['p' => $pid]);
    }
    foreach (rows("SELECT id FROM warehouses WHERE name LIKE :n OR code LIKE :c", ['n' => '%' . CFG_TAG . '%', 'c' => '%' . CFG_TAG . '%']) as $oldWh) {
        $db->prepare("DELETE FROM stock_movements WHERE warehouse_id = :w")->execute(['w' => $oldWh['id']]);
        $db->prepare("DELETE FROM warehouses WHERE id = :w")->execute(['w' => $oldWh['id']]);
    }
    foreach (rows("SELECT id FROM materials WHERE material_code LIKE :c", ['c' => CFG_TAG . '%']) as $oldM) {
        $db->prepare("DELETE FROM budget_items WHERE material_id = :m")->execute(['m' => $oldM['id']]);
        $db->prepare("DELETE FROM materials WHERE id = :m")->execute(['m' => $oldM['id']]);
    }

    // Proyek uji P1 + proyek lain P9 (pemilik stok yang TIDAK boleh dipinjam P1)
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P1 " . CFG_TAG . "', 1, 'Lokasi Uji', CURRENT_DATE, CURRENT_DATE + 30, 500000000, 0, 0, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projP1'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projP1'];
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P9 " . CFG_TAG . "', 1, 'Lokasi Uji Lain', CURRENT_DATE, CURRENT_DATE + 30, 500000000, 0, 0, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projOther'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projOther'];

    // Material uji
    $g['matM1'] = ensure_material(CFG_TAG . '-M1', 'Baut Hex ' . CFG_TAG, 'Buah');
    $g['matM2'] = ensure_material(CFG_TAG . '-M2', 'Mur Hex ' . CFG_TAG, 'Buah');
    $g['created']['material'] = [$g['matM1'], $g['matM2']];

    // RAB P1: M1 qty 100, M2 qty 50
    $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan)
                  VALUES (:p, 'Material', :m, :nm, :u, :q, 10000)")
        ->execute(['p' => $g['projP1'], 'm' => $g['matM1'], 'nm' => 'Baut Hex ' . CFG_TAG, 'u' => 'Buah', 'q' => 100]);
    $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan)
                  VALUES (:p, 'Material', :m, :nm, :u, :q, 5000)")
        ->execute(['p' => $g['projP1'], 'm' => $g['matM2'], 'nm' => 'Mur Hex ' . CFG_TAG, 'u' => 'Buah', 'q' => 50]);

    // Gudang WH-A milik P1, WH-B milik P9 (proyek lain)
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-A-' . CFG_TAG, 'n' => 'Gudang P1 ' . CFG_TAG, 'p' => $g['projP1']]);
    $g['whA'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whA'];
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-B-' . CFG_TAG, 'n' => 'Gudang P9 ' . CFG_TAG, 'p' => $g['projOther']]);
    $g['whB'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whB'];

    // Stok M1 di WH-A (milik P1) = 40 dan WH-B (milik P9) = 500.
    // [FIX-FK] wh_receiving_hdr punya FK ke grn_hdr -> fixture stok langsung di-posting ke
    // ledger stock_movements (sama seperti yang dilakukan GRN/wh_receiving asli setelah posting).
    $db->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                  VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 40, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whA'], 'r' => CFG_TAG . '-RCV-A']);
    $db->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                  VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 500, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whB'], 'r' => CFG_TAG . '-RCV-B']);

    // User admin + login (FormAccess level 4 utk form 24 & 138)
    $g['user_admin'] = ensure_user(4, 'admin');
    $uname = val("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g['user_admin']]);
    if (!login($g['user_admin'], $uname, $uname . '-pass')) { fwrite(STDERR, "[SETUP] login gagal\n"); }
}

function cleanup_test_data(): void
{
    $g = $GLOBALS['g'];
    $db = db();
    $db->beginTransaction();
    try {
        foreach ($g['created']['po'] as $poId) {
            $db->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(['i' => $poId]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(['i' => $poId]);
        }
        foreach ($g['created']['project'] as $pid) {
            foreach (rows("SELECT id FROM purchase_orders WHERE project_id = :p", ['p' => $pid]) as $po) {
                $db->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(['i' => $po['id']]);
                $db->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(['i' => $po['id']]);
            }
            $db->prepare("DELETE FROM budget_items WHERE project_id = :p")->execute(['p' => $pid]);
            $db->prepare("DELETE FROM projects WHERE id = :p")->execute(['p' => $pid]);
        }
        // (penghapusan purchase_orders/budget_items/projects sudah di loop di atas)
        foreach ($g['created']['warehouse'] as $wid) {
            $db->prepare("DELETE FROM stock_movements WHERE warehouse_id = :w")->execute(['w' => $wid]);
            $db->prepare("DELETE FROM warehouses WHERE id = :w")->execute(['w' => $wid]);
        }
        foreach ($g['created']['material'] as $mid) {
            $db->prepare("DELETE FROM budget_items WHERE material_id = :m")->execute(['m' => $mid]);
            $db->prepare("DELETE FROM materials WHERE id = :m")->execute(['m' => $mid]);
        }
        foreach ($g['created']['user'] as $uid) {
            $db->prepare("DELETE FROM m_user_access_menu WHERE user_id = :u")->execute(['u' => $uid]);
            $db->prepare("DELETE FROM m_user_system WHERE id = :u")->execute(['u' => $uid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "[CLEANUP] gagal: " . $e->getMessage() . "\n");
    }
}

/* ============================================================
 * 4. TEST CASES
 * ==========================================================*/
function record(string $tc, string $scenario, $status, string $note = ''): void
{
    $GLOBALS['g']['results'][] = ['tc' => $tc, 'scenario' => $scenario, 'status' => $status, 'note' => $note];
    printf("  [%s] %-7s %s%s\n", $status === true ? 'PASS' : ($status === false ? 'FAIL' : 'SKIP'), $tc, $scenario, $note !== '' ? " -- {$note}" : '');
}
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) <= $eps; }

/** TC-101: load_planning mengembalikan qty_on_hand & qty_to_buy yang benar. */
function tc101(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'load_planning', ['project_id' => $g['projP1']]);
    if (empty($r['json']['success'])) { record('TC-101', 'load_planning berhasil', false, 'http=' . $r['http'] . ' raw=' . substr($r['raw'], 0, 160)); return; }
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $m2 = $byMat[$g['matM2']] ?? null;
    $ok = true; $note = [];
    if (!$m1) { $ok = false; $note[] = 'M1 tidak ada'; }
    else {
        $note[] = 'M1 on_hand=' . $m1['qty_on_hand'] . ' remaining=' . $m1['qty_remaining'] . ' to_buy=' . $m1['qty_to_buy'];
        if (!approx((float)$m1['qty_on_hand'], 40)) { $ok = false; $note[] = 'M1 on_hand harus 40 (stok WH-A milik P1)'; }
        if (!approx((float)$m1['qty_to_buy'], 60)) { $ok = false; $note[] = 'M1 to_buy harus 60 (100-40)'; }
    }
    if (!$m2) { $ok = false; $note[] = 'M2 tidak ada'; }
    else {
        $note[] = 'M2 on_hand=' . $m2['qty_on_hand'] . ' to_buy=' . $m2['qty_to_buy'];
        if (!approx((float)$m2['qty_on_hand'], 0)) { $ok = false; $note[] = 'M2 on_hand harus 0'; }
        if (!approx((float)$m2['qty_to_buy'], 50)) { $ok = false; $note[] = 'M2 to_buy harus 50'; }
    }
    record('TC-101', 'load_planning: qty_on_hand & qty_to_buy sesuai stok proyek sendiri', $ok, implode(' | ', $note));
}

/** TC-102: stok milik proyek LAIN (WH-B/P9) tidak dihitung untuk P1. */
function tc102(): void
{
    $g = $GLOBALS['g'];
    // Hitung langsung: total seluruh stok M1 di DB = 540 (40 P1 + 500 P9);
    // P1 hanya boleh melihat 40.
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'load_planning', ['project_id' => $g['projP1']]);
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $totalAll = (float)val("SELECT SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) FROM stock_movements WHERE item_id = :i", ['i' => $g['matM1']]);
    $ok = $m1 && approx((float)$m1['qty_on_hand'], 40) && approx($totalAll, 540);
    record('TC-102', 'stok proyek lain (500 di WH-B) TIDAK dihitung; total DB 540, P1 hanya 40', $ok,
        'total_db=' . $totalAll . ' p1_on_hand=' . ($m1 ? $m1['qty_on_hand'] : 'n/a'));
}

/** TC-103: check_inventory OK untuk qty <= kebutuhan bersih. */
function tc103(): void
{
    $g = $GLOBALS['g'];
    $items = json_encode([
        ['rab_material_id' => val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM1']]), 'material_id' => $g['matM1'], 'qty' => 60],
        ['rab_material_id' => val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM2']]), 'material_id' => $g['matM2'], 'qty' => 50],
    ]);
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'check_inventory', ['project_id' => $g['projP1'], 'items' => $items]);
    $ok = !empty($r['json']['success']);
    $note = '';
    if ($ok) {
        $allOk = true; $noteParts = [];
        foreach ($r['json']['data'] as $d) {
            $noteParts[] = $d['material_name'] . ': covered=' . $d['qty_covered'] . ' to_buy=' . $d['qty_to_buy'];
            if (!$d['ok']) { $allOk = false; }
        }
        $note = implode(' | ', $noteParts);
        $ok = $allOk;
    } else {
        $note = substr($r['raw'], 0, 160);
    }
    record('TC-103', 'check_inventory: qty sesuai kebutuhan bersih dinyatakan OK', $ok, $note);
}

/** TC-104: check_inventory menolak qty > kuota RAB (melebihi sisa kuota, bukan sekadar perlu beli). */
function tc104(): void
{
    $g = $GLOBALS['g'];
    $bid = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM1']]);
    // qty 101 melebihi kuota RAB 100 (tanpa tolerance) -> harus ditolak (ok=false),
    // meski stok 40 tersedia. Ini bedanya "perlu beli" (informasi) vs "melebihi kuota" (error).
    $items = json_encode([['rab_material_id' => $bid, 'material_id' => $g['matM1'], 'qty' => 101]]);
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'check_inventory', ['project_id' => $g['projP1'], 'items' => $items]);
    $ok = !empty($r['json']['success']);
    $msg = '';
    if ($ok) {
        $d = $r['json']['data'][0] ?? null;
        $msg = $d['message'] ?? '';
        $ok = $d && !empty($d['ok']) === false;
    } else {
        $msg = substr($r['raw'], 0, 160);
    }
    record('TC-104', 'check_inventory: qty 101 > kuota RAB 100 ditolak (ok=false)', $ok, $msg);
}

/** TC-105: dua baris RAB berbeda material; stok hanya dipakai sekali (tidak ditindih). */
function tc105(): void
{
    $g = $GLOBALS['g'];
    $bid1 = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM1']]);
    $bid2 = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM2']]);
    // M1: stok 40 diminta 30 (tercoverasi penuh) lalu diminta 20 lagi -> hanya sisa 10 yang tertutupi,
    //     jadi baris kedua to_buy = 10 (stok tidak ditindih).
    // M2: tanpa stok -> qty berapa pun to_buy = qty itu sendiri.
    $items = json_encode([
        ['rab_material_id' => $bid1, 'material_id' => $g['matM1'], 'qty' => 30],
        ['rab_material_id' => $bid1, 'material_id' => $g['matM1'], 'qty' => 20],
        ['rab_material_id' => $bid2, 'material_id' => $g['matM2'], 'qty' => 10],
    ]);
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'check_inventory', ['project_id' => $g['projP1'], 'items' => $items]);
    $ok = !empty($r['json']['success']);
    $note = '';
    if ($ok) {
        $d = $r['json']['data'];
        $tb = array_map(function ($x) { return (float)$x['qty_to_buy']; }, $d);
        $cov = array_map(function ($x) { return (float)$x['qty_covered']; }, $d);
        $note = 'M1 baris1 cov=' . $cov[0] . ' tb=' . $tb[0] . ' | M1 baris2 cov=' . $cov[1] . ' tb=' . $tb[1] . ' | M2 tb=' . $tb[2];
        // baris 1: tertutupi 30 penuh -> to_buy 0; baris 2: sisa stok 10 -> to_buy 10; M2: to_buy 10
        $ok = approx($tb[0], 0) && approx($tb[1], 10) && approx($tb[2], 10);
    } else {
        $note = substr($r['raw'], 0, 160);
    }
    record('TC-105', 'dua baris RAB material sama: stok hanya dipakai sekali (tidak ditindih)', $ok, $note);
}

/** TC-106: list_rab_items di poCRD menampilkan qty_on_hand & qty_to_buy. */
function tc106(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'poCRD.php', 'list_rab_items', ['project_id' => $g['projP1']]);
    if (empty($r['json']['success'])) { record('TC-106', 'list_rab_items menyertakan qty_on_hand/qty_to_buy', false, substr($r['raw'], 0, 160)); return; }
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $ok = $m1 && approx((float)$m1['qty_on_hand'], 40) && approx((float)$m1['qty_to_buy'], 60);
    record('TC-106', 'poCRD list_rab_items: qty_on_hand=40 & qty_to_buy=60 untuk M1', $ok,
        $m1 ? ('on_hand=' . $m1['qty_on_hand'] . ' to_buy=' . $m1['qty_to_buy']) : 'M1 tidak ada');
}

/** TC-107: save_po menerima qty = kebutuhan bersih (stok diperhitungkan). */
function tc107(): void
{
    $g = $GLOBALS['g'];
    $bid = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM1']]);
    $r = api($g['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $g['projP1'], 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode([['budget_item_id' => $bid, 'qty' => 60, 'unit_price' => 10000]]),
    ]);
    $ok = !empty($r['json']['success']);
    $poId = $ok ? (int)$r['json']['data']['id'] : 0;
    if ($poId) { $g['created']['po'][] = $poId; }
    record('TC-107', 'save_po: qty 60 (= sisa kuota 100 - stok 40) diterima', $ok,
        $ok ? ('po_number=' . $r['json']['data']['po_number']) : substr($r['raw'], 0, 200));
}

/** TC-108: save_po menolak qty > kebutuhan bersih (stok proyek sendiri sudah mencukupi sebagian). */
function tc108(): void
{
    $g = $GLOBALS['g'];
    $bid = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM1']]);
    $r = api($g['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $g['projP1'], 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode([['budget_item_id' => $bid, 'qty' => 100, 'unit_price' => 10000]]),  // abaikan stok 40
    ]);
    $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'kebutuhan bersih') !== false;
    record('TC-108', 'save_po: qty 100 (tanpa kurangi stok 40) ditolak "melebihi kebutuhan bersih"', $ok,
        substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
}

/** TC-109: material tanpa stok sama sekali (M2) tetap dapat di-PO penuh sesuai kebutuhan bersih. */
function tc109(): void
{
    $g = $GLOBALS['g'];
    $bidM2 = (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $g['projP1'], 'm' => $g['matM2']]);
    // M2 tanpa stok di mana pun -> kebutuhan bersih = penuh 50. Sistem tidak boleh menganggap
    // stok proyek lain (atau stok material lain) tersedia untuk material ini.
    $r = api($g['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $g['projP1'], 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode([['budget_item_id' => $bidM2, 'qty' => 50, 'unit_price' => 5000]]),
    ]);
    $ok = !empty($r['json']['success']);
    $poId = $ok ? (int)$r['json']['data']['id'] : 0;
    if ($poId) { $g['created']['po'][] = $poId; }
    record('TC-109', 'save_po: material tanpa stok (M2) qty 50 = kebutuhan bersih diterima', $ok,
        $ok ? ('po_number=' . $r['json']['data']['po_number']) : substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
}

/** TC-110: stok di gudang proyek LAIN tidak mengurangi kebutuhan beli P1 (M1: P1 butuh 60, bukan 0). */
function tc110(): void
{
    $g = $GLOBALS['g'];
    // qty_to_buy M1 di list_rab_items harus 60 (bukan 0 / negatif) meski total stok M1 di DB = 540.
    $r = api($g['user_admin'], 'poCRD.php', 'list_rab_items', ['project_id' => $g['projP1']]);
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $ok = $m1 && approx((float)$m1['qty_to_buy'], 60);
    record('TC-110', 'kebutuhan bersih M1 = 60 meski proyek lain punya 500 (stok lintas proyek tidak dipinjam)', $ok,
        $m1 ? ('qty_to_buy=' . $m1['qty_to_buy'] . ' on_hand=' . $m1['qty_on_hand']) : 'M1 tidak ada');
}

/* ============================================================
 * 5. LAPORAN & MAIN
 * ==========================================================*/
function write_report(array $groupResults, string $groupTitle): void
{
    $path = CFG_REPORT;
    $old = is_file($path) ? file_get_contents($path) : '';
    $sec = "<!-- BEGIN:{$groupTitle} -->\n";
    $sec .= "## {$groupTitle}\n\n";
    $sec .= "| TC | Skenario | Hasil | Bukti / Catatan |\n|----|----------|-------|------------------|\n";
    foreach ($groupResults as $r) {
        $st = $r['status'] === true ? 'PASS' : ($r['status'] === false ? 'FAIL' : 'SKIP');
        $sec .= "| {$r['tc']} | " . str_replace('|', '\|', $r['scenario']) . " | {$st} | " . str_replace('|', '\|', $r['note']) . " |\n";
    }
    $sec .= "<!-- END:{$groupTitle} -->\n";
    if (preg_match('/<!-- BEGIN:' . preg_quote($groupTitle, '/') . ' -->.*?<!-- END:' . preg_quote($groupTitle, '/') . ' -->\n?/s', $old)) {
        $out = preg_replace('/<!-- BEGIN:' . preg_quote($groupTitle, '/') . ' -->.*?<!-- END:' . preg_quote($groupTitle, '/') . ' -->\n?/s', $sec, $old);
    } else {
        $out = rtrim($old) . "\n\n" . $sec;
    }
    $pass = $fail = $skip = 0;
    foreach (preg_split('/\n(?=\| TC-)/', $out) as $ln) {
        if (strpos($ln, '| TC-') !== 0) { continue; }
        $cols = array_map('trim', explode('|', $ln));
        $h = $cols[3] ?? '';
        if ($h === 'PASS') { $pass++; } elseif ($h === 'FAIL') { $fail++; } elseif ($h === 'SKIP') { $skip++; }
    }
    $header = "# Test Report Cek Inventory sebelum Purchase Planning / PO (TC-101 s/d TC-110)\n\n";
    $header .= "**Tanggal eksekusi:** " . date('Y-m-d H:i:s') . "  \n";
    $header .= "**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `" . CFG_BASE_URL . "`  \n";
    $header .= "**Dokumen acuan:** `tests/run_e2e_inventory_check.php`  \n";
    $header .= "**Ringkasan sementara:** PASS = {$pass}, FAIL = {$fail}, SKIP = {$skip}\n\n";
    $header .= "> Fitur: pengecekan inventory sebelum membuat purchase planning/PO. Stok yang dihitung hanya milik proyek\n";
    $header .= "> sendiri (gudang `warehouses.project_id`) + gudang bersama (`project_id IS NULL`). Stok milik proyek lain\n";
    $header .= "> tidak boleh dipinjam. Kebutuhan bersih (`qty_to_buy`) = sisa kuota RAB - stok tersedia.\n\n";
    $out = preg_replace('/^# Test Report Cek Inventory.*?(?=\n## |\n<!-- BEGIN:)/s', '', $out);
    $out = $header . ltrim($out, "\n");
    file_put_contents($path, $out);
}

function main(array $argv): void
{
    $opts = $argv;
    $only = [];
    $group = '';
    $doSetup = true; $doCleanup = true;
    foreach ($opts as $a) {
        if (strpos($a, '--only=') === 0) { $only = array_map('trim', explode(',', substr($a, 7))); }
        if (strpos($a, '--group=') === 0) { $group = strtoupper(substr($a, 8)); }
        if ($a === '--setup') { $doCleanup = false; }
        if ($a === '--cleanup') { $doSetup = false; }
        if ($a === '--keep') { $doCleanup = false; }
    }

    echo "=== E2E Cek Inventory sebelum Purchase Planning / PO (TC-101 s/d TC-110) ===\n";
    echo "DB: " . CFG_DB_NAME . " @ " . CFG_DB_HOST . " | URL: " . CFG_BASE_URL . "\n";

    if ($doSetup) {
        echo "[setup] membuat data uji (P1 + P9, M1/M2, WH-A/WH-B, stok 40 vs 500, user admin)...\n";
        setup_test_data();
        echo "[setup] selesai. projP1={$GLOBALS['g']['projP1']} projOther={$GLOBALS['g']['projOther']} matM1={$GLOBALS['g']['matM1']} matM2={$GLOBALS['g']['matM2']} whA={$GLOBALS['g']['whA']} whB={$GLOBALS['g']['whB']}\n";
    }

    $groupInv = 'Kelompok F — Cek Inventory sebelum Purchase Planning / PO';
    // Urutan: TC-107 (save_po OK) dijalankan TERAKHIR karena mengubah sisa kuota RAB
    // (PO tersimpan tetap dihitung po_ordered_map); TC-110 butuh keadaan belum ada PO.
    $testsF = [
        'tc101' => 'TC-101', 'tc102' => 'TC-102', 'tc103' => 'TC-103', 'tc104' => 'TC-104', 'tc105' => 'TC-105',
        'tc106' => 'TC-106', 'tc110' => 'TC-110', 'tc108' => 'TC-108', 'tc109' => 'TC-109', 'tc107' => 'TC-107',
    ];
    if ($group && $group !== 'F') { echo "[run] Kelompok F dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok F:\n";
        foreach ($testsF as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupInv);
    }

    if ($doCleanup) {
        echo "[cleanup] menghapus data uji...\n";
        cleanup_test_data();
        echo "[cleanup] selesai.\n";
    } else {
        echo "[cleanup] DILEWATI (--keep/--setup) -- data uji tetap di DB untuk investigasi.\n";
    }

    $p = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === true));
    $f = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === false));
    echo "\nHasil Keseluruhan (F): PASS={$p} FAIL={$f} | laporan: " . CFG_REPORT . "\n";
    exit($f > 0 ? 1 : 0);
}

if (!defined('E2E_AS_LIB')) {
    main($argv);
}
