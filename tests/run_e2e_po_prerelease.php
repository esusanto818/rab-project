<?php
/**
 * tests/run_e2e_po_prerelease.php
 *
 * Runner E2E layer "Pengecekan PO Sebelum Release" terhadap proyekGMN_test + web lokal
 * (base http://127.0.0.1:8098). Memperkuat logika bisnis poCRD.php change_status:
 *   - validasi real-time GetProjectStockMap() & po_net_need() DI DALAM transaksi ACID
 *     sebelum status PO diubah (Diajukan / Disetujui).
 *   - stok gudang proyek lain tetap terisolasi.
 *
 * Skenario (TC-201 s/d TC-210):
 *   TC-201 Release normal (Draft -> Diajukan) lolos
 *   TC-202 Blokir: stok proyek bertambah sebelum release sehingga qty PO > kebutuhan bersih
 *   TC-203 Blokir: kuota RAB habis dipakai PO lain (PO lain disetujui duluan)
 *   TC-204 Blokir double-release (Diajukan -> Diajukan lagi ditolak state machine)
 *   TC-205 Blokir: periode akuntansi tutup
 *   TC-206 Bukti stok proyek lain tidak dipinjam (P1 butuh beli penuh)
 *   TC-207 Blokir: supplier non-aktif
 *   TC-208 Blokir: PO tanpa item
 *   TC-209 Blokir: qty item = 0 / harga negatif (integritas item)
 *   TC-210 Draft -> Diajukan -> Disetujui (alur lengkap lolos)
 *
 * Pakai: php tests/run_e2e_po_prerelease.php [--setup|--cleanup] [--keep] [--only=TC-201]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_REPORT   = __DIR__ . '/../test_report_po_prerelease.md';
const CFG_TAG      = 'E2E-POREL';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';

/* ---------------- State global ---------------- */
$g = [
    'db' => null,
    'projP1' => 0, 'projOther' => 0,
    'matM1' => 0, 'matM2' => 0,
    'whA' => 0, 'whB' => 0,
    'user_admin' => 0,
    'poMain' => 0, 'poOther' => 0,
    'created' => ['project' => [], 'material' => [], 'warehouse' => [], 'po' => [], 'user' => [], 'budget_item' => []],
    'results' => [],
];

/* ---------------- DB helpers (sama dg run_e2e_release.php) ---------------- */
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

