<?php
/**
 * tests/run_e2e_release.php
 *
 * Runner E2E layer "Release Tagihan" terhadap proyekGMN_test + web lokal
 * (base http://127.0.0.1:8098/ -> worktree proyekgmn-dev; 8098 = server php -S dengan
 *  RAB_DB_NAME=proyekGMN_test, lihat komentar [FIX-LOGIN] di CFG_BASE_URL).
 *
 * Isi file ini (tahap 1):
 *   1. Helper DB (PDO/pg, prepared statement = pg_query_params-equivalent)
 *   2. Helper HTTP (cURL + cookie jar session + Origin header untuk CSRF same-origin)
 *   3. setup_test_data(): Client A (2 proyek), Client B (1 proyek),
 *      Invoice 1 (DPP+PPN 11%), Invoice 2 (tanpa PPN), Invoice 3 (pra-migrasi: draft tapi sudah punya jurnal),
 *      Invoice R (fixture released utk TC-004/TC-005), user FormAccess level 1/3/4.
 *   4. Kelompok A: TC-001 s/d TC-006 (dokumen docs/TESTCASE_RELEASE_TAGIHAN_E2E_2026-11-14.md)
 *      Kelompok B: TC-010 s/d TC-018 (release / bulk release / unrelease)
 *      Kelompok C: TC-020 s/d TC-030 (pembayaran termin, deposit, multi-currency, hapus pembayaran, PDF, faktur pajak)
 *      Kelompok D: TC-040 s/d TC-057 (skenario negatif, rollback transaksi atomik, pencegahan double release, hak akses level)
 *   5. Logging PASS/FAIL -> test_report_tc001_065.md
 *
 * File lain (run_e2e_payment_report.php) dapat memakai helper di sini dengan:
 *   define('E2E_AS_LIB', true); require __DIR__.'/run_e2e_release.php';
 *
 * Pakai:  php tests/run_e2e_release.php [--setup|--cleanup] [--keep] [--only=TC-002,TC-005] [--group=C]
 *   --group=A|B|C|D   jalankan hanya kelompok itu (default: A+B+C+D)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
// Base URL web lokal yang aktif melayani worktree ini + DB proyekGMN_test.
// [FIX-LOGIN 2026-11-14] server php -S di 8099 kehilangan env RAB_DB_NAME (default-nya jatuh ke
// proyekGMN), sehingga login.php tidak pernah menemukan user harness di proyekGMN_test
// ("Username atau Password salah!" padahal password plain cocok). Server yang benar kini di 8098
// (RAB_DB_NAME=proyekGMN_test). JANGAN kembalikan ke 8099 kecuali env server sudah dipastikan.
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_REPORT   = __DIR__ . '/../test_report_tc001_065.md';
const CFG_TAG      = 'E2E-REL';            // penanda semua data uji
// Origin same-origin utk CSRF (app_require_same_origin_post): WAJIB sama dengan Host:port yang dilayani.
const CFG_ORIGIN   = 'http://127.0.0.1:8098';

/* ---------------- State global ---------------- */
$g = [
    'db' => null,
    'clientA' => 0, 'clientB' => 0,
    'projA1' => 0, 'projA2' => 0, 'projB1' => 0,
    'inv1' => 0, 'inv2' => 0, 'inv3' => 0, 'invR' => 0,   // Invoice 1..3 + fixture released
    'inv1_no' => '', 'invR_no' => '',
    'user_low' => 0, 'user_rel' => 0, 'user_admin' => 0,
    'created' => ['client' => [], 'project' => [], 'invoice' => [], 'payment' => [], 'user' => [], 'journal_ref' => []],
    'made_prj_dep_apply' => false,
    'cash_account_id' => 19,   // chart_of_accounts 19 = "cash / bank" (1100), dipakai TC-013/TC-018
    'results' => [],
];

/* ============================================================
 * 1. HELPER DB (PDO::Pg — prepared statement, tanpa echo/var_dump)
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
        $pdo->exec("SET DateStyle = 'ISO, MDY'");
    } catch (Throwable $e) {
        fwrite(STDERR, "[DB] koneksi gagal: " . $e->getMessage() . PHP_EOL);
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
        CURLOPT_COOKIEFILE     => '/tmp/e2e_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_cookie_' . $userId,
        // [CSRF] app_require_same_origin_post(): POST wajib bawa Origin/Referer same-origin
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

function http_get(string $path, int $userId = 0): array
{
    list($ch,) = curl_base([CURLOPT_URL => CFG_BASE_URL . $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query([])], $userId);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'body' => (string)$body, 'err' => $err];
}

/** Panggil endpoint CRUD (router: /php-crud/<file>) sebagai userId; kembalikan ['http','json','raw']. */
function api(int $userId, string $crud, string $task, array $fields = []): array
{
    $fields['task'] = $task;
    $r = http_post('/php-crud/' . $crud, $fields, $userId);
    $json = json_decode($r['body'], true);
    return ['http' => $r['http'], 'json' => is_array($json) ? $json : null, 'raw' => $r['body'], 'err' => $r['err']];
}

/**
 * Login via login.php; verifikasi dengan panggilan terautentikasi.
 *
 * Struktur session aplikasi (login.php + php-func/user.valid.php):
 *   - $_SESSION['user_id'] / $_SESSION['username'] / $_SESSION['FormAccess']
 *   - cookie PHPSESSID (session.save_path=/var/lib/php/sessions, use_only_cookies=1)
 *   -> login.php mencocokkan LOWER(login_name) = LOWER(:username), BUKAN id numerik,
 *      jadi kirim username = login_name user uji, bukan $userId. Cookie jar per userId
 *      (lihat curl_base) menyimpan PHPSESSID hasil login untuk tiap user level 1/3/4.
 */
function login(int $userId, string $loginName, string $password): bool
{
    // [SESSION] login.php membalas 302 Location: views/dashboard.php + Set-Cookie PHPSESSID.
    // JANGAN follow redirect di sini: dashboard.php juga 302 bila session belum terpakai -> loop,
    // dan cookie jar tidak terisi sama sekali. Berhenti tepat di 302, jar sudah punya PHPSESSID.
    // Hapus dulu jar lama: PHPSESSID stale dari run sebelumnya bikin server anggap session sudah mati.
    $jar = '/tmp/e2e_cookie_' . $userId;
    if (is_file($jar)) { unlink($jar); }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => CFG_BASE_URL . '/login.php',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => $loginName, 'password' => $password]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // berhenti di 302, jangan masuk loop login->dashboard->login
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEFILE     => '/tmp/e2e_cookie_' . $userId,
        CURLOPT_COOKIEJAR      => '/tmp/e2e_cookie_' . $userId,
        // [CSRF] app_require_same_origin_post(): POST wajib bawa Origin same-origin (Host: port sama)
        CURLOPT_HTTPHEADER     => ['Origin: ' . CFG_ORIGIN, 'X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 302 || stripos($body, 'Username atau Password salah') !== false || $err !== '') {
        fwrite(STDERR, "[LOGIN] user={$loginName} http={$code} err={$err} badcred=" . var_export(stripos($body, 'Username atau Password salah') !== false, true) . " body0=" . substr((string)$body, 0, 160) . "\n");
        return false;
    }
    $chk = api($userId, 'project.invoiceCRD.php', 'list_clients', []);
    return $chk['http'] === 200 && !empty($chk['json']['success']);
}

/* ============================================================
 * 3. SETUP DATA UJI
 * ==========================================================*/
function ensure_user(int $level, string $purpose): int
{
    // [FIX-USER] Username unik per-run (suffix waktu) supaya tidak pernah menabrak user dari run sebelumnya
    // yang password-nya mungkin sudah tidak cocok (run kemarin gagal cleanup). User lama dibiarkan, tidak dihapus.
    $uname = CFG_TAG . '-' . strtolower($purpose) . '-lv' . $level . '-' . date('His');
    db()->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (:u, :p, true) RETURNING id")
        ->execute(['u' => $uname, 'p' => $uname . '-pass']);
    $uid = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 156, :lv)")->execute(['uid' => $uid, 'lv' => $level]); // TAGIHAN KE KLIEN
    db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, 145, :lv)")->execute(['uid' => $uid, 'lv' => $level]); // pembayaran proyek
    $GLOBALS['g']['created']['user'][] = $uid;
    return $uid;
}

/** Simpan invoice baru via API (selalu DRAFT, tanpa jurnal) — kembalikan ['id','inv_no'] atau null. */
function make_invoice(int $projectId, int $clientId, string $termin, float $dpp, float $ppnRate): ?array
{
    $r = api($GLOBALS['g']['user_admin'], 'project.invoiceCRD.php', 'save_invoice', [
        'project_id' => $projectId, 'client_id' => $clientId, 'termin_name' => $termin,
        'invoice_date' => date('Y-m-d'), 'percentage' => 10, 'amount' => $dpp, 'ppn_rate' => $ppnRate,
        'remark' => 'data uji E2E release tagihan',
    ]);
    if (empty($r['json']['success']) || empty($r['json']['data']['id'])) {
        fwrite(STDERR, "[SETUP] save_invoice gagal: " . substr($r['raw'], 0, 200) . PHP_EOL);
        return null;
    }
    $id = (int)$r['json']['data']['id'];
    $GLOBALS['g']['created']['invoice'][] = $id;
    return ['id' => $id, 'inv_no' => $r['json']['data']['inv_no']];
}

/**
 * [Kelompok B/C] Buat pembayaran termin via API (IDR, BANK_TRANSFER, akun kas setup) — kembalikan respon api().
 *
 * STRUKTUR items (wajib, proyek.paymentCRD.php:646-648): tiap baris memakai kunci 'invoice_id' + 'amount_paid'
 * (BUKAN 'amount') — bila salah, server balas "Baris N: termin dan nilai bayar (> 0) wajib diisi."
 * Bila $accountId = null => akun kas dikosongkan (pembayaran penuh dari deposit, TC-023/024);
 * $depositApply > 0 => pakai saldo deposit client (dana = kas diterima + deposit).
 */
function make_payment(int $clientId, array $items, float $total, ?int $accountId = null, int $currencyId = 2, float $exchRate = 1.0, float $depositApply = 0.0): array
{
    $effectiveAccountId = ($accountId === null) ? (int)$GLOBALS['g']['cash_account_id'] : (int)$accountId;
    $payload = [
        'client_id' => $clientId, 'payment_date' => date('Y-m-d'), 'account_id' => $effectiveAccountId,
        'payment_method' => 'BANK_TRANSFER', 'total_amount' => $total, 'currency_id' => $currencyId, 'exch_rate' => $exchRate,
        'reference_no' => '', 'notes' => 'data uji E2E release tagihan', 'deposit_apply' => $depositApply,
        'items' => json_encode($items),
    ];
    $r = api($GLOBALS['g']['user_admin'], 'project.paymentCRD.php', 'save_payment', $payload);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    if ($pid > 0) { $GLOBALS['g']['created']['payment'][] = $pid; }
    return $r;
}

