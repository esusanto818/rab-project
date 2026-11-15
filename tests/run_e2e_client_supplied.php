<?php
/**
 * tests/run_e2e_client_supplied.php
 *
 * Runner E2E fitur "Material Titipan Klien (CLIENT_SUPPLIED) Rp 0" (patch gelombang 2, item F-4.1):
 *   - rabCRD.php            : menyimpan item RAB ber-material_source='CLIENT_SUPPLIED' (kolom
 *                             budget_items.material_source, migrasi sql/2026-11-15_patch_gelombang2_client_supplied.sql).
 *   - purchase.planningCRD  : load_planning & check_inventory mengembalikan qty_to_buy = 0 untuk
 *                             CLIENT_SUPPLIED (skip total, bukan GREATEST) + flag material_source.
 *   - poCRD.php             : list_rab_items menampilkan qty_to_buy = 0 & material_source;
 *                             save_po MENOLAK budget_item CLIENT_SUPPLIED (kebutuhan bersih = 0).
 *   - grnCRD.php            : unit_cost DIPAKSA 0 server-side untuk item CLIENT_SUPPLIED.
 *   - whrcvCRD.php          : penerimaan fisik Rp 0 masuk stock_movements IN tanpa jurnal GRN_RCV.
 *   - warehouse.issuingCRD  : issue stok Rp 0 OUT tanpa jurnal ISSUE_PROJECT.
 *
 * Data uji (semua ditandai CFG_TAG dan dibersihkan di cleanup):
 *   - Proyek P1 dengan RAB material M1 (CLIENT_SUPPLIED) & M2 (COMPANY, sebagai kontrol).
 *   - Gudang WH-P1 milik P1 untuk WH Receiving + Issuing.
 *   - Alur: RAB -> (tolak) PO -> GRN dari PO Dummy COMPANY -> WH Rcv -> Issuing.
 *     GRN dibuat dari PO M2 (COMPANY) karena save_po menolak CLIENT_SUPPLIED; biaya Rp 0
 *     untuk M1 tetap dipaksa server-side lewat budget_items.material_source pada PO dummy.
 *
 * Pakai: php tests/run_e2e_client_supplied.php [--setup|--cleanup] [--keep] [--only=TC-CS-01,TC-CS-03]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi (sama dg run_e2e_inventory_check.php) ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_REPORT   = __DIR__ . '/../test_report_client_supplied.md';
const CFG_TAG      = 'E2E-CS';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';

/* ---------------- State global ---------------- */
$g = [
    'db' => null,
    'projP1' => 0,
    'matM1' => 0, 'matM2' => 0,
    'biM1' => 0, 'biM2' => 0,          // budget_items id (M1 = CLIENT_SUPPLIED, M2 = COMPANY)
    'whP1' => 0,
    'poDummy' => 0, 'poItemM1' => 0,   // PO dummy COMPANY utk alur GRN (dibersihkan di cleanup)
    'grnHdr' => 0, 'grnDtl' => 0,
    'whrcvHdr' => 0,
    'issHdr' => 0,
    'user_admin' => 0,
    'created' => ['project' => [], 'material' => [], 'warehouse' => [], 'user' => [], 'po' => [], 'grn' => [], 'whrcv' => [], 'issue' => []],
    'results' => [],
];