/* ---------------- HTTP helpers ---------------- */
function curl_base(array $extra, int $userId = 0): array
{
    $ch = curl_init();
    curl_setopt_array($ch, $extra + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEFILE     => '/tmp/e2e_porel_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_porel_cookie_' . $userId,
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
    $jar = '/tmp/e2e_porel_cookie_' . $userId;
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

/* ---------------- Setup / cleanup ---------------- */
function ensure_user(int $level, string $purpose): int
{
    $uname = CFG_TAG . '-' . strtolower($purpose) . '-lv' . $level . '-' . date('His');
    db()->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (:u, :p, true) RETURNING id")
        ->execute(['u' => $uname, 'p' => $uname . '-pass']);
    $uid = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 24, :lv)")->execute(['uid' => $uid, 'lv' => $level]); // PURCHASE ORDER
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 138, :lv)")->execute(['uid' => $uid, 'lv' => $level]); // PURCHASE PLANNING
    $GLOBALS['g']['created']['user'][] = $uid;
    return $uid;
}
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

    // [IDEMPOTEN] pastikan periode akuntansi berjalan terbuka: run sebelumnya bisa jadi crash
    // di tengah tc205() sehingga periode tertinggal tertutup dan semua save_po setup gagal.
    $per = row("SELECT id, is_closed FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date");
    if ($per && ($per['is_closed'] === 't' || $per['is_closed'] === true || $per['is_closed'] === 1 || $per['is_closed'] === '1')) {
        db()->prepare("UPDATE accounting_periods SET is_closed = false WHERE id = :id")->execute(['id' => $per['id']]);
        fwrite(STDOUT, "[setup] periode akuntansi berjalan dibuka kembali (id={$per['id']}).\n");
    }

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

    // Proyek P1 (utama) + proyek lain P9 (pemilik stok yang tidak boleh dipinjam)
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P1 " . CFG_TAG . "', 1, 'Lokasi Uji', CURRENT_DATE, CURRENT_DATE + 30, 500000000, 0, 0, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projP1'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projP1'];
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P9 " . CFG_TAG . "', 1, 'Lokasi Lain', CURRENT_DATE, CURRENT_DATE + 30, 500000000, 0, 0, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projOther'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projOther'];

    // Material uji
    $g['matM1'] = ensure_material(CFG_TAG . '-M1', 'Baut Hex ' . CFG_TAG, 'Buah');
    $g['matM2'] = ensure_material(CFG_TAG . '-M2', 'Mur Hex ' . CFG_TAG, 'Buah');
    $g['created']['material'] = [$g['matM1'], $g['matM2']];

    // RAB P1: M1 qty 100, M2 qty 50
    foreach ([[$g['matM1'], 100, 10000], [$g['matM2'], 50, 5000]] as [$m, $q, $p]) {
        $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan)
                      VALUES (:p, 'Material', :m, :n, 'Buah', :q, :pr) RETURNING id")
            ->execute(['p' => $g['projP1'], 'm' => $m, 'n' => 'Material ' . $m, 'q' => $q, 'pr' => $p]);
        $g['created']['budget_item'][] = (int)$db->lastInsertId();
    }

    // Gudang WH-A milik P1 (stok M1=40), WH-B milik P9 (stok M1=500, proyek lain)
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-A-' . CFG_TAG, 'n' => 'Gudang P1 ' . CFG_TAG, 'p' => $g['projP1']]);
    $g['whA'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whA'];
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-B-' . CFG_TAG, 'n' => 'Gudang P9 ' . CFG_TAG, 'p' => $g['projOther']]);
    $g['whB'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whB'];

    // Stok M1: 40 di WH-A (milik P1), 500 di WH-B (milik P9)
    $db->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                  VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 40, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whA'], 'r' => CFG_TAG . '-A']);
    $db->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                  VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 500, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whB'], 'r' => CFG_TAG . '-B']);

    // User admin + login (FormAccess level 4 utk form 24)
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

/* ---------------- Helpers pembuat PO ---------------- */
/** Buat PO Draft via API; kembalikan ['id','po_number'] atau null.
    Tiap item: [bid, qty] atau [bid, qty, harga] (harga default 10000). */
function make_po(int $projectId, array $items, float $price = 10000): ?array
{
    $payloadItems = [];
    foreach ($items as $it) {
        $payloadItems[] = ['budget_item_id' => (int)$it[0], 'qty' => (float)$it[1], 'unit_price' => (float)($it[2] ?? $price)];
    }
    $r = api($GLOBALS['g']['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $projectId, 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode($payloadItems),
    ]);
    if (empty($r['json']['success']) || empty($r['json']['data']['id'])) {
        fwrite(STDERR, "[SETUP] save_po gagal: " . substr($r['raw'], 0, 200) . "\n");
        return null;
    }
    $id = (int)$r['json']['data']['id'];
    $GLOBALS['g']['created']['po'][] = $id;
    return ['id' => $id, 'po_number' => $r['json']['data']['po_number']];
}
function change_status(int $poId, string $to): array
{
    return api($GLOBALS['g']['user_admin'], 'poCRD.php', 'change_status', ['id' => $poId, 'to' => $to]);
}

function del_po(int $poId): void {
    db()->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(["i" => $poId]);
    db()->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(["i" => $poId]);
}

/* ---------------- Test cases ---------------- */
function record(string $tc, string $scenario, $status, string $note = ''): void
{
    $GLOBALS['g']['results'][] = ['tc' => $tc, 'scenario' => $scenario, 'status' => $status, 'note' => $note];
    printf("  [%s] %-7s %s%s\n", $status === true ? 'PASS' : ($status === false ? 'FAIL' : 'SKIP'), $tc, $scenario, $note !== '' ? " -- {$note}" : '');
}
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) <= $eps; }
function bid_of(int $projectId, int $materialId): int
{
    return (int)val("SELECT id FROM budget_items WHERE project_id=:p AND material_id=:m", ['p' => $projectId, 'm' => $materialId]);
}