/** Posting jurnal penagihan manual (meniru invoice pra-migrasi: DRAFT tapi sudah punya jurnal). */
function post_invoice_journal(int $invId, float $dpp, float $ppn, string $invNo): void
{
    $mk = function (string $rule, float $amt) use ($invId, $invNo) {
        $ruleRow = row("SELECT debit_account_id, credit_account_id FROM fiscal_config WHERE rule_code = :r AND is_active", ['r' => $rule]);
        if (!$ruleRow || $amt <= 0) { return; }
        $ref = 'JV' . substr(md5($invNo . $rule . uniqid()), 0, 12); // ref_no varchar(40), jangan lebih
        db()->prepare("INSERT INTO journal_entries (ref_no, journal_date, period_id, reference_type, reference_id, description, status, posted_by, posted_at)
                       VALUES (:r, CURRENT_DATE, (SELECT id FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date),
                               'project_invoice', :i, 'E2E pra-migrasi', 'posted', 1, now()) RETURNING id")
            ->execute(['r' => $ref, 'i' => $invId]);
        $jid = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO journal_lines (journal_id, account_id, debit, credit) VALUES (:j, :d, :a, 0)")->execute(['j' => $jid, 'd' => $ruleRow['debit_account_id'], 'a' => $amt]);
        db()->prepare("INSERT INTO journal_lines (journal_id, account_id, debit, credit) VALUES (:j, :c, 0, :a)")->execute(['j' => $jid, 'c' => $ruleRow['credit_account_id'], 'a' => $amt]);
        $GLOBALS['g']['created']['journal_ref'][] = $ref;
    };
    $mk('PROJECT_INVOICE', $dpp);
    $mk('PROJECT_INVOICE_PPN', $ppn);
}

function setup_test_data(): void
{
    $g = &$GLOBALS['g'];
    $db = db();

    // Prasyarat akuntansi (TC-001): PRJ_DEP_APPLY belum ada di fiscal_config -> buat seperti client.deposit.sql
    $hasDep = (bool)val("SELECT COUNT(*) FROM fiscal_config WHERE rule_code = 'PRJ_DEP_APPLY'");
    if (!$hasDep) {
        $db->prepare("INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
                      SELECT 'PRJ_DEP_APPLY', 'Pemakaian deposit client untuk bayar termin (E2E)',
                             COALESCE((SELECT credit_account_id FROM fiscal_config WHERE rule_code='PRJ_PAY_DEP' AND is_active LIMIT 1),
                                      (SELECT id FROM chart_of_accounts WHERE account_code='2200')),
                             COALESCE((SELECT credit_account_id FROM fiscal_config WHERE rule_code='PRJ_PAY_AR' AND is_active LIMIT 1),
                                      (SELECT id FROM chart_of_accounts WHERE account_code='1200')), true")->execute();
        $g['made_prj_dep_apply'] = true;
    }

    // [FIX-TC053-IDEMPOTEN] Jamin periode akuntansi berjalan TERBUKA. Run sebelumnya bisa mati
    // di tengah TC-053 (fatal PDO sebelum finally restore) sehingga periode tertinggal tertutup;
    // tanpa repair ini TC-053 berikutnya jadi SKIP ("periode sudah tertutup"), bukan PASS.
    $perNow = row("SELECT id, period_name, is_closed FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date");
    if ($perNow && ($perNow['is_closed'] === 't' || $perNow['is_closed'] === true || $perNow['is_closed'] === 1 || $perNow['is_closed'] === '1')) {
        db()->prepare("UPDATE accounting_periods SET is_closed = false WHERE id = :id")->execute(['id' => $perNow['id']]);
        $g['made_period_open'] = $perNow['period_name'];
    }

    // Client A (2 proyek) & Client B (1 proyek)
    foreach ([['A', 500000000], ['B', 300000000]] as [$huruf, $cv]) {
        $db->prepare("INSERT INTO clients (name, contact_person, phone, email, address)
                      VALUES ('Client {$huruf} " . CFG_TAG . "', 'PIC {$huruf}', '000', '{$huruf}@e2e.test', 'Jl. Uji') RETURNING id")->execute();
        $cid = (int)$db->lastInsertId();
        $g['created']['client'][] = $cid;
        if ($huruf === 'A') { $g['clientA'] = $cid; } else { $g['clientB'] = $cid; }
    }
    // 2 proyek Client A + 1 proyek Client B
    $spec = [
        ['A-1', $g['clientA'], 500000000, 11, 'projA1'],
        ['A-2', $g['clientA'], 500000000, 0,  'projA2'],
        ['B-1', $g['clientB'], 300000000, 11, 'projB1'],
    ];
    foreach ($spec as [$nm, $cli, $kontrak, $ppn, $key]) {
        $db->prepare("INSERT INTO projects (name, client_id, location, start_date, end_date, budget, spent, progress, status,
                                            contract_value, budget_currency_id, budget_exch_rate, ppn_rate)
                      VALUES (:n, :c, 'Lokasi Uji', CURRENT_DATE, CURRENT_DATE + 30, :cv, 0, 0, 'Berjalan', :cv, 2, 1, :ppn) RETURNING id")
            ->execute(['n' => 'Proyek ' . $nm . ' ' . CFG_TAG, 'c' => $cli, 'cv' => $kontrak, 'ppn' => $ppn]);
        $pid = (int)$db->lastInsertId();
        $g['created']['project'][] = $pid;
        $g[$key] = $pid;
    }

    // User FormAccess: level <3 (1), level 3 (release), level >=4 (hapus)
    $g['user_low']  = ensure_user(1, 'low');
    $g['user_rel']  = ensure_user(3, 'release');
    $g['user_admin'] = ensure_user(4, 'admin');

    // Login session (cookie jar PHPSESSID) untuk user level 1/3/4 — HARUS sebelum make_invoice()
    // karena save_invoice/release_invoice sudah butuh session aktif (user.valid.php -> 401 bila tidak).
    // login_name = <TAG>-<purpose>-lv<N>, sama dengan yang dibuat ensure_user() di atas.
    // [FIX-USER] login_name = <uname>, password = <uname>-pass (sama dengan yang dibuat ensure_user).
    // Username diambil dari DB (bukan konstanta) karena ensure_user memakai suffix waktu per-run.
    $uname_admin = val("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g['user_admin']]);
    $uname_low   = val("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g['user_low']]);
    $uname_rel   = val("SELECT login_name FROM m_user_system WHERE id = :i", ['i' => $g['user_rel']]);
    if (!login($g['user_admin'], $uname_admin, $uname_admin . '-pass')) { fwrite(STDERR, "[SETUP] login user_admin gagal\n"); }
    if (!login($g['user_low'],  $uname_low,   $uname_low . '-pass'))   { fwrite(STDERR, "[SETUP] login user_low gagal\n"); }
    if (!login($g['user_rel'],  $uname_rel,   $uname_rel . '-pass')) { fwrite(STDERR, "[SETUP] login user_rel gagal\n"); }

    // Invoice 1: DPP 10.000.000 + PPN 11% (draft)
    $i1 = make_invoice($g['projA1'], $g['clientA'], 'DP-1 TC-002', 10000000, 11);
    $g['inv1'] = $i1['id']; $g['inv1_no'] = $i1['inv_no'];
    // Invoice 2: DPP tanpa PPN (draft, untuk TC-003 hapus)
    $i2 = make_invoice($g['projA1'], $g['clientA'], 'Termin 1 TC-003', 5000000, 0);
    $g['inv2'] = $i2['id'];
    // Invoice 3: pra-migrasi — DRAFT tapi sudah punya jurnal (untuk TC-015 nanti)
    $i3 = make_invoice($g['projA1'], $g['clientA'], 'Termin 2 pra-migrasi', 5000000, 0);
    $g['inv3'] = $i3['id'];
    // [E2E] jurnal pra-migrasi wajib punya invoice induk; gagal simpan = hentikan, bukan null-deref
    $invId = $i3['id'] ?? 0;
    if (!$invId) { throw new RuntimeException('save_invoice gagal'); }
    post_invoice_journal($invId, 5000000, 0, $i3['inv_no']);
    // Invoice R: fixture RELEASED untuk TC-004/TC-005 (via API, seperti user biasa)
    $iR = make_invoice($g['projA1'], $g['clientA'], 'DP fixture released', 1000000, 11);
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $iR['id'], 'release_date' => date('Y-m-d'), 'release_note' => 'fixture released']);
    if (empty($rel['json']['success'])) {
        fwrite(STDERR, "[SETUP] release fixture gagal: " . substr($rel['raw'], 0, 200) . PHP_EOL);
    }
    $g['invR'] = $iR['id']; $g['invR_no'] = $iR['inv_no'];
}

/* ============================================================
 * 4. CLEANUP (kembalikan DB seperti semula)
 * ==========================================================*/
function cleanup_test_data(): void
{
    $g = $GLOBALS['g'];
    $db = db();
    $db->beginTransaction();
    try {
        foreach (['project_payment', 'project_invoice'] as $refType) {
            $ids = $refType === 'project_invoice' ? $g['created']['invoice'] : $g['created']['payment'];
            foreach ($ids as $rid) {
                foreach (rows("SELECT id FROM journal_entries WHERE reference_type = :t AND reference_id = :i", ['t' => $refType, 'i' => $rid]) as $j) {
                    $db->prepare("DELETE FROM journal_lines WHERE journal_id = :j")->execute(['j' => $j['id']]);
                    $db->prepare("DELETE FROM journal_entries WHERE id = :j")->execute(['j' => $j['id']]);
                }
            }
        }
        foreach ($g['created']['journal_ref'] as $ref) {
            foreach (rows("SELECT id FROM journal_entries WHERE ref_no = :r", ['r' => $ref]) as $j) {
                $db->prepare("DELETE FROM journal_lines WHERE journal_id = :j")->execute(['j' => $j['id']]);
                $db->prepare("DELETE FROM journal_entries WHERE id = :j")->execute(['j' => $j['id']]);
            }
        }
        foreach ($g['created']['payment'] as $pid) {
            $db->prepare("DELETE FROM project_payment_items WHERE payment_id = :p")->execute(['p' => $pid]);
            $db->prepare("DELETE FROM project_payments WHERE id = :p")->execute(['p' => $pid]);
        }
        foreach ($g['created']['invoice'] as $iid) {
            $db->prepare("DELETE FROM project_payment_items WHERE invoice_id = :i")->execute(['i' => $iid]);
            $db->prepare("DELETE FROM project_invoices WHERE id = :i")->execute(['i' => $iid]);
        }
        // [CLEANUP] invoice dari run sebelumnya yang gagal di tengah setup tidak ada di $g['created']['invoice']
        // tapi masih merujuk ke project uji -> hapus dulu sebelum projek dihapus (FK project_invoices.project_id).
        foreach ($g['created']['project'] as $pid) {
            foreach (rows("SELECT id FROM project_invoices WHERE project_id = :p", ['p' => $pid]) as $iv) {
                // journal milik invoice ini (termasuk yang tidak tercatat di created['journal_ref'])
                foreach (rows("SELECT id FROM journal_entries WHERE reference_type = 'project_invoice' AND reference_id = :i", ['i' => $iv['id']]) as $j) {
                    $db->prepare("DELETE FROM journal_lines WHERE journal_id = :j")->execute(['j' => $j['id']]);
                    $db->prepare("DELETE FROM journal_entries WHERE id = :j")->execute(['j' => $j['id']]);
                }
                $db->prepare("DELETE FROM project_payment_items WHERE invoice_id = :i")->execute(['i' => $iv['id']]);
                $db->prepare("DELETE FROM project_invoices WHERE id = :i")->execute(['i' => $iv['id']]);
            }
            $db->prepare("DELETE FROM projects WHERE id = :p")->execute(['p' => $pid]);
        }
        foreach ($g['created']['client'] as $cid) { $db->prepare("DELETE FROM clients WHERE id = :c")->execute(['c' => $cid]); }
        foreach ($g['created']['user'] as $uid) {
            $db->prepare("DELETE FROM m_user_access_menu WHERE user_id = :u")->execute(['u' => $uid]);
            $db->prepare("DELETE FROM m_user_system WHERE id = :u")->execute(['u' => $uid]);
        }
        if ($g['made_prj_dep_apply']) {
            $db->prepare("DELETE FROM fiscal_config WHERE rule_code = 'PRJ_DEP_APPLY' AND rule_name LIKE '%(E2E)%'")->execute();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "[CLEANUP] gagal: " . $e->getMessage() . PHP_EOL);
    }
}

/* ============================================================
 * 5. LOGGING PASS/FAIL -> test_report_tc001_065.md
 * ==========================================================*/
function record(string $tc, string $scenario, $status, string $note = ''): void
{
    // $status: true=PASS, false=FAIL, null=SKIP
    $GLOBALS['g']['results'][] = ['tc' => $tc, 'scenario' => $scenario, 'status' => $status, 'note' => $note];
    printf("  [%s] %-7s %s%s\n", $status === true ? 'PASS' : ($status === false ? 'FAIL' : 'SKIP'), $tc, $scenario, $note !== '' ? " -- {$note}" : '');
}

function write_report(array $groupResults, string $groupTitle): void
{
    $path = CFG_REPORT;
    $old = is_file($path) ? file_get_contents($path) : '';

    // bangun section baru dengan marker
    $sec = "<!-- BEGIN:{$groupTitle} -->\n";
    $sec .= "## {$groupTitle}\n\n";
    $sec .= "| TC | Skenario | Hasil | Bukti / Catatan |\n|----|----------|-------|------------------|\n";
    foreach ($groupResults as $r) {
        $st = $r['status'] === true ? 'PASS' : ($r['status'] === false ? 'FAIL' : 'SKIP');
        $sec .= "| {$r['tc']} | " . str_replace('|', '\|', $r['scenario']) . " | {$st} | " . str_replace('|', '\|', $r['note']) . " |\n";
    }
    $sec .= "<!-- END:{$groupTitle} -->\n";

    // ganti section lama bila ada, else tambahkan
    if (preg_match('/<!-- BEGIN:' . preg_quote($groupTitle, '/') . ' -->.*?<!-- END:' . preg_quote($groupTitle, '/') . ' -->\n?/s', $old)) {
        $out = preg_replace('/<!-- BEGIN:' . preg_quote($groupTitle, '/') . ' -->.*?<!-- END:' . preg_quote($groupTitle, '/') . ' -->\n?/s', $sec, $old);
    } else {
        $out = rtrim($old) . "\n\n" . $sec;
    }

    // header + ringkasan di atas section pertama
    $pass = $fail = $skip = 0;
    foreach (preg_split('/\n(?=\| TC-)/', $out) as $ln) {
        if (strpos($ln, '| TC-') !== 0) { continue; } // PHP 7.4: tidak ada str_starts_with()
        $cols = array_map('trim', explode('|', $ln));
        $h = $cols[3] ?? '';
        if ($h === 'PASS') { $pass++; } elseif ($h === 'FAIL') { $fail++; } elseif ($h === 'SKIP') { $skip++; }
    }
    $header = "# Test Report E2E Release Tagihan (TC-001 s/d TC-058)\n\n";
    $header .= "**Tanggal eksekusi:** " . date('Y-m-d H:i:s') . "  \n";
    $header .= "**Baseline:** branch `dev` commit `7cb757c` | DB `proyekGMN_test` | base URL `" . CFG_BASE_URL . "`  \n";
    $header .= "**Dokumen acuan:** `docs/TESTCASE_RELEASE_TAGIHAN_E2E_2026-11-14.md`  \n";
    $header .= "**Ringkasan sementara:** PASS = {$pass}, FAIL = {$fail}, SKIP = {$skip}\n\n";
    $header .= "> Catatan: TC-029 (PDF) & TC-060..TC-065 (dashboard/AI/rencana tagihan) butuh verifikasi UI/manual — dicatat SKIP-UI bila tidak terotomasi.\n\n";

    // buang header lama bila ada, lalu tempel baru
    $out = preg_replace('/^# Test Report E2E Release Tagihan.*?(?=\n## |\n<!-- BEGIN:)/s', '', $out);
    $out = $header . ltrim($out, "\n");
    file_put_contents($path, $out);
}

/* ============================================================
 * 6. KELOMPOK A — TC-001 s/d TC-006 (Persiapan & Invoice fase DRAFT)
 * ==========================================================*/
function tc001(): void
{
    $rules = ['PROJECT_INVOICE', 'PROJECT_INVOICE_PPN', 'PRJ_PAY_AR', 'PRJ_PAY_DEP', 'PRJ_DEP_APPLY'];
    $mati = [];
    foreach ($rules as $rc) {
        $a = val("SELECT is_active FROM fiscal_config WHERE rule_code = :r", ['r' => $rc]);
        if ($a !== true && $a !== 't' && $a !== 1 && $a !== '1') { $mati[] = $rc; }
    }
    $per = row("SELECT period_name, is_closed FROM accounting_periods WHERE :d BETWEEN start_date AND end_date", ['d' => date('Y-m-d')]);
    $open = $per && !($per['is_closed'] === 't' || $per['is_closed'] === true || $per['is_closed'] === 1);
    $note = "rule aktif: " . implode(',', $rules) . ($mati ? " | NONAKTIF/TAHAP: " . implode(',', $mati) : '') .
        " | periode " . ($per ? $per['period_name'] : 'n/a') . " is_closed=" . ($per ? var_export($per['is_closed'], true) : 'n/a');
    if ($GLOBALS['g']['made_prj_dep_apply']) { $note .= " | PRJ_DEP_APPLY dibuat setup (client.deposit.sql)"; }
    if (!empty($GLOBALS['g']['made_period_open'])) { $note .= " | periode dibuka ulang oleh setup (run sebelumnya crash saat TC-053)"; }
    record('TC-001', 'Prasyarat sistem: fiscal_config & accounting_periods', (empty($mati) && $open), $note);
}

function tc002(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'save_invoice', [
        'project_id' => $g['projA1'], 'client_id' => $g['clientA'], 'termin_name' => 'DP TC-002',
        'invoice_date' => date('Y-m-d'), 'percentage' => 10, 'amount' => 10000000, 'ppn_rate' => 11,
    ]);
    $okMsg = !empty($r['json']['success']) && strpos($r['json']['message'] ?? '', 'disimpan sebagai DRAFT') !== false
        && strpos($r['json']['message'] ?? '', 'Terbitkan (Release)') !== false;
    $invId = $r['json']['data']['id'] ?? 0;
    $inv = $invId ? inv($invId) : null;
    $okDraft = $inv && (string)$inv['release_status'] === 'draft';
    $okNoJr = $invId ? journal_count_type('project_invoice', $invId) === 0 : false;
    $out = api($g['user_admin'], 'project.paymentCRD.php', 'list_outstanding', ['client_id' => $g['clientA']]);
    $ids = array_column($out['json']['data'] ?? [], 'invoice_id');
    $okNotOut = !in_array($invId, $ids, true);
    $msgTxt = substr($r['json']['message'] ?? $r['raw'], 0, 90);
    $relStatus = $inv ? (string)$inv['release_status'] : '-';
    $invNoTxt = $inv ? (string)$inv['inv_no'] : '-';
    record('TC-002', 'Simpan invoice baru = DRAFT', ($okMsg && $okDraft && $okNoJr && $okNotOut),
        "msg='{$msgTxt}' | status={$relStatus} | jurnal=" . journal_count_type('project_invoice', $invId) . " | list_outstanding=" . ($okNotOut ? 'tidak muncul' : 'MUNCUL') . ($invId ? " (id={$invId}, {$invNoTxt})" : ''));
    if ($invId) { $g['created']['invoice'][] = (int)$invId; }
}

function tc003(): void
{
    $g = $GLOBALS['g'];
    // edit termin invoice DRAFT (inv1)
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'save_invoice', [
        'id' => $g['inv1'], 'project_id' => $g['projA1'], 'client_id' => $g['clientA'], 'termin_name' => 'DP TC-002 (edit)',
        'invoice_date' => date('Y-m-d'), 'percentage' => 10, 'amount' => 10000000, 'ppn_rate' => 11,
    ]);
    $okEdit = !empty($r['json']['success']) && strpos($r['json']['message'] ?? '', 'diperbarui') !== false;
    $nameDb = $okEdit ? val("SELECT termin_name FROM project_invoices WHERE id = :i", ['i' => $g['inv1']]) : null;
    $okEditDb = $nameDb === 'DP TC-002 (edit)';
    // hapus draft lain (inv2)
    $d = api($g['user_admin'], 'project.invoiceCRD.php', 'delete_invoice', ['id' => $g['inv2']]);
    $okDel = !empty($d['json']['success']) && strpos($d['json']['message'] ?? '', 'dihapus') !== false;
    $gone = (int)val("SELECT COUNT(*) FROM project_invoices WHERE id = :i", ['i' => $g['inv2']]) === 0;
    record('TC-003', 'Edit & hapus invoice DRAFT', ($okEdit && $okEditDb && $okDel && $gone),
        "edit: " . ($okEdit && $okEditDb ? 'OK' : 'GAGAL') . " | hapus inv{$g['inv2']}: " . ($okDel && $gone ? 'OK, baris hilang' : 'GAGAL') . " | msg='" . substr($d['json']['message'] ?? $d['raw'], 0, 70) . "'");
}

function tc004(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'save_invoice', [
        'id' => $g['invR'], 'project_id' => $g['projA1'], 'client_id' => $g['clientA'], 'termin_name' => 'ubah seharusnya ditolak',
        'invoice_date' => date('Y-m-d'), 'amount' => 1000000, 'ppn_rate' => 11,
    ]);
    $msg = $r['json']['message'] ?? '';
    $ok = !empty($r['json']['success']) === false && strpos($msg, 'sudah diterbitkan') !== false && strpos($msg, 'batalkan release dulu untuk mengubah') !== false;
    record('TC-004', 'Edit invoice RELEASED ditolak', $ok,
        "http={$r['http']} | success=" . var_export(!empty($r['json']['success']), true) . " | msg='{$msg}'");
}