/* ---------------- Konfigurasi DB ---------------- */
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
function csval(string $sql, array $p = [])
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
        CURLOPT_COOKIEFILE     => '/tmp/e2e_cs_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_cs_cookie_' . $userId,
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
    $jar = '/tmp/e2e_cs_cookie_' . $userId;
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
    // form 138 = PURCHASE PLANNING, form 24 = PURCHASE ORDER,
    // form 137 = GOODS RECEIPT NOTE, form 134 = WAREHOUSE RECEIVED, form 144 = WAREHOUSE ISSUE
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 138, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 24, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 137, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 134, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 144, :lv)")->execute(['uid' => $uid, 'lv' => $level]);
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
            // [FIX-FK] urutan FK: wh_receiving_dtl -> grn_dtl -> po_items; wh_receiving_hdr -> grn_hdr -> po_id
            foreach (rows("SELECT id FROM grn_hdr WHERE po_id = :i", ['i' => $po['id']]) as $gh) {
                foreach (rows("SELECT id FROM wh_receiving_hdr WHERE grn_hdr_id = :g", ['g' => $gh['id']]) as $wr) {
                    $db->prepare("DELETE FROM wh_receiving_dtl WHERE wh_receiving_hdr_id = :x")->execute(['x' => $wr['id']]);
                    $db->prepare("DELETE FROM wh_receiving_hdr WHERE id = :x")->execute(['x' => $wr['id']]);
                }
                $db->prepare("DELETE FROM wh_receiving_dtl WHERE grn_dtl_id IN (SELECT id FROM grn_dtl WHERE grn_hdr_id = :x)")->execute(['x' => $gh['id']]);
                $db->prepare("DELETE FROM grn_dtl WHERE grn_hdr_id = :x")->execute(['x' => $gh['id']]);
                $db->prepare("DELETE FROM grn_hdr WHERE id = :x")->execute(['x' => $gh['id']]);
            }
            $db->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(['i' => $po['id']]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(['i' => $po['id']]);
        }
        $db->prepare("DELETE FROM budget_items WHERE project_id = :p")->execute(['p' => $pid]);
    $db->prepare("DELETE FROM material_out_items WHERE material_out_id IN (SELECT id FROM material_out_header WHERE project_id IN (SELECT id FROM projects WHERE id = :p))")->execute(['p' => $pid]);
    $db->prepare("DELETE FROM material_out_header WHERE project_id IN (SELECT id FROM projects WHERE id = :p)")->execute(['p' => $pid]);
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

    // Proyek uji P1 (progress 0 agar validateMaterialOutAgainstProgress tidak membatasi issue Rp 0)
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P1 " . CFG_TAG . "', 1, 'Lokasi Uji', CURRENT_DATE, CURRENT_DATE + 30, 500000000, 0, 0, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projP1'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projP1'];

    // Material uji: M1 = titipan klien, M2 = kontrol perusahaan
    $g['matM1'] = ensure_material(CFG_TAG . '-M1', 'Baut Titipan ' . CFG_TAG, 'Buah');
    $g['matM2'] = ensure_material(CFG_TAG . '-M2', 'Mur Kontrol ' . CFG_TAG, 'Buah');
    $g['created']['material'] = [$g['matM1'], $g['matM2']];

    // RAB P1: M1 CLIENT_SUPPLIED (qty 100, harga plan 0), M2 COMPANY (qty 50, harga plan 5000)
    $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan, material_source)
                  VALUES (:p, 'Material', :m, :nm, :u, :q, 0, 'CLIENT_SUPPLIED')")
        ->execute(['p' => $g['projP1'], 'm' => $g['matM1'], 'nm' => 'Baut Titipan ' . CFG_TAG, 'u' => 'Buah', 'q' => 100]);
    $g['biM1'] = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan, material_source)
                  VALUES (:p, 'Material', :m, :nm, :u, :q, 5000, 'COMPANY')")
        ->execute(['p' => $g['projP1'], 'm' => $g['matM2'], 'nm' => 'Mur Kontrol ' . CFG_TAG, 'u' => 'Buah', 'q' => 50]);
    $g['biM2'] = (int)$db->lastInsertId();

    // Gudang WH-P1 milik P1 (penerimaan fisik + issuing)
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-P1-' . CFG_TAG, 'n' => 'Gudang P1 ' . CFG_TAG, 'p' => $g['projP1']]);
    $g['whP1'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whP1'];

    // PO dummy berisi M1 (COMPANY) sebagai kendaraan GRN: GRN dipaksa unit_cost 0 server-side
    // karena pi.budget_item_id -> budget_items.material_source = 'CLIENT_SUPPLIED'.
    $db->prepare("INSERT INTO purchase_orders (po_number, po_date, project_id, supplier_id, status, currency_id, exch_rate, ppn_mode, ppn_rate)
                  VALUES (:no, CURRENT_DATE, :p, 1, 'Disetujui', 2, 1, 'NONE', 0) RETURNING id")
        ->execute(['no' => CFG_TAG . '-DUMMY', 'p' => $g['projP1']]);
    $g['poDummy'] = (int)$db->lastInsertId(); $g['created']['po'][] = $g['poDummy'];
    $db->prepare("INSERT INTO po_items (po_id, budget_item_id, material_id, description, unit, qty, unit_price, currency_id, exch_rate)
                  VALUES (:po, :bid, :m, :d, 'Buah', 100, 0, 2, 1) RETURNING id")
        ->execute(['po' => $g['poDummy'], 'bid' => $g['biM1'], 'm' => $g['matM1'], 'd' => 'Baut Titipan ' . CFG_TAG]);
    $g['poItemM1'] = (int)$db->lastInsertId();

    // User admin + login (FormAccess level 4 utk form 24 & 138)
    $g['user_admin'] = ensure_user(4, 'admin');
    $uname = csval("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g['user_admin']]);
    if (!login($g['user_admin'], $uname, $uname . '-pass')) { fwrite(STDERR, "[SETUP] login gagal\n"); }
}

function cleanup_test_data(): void
{
    $g = $GLOBALS['g'];
    $db = db();
    $db->beginTransaction();
    try {
        foreach ($g['created']['issue'] as $id) {
            $db->prepare("DELETE FROM material_out_items WHERE material_out_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM material_out_header WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['whrcv'] as $id) {
            $db->prepare("DELETE FROM wh_receiving_dtl WHERE wh_receiving_hdr_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM wh_receiving_hdr WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['grn'] as $id) {
            $db->prepare("DELETE FROM wh_receiving_dtl WHERE grn_dtl_id IN (SELECT id FROM grn_dtl WHERE grn_hdr_id = :i)")->execute(['i' => $id]);
            $db->prepare("DELETE FROM wh_receiving_hdr WHERE id NOT IN (SELECT DISTINCT wh_receiving_hdr_id FROM wh_receiving_dtl WHERE wh_receiving_hdr_id IS NOT NULL)")->execute();
            $db->prepare("DELETE FROM grn_dtl WHERE grn_hdr_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM grn_hdr WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['po'] as $poId) {
            $db->prepare("DELETE FROM po_items WHERE po_id = :i")->execute(['i' => $poId]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :i")->execute(['i' => $poId]);
        }
        foreach ($g['created']['project'] as $pid) {
            $db->prepare("DELETE FROM budget_items WHERE project_id = :p")->execute(['p' => $pid]);
    $db->prepare("DELETE FROM material_out_items WHERE material_out_id IN (SELECT id FROM material_out_header WHERE project_id IN (SELECT id FROM projects WHERE id = :p))")->execute(['p' => $pid]);
    $db->prepare("DELETE FROM material_out_header WHERE project_id IN (SELECT id FROM projects WHERE id = :p)")->execute(['p' => $pid]);
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

/* ============================================================
 * 4. TEST CASES
 * ==========================================================*/
function record(string $tc, string $scenario, $status, string $note = ''): void
{
    $GLOBALS['g']['results'][] = ['tc' => $tc, 'scenario' => $scenario, 'status' => $status, 'note' => $note];
    printf("  [%s] %-9s %s%s\n", $status === true ? 'PASS' : ($status === false ? 'FAIL' : 'SKIP'), $tc, $scenario, $note !== '' ? " -- {$note}" : '');
}
function approx(float $a, float $b, float $eps = 0.001): bool { return abs($a - $b) <= $eps; }

/** TC-CS-01: item RAB CLIENT_SUPPLIED tersimpan di budget_items.material_source. */
function tc01(): void
{
    $g = $GLOBALS['g'];
    $src = csval("SELECT material_source FROM budget_items WHERE id = :i", ['i' => $g['biM1']]);
    $ok  = strtoupper((string)$src) === 'CLIENT_SUPPLIED';
    record('TC-CS-01', 'RAB tersimpan: budget_items.material_source = CLIENT_SUPPLIED', $ok, 'material_source=' . var_export($src, true));
}

/** TC-CS-02: RAB non-titipan tetap COMPANY (kontrol). */
function tc02(): void
{
    $g = $GLOBALS['g'];
    $src = csval("SELECT material_source FROM budget_items WHERE id = :i", ['i' => $g['biM2']]);
    $ok  = strtoupper((string)$src) === 'COMPANY';
    record('TC-CS-02', 'RAB kontrol: material_source = COMPANY (default)', $ok, 'material_source=' . var_export($src, true));
}

/** TC-CS-03: load_planning mengembalikan qty_to_buy = 0 untuk CLIENT_SUPPLIED. */
function tc03(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'load_planning', ['project_id' => $g['projP1']]);
    if (empty($r['json']['success'])) { record('TC-CS-03', 'load_planning berhasil', false, 'http=' . $r['http'] . ' raw=' . substr($r['raw'], 0, 160)); return; }
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $ok = $m1 && approx((float)$m1['qty_to_buy'], 0) && strtoupper((string)($m1['material_source'] ?? '')) === 'CLIENT_SUPPLIED';
    record('TC-CS-03', 'load_planning: CLIENT_SUPPLIED qty_to_buy = 0 & flag material_source tampil', $ok,
        $m1 ? ('to_buy=' . $m1['qty_to_buy'] . ' src=' . $m1['material_source']) : 'M1 tidak ada');
}

/** TC-CS-04: load_planning untuk COMPANY tetap menghitung kebutuhan beli normal. */
function tc04(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'load_planning', ['project_id' => $g['projP1']]);
    $byMat = [];
    foreach ($r['json']['data'] ?? [] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m2 = $byMat[$g['matM2']] ?? null;
    $ok = $m2 && approx((float)$m2['qty_to_buy'], 50) && strtoupper((string)($m2['material_source'] ?? '')) === 'COMPANY';
    record('TC-CS-04', 'load_planning: COMPANY (M2) qty_to_buy = 50 normal (bukan 0)', $ok,
        $m2 ? ('to_buy=' . $m2['qty_to_buy'] . ' src=' . $m2['material_source']) : 'M2 tidak ada');
}

/** TC-CS-05: list_rab_items di poCRD menampilkan qty_to_buy = 0 & material_source CLIENT_SUPPLIED. */
function tc05(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'poCRD.php', 'list_rab_items', ['project_id' => $g['projP1']]);
    if (empty($r['json']['success'])) { record('TC-CS-05', 'list_rab_items berhasil', false, substr($r['raw'], 0, 160)); return; }
    $byMat = [];
    foreach ($r['json']['data'] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $ok = $m1 && approx((float)$m1['qty_to_buy'], 0) && strtoupper((string)$m1['material_source']) === 'CLIENT_SUPPLIED';
    record('TC-CS-05', 'poCRD list_rab_items: M1 qty_to_buy = 0 + material_source = CLIENT_SUPPLIED', $ok,
        $m1 ? ('to_buy=' . $m1['qty_to_buy'] . ' src=' . $m1['material_source']) : 'M1 tidak ada');
}

/** TC-CS-06: save_po MENOLAK budget_item CLIENT_SUPPLIED (kebutuhan bersih = 0). */
function tc06(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $g['projP1'], 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode([['budget_item_id' => $g['biM1'], 'qty' => 10, 'unit_price' => 0]]),
    ]);
    $ok = empty($r['json']['success']);
    $msg = (string)($r['json']['message'] ?? $r['raw']);
    record('TC-CS-06', 'save_po: budget_item CLIENT_SUPPLIED DITOLAK (tidak boleh masuk PO pembelian)', $ok, substr($msg, 0, 140));
}

/** TC-CS-07: save_po menerima budget_item COMPANY (M2) sebagai kontrol. */
function tc07(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'poCRD.php', 'save_po', [
        'project_id' => $g['projP1'], 'supplier_id' => 1, 'po_date' => date('Y-m-d'),
        'currency_id' => 2, 'exch_rate' => 1, 'ppn_mode' => 'NONE', 'ppn_rate' => 0,
        'items' => json_encode([['budget_item_id' => $g['biM2'], 'qty' => 10, 'unit_price' => 5000]]),
    ]);
    $ok = !empty($r['json']['success']);
    $poId = $ok ? (int)$r['json']['data']['id'] : 0;
    if ($poId) { $g['created']['po'][] = $poId; }
    record('TC-CS-07', 'save_po: COMPANY (M2) qty 10 diterima (alur normal tidak terganggu)', $ok,
        $ok ? ('po_number=' . $r['json']['data']['po_number']) : substr((string)($r['json']['message'] ?? $r['raw']), 0, 140));
}

/** TC-CS-08: GRN untuk PO titipan klien memaksa unit_cost = 0 server-side. */
function tc08(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'grnCRD.php', 'create', [
        'po_id' => $g['poDummy'], 'supplier_id' => 1, 'grn_date' => date('Y-m-d'),
        'vehicle_plate_no' => 'B 1234 CS', 'driver_name' => 'Sopir Uji', 'supplier_do_no' => 'DO-CS-01',
        'arrItems' => json_encode([[
            'po_dtl_id' => $g['poItemM1'], 'item_id' => $g['matM1'], 'item_name' => 'Baut Titipan ' . CFG_TAG,
            'qty_received' => 100, 'unit_cost' => 12345,           // <-- dikirim > 0, harus dipaksa 0
            'remark' => 'GRN titipan klien uji'
        ]]),
    ]);
    $ok = !empty($r['json']['success']);
    // [FIX] grnCRD create mengembalikan ref_no (bukan id) -> cari id dari ref_no
    $grnRef = (string)($r['json']['ref_no'] ?? '');
    $grnId = $ok ? (int)csval("SELECT id FROM grn_hdr WHERE ref_no = :n", ['n' => $grnRef]) : 0;
    if ($grnId) { $GLOBALS['g']['grnHdr'] = $grnId; $GLOBALS['g']['created']['grn'][] = $grnId; }   // [FIX] tulis langsung ke $GLOBALS (copy lokal tidak bertahan lintas function)
    $cost = $grnId ? csval("SELECT unit_cost FROM grn_dtl WHERE grn_hdr_id = :h", ['h' => $grnId]) : null;
    $ok   = $ok && $cost !== null && approx((float)$cost, 0);
    record('TC-CS-08', 'grnCRD create: unit_cost DIPAKSA 0 untuk CLIENT_SUPPLIED (kiriman 12345 diabaikan)', $ok,
        'unit_cost_tersimpan=' . var_export($cost, true) . ' | ' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 80));
}

/** TC-CS-09: WH Receiving Rp 0 -> stock_movements IN tercatat, TANPA jurnal GRN_RCV. */
function tc09(): void
{
    $g = $GLOBALS['g'];
    $gd = (int)csval("SELECT id FROM grn_dtl WHERE grn_hdr_id = :h", ['h' => $g['grnHdr']]);
    $dbgItem = csval("SELECT item_id FROM grn_dtl WHERE id = :d", ['d' => $gd]);
    $r = api($g['user_admin'], 'whrcvCRD.php', 'create', [
        'grn_hdr_id' => $g['grnHdr'], 'warehouse_id' => $g['whP1'], 'received_date' => date('Y-m-d'),
        'items' => json_encode([[
            'grn_dtl_id' => $gd, 'grn_hdr_id' => $g['grnHdr'], 'item_id' => $g['matM1'],
            'item_name' => 'Baut Titipan ' . CFG_TAG, 'qty_received' => 100, 'unit_cost' => 0
        ]]),
    ]);
    $ok = !empty($r['json']['success']);
    $whId = $ok ? (int)$r['json']['id'] : 0;
    if ($whId) { $g['whrcvHdr'] = $whId; $g['created']['whrcv'][] = $whId; }

    $mv = $whId ? row("SELECT movement_type, qty, unit_cost FROM stock_movements
                        WHERE reference_type = 'wh_receiving' AND reference_id = :i ORDER BY id DESC LIMIT 1", ['i' => $whId]) : null;
    $jn = $whId ? (int)csval("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'wh_receiving' AND reference_id = :i", ['i' => $whId]) : -1;
    $ok = $ok && $mv && $mv['movement_type'] === 'IN' && approx((float)$mv['qty'], 100) && approx((float)$mv['unit_cost'], 0) && $jn === 0;
    record('TC-CS-09', 'whrcvCRD: fisik IN 100 @ Rp 0 di stock_movements + TANPA jurnal GRN_RCV', $ok,
        'mv=' . ($mv ? ($mv['movement_type'] . ' qty=' . $mv['qty'] . ' cost=' . $mv['unit_cost']) : 'null') . " jurnal_rows={$jn}");
}

/** TC-CS-10: Issuing Rp 0 -> stock_movements OUT + material_out_items @ Rp 0, TANPA jurnal ISSUE_PROJECT. */
function tc10(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'warehouse.issuingCRD.php', 'save', [
        'action' => 'save',   // issuingCRD memakai $_REQUEST['action'], bukan 'task'
        'project_id' => $g['projP1'], 'warehouse_id' => $g['whP1'], 'issue_date' => date('Y-m-d'),
        'issue_type' => 'PROJECT', 'received_by_mandor' => 'Mandor Uji', 'remark' => 'Issue titipan klien',
        'details' => json_encode([[
            'material_id' => $g['matM1'], 'qty_out' => 30, 'notes' => 'Issue CLIENT_SUPPLIED Rp 0'
        ]]),
    ]);
    $ok = !empty($r['json']['success']);
    $issId = $ok ? (int)($r['json']['data']['id'] ?? $r['json']['id'] ?? 0) : 0;
    if ($issId) { $g['issHdr'] = $issId; $g['created']['issue'][] = $issId; }

    $mv = $issId ? row("SELECT movement_type, qty, unit_cost FROM stock_movements
                         WHERE item_id = :m AND warehouse_id = :w AND movement_type = 'OUT' ORDER BY id DESC LIMIT 1", ['m' => $g['matM1'], 'w' => $g['whP1']]) : null;
    $it = $issId ? row("SELECT qty_out, unit_cost FROM material_out_items WHERE material_out_id = :i ORDER BY id LIMIT 1", ['i' => $issId]) : null;
    $jn = $issId ? (int)csval("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'material_out' AND reference_id = :i", ['i' => $issId]) : -1;
    $saldo = (float)csval("SELECT COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END),0) FROM stock_movements
                          WHERE item_id = :m AND warehouse_id = :w", ['m' => $g['matM1'], 'w' => $g['whP1']]);
    $ok = $ok && $mv && $mv['movement_type'] === 'OUT' && approx((float)$mv['qty'], 30) && approx((float)$mv['unit_cost'], 0)
        && $it && approx((float)$it['unit_cost'], 0) && $jn === 0 && approx($saldo, 70);
    record('TC-CS-10', 'issuing: OUT 30 @ Rp 0 (saldo 70) + material_out_items Rp 0 + TANPA jurnal ISSUE_PROJECT', $ok,
        'mv=' . ($mv ? ($mv['movement_type'] . ' qty=' . $mv['qty'] . ' cost=' . $mv['unit_cost']) : 'null')
        . " item_cost=" . ($it ? $it['unit_cost'] : 'n/a') . " jurnal_rows={$jn} saldo={$saldo}");
}

/** TC-CS-11: stok gudang proyek lain TIDAK dipakai untuk menutupi kebutuhan CLIENT_SUPPLIED. */
function tc11(): void
{
    $g = $GLOBALS['g'];
    // Setelah OUT 30, sisa stok P1 = 70. load_planning CLIENT_SUPPLIED tetap to_buy = 0 (bukan -70).
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'load_planning', ['project_id' => $g['projP1']]);
    $byMat = [];
    foreach ($r['json']['data'] ?? [] as $row) { $byMat[(int)$row['material_id']] = $row; }
    $m1 = $byMat[$g['matM1']] ?? null;
    $ok = $m1 && approx((float)$m1['qty_to_buy'], 0) && approx((float)$m1['qty_on_hand'], 70);
    record('TC-CS-11', 'load_planning pasca-issue: CLIENT_SUPPLIED to_buy tetap 0 (stok fisik 70 tidak mengubah kebutuhan beli)', $ok,
        $m1 ? ('to_buy=' . $m1['qty_to_buy'] . ' on_hand=' . $m1['qty_on_hand']) : 'M1 tidak ada');
}

/** TC-CS-12: check_inventory menandai CLIENT_SUPPLIED "tidak dibeli, qty_to_buy = 0". */
function tc12(): void
{
    $g = $GLOBALS['g'];
    $items = json_encode([['rab_material_id' => $g['biM1'], 'material_id' => $g['matM1'], 'qty' => 10]]);
    $r = api($g['user_admin'], 'purchase.planningCRD.php', 'check_inventory', ['project_id' => $g['projP1'], 'items' => $items]);
    $ok = !empty($r['json']['success']);
    $msg = ''; $toBuy = null;
    if ($ok) {
        $d = $r['json']['data'][0] ?? null;
        $toBuy = $d ? (float)$d['qty_to_buy'] : null;
        $msg = $d ? (string)$d['message'] : '';
        $ok  = $d && approx((float)$d['qty_to_buy'], 0) && stripos($msg, 'titipan klien') !== false;
    } else {
        $msg = substr($r['raw'], 0, 160);
    }
    record('TC-CS-12', 'check_inventory: baris CLIENT_SUPPLIED -> qty_to_buy 0 + pesan "titipan klien"', $ok, "to_buy={$toBuy} msg=" . substr($msg, 0, 90));
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
    $header = "# Test Report Material Titipan Klien (CLIENT_SUPPLIED) Rp 0 (TC-CS-01 s/d TC-CS-12)\n\n";
    $header .= "**Tanggal eksekusi:** " . date('Y-m-d H:i:s') . "  \n";
    $header .= "**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `" . CFG_BASE_URL . "`  \n";
    $header .= "**Dokumen acuan:** `tests/run_e2e_client_supplied.php`  \n";
    $header .= "**Ringkasan sementara:** PASS = {$pass}, FAIL = {$fail}, SKIP = {$skip}\n\n";
    $header .= "> Fitur: material titipan klien (budget_items.material_source = 'CLIENT_SUPPLIED') Rp 0.\n";
    $header .= "> qty_to_buy wajib 0 di Planning/PO, save_po menolak, GRN/WH Rcv memaksa biaya 0,\n";
    $header .= "> fisik masuk/keluar stok tercatat di stock_movements tanpa jurnal rupiah.\n\n";
    $out = preg_replace('/^# Test Report Material Titipan.*?(?=\n## |\n<!-- BEGIN:)/s', '', $out);
    $out = $header . ltrim($out, "\n");
    file_put_contents($path, $out);
}

function main(array $argv): void
{
    $opts = $argv;
    $only = [];
    $doSetup = true; $doCleanup = true;
    foreach ($opts as $a) {
        if (strpos($a, '--only=') === 0) { $only = array_map('trim', explode(',', substr($a, 7))); }
        if ($a === '--setup') { $doCleanup = false; }
        if ($a === '--cleanup') { $doSetup = false; }
        if ($a === '--keep') { $doCleanup = false; }
    }

    echo "=== E2E Material Titipan Klien CLIENT_SUPPLIED Rp 0 (TC-CS-01 s/d TC-CS-12) ===\n";
    echo "DB: " . CFG_DB_NAME . " @ " . CFG_DB_HOST . " | URL: " . CFG_BASE_URL . "\n";

    if ($doSetup) {
        echo "[setup] membuat data uji (P1, M1 titipan klien + M2 kontrol, WH-P1, PO dummy, user admin)...\n";
        setup_test_data();
        echo "[setup] selesai. projP1={$GLOBALS['g']['projP1']} matM1={$GLOBALS['g']['matM1']} matM2={$GLOBALS['g']['matM2']} whP1={$GLOBALS['g']['whP1']} biM1={$GLOBALS['g']['biM1']}\n";
    }

    $groupCs = 'Kelompok CS — Material Titipan Klien (CLIENT_SUPPLIED) Rp 0';
    $tests = [
        'tc01' => 'TC-CS-01', 'tc02' => 'TC-CS-02', 'tc03' => 'TC-CS-03', 'tc04' => 'TC-CS-04',
        'tc05' => 'TC-CS-05', 'tc06' => 'TC-CS-06', 'tc07' => 'TC-CS-07', 'tc08' => 'TC-CS-08',
        'tc09' => 'TC-CS-09', 'tc10' => 'TC-CS-10', 'tc11' => 'TC-CS-11', 'tc12' => 'TC-CS-12',
    ];
    echo "[run] eksekusi Kelompok CS:\n";
    foreach ($tests as $fn => $tc) {
        if ($only && !in_array($tc, $only, true)) { continue; }
        $fn();
    }
    write_report($GLOBALS['g']['results'], $groupCs);

    if ($doCleanup) {
        echo "[cleanup] menghapus data uji...\n";
        cleanup_test_data();
        echo "[cleanup] selesai.\n";
    } else {
        echo "[cleanup] DILEWATI (--keep/--setup) -- data uji tetap di DB untuk investigasi.\n";
    }

    $p = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === true));
    $f = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === false));
    echo "\nHasil Keseluruhan (CS): PASS={$p} FAIL={$f} | laporan: " . CFG_REPORT . "\n";
    exit($f > 0 ? 1 : 0);
}

if (!defined('E2E_AS_LIB')) {
    main($argv);
}
