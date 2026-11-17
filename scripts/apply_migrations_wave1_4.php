<?php
/**
 * scripts/apply_migrations_wave1_4.php
 *
 * Fase 2 pilihan #1 + #5 (Business Gap Analysis AR/AP/GL) — target DB proyekGMN_test SAJA.
 *  - #1: terapkan migrasi gelombang 1-4 (SKEMA saja; bulk_release DILEWATI sesuai instruksi user)
 *  - #5: pastikan rule PRJ_DEP_APPLY ada di fiscal_config (Dr 2200 / Cr 1200, aktif)
 *
 * Semua file migrasi idempoten (ADD COLUMN IF NOT EXISTS / DO-block guard).
 * Cara jalan: php scripts/apply_migrations_wave1_4.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
error_reporting(E_ALL & ~E_NOTICE);

const DB_HOST = '127.0.0.1';
const DB_PORT = '5432';
const DB_NAME = 'proyekGMN_test';   // WAJIB test, bukan proyekGMN
const DB_USER = 'postgres';
const DB_PASS = '';                 // dari env bila perlu

$files = array(
    // gelombang 1-4 (SKEMA) — bulk_release DILEWATI (butuh parameter & ubah data)
    'sql/2026-11-14.project_invoice_release.sql',
    'sql/2026-11-15.menu_tagihan_klien_rename.sql',
    'sql/2026-11-15_patch_gelombang1_readiness_rab.sql',
    'sql/2026-11-15_patch_gelombang2_client_supplied.sql',
    'sql/2026-11-16.project_actual_cost_view.sql',
    'sql/20261115.project_invoice_retention.sql',
    'sql/20261115.project_payment_fx_and_allocation.sql',
    'sql/20261115.grn_approval_and_supplier_payment.sql',
    'sql/20261115.subcon_inspection_certification.sql',
    'sql/20261115.task_material_allocation.sql',
    'sql/20261115.year_close.sql',
    'sql/2026-11-12.ap_currency.sql',
    'sql/2026-11-12.subcon_and_leftover_upgrade.sql',
);

function dbconn()
{
    $c = @pg_connect('host=' . DB_HOST . ' port=' . DB_PORT . ' dbname=' . DB_NAME
        . ' user=' . DB_USER . ' password=' . DB_PASS);
    if (!$c) { fwrite(STDERR, "CONNECT-FAIL: " . pg_last_error() . "\n"); exit(1); }
    return $c;
}

$c = dbconn();
echo "TARGET DB: " . DB_NAME . "\n";

// --- 0. Snapshot baseline -------------------------------------------------
function snap($c, $sql, $label)
{
    $r = @pg_query($c, $sql);
    if (!$r) { echo "$label = QUERY-ERROR: " . pg_last_error($c) . "\n"; return; }
    $n = pg_num_rows($r);
    echo "$label = $n\n";
}
echo "--- BASELINE ---\n";
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='project_invoices' AND column_name LIKE 'retention%'", 'baseline: inv retention cols');
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='project_payments' AND column_name='total_amount_base'", 'baseline: pay total_amount_base');
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='grn_hdr' AND column_name='approval_status'", 'baseline: grn approval_status');
snap($c, "SELECT 1 FROM fiscal_config WHERE rule_code='PRJ_DEP_APPLY'", 'baseline: PRJ_DEP_APPLY rule');
snap($c, "SELECT 1 FROM pg_trigger WHERE tgname='trg_check_project_payment_within_invoice'", 'baseline: trg project payment guard');
snap($c, "SELECT 1 FROM pg_trigger WHERE tgname='trg_check_supplier_payment_within_invoice'", 'baseline: trg supplier payment guard');
snap($c, "SELECT id,title FROM m_form_name WHERE id=156 AND title='TAGIHAN KLIEN'", 'baseline: menu 156 rename');
pg_free_result(pg_query($c, "SELECT 1")); // noop

// --- 1. Jalankan migrasi --------------------------------------------------
echo "--- APPLY MIGRATIONS ---\n";
$fail = 0;
foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    if (!is_file($path)) { echo "SKIP (file tidak ada): $f\n"; continue; }
    $sql = file_get_contents($path);
    // Hapus baris \set psql (bulk_release tidak ada di daftar, tapi jaga-jaga)
    $sql = preg_replace('/^\\s*\\set\s.*$/m', '', $sql);
    $res = @pg_query($c, $sql);
    if (!$res) {
        $fail++;
        echo "FAIL  : $f\n       " . trim(pg_last_error($c)) . "\n";
    } else {
        echo "OK    : $f\n";
    }
    // hasil multi-statement sudah habis dikonsumsi pg_query (pg_query hanya eksekusi satu stack;
    // PostgreSQL menjalankan seluruh string sebagai satu rangkaian bila tanpa error).
}
if ($fail > 0) { echo "ABORT: $fail migrasi gagal. Periksa error di atas.\n"; exit(2); }

// --- 2. Pilihan #5: pastikan PRJ_DEP_APPLY + pasangan akun benar ----------
echo "--- FIX #5: PRJ_DEP_APPLY ---\n";
$sql = "INSERT INTO fiscal_config (rule_code, rule_name, debit_account_id, credit_account_id, is_active)
        SELECT 'PRJ_DEP_APPLY', 'Pemakaian deposit client untuk bayar termin', d.id, c.id, true
          FROM chart_of_accounts d, chart_of_accounts c
         WHERE d.account_code = '2200' AND c.account_code = '1200'
        ON CONFLICT (rule_code) DO UPDATE
           SET rule_name = EXCLUDED.rule_name,
               debit_account_id = EXCLUDED.debit_account_id,
               credit_account_id = EXCLUDED.credit_account_id,
               is_active = EXCLUDED.is_active
         WHERE fiscal_config.debit_account_id  <> EXCLUDED.debit_account_id
            OR fiscal_config.credit_account_id <> EXCLUDED.credit_account_id
            OR fiscal_config.is_active        <> EXCLUDED.is_active";
$res = @pg_query($c, $sql);
if (!$res) { echo "FAIL PRJ_DEP_APPLY upsert: " . pg_last_error($c) . "\n"; exit(3); }
echo "OK    : PRJ_DEP_APPLY upsert\n";

// --- 3. Verifikasi pasca-terapkan -----------------------------------------
echo "--- POST-CHECK ---\n";
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='project_invoices' AND column_name LIKE 'retention%'", 'post: inv retention cols (harus 2)');
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='project_payments' AND column_name='total_amount_base'", 'post: pay total_amount_base (harus 1)');
snap($c, "SELECT column_name FROM information_schema.columns WHERE table_name='grn_hdr' AND column_name='approval_status'", 'post: grn approval_status (harus 1)');
snap($c, "SELECT 1 FROM fiscal_config WHERE rule_code='PRJ_DEP_APPLY'", 'post: PRJ_DEP_APPLY rule (harus 1)');
snap($c, "SELECT 1 FROM pg_trigger WHERE tgname='trg_check_project_payment_within_invoice'", 'post: trg project payment guard');
snap($c, "SELECT 1 FROM pg_trigger WHERE tgname='trg_check_supplier_payment_within_invoice'", 'post: trg supplier payment guard');
snap($c, "SELECT id,title FROM m_form_name WHERE id=156 AND title='TAGIHAN KLIEN'", 'post: menu 156 rename');
$r = pg_query($c, "SELECT f.rule_code, d.account_code AS dr, c.account_code AS cr, f.is_active
                     FROM fiscal_config f
                     JOIN chart_of_accounts d ON d.id=f.debit_account_id
                     JOIN chart_of_accounts c ON c.id=f.credit_account_id
                    WHERE f.rule_code='PRJ_DEP_APPLY'");
$row = pg_fetch_assoc($r);
echo "post: PRJ_DEP_APPLY detail = " . json_encode($row) . "\n";
pg_close($c);
echo "SELESAI.\n";