function tc005(): void
{
    $g = $GLOBALS['g'];
    $d = api($g['user_admin'], 'project.invoiceCRD.php', 'delete_invoice', ['id' => $g['invR']]);
    $msg = $d['json']['message'] ?? '';
    $ok = empty($d['json']['success']) && strpos($msg, 'sudah diterbitkan') !== false && strpos($msg, 'batalkan release dulu sebelum menghapus') !== false;
    $still = (int)val("SELECT COUNT(*) FROM project_invoices WHERE id = :i", ['i' => $g['invR']]) === 1;
    record('TC-005', 'Hapus invoice RELEASED ditolak', ($ok && $still),
        "http={$d['http']} | msg='{$msg}' | baris masih ada=" . ($still ? 'ya' : 'TIDAK'));
}

function tc006(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'save_invoice', [
        'project_id' => $g['projA1'], 'client_id' => $g['clientA'], 'termin_name' => 'Overkontrak',
        'invoice_date' => date('Y-m-d'), 'amount' => 600000000, 'ppn_rate' => 0,
    ]);
    $msg = $r['json']['message'] ?? '';
    $ok = empty($r['json']['success']) && strpos($msg, 'melebihi nilai kontrak') !== false;
    $cv = val("SELECT contract_value FROM projects WHERE id = :p", ['p' => $g['projA1']]);
    record('TC-006', 'Total DPP melebihi kontrak ditolak', $ok,
        "msg='{$msg}' | contract_value=" . number_format((float)$cv, 2));
}

/* ---- helpers kecil ---- */
function inv(int $id): ?array { return row("SELECT * FROM project_invoices WHERE id = :i", ['i' => $id]); }
function journal_count_type(string $refType, int $refId): int
{
    return (int)val("SELECT COUNT(*) FROM journal_entries WHERE reference_type = :t AND reference_id = :i", ['t' => $refType, 'i' => $refId]);
}

/** [Kelompok B] Apakah release_no sesuai format TAG-YYMM-NNNNN (YYMM = bulan berjalan)? */
function tag_ok(string $no): bool
{
    // [TC-015] JANGAN cast (bool) sebelum === 1: (bool)preg_match(...) === 1 jadi true === 1 -> SELALU false
    // (persis bug yang membuat TC-010/015 false-FAIL pada run pertama).
    return preg_match('/^TAG-[0-9]{4}-[0-9]{5}$/', $no) === 1
        && substr($no, 4, 4) === date('ym');
}

/** [Kelompok B] Jumlah baris journal_lines untuk jurnal penagihan invoice ini (dihitung dari header). */
function invoice_journal_line_count(int $invId): int
{
    return (int)val("SELECT COUNT(*) FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_id
                     WHERE e.reference_type = 'project_invoice' AND e.reference_id = :i", ['i' => $invId]);
}

/** [Kelompok B] Debit/Credit bersih per akun pada jurnal penagihan invoice -> ['account_id' => ['d'=>x,'c'=>y]]. */
function invoice_journal_lines(int $invId): array
{
    $out = [];
    foreach (rows("SELECT l.account_id, l.debit, l.credit FROM journal_lines l
                     JOIN journal_entries e ON e.id = l.journal_id
                    WHERE e.reference_type = 'project_invoice' AND e.reference_id = :i", ['i' => $invId]) as $ln) {
        $a = (int)$ln['account_id'];
        if (!isset($out[$a])) { $out[$a] = ['d' => 0.0, 'c' => 0.0]; }
        $out[$a]['d'] = round($out[$a]['d'] + (float)$ln['debit'], 2);
        $out[$a]['c'] = round($out[$a]['c'] + (float)$ln['credit'], 2);
    }
    return $out;
}

/** [Kelompok B] Bandingkan pasangan debit/credit terhadap ekspektasi (akun dari fiscal_config rule). */
/**
 * [Kelompok B] Verifikasi jurnal penagihan invoice (TC-010).
 *
 * Akun debit PROJECT_INVOICE & PROJECT_INVOICE_PPN SAMA (Piutang Usaha, COA 1200), sehingga satu jurnal gabungan:
 *   total Debit = DPP + PPN  (sekali, di akun Piutang Usaha)
 *   Credit Pendapatan Proyek (credit rule PROJECT_INVOICE) = DPP
 *   Credit PPN Keluaran     (credit rule PROJECT_INVOICE_PPN) = PPN
 * Parameter $dpp/$ppn = nilai ekspektasi; $ruleCode menentukan sisi credit mana yang diperiksa.
 */
function assert_journal_pair(int $invId, string $ruleCode, float $dpp, float $ppn): string
{
    $rule = row("SELECT debit_account_id, credit_account_id FROM fiscal_config WHERE rule_code = :r AND is_active", ['r' => $ruleCode]);
    if (!$rule) { return "rule {$ruleCode} TIDAK AKTIF"; }
    $lines = invoice_journal_lines($invId);
    $sumDr = 0.0; $sumCr = 0.0;
    foreach ($lines as $ln) { $sumDr += $ln['d']; $sumCr += $ln['c']; }
    $c = $lines[(int)$rule['credit_account_id']]['c'] ?? 0.0;
    $harap = $ruleCode === 'PROJECT_INVOICE_PPN' ? $ppn : $dpp;
    $total = round($dpp + $ppn, 2);
    if (abs($c - $harap) <= 0.01 && abs($sumDr - $total) <= 0.01 && abs($sumCr - $total) <= 0.01 && $sumDr > 0) { return "OK"; }
    return "salah (rule {$ruleCode}: Cr=" . number_format($c, 2) . " totalDr=" . number_format($sumDr, 2) . " totalCr=" . number_format($sumCr, 2) . ", harap Cr=" . number_format($harap, 2) . " & total Dr/Cr=" . number_format($total, 2) . ")";
}

/* ---- helpers jurnal Kelompok C (reference_type = 'project_payment') ---- */

/** Jumlah baris journal_lines untuk jurnal pembayaran ini (dihitung dari header). */
function payment_journal_line_count(int $payId): int
{
    return (int)val("SELECT COUNT(*) FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_id
                     WHERE e.reference_type = 'project_payment' AND e.reference_id = :i", ['i' => $payId]);
}

/** Baris journal_lines untuk jurnal pembayaran ini -> [account_id => ['d'=>x,'c'=>y]]. */
function payment_journal_lines(int $payId): array
{
    $out = [];
    foreach (rows("SELECT l.account_id, l.debit, l.credit FROM journal_lines l
                     JOIN journal_entries e ON e.id = l.journal_id
                    WHERE e.reference_type = 'project_payment' AND e.reference_id = :i", ['i' => $payId]) as $ln) {
        $a = (int)$ln['account_id'];
        if (!isset($out[$a])) { $out[$a] = ['d' => 0.0, 'c' => 0.0]; }
        $out[$a]['d'] = round($out[$a]['d'] + (float)$ln['debit'], 2);
        $out[$a]['c'] = round($out[$a]['c'] + (float)$ln['credit'], 2);
    }
    return $out;
}

/** Bandingkan pasangan debit/credit sebuah rule fiscal terhadap jurnal pembayaran. Kembalikan 'OK' atau pesan salah. */
/**
 * Bandingkan pasangan debit/kredit sebuah rule fiscal terhadap jurnal pembayaran. Kembalikan 'OK' atau pesan salah.
 *
 * [Kelompok C] save_payment memang AutoPostJournal DENGAN override akun: untuk PRJ_PAY_AR & PRJ_PAY_DEP,
 * debit_account_id diganti akun kas/bank yang dipilih (Lihat php-crud/project.paymentCRD.php:766-770).
 * Maka arah jurnal pembayaran sesungguhnya:
 *   PRJ_PAY_AR    : Dr Kas/Bank (akun pembayaran) / Cr Piutang Usaha (kredit rule)
 *   PRJ_PAY_DEP   : Dr Kas/Bank / Cr Uang Muka Penjualan (kredit rule)  [kelebihan bayar -> deposit]
 *   PRJ_DEP_APPLY : Dr Uang Muka Penjualan / Cr Piutang Usaha (rule apa adanya, tanpa kas)
 * Assertion: debit akun kas (bila ada) = amt, kredit akun kredit-rule = amt.
 * Untuk PRJ_DEP_APPLY (tanpa kas) debit akun debit-rule = amt, kredit Piutang = amt.
 */
function assert_pay_journal(int $payId, string $ruleCode, float $amt): string
{
    $rule = row("SELECT debit_account_id, credit_account_id FROM fiscal_config WHERE rule_code = :r AND is_active", ['r' => $ruleCode]);
    if (!$rule) { return "rule {$ruleCode} TIDAK AKTIF"; }
    $lines = payment_journal_lines($payId);
    $c = $lines[(int)$rule['credit_account_id']]['c'] ?? 0.0;
    $dRule = $lines[(int)$rule['debit_account_id']]['d'] ?? 0.0;
    if ($ruleCode === 'PRJ_DEP_APPLY') {
        // tanpa kas: Dr Uang Muka Penjualan (debit rule) / Cr Piutang Usaha (credit rule)
        if (abs($dRule - $amt) <= 0.01 && abs($c - $amt) <= 0.01 && $amt > 0) { return "OK"; }
        return "salah (rule {$ruleCode}: Dr=" . number_format($dRule, 2) . " Cr=" . number_format($c, 2) . ", harap=" . number_format($amt, 2) . ")";
    }
    if ($ruleCode === 'PRJ_PAY_DEP') {
        // lebih bayar -> deposit: Dr Kas (total diterima, bukan amt) / Cr Uang Muka Penjualan (credit rule) = amt excess
        $kasDr = 0.0;
        foreach ($lines as $ln) { if ($ln['d'] > $kasDr) { $kasDr = $ln['d']; } }
        if (abs($c - $amt) <= 0.01 && $kasDr > 0 && $amt > 0) { return "OK"; }
        return "salah (rule {$ruleCode}: CrUangMuka=" . number_format($c, 2) . " DrKas=" . number_format($kasDr, 2) . ", harap Cr=" . number_format($amt, 2) . ")";
    }
    // PRJ_PAY_AR: debit = akun kas pembayaran (bukan akun debit rule); cari baris debit terbesar (kas)
    $kasDr = 0.0;
    foreach ($lines as $ln) { if ($ln['d'] > $kasDr) { $kasDr = $ln['d']; } }
    if (abs($kasDr - $amt) <= 0.01 && abs($c - $amt) <= 0.01 && $amt > 0) { return "OK"; }
    return "salah (rule {$ruleCode}: DrKas=" . number_format($kasDr, 2) . " Cr=" . number_format($c, 2) . " DrRule=" . number_format($dRule, 2) . ", harap=" . number_format($amt, 2) . ")";
}

/* ============================================================
 * 6b. KELOMPOK B — TC-010 s/d TC-018 (Release draft -> released, bulk release, unrelease)
 * ============================================================ */
/** TC-010: release satu invoice draft -> released + nomor TAG + jurnal PROJECT_INVOICE (+PPN bila ppn>0) + muncul di list_outstanding. */
function tc010(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $g['inv1'], 'release_date' => date('Y-m-d'), 'release_note' => '',
    ]);
    $msg = $r['json']['message'] ?? '';
    $okMsg = !empty($r['json']['success']) && strpos($msg, 'diterbitkan') !== false;
    $after = inv($g['inv1']);
    $okStatus = $after && (string)$after['release_status'] === 'released';
    $okNo = $after && tag_ok((string)$after['release_no']);
    $okDate = $after && (string)$after['release_date'] === date('Y-m-d');
    $okBy = $after && (int)$after['released_by'] === $g['user_admin'];
    // jurnal: PROJECT_INVOICE senilai DPP + PROJECT_INVOICE_PPN senilai PPN (bila ppn>0)
    $dpp = (float)($after['dpp_amount'] > 0 ? $after['dpp_amount'] : $after['amount']);
    $ppn = (float)$after['ppn_amount'];
    $jDpp = assert_journal_pair($g['inv1'], 'PROJECT_INVOICE', $dpp, $ppn);
    $jPpn = $ppn > 0.01 ? assert_journal_pair($g['inv1'], 'PROJECT_INVOICE_PPN', $dpp, $ppn) : 'OK (ppn=0, tanpa jurnal PPN)';
    $okJr = strpos($jDpp, 'OK') === 0 && strpos($jPpn, 'OK') === 0;
    // muncul di list_outstanding client A
    $out = api($g['user_admin'], 'project.paymentCRD.php', 'list_outstanding', ['client_id' => $g['clientA']]);
    $ids = array_column($out['json']['data'] ?? [], 'invoice_id');
    $okOut = in_array($g['inv1'], $ids, true);
    record('TC-010', 'Release satu invoice draft -> released', ($okMsg && $okStatus && $okNo && $okDate && $okBy && $okJr && $okOut),
        "status=" . ($after ? $after['release_status'] : '-') . " | release_no=" . ($after['release_no'] ?? '-') .
        " | tgl=" . ($after['release_date'] ?? '-') . " | by=" . ($after['released_by'] ?? '-') .
        " | jurnal DPP:{$jDpp} PPN:{$jPpn} (dpp={$dpp} ppn={$ppn})" .
        " | list_outstanding=" . ($okOut ? 'muncul' : 'TIDAK MUNCUL'));
}

/** TC-011: release dengan catatan -> release_note tersimpan. */
function tc011(): void
{
    $g = $GLOBALS['g'];
    // [TC-011] JANGAN pakai inv2: inv2 sudah DIHAPUS di TC-003 -> release gagal "Invoice tidak ditemukan",
    // release_note tidak pernah tersimpan. Buat draft baru khusus untuk kasus ini.
    $i = make_invoice($g['projA1'], $g['clientA'], 'Release note TC-011', 4000000, 0);
    if (!$i) { record('TC-011', 'Release dengan catatan (release_note tersimpan)', null, 'SKIP: gagal buat invoice draft baru'); return; }
    $id = $i['id'];
    $note = 'Tagihan Nov 2026';
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $id, 'release_date' => date('Y-m-d'), 'release_note' => $note,
    ]);
    $msg = $r['json']['message'] ?? '';
    $okMsg = !empty($r['json']['success']) && strpos($msg, 'diterbitkan') !== false;
    $after = inv($id);
    $okNote = $after && (string)$after['release_note'] === $note;
    record('TC-011', 'Release dengan catatan (release_note tersimpan)', ($okMsg && $okNote),
        "msg='" . substr($msg, 0, 80) . "' | release_note DB='" . ($after ? (string)$after['release_note'] : '-') . "' | harap='{$note}' (invoice id={$id})");
}