/** TC-201: release normal Draft -> Diajukan lolos (stok mencukupi kebutuhan bersih). */
function tc201(): void
{
    $g = $GLOBALS['g'];
    $bid1 = bid_of($g['projP1'], $g['matM1']);
    $po = make_po($g['projP1'], [[$bid1, 60]]);   // kebutuhan bersih M1 = 100 - 40 stok = 60
    if (!$po) { record('TC-201', 'release normal Draft->Diajukan lolos', false, 'setup save_po gagal'); return; }
    $r = change_status($po['id'], 'Diajukan');
    $ok = !empty($r['json']['success']);
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
    $ok = $ok && $st === 'Diajukan';
    record('TC-201', 'release normal: Draft -> Diajukan lolos', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? ''), 0, 80));
    del_po($po['id']);
}

/** TC-202: blokir bila stok proyek bertambah sebelum release sehingga qty PO > kebutuhan bersih. */
function tc202(): void
{
    $g = $GLOBALS['g'];
    $bid1 = bid_of($g['projP1'], $g['matM1']);
    // PO qty 60 (kebutuhan bersih saat stok=40). Lalu stok proyek bertambah 20 -> jadi 60.
    $po = make_po($g['projP1'], [[$bid1, 60]]);
    if (!$po) { record('TC-202', 'blokir release bila stok proyek bertambah', false, 'setup save_po gagal'); return; }
    db()->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                   VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 20, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whA'], 'r' => CFG_TAG . '-TAMBAH']);
    // sekarang stok = 60, kebutuhan bersih = 100 - 60 = 40, qty PO 60 > 40 -> release harus ditolak
    $r = change_status($po['id'], 'Diajukan');
    $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'kebutuhan bersih') !== false;
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
    $ok = $ok && $st === 'Draft';
    record('TC-202', 'blokir release: stok proyek bertambah sebelum release (qty PO > kebutuhan bersih)', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 120));
    del_po($po['id']);
    // restore stok
    db()->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                   VALUES (CURRENT_DATE, :i, NULL, :w, 'OUT', 20, 10000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whA'], 'r' => CFG_TAG . '-RESTORE']);
}

/** TC-203: blokir bila kuota RAB habis dipakai PO lain (PO lain Diajukan duluan). */
function tc203(): void
{
    $g = $GLOBALS['g'];
    $bid1 = bid_of($g['projP1'], $g['matM1']);
    $poA = make_po($g['projP1'], [[$bid1, 60]]);
    if (!$poA) { record('TC-203', 'blokir release: kuota RAB habis dipakai PO lain', false, 'setup gagal'); return; }
    $rA = change_status($poA['id'], 'Diajukan');
    $db = db();
    $db->prepare("INSERT INTO purchase_orders (po_number, project_id, supplier_id, po_date, status, currency_id, exch_rate, budget_currency_id, budget_exch_rate, total_base, total_budget, ppn_mode, ppn_rate) VALUES (:no, :p, 1, CURRENT_DATE, 'Draft', 2, 1, 2, 1, 100000, 100000, 'NONE', 0)")->execute(['no' => CFG_TAG . '-POB', 'p' => $g['projP1']]);
    $idB = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO po_items (po_id, budget_item_id, material_id, description, qty, unit_price) VALUES (:p, :b, :m, 'Item PO B E2E', 10, 10000)")->execute(['p' => $idB, 'b' => $bid1, 'm' => $g['matM1']]);
    $poB = ['id' => $idB];
    $rB = change_status($poB['id'], 'Diajukan');
    $ok = !empty($rB['json']['success']) === false && stripos($rB['json']['message'] ?? $rB['raw'], 'kebutuhan bersih') !== false;
    $stB = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $poB['id']]);
    $stA = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $poA['id']]);
    $ok = $ok && $stB === 'Draft' && $stA === 'Diajukan';
    record('TC-203', 'blokir release: kuota RAB habis dipakai PO lain (PO A Diajukan, PO B ditolak)', $ok,
        'A=' . var_export($stA, true) . ' B=' . var_export($stB, true) . ' msgB=' . substr((string)($rB['json']['message'] ?? $rB['raw']), 0, 100));
    del_po($poB['id']); del_po($poA['id']);
}

