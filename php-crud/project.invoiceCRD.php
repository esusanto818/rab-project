<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * projectinvoiceCRD.php
 * ============================================================
 * Backend "Tagihan ke Klien" (invoice termin proyek). Front end: js/projectinvoice.js, views/projectinvoice.php,
 * cetak: pdf/projectinvoice.php. Menu: m_form_name seq 6026 (grup 75).
 *
 * Alur   : SIMPAN invoice (DRAFT, internal) -> RELEASE tagihan (terbit dokumen TAG-..., jurnal penagihan posting,
 *          invoice masuk daftar tagihan di modul Project Payment) -> client membayar (alokasi ke invoice ini) ->
 *          kelebihan bayar masuk deposit client dan dipakai untuk invoice berikutnya (lihat project_paymentCRD.php).
 * Tabel  : project_invoices (inv_no, termin_name, invoice_date, due_date, amount = TOTAL tagihan DPP + PPN, paid_amount,
 *          dpp_amount, ppn_rate, ppn_amount, faktur_pajak_no/date, release_status/release_no/release_date/released_by/release_note).
 *          Pembayaran: project_payment_items -> project_payments.
 * Aturan :
 *  1. Nilai yang diinput = DPP (tanpa PPN). Tarif default 11% (0 = tanpa PPN). amount = DPP + PPN.
 *  2. Total DPP semua invoice proyek tidak boleh melebihi contract_value (bila diisi).
 *  3. [RELEASE-LAYER] Invoice baru disimpan sebagai DRAFT: belum muncul di Pembayaran Termin, jurnal belum posting,
 *     masih boleh diubah/dihapus. Hanya invoice ber-status RELEASED yang dianggap tagihan resmi (masuk Payment,
 *     bisa dibayar, dicetak dokumen TAG-... untuk diserahkan ke client). Batal release (unrelease) mengembalikan
 *     ke draft + membatalkan jurnal penagihannya; hanya boleh bila belum ada pembayaran.
 *  4. Ubah/hapus invoice hanya selama status DRAFT dan belum ada pembayaran. Faktur pajak boleh diisi kapan saja.
 *  5. Jurnal penagihan (posting saat RELEASE, dalam transaksi yang sama; gagal posting = release batal):
 *       PROJECT_INVOICE     : Dr Piutang Usaha / Cr Pendapatan  (senilai DPP)
 *       PROJECT_INVOICE_PPN : Dr Piutang Usaha / Cr PPN Keluaran (senilai PPN)
 *     reference_type 'project_invoice'. Invoice lama yang sudah punya jurnal tidak diposting ulang saat di-release
 *     (cek ti_has_journal), sehingga migrasi data lama tidak menimbulkan jurnal ganda.
 *  6. due_date (jatuh tempo) opsional; dipakai untuk status jatuh tempo dan proyeksi arus kas penerimaan (summary).
 *     Ringkasan (summary) hanya menghitung invoice RELEASED; nilai DRAFT tampil di kartu "Belum Diterbitkan".
 * Task   : list_clients | list_projects | list_invoices | load_invoice | save_invoice | delete_invoice |
 *          update_faktur_pajak | release_invoice | release_invoices | unrelease_invoice
 * Migrasi: sql/2026-10-27.project_invoice.sql; sql/2026-11-14.project_invoice_release.sql (layer release)
 * ============================================================
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

const TI_EPS = 0.01;                          // toleransi nilai rupiah
const TI_JOURNAL_REF = 'project_invoice';     // nilai enum journal_entries.reference_type
const TI_PPN_RULE = 'PROJECT_INVOICE_PPN';    // fiscal_config: Dr Piutang Usaha / Cr PPN Keluaran
const TI_REL_DRAFT = 'draft';                 // [RELEASE-LAYER] belum diterbitkan ke client
const TI_REL_RELEASED = 'released';           // [RELEASE-LAYER] dokumen tagihan sudah diterbitkan

class TiError extends Exception {}