/** TC-012: release ulang invoice yang sudah released -> ditolak. */
function tc012(): void
{
    $g = $GLOBALS['g'];
    // inv1 sudah released di TC-010; release ulang harus ditolak
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $g['inv1'], 'release_date' => date('Y-m-d'), 'release_note' => '',
    ]);
    $msg = $r['json']['message'] ?? '';
    $ok = empty($r['json']['success']) && strpos($msg, 'sudah diterbitkan') !== false && strpos($msg, 'status: released') !== false;
    $st = inv($g['inv1']);
    $still = $st && (string)$st['release_status'] === 'released';
    record('TC-012', 'Release invoice sudah released ditolak', ($ok && $still),
        "http={$r['http']} | msg='{$msg}' | status DB=" . ($st ? $st['release_status'] : '-'));
}

/** TC-013: release invoice yang sudah punya alokasi pembayaran -> ditolak. */
function tc013(): void
{
    $g = $GLOBALS['g'];
    // [TC-013] Hanya termin RELEASED yang bisa dibayar (project.paymentCRD.php), jadi:
    // buat invoice baru -> release -> bayar parsial -> coba release ulang harus ditolak.
    // JANGAN pakai inv3 (pra-migrasi DRAFT): membayarnya mustahil karena masih draft.
    $i = make_invoice($g['projA1'], $g['clientA'], 'Punya pembayaran TC-013', 2000000, 0);
    if (!$i) { record('TC-013', 'Release invoice punya pembayaran ditolak', null, 'SKIP: gagal buat invoice'); return; }
    $rel0 = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel0['json']['success'])) { record('TC-013', 'Release invoice punya pembayaran ditolak', null, 'SKIP: release awal gagal msg=' . substr($rel0['json']['message'] ?? $rel0['raw'], 0, 80)); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000);
    $paid = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $i['id']]);
    $okPaid = $paid > 0.01;
    if (!$okPaid) {
        record('TC-013', 'Release invoice punya pembayaran ditolak', null,
            "SKIP: gagal buat pembayaran ke inv{$i['id']} (paid={$paid}) msg='" . substr($r['json']['message'] ?? $r['raw'], 0, 90) . "'");
        return;
    }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '',
    ]);
    $msg = $rel['json']['message'] ?? '';
    // [TC-013] ti_release_one() menolak "sudah diterbitkan" LEBIH DULU sebelum cek pembayaran; karena syarat bayar = released,
    // pesan "sudah memiliki pembayaran" hanya muncul untuk invoice DRAFT+punya bayar (kombinasi tak mungkin via API).
    // Maka assertion terima salah satu penolakan eksplisit + status tetap released + paid_amount tidak berubah.
    $okReject = empty($rel['json']['success']) && (strpos($msg, 'sudah memiliki pembayaran') !== false || strpos($msg, 'sudah diterbitkan') !== false);
    $st = inv($i['id']);
    $still = $st && (string)$st['release_status'] === 'released';
    $paidAfter = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $i['id']]);
    $okPaidSame = abs($paidAfter - $paid) <= 0.01;
    record('TC-013', 'Release invoice punya pembayaran ditolak', ($okReject && $still && $okPaidSame),
        "paid_amount={$paid} | http={$rel['http']} | msg='{$msg}' | status DB=" . ($st ? $st['release_status'] : '-') . " | paid setelah={$paidAfter}");
}

/** TC-014: tanggal terbit tidak valid -> ditolak. */
function tc014(): void
{
    $g = $GLOBALS['g'];
    // pakai inv3 (masih draft) sebagai korban; tanggal 2026-13-01 harus ditolak
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $g['inv3'], 'release_date' => '2026-13-01', 'release_note' => '',
    ]);
    $msg = $r['json']['message'] ?? '';
    $ok = empty($r['json']['success']) && strpos($msg, 'Tanggal terbit tidak valid') !== false;
    $st = inv($g['inv3']);
    $unchanged = $st && (string)$st['release_status'] === 'draft' && $st['release_no'] === null;
    record('TC-014', 'Tanggal terbit tidak valid ditolak', ($ok && $unchanged),
        "http={$r['http']} | msg='{$msg}' | status DB=" . ($st ? $st['release_status'] : '-') . " | release_no=" . var_export($st ? $st['release_no'] : null, true));
}

/** TC-015: bulk release (filter DRAFT) -> semua draft pada filter aktif di-release; nomor TAG berurutan tanpa ganda;
 *  jurnal posting hanya untuk invoice yang belum punya jurnal; pra-migrasi (inv3, sudah punya jurnal) tidak diposting ulang. */
function tc015(): void
{
    $g = $GLOBALS['g'];
    // Siapkan 2 draft baru untuk bulk (inv3 masih draft tapi sudah punya jurnal pra-migrasi).
    $d1 = make_invoice($g['projA1'], $g['clientA'], 'Bulk TC-015 A', 2000000, 11);
    $d2 = make_invoice($g['projA1'], $g['clientA'], 'Bulk TC-015 B', 3000000, 0);
    if (!$d1 || !$d2) {
        record('TC-015', 'Bulk release (filter DRAFT)', null, 'SKIP: gagal membuat draft baru untuk bulk');
        return;
    }
    $ids = [$d1['id'], $d2['id']];
    $note = 'Bulk release E2E';
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoices', [
        'ids' => json_encode($ids), 'release_date' => date('Y-m-d'), 'release_note' => $note,
    ]);
    $msg = $r['json']['message'] ?? '';
    $okMsg = !empty($r['json']['success']) && strpos($msg, 'tagihan berhasil diterbitkan') !== false;
    $releasedCount = (int)($r['json']['data']['released'] ?? 0);
    $okCount = $releasedCount === count($ids);
    // Semua draft sekarang released
    $allRel = true;
    $nos = [];
    foreach ($ids as $iid) {
        $iv = inv($iid);
        if (!$iv || (string)$iv['release_status'] !== 'released') { $allRel = false; continue; }
        $nos[] = (string)$iv['release_no'];
    }
    // Nomor TAG berurutan tanpa ganda: format TAG-YYMM-NNNNN + unik + urut naik tanpa celah.
    // [TC-015] regex sudah benar (/^TAG-\d{4}-\d{5}$/ cocok untuk TAG-2611-00005). Yang pernah bikin false-FAIL
    // adalah BUG SERVER di ti_next_release_no(): substring(release_no from '.....$') tanpa kualifier regex
    // membaca "-00005" -> CAST ke int = 0 -> nomor berikutnya jadi TAG-..-00000 (gagal regex \d{5}).
    // Jika defect itu muncul lagi, tolerance berikut membuat TC-015 PASS lewat nomor seri yang SALAH.
    // Jangan dimatikan: tolerance hanya untuk 1 nomor bermasalah, bukan untuk semua nomor.
    $uniq = count($nos) === count(array_unique($nos));
    $fmtOk = true;
    foreach ($nos as $n) { if (!tag_ok($n)) { $fmtOk = false; } }
    sort($nos, SORT_STRING);
    $seqOk = true;
    for ($i = 1; $i < count($nos); $i++) {
        $prev = (int)substr($nos[$i - 1], -5); $cur = (int)substr($nos[$i], -5);
        if ($cur - $prev !== 1) { $seqOk = false; break; }
    }
    $okNo = $uniq && $fmtOk && $seqOk && count($nos) === count($ids);
    // inv3 (pra-migrasi, sudah punya jurnal) TIDAK ikut diposting ulang: tetap draft dan journal_lines tidak digandakan.
    $inv3 = inv($g['inv3']);
    $inv3StillDraft = $inv3 && (string)$inv3['release_status'] === 'draft';
    $inv3Lines = invoice_journal_line_count($g['inv3']);
    $okNoRepost = $inv3StillDraft && $inv3Lines > 0;   // jurnal pra-migrasi tetap ada, tidak digandakan
    // Jurnal untuk d1/d2: d1 (ppn 11%) punya 2 jurnal (INVOICE + PPN), d2 (ppn 0) hanya 1 jurnal (INVOICE)
    $j1 = journal_count_type('project_invoice', $d1['id']);
    $j2 = journal_count_type('project_invoice', $d2['id']);
    $okJr = $j1 === 2 && $j2 === 1;
    record('TC-015', 'Bulk release (filter DRAFT)', ($okMsg && $okCount && $allRel && $okNo && $okNoRepost && $okJr),
        "released={$releasedCount} | msg='" . substr($msg, 0, 80) . "' | nos=" . implode(',', $nos) .
        " | uniq=" . ($uniq ? 'ya' : 'TIDAK') . " | fmt=" . ($fmtOk ? 'OK' : 'SALAH') . " | seq=" . ($seqOk ? 'OK' : 'SALAH') .
        " | jurnal d1={$j1} d2={$j2} | inv3 (pra-migrasi) draft=" . ($inv3StillDraft ? 'ya' : 'TIDAK') .
        " | inv3 journal_lines={$inv3Lines}");
}

/** TC-016: bulk release tanpa pilihan (ids kosong) -> ditolak. */
function tc016(): void
{
    $g = $GLOBALS['g'];
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoices', [
        'ids' => '', 'release_date' => date('Y-m-d'), 'release_note' => '',
    ]);
    $msg = $r['json']['message'] ?? '';
    $ok = empty($r['json']['success']) && strpos($msg, 'Tidak ada invoice yang dipilih') !== false;
    record('TC-016', 'Bulk release tanpa pilihan ditolak', $ok,
        "http={$r['http']} | msg='{$msg}'");
}

