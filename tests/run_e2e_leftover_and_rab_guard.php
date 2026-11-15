<?php
/**
 * tests/run_e2e_leftover_and_rab_guard.php
 *
 * Runner E2E "Patch Gelombang 1" (audit hulu-hilir 2026-11-15 §9):
 *   - F-3.1  reference_type 'material_leftover' terdaftar di AutoPostJournal & JrnPost,
 *            retur sisa material proyek (material.leftoverCRD.php) IN + jurnal WH_LEFTOVER + reverse.
 *   - F-5.1  rule PRJ_DEP_APPLY siap di fiscal_config (migrasi
 *            sql/2026-11-15_patch_gelombang1_readiness_rab.sql, idempoten, dijalankan setup bila belum).
 *   - F-5.2  save_rab menolak hapus item RAB yang sudah dipakai PO (rabItemBlock).
 *   - F-5.3  kategori RAB disamakan DB vs PHP, case-insensitive, tanpa demote diam-diam ke 'Lain-lain'.
 *
 * Semua mutasi lewat HTTP endpoint asli (php -S 127.0.0.1:8098, RAB_DB_NAME=proyekGMN_test)
 * sehingga session/akses/CSRF teruji seperti produksi. Data uji ditandai TAG E2E-LGR dan
 * dibersihkan saat --cleanup.
 *
 * Pakai:  php tests/run_e2e_leftover_and_rab_guard.php [--setup|--cleanup] [--keep]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

/* ---------------- Konfigurasi ---------------- */
const CFG_DB_HOST = '127.0.0.1';
const CFG_DB_PORT = '5432';
const CFG_DB_NAME = 'proyekGMN_test';
const CFG_DB_USER = 'postgres';
const CFG_BASE_URL = 'http://127.0.0.1:8098';
const CFG_ORIGIN   = 'http://127.0.0.1:8098';
const CFG_TAG      = 'E2E-LGR';

$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : [];
$doSetup  = in_array('--setup', $argv, true);
$doCleanup = in_array('--cleanup', $argv, true);
$keep = in_array('--keep', $argv, true);

$results = [];
$pass = $fail = 0;
function rec(string $id, string $title, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; }
    echo '  ' . ($ok ? '[PASS]' : '[FAIL]') . " {$id}  {$title}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

/* ============================================================
 * 1. HELPER DB (PDO prepared = pg_query_params-equivalent)
 * ==========================================================*/