/** TC-204: blokir double-release (state machine: Diajukan tidak bisa Diajukan lagi). */
function tc204(): void
{
    $g = $GLOBALS['g'];
    $bid2 = bid_of($g['projP1'], $g['matM2']);
    $po = make_po($g['projP1'], [[$bid2, 50, 5000]]);
    if (!$po) { record('TC-204', 'blokir double-release', false, 'setup save_po gagal'); return; }
    change_status($po['id'], 'Diajukan');
    $r2 = change_status($po['id'], 'Diajukan');
    $ok = !empty($r2['json']['success']) === false && stripos($r2['json']['message'] ?? $r2['raw'], 'tidak dapat diubah') !== false;
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
    $ok = $ok && $st === 'Diajukan';
    record('TC-204', 'blokir double-release: Diajukan -> Diajukan ditolak state machine', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r2['json']['message'] ?? $r2['raw']), 0, 80));
    del_po($po['id']);
}

/** TC-205: blokir release bila periode akuntansi tutup. */
function tc205(): void
{
    $g = $GLOBALS['g'];
    // tutup periode berjalan sementara (dibuka kembali di finally agar tidak pernah tertinggal tertutup)
    $bid2 = bid_of($g['projP1'], $g['matM2']);
    $po = make_po($g['projP1'], [[$bid2, 50, 5000]]);
    $per = row("SELECT id, is_closed FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date");
    $wasClosed = $per && ($per['is_closed'] === 't' || $per['is_closed'] === true || $per['is_closed'] === 1 || $per['is_closed'] === '1');
    if ($per && !$wasClosed) {
        db()->prepare("UPDATE accounting_periods SET is_closed = true WHERE id = :id")->execute(['id' => $per['id']]);
    }
    try {
        $r = ['json' => null, 'raw' => 'setup save_po gagal'];
        $st = null; $ok = false;
        if ($po) {
            $r = change_status($po['id'], 'Diajukan');
            $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
            $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'periode akuntansi') !== false;
            $ok = $ok && $st === 'Draft';
        }
    } finally {
        // buka kembali periode (finally: menjamin periode tidak tertinggal tertutup walau assertion error)
        if ($per && !$wasClosed) {
            db()->prepare("UPDATE accounting_periods SET is_closed = false WHERE id = :id")->execute(['id' => $per['id']]);
        }
    }
    if ($po) del_po($po['id']);
    record('TC-205', 'blokir release: periode akuntansi tutup', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 120));
}

/** TC-206: bukti stok proyek lain tidak dipinjam (M1 punya 500 di WH-B proyek lain, P1 tetap butuh beli). */
function tc206(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'poCRD.php', 'list_rab_items', ['project_id' => $g['projP1']]);
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $totalAll = (float)val("SELECT SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) FROM stock_movements WHERE item_id = :i", ['i' => $g['matM1']]);
    $ok = $m1 && approx((float)$m1['qty_on_hand'], 40) && approx((float)$m1['qty_to_buy'], 60) && approx($totalAll, 540);
    record('TC-206', 'stok proyek lain (500 di WH-B) tidak dipinjam; P1 lihat 40 & butuh beli 60', $ok,
        'total_db=' . $totalAll . ' p1_on_hand=' . ($m1 ? $m1['qty_on_hand'] : 'n/a') . ' to_buy=' . ($m1 ? $m1['qty_to_buy'] : 'n/a'));
}