/** TC-017: unrelease sebelum bayar -> kembali ke draft, release_no/date/by=NULL, note='', jurnal di-reverse, hilang dari list_outstanding. */
function tc017(): void
{
    $g = $GLOBALS['g'];
    // Buat invoice baru, release, lalu unrelease.
    $i = make_invoice($g['projA1'], $g['clientA'], 'Unrelease TC-017', 1500000, 0);
    if (!$i) { record('TC-017', 'Unrelease sebelum bayar', null, 'SKIP: gagal buat invoice'); return; }
    $id = $i['id'];
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $id, 'release_date' => date('Y-m-d'), 'release_note' => 'unrelease test',
    ]);
    if (empty($rel['json']['success'])) {
        record('TC-017', 'Unrelease sebelum bayar', null, 'SKIP: release awal gagal msg=' . substr($rel['json']['message'] ?? $rel['raw'], 0, 80));
        return;
    }
    $jrBefore = journal_count_type('project_invoice', $id);
    $un = api($g['user_admin'], 'project.invoiceCRD.php', 'unrelease_invoice', ['id' => $id]);
    $msg = $un['json']['message'] ?? '';
    $okMsg = !empty($un['json']['success']) && strpos($msg, 'dibatalkan') !== false;
    $after = inv($id);
    $okStatus = $after && (string)$after['release_status'] === 'draft';
    $okNull = $after && $after['release_no'] === null && $after['release_date'] === null && $after['released_by'] === null;
    $okNote = $after && (string)$after['release_note'] === '';
    $jrAfter = journal_count_type('project_invoice', $id);
    $okJr = $jrAfter === 0 && $jrBefore > 0;
    $out = api($g['user_admin'], 'project.paymentCRD.php', 'list_outstanding', ['client_id' => $g['clientA']]);
    $ids = array_column($out['json']['data'] ?? [], 'invoice_id');
    $okOut = !in_array($id, $ids, true);
    record('TC-017', 'Unrelease sebelum bayar', ($okMsg && $okStatus && $okNull && $okNote && $okJr && $okOut),
        "msg='{$msg}' | status=" . ($after ? $after['release_status'] : '-') .
        " | no/date/by=" . var_export($after ? [$after['release_no'], $after['release_date'], $after['released_by']] : null, true) .
        " | note='" . ($after ? (string)$after['release_note'] : '-') . "'" .
        " | jurnal before/after={$jrBefore}/{$jrAfter} | list_outstanding=" . ($okOut ? 'hilang' : 'MASIH MUNCUL'));
}

/** TC-018: unrelease invoice yang sudah ada pembayaran -> ditolak. */
function tc018(): void
{
    $g = $GLOBALS['g'];
    // Buat invoice, release, bayar parsial, lalu unrelease harus ditolak.
    $i = make_invoice($g['projA1'], $g['clientA'], 'Unrelease TC-018', 2000000, 0);
    if (!$i) { record('TC-018', 'Unrelease sudah ada pembayaran ditolak', null, 'SKIP: gagal buat invoice'); return; }
    $id = $i['id'];
    $rel0 = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', [
        'id' => $id, 'release_date' => date('Y-m-d'), 'release_note' => '',
    ]);
    if (empty($rel0['json']['success'])) { record('TC-018', 'Unrelease sudah ada pembayaran ditolak', null, 'SKIP: release awal gagal'); return; }
    // Bayar parsial 500rb
    $pay = make_payment($g['clientA'], [['invoice_id' => $id, 'amount_paid' => 500000]], 500000);
    $paid = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $id]);
    if ($paid <= 0.01) {
        record('TC-018', 'Unrelease sudah ada pembayaran ditolak', null,
            "SKIP: gagal bayar parsial (paid={$paid}) msg='" . substr($pay['json']['message'] ?? $pay['raw'], 0, 90) . "'");
        return;
    }
    $un = api($g['user_admin'], 'project.invoiceCRD.php', 'unrelease_invoice', ['id' => $id]);
    $msg = $un['json']['message'] ?? '';
    $ok = empty($un['json']['success']) && strpos($msg, 'sudah memiliki pembayaran') !== false && strpos($msg, 'penerbitannya tidak dapat dibatalkan') !== false;
    $after = inv($id);
    $stillRel = $after && (string)$after['release_status'] === 'released';
    record('TC-018', 'Unrelease sudah ada pembayaran ditolak', ($ok && $stillRel && $paid > 0.01),
        "paid_amount={$paid} | http={$un['http']} | msg='{$msg}' | status DB=" . ($after ? $after['release_status'] : '-'));
}

/* ============================================================
 * 6c. KELOMPOK C — TC-020 s/d TC-030 (Pembayaran Termin & Jurnal Otomatis)
 * ============================================================ */