function db(): PDO
{
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $pass = getenv('E2E_DB_PASSWORD') ?: '';
    try {
        $pdo = new PDO('pgsql:host=' . CFG_DB_HOST . ';port=' . CFG_DB_PORT . ';dbname=' . CFG_DB_NAME, CFG_DB_USER, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET DateStyle = 'ISO, MDY'");
    } catch (Throwable $e) {
        fwrite(STDERR, "[DB] koneksi gagal: " . $e->getMessage() . "\n");
        exit(1);
    }
    return $pdo;
}
function row(string $sql, array $p = []): ?array
{
    $st = db()->prepare($sql); $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
function val(string $sql, array $p = [])
{
    $r = row($sql, $p);
    return $r ? array_values($r)[0] : null;
}
function esc(string $s): string
{
    return substr(preg_replace('/[^A-Za-z0-9_-]+/', '-', $s), 0, 40);
}

/* ============================================================
 * 2. HELPER HTTP (Origin CSRF + cookie jar per user)
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
/** Panggil endpoint CRUD sebagai userId; kembalikan ['http','json','raw']. */
function api(int $userId, string $crud, string $task, array $fields = []): array
{
    $fields['task'] = $task;
    $r = http_post('/php-crud/' . $crud, $fields, $userId);
    $json = json_decode($r['body'], true);
    return ['http' => $r['http'], 'json' => is_array($json) ? $json : null, 'raw' => $r['body'], 'err' => $r['err']];
}
/** Login via login.php; verifikasi dengan panggilan terautentikasi. */
function login(int $userId, string $loginName, string $password): bool
{
    $jar = '/tmp/e2e_cookie_' . $userId;
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
        fwrite(STDERR, "[LOGIN] user={$loginName} http={$code} err={$err} body0=" . substr($body, 0, 120) . "\n");
        return false;
    }
    $chk = api($userId, 'project.invoiceCRD.php', 'list_clients', []);
    return $chk['http'] === 200 && !empty($chk['json']['success']);
}

/* ============================================================
 * 3. SETUP / CLEANUP
 * ==========================================================*/
$g = [
    'adminId' => 0, 'adminName' => '', 'adminPass' => '',
    'projId' => 0, 'whId' => 0, 'matId' => 0, 'expId' => 0, 'supId' => 0, 'curId' => 2,
    'mhdrId' => 0, 'moiId' => 0, 'poId' => 0, 'poItemId' => 0, 'bidId' => 0, 'bidId2' => 0,
    'made_prj_dep_apply' => false, 'made_checks' => false,
    'created' => ['project' => [], 'mout' => [], 'lo_hdr' => [], 'po' => [], 'budget' => [], 'user' => []],
];

/** Idempoten: jalankan migrasi Patch Gelombang 1 (enum reference_type + PRJ_DEP_APPLY + CHECK kategori). */
function apply_wave1_migration(): string
{
    $db = db();
    $log = [];
    // F-3.1: nilai enum reference_type yang belum ada (ALTER TYPE ADD VALUE di luar transaksi)
    foreach (['material_leftover', 'subcon_leftover_buyback', 'subcon_invoice', 'subcon_invoice_pay'] as $ev) {
        $n = (int)val("SELECT COUNT(*) FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                        WHERE t.typname = 'reference_type' AND e.enumlabel = :v", ['v' => $ev]);
        if (!$n) {
            $db->exec("ALTER TYPE public.reference_type ADD VALUE '{$ev}'");
            $log[] = "enum+{$ev}";
        }
    }
    // F-5.1: PRJ_DEP_APPLY (Dr 2200 Uang Muka Penjualan / Cr 1200 Piutang Usaha, aktif)
    $has = (int)val("SELECT COUNT(*) FROM fiscal_config WHERE rule_code = 'PRJ_DEP_APPLY'");
    if (!$has) {
        $db->prepare("INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
                      SELECT 'PRJ_DEP_APPLY', 'Pemakaian deposit client untuk bayar termin', d.id, c.id, true
                        FROM chart_of_accounts d, chart_of_accounts c
                       WHERE d.account_code = '2200' AND c.account_code = '1200'")->execute();
        $log[] = 'PRJ_DEP_APPLY inserted';
    } else {
        $db->prepare("UPDATE fiscal_config f SET debit_account_id = d.id, credit_account_id = c.id, is_active = true
                        FROM chart_of_accounts d, chart_of_accounts c
                       WHERE f.rule_code = 'PRJ_DEP_APPLY' AND d.account_code = '2200' AND c.account_code = '1200'
                         AND (f.debit_account_id <> d.id OR f.credit_account_id <> c.id OR NOT f.is_active)")->execute();
        $log[] = 'PRJ_DEP_APPLY already present';
    }
    // F-5.3: samakan CHECK kategori budget_items/expense_master dengan RAB_CATEGORIES di PHP.
    // Constraint LAMA (dengan 'Alat') DIGANTI -- bukan sekadar ditambah bila belum ada.
    $db->exec("UPDATE budget_items SET category = 'Peralatan' WHERE category = 'Alat'");
    $db->exec("ALTER TABLE budget_items DROP CONSTRAINT IF EXISTS budget_items_category_check");
    $db->exec("ALTER TABLE budget_items ADD CONSTRAINT budget_items_category_check
               CHECK (category IN ('Material','Upah','Peralatan','Transport','Konsumsi','Lain-lain'))");
    $db->exec("UPDATE expense_master SET category = 'Peralatan' WHERE category = 'Alat'");
    $db->exec("ALTER TABLE expense_master DROP CONSTRAINT IF EXISTS expense_master_category_check");
    $db->exec("ALTER TABLE expense_master ADD CONSTRAINT expense_master_category_check
               CHECK (category IN ('Upah','Peralatan','Transport','Konsumsi','Lain-lain'))");
    // 2d) updated_at budget_items + trigger (perbaikan SQLSTATE[42703] path UPDATE save_rab non-material)
    $hasCol = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'budget_items' AND column_name = 'updated_at'");
    if (!$hasCol) { $db->exec("ALTER TABLE budget_items ADD COLUMN updated_at timestamp without time zone"); }
    $db->exec("CREATE OR REPLACE FUNCTION fn_budget_items_touch() RETURNS trigger AS \$\$
BEGIN NEW.updated_at := NOW(); RETURN NEW; END \$\$ LANGUAGE plpgsql");
    $db->exec("DROP TRIGGER IF EXISTS trg_budget_items_touch ON budget_items");
    $db->exec("CREATE TRIGGER trg_budget_items_touch BEFORE UPDATE ON budget_items FOR EACH ROW EXECUTE PROCEDURE fn_budget_items_touch()");
    $log[] = 'category CHECKs aligned to PHP list';
    $log[] = 'budget_items.updated_at + trigger ok';
    return implode('; ', $log);
}

/** Buat user harness level 4 (admin) khusus uji; login dengan password plain (login.php membandingkan plain). */
function ensure_admin_user(): int
{
    global $g;
    $uname = CFG_TAG . '-admin-' . date('His');
    db()->prepare("INSERT INTO m_user_system (login_name, password, active) VALUES (:u, :p, true) RETURNING id")
        ->execute(['u' => $uname, 'p' => $uname . '-pass']);
    $uid = (int)db()->lastInsertId();
    foreach ([132, 156, 145, 118, 157] as $formId) {               // 132=RAB, 157=Material Left Over (dari m_form_name)
        db()->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level) VALUES (:uid, :f, 4)")
            ->execute(['uid' => $uid, 'f' => $formId]);
    }
    $g['adminId'] = $uid; $g['adminName'] = $uname; $g['adminPass'] = $uname . '-pass';
    $g['created']['user'][] = $uid;
    return $uid;
}

function cleanup_all(): void
{
    global $g;
    $db = db();
    $ids = $g['created'];
    if ($ids['lo_hdr']) {
        foreach ($ids['lo_hdr'] as $h) {
            $db->prepare("DELETE FROM stock_movements WHERE reference_type = 'MATERIAL_LEFTOVER' AND reference_id = :h")->execute([':h' => $h]);
            $db->prepare("DELETE FROM journal_entries WHERE reference_type = 'material_leftover' AND reference_id = :h")->execute([':h' => $h]);
            $db->prepare("DELETE FROM material_leftover_items WHERE header_id = :h")->execute([':h' => $h]);
            $db->prepare("DELETE FROM material_leftover_header WHERE id = :h")->execute([':h' => $h]);
        }
    }
    if ($ids['mout']) {
        foreach ($ids['mout'] as $h) {
            $db->prepare("DELETE FROM material_out_items WHERE material_out_id = :h")->execute([':h' => $h]);
            $db->prepare("DELETE FROM material_out_header WHERE id = :h")->execute([':h' => $h]);
        }
    }
    if ($ids['po']) {
        foreach ($ids['po'] as $p) {
            $db->prepare("DELETE FROM po_items WHERE po_id = :p")->execute([':p' => $p]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :p")->execute([':p' => $p]);
        }
    }
    if ($ids['budget']) {
        foreach ($ids['budget'] as $b) { $db->prepare("DELETE FROM budget_items WHERE id = :b")->execute([':b' => $b]); }
    }
    if ($ids['project']) {
        foreach ($ids['project'] as $p) { $db->prepare("DELETE FROM projects WHERE id = :p")->execute([':p' => $p]); }
    }
    // hapus user harness + menu-access-nya (JANGAN sentuh user asli seperti eddy)
    if ($g['adminId'] && in_array($g['adminId'], $ids['user'] ?? [], true)) {
        $db->prepare("DELETE FROM m_user_access_menu WHERE user_id = :u")->execute([':u' => $g['adminId']]);
        $db->prepare("DELETE FROM m_user_system WHERE id = :u")->execute([':u' => $g['adminId']]);
    }
    $jar = '/tmp/e2e_cookie_' . $g['adminId'];
    if (is_file($jar)) { unlink($jar); }
    echo "[CLEANUP] selesai.\n";
}

/* ============================================================
 * 4. TEST CASES
 * ==========================================================*/

// ---------- TC-LGR-01..03: retur sisa material proyek (F-3.1) ----------
function tc_leftover_flow(): void
{
    global $g;
    $pid = $g['projId']; $wh = $g['whId']; $mid = $g['matId'];
    $today = date('Y-m-d');

    // TC-LGR-01: posting retur sisa -> stok IN + jurnal WH_LEFTOVER (Dr 1500 / Cr 1700)
    $r = api($g['adminId'], 'material.leftoverCRD.php', 'save', [
        'project_id' => $pid, 'warehouse_id' => $wh, 'doc_date' => $today,
        'remark' => CFG_TAG . ' retur sisa',
        'details' => json_encode([['material_id' => $mid, 'qty' => 1, 'notes' => 'E2E gelombang 1']]),
    ]);
    $ok = !empty($r['json']['success']) && !empty($r['json']['data']['id']);
    rec('TC-LGR-01', 'Retur sisa material proyek sukses (stok IN + jurnal WH_LEFTOVER)', $ok,
        $ok ? 'hdrId=' . $r['json']['data']['id'] : substr($r['raw'], 0, 180));
    if (!$ok) { return; }
    $hdrId = (int)$r['json']['data']['id'];
    $g['created']['lo_hdr'][] = $hdrId;

    $mov = row("SELECT movement_type, reference_type, qty, unit_cost FROM stock_movements
                 WHERE reference_type = 'MATERIAL_LEFTOVER' AND reference_id = :h", ['h' => $hdrId]);
    rec('TC-LGR-02', 'stock_movements IN tercatat (reference_type MATERIAL_LEFTOVER)',
        (bool)$mov && $mov['movement_type'] === 'IN' && (float)$mov['qty'] > 0,
        $mov ? "type={$mov['movement_type']} qty={$mov['qty']}" : 'tidak ada baris movement');

    $whr = row("SELECT debit_account_id AS dr, credit_account_id AS cr FROM fiscal_config WHERE rule_code = 'WH_LEFTOVER'");
    $stL = db()->prepare("SELECT l.account_id, l.debit, l.credit FROM journal_entries e
                              JOIN journal_lines l ON l.journal_id = e.id
                             WHERE e.reference_type = 'material_leftover' AND e.reference_id = :h
                             ORDER BY l.debit DESC, l.id");
    $stL->execute(['h' => $hdrId]);
    $lines = $stL->fetchAll(PDO::FETCH_ASSOC);
    $okJr = $whr && count($lines) === 2
        && (int)$lines[0]['account_id'] === (int)$whr['dr'] && (float)$lines[0]['debit'] > 0 && (float)$lines[0]['credit'] == 0
        && (int)$lines[1]['account_id'] === (int)$whr['cr'] && (float)$lines[1]['credit'] > 0 && (float)$lines[1]['debit'] == 0;
    rec('TC-LGR-03', 'Jurnal WH_LEFTOVER terbit (Dr Persediaan 1500 / Cr WIP 1700) reference_type material_leftover',
        $okJr, $okJr ? 'Dr ' . $lines[0]['account_id'] . ' / Cr ' . $lines[1]['account_id'] : 'jurnal tak sesuai');

    // TC-LGR-04: reverse -> stok & jurnal ikut batal
    $rv = api($g['adminId'], 'material.leftoverCRD.php', 'reverse', ['id' => $hdrId]);
    $okRv = !empty($rv['json']['success']);
    rec('TC-LGR-04', 'Reverse retur sisa berhasil (stok & jurnal dibatalkan)', $okRv,
        $okRv ? '' : substr($rv['raw'], 0, 180));
    if (!$okRv) { return; }
    $mov2 = (int)val("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'MATERIAL_LEFTOVER' AND reference_id = :h", ['h' => $hdrId]);
    $je2  = (int)val("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'material_leftover' AND reference_id = :h", ['h' => $hdrId]);
    $st   = row("SELECT status FROM material_leftover_header WHERE id = :h", ['h' => $hdrId]);
    rec('TC-LGR-05', 'Setelah reverse: movement=0, jurnal=0, status=reversed',
        $mov2 === 0 && $je2 === 0 && $st && $st['status'] === 'reversed',
        "mov={$mov2} je={$je2} status=" . ($st ? $st['status'] : '-'));
}

// ---------- TC-LGR-10..12: guard hapus RAB + kategori (F-5.2 & F-5.3) ----------
function tc_rab_guard(): void
{
    global $g;
    $pid = $g['projId'];

    // TC-LGR-10: save_rab menolak hapus item RAB yang sudah dipakai PO
    $r = api($g['adminId'], 'rabCRD.php', 'save_rab', [
        'project_id' => $pid, 'currency_id' => $g['curId'], 'exch_rate' => 1, 'rate_date' => date('Y-m-d'),
        // grid TANPA material proyek (bid dipakai PO) dan TANPA expense -> keduanya lama akan dihapus
        'items' => json_encode([
            ['category' => 'Material', 'material_id' => 0, 'expense_id' => 0, 'item_name' => 'x', 'budget_qty' => 0, 'unit_price' => 0],
        ]),
    ]);
    $body = (string)$r['raw'];
    $ok = !empty($r['json']) && empty($r['json']['success']) && stripos($body, 'tidak dapat dihapus') !== false && stripos($body, 'PO') !== false;
    rec('TC-LGR-10', 'save_rab menolak hapus item RAB yang sudah dipakai PO (guard F-5.2)', $ok,
        $ok ? 'pesan: ' . substr($body, 0, 120) : substr($body, 0, 200));

    // pastikan item belum terhapus (transaksi rollback)
    $still = (int)val("SELECT COUNT(*) FROM budget_items WHERE id = :b AND project_id = :p", ['b' => $g['bidId'], 'p' => $pid]);
    rec('TC-LGR-11', 'Item RAB yang dipakai PO masih utuh di budget_items (rollback ACID)', $still === 1, "count={$still}");

    // TC-LGR-12: kategori 'PERALATAN' (huruf besar) dinormalisasi -> 'Peralatan', tersimpan tanpa demote
    $r2 = api($g['adminId'], 'rabCRD.php', 'save_rab', [
        'project_id' => $pid, 'currency_id' => $g['curId'], 'exch_rate' => 1, 'rate_date' => date('Y-m-d'),
        'items' => json_encode([
            ['category' => 'Material', 'material_id' => $g['matId'], 'expense_id' => 0, 'item_name' => 'Semen E2E', 'budget_qty' => 10, 'unit_price' => 5000, 'unit' => 'Sak'],
            ['category' => 'PERALATAN', 'material_id' => 0, 'expense_id' => $g['expId'], 'item_name' => 'Sewa Excavator', 'budget_qty' => 1, 'unit_price' => 1000000, 'unit' => 'ls'],
        ]),
    ]);
    $ok2 = !empty($r2['json']['success']);
    rec('TC-LGR-12', 'Kategori "PERALATAN" (upper) ternormalisasi & tersimpan (case-insensitive F-5.3)', $ok2,
        $ok2 ? '' : substr((string)$r2['raw'], 0, 200));
    if ($ok2) {
        $cat = val("SELECT category FROM budget_items WHERE project_id = :p AND expense_id = :e", ['p' => $pid, 'e' => $g['expId']]);
        rec('TC-LGR-13', 'budget_items.category tersimpan "Peralatan" (bukan demote Lain-lain)', $cat === 'Peralatan', "cat={$cat}");
    }

    // TC-LGR-14: kategori tidak dikenal -> error eksplisit (tanpa demote diam-diam)
    $r3 = api($g['adminId'], 'rabCRD.php', 'save_rab', [
        'project_id' => $pid, 'currency_id' => $g['curId'], 'exch_rate' => 1, 'rate_date' => date('Y-m-d'),
        'items' => json_encode([
            ['category' => 'Material', 'material_id' => $g['matId'], 'expense_id' => 0, 'item_name' => 'Semen E2E', 'budget_qty' => 10, 'unit_price' => 5000, 'unit' => 'Sak'],
            ['category' => 'Honor', 'material_id' => 0, 'expense_id' => $g['expId'], 'item_name' => 'Honor', 'budget_qty' => 1, 'unit_price' => 1000, 'unit' => 'ls'],
        ]),
    ]);
    $body3 = (string)$r3['raw'];
    $ok3 = !empty($r3['json']) && empty($r3['json']['success']) && stripos($body3, 'Kategori RAB tidak valid') !== false;
    rec('TC-LGR-14', 'Kategori tak dikenal "Honor" ditolak dengan pesan eksplisit', $ok3, substr($body3, 0, 160));
}

// ---------- TC-LGR-20: F-5.1 readiness PRJ_DEP_APPLY ----------
function tc_dep_apply_readiness(): void
{
    global $g;
    $r = row("SELECT f.rule_code, d.account_code AS dr, c.account_code AS cr, f.is_active
                FROM fiscal_config f
                JOIN chart_of_accounts d ON d.id = f.debit_account_id
                JOIN chart_of_accounts c ON c.id = f.credit_account_id
               WHERE f.rule_code = 'PRJ_DEP_APPLY'");
    rec('TC-LGR-20', 'PRJ_DEP_APPLY aktif di fiscal_config (Dr 2200 / Cr 1200)',
        (bool)$r && $r['dr'] === '2200' && $r['cr'] === '1200' && in_array($r['is_active'], [true, 't', 'true', 1, '1'], true),
        $r ? "Dr {$r['dr']} / Cr {$r['cr']} active=" . var_export($r['is_active'], true) : 'rule tidak ditemukan');
}

/* ============================================================
 * 5. MAIN
 * ==========================================================*/
echo "=== E2E Leftover Return & RAB Guard (Patch Gelombang 1) ===\n";

// setup awal (wajib) meski mode --cleanup tidak dipakai
$admin = row("SELECT id, login_name, password FROM m_user_system WHERE login_name = 'eddy' AND active = true ORDER BY id LIMIT 1");
if (!$admin) { fwrite(STDERR, "User admin 'eddy' tidak ditemukan.\n"); exit(1); }
$g['adminId'] = (int)$admin['id'];
$g['adminName'] = $admin['login_name'];
$g['adminPass'] = $admin['password'];

if ($doCleanup) {
    cleanup_all();
    echo "Selesai (cleanup).\n";
    exit(0);
}

// Migrasi F-5.1/F-5.3 (idempoten)
$mig = apply_wave1_migration();
echo "[SETUP] migrasi: {$mig}\n";

// User admin harness (level 4) + login (session + FormAccess)
ensure_admin_user();
if (!login($g['adminId'], $g['adminName'], $g['adminPass'])) {
    fwrite(STDERR, "Login admin harness gagal.\n");
    exit(1);
}
echo "[SETUP] login user={$g['adminName']} ok\n";

// Proyek uji (status Belum Mulai + contract_value diisi agar RAB lolos plafon)
$db = db();
$tag = CFG_TAG . '-' . date('His');
$db->prepare("INSERT INTO projects (name, status, contract_value, budget, created_at) VALUES (:n, 'Belum Mulai', 100000000, 100000000, NOW()) RETURNING id")
   ->execute(['n' => $tag . ' Proyek']);
$g['projId'] = (int)$db->lastInsertId();
$g['created']['project'][] = $g['projId'];

// Gudang tujuan retur sisa (gudang bersama / pusat aktif) + material + expense + supplier + currency
$g['whId']  = (int)val("SELECT id FROM warehouses WHERE active = true AND project_id IS NULL ORDER BY id LIMIT 1");
$g['matId'] = (int)val("SELECT id FROM materials WHERE active = true ORDER BY id LIMIT 1");
$g['expId'] = (int)val("SELECT id FROM expense_master WHERE active = true AND category = 'Peralatan' ORDER BY id LIMIT 1");
$g['supId'] = (int)val("SELECT id FROM m_suppliers WHERE active = true ORDER BY id LIMIT 1");
echo "[SETUP] proj={$g['projId']} wh={$g['whId']} mat={$g['matId']} exp={$g['expId']} sup={$g['supId']}\n";

// --- Fixture issue material ke proyek (pemicu qty issue bersih > 0 untuk retur sisa) ---
$db->prepare("INSERT INTO material_out_header (ref_no, project_id, warehouse_id, issue_date, issue_type, status, remark, issued_by, created_at)
              VALUES (:no, :p, :w, CURRENT_DATE, 'PROJECT', 'posted', :nm, :u, NOW()) RETURNING id")
   ->execute(['no' => $tag . '-MO', 'p' => $g['projId'], 'w' => $g['whId'], 'nm' => CFG_TAG . ' issue', 'u' => $g['adminId']]);
$g['mhdrId'] = (int)$db->lastInsertId();
$g['created']['mout'][] = $g['mhdrId'];
$db->prepare("INSERT INTO material_out_items (material_out_id, material_id, qty_out, unit_cost, amount)
              VALUES (:h, :m, 10, 68000, 680000)")
   ->execute(['h' => $g['mhdrId'], 'm' => $g['matId']]);
$g['moiId'] = (int)$db->lastInsertId();

// --- Fixture RAB: material dipakai PO + expense bebas ---
$db->prepare("INSERT INTO budget_items (project_id, material_id, category, item_name, unit, qty_plan, unit_price_plan, created_at)
              VALUES (:p, :m, 'Material', 'Semen E2E', 'Sak', 100, 5000, NOW()) RETURNING id")
   ->execute(['p' => $g['projId'], 'm' => $g['matId']]);
$g['bidId'] = (int)$db->lastInsertId();
$g['created']['budget'][] = $g['bidId'];
$db->prepare("INSERT INTO budget_items (project_id, expense_id, category, item_name, unit, qty_plan, unit_price_plan, created_at)
              VALUES (:p, :e, 'Peralatan', 'Sewa Excavator E2E', 'ls', 10, 100000, NOW()) RETURNING id")
   ->execute(['p' => $g['projId'], 'e' => $g['expId']]);
$g['bidId2'] = (int)$db->lastInsertId();
$g['created']['budget'][] = $g['bidId2'];

// --- Fixture PO Draft yang memakai bidId (pemicu guard F-5.2) ---
$db->prepare("INSERT INTO purchase_orders (po_number, project_id, supplier_id, status, po_date, notes, currency_id, exch_rate, budget_currency_id, budget_exch_rate, created_at, updated_at)
              VALUES (:no, :p, :s, 'Disetujui', CURRENT_DATE, :nt, :c, 1, :c, 1, NOW(), NOW()) RETURNING id")
   ->execute(['no' => $tag . '-PO', 'p' => $g['projId'], 's' => $g['supId'], 'nt' => CFG_TAG . ' guard hapus RAB', 'c' => $g['curId']]);
$g['poId'] = (int)$db->lastInsertId();
$g['created']['po'][] = $g['poId'];
$db->prepare("INSERT INTO po_items (po_id, budget_item_id, material_id, description, qty, unit, unit_price, currency_id, exch_rate, amount_base, amount_budget)
              VALUES (:po, :b, :m, 'Semen E2E', 10, 'Sak', 5000, :c, 1, 50000, 50000)")
   ->execute(['po' => $g['poId'], 'b' => $g['bidId'], 'm' => $g['matId'], 'c' => $g['curId']]);
$g['poItemId'] = (int)$db->lastInsertId();
echo "[SETUP] fixture issue/RAB/PO selesai\n";

// --- Jalankan test case ---
echo "\n-- Kelompok A: retur sisa material proyek (F-3.1) --\n";
tc_leftover_flow();
echo "\n-- Kelompok B: guard hapus RAB & kategori (F-5.2 / F-5.3) --\n";
tc_rab_guard();
echo "\n-- Kelompok C: readiness PRJ_DEP_APPLY (F-5.1) --\n";
tc_dep_apply_readiness();

if (!$keep) { cleanup_all(); }

echo "\nHasil E2E Leftover & RAB Guard: PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