/** TC-207: blokir release bila supplier non-aktif. */
function tc207(): void
{
    $g = $GLOBALS['g'];
    // nonaktifkan supplier 1 sementara
    $bid2 = bid_of($g['projP1'], $g['matM2']);
    $po = make_po($g['projP1'], [[$bid2, 50, 5000]]);
    $wasActive = val("SELECT active FROM m_suppliers WHERE id = 1");
    db()->prepare("UPDATE m_suppliers SET active = false WHERE id = 1")->execute();
    $ok = false; $st = null; $r = ['json' => null, 'raw' => 'setup save_po gagal'];
    if ($po) {
        $r = change_status($po['id'], 'Diajukan');
        $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'supplier') !== false;
        $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
        $ok = $ok && $st === 'Draft';
    }
    db()->prepare("UPDATE m_suppliers SET active = :a WHERE id = 1")->execute(['a' => ($wasActive === true || $wasActive === "t" || $wasActive === "1" || $wasActive === 1 ? "true" : "false")]);
    if ($po) del_po($po['id']);
    record('TC-207', 'blokir release: supplier non-aktif', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 100));
}

/** TC-208: blokir release PO tanpa item. */
function tc208(): void
{
    $g = $GLOBALS['g'];
    // PO tanpa item: insert langsung header (save_po menolak item kosong, jadi bypass via DB)
    $db = db();
    $db->prepare("INSERT INTO purchase_orders (po_number, project_id, supplier_id, po_date, status, notes, currency_id, exch_rate, budget_currency_id, budget_exch_rate, total_base, total_budget, ppn_mode, ppn_rate)
                  VALUES (:no, :p, 1, CURRENT_DATE, 'Draft', NULL, 2, 1, 2, 1, 0, 0, 'NONE', 0) RETURNING id")
        ->execute(['no' => CFG_TAG . '-NOITEM', 'p' => $g['projP1']]);
    $id = (int)$db->lastInsertId();
    $g['created']['po'][] = $id;
    $r = change_status($id, 'Diajukan');
    $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'item') !== false;
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $id]);
    $ok = $ok && $st === 'Draft';
    record('TC-208', 'blokir release: PO tanpa item', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 100));
}

/** TC-209: blokir release bila ada item qty <= 0 atau harga negatif (integritas item). */
function tc209(): void
{
    $g = $GLOBALS['g'];
    $bid2 = bid_of($g['projP1'], $g['matM2']);
    $po = make_po($g['projP1'], [[$bid2, 50, 5000]]);
    if (!$po) { del_po($po['id']); record('TC-209', 'blokir release: integritas item (qty 0 / harga negatif)', false, 'setup save_po gagal'); return; }
    // rusakkan satu baris item: qty = 0
    db()->prepare("UPDATE po_items SET qty = 0 WHERE po_id = :i")->execute(['i' => $po['id']]);
    $r = change_status($po['id'], 'Diajukan');
    $ok = !empty($r['json']['success']) === false && stripos($r['json']['message'] ?? $r['raw'], 'qty') !== false;
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
    $ok = $ok && $st === 'Draft';
    // perbaiki lagi utk tidak mengganggu test lain
    db()->prepare("UPDATE po_items SET qty = 50 WHERE po_id = :i")->execute(['i' => $po['id']]);
    del_po($po['id']); record('TC-209', 'blokir release: integritas item (qty = 0)', $ok,
        'status=' . var_export($st, true) . ' msg=' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 100));
}