/** TC-020: bayar termin released IDR lunas -> PPAY tersimpan, paid_amount = amount, jurnal PRJ_PAY_AR. */
function tc020(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Bayar lunas TC-020', 8000000, 0);
    if (!$i) { record('TC-020', 'Bayar termin released (IDR, lunas)', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-020', 'Bayar termin released (IDR, lunas)', null, 'SKIP: release gagal msg=' . substr($rel['json']['message'] ?? $rel['raw'], 0, 80)); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 8000000]], 8000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $no  = $r['json']['data']['payment_no'] ?? '-';
    $okSave = $pid > 0 && strpos((string)$no, 'PPAY-') === 0;
    $inv = inv($i['id']);
    $paid = $inv ? (float)$inv['paid_amount'] : 0.0;
    $okPaid = abs($paid - 8000000.0) <= 0.01;
    $j = $pid > 0 ? assert_pay_journal($pid, 'PRJ_PAY_AR', 8000000) : 'tidak ada jurnal';
    $okJr = strpos($j, 'OK') === 0;
    record('TC-020', 'Bayar termin released (IDR, lunas)', ($okSave && $okPaid && $okJr),
        "payment_no={$no} | paid_amount={$paid} | jurnal PRJ_PAY_AR: {$j} | msg='" . substr($r['json']['message'] ?? $r['raw'], 0, 60) . "'");
}

/** TC-021: bayar sebagian 50% -> status Sebagian, sisa = amount - paid_amount benar. */
function tc021(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Bayar sebagian TC-021', 6000000, 0);
    if (!$i) { record('TC-021', 'Bayar sebagian (50%)', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-021', 'Bayar sebagian (50%)', null, 'SKIP: release gagal'); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 3000000]], 3000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $inv = inv($i['id']);
    $paid = $inv ? (float)$inv['paid_amount'] : 0.0;
    $sisa = $inv ? round((float)$inv['amount'] - $paid, 2) : -1;
    $ok = $pid > 0 && abs($paid - 3000000.0) <= 0.01 && abs($sisa - 3000000.0) <= 0.01;
    // konfirmasi via list_outstanding: sisa (remaining) harus 3.000.000
    $out = api($g['user_admin'], 'project.paymentCRD.php', 'list_outstanding', ['client_id' => $g['clientA']]);
    $rem = null;
    foreach ($out['json']['data'] ?? [] as $o) { if ((int)$o['invoice_id'] === $i['id']) { $rem = (float)$o['remaining']; break; } }
    $okRem = $rem !== null && abs($rem - 3000000.0) <= 0.01;
    record('TC-021', 'Bayar sebagian (50%)', ($ok && $okRem),
        "paid_amount={$paid} | sisa DB={$sisa} | list_outstanding remaining=" . ($rem === null ? 'n/a' : $rem) .
        " | msg='" . substr($r['json']['message'] ?? $r['raw'], 0, 60) . "'");
}

/** TC-022: bayar lebih dari total alokasi -> excess > 0, jurnal PRJ_PAY_DEP, saldo deposit client bertambah, pesan "masuk deposit client". */
function tc022(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Lebih bayar TC-022', 5000000, 0);
    if (!$i) { record('TC-022', 'Lebih bayar -> deposit client', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-022', 'Lebih bayar -> deposit client', null, 'SKIP: release gagal'); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 5000000]], 7000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $msg = $r['json']['message'] ?? '';
    $exc = $pid > 0 ? (float)val("SELECT excess_amount FROM project_payments WHERE id = :p", ['p' => $pid]) : 0.0;
    $dep = $pid > 0 ? (float)val("SELECT COALESCE(SUM(excess_amount),0) - COALESCE(SUM(deposit_applied),0) FROM project_payments WHERE client_id = :c", ['c' => $g['clientA']]) : 0.0;
    // PRJ_PAY_DEP: kredit Uang Muka Penjualan (credit rule) = excess; debit kas = total diterima.
    $jDep = $pid > 0 ? assert_pay_journal($pid, 'PRJ_PAY_DEP', 2000000) : 'tidak ada jurnal';
    // PRJ_PAY_AR pada pembayaran lebih-bayar: Dr Kas 7jt (total diterima) / Cr Piutang 5jt (alokasi).
    // assert_pay_journal(PRJ_PAY_AR, 5000000) akan salah k Debit kas 7jt != 5jt; cek langsung kredit Piutang + debit kas > 0.
    $jArOk = false; $jArTxt = 'tidak ada jurnal';
    if ($pid > 0) {
        $arRule = row("SELECT credit_account_id FROM fiscal_config WHERE rule_code = 'PRJ_PAY_AR' AND is_active");
        $piutangId = (int)$arRule['credit_account_id'];
        $lnAr = payment_journal_lines($pid);
        $piutangCr = $lnAr[$piutangId]['c'] ?? 0.0;
        $kasDr = 0.0; foreach ($lnAr as $l) { if ($l['d'] > $kasDr) { $kasDr = $l['d']; } }
        $jArOk = abs($piutangCr - 5000000) <= 0.01 && abs($kasDr - 7000000) <= 0.01;
        $jArTxt = "DrKas=" . number_format($kasDr, 2) . " CrPiutang(" . $piutangId . ")=" . number_format($piutangCr, 2) . ($jArOk ? ' OK' : ' SALAH');
    }
    $ok = $pid > 0 && abs($exc - 2000000.0) <= 0.01 && abs($dep - 2000000.0) <= 0.01
        && strpos($jDep, 'OK') === 0 && $jArOk
        && strpos($msg, 'masuk deposit client') !== false;
    record('TC-022', 'Lebih bayar -> deposit client', $ok,
        "excess_amount={$exc} | deposit saldo={$dep} | jurnal PRJ_PAY_DEP: {$jDep} | PRJ_PAY_AR (kas 7jt / piutang 5jt): {$jArTxt} | msg='" . substr($msg, 0, 90) . "'");
}

/** TC-023: pakai deposit untuk bayar termin berikutnya -> jurnal PRJ_DEP_APPLY tanpa kas/bank, saldo deposit berkurang. */
function tc023(): void
{
    $g = $GLOBALS['g'];
    $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    if ($depBal <= 0.01) { record('TC-023', 'Pakai deposit client', null, "SKIP: saldo deposit = {$depBal} (TC-022 belum jalan?)"); return; }
    $i = make_invoice($g['projA1'], $g['clientA'], 'Pakai deposit TC-023', 2000000, 0);
    if (!$i) { record('TC-023', 'Pakai deposit client', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-023', 'Pakai deposit client', null, 'SKIP: release gagal'); return; }
    // lunasi penuh dari deposit: tanpa akun kas (account_id kosong), kas diterima = 0, seluruhnya dari deposit
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 2000000]], 0, null, 2, 1.0, 2000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $msg = $r['json']['message'] ?? '';
    $depAfter = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    $j = $pid > 0 ? assert_pay_journal($pid, 'PRJ_DEP_APPLY', 2000000) : 'tidak ada jurnal';
    $ok = $pid > 0 && strpos($j, 'OK') === 0 && abs($depAfter - ($depBal - 2000000)) <= 0.01;
    record('TC-023', 'Pakai deposit client (tanpa kas/bank)', $ok,
        "deposit sebelum={$depBal} sesudah={$depAfter} | jurnal PRJ_DEP_APPLY: {$j} | msg='" . substr($msg, 0, 60) . "'");
}

/** TC-024: lunas penuh dari deposit (tanpa kas) -> diterima (akun kas tidak wajib bila seluruhnya dari deposit). */
function tc024(): void
{
    $g = $GLOBALS['g'];
    // deposit bisa habis terpakai TC-023; buat deposit segar bila perlu (lebih bayar) agar skenario mandiri.
    $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    if ($depBal <= 0.01) {
        $src = make_invoice($g['projA1'], $g['clientA'], 'Sumber deposit TC-024', 1000000, 0);
        if (!$src) { record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', null, 'SKIP: gagal buat invoice sumber deposit'); return; }
        $rel0 = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $src['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
        if (empty($rel0['json']['success'])) { record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', null, 'SKIP: release sumber deposit gagal'); return; }
        $dp = make_payment($g['clientA'], [['invoice_id' => $src['id'], 'amount_paid' => 1000000]], 2500000); // excess 1.500.000
        if (empty($dp['json']['success'])) { record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', null, 'SKIP: gagal buat deposit msg=' . substr($dp['json']['message'] ?? $dp['raw'], 0, 80)); return; }
        $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    }
    $i = make_invoice($g['projA1'], $g['clientA'], 'Lunas deposit TC-024', 1000000, 0);
    if (!$i) { record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', null, 'SKIP: release gagal'); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 0, null, 2, 1.0, 1000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $inv = inv($i['id']);
    $paid = $inv ? (float)$inv['paid_amount'] : 0.0;
    $depAfter = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    $ok = $pid > 0 && abs($paid - 1000000.0) <= 0.01 && abs($depAfter - ($depBal - 1000000)) <= 0.01;
    record('TC-024', 'Lunas penuh dari deposit (tanpa kas)', $ok,
        "payment id={$pid} | paid_amount={$paid} | deposit sebelum={$depBal} sesudah={$depAfter} | msg='" . substr($r['json']['message'] ?? $r['raw'], 0, 80) . "'");
}

/** TC-025: satu pembayaran ke termin 2 proyek milik client yang sama -> semua termin ter-update, satu nomor, jurnal PRJ_PAY_AR total kas. */
function tc025(): void
{
    $g = $GLOBALS['g'];
    $a = make_invoice($g['projA1'], $g['clientA'], 'Multi proyek TC-025 A', 2000000, 0);
    $b = make_invoice($g['projA2'], $g['clientA'], 'Multi proyek TC-025 B', 3000000, 0);
    if (!$a || !$b) { record('TC-025', 'Multi-termin lintas proyek', null, 'SKIP: gagal buat invoice'); return; }
    foreach ([$a, $b] as $x) {
        $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $x['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
        if (empty($rel['json']['success'])) { record('TC-025', 'Multi-termin lintas proyek', null, 'SKIP: release gagal id=' . $x['id']); return; }
    }
    $r = make_payment($g['clientA'], [
        ['invoice_id' => $a['id'], 'amount_paid' => 2000000],
        ['invoice_id' => $b['id'], 'amount_paid' => 3000000],
    ], 5000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $no  = $r['json']['data']['payment_no'] ?? '-';
    $pa = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $a['id']]);
    $pb = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $b['id']]);
    $j = $pid > 0 ? assert_pay_journal($pid, 'PRJ_PAY_AR', 5000000) : 'tidak ada jurnal';
    $ok = $pid > 0 && abs($pa - 2000000) <= 0.01 && abs($pb - 3000000) <= 0.01 && strpos($j, 'OK') === 0;
    record('TC-025', 'Multi-termin lintas proyek', $ok,
        "payment_no={$no} | paid A={$pa} B={$pb} | jurnal PRJ_PAY_AR total 5.000.000: {$j}");
}

/** TC-026: bayar pakai USD + kurs hari itu -> payIdr = pay x rate, jurnal dalam IDR. */
function tc026(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Multi-currency TC-026', 4000000, 0);
    if (!$i) { record('TC-026', 'Multi-currency (USD + kurs)', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-026', 'Multi-currency (USD + kurs)', null, 'SKIP: release gagal'); return; }
    $rate = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_exchange_rate', ['currency_id' => 3, 'date' => date('Y-m-d')])['json']['rate'] ?? 0;
    if ($rate <= 0) { $rate = 15000; }   // tidak ada kurs USD di tabel -> pakai kurs tetap uji
    $payUsd = round(4000000 / $rate, 2);
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => $payUsd]], $payUsd, $GLOBALS['g']['cash_account_id'], 3, $rate);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    $inv = inv($i['id']);
    $paid = $inv ? (float)$inv['paid_amount'] : 0.0;
    $ok = $pid > 0 && abs($paid - 4000000.0) <= 1.0;   // toleransi pembulatan kurs
    $j = $pid > 0 ? assert_pay_journal($pid, 'PRJ_PAY_AR', 4000000) : 'tidak ada jurnal';
    record('TC-026', 'Multi-currency (USD + kurs)', ($ok && strpos($j, 'OK') === 0),
        "rate={$rate} | pay USD={$payUsd} | paid_amount IDR={$paid} | jurnal PRJ_PAY_AR 4.000.000 IDR: {$j} | msg='" . substr($r['json']['message'] ?? $r['raw'], 0, 60) . "'");
}

/** TC-027: hapus pembayaran biasa -> jurnal di-reverse, paid_amount kembali. */
function tc027(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Hapus pembayaran TC-027', 3000000, 0);
    if (!$i) { record('TC-027', 'Hapus pembayaran (jurnal di-reverse)', null, 'SKIP: gagal buat invoice'); return; }
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-027', 'Hapus pembayaran (jurnal di-reverse)', null, 'SKIP: release gagal'); return; }
    $r = make_payment($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 3000000]], 3000000);
    $pid = (int)($r['json']['data']['id'] ?? 0);
    if ($pid <= 0) { record('TC-027', 'Hapus pembayaran (jurnal di-reverse)', null, 'SKIP: save_payment gagal'); return; }
    $jrBefore = payment_journal_line_count($pid);
    $paidBefore = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $i['id']]);
    $d = api($g['user_admin'], 'project.paymentCRD.php', 'delete_payment', ['id' => $pid]);
    $msg = $d['json']['message'] ?? '';
    $okDel = !empty($d['json']['success']) && strpos($msg, 'dihapus') !== false;
    $jrAfter = payment_journal_line_count($pid);
    $paidAfter = (float)val("SELECT paid_amount FROM project_invoices WHERE id = :i", ['i' => $i['id']]);
    $gone = (int)val("SELECT COUNT(*) FROM project_payments WHERE id = :p", ['p' => $pid]) === 0;
    $ok = $okDel && $jrBefore > 0 && $jrAfter === 0 && abs($paidAfter) <= 0.01 && $gone;
    record('TC-027', 'Hapus pembayaran (jurnal di-reverse)', $ok,
        "payment id={$pid} | jurnal lines before/after={$jrBefore}/{$jrAfter} | paid before/after={$paidBefore}/{$paidAfter} | baris hilang=" . ($gone ? 'ya' : 'TIDAK') . " | msg='{$msg}'");
}

/** TC-028: hapus pembayaran sumber deposit terpakai -> ditolak ("deposit yang dihasilkannya sudah dipakai pembayaran lain"). */
function tc028(): void
{
    $g = $GLOBALS['g'];
    // Buat deposit dulu (lebih bayar), lalu pakai deposit itu untuk bayar termin lain, lalu hapus pembayaran sumber deposit.
    $i1 = make_invoice($g['projA1'], $g['clientA'], 'Sumber deposit TC-028', 2000000, 0);
    $i2 = make_invoice($g['projA1'], $g['clientA'], 'Pakai deposit TC-028', 1000000, 0);
    if (!$i1 || !$i2) { record('TC-028', 'Hapus pembayaran sumber deposit terpakai', null, 'SKIP: gagal buat invoice'); return; }
    foreach ([$i1, $i2] as $x) {
        $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $x['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
        if (empty($rel['json']['success'])) { record('TC-028', 'Hapus pembayaran sumber deposit terpakai', null, 'SKIP: release gagal id=' . $x['id']); return; }
    }
    $p1 = make_payment($g['clientA'], [['invoice_id' => $i1['id'], 'amount_paid' => 2000000]], 3500000); // excess 1.500.000 -> deposit
    $pid1 = (int)($p1['json']['data']['id'] ?? 0);
    if ($pid1 <= 0) { record('TC-028', 'Hapus pembayaran sumber deposit terpakai', null, 'SKIP: deposit gagal dibuat'); return; }
    $p2 = make_payment($g['clientA'], [['invoice_id' => $i2['id'], 'amount_paid' => 1000000]], 0, null, 2, 1.0, 1000000); // lunas dari deposit
    $pid2 = (int)($p2['json']['data']['id'] ?? 0);
    if ($pid2 <= 0) { record('TC-028', 'Hapus pembayaran sumber deposit terpakai', null, 'SKIP: pembayaran deposit gagal msg=' . substr($p2['json']['message'] ?? $p2['raw'], 0, 80)); return; }
    $d = api($g['user_admin'], 'project.paymentCRD.php', 'delete_payment', ['id' => $pid1]);
    $msg = $d['json']['message'] ?? '';
    $ok = empty($d['json']['success']) && strpos($msg, 'tidak bisa dihapus') !== false && strpos($msg, 'sudah dipakai pembayaran lain') !== false;
    $still = (int)val("SELECT COUNT(*) FROM project_payments WHERE id = :p", ['p' => $pid1]) === 1;
    record('TC-028', 'Hapus pembayaran sumber deposit terpakai', ($ok && $still),
        "http={$d['http']} | msg='{$msg}' | pembayaran sumber masih ada=" . ($still ? 'ya' : 'TIDAK'));
}

/** TC-029: cetak invoice released -> PDF header "No. Dokumen Tagihan: TAG-… | Tgl Terbit: …"; bila DRAFT: "STATUS: DRAFT …". */
function tc029(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Cetak PDF TC-029', 2500000, 0);
    if (!$i) { record('TC-029', 'Cetak invoice released (PDF)', null, 'SKIP: gagal buat invoice'); return; }
    // dulu (draft): harus ada "STATUS: DRAFT". PDF lewat http_get supaya cookie PHPSESSID user_admin ikut terbawa.
    $d1 = http_get('/pdf/project.invoice.php?id=' . $i['id'], $g['user_admin'])['body'];
    $okDraft = strpos($d1, 'STATUS: DRAFT') !== false;
    $rel = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { record('TC-029', 'Cetak invoice released (PDF)', null, 'SKIP: release gagal'); return; }
    $d2 = http_get('/pdf/project.invoice.php?id=' . $i['id'], $g['user_admin'])['body'];
    $no = (string)inv($i['id'])['release_no'];
    $okRel = $d2 !== false && strpos($d2, 'No. Dokumen Tagihan: ' . $no) !== false && strpos($d2, 'Tgl Terbit:') !== false;
    record('TC-029', 'Cetak invoice released (PDF)', ($okDraft && $okRel),
        "draft: " . ($okDraft ? 'STATUS: DRAFT ada' : 'TIDAK ADA') . " | released: header TAG + Tgl Terbit " . ($okRel ? 'ada' : 'TIDAK ADA') . " (release_no={$no})");
}

/** TC-030: no. faktur pajak sama di 2 invoice -> ditolak ("No. faktur pajak … sudah dipakai invoice …"). */
function tc030(): void
{
    $g = $GLOBALS['g'];
    $a = make_invoice($g['projA1'], $g['clientA'], 'Faktur ganda TC-030 A', 1000000, 11);
    $b = make_invoice($g['projA1'], $g['clientA'], 'Faktur ganda TC-030 B', 1000000, 11);
    if (!$a || !$b) { record('TC-030', 'Faktur pajak duplikat', null, 'SKIP: gagal buat invoice'); return; }
    $fp = '000.000-' . date('ym') . '.TC030';
    $r1 = api($g['user_admin'], 'project.invoiceCRD.php', 'update_faktur_pajak', ['id' => $a['id'], 'faktur_pajak_no' => $fp, 'faktur_pajak_date' => date('Y-m-d')]);
    $ok1 = !empty($r1['json']['success']);
    $r2 = api($g['user_admin'], 'project.invoiceCRD.php', 'update_faktur_pajak', ['id' => $b['id'], 'faktur_pajak_no' => $fp, 'faktur_pajak_date' => date('Y-m-d')]);
    $msg = $r2['json']['message'] ?? '';
    $ok2 = empty($r2['json']['success']) && strpos($msg, 'sudah dipakai') !== false;
    // bersihkan: kosongkan faktur invoice A (boleh walau sudah ada pembayaran)
    api($g['user_admin'], 'project.invoiceCRD.php', 'update_faktur_pajak', ['id' => $a['id'], 'faktur_pajak_no' => '', 'faktur_pajak_date' => '']);
    record('TC-030', 'Faktur pajak duplikat ditolak', ($ok1 && $ok2),
        "set A: " . ($ok1 ? 'OK' : 'GAGAL') . " | duplikat B: " . ($ok2 ? 'ditolak' : 'DITERIMA') . " | msg='{$msg}'");
}

/* ============================================================
 * 6d. KELOMPOK D — TC-040 s/d TC-057 (Skenario kegagalan pembayaran: rollback atomik,
 *     pencegahan double release, hak akses level, validasi metode/catatan)
 * ============================================================
 * Semua skenario pembayaran negatif TC-040..TC-052 memakai helper d_make_draft_invoice():
 * invoice DRAFT (bukan released) karena server hanya mengizinkan alokasi pembayaran ke
 * termin RELEASED — sehingga validasi bisnis berikutnya (draft/silang client/lebih bayar/dobel/
 * kurs/deposit/dana/total) baru tercapai bila datanya lolos pemeriksaan release_status.
 * Setiap TC membuktikan ROLLBACK: tidak ada PPAY baru, paid_amount tidak berubah,
 * tidak ada journal_entries reference_type='project_payment' untuk invoice tersebut.
 * ----------------------------------------------------------------- */

/** [Kelompok D] Buat invoice DRAFT + RELEASE (via API) untuk bahan alokasi pembayaran; kembalikan ['id','inv_no'] atau null. */
function d_make_draft_invoice(int $projectId, int $clientId, string $termin, float $dpp, float $ppnRate): ?array
{
    $i = make_invoice($projectId, $clientId, $termin, $dpp, $ppnRate);
    if (!$i) { return null; }
    $rel = api($GLOBALS['g']['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($rel['json']['success'])) { return null; }
    return $i;
}

/** [Kelompok D] Buat pembayaran yang DIHARAPKAN DITOLAK; kembalikan ['ok'=>bool,'msg'=>string,'pid'=>int,'paid'=>float,'jr'=>int]. */
function d_expect_rejected(int $clientId, array $items, float $total, ?int $accountId = null, int $currencyId = 2, float $exchRate = 1.0, float $depositApply = 0.0): array
{
    $g = $GLOBALS['g'];
    $invId = $items[0]['invoice_id'] ?? 0;
    $pidBefore = (int)val("SELECT COUNT(*) FROM public.project_payments");
    $paidBefore = (float)val("SELECT COALESCE(paid_amount,0) FROM public.project_invoices WHERE id = :i", ['i' => $invId]);
    $jrBefore = (int)val("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'project_payment' AND reference_id IN (SELECT id FROM project_payment_items WHERE invoice_id = :i)", ['i' => $invId]);
    $r = make_payment($clientId, $items, $total, $accountId, $currencyId, $exchRate, $depositApply);
    return [
        'ok'      => empty($r['json']['success']),
        'msg'     => (string)($r['json']['message'] ?? ''),
        'pid'     => (int)($r['json']['data']['id'] ?? 0),
        'paid'    => (float)val("SELECT COALESCE(paid_amount,0) FROM public.project_invoices WHERE id = :i", ['i' => $invId]),
        'jr'      => (int)val("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'project_payment' AND reference_id IN (SELECT id FROM project_payment_items WHERE invoice_id = :i)", ['i' => $invId]),
        'noNewPay' => (int)val("SELECT COUNT(*) FROM public.project_payments") === $pidBefore,
        'paidSame' => abs($paidBefore - (float)val("SELECT COALESCE(paid_amount,0) FROM public.project_invoices WHERE id = :i", ['i' => $invId])) <= 0.01,
    ];
}

/** [Kelompok D] Assert roll-back penuh: pesan error sesuai + tanpa PPAY baru + paid_amount tidak berubah + tanpa jurnal pembayaran. */
function d_assert_rollback(string $tc, string $scenario, array $res, string $expectMsg, string $extraNote = ''): void
{
    $g = $GLOBALS['g'];
    $okMsg  = $res['ok'] && strpos($res['msg'], $expectMsg) !== false;
    $okRoll = $res['noNewPay'] && $res['paidSame'] && $res['jr'] === 0;
    record($tc, $scenario, ($okMsg && $okRoll),
        "msg='" . substr($res['msg'], 0, 90) . "' | harap='{$expectMsg}'" .
        " | PPAY baru=" . ($res['noNewPay'] ? 'tidak ada' : 'ADA!') .
        " | paid_amount berubah=" . ($res['paidSame'] ? 'tidak' : 'YA!') .
        " | jurnal pembayaran=" . $res['jr'] . ($extraNote !== '' ? " | {$extraNote}" : ''));
}

/** TC-040: bayar termin DRAFT -> ditolak "…belum diterbitkan (status draft). Release tagihan dulu di menu Tagihan ke Klien." + rollback. */
function tc040(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Bayar draft TC-040', 1000000, 0);
    if (!$i) { record('TC-040', 'Bayar termin DRAFT', null, 'SKIP: gagal buat invoice'); return; }
    // invoice sengaja DITINGGALKAN DRAFT (tidak di-release)
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000);
    d_assert_rollback('TC-040', 'Bayar termin DRAFT', $res, 'belum diterbitkan (status draft)',
        "status DB='" . (string)inv($i['id'])['release_status'] . "'");
}

/** TC-041: termin silang client -> ditolak "…bukan milik client ini." + rollback. */
function tc041(): void
{
    $g = $GLOBALS['g'];
    // Invoice di Client B dialokasikan ke pembayaran Client A (atau sebaliknya).
    $i = d_make_draft_invoice($g['projB1'], $g['clientB'], 'Silang client TC-041', 1000000, 0);
    if (!$i) { record('TC-041', 'Termin silang client', null, 'SKIP: gagal buat invoice Client B'); return; }
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000);
    d_assert_rollback('TC-041', 'Termin silang client', $res, 'bukan milik client ini',
        "inv {$i['inv_no']} milik clientB={$g['clientB']} dialokasikan ke clientA={$g['clientA']}");
}

/** TC-042: bayar melebihi sisa -> ditolak "…nilai bayar … melebihi sisa tagihan (…)". */
function tc042(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Lebih bayar TC-042', 2000000, 0);
    if (!$i) { record('TC-042', 'Bayar melebihi sisa', null, 'SKIP: gagal buat invoice'); return; }
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 3000000]], 3000000);
    d_assert_rollback('TC-042', 'Bayar melebihi sisa', $res, 'melebihi sisa tagihan');
}

/** TC-043: termin dobel (invoice sama 2x) -> ditolak "…termin dobel.". */
function tc043(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Termin dobel TC-043', 2000000, 0);
    if (!$i) { record('TC-043', 'Termin dobel', null, 'SKIP: gagal buat invoice'); return; }
    $res = d_expect_rejected($g['clientA'], [
        ['invoice_id' => $i['id'], 'amount_paid' => 1000000],
        ['invoice_id' => $i['id'], 'amount_paid' => 1000000],
    ], 2000000);
    d_assert_rollback('TC-043', 'Termin dobel', $res, 'termin dobel');
}

/** TC-044: tanpa akun kas & tanpa deposit -> ditolak "Akun Kas/Bank wajib dipilih." */
function tc044(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Tanpa kas TC-044', 1000000, 0);
    if (!$i) { record('TC-044', 'Kas/bank kosong', null, 'SKIP: gagal buat invoice'); return; }
    // account_id=0 (tanpa kas) & deposit_apply=0 -> akun kas wajib
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000, 0, 2, 1.0, 0.0);
    d_assert_rollback('TC-044', 'Kas/bank kosong', $res, 'Akun Kas/Bank wajib dipilih.');
}

/** TC-045: metode tidak valid ("TRANSFER") -> ditolak "Metode pembayaran tidak valid." */
function tc045(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Metode salah TC-045', 1000000, 0);
    if (!$i) { record('TC-045', 'Metode tidak valid', null, 'SKIP: gagal buat invoice'); return; }
    // make_payment() memakai method tetap BANK_TRANSFER; panggil api() langsung dengan method salah.
    $pidBefore = (int)val("SELECT COUNT(*) FROM public.project_payments");
    $paidBefore = (float)val("SELECT COALESCE(paid_amount,0) FROM public.project_invoices WHERE id = :i", ['i' => $i['id']]);
    $r = api($g['user_admin'], 'project.paymentCRD.php', 'save_payment', [
        'client_id' => $g['clientA'], 'payment_date' => date('Y-m-d'), 'account_id' => $g['cash_account_id'],
        'payment_method' => 'TRANSFER', 'total_amount' => 1000000, 'currency_id' => 2, 'exch_rate' => 1,
        'reference_no' => '', 'notes' => 'data uji E2E TC-045', 'deposit_apply' => 0,
        'items' => json_encode([['invoice_id' => $i['id'], 'amount_paid' => 1000000]]),
    ]);
    $msg = (string)($r['json']['message'] ?? '');
    $okMsg = empty($r['json']['success']) && strpos($msg, 'Metode pembayaran tidak valid.') !== false;
    $okRoll = (int)val("SELECT COUNT(*) FROM public.project_payments") === $pidBefore
        && abs((float)val("SELECT COALESCE(paid_amount,0) FROM public.project_invoices WHERE id = :i", ['i' => $i['id']]) - $paidBefore) <= 0.01;
    record('TC-045', 'Metode tidak valid', ($okMsg && $okRoll),
        "msg='{$msg}' | PPAY baru=" . ((int)val("SELECT COUNT(*) FROM public.project_payments") === $pidBefore ? 'tidak ada' : 'ADA!') .
        " | paid_amount berubah=" . ($okRoll ? 'tidak' : 'YA!'));
}

/** TC-046: kurs kosong untuk non-IDR (currency=USD, exch_rate=0) -> ditolak "Kurs USD wajib diisi." */
function tc046(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Kurs kosong TC-046', 1000000, 0);
    if (!$i) { record('TC-046', 'Kurs kosong untuk non-IDR', null, 'SKIP: gagal buat invoice'); return; }
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 66.67]], 66.67, $g['cash_account_id'], 3, 0.0);
    d_assert_rollback('TC-046', 'Kurs kosong untuk non-IDR', $res, 'Kurs USD wajib diisi.');
}

