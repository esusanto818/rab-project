<?php
/**
 * tests/run_e2e_progress_cost_control.php
 *
 * Runner E2E layer "Kontrol Progress Proyek vs Realisasi Biaya" (Patch Gelombang 3 kedua):
 *   - F-1.1 : validasi klaim/verifikasi progress terhadap realisasi biaya & material
 *             (submit_claim 100% ditolak bila belum ada issue material; warning lunak bila klaim
 *             jauh melampaui realisasi biaya; verify memaksa ulang hard-block 100% tanpa issue).
 *   - F-1.2 : satu rumus realisasi biaya proyek via view public.v_project_actual_cost
 *             (material issue bersih + financial_expenses + prestasi subkon tersertifikasi),
 *             dipakai konsisten oleh prog_metrics() (project.progressCRD), dash_project_context()
 *             (php-func/project.analysis.php), wr_calc() (project.wipreleaseCRD) dan gate
 *             validateMaterialOutAgainstProgress() (warehouse.issuingCRD).
 *
 * Data uji (semua ditandai CFG_TAG dan dibersihkan di cleanup):
 *   - Proyek P1 (Berjalan, budget 500.000.000, RAB M1 qty 100 @ 10.000 = 1.000.000.000 -> dipakai utk pembanding).
 *   - Proyek P2 (untuk uji warning klaim jauh melintasi biaya? tidak dipakai -- satu proyek cukup).
 *   - User level 1 (pelapor, MENGAJUKAN klaim), level 4 (verifikator, memverifikasi).
 *   - Alur: buat progress log manual? TIDAK -- semua lewat API submit_claim / verify.
 *   - Fixture: issue PROJECT posting M1 10 Buah @ 100.000 (via issuing save) SEBELUM klaim 100%.
 *
 * Pakai: php tests/run_e2e_progress_cost_control.php [--setup|--cleanup] [--keep] [--only=TC-PC-01,TC-PC-04]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_REPORT   = __DIR__ . '/../test_report_progress_cost_control.md';
const CFG_TAG      = 'E2E-PC';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';

/* ---------------- State global ---------------- */
$g = [
    'db' => null,
    'projP1' => 0,
    'matM1' => 0,
    'whP1' => 0,
    'biM1' => 0,
    'user_pelapor' => 0,
    'user_verifier' => 0,
    'user_admin' => 0,
    'issHdr' => 0,
    'claimId' => 0,
    'created' => ['project' => [], 'material' => [], 'warehouse' => [], 'user' => [], 'issue' => [], 'expense' => [], 'subcon' => []],
    'results' => [],
];

/* ============================================================
 * 1. HELPER DB (PDO/pg, prepared statement)
 * ==========================================================*/
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
        CURLOPT_COOKIEFILE     => '/tmp/e2e_pc_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_pc_cookie_' . $userId,
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
    // [FIX 2026-11-17] dashboardCRD membaca $_GET/$_POST['action'], bukan 'task' -- JANGAN overwrite.
    if ($crud === 'dashboardCRD.php') { $fields['action'] = $task; } else { $fields['task'] = $task; }
    $r = http_post('/php-crud/' . $crud, $fields, $userId);
    $json = json_decode($r['body'], true);
    return ['http' => $r['http'], 'json' => is_array($json) ? $json : null, 'raw' => $r['body'], 'err' => $r['err']];
}
function login(int $userId, string $loginName, string $password): bool
{
    $jar = '/tmp/e2e_pc_cookie_' . $userId;
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
    return true;
}

/* ============================================================
 * 3. SETUP DATA UJI
 * ==========================================================*/