function ti_send($success, $data = [], $message = '', $extra = []) {
    $resp = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $resp['data'] = $data;
        if (!isset($resp['totalCount'])) $resp['totalCount'] = is_array($data) ? count($data) : 0;
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ti_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/** [RELEASE-LAYER] Baca catatan penerbitan dari POST: opsional, string, maks 200 karakter, sudah di-trim. */
function ti_read_release_note(): string {
    $raw = trim((string)($_POST['release_note'] ?? ''));
    if ($raw === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($raw, 'UTF-8') > 200) {
        throw new TiError('Catatan penerbitan terlalu panjang (maksimal 200 karakter).');
    } elseif (!function_exists('mb_strlen') && strlen($raw) > 200) {
        throw new TiError('Catatan penerbitan terlalu panjang (maksimal 200 karakter).');
    }
    return $raw;
}

function ti_has_journal($db, int $invId): bool {
    $st = $db->prepare("SELECT 1 FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => TI_JOURNAL_REF, ':id' => $invId]);
    return (bool)$st->fetchColumn();
}

/** Jurnal penagihan: DPP ke pendapatan + PPN ke PPN Keluaran (bila ada). */
function ti_post_journal($db, int $invId, string $invNo, string $date, float $dpp, float $ppn, string $projectName): void {
    $jr = AutoPostJournal($db, [
        'rule_code' => 'PROJECT_INVOICE', 'reference_type' => TI_JOURNAL_REF, 'reference_id' => $invId,
        'journal_date' => $date, 'amount' => $dpp, 'description' => "Tagihan termin {$invNo} - {$projectName}",
    ]);
    if (!$jr['success']) throw new TiError('Gagal posting jurnal penagihan: ' . $jr['message']);
    if ($ppn > TI_EPS) {
        $jr = AutoPostJournal($db, [
            'rule_code' => TI_PPN_RULE, 'reference_type' => TI_JOURNAL_REF, 'reference_id' => $invId,
            'journal_date' => $date, 'amount' => $ppn, 'description' => "PPN Keluaran termin {$invNo} - {$projectName}",
        ]);
        if (!$jr['success']) throw new TiError('Gagal posting jurnal PPN Keluaran: ' . $jr['message']);
    }
}

/** Invoice sudah punya pembayaran? */
function ti_has_payment($db, int $invId, float $paid): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM public.project_payment_items WHERE invoice_id = :id");
    $st->execute([':id' => $invId]);
    return $paid > TI_EPS || (int)$st->fetchColumn() > 0;
}

/** Cek no. faktur pajak keluaran unik antar invoice. */
function ti_check_faktur_unique($db, $fpNo, int $exclId): void {
    if ($fpNo === null || $fpNo === '') return;
    $st = $db->prepare("SELECT inv_no FROM public.project_invoices WHERE faktur_pajak_no = :n AND id <> :id LIMIT 1");
    $st->execute([':n' => $fpNo, ':id' => $exclId]);
    $dup = $st->fetchColumn();
    if ($dup) throw new TiError("No. faktur pajak {$fpNo} sudah dipakai invoice {$dup}.");
}

/** [RELEASE-LAYER] Nomor dokumen tagihan berikutnya (format TAG-yymm-00001, seperti CreateRefNo tapi dalam transaksi PDO berjalan). */
function ti_next_release_no($db): string {
    $db->query("SELECT pg_advisory_xact_lock(987654321)");          // kunci supaya tidak ada nomor ganda saat release bersamaan
    $ym = date('ym');
    $st = $db->prepare("
        SELECT COALESCE(MAX(CAST(substring(release_no from '.....$') AS integer)), 0) + 1
          FROM public.project_invoices
         WHERE release_no IS NOT NULL
           AND substring(release_no, length(release_no) - 9, 2) = :y
           AND substring(release_no, length(release_no) - 7, 2) = :m");
    $st->execute([':y' => substr($ym, 0, 2), ':m' => substr($ym, 2, 2)]);
    return 'TAG-' . $ym . '-' . str_pad((int)$st->fetchColumn(), 5, '0', STR_PAD_LEFT);
}

/**
 * [RELEASE-LAYER] Terbitkan satu invoice: draft -> released (+ nomor TAG, tanggal, user, catatan) + posting jurnal bila belum ada.
 * HARUS dipanggil di dalam transaksi PDO yang sudah dibuka. Melempar TiError bila tidak memenuhi syarat.
 * Catatan penerbitan (release_note) opsional, maks 200 karakter; nilai kosong -> ''.
 */
function ti_release_one($db, int $id, string $relDate, int $userId, string $relNote = ''): array {
    $st = $db->prepare("SELECT id, inv_no, project_id, paid_amount, release_status FROM public.project_invoices WHERE id = :id FOR UPDATE");
    $st->execute([':id' => $id]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) throw new TiError('Invoice tidak ditemukan.');
    if ((string)$inv['release_status'] !== TI_REL_DRAFT) {
        throw new TiError("Invoice {$inv['inv_no']} sudah diterbitkan (status: {$inv['release_status']}).");
    }
    if (ti_has_payment($db, $id, (float)$inv['paid_amount'])) {
        throw new TiError("Invoice {$inv['inv_no']} sudah memiliki pembayaran, tidak dapat diterbitkan ulang.");
    }
    $relNo = ti_next_release_no($db);
    $st = $db->prepare("
        UPDATE public.project_invoices
           SET release_status = :s, release_no = :n, release_date = CAST(:d AS date), released_by = :u, release_note = :note
         WHERE id = :id");
    $st->execute([':s' => TI_REL_RELEASED, ':n' => $relNo, ':d' => $relDate, ':u' => $userId, ':note' => $relNote, ':id' => $id]);

    // Jurnal penagihan: invoice lama (pra migrasi) mungkin sudah punya jurnal -> jangan posting ganda.
    if (!ti_has_journal($db, $id)) {
        $st = $db->prepare("
            SELECT v.dpp_amount, v.ppn_amount, v.amount, v.invoice_date, p.name AS project_name
              FROM public.project_invoices v JOIN public.projects p ON p.id = v.project_id
             WHERE v.id = :id");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $dpp = (float)$r['dpp_amount'] > 0 ? (float)$r['dpp_amount'] : (float)$r['amount'];
        ti_post_journal($db, $id, $inv['inv_no'], (string)$r['invoice_date'], $dpp, (float)$r['ppn_amount'], (string)$r['project_name']);
    }
    return ['inv_no' => $inv['inv_no'], 'release_no' => $relNo];
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    ti_send(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

$invoiceAccess = (int)($_SESSION['FormAccess'] ?? 0);
$invoiceRequired = 1;
if ($task === 'save_invoice') $invoiceRequired = 2;
// [RELEASE-LAYER] penerbitan & pembatalan release = level ubah (3); hapus tetap level 4.
if (in_array($task, ['update_faktur_pajak', 'release_invoice', 'release_invoices', 'unrelease_invoice'], true)) $invoiceRequired = 3;
if ($task === 'delete_invoice') $invoiceRequired = 4;
if ($invoiceAccess < $invoiceRequired) {
    http_response_code(403);
    ti_send(false, [], 'Hak akses tidak mencukupi untuk operasi invoice proyek ini.');
}
if (in_array($task, ['save_invoice', 'update_faktur_pajak', 'delete_invoice', 'release_invoice', 'release_invoices', 'unrelease_invoice'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    ti_send(false, [], 'Metode harus POST untuk perubahan invoice proyek.');
}

try {
    switch ($task) {

        case 'list_clients':
            $rows = $db->query("SELECT id, name FROM public.clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            ti_send(true, $rows);
            break;

        // Proyek + nilai kontrak + total DPP yang sudah ditagih (untuk kontrol sisa kontrak di form)
        case 'list_projects':
            $rows = $db->query("
                SELECT p.id, p.name, p.client_id, COALESCE(p.contract_value, 0) AS contract_value,
                       COALESCE(b.dpp, 0) AS billed_dpp
                  FROM public.projects p
                  LEFT JOIN (SELECT project_id, SUM(CASE WHEN dpp_amount > 0 THEN dpp_amount ELSE amount END) AS dpp
                               FROM public.project_invoices GROUP BY project_id) b ON b.project_id = p.id
                 ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['client_id'] = $r['client_id'] !== null ? (int)$r['client_id'] : 0;
                $r['contract_value'] = (float)$r['contract_value'];
                $r['billed_dpp'] = (float)$r['billed_dpp'];
            }
            unset($r);
            ti_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Daftar invoice + ringkasan (grid utama)
         * Filter: query, client_id, project_id, status (unpaid|partial|paid|open|overdue),
         *         doc_status (draft|released) [RELEASE-LAYER], date_from, date_to
         * Ringkasan HANYA menghitung invoice RELEASED (tagihan resmi ke client);
         * nilai DRAFT dilaporkan terpisah (draft_count / draft_value) untuk keperluan monitoring.
         * ------------------------------------------------------------------ */
        case 'list_invoices':
            $start = max((int)($_REQUEST['start'] ?? 0), 0);
            $limit = min(max((int)($_REQUEST['limit'] ?? 250), 1), 1000);
            $query = trim((string)($_REQUEST['query'] ?? ''));
            $cli   = (int)($_REQUEST['client_id'] ?? 0);
            $prj   = (int)($_REQUEST['project_id'] ?? 0);
            $stat  = (string)($_REQUEST['status'] ?? '');
            $doc   = (string)($_REQUEST['doc_status'] ?? '');               // [RELEASE-LAYER]
            $dFrom = (string)($_REQUEST['date_from'] ?? '');
            $dTo   = (string)($_REQUEST['date_to'] ?? '');

            $where = []; $params = [];
            if ($query !== '') {
                $where[] = "(x.inv_no ILIKE :q OR x.project_name ILIKE :q OR x.client_name ILIKE :q OR x.termin_name ILIKE :q OR x.faktur_pajak_no ILIKE :q OR x.release_no ILIKE :q)";
                $params[':q'] = '%' . $query . '%';
            }
            if ($cli > 0) { $where[] = "x.client_id = :cli"; $params[':cli'] = $cli; }
            if ($prj > 0) { $where[] = "x.project_id = :prj"; $params[':prj'] = $prj; }
            if ($dFrom !== '' && ti_valid_date($dFrom)) { $where[] = "x.invoice_date >= CAST(:df AS date)"; $params[':df'] = $dFrom; }
            if ($dTo !== '' && ti_valid_date($dTo))     { $where[] = "x.invoice_date <= CAST(:dt AS date)"; $params[':dt'] = $dTo; }
            switch ($stat) {
                case 'unpaid':  $where[] = "x.paid_amount <= 0.005"; break;
                case 'partial': $where[] = "x.paid_amount > 0.005 AND x.amount - x.paid_amount > 0.005"; break;
                case 'paid':    $where[] = "x.amount - x.paid_amount <= 0.005"; break;
                case 'open':    $where[] = "x.amount - x.paid_amount > 0.005"; break;
                case 'overdue': $where[] = "x.due_date < CURRENT_DATE AND x.amount - x.paid_amount > 0.005"; break;
            }
            switch ($doc) {                                                   // [RELEASE-LAYER]
                case 'draft':    $where[] = "x.release_status = 'draft'"; break;
                case 'released': $where[] = "x.release_status = 'released'"; break;
            }
            $w = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $base = "
                SELECT v.id, v.inv_no, v.invoice_date, v.due_date, v.termin_name, v.percentage, v.amount, v.paid_amount,
                       v.dpp_amount, v.ppn_rate, v.ppn_amount, v.faktur_pajak_no, v.faktur_pajak_date, v.remark,
                       v.release_status, v.release_no, v.release_date, v.released_by, v.release_note,
                       e.employee_name AS released_by_name,
                       p.id AS project_id, p.name AS project_name, cl.id AS client_id, cl.name AS client_name,
                       ROW_NUMBER() OVER (PARTITION BY v.project_id ORDER BY v.invoice_date, v.id) AS termin_no
                  FROM public.project_invoices v
                  JOIN public.projects p ON p.id = v.project_id
                  LEFT JOIN public.clients cl ON cl.id = p.client_id
                  LEFT JOIN public.m_user_system u ON u.id = v.released_by        -- [RELEASE-LAYER]
                  LEFT JOIN public.m_employee e ON e.id = u.employee_id";

            $st = $db->prepare("SELECT COUNT(*) FROM ({$base}) x {$w}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            // Ringkasan (mengikuti filter): rilis resmi = hanya yang RELEASED; draft dilaporkan terpisah. [RELEASE-LAYER]
            $st = $db->prepare("
                SELECT COALESCE(SUM(CASE WHEN x.release_status = 'released' THEN x.amount END), 0) AS invoiced,
                       COALESCE(SUM(CASE WHEN x.release_status = 'released' THEN x.paid_amount END), 0) AS paid,
                       COALESCE(SUM(CASE WHEN x.release_status = 'released' THEN GREATEST(x.amount - x.paid_amount, 0) END), 0) AS outstanding,
                       COALESCE(SUM(CASE WHEN x.release_status = 'released' AND x.due_date < CURRENT_DATE
                                              AND x.amount - x.paid_amount > 0.005
                                         THEN x.amount - x.paid_amount END), 0) AS overdue,
                       COALESCE(SUM(CASE WHEN x.release_status = 'released' AND x.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30
                                              AND x.amount - x.paid_amount > 0.005
                                         THEN x.amount - x.paid_amount END), 0) AS due_30,
                       COALESCE(SUM(CASE WHEN x.release_status = 'released' AND x.due_date IS NULL
                                         AND x.amount - x.paid_amount > 0.005
                                         THEN x.amount - x.paid_amount END), 0) AS no_due,
                       COUNT(CASE WHEN x.release_status = 'draft' THEN 1 END) AS draft_count,
                       COALESCE(SUM(CASE WHEN x.release_status = 'draft' THEN x.amount END), 0) AS draft_value
                  FROM ({$base}) x {$w}");
            $st->execute($params);
            $sum = $st->fetch(PDO::FETCH_ASSOC);
            foreach ($sum as $k => $v) $sum[$k] = round((float)$v, 2);
            $sum['draft_count'] = (int)$sum['draft_count'];

            $st = $db->prepare("SELECT x.*, (CURRENT_DATE - x.due_date) AS days_overdue
                                  FROM ({$base}) x {$w}
                                 ORDER BY x.invoice_date DESC, x.id DESC LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                foreach (['id', 'project_id', 'client_id', 'termin_no', 'released_by'] as $f) $r[$f] = $r[$f] !== null ? (int)$r[$f] : 0;
                foreach (['percentage', 'amount', 'paid_amount', 'dpp_amount', 'ppn_rate', 'ppn_amount'] as $f) $r[$f] = $r[$f] !== null ? (float)$r[$f] : 0.0;
                $r['remaining'] = round($r['amount'] - $r['paid_amount'], 2);
                $r['status'] = ($r['remaining'] <= 0.005) ? 'Lunas' : ($r['paid_amount'] > 0.005 ? 'Sebagian' : 'Belum Bayar');
                $r['is_overdue'] = ($r['remaining'] > 0.005 && $r['due_date'] !== null && (int)$r['days_overdue'] > 0);
                $r['days_overdue'] = $r['is_overdue'] ? (int)$r['days_overdue'] : 0;
            }
            unset($r);
            ti_send(true, $rows, '', ['totalCount' => $total, 'summary' => $sum]);
            break;

        /* ------------------------------------------------------------------
         * Detail satu invoice + pembayaran yang dialokasikan ke invoice ini
         * ------------------------------------------------------------------ */
        case 'load_invoice':
            $id = (int)($_REQUEST['id'] ?? 0);
            $st = $db->prepare("
                SELECT v.id, v.inv_no, v.invoice_date, v.due_date, v.termin_name, v.percentage, v.amount, v.paid_amount,
                       v.dpp_amount, v.ppn_rate, v.ppn_amount, v.faktur_pajak_no, v.faktur_pajak_date, v.remark,
                       v.release_status, v.release_no, v.release_date, v.released_by, v.release_note,
                       e.employee_name AS released_by_name,
                       p.id AS project_id, p.name AS project_name, COALESCE(p.contract_value, 0) AS contract_value,
                       cl.id AS client_id, cl.name AS client_name
                  FROM public.project_invoices v
                  JOIN public.projects p ON p.id = v.project_id
                  LEFT JOIN public.clients cl ON cl.id = p.client_id
                  LEFT JOIN public.m_user_system u ON u.id = v.released_by        -- [RELEASE-LAYER]
                  LEFT JOIN public.m_employee e ON e.id = u.employee_id
                 WHERE v.id = :id");
            $st->execute([':id' => $id]);
            $h = $st->fetch(PDO::FETCH_ASSOC);
            if (!$h) ti_send(false, [], 'Invoice tidak ditemukan.');
            foreach (['id', 'project_id', 'client_id', 'released_by'] as $f) $h[$f] = $h[$f] !== null ? (int)$h[$f] : 0;
            foreach (['percentage', 'amount', 'paid_amount', 'dpp_amount', 'ppn_rate', 'ppn_amount', 'contract_value'] as $f) $h[$f] = $h[$f] !== null ? (float)$h[$f] : 0.0;
            $h['remaining'] = round($h['amount'] - $h['paid_amount'], 2);
            $h['editable'] = ((string)$h['release_status'] === TI_REL_DRAFT) && !ti_has_payment($db, $id, $h['paid_amount']);  // [RELEASE-LAYER]

            $st = $db->prepare("
                SELECT pp.payment_no, pp.payment_date, pp.payment_method, i.amount_paid
                  FROM public.project_payment_items i
                  JOIN public.project_payments pp ON pp.id = i.payment_id
                 WHERE i.invoice_id = :id ORDER BY pp.payment_date, pp.id");
            $st->execute([':id' => $id]);
            $pays = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pays as &$p) $p['amount_paid'] = (float)$p['amount_paid'];
            unset($p);
            ti_send(true, ['header' => $h, 'payments' => $pays]);
            break;

        /* ------------------------------------------------------------------
         * Simpan / ubah invoice (selalu sebagai DRAFT; jurnal penagihan belum posting)
         * Ubah hanya bila masih DRAFT dan belum ada pembayaran.
         * ------------------------------------------------------------------ */
        case 'save_invoice':
            $id      = (int)($_POST['id'] ?? 0);
            // Aksi ini melayani penerbitan dan perubahan invoice; perubahan memerlukan level 3.
            if ($id > 0 && $invoiceAccess < 3) {
                http_response_code(403);
                ti_send(false, [], 'Hak ubah invoice proyek tidak tersedia.');
            }
            $prj     = (int)($_POST['project_id'] ?? 0);
            $cliChk  = (int)($_POST['client_id'] ?? 0);
            $name    = trim((string)($_POST['termin_name'] ?? ''));
            $date    = (string)($_POST['invoice_date'] ?? '');
            $due     = trim((string)($_POST['due_date'] ?? ''));
            $pct     = ($_POST['percentage'] ?? '') !== '' ? round((float)$_POST['percentage'], 2) : null;
            $amt     = round((float)($_POST['amount'] ?? 0), 2);               // DPP (tanpa PPN)
            $ppnRate = round((float)($_POST['ppn_rate'] ?? 0), 2);             // 0 = tanpa PPN
            $fpNo    = trim((string)($_POST['faktur_pajak_no'] ?? '')) ?: null;
            $fpDate  = trim((string)($_POST['faktur_pajak_date'] ?? ''));
            $remark  = trim((string)($_POST['remark'] ?? '')) ?: null;

            try {
                if ($prj <= 0) throw new TiError('Proyek wajib dipilih.');
                if ($name === '') throw new TiError('Nama termin wajib diisi.');
                if (!ti_valid_date($date)) throw new TiError('Tanggal invoice tidak valid.');
                if ($due !== '' && !ti_valid_date($due)) throw new TiError('Tanggal jatuh tempo tidak valid.');
                if ($due !== '' && $due < $date) throw new TiError('Jatuh tempo tidak boleh lebih awal dari tanggal invoice.');
                if ($pct !== null && ($pct < 0 || $pct > 100)) throw new TiError('Persentase harus antara 0 - 100.');
                if ($ppnRate < 0 || $ppnRate > 100) throw new TiError('Tarif PPN harus antara 0 - 100%.');
                if ($fpDate !== '' && !ti_valid_date($fpDate)) throw new TiError('Tanggal faktur pajak tidak valid.');
                if ($fpNo === null && $fpDate !== '') throw new TiError('Isi no. faktur pajak, atau kosongkan tanggalnya.');

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT COALESCE(contract_value, 0) AS cv, client_id, name FROM public.projects WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $prj]);
                    $prjRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prjRow) throw new TiError('Proyek tidak ditemukan.');
                    if ($cliChk > 0 && (int)$prjRow['client_id'] !== $cliChk) throw new TiError('Proyek tidak termasuk client yang dipilih.');
                    $contract = (float)$prjRow['cv'];

                    $inv = null;
                    if ($id > 0) {
                        $st = $db->prepare("SELECT id, inv_no, project_id, paid_amount, release_status FROM public.project_invoices WHERE id = :id FOR UPDATE");
                        $st->execute([':id' => $id]);
                        $inv = $st->fetch(PDO::FETCH_ASSOC);
                        if (!$inv) throw new TiError('Invoice tidak ditemukan.');
                        if ((int)$inv['project_id'] !== $prj) throw new TiError('Proyek invoice tidak dapat diganti. Hapus lalu terbitkan ulang bila salah proyek.');
                        if ((string)$inv['release_status'] !== TI_REL_DRAFT) {    // [RELEASE-LAYER] yang sudah diterbitkan tidak boleh diubah
                            throw new TiError("Invoice {$inv['inv_no']} sudah diterbitkan ({$inv['release_status']}); batalkan release dulu untuk mengubah.");
                        }
                        if (ti_has_payment($db, $id, (float)$inv['paid_amount'])) {
                            throw new TiError("Invoice {$inv['inv_no']} sudah memiliki pembayaran, tidak dapat diubah.");
                        }
                    }

                    if ($amt <= 0 && $pct !== null && $contract > 0) $amt = round($contract * $pct / 100, 2);
                    if ($amt <= 0) throw new TiError('Nilai invoice (DPP) harus lebih dari 0.');

                    // kontrol nilai kontrak: total DPP invoice lain + invoice ini <= contract_value
                    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN dpp_amount > 0 THEN dpp_amount ELSE amount END), 0)
                                          FROM public.project_invoices WHERE project_id = :p AND id <> :x");
                    $st->execute([':p' => $prj, ':x' => $id]);
                    $existing = (float)$st->fetchColumn();
                    if ($contract > 0 && $existing + $amt > $contract + TI_EPS) {
                        throw new TiError('Total tagihan DPP (' . number_format($existing + $amt, 2) . ') melebihi nilai kontrak (' . number_format($contract, 2) . ').');
                    }
                    ti_check_faktur_unique($db, $fpNo, $id);

                    $ppnAmt = ($ppnRate > 0) ? round($amt * $ppnRate / 100, 2) : 0.0;
                    $total  = round($amt + $ppnAmt, 2);

                    if ($id > 0) {
                        // [RELEASE-LAYER] invoice lama (pra migrasi) bisa sudah punya jurnal saat masih draft -> reverse saja;
                        // jurnal penagihan yang benar diposting ulang saat release.
                        if (ti_has_journal($db, $id)) ReverseJournalByReference($db, TI_JOURNAL_REF, $id);
                        $st = $db->prepare("
                            UPDATE public.project_invoices
                               SET termin_name = :n, invoice_date = CAST(:d AS date), due_date = CAST(NULLIF(:due, '') AS date),
                                   percentage = :pct, amount = :a, dpp_amount = :dpp, ppn_rate = :pr, ppn_amount = :ppn,
                                   faktur_pajak_no = :fn, faktur_pajak_date = CAST(NULLIF(:fd, '') AS date), remark = :rm
                             WHERE id = :id");
                        $st->execute([':n' => $name, ':d' => $date, ':due' => $due, ':pct' => $pct, ':a' => $total, ':dpp' => $amt, ':pr' => $ppnRate,
                                      ':ppn' => $ppnAmt, ':fn' => $fpNo, ':fd' => $fpDate, ':rm' => $remark, ':id' => $id]);
                        $invNo = $inv['inv_no'];
                        $newId = $id;
                    } else {
                        if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                        $invNo = CreateRefNo('project_invoices', 'inv_no', 'INV');
                        $st = $db->prepare("
                            INSERT INTO public.project_invoices
                                (inv_no, project_id, termin_name, invoice_date, due_date, percentage, amount, paid_amount,
                                 dpp_amount, ppn_rate, ppn_amount, faktur_pajak_no, faktur_pajak_date, remark, release_status)
                            VALUES (:no, :p, :n, CAST(:d AS date), CAST(NULLIF(:due, '') AS date), :pct, :a, 0,
                                    :dpp, :pr, :ppn, :fn, CAST(NULLIF(:fd, '') AS date), :rm, :rs)
                            RETURNING id");
                        $st->execute([':no' => $invNo, ':p' => $prj, ':n' => $name, ':d' => $date, ':due' => $due, ':pct' => $pct, ':a' => $total,
                                      ':dpp' => $amt, ':pr' => $ppnRate, ':ppn' => $ppnAmt, ':fn' => $fpNo, ':fd' => $fpDate, ':rm' => $remark,
                                      ':rs' => TI_REL_DRAFT]);                               // [RELEASE-LAYER] invoice baru = DRAFT
                        $newId = (int)$st->fetchColumn();
                    }
                    // [RELEASE-LAYER] jurnal penagihan TIDAK diposting di sini; diposting saat RELEASE (lihat release_invoice).
                    $db->commit();
                    ti_send(true, ['id' => $newId, 'inv_no' => $invNo],
                        "Invoice {$invNo} " . ($id > 0 ? 'diperbarui' : 'disimpan sebagai DRAFT') . "." .
                        ($id > 0 ? '' : ' Terbitkan (Release) agar menjadi tagihan resmi ke client.'));
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (TiError $e) {
                ti_send(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * [RELEASE-LAYER] Terbitkan invoice ke client: draft -> released (+ nomor TAG) + posting jurnal.
         * ------------------------------------------------------------------ */
        case 'release_invoice':
            $id = (int)($_POST['id'] ?? 0);
            $relDate = trim((string)($_POST['release_date'] ?? ''));
            try {
                if ($id <= 0) throw new TiError('Invoice wajib dipilih.');
                if ($relDate !== '' && !ti_valid_date($relDate)) throw new TiError('Tanggal terbit tidak valid.');
                if ($relDate === '') $relDate = date('Y-m-d');
                $relNote = ti_read_release_note();                          // [RELEASE-LAYER] catatan penerbitan (opsional)
                $db->beginTransaction();
                try {
                    $out = ti_release_one($db, $id, $relDate, (int)($_SESSION['user_id'] ?? 0), $relNote);
                    $db->commit();
                    ti_send(true, ['id' => $id, 'inv_no' => $out['inv_no'], 'release_no' => $out['release_no']],
                        "Dokumen tagihan {$out['release_no']} untuk invoice {$out['inv_no']} diterbitkan. Invoice kini muncul di Pembayaran Termin.");
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (TiError $e) {
                ti_send(false, [], $e->getMessage());
            }
            break;

        // [RELEASE-LAYER] Release banyak sekaligus (bulk) sesuai filter: ids = JSON array maupun CSV.
        case 'release_invoices':
            $rawIds = $_POST['ids'] ?? '';
            if (is_string($rawIds)) {
                $dec = json_decode($rawIds, true);
                $ids = is_array($dec) ? $dec : array_map('trim', explode(',', $rawIds));
            } else {
                $ids = (array)$rawIds;
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
            try {
                if (!count($ids)) throw new TiError('Tidak ada invoice yang dipilih untuk diterbitkan.');
                $relDate = trim((string)($_POST['release_date'] ?? ''));
                if ($relDate !== '' && !ti_valid_date($relDate)) throw new TiError('Tanggal terbit tidak valid.');
                if ($relDate === '') $relDate = date('Y-m-d');
                $relNote = ti_read_release_note();                          // [RELEASE-LAYER] catatan per-invoice (opsional)
                $db->beginTransaction();
                try {
                    $released = [];
                    foreach ($ids as $oneId) {
                        $out = ti_release_one($db, $oneId, $relDate, (int)($_SESSION['user_id'] ?? 0), $relNote);
                        $released[] = $out['inv_no'];
                    }
                    $db->commit();
                    ti_send(true, ['released' => count($released), 'invoices' => $released],
                        count($released) . ' tagihan berhasil diterbitkan (' . implode(', ', $released) . ').');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (TiError $e) {
                ti_send(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * [RELEASE-LAYER] Batalkan penerbitan: released -> draft (jurnal penagihan dibatalkan).
         * Hanya bila belum ada pembayaran.
         * ------------------------------------------------------------------ */
        case 'unrelease_invoice':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                if ($id <= 0) throw new TiError('Invoice wajib dipilih.');
                $st = $db->prepare("SELECT id, inv_no, paid_amount, release_status FROM public.project_invoices WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new TiError('Invoice tidak ditemukan.');
                if ((string)$inv['release_status'] !== TI_REL_RELEASED) {
                    throw new TiError("Invoice {$inv['inv_no']} belum diterbitkan (status: {$inv['release_status']}).");
                }
                if (ti_has_payment($db, $id, (float)$inv['paid_amount'])) {
                    throw new TiError("Invoice {$inv['inv_no']} sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan.");
                }
                if (ti_has_journal($db, $id)) ReverseJournalByReference($db, TI_JOURNAL_REF, $id);
                $st = $db->prepare("
                    UPDATE public.project_invoices
                       SET release_status = :s, release_no = NULL, release_date = NULL, released_by = NULL, release_note = ''
                     WHERE id = :id");
                $st->execute([':s' => TI_REL_DRAFT, ':id' => $id]);
                $db->commit();
                ti_send(true, [], "Penerbitan invoice {$inv['inv_no']} dibatalkan; kembali ke DRAFT.");
            } catch (TiError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                ti_send(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus invoice (hanya DRAFT & belum ada pembayaran) - jurnal penagihannya ikut di-reverse
         * ------------------------------------------------------------------ */
        case 'delete_invoice':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT inv_no, paid_amount, release_status FROM public.project_invoices WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new TiError('Invoice tidak ditemukan.');
                if ((string)$inv['release_status'] !== TI_REL_DRAFT) {   // [RELEASE-LAYER] dokumen yang sudah diterbitkan tidak boleh dihapus
                    throw new TiError("Invoice {$inv['inv_no']} sudah diterbitkan; batalkan release dulu sebelum menghapus.");
                }
                if (ti_has_payment($db, $id, (float)$inv['paid_amount'])) {
                    throw new TiError("Invoice {$inv['inv_no']} sudah memiliki pembayaran, tidak dapat dihapus.");
                }
                if (ti_has_journal($db, $id)) ReverseJournalByReference($db, TI_JOURNAL_REF, $id);
                $db->prepare("DELETE FROM public.project_invoices WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                ti_send(true, [], "Invoice {$inv['inv_no']} dihapus.");
            } catch (TiError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                ti_send(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Isi / ubah faktur pajak keluaran (boleh walau sudah ada pembayaran)
         * ------------------------------------------------------------------ */
        case 'update_faktur_pajak':
            $id     = (int)($_POST['id'] ?? 0);
            $fpNo   = trim((string)($_POST['faktur_pajak_no'] ?? ''));
            $fpDate = trim((string)($_POST['faktur_pajak_date'] ?? ''));
            $db->beginTransaction();
            try {
                if ($fpDate !== '' && !ti_valid_date($fpDate)) throw new TiError('Tanggal faktur pajak tidak valid.');
                if (strlen($fpNo) > 30) throw new TiError('No. faktur pajak maksimal 30 karakter.');
                if ($fpNo === '' && $fpDate !== '') throw new TiError('Isi no. faktur pajak, atau kosongkan tanggalnya.');
                $st = $db->prepare("SELECT inv_no, ppn_amount FROM public.project_invoices WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new TiError('Invoice tidak ditemukan.');
                if ((float)$inv['ppn_amount'] <= TI_EPS) throw new TiError("Invoice {$inv['inv_no']} tidak memuat PPN, tidak ada faktur pajak.");
                ti_check_faktur_unique($db, $fpNo, $id);
                $st = $db->prepare("UPDATE public.project_invoices
                                       SET faktur_pajak_no = NULLIF(:n, ''), faktur_pajak_date = CAST(NULLIF(:d, '') AS date) WHERE id = :id");
                $st->execute([':n' => $fpNo, ':d' => $fpDate, ':id' => $id]);
                $db->commit();
                ti_send(true, [], $fpNo === '' ? "Faktur pajak invoice {$inv['inv_no']} dikosongkan." : "Faktur pajak invoice {$inv['inv_no']} tersimpan.");
            } catch (TiError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                ti_send(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            ti_send(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[projectinvoiceCRD] ' . $e->getMessage());
    ti_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[projectinvoiceCRD] ' . $e->getMessage());
    ti_send(false, [], 'System Error: ' . $e->getMessage());
}