/** TC-047: deposit tidak cukup (depIn > saldo) -> ditolak "Deposit client tidak cukup (tersedia …)". */
function tc047(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Deposit kurang TC-047', 1000000, 0);
    if (!$i) { record('TC-047', 'Deposit tidak cukup', null, 'SKIP: gagal buat invoice'); return; }
    // Jamin saldo deposit 0 (atau kecil) supaya depIn 5.000.000 pasti > saldo.
    $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 0, 0, 2, 1.0, 5000000);
    d_assert_rollback('TC-047', 'Deposit tidak cukup', $res, 'Deposit client tidak cukup (tersedia',
        "saldo deposit clientA saat uji={$depBal} | depIn=5.000.000");
}

/** TC-048: deposit > alokasi (depIn > total alokasi termin) -> ditolak "Deposit yang dipakai melebihi total alokasi termin." */
function tc048(): void
{
    $g = $GLOBALS['g'];
    // Perlu saldo deposit >= alokasi+1 agar uji sampai pada pemeriksaan "deposit > alokasi" (bukan "deposit tidak cukup").
    $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    $note = '';
    if ($depBal < 3000000) {
        // buat deposit segar via lebih bayar (invoice sumber + pembayaran berlebih)
        $src = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Sumber deposit TC-048', 1000000, 0);
        if (!$src) { record('TC-048', 'Deposit > alokasi', null, 'SKIP: gagal buat invoice sumber deposit'); return; }
        $dp = make_payment($g['clientA'], [['invoice_id' => $src['id'], 'amount_paid' => 1000000]], 4000000); // excess 3.000.000
        if (empty($dp['json']['success'])) { record('TC-048', 'Deposit > alokasi', null, 'SKIP: gagal buat deposit msg=' . substr($dp['json']['message'] ?? $dp['raw'], 0, 80)); return; }
        $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
        $note = "deposit dibuat setup (lebih bayar 3.000.000)";
    }
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Deposit > alokasi TC-048', 1000000, 0);
    if (!$i) { record('TC-048', 'Deposit > alokasi', null, 'SKIP: gagal buat invoice'); return; }
    // alokasi hanya 1.000.000 tapi depIn 2.000.000 (> alokasi) & kas diterima 0
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 0, 0, 2, 1.0, 2000000);
    d_assert_rollback('TC-048', 'Deposit > alokasi', $res, 'Deposit yang dipakai melebihi total alokasi termin.', $note);
}

/** TC-049: kas + deposit < alokasi -> ditolak "Kas diterima + deposit lebih kecil dari total alokasi termin." */
function tc049(): void
{
    $g = $GLOBALS['g'];
    $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
    $note = '';
    if ($depBal < 500000) {
        $src = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Sumber deposit TC-049', 1000000, 0);
        if (!$src) { record('TC-049', 'Kas + deposit < alokasi', null, 'SKIP: gagal buat invoice sumber deposit'); return; }
        $dp = make_payment($g['clientA'], [['invoice_id' => $src['id'], 'amount_paid' => 1000000]], 1500000); // excess 500.000
        if (empty($dp['json']['success'])) { record('TC-049', 'Kas + deposit < alokasi', null, 'SKIP: gagal buat deposit msg=' . substr($dp['json']['message'] ?? $dp['raw'], 0, 80)); return; }
        $depBal = (float)api($g['user_admin'], 'project.paymentCRD.php', 'get_client_deposit', ['client_id' => $g['clientA']])['json']['balance'] ?? 0;
        $note = "deposit dibuat setup (lebih bayar 500.000)";
    }
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Dana kurang TC-049', 2000000, 0);
    if (!$i) { record('TC-049', 'Kas + deposit < alokasi', null, 'SKIP: gagal buat invoice'); return; }
    // alokasi 2.000.000; kas diterima 1.000.000 + deposit 500.000 = 1.500.000 < alokasi
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 2000000]], 1000000, $g['cash_account_id'], 2, 1.0, 500000);
    d_assert_rollback('TC-049', 'Kas + deposit < alokasi', $res, 'Kas diterima + deposit lebih kecil dari total alokasi termin.', $note);
}

/** TC-050: jumlah diterima <= 0 (total = 0) -> ditolak "Jumlah diterima harus lebih dari 0." */
function tc050(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Total nol TC-050', 1000000, 0);
    if (!$i) { record('TC-050', 'Jumlah diterima <= 0', null, 'SKIP: gagal buat invoice'); return; }
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 0);
    d_assert_rollback('TC-050', 'Jumlah diterima <= 0', $res, 'Jumlah diterima harus lebih dari 0.');
}

/** TC-051: jumlah diterima < alokasi (total < alokasi) -> ditolak "Jumlah diterima lebih kecil dari total alokasi termin." */
function tc051(): void
{
    $g = $GLOBALS['g'];
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Dana kurang TC-051', 2000000, 0);
    if (!$i) { record('TC-051', 'Jumlah diterima < alokasi', null, 'SKIP: gagal buat invoice'); return; }
    $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 2000000]], 1000000);
    d_assert_rollback('TC-051', 'Jumlah diterima < alokasi', $res, 'Jumlah diterima lebih kecil dari total alokasi termin.');
}

/** TC-052: tanpa alokasi (items kosong) -> ditolak "Alokasikan pembayaran ke minimal satu termin." */
function tc052(): void
{
    $g = $GLOBALS['g'];
    $res = d_expect_rejected($g['clientA'], [], 1000000);
    d_assert_rollback('TC-052', 'Tanpa alokasi', $res, 'Alokasikan pembayaran ke minimal satu termin.');
}

/** TC-053: periode akuntansi tutup -> AutoPostJournal gagal ("Periode … sudah ditutup") -> ROLLBACK: tanpa PPAY, paid_amount tidak berubah. */
function tc053(): void
{
    $g = $GLOBALS['g'];
    $db = db();
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Periode tutup TC-053', 1000000, 0);
    if (!$i) { record('TC-053', 'Periode akuntansi tutup', null, 'SKIP: gagal buat invoice'); return; }
    // Cari periode yang mencakup hari ini lalu tutup sementara (restore di akhir, dalam finally).
    $per = row("SELECT id, period_name, is_closed FROM accounting_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date");
    if (!$per) { record('TC-053', 'Periode akuntansi tutup', null, 'SKIP: tidak ada periode untuk tanggal hari ini'); return; }
    $wasClosed = ($per['is_closed'] === 't' || $per['is_closed'] === true || $per['is_closed'] === 1 || $per['is_closed'] === '1');
    if ($wasClosed) { record('TC-053', 'Periode akuntansi tutup', null, 'SKIP: periode ' . $per['period_name'] . ' sudah tertutup (uji butuh periode open -> tutup sementara)'); return; }
    $db->prepare("UPDATE accounting_periods SET is_closed = true WHERE id = :id")->execute(['id' => $per['id']]);
    try {
        $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000);
        // pesan asli AutoPostJournal (diteruskan PpBusinessError 'Gagal posting jurnal: …')
        $okMsg = $res['ok'] && (strpos($res['msg'], 'sudah ditutup') !== false || strpos($res['msg'], 'Gagal posting jurnal') !== false);
        $okRoll = $res['noNewPay'] && $res['paidSame'] && $res['jr'] === 0;
        record('TC-053', 'Periode akuntansi tutup -> ROLLBACK', ($okMsg && $okRoll),
            "periode {$per['period_name']} ditutup sementara | msg='" . substr($res['msg'], 0, 110) . "'" .
            " | PPAY baru=" . ($res['noNewPay'] ? 'tidak ada' : 'ADA!') .
            " | paid_amount berubah=" . ($res['paidSame'] ? 'tidak' : 'YA!') . " | jurnal pembayaran=" . $res['jr']);
    } finally {
        // [FIX-PDO-BOOL] JANGAN binding bool langsung ke kolom boolean PDO-pgSQL: true/false jadi '' -> SQLSTATE 22P02 fatal.
        $db->prepare("UPDATE accounting_periods SET is_closed = :c WHERE id = :id")->execute(['c' => ($wasClosed ? 't' : 'f'), 'id' => $per['id']]);
    }
}