/** TC-210: alur lengkap Draft -> Diajukan -> Disetujui lolos (validasi berlapis). */
function tc210(): void
{
    $g = $GLOBALS['g'];
    $bid2 = bid_of($g['projP1'], $g['matM2']);
    $po = make_po($g['projP1'], [[$bid2, 50, 5000]]);
    if (!$po) { record('TC-210', 'alur lengkap Draft->Diajukan->Disetujui lolos', false, 'setup save_po gagal'); return; }
    $r1 = change_status($po['id'], 'Diajukan');
    $r2 = change_status($po['id'], 'Disetujui');
    $st = val("SELECT status FROM purchase_orders WHERE id = :i", ['i' => $po['id']]);
    $ok = !empty($r1['json']['success']) && !empty($r2['json']['success']) && $st === 'Disetujui';
    record('TC-210', 'alur lengkap: Draft -> Diajukan -> Disetujui lolos validasi real-time', $ok,
        'status=' . var_export($st, true) . ' msg1=' . substr((string)($r1['json']['message'] ?? ''), 0, 40) . ' msg2=' . substr((string)($r2['json']['message'] ?? ''), 0, 40));
}

/* ---------------- Laporan & main ---------------- */
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
    $header = "# Test Report Pengecekan PO Sebelum Release (TC-201 s/d TC-210)\n\n";
    $header .= "**Tanggal eksekusi:** " . date('Y-m-d H:i:s') . "  \n";
    $header .= "**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `" . CFG_BASE_URL . "`  \n";
    $header .= "**Dokumen acuan:** `docs/HOWTO_PO_PRERELEASE_CHECK_2026-11-15.md`  \n";
    $header .= "**Ringkasan sementara:** PASS = {$pass}, FAIL = {$fail}, SKIP = {$skip}\n\n";
    $header .= "> Fitur: validasi real-time saat release PO (`change_status` ke Diajukan/Disetujui) di dalam\n";
    $header .= "> transaksi ACID: baca ulang `GetProjectStockMap()` & `po_net_need()`, blokir bila stok proyek\n";
    $header .= "> bertambah / kuota RAB habis / periode tutup / supplier non-aktif / item tidak valid.\n";
    $header .= "> Stok milik proyek lain (`warehouses.project_id <> proyek ini`) tidak pernah dihitung.\n\n";
    $out = preg_replace('/^# Test Report Pengecekan PO Sebelum Release.*?(?=\n## |\n<!-- BEGIN:)/s', '', $out);
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

    echo "=== E2E Pengecekan PO Sebelum Release (TC-201 s/d TC-210) ===\n";
    echo "DB: " . CFG_DB_NAME . " @ " . CFG_DB_HOST . " | URL: " . CFG_BASE_URL . "\n";

    if ($doSetup) {
        echo "[setup] membuat data uji (P1+P9, M1/M2, WH-A/WH-B, stok 40 vs 500, user admin)...\n";
        setup_test_data();
        echo "[setup] selesai. projP1={$GLOBALS['g']['projP1']} projOther={$GLOBALS['g']['projOther']} matM1={$GLOBALS['g']['matM1']} matM2={$GLOBALS['g']['matM2']} whA={$GLOBALS['g']['whA']} whB={$GLOBALS['g']['whB']}\n";
    }

    $groupPre = 'Kelompok G — Pengecekan PO Sebelum Release (validasi real-time)';
    $testsG = [
        'tc201' => 'TC-201', 'tc202' => 'TC-202', 'tc203' => 'TC-203', 'tc204' => 'TC-204', 'tc205' => 'TC-205',
        'tc206' => 'TC-206', 'tc207' => 'TC-207', 'tc208' => 'TC-208', 'tc209' => 'TC-209', 'tc210' => 'TC-210',
    ];
    if ($group && $group !== 'G') { echo "[run] Kelompok G dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok G:\n";
        foreach ($testsG as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupPre);
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
    echo "\nHasil Keseluruhan (G): PASS={$p} FAIL={$f} | laporan: " . CFG_REPORT . "\n";
    exit($f > 0 ? 1 : 0);
}

if (!defined('E2E_AS_LIB')) {
    main($argv);
}
