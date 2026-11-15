<?php
/**
 * function.journal.stock.php  [NEW 2026-10-11]
 * ------------------------------------------------------------------
 * Jurnal otomatis untuk transaksi stok KELUAR:
 *   - Issue material ke proyek      : Dr WIP (1700)            / Cr Persediaan Bahan Baku (1500)
 *   - Kirim retur supplier          : Dr GRN Clearing (2600)   / Cr Persediaan Bahan Baku (1500)
 *   - Retur diselesaikan via Kredit : Dr Hutang Usaha (2100)   / Cr GRN Clearing (2600)
 *   - Retur Ganti Barang            : tidak perlu jurnal (RCV pengganti sudah Dr 1500 / Cr 2600)
 *
 * [FIX 2026-10-11] mb_substr() diganti JrnSub() -> tetap jalan walau ekstensi php-mbstring belum terpasang
 *   (error sebelumnya: 'Call to undefined function mb_substr()' -> HTTP 500 saat Konfirmasi Dikirim).
 *
 * Mapping akun diambil dari fiscal_config (rule_code), BUKAN hardcode:
 *   ISSUE_PROJECT | RETURN_SUPPLIER | RETURN_CREDIT_NOTE
 * Jurnal langsung berstatus 'posted' (konsisten dengan jurnal WHR).
 *
 * Bergantung pada helper yang sudah dipakai CRD lain:
 *   WhRow(), WhRows(), WhQ(), WhBool(), WhException, CreateRefNo()
 * Semua error dilempar sebagai WhException -> CRD pemanggil sudah ROLLBACK,
 * jadi stok dan jurnal selalu tersimpan atau batal bersama (ACID).
 */

if (!defined('JRN_REF_ISSUE'))  { define('JRN_REF_ISSUE',  'material_out'); }     // reference_type jurnal issue proyek
if (!defined('JRN_REF_RETURN')) { define('JRN_REF_RETURN', 'supplier_return'); }   // reference_type jurnal retur supplier
// [PATCH 2026-11-15 F-3.1] reference_type retur sisa material proyek (material.leftoverCRD.php) &
// buy-back sisa subkon (rancangan audit §9.1). Kolom journal_entries.reference_type bertipe VARCHAR,
// sehingga satu-satunya gerbang keabsahan nilai adalah daftar valid di bawah (in_array).
if (!defined('JRN_REF_MATERIAL_LEFTOVER'))       { define('JRN_REF_MATERIAL_LEFTOVER',       'material_leftover'); }
if (!defined('JRN_REF_SUBCON_LEFTOVER_BUYBACK')) { define('JRN_REF_SUBCON_LEFTOVER_BUYBACK', 'subcon_leftover_buyback'); }
if (!defined('JRN_VALID_REF_TYPES')) {
    define('JRN_VALID_REF_TYPES', implode('|', [
        'material_out', 'supplier_return', 'material_leftover', 'subcon_leftover_buyback',
        'wh_receiving', 'purchase_grn', 'purchase_invoice', 'purchase_return',
        'project_invoice', 'project_payment', 'payment_entry', 'wip_release',
        'sales_univoice', 'sales_proyekoice', 'manual_entry', 'closing_entry', 'adjustment', 'stock_transfer',
        'subcon_progress', 'subcon_payment', 'subcon_invoice', 'subcon_invoice_pay',
    ]));
}

/** Benarkah $refType termasuk reference_type jurnal yang dikenal modul stok (validasi in_array). */
function JrnRefKnown(string $refType): bool {
    return in_array($refType, explode('|', JRN_VALID_REF_TYPES), true);
}

// [FIX] Potong teks maks $len karakter. Pakai mb_substr bila tersedia; bila tidak, fallback aman UTF-8 (tanpa mbstring)
function JrnSub($str, $start, $len) {
    $str = (string)$str;
    if (function_exists('mb_substr')) { return mb_substr($str, $start, $len, 'UTF-8'); }
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);       // pecah per karakter UTF-8
    if ($chars === false) { return substr($str, $start, $len); }      // bukan UTF-8 valid -> potong per byte
    return implode('', array_slice($chars, $start, $len));
}