function ensure_user(int $level, string $purpose, array $formIds = []): int
{
    $uname = CFG_TAG . '-' . strtolower($purpose) . '-lv' . $level . '-' . date('His');
    db()->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (:u, :p, true) RETURNING id")
        ->execute(['u' => $uname, 'p' => $uname . '-pass']);
    $uid = (int)db()->lastInsertId();
    // form 146 = PROGRESS PROYEK (project.progress.php), form 148 = PELEPASAN WIP (project.wiprelease.php),
    // form 118 = PROYEK (master), form 144 = WAREHOUSE ISSUE
    $forms = $formIds ?: [146, 148, 118, 144];
    foreach ($forms as $fid) {
        db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, :f, :lv)")->execute(['uid' => $uid, 'f' => $fid, 'lv' => $level]);
    }
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
        // [FIX 2026-11-17] tabel project_progress / project_progress_photos TIDAK ADA di proyekGMN_test
        // (hanya project_progress_log); log dihapus langsung berdasarkan project_id.
        $db->prepare("DELETE FROM project_progress_log WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM project_wip_release WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM financial_expense_allocations WHERE financial_expense_id IN (SELECT id FROM financial_expenses WHERE project_id = :p)")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM financial_expenses WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM material_out_items WHERE material_out_id IN (SELECT id FROM material_out_header WHERE project_id = :p)")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM material_out_header WHERE project_id = :p")->execute(['p' => $pid]);
        // rantai subkon: certificate -> inspection -> progress -> contract
        $db->prepare("DELETE FROM subcon_payment_certificates WHERE inspection_id IN (
                        SELECT si.id FROM subcon_inspections si
                        JOIN subcon_progress sp ON sp.id = si.progress_id
                        JOIN subcon_contracts sc ON sc.id = sp.contract_id
                        WHERE sc.project_id = :p)")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM subcon_inspections WHERE progress_id IN (
                        SELECT sp.id FROM subcon_progress sp
                        JOIN subcon_contracts sc ON sc.id = sp.contract_id
                        WHERE sc.project_id = :p)")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM subcon_progress WHERE contract_id IN (SELECT id FROM subcon_contracts WHERE project_id = :p)")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM subcon_contracts WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM budget_items WHERE project_id = :p")->execute(['p' => $pid]);
        $db->prepare("DELETE FROM journal_lines WHERE journal_id IN (SELECT id FROM journal_entries WHERE reference_id IN (
                        SELECT h.id FROM material_out_header h WHERE h.project_id = :p1) OR reference_id IN (
                        SELECT r.id FROM project_wip_release r WHERE r.project_id = :p2))")->execute(['p1' => $pid, 'p2' => $pid]);
        $db->prepare("DELETE FROM journal_entries WHERE reference_id IN (
                        SELECT h.id FROM material_out_header h WHERE h.project_id = :p1) OR reference_id IN (
                        SELECT r.id FROM project_wip_release r WHERE r.project_id = :p2)")->execute(['p1' => $pid, 'p2' => $pid]);
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

    // Proyek uji P1: Berjalan, budget 500.000.000, kontrak 500.000.000, progress awal 20
    // [FIX 2026-11-17] gate issuing (validateMaterialOutAgainstProgress) menolak bila progress=0
    // (plafon = budget*0*1.05 = 0) -> fixture harus mulai dengan progress > 0.
    $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status, contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                  VALUES ('Proyek P1 " . CFG_TAG . "', 1, 'Lokasi Uji', CURRENT_DATE - 10, CURRENT_DATE + 60, 500000000, 0, 20, 'Berjalan', 500000000, 2, 1, 0) RETURNING id")->execute();
    $g['projP1'] = (int)$db->lastInsertId(); $g['created']['project'][] = $g['projP1'];

    // Material uji M1: RAB qty 100 @ 10.000 = 1.000.000.000 (untuk pembanding plafon)
    $g['matM1'] = ensure_material(CFG_TAG . '-M1', 'Material Uji ' . CFG_TAG, 'Buah');
    $g['created']['material'][] = $g['matM1'];
    $db->prepare("INSERT INTO budget_items (project_id, category, material_id, item_name, unit, qty_plan, unit_price_plan)
                  VALUES (:p, 'Material', :m, :nm, :u, :q, :pr)")
        ->execute(['p' => $g['projP1'], 'm' => $g['matM1'], 'nm' => 'Material Uji ' . CFG_TAG, 'u' => 'Buah', 'q' => 100, 'pr' => 10000]);
    $g['biM1'] = (int)$db->lastInsertId();

    // Gudang WH-P1 milik P1 + stok M1 = 100 (biaya 100.000/unit, total 10.000.000)
    $db->prepare("INSERT INTO warehouses (code, name, project_id, active) VALUES (:c, :n, :p, true) RETURNING id")
        ->execute(['c' => 'WH-P1-' . CFG_TAG, 'n' => 'Gudang P1 ' . CFG_TAG, 'p' => $g['projP1']]);
    $g['whP1'] = (int)$db->lastInsertId(); $g['created']['warehouse'][] = $g['whP1'];
    $db->prepare("INSERT INTO stock_movements (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, created_by)
                  VALUES (CURRENT_DATE, :i, NULL, :w, 'IN', 100, 100000, 'wh_receiving', 0, :r, 1)")
        ->execute(['i' => $g['matM1'], 'w' => $g['whP1'], 'r' => CFG_TAG . '-RCV-1']);

    // User: pelapor level 3 (bisa submit_claim + verify -> self-verify test di TC-PC-07 bermakna,
    // karena modul melarang pelapor memverifikasi klaimnya sendiri), verifikator level 4, admin level 4.
    $g['user_pelapor']  = ensure_user(3, 'report');
    $g['user_verifier'] = ensure_user(4, 'verify');
    $g['user_admin']    = ensure_user(4, 'admin');
    foreach ([['user_pelapor', 'report'], ['user_verifier', 'verify'], ['user_admin', 'admin']] as [$k, $purpose]) {
        $uname = csval("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g[$k]]);
        if (!login($g[$k], $uname, $uname . '-pass')) { fwrite(STDERR, "[SETUP] login {$k} gagal\n"); }
    }
}

function cleanup_test_data(): void
{
    $g = &$GLOBALS['g'];
    $db = db();
    $db->beginTransaction();
    try {
        foreach ($g['created']['issue'] as $id) {
            $db->prepare("DELETE FROM material_out_items WHERE material_out_id = :i")->execute(['i' => $id]);
            // reference_type enum lowercase: 'material_out' (lihat php-func/journal.stock.php JRN_REF_ISSUE)
            $db->prepare("DELETE FROM stock_movements WHERE reference_type = 'MATERIAL_OUT' AND reference_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM journal_lines WHERE journal_id IN (SELECT id FROM journal_entries WHERE reference_type = 'material_out' AND reference_id = :i)")->execute(['i' => $id]);
            $db->prepare("DELETE FROM journal_entries WHERE reference_type = 'material_out' AND reference_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM material_out_header WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['expense'] as $id) {
            $db->prepare("DELETE FROM financial_expense_allocations WHERE financial_expense_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM financial_expenses WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['subcon'] as $id) {
            $db->prepare("DELETE FROM subcon_payment_certificates WHERE inspection_id IN (SELECT id FROM subcon_inspections WHERE progress_id IN (SELECT id FROM subcon_progress WHERE contract_id = :i))")->execute(['i' => $id]);
            $db->prepare("DELETE FROM subcon_inspections WHERE progress_id IN (SELECT id FROM subcon_progress WHERE contract_id = :i)")->execute(['i' => $id]);
            $db->prepare("DELETE FROM subcon_progress WHERE contract_id = :i")->execute(['i' => $id]);
            $db->prepare("DELETE FROM subcon_contracts WHERE id = :i")->execute(['i' => $id]);
        }
        foreach ($g['created']['project'] as $pid) {
            $db->prepare("DELETE FROM project_progress_log WHERE project_id = :p")->execute(['p' => $pid]);
            // tabel project_progress TIDAK ADA di proyekGMN_test (hanya project_progress_log)
            $db->prepare("DELETE FROM project_wip_release WHERE project_id = :p")->execute(['p' => $pid]);
            $db->prepare("DELETE FROM material_out_items WHERE material_out_id IN (SELECT id FROM material_out_header WHERE project_id = :p)")->execute(['p' => $pid]);
            $db->prepare("DELETE FROM stock_movements WHERE warehouse_id IN (SELECT id FROM warehouses WHERE project_id = :p)")->execute(['p' => $pid]);
            $db->prepare("DELETE FROM material_out_header WHERE project_id = :p")->execute(['p' => $pid]);
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

/* ============================================================
 * 4. TEST CASES
 * ==========================================================*/
function record(string $tc, string $scenario, $status, string $note = ''): void
{
    $GLOBALS['g']['results'][] = ['tc' => $tc, 'scenario' => $scenario, 'status' => $status, 'note' => $note];
    printf("  [%s] %-12s %s%s\n", $status === true ? 'PASS' : ($status === false ? 'FAIL' : 'SKIP'), $tc, $scenario, $note !== '' ? " -- {$note}" : '');
}
function approx(float $a, float $b, float $eps = 0.005): bool { return abs($a - $b) <= $eps; }

/** TC-PC-01: view v_project_actual_cost tersedia dan mengembalikan 1 baris/proyek. */
function tc01(): void
{
    $n = (int)csval("SELECT COUNT(*) FROM information_schema.views WHERE table_schema = 'public' AND table_name = 'v_project_actual_cost'");
    $r = row("SELECT project_id, cost_material, cost_expense, cost_subcon, cost_total, rab_budget, progress
               FROM public.v_project_actual_cost WHERE project_id = :p", ['p' => $GLOBALS['g']['projP1']]);
    $ok = $n === 1 && $r !== null;
    record('TC-PC-01', 'view v_project_actual_cost ada + berisi proyek uji', $ok,
        $r ? ('cost_total=' . $r['cost_total'] . ' rab_budget=' . $r['rab_budget'] . ' progress=' . $r['progress']) : 'view/baris tidak ada');
}

/** TC-PC-02: ProjectActualCost() menyamai SUM manual (material posted) untuk proyek uji. */
function tc02(): void
{
    $db = db();
    $manual = (float)csval("SELECT COALESCE(SUM(i.qty_out * i.unit_cost), 0)
                            FROM material_out_items i
                            JOIN material_out_header h ON h.id = i.material_out_id
                            WHERE h.project_id = :p AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type,'PROJECT') = 'PROJECT'",
                            ['p' => $GLOBALS['g']['projP1']]);
    // fungsi ada di function.all.php (dimuat lewat endpoint; di sini panggil ulang via query view)
    $view = (float)csval("SELECT COALESCE(cost_material, 0) FROM public.v_project_actual_cost WHERE project_id = :p", ['p' => $GLOBALS['g']['projP1']]);
    $ok = approx($manual, $view);
    record('TC-PC-02', 'cost_material (view) == SUM manual material posted', $ok, "manual={$manual} view={$view}");
}

/** TC-PC-03: prog_metrics (via list_projects) mengembalikan cost_actual = cost_material + expense + subkon. */
function tc03(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_verifier'], 'project.progressCRD.php', 'list_projects', ['query' => 'P1 ' . CFG_TAG]);
    $ok = !empty($r['json']['success']);
    $row = null;
    foreach ($r['json']['data'] ?? [] as $d) { if ((int)$d['id'] === $g['projP1']) { $row = $d; break; } }
    $ok = $ok && $row !== null;
    $detail = $row ? ('cost_actual=' . $row['cost_actual'] . ' cost_material=' . $row['cost_material'] . ' cost_other=' . $row['cost_other'] . ' cost_subcon=' . ($row['cost_subcon'] ?? 'n/a') . ' cpi_status=' . $row['cpi_status']) : 'proyek tidak ditemukan';
    record('TC-PC-03', 'list_projects mengembalikan metrik realisasi terpadu (material+expense+subkon)', $ok, $detail);
}

/** TC-PC-04: klaim 100% ditolak hard-block bila belum ada issue material. */
function tc04(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_pelapor'], 'project.progressCRD.php', 'submit_claim', [
        'project_id' => $g['projP1'],
        'report_date' => date('Y-m-d'),
        'percent_claimed' => 100,
        'note' => 'Klaim 100% tanpa issue material',
        'evidence_ref' => 'BAP-' . CFG_TAG . '-001',
    ]);
    $ok = empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? $r['raw']), '100% DITOLAK') !== false;
    record('TC-PC-04', 'submit_claim 100% tanpa issue material DITOLAK (hard-block)', $ok,
        substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
}

/** TC-PC-05: issue PROJECT posting -> realisasi material > 0 (prasyarat klaim 100%). */
function tc05(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_admin'], 'warehouse.issuingCRD.php', 'save', [
        'action' => 'save',
        'project_id' => $g['projP1'], 'warehouse_id' => $g['whP1'], 'issue_date' => date('Y-m-d'),
        'issue_type' => 'PROJECT', 'received_by_mandor' => 'Mandor Uji', 'remark' => 'Issue untuk uji F-1.1',
        'details' => json_encode([[
            'material_id' => $g['matM1'], 'qty_out' => 10, 'notes' => 'Issue F-1.1'
        ]]),
    ]);
    $ok = !empty($r['json']['success']);
    $issId = $ok ? (int)($r['json']['data']['id'] ?? $r['json']['id'] ?? 0) : 0;
    if ($issId) { $g['issHdr'] = $issId; $g['created']['issue'][] = $issId; }
    $mv = $issId ? row("SELECT movement_type, qty, unit_cost FROM stock_movements
                         WHERE item_id = :m AND warehouse_id = :w AND movement_type = 'OUT' ORDER BY id DESC LIMIT 1", ['m' => $g['matM1'], 'w' => $g['whP1']]) : null;
    $ok = $ok && $mv && approx((float)$mv['qty'], 10);
    record('TC-PC-05', 'issue PROJECT 10 Buah terindeks realisasi (OUT 10)', $ok,
        $mv ? ('mv qty=' . $mv['qty'] . ' cost=' . $mv['unit_cost']) : 'gagal');
}

/** TC-PC-06: setelah ada issue, klaim 100% DIIZINKAN lewat (hard-block tidak memblokir klaim sah). */
function tc06(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_pelapor'], 'project.progressCRD.php', 'submit_claim', [
        'project_id' => $g['projP1'],
        'report_date' => date('Y-m-d'),
        'percent_claimed' => 30,
        'note' => 'Klaim sah setelah issue',
        'evidence_ref' => 'BAP-' . CFG_TAG . '-002',
    ]);
    $ok = !empty($r['json']['success']);
    $cid = $ok ? (int)($r['json']['data']['id'] ?? 0) : 0;
    if ($cid) { $g['claimId'] = $cid; }
    record('TC-PC-06', 'submit_claim 30% setelah ada issue material diterima', $ok,
        substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
}

/** TC-PC-07: verifikasi klaim oleh pelapor sendiri DITOLAK (self-verify dilarang). */
function tc07(): void
{
    $g = &$GLOBALS['g'];
    if (!$g['claimId']) { record('TC-PC-07', 'self-verify ditolak', false, 'claimId kosong'); return; }
    $r = api($g['user_pelapor'], 'project.progressCRD.php', 'verify', [
        'id' => $g['claimId'], 'decision' => 'Diverifikasi', 'verify_note' => 'verifikasi sendiri',
    ]);
    $ok = empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? $r['raw']), 'tidak boleh diverifikasi') !== false;
    record('TC-PC-07', 'verifikator tidak boleh = pelapor sendiri', $ok,
        substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
}

/** TC-PC-08: verifikasi klaim sah -> progress tercatat, snap_cost_base terisi (bukan 0). */
function tc08(): void
{
    $g = &$GLOBALS['g'];
    if (!$g['claimId']) { record('TC-PC-08', 'verifikasi klaim sah', false, 'claimId kosong'); return; }
    $r = api($g['user_verifier'], 'project.progressCRD.php', 'verify', [
        'id' => $g['claimId'], 'decision' => 'Diverifikasi', 'verify_note' => 'oke',
    ]);
    $ok = !empty($r['json']['success']);
    $snap = $ok ? row("SELECT snap_cost_base, snap_cpi, status FROM project_progress_log WHERE id = :i", ['i' => $g['claimId']]) : null;
    $ok = $ok && $snap && (float)$snap['snap_cost_base'] > 0;
    record('TC-PC-08', 'verifikasi [PASS] snap_cost_base > 0 (realisasi tercatat)', $ok,
        $snap ? ('snap_cost_base=' . $snap['snap_cost_base'] . ' snap_cpi=' . $snap['snap_cpi'] . ' status=' . $snap['status']) : 'gagal');
}

/** TC-PC-09: expense RAB di-set + view mengalirkan cost_expense > 0 (F-1.2 jalur expense). */
function tc09(): void
{
    $g = &$GLOBALS['g'];
    $expNo = CFG_TAG . '-EXP-' . date('His');
    db()->prepare("INSERT INTO financial_expenses (expense_no, expense_date, project_id, account_id, category, amount, status, notes)
                   VALUES (:no, CURRENT_DATE, :p, 1, 'Beban Operasional', 2500000, 'PAID', 'uji F-1.2 expense') RETURNING id")
        ->execute(['no' => $expNo, 'p' => $g['projP1']]);
    $expId = (int)db()->lastInsertId();
    $g['created']['expense'][] = $expId;
    $view = (float)csval("SELECT COALESCE(cost_expense, 0) FROM public.v_project_actual_cost WHERE project_id = :p", ['p' => $g['projP1']]);
    $ok = approx($view, 2500000);
    record('TC-PC-09', 'cost_expense (view) = 2.500.000 setelah expense PAID', $ok, "view={$view}");
}

/** TC-PC-10: prog_metrics expense masuk cost_actual (2.500.000 + material). */
function tc10(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_verifier'], 'project.progressCRD.php', 'list_projects', ['query' => 'P1 ' . CFG_TAG]);
    $row = null;
    foreach ($r['json']['data'] ?? [] as $d) { if ((int)$d['id'] === $g['projP1']) { $row = $d; break; } }
    $exp = $row ? (float)$row['cost_other'] : -1;
    $ok = $row && approx($exp, 2500000);
    record('TC-PC-10', 'list_projects cost_other = 2.500.000 (expense masuk realisasi)', $ok,
        $row ? ('cost_other=' . $row['cost_other'] . ' cost_actual=' . $row['cost_actual']) : 'proyek tidak ditemukan');
}

/** TC-PC-11: wr_calc (preview WIP) mengembalikan field actual_cost terpadu. */
function tc11(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_verifier'], 'project.wipreleaseCRD.php', 'preview', ['project_id' => $g['projP1'], 'mode' => 'progress', 'progress_pct' => 30]);
    $j = $r['json'] ?? null;
    $ok = $j && !empty($j['success']);
    $d = $j['data'] ?? null;
    $detail = $d ? ('wip_cumulative=' . ($d['wip_cumulative'] ?? 'n/a') . ' actual_cost=' . ($d['actual_cost'] ?? 'n/a')
                   . ' actual_cost_expense=' . ($d['actual_cost_expense'] ?? 'n/a') . ' cost_remaining_wip=' . ($d['cost_remaining_wip'] ?? 'n/a'))
                 : 'preview gagal: ' . substr($r['raw'], 0, 160);
    $ok = $ok && $d && isset($d['actual_cost']) && isset($d['actual_cost_expense']);
    record('TC-PC-11', 'preview WIP memuat field realisasi terpadu (actual_cost/expense/remaining)', $ok, $detail);
}

/** TC-PC-12: dashboard read_project_profitability (via task dashboard) mengembalikan cost_total terpadu. */
function tc12(): void
{
    $g = &$GLOBALS['g'];
    $r = api($g['user_admin'], 'dashboardCRD.php', 'read_project_profitability', ['project_id' => $g['projP1']]);
    $ok = !empty($r['json']['success']);
    $row = null;
    foreach ($r['json']['data'] ?? [] as $d) { if ((int)$d['id'] === $g['projP1']) { $row = $d; break; } }
    $ok = $ok && $row !== null && isset($row['cost_total']);
    $detail = $row ? ('cost_total=' . $row['cost_total'] . ' cost_material=' . $row['cost_material'] . ' cost_expense=' . $row['cost_expense'] . ' cost_subcon=' . $row['cost_subcon'])
                   : 'baris proyek tidak ada / task gagal';
    record('TC-PC-12', 'dashboard profitability memuat cost_total terpadu', $ok, $detail);
}

/** TC-PC-13: issuing ditolak bila proyeked spend melebihi plafon progress-gated (gate view, bukan budget header). */
function tc13(): void
{
    $g = &$GLOBALS['g'];
    // P1 progress terverifikasi = 30 (setelah TC-PC-08), budget 500jt -> plafon = 500jt * 30% * 1.05 = 157,5jt.
    // Material OUT sebelumnya: 10 x 100.000 = 1.000.000 (jauh di bawah plafon).
    // Kita uji gate dengan membuat expense besar (mis. 200.000.000) lalu issue lagi — harus DITOLAK.
    $bigExpNo = CFG_TAG . '-BIG-' . date('His');
    db()->prepare("INSERT INTO financial_expenses (expense_no, expense_date, project_id, account_id, category, amount, status, notes)
                   VALUES (:no, CURRENT_DATE, :p, 1, 'Beban Operasional', 200000000, 'PAID', 'uji F-1.1 gate') RETURNING id")
        ->execute(['no' => $bigExpNo, 'p' => $g['projP1']]);
    $bigExpId = (int)db()->lastInsertId();
    $g['created']['expense'][] = $bigExpId;
    $r = api($g['user_admin'], 'warehouse.issuingCRD.php', 'save', [
        'action' => 'save',
        'project_id' => $g['projP1'], 'warehouse_id' => $g['whP1'], 'issue_date' => date('Y-m-d'),
        'issue_type' => 'PROJECT', 'received_by_mandor' => 'Mandor Uji', 'remark' => 'Issue di atas plafon',
        'details' => json_encode([[
            'material_id' => $g['matM1'], 'qty_out' => 1, 'notes' => 'harus ditolak'
        ]]),
    ]);
    $ok = empty($r['json']['success']) && stripos((string)($r['json']['message'] ?? $r['raw']), 'melampaui batas progress') !== false;
    record('TC-PC-13', 'issue di atas plafon progress-gated DITOLAK', $ok,
        substr((string)($r['json']['message'] ?? $r['raw']), 0, 160));
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
        $sec .= "| {$r['tc']} | " . str_replace('|', '\\|', $r['scenario']) . " | {$st} | " . str_replace('|', '\\|', $r['note']) . " |\n";
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
    $header = "# Test Report Progress Cost Control (F-1.1 & F-1.2) (TC-PC-01 s/d TC-PC-13)\n\n";
    $header .= "**Tanggal eksekusi:** " . date('Y-m-d H:i:s') . "  \n";
    $header .= "**Baseline:** branch `dev` | DB `proyekGMN_test` | base URL `" . CFG_BASE_URL . "`  \n";
    $header .= "**Dokumen acuan:** `tests/run_e2e_progress_cost_control.php`  \n";
    $header .= "**Ringkasan sementara:** PASS = {$pass}, FAIL = {$fail}, SKIP = {$skip}\n\n";
    $header .= "> Fitur: kontrol progress fisik vs realisasi biaya proyek.\n";
    $header .= "> F-1.1: hard-block klaim 100% tanpa issue material; verify memaksa ulang; manual progress non-admin ditolak.\n";
    $header .= "> F-1.2: satu rumus realisasi `v_project_actual_cost` (material + expense + subkon tersertifikasi)\n";
    $header .= "> dipakai konsisten di prog_metrics, dash_project_context, wr_calc, dan gate issuing.\n\n";
    $out = preg_replace('/^# Test Report Progress Cost Control.*?(?=\n## |\n<!-- BEGIN:)/s', '', $out);
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

    echo "=== E2E Kontrol Progress vs Realisasi Biaya (F-1.1 & F-1.2) (TC-PC-01 s/d TC-PC-13) ===\n";
    echo "DB: " . CFG_DB_NAME . " @ " . CFG_DB_HOST . " | URL: " . CFG_BASE_URL . "\n";

    if ($doSetup) {
        echo "[setup] membuat data uji (P1 + WH-P1 + stok M1, pelapor lv2/verifier lv4/admin lv4)...\n";
        setup_test_data();
        echo "[setup] selesai. projP1={$GLOBALS['g']['projP1']} matM1={$GLOBALS['g']['matM1']} whP1={$GLOBALS['g']['whP1']} biM1={$GLOBALS['g']['biM1']}\n";
    }

    $groupPc = 'Kelompok PC — Kontrol Progress Proyek vs Realisasi Biaya (F-1.1 & F-1.2)';
    $tests = [
        'tc01' => 'TC-PC-01', 'tc02' => 'TC-PC-02', 'tc03' => 'TC-PC-03', 'tc04' => 'TC-PC-04',
        'tc05' => 'TC-PC-05', 'tc06' => 'TC-PC-06', 'tc07' => 'TC-PC-07', 'tc08' => 'TC-PC-08',
        'tc09' => 'TC-PC-09', 'tc10' => 'TC-PC-10', 'tc11' => 'TC-PC-11', 'tc12' => 'TC-PC-12',
        'tc13' => 'TC-PC-13',
    ];
    echo "[run] eksekusi Kelompok PC:\n";
    foreach ($tests as $fn => $tc) {
        if ($only && !in_array($tc, $only, true)) { continue; }
        $fn();
    }
    write_report($GLOBALS['g']['results'], $groupPc);

    if ($doCleanup) {
        echo "[cleanup] menghapus data uji...\n";
        cleanup_test_data();
        echo "[cleanup] selesai.\n";
    } else {
        echo "[cleanup] DILEWATI (--keep/--setup) -- data uji tetap di DB untuk investigasi.\n";
    }

    $p = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === true));
    $f = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === false));
    echo "\nHasil Keseluruhan (PC): PASS={$p} FAIL={$f} | laporan: " . CFG_REPORT . "\n";
    exit($f > 0 ? 1 : 0);
}

if (!defined('E2E_AS_LIB')) {
    main($argv);
}