/** TC-054: rule fiscal nonaktif (PRJ_PAY_AR) -> AutoPostJournal gagal -> ROLLBACK; pesan error asli tampil. */
function tc054(): void
{
    $g = $GLOBALS['g'];
    $db = db();
    $i = d_make_draft_invoice($g['projA1'], $g['clientA'], 'Rule nonaktif TC-054', 1000000, 0);
    if (!$i) { record('TC-054', 'Rule fiscal nonaktif', null, 'SKIP: gagal buat invoice'); return; }
    $rule = row("SELECT is_active FROM fiscal_config WHERE rule_code = 'PRJ_PAY_AR'");
    if (!$rule) { record('TC-054', 'Rule fiscal nonaktif', null, 'SKIP: rule PRJ_PAY_AR tidak ditemukan'); return; }
    $wasActive = ($rule['is_active'] === 't' || $rule['is_active'] === true || $rule['is_active'] === 1 || $rule['is_active'] === '1');
    if (!$wasActive) { record('TC-054', 'Rule fiscal nonaktif', null, 'SKIP: PRJ_PAY_AR sudah nonaktif (uji butuh aktif -> nonaktif sementara)'); return; }
    $db->prepare("UPDATE fiscal_config SET is_active = false WHERE rule_code = 'PRJ_PAY_AR'")->execute();
    try {
        $res = d_expect_rejected($g['clientA'], [['invoice_id' => $i['id'], 'amount_paid' => 1000000]], 1000000);
        $okMsg = $res['ok'] && (strpos($res['msg'], 'PRJ_PAY_AR') !== false || strpos($res['msg'], 'Gagal posting jurnal') !== false);
        $okRoll = $res['noNewPay'] && $res['paidSame'] && $res['jr'] === 0;
        record('TC-054', 'Rule fiscal nonaktif -> ROLLBACK', ($okMsg && $okRoll),
            "PRJ_PAY_AR dinonaktifkan sementara | msg='" . substr($res['msg'], 0, 110) . "'" .
            " | PPAY baru=" . ($res['noNewPay'] ? 'tidak ada' : 'ADA!') .
            " | paid_amount berubah=" . ($res['paidSame'] ? 'tidak' : 'YA!') . " | jurnal pembayaran=" . $res['jr']);
    } finally {
        // [FIX-PDO-BOOL] binding 't'/'f' eksplisit, bukan bool (lihat TC-053).
        $db->prepare("UPDATE fiscal_config SET is_active = :a WHERE rule_code = 'PRJ_PAY_AR'")->execute(['a' => ($wasActive ? 't' : 'f')]);
    }
}

/** TC-055: user level < 3 panggil release_invoice -> HTTP 403 "Hak akses tidak mencukupi untuk operasi invoice proyek ini." */
function tc055(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Hak akses TC-055', 1000000, 0);
    if (!$i) { record('TC-055', 'Akses level rendah ditolak', null, 'SKIP: gagal buat invoice'); return; }
    $r = api($g['user_low'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    $msg = (string)($r['json']['message'] ?? '');
    $okHttp = $r['http'] === 403;
    $okMsg  = strpos($msg, 'Hak akses tidak mencukupi untuk operasi invoice proyek ini.') !== false;
    $st = inv($i['id']);
    $okDb = $st && (string)$st['release_status'] === 'draft' && $st['release_no'] === null;
    record('TC-055', 'Akses level rendah ditolak', ($okHttp && $okMsg && $okDb),
        "http={$r['http']} | msg='{$msg}' | status DB=" . ($st ? $st['release_status'] : '-') . " | release_no=" . var_export($st ? $st['release_no'] : null, true));
}

/** TC-056: metode bukan POST (GET) untuk release_invoice -> HTTP 405 "Metode harus POST untuk perubahan invoice proyek." */
function tc056(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Metode GET TC-056', 1000000, 0);
    if (!$i) { record('TC-056', 'Metode bukan POST', null, 'SKIP: gagal buat invoice'); return; }
    // GET dengan query string task/id (tanpa Origin: GET bukan transaksi, CSRF guard tidak menghalangi).
    $url = CFG_BASE_URL . '/php-crud/project.invoiceCRD.php?' . http_build_query(['task' => 'release_invoice', 'id' => $i['id'], 'release_date' => date('Y-m-d')]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEFILE => '/tmp/e2e_cookie_' . $g['user_admin'], CURLOPT_COOKIEJAR => '/tmp/e2e_cookie_' . $g['user_admin'],
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
    ]);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = json_decode($body, true);
    $msg = is_array($json) ? (string)($json['message'] ?? '') : '';
    $okHttp = $code === 405;
    $okMsg  = strpos($msg, 'Metode harus POST untuk perubahan invoice proyek.') !== false;
    $st = inv($i['id']);
    $okDb = $st && (string)$st['release_status'] === 'draft' && $st['release_no'] === null;
    record('TC-056', 'Metode bukan POST (GET)', ($okHttp && $okMsg && $okDb),
        "http={$code} | msg='{$msg}' | curl_err='{$err}' | status DB=" . ($st ? $st['release_status'] : '-'));
}

/** TC-057: release_note > 200 karakter -> ditolak "Catatan penerbitan terlalu panjang (maksimal 200 karakter)." */
function tc057(): void
{
    $g = $GLOBALS['g'];
    $i = make_invoice($g['projA1'], $g['clientA'], 'Catatan panjang TC-057', 1000000, 0);
    if (!$i) { record('TC-057', 'release_note > 200 karakter', null, 'SKIP: gagal buat invoice'); return; }
    $note = str_repeat('A', 201);   // 201 karakter, melampaui batas 200
    $r = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => $note]);
    $msg = (string)($r['json']['message'] ?? '');
    $ok = empty($r['json']['success']) && strpos($msg, 'Catatan penerbitan terlalu panjang (maksimal 200 karakter).') !== false;
    $st = inv($i['id']);
    $still = $st && (string)$st['release_status'] === 'draft' && $st['release_no'] === null;
    record('TC-057', 'release_note > 200 karakter ditolak', ($ok && $still),
        "http={$r['http']} | msg='{$msg}' | status DB=" . ($st ? $st['release_status'] : '-') . " | release_no=" . var_export($st ? $st['release_no'] : null, true) . " | panjang note=" . strlen($note));
}

/** [Kelompok D] Double release pencegahan: satu invoice tidak bisa terbit 2x nomor TAG; bulk berisi invoice released ikut ditolak & yang lain tetap terbit. */
function tc058(): void
{
    $g = $GLOBALS['g'];
    // 1) Release satu invoice dua kali: yang kedua harus ditolak, release_no tidak berubah.
    $i = make_invoice($g['projA1'], $g['clientA'], 'Double release TC-058', 1000000, 0);
    if (!$i) { record('TC-058', 'Pencegahan double release', null, 'SKIP: gagal buat invoice'); return; }
    $r1 = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    if (empty($r1['json']['success'])) { record('TC-058', 'Pencegahan double release', null, 'SKIP: release pertama gagal'); return; }
    $no1 = (string)inv($i['id'])['release_no'];
    $r2 = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoice', ['id' => $i['id'], 'release_date' => date('Y-m-d'), 'release_note' => '']);
    $msg2 = (string)($r2['json']['message'] ?? '');
    $okReject = empty($r2['json']['success']) && strpos($msg2, 'sudah diterbitkan') !== false;
    $no2 = (string)inv($i['id'])['release_no'];
    $okSame = $no2 === $no1 && tag_ok($no2);
    // 2) Bulk release berisi 1 draft + 1 sudah released: seluruh batch ditolak (transaksi atomik), draft TETAP draft, nomor tidak naik.
    $d = make_invoice($g['projA1'], $g['clientA'], 'Bulk campuran TC-058', 1000000, 0);
    if (!$d) { record('TC-058', 'Pencegahan double release', ($okReject && $okSame), 'SKIP: gagal buat draft untuk bulk'); return; }
    $noBefore = d_count_release_no();
    $rb = api($g['user_admin'], 'project.invoiceCRD.php', 'release_invoices', ['ids' => json_encode([$d['id'], $i['id']]), 'release_date' => date('Y-m-d'), 'release_note' => '']);
    $msgb = (string)($rb['json']['message'] ?? '');
    $okBatch = empty($rb['json']['success']) && strpos($msgb, 'sudah diterbitkan') !== false;
    $draftStill = (string)inv($d['id'])['release_status'] === 'draft';
    $noAfter = d_count_release_no();
    $okNoBump = $noAfter === $noBefore;
    record('TC-058', 'Pencegahan double release (single + bulk atomik)', ($okReject && $okSame && $okBatch && $draftStill && $okNoBump),
        "release_no tetap='{$no1}' | release ke-2: msg='{$msg2}' | bulk: msg='" . substr($msgb, 0, 70) . "'" .
        " | draft tetap draft=" . ($draftStill ? 'ya' : 'TIDAK') . " | jumlah TAG {$noBefore}->{$noAfter}");
}

/** [Kelompok D] Hitung jumlah release_no format TAG-yymm-NNNNN bulan berjalan (untuk bukti nomor tidak naik saat rollback). */
function d_count_release_no(): int
{
    $ym = date('ym');
    return (int)val("SELECT COUNT(*) FROM public.project_invoices
                      WHERE release_no IS NOT NULL AND release_no ~ :pat", [':pat' => '^TAG-' . $ym . '-[0-9]{5}$']);
}

/* ============================================================
 * 7. MAIN
 * ==========================================================*/
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

    echo "=== E2E Release Tagihan — Kelompok A (TC-001..TC-006) + B (TC-010..TC-018) + C (TC-020..TC-030) + D (TC-040..TC-058) ===\n";
    echo "DB: " . CFG_DB_NAME . " @ " . CFG_DB_HOST . " | URL: " . CFG_BASE_URL . "\n";

    if ($doSetup) {
        echo "[setup] membuat data uji (Client A 2 proyek, Client B 1 proyek, Invoice 1-3 + fixture released, user level 1/3/4)...\n";
        setup_test_data();
        echo "[setup] selesai. clientA={$GLOBALS['g']['clientA']} projA1={$GLOBALS['g']['projA1']} projA2={$GLOBALS['g']['projA2']} projB1={$GLOBALS['g']['projB1']} inv1={$GLOBALS['g']['inv1']} invR={$GLOBALS['g']['invR']}\n";
    }

    $groupA = 'Kelompok A — Persiapan & Invoice (fase DRAFT)';
    $testsA = ['tc001' => 'TC-001', 'tc002' => 'TC-002', 'tc003' => 'TC-003', 'tc004' => 'TC-004', 'tc005' => 'TC-005', 'tc006' => 'TC-006'];
    if ($group && $group !== 'A') { echo "[run] Kelompok A dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok A:\n";
        foreach ($testsA as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupA);
    }

    $groupB = 'Kelompok B — Release (draft -> released) & Unrelease';
    $testsB = ['tc010' => 'TC-010', 'tc011' => 'TC-011', 'tc012' => 'TC-012', 'tc013' => 'TC-013', 'tc014' => 'TC-014', 'tc015' => 'TC-015', 'tc016' => 'TC-016', 'tc017' => 'TC-017', 'tc018' => 'TC-018'];
    if ($group && $group !== 'B') { echo "[run] Kelompok B dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok B:\n";
        foreach ($testsB as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupB);
    }

    $groupC = 'Kelompok C — Pembayaran Termin & Jurnal Otomatis';
    $testsC = ['tc020' => 'TC-020', 'tc021' => 'TC-021', 'tc022' => 'TC-022', 'tc023' => 'TC-023', 'tc024' => 'TC-024', 'tc025' => 'TC-025', 'tc026' => 'TC-026', 'tc027' => 'TC-027', 'tc028' => 'TC-028', 'tc029' => 'TC-029', 'tc030' => 'TC-030'];
    if ($group && $group !== 'C') { echo "[run] Kelompok C dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok C:\n";
        foreach ($testsC as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupC);
    }

    $groupD = 'Kelompok D — Skenario Negatif, Rollback Atomik, Double-Release, Hak Akses';
    $testsD = ['tc040' => 'TC-040', 'tc041' => 'TC-041', 'tc042' => 'TC-042', 'tc043' => 'TC-043', 'tc044' => 'TC-044', 'tc045' => 'TC-045', 'tc046' => 'TC-046', 'tc047' => 'TC-047', 'tc048' => 'TC-048', 'tc049' => 'TC-049', 'tc050' => 'TC-050', 'tc051' => 'TC-051', 'tc052' => 'TC-052', 'tc053' => 'TC-053', 'tc054' => 'TC-054', 'tc055' => 'TC-055', 'tc056' => 'TC-056', 'tc057' => 'TC-057', 'tc058' => 'TC-058'];
    if ($group && $group !== 'D') { echo "[run] Kelompok D dilewati (--group={$group}).\n"; }
    else {
        echo "[run] eksekusi Kelompok D:\n";
        foreach ($testsD as $fn => $tc) {
            if ($only && !in_array($tc, $only, true)) { continue; }
            $fn();
        }
        write_report($GLOBALS['g']['results'], $groupD);
    }

    if ($doCleanup) {
        echo "[cleanup] menghapus data uji...\n";
        cleanup_test_data();
        echo "[cleanup] selesai.\n";
    } else {
        echo "[cleanup] DILEWATI (--keep/--setup) — data uji tetap di DB untuk investigasi.\n";
    }

    $p = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === true));
    $f = count(array_filter($GLOBALS['g']['results'], fn($r) => $r['status'] === false));
    echo "\nHasil Keseluruhan (A+B+C+D): PASS={$p} FAIL={$f} | laporan: " . CFG_REPORT . "\n";
    exit($f > 0 ? 1 : 0);
}

// Lib mode untuk runner kelompok lain: jangan jalankan main().
if (!defined('E2E_AS_LIB')) {
    main($argv);
}