// Ambil pasangan akun (debit, kredit) dari fiscal_config
function JrnAccounts($ruleCode) {
    $r = WhRow('SELECT debit_account_id, credit_account_id FROM fiscal_config
                WHERE rule_code=$1 AND is_active=true ORDER BY id DESC LIMIT 1', array($ruleCode));
    if (!$r) {
        throw new WhException("Mapping akun jurnal '{$ruleCode}' belum diatur di fiscal_config. Jalankan SQL migrasi 2026-10-11.");
    }
    return array((int)$r['debit_account_id'], (int)$r['credit_account_id']);
}

// Periode akuntansi untuk tanggal transaksi; tolak bila belum ada / sudah ditutup
function JrnPeriod($date) {
    $p = WhRow('SELECT id, period_name, is_closed FROM accounting_periods
                WHERE CAST($1 AS date) BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1', array($date));
    if (!$p) { throw new WhException("Periode akuntansi untuk tanggal {$date} belum dibuat."); }
    if (WhBool($p['is_closed'])) { throw new WhException("Periode akuntansi {$p['period_name']} sudah ditutup."); }
    return (int)$p['id'];
}

/**
 * Buat jurnal 2 baris (1 debit, 1 kredit) langsung posted.
 * Mengembalikan journal_entries.id, atau null bila amount <= 0 (tidak ada nilai untuk dijurnal).
 */
function JrnPost($ruleCode, $refType, $refId, $date, $amount, $desc, $userId) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) { return null; }
    // [PATCH 2026-11-15 F-3.1] reference_type wajib terdaftar (in_array) agar nilai tak dikenal tidak lolak.
    if (!JrnRefKnown((string)$refType)) {
        throw new WhException("reference_type '{$refType}' tidak dikenal. Daftar valid: " . JRN_VALID_REF_TYPES . '.');
    }
    list($drAcc, $crAcc) = JrnAccounts($ruleCode);
    $periodId = JrnPeriod($date);
    $desc = JrnSub($desc, 0, 255);      // [FIX] was mb_substr
    $by = ((int)$userId > 0) ? (int)$userId : null;

    $refNo = CreateRefNo('journal_entries', 'ref_no', 'JV');
    $je = WhRow("INSERT INTO journal_entries
                    (ref_no, journal_date, period_id, reference_type, reference_id, description, status, posted_by, posted_at)
                 VALUES ($1,$2,$3,$4,$5,$6,'posted',$7,now()) RETURNING id",
                array($refNo, $date, $periodId, $refType, (int)$refId, $desc, $by));
    $jid = (int)$je['id'];

    WhQ('INSERT INTO journal_lines (journal_id, account_id, debit, credit, description) VALUES ($1,$2,$3,0,$4)',
        array($jid, $drAcc, $amount, $desc));
    WhQ('INSERT INTO journal_lines (journal_id, account_id, debit, credit, description) VALUES ($1,$2,0,$3,$4)',
        array($jid, $crAcc, $amount, $desc));
    return $jid;
}

/**
 * Reverse semua jurnal posted milik dokumen (refType + refId) yang belum pernah di-reverse.
 * Reverse = jurnal baru dengan debit/kredit dibalik, keterangan 'Reverse of <ref_no asli>'.
 * Dokumen lama yang belum punya jurnal (dibuat sebelum fitur ini) -> dilewati tanpa error.
 * Mengembalikan jumlah jurnal yang di-reverse.
 */
function JrnReverseByRef($refType, $refId, $date, $userId, $descPrefix) {
    // [PATCH 2026-11-15 F-3.1] reference_type wajib terdaftar (in_array), sama seperti JrnPost.
    if (!JrnRefKnown((string)$refType)) {
        throw new WhException("reference_type '{$refType}' tidak dikenal. Daftar valid: " . JRN_VALID_REF_TYPES . '.');
    }
    $orig = WhRows("SELECT e.id, e.ref_no FROM journal_entries e
                    WHERE e.reference_type=$1 AND e.reference_id=$2 AND e.status='posted'
                      AND e.description NOT LIKE 'Reverse of %'
                      AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.description = 'Reverse of ' || e.ref_no)
                    ORDER BY e.id", array($refType, (int)$refId));
    if (!count($orig)) { return 0; }

    $periodId = JrnPeriod($date);
    $by = ((int)$userId > 0) ? (int)$userId : null;
    foreach ($orig as $o) {
        $desc = JrnSub('Reverse of ' . $o['ref_no'], 0, 255);      // [FIX] was mb_substr
        $refNo = CreateRefNo('journal_entries', 'ref_no', 'JV');
        $je = WhRow("INSERT INTO journal_entries
                        (ref_no, journal_date, period_id, reference_type, reference_id, description, status, posted_by, posted_at)
                     VALUES ($1,$2,$3,$4,$5,$6,'posted',$7,now()) RETURNING id",
                    array($refNo, $date, $periodId, $refType, (int)$refId, $desc, $by));
        $jid = (int)$je['id'];
        // salin baris asli dengan debit <-> kredit ditukar
        WhQ("INSERT INTO journal_lines (journal_id, account_id, debit, credit, description)
             SELECT $1, account_id, credit, debit, $2 FROM journal_lines WHERE journal_id=$3 ORDER BY id",
            array($jid, JrnSub($descPrefix . ' - ' . $o['ref_no'], 0, 255)      /* [FIX] was mb_substr */, (int)$o['id']));
    }
    return count($orig);
}