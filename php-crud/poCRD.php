<?php
/**
 * poCRD.php
 * ============================================================
 * Backend Controller Purchase Order (po.js).
 * Pola sama dengan rabCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Sumber data: purchase_orders, po_items, budget_items (RAB), materials,
 * m_suppliers, m_currency, grn_hdr/grn_dtl (qty diterima).
 *
 * Aturan:
 *  1. Item PO wajib dari RAB proyek tsb (po_items.budget_item_id -> budget_items.id).
 *  2. Total qty di-PO per baris RAB (semua PO non-Dibatalkan) tidak boleh melebihi
 *     qty_plan + toleransi RAB.
 *  3. PO hanya boleh diubah / dihapus saat status Draft.
 *  4. Alur status: Draft -> Diajukan -> Disetujui -> Selesai; Dibatalkan dari
 *     Draft/Diajukan/Disetujui selama belum ada barang diterima (GRN).
 *     Diajukan / Disetujui wajib punya supplier dan minimal 1 item.
 *  5. [MULTI CURRENCY PO] Header PO: mata uang + kurs PO secara keseluruhan (default IDR).
 *     Setiap item PO punya mata uang & kurs sendiri (currency_id, exch_rate di po_items):
 *     - Material beli lokal  => IDR, kurs 1
 *     - Material beli impor  => mis. USD + kurs KMK
 *     Butuh migrasi: sql/2026-10-02.po_item_currency.sql
 *  6. [PPN - TAHAP 1] PPN Masukan di PO (tarif default 11%). Mode per PO (purchase_orders.ppn_mode):
 *     NONE = tanpa PPN | EXCL = harga item belum termasuk PPN | INCL = harga item sudah termasuk PPN.
 *     Tarif disimpan per PO (ppn_rate) agar PO lama tidak berubah bila tarif nasional berganti.
 *     DPP / PPN / total dihitung server (po_ppn_calc) dari jumlah item dibulatkan per baris,
 *     pembulatan PPN di level dokumen. Butuh migrasi: sql/2026-10-26.ppn_po.sql
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

const PO_EPS = 0.0001;
const PPN_DEFAULT_RATE = 11.0;                 // [PPN] tarif default (%); samakan dengan PPN_DEFAULT_RATE di po.js
const PPN_MODES = ['NONE', 'EXCL', 'INCL'];    // [PPN] mode PPN yang valid
const PO_TRANSITIONS = [
    'Draft'     => ['Diajukan', 'Dibatalkan'],
    'Diajukan'  => ['Disetujui', 'Draft', 'Dibatalkan'],
    'Disetujui' => ['Selesai', 'Dibatalkan'],
    'Selesai'   => [],
    'Dibatalkan' => [],
];

class PoBusinessError extends Exception {}

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function po_fmt($n): string {
    return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.');
}

/** [PPN] Normalisasi mode PPN; nilai tak dikenal dianggap NONE. */
function po_ppn_mode($m): string {
    $m = strtoupper(trim((string)$m));
    return in_array($m, PPN_MODES, true) ? $m : 'NONE';
}

/**
 * [PPN] Hitung DPP / PPN / total dari subtotal item (jumlah qty x harga per baris, sudah dibulatkan).
 *  NONE : tanpa PPN
 *  EXCL : harga belum termasuk PPN -> DPP = subtotal, PPN = DPP x tarif, total = DPP + PPN
 *  INCL : harga sudah termasuk PPN -> total = subtotal, DPP = subtotal / (1 + tarif), PPN = total - DPP
 */
function po_ppn_calc(float $subtotal, string $mode, float $rate): array {
    $subtotal = round($subtotal, 2);
    $mode = po_ppn_mode($mode);
    if ($mode === 'NONE' || $rate <= 0) {
        return ['subtotal' => $subtotal, 'dpp' => $subtotal, 'ppn' => 0.0, 'total' => $subtotal];
    }
    if ($mode === 'INCL') {
        $dpp = round($subtotal / (1 + $rate / 100), 2);
        return ['subtotal' => $subtotal, 'dpp' => $dpp, 'ppn' => round($subtotal - $dpp, 2), 'total' => $subtotal];
    }
    $ppn = round($subtotal * $rate / 100, 2);
    return ['subtotal' => $subtotal, 'dpp' => $subtotal, 'ppn' => $ppn, 'total' => round($subtotal + $ppn, 2)];
}

/** Qty yang sudah di-PO per baris RAB proyek (PO Dibatalkan & PO $exclPoId tidak dihitung). */
function po_ordered_map($db, int $projectId, int $exclPoId = 0): array {
    $st = $db->prepare("
        SELECT poi.budget_item_id, SUM(poi.qty) AS q
          FROM po_items poi
          JOIN purchase_orders po ON po.id = poi.po_id
         WHERE po.project_id = :p AND po.status <> 'Dibatalkan' AND po.id <> :x AND poi.budget_item_id IS NOT NULL
         GROUP BY poi.budget_item_id");
    $st->execute([':p' => $projectId, ':x' => $exclPoId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['budget_item_id']] = (float)$r['q'];
    return $map;
}

/** Total qty yang sudah diterima (GRN) untuk satu PO. */
function po_received_total($db, int $poId): float {
    $st = $db->prepare("
        SELECT COALESCE(SUM(d.qty_received), 0)
          FROM grn_dtl d
          JOIN grn_hdr h ON h.id = d.grn_hdr_id
         WHERE h.po_id = :id AND COALESCE(LOWER(h.status), '') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')");
    $st->execute([':id' => $poId]);
    return (float)$st->fetchColumn();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Master untuk combo
         * ------------------------------------------------------------------ */
        case 'list_project_options':
            $rows = $db->query("SELECT id, name FROM public.projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            sendJsonResponse(true, $rows);
            break;

        case 'list_suppliers':
            // [PPN] sertakan status PKP & NPWP supplier (kolom baru m_suppliers.is_pkp / npwp_no)
            $rows = $db->query("SELECT id, name, COALESCE(is_pkp, false) AS is_pkp, npwp_no FROM public.m_suppliers WHERE active IS NOT FALSE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['is_pkp'] = in_array($r['is_pkp'], [true, 't', 'true', 1, '1'], true);
            }
            sendJsonResponse(true, $rows);
            break;

        case 'list_currencies':
            $rows = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Kurs default PO: kurs pajak KMK (tax_exchange_rate_*) pada tanggal PO.
         * Bila tanggal PO tidak masuk periode KMK mana pun -> pakai kurs terakhir.
         * Nilai = IDR per 1 unit mata uang (rate_per_unit; bila kosong rate / unit_multiplier).
         * ------------------------------------------------------------------ */
        case 'get_exchange_rate':
            $cid  = (int)($_REQUEST['currency_id'] ?? 0);
            $date = (string)($_REQUEST['date'] ?? '');
            $dt   = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) $date = date('Y-m-d');

            $st = $db->prepare("SELECT init FROM public.m_currency WHERE id = :id");
            $st->execute([':id' => $cid]);
            $curRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) sendJsonResponse(false, [], 'Mata uang tidak ditemukan.');
            $code = strtoupper(trim((string)$curRow['init']));

            if ($code === 'IDR') {
                sendJsonResponse(true, ['rate' => 1, 'source' => 'IDR']);
            }

            $base = "SELECT COALESCE(d.rate_per_unit, d.rate / NULLIF(d.unit_multiplier, 0)) AS rate,
                            h.kmk_no, h.start_date, h.end_date
                       FROM public.tax_exchange_rate_dtl d
                       JOIN public.tax_exchange_rate_hdr h ON h.id = d.hdr_id
                      WHERE (d.currency_id = :cid OR UPPER(d.currency_code) = :code)";

            // 1) periode KMK yang memuat tanggal PO
            $st = $db->prepare($base . " AND CAST(:d AS date) BETWEEN h.start_date AND h.end_date
                                          ORDER BY h.start_date DESC, h.id DESC LIMIT 1");
            $st->execute([':cid' => $cid, ':code' => $code, ':d' => $date]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $source = 'periode';

            // 2) belum ada data untuk tanggal tsb -> kurs terakhir yang tersedia
            if (!$r || $r['rate'] === null) {
                $st = $db->prepare($base . " ORDER BY h.end_date DESC, h.id DESC LIMIT 1");
                $st->execute([':cid' => $cid, ':code' => $code]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $source = 'terakhir';
            }

            if (!$r || $r['rate'] === null) {
                sendJsonResponse(true, ['rate' => null, 'source' => 'none']);
            }
            sendJsonResponse(true, [
                'rate'       => round((float)$r['rate'], 4),
                'source'     => $source,
                'kmk_no'     => $r['kmk_no'],
                'start_date' => date('d/m/Y', strtotime($r['start_date'])),
                'end_date'   => date('d/m/Y', strtotime($r['end_date'])),
            ]);
            break;

        /* ------------------------------------------------------------------
         * Baris RAB proyek + kuota yang masih boleh di-PO
         * (po_id = PO yang sedang diedit, agar qty miliknya sendiri tidak dihitung)
         * ------------------------------------------------------------------ */
        case 'list_rab_items':
            $prj  = (int)($_REQUEST['project_id'] ?? 0);
            $excl = (int)($_REQUEST['po_id'] ?? 0);
            if ($prj <= 0) sendJsonResponse(true, []);

            // Ambil info mata uang anggaran proyek supaya PO tahu default currency RAB
            $stPrjCur = $db->prepare("
                SELECT p.budget_currency_id,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS rab_currency_code,
                       COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS rab_exch_rate
                  FROM public.projects p
                  LEFT JOIN public.m_currency cur ON cur.id = p.budget_currency_id
                 WHERE p.id = :p");
            $stPrjCur->execute([':p' => $prj]);
            $prjCur = $stPrjCur->fetch(PDO::FETCH_ASSOC);

            $st = $db->prepare("
                SELECT bi.id AS budget_item_id, bi.material_id, m.material_code, m.material_name,
                       COALESCE(NULLIF(bi.unit, ''), m.unit, '') AS unit,
                       bi.qty_plan, COALESCE(bi.tolerance_pct, 0) AS tolerance_pct,
                       COALESCE(bi.unit_price_plan, 0) AS unit_price_plan
                  FROM public.budget_items bi
                  JOIN public.materials m ON m.id = bi.material_id
                 WHERE bi.project_id = :p AND bi.category = 'Material' AND COALESCE(bi.qty_plan, 0) > 0
                 ORDER BY m.material_name ASC");
            $st->execute([':p' => $prj]);
            $ordered = po_ordered_map($db, $prj, $excl);

            $data = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $plan = (float)$r['qty_plan'];
                $ord  = $ordered[(int)$r['budget_item_id']] ?? 0.0;
                $data[] = [
                    'budget_item_id'     => (int)$r['budget_item_id'],
                    'material_id'        => (int)$r['material_id'],
                    'material_code'      => $r['material_code'],
                    'material_name'      => $r['material_name'],
                    'unit'               => $r['unit'],
                    'qty_plan'           => $plan,
                    'tolerance_pct'      => (float)$r['tolerance_pct'],
                    'unit_price_plan'    => (float)$r['unit_price_plan'],
                    'qty_ordered'        => $ord,
                    'qty_remaining'      => max($plan - $ord, 0),
                    'qty_quota'          => max($plan * (1 + (float)$r['tolerance_pct'] / 100) - $ord, 0),
                    // Info mata uang RAB (untuk saran default currency item)
                    'rab_currency_id'    => $prjCur ? (int)$prjCur['budget_currency_id'] : null,
                    'rab_currency_code'  => $prjCur ? $prjCur['rab_currency_code'] : 'IDR',
                    'rab_exch_rate'      => $prjCur ? (float)$prjCur['rab_exch_rate'] : 1.0,
                ];
            }
            sendJsonResponse(true, $data);
            break;

        /* ------------------------------------------------------------------
         * Daftar PO (grid utama)
         * ------------------------------------------------------------------ */
        case 'list_pos':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (po.po_number ILIKE :query OR p.name ILIKE :query OR s.name ILIKE :query)";
                $params[':query'] = '%' . $query . '%';
            }

            $st = $db->prepare("SELECT COUNT(*)
                                  FROM purchase_orders po
                                  JOIN public.projects p ON p.id = po.project_id
                                  LEFT JOIN public.m_suppliers s ON s.id = po.supplier_id {$where}");
            $st->execute($params);
            $totalCount = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT po.id, po.po_number, po.po_date, po.project_id, p.name AS project_name,
                       po.supplier_id, s.name AS supplier_name, po.status, po.notes,
                       po.currency_id, c.init AS currency_code, po.exch_rate,
                       po.ppn_mode, po.ppn_rate,                               -- [PPN]
                       COUNT(i.id) AS item_count,
                       COALESCE(SUM(i.qty * i.unit_price), 0) AS total_amount,
                       COALESCE(SUM(ROUND(i.qty * i.unit_price, 2)), 0) AS subtotal_r   -- [PPN] dasar hitung DPP/PPN
                  FROM purchase_orders po
                  JOIN public.projects p ON p.id = po.project_id
                  LEFT JOIN public.m_suppliers s ON s.id = po.supplier_id
                  LEFT JOIN public.m_currency c ON c.id = po.currency_id
                  LEFT JOIN po_items i ON i.po_id = po.id
                  {$where}
                 GROUP BY po.id, p.name, s.name, c.init
                 ORDER BY po.po_date DESC, po.id DESC
                 LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['project_id'] = (int)$r['project_id'];
                $r['supplier_id'] = $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null;
                $r['currency_id'] = $r['currency_id'] !== null ? (int)$r['currency_id'] : null;
                $r['exch_rate'] = (float)$r['exch_rate'];
                $r['item_count'] = (int)$r['item_count'];
                $r['total_amount'] = (float)$r['total_amount'];
                // [PPN] DPP / PPN / total per PO
                $r['ppn_mode'] = po_ppn_mode($r['ppn_mode']);
                $r['ppn_rate'] = (float)$r['ppn_rate'];
                $calc = po_ppn_calc((float)$r['subtotal_r'], $r['ppn_mode'], $r['ppn_rate']);
                unset($r['subtotal_r']);
                $r['dpp'] = $calc['dpp'];
                $r['ppn_amount'] = $calc['ppn'];
                $r['grand_total'] = $calc['total'];
                $r['ppn_idr'] = round($calc['ppn'] * $r['exch_rate'], 2);   // PPN setara IDR (kurs PO)
            }
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => $totalCount]);
            break;

        /* ------------------------------------------------------------------
         * Detail PO (header + item + qty diterima + aksi status yang diizinkan)
         * ------------------------------------------------------------------ */
        case 'load_po':
            $id = (int)($_REQUEST['id'] ?? 0);
            $st = $db->prepare("
                SELECT po.id, po.po_number, po.po_date, po.project_id, p.name AS project_name,
                       po.supplier_id, s.name AS supplier_name, po.status, po.notes,
                       po.currency_id, c.init AS currency_code, po.exch_rate,
                       po.ppn_mode, po.ppn_rate            -- [PPN]
                  FROM purchase_orders po
                  JOIN public.projects p ON p.id = po.project_id
                  LEFT JOIN public.m_suppliers s ON s.id = po.supplier_id
                  LEFT JOIN public.m_currency c ON c.id = po.currency_id
                 WHERE po.id = :id");
            $st->execute([':id' => $id]);
            $hdr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) sendJsonResponse(false, [], 'PO tidak ditemukan.');
            $hdr['exch_rate'] = (float)$hdr['exch_rate'];

            $st = $db->prepare("
                SELECT i.id, i.budget_item_id, i.material_id, m.material_code, i.description, i.unit,
                       i.qty, i.unit_price,
                       i.currency_id, COALESCE(UPPER(TRIM(ic.init)), 'IDR') AS item_currency_code,
                       COALESCE(NULLIF(i.exch_rate, 0), 1) AS exch_rate,
                       COALESCE(g.q, 0) AS qty_received
                  FROM po_items i
                  LEFT JOIN public.materials m ON m.id = i.material_id
                  LEFT JOIN public.m_currency ic ON ic.id = i.currency_id
                  LEFT JOIN (
                        SELECT d.po_item_id, SUM(d.qty_received) AS q
                          FROM grn_dtl d JOIN grn_hdr h ON h.id = d.grn_hdr_id
                         WHERE COALESCE(LOWER(h.status), '') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')
                         GROUP BY d.po_item_id
                   ) g ON g.po_item_id = i.id
                 WHERE i.po_id = :id ORDER BY i.id");
            $st->execute([':id' => $id]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            $received = 0.0;
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['budget_item_id'] = $it['budget_item_id'] !== null ? (int)$it['budget_item_id'] : null;
                $it['material_id'] = $it['material_id'] !== null ? (int)$it['material_id'] : null;
                $it['currency_id'] = $it['currency_id'] !== null ? (int)$it['currency_id'] : null;
                $it['qty'] = (float)$it['qty'];
                $it['unit_price'] = (float)$it['unit_price'];
                $it['exch_rate'] = (float)$it['exch_rate'];
                $it['qty_received'] = (float)$it['qty_received'];
                $received += $it['qty_received'];
            }

            unset($it);                    // [PPN] putus referensi foreach di atas sebelum iterasi ulang

            // [PPN] ringkasan DPP / PPN / total PO (dihitung server)
            $hdr['ppn_mode'] = po_ppn_mode($hdr['ppn_mode']);
            $hdr['ppn_rate'] = (float)$hdr['ppn_rate'];
            $subItems = 0.0;
            foreach ($items as $row) { $subItems += round($row['qty'] * $row['unit_price'], 2); }
            $calc = po_ppn_calc($subItems, $hdr['ppn_mode'], $hdr['ppn_rate']);
            $hdr['subtotal']    = $calc['subtotal'];
            $hdr['dpp']         = $calc['dpp'];
            $hdr['ppn_amount']  = $calc['ppn'];
            $hdr['grand_total'] = $calc['total'];
            $hdr['ppn_idr']     = round($calc['ppn'] * $hdr['exch_rate'], 2);

            $allowed = PO_TRANSITIONS[$hdr['status']] ?? [];
            if ($received > PO_EPS) {      // sudah ada barang diterima -> tidak boleh dibatalkan / dikembalikan
                $allowed = array_values(array_diff($allowed, ['Dibatalkan', 'Draft']));
            }
            sendJsonResponse(true, ['header' => $hdr, 'items' => $items, 'allowed_actions' => $allowed]);
            break;

        /* ------------------------------------------------------------------
         * Simpan PO (baru / ubah). Hanya status Draft yang boleh diubah.
         * ------------------------------------------------------------------ */
        case 'save_po':
            $id    = (int)($_POST['id'] ?? 0);
            $prj   = (int)($_POST['project_id'] ?? 0);
            $sup   = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $cur   = (int)($_POST['currency_id'] ?? 0) ?: null;
            $rate  = (float)($_POST['exch_rate'] ?? 1);
            $date  = (string)($_POST['po_date'] ?? '');
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            $items = json_decode($_POST['items'] ?? '[]', true);
            if ($rate <= 0) $rate = 1;
            // [PPN] mode & tarif PPN (NONE = tanpa PPN, tarif disimpan 0)
            $ppnMode = po_ppn_mode($_POST['ppn_mode'] ?? 'NONE');
            $ppnRate = round((float)($_POST['ppn_rate'] ?? PPN_DEFAULT_RATE), 2);

            try {
                $d = DateTime::createFromFormat('Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) throw new PoBusinessError('Tanggal PO tidak valid.');
                if ($prj <= 0) throw new PoBusinessError('Proyek wajib dipilih.');
                if (!is_array($items) || !count($items)) throw new PoBusinessError('Minimal satu material harus diisi.');
                // [PPN] validasi tarif
                if ($ppnMode === 'NONE') { $ppnRate = 0.0; }
                elseif ($ppnRate <= 0 || $ppnRate > 100) throw new PoBusinessError('Tarif PPN harus lebih dari 0 dan maksimal 100%.');

                $db->beginTransaction();
                try {
                    if ($id > 0) {
                        $st = $db->prepare("SELECT status FROM purchase_orders WHERE id = :id FOR UPDATE");
                        $st->execute([':id' => $id]);
                        $cur0 = $st->fetch(PDO::FETCH_ASSOC);
                        if (!$cur0) throw new PoBusinessError('PO tidak ditemukan.');
                        if ($cur0['status'] !== 'Draft') throw new PoBusinessError('Hanya PO berstatus Draft yang dapat diubah.');
                    }

                    $st = $db->prepare("SELECT 1 FROM public.projects WHERE id = :id");
                    $st->execute([':id' => $prj]);
                    if (!$st->fetchColumn()) throw new PoBusinessError('Proyek tidak ditemukan.');
                    if ($sup) {
                        $st = $db->prepare("SELECT 1 FROM public.m_suppliers WHERE id = :id");
                        $st->execute([':id' => $sup]);
                        if (!$st->fetchColumn()) throw new PoBusinessError('Supplier tidak ditemukan.');
                    }

                    if ($cur) {                       // IDR selalu berkurs 1, apa pun yang dikirim klien
                        $st = $db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id = :id");
                        $st->execute([':id' => $cur]);
                        if ($st->fetchColumn() === 'IDR') $rate = 1;
                    }

                    // Validasi item terhadap RAB proyek
                    $ordered = po_ordered_map($db, $prj, $id);
                    $stBi = $db->prepare("
                        SELECT bi.id, bi.material_id, bi.qty_plan, COALESCE(bi.tolerance_pct, 0) AS tol,
                               m.material_name, COALESCE(NULLIF(bi.unit, ''), m.unit, '') AS unit
                          FROM public.budget_items bi JOIN public.materials m ON m.id = bi.material_id
                         WHERE bi.id = :id AND bi.project_id = :p AND bi.category = 'Material'");
                    $tally = []; $clean = [];
                    foreach ($items as $n => $it) {
                        $bid      = (int)($it['budget_item_id'] ?? 0);
                        $qty      = round((float)($it['qty'] ?? 0), 3);
                        $price    = round((float)($it['unit_price'] ?? 0), 2);
                        $itemCur  = (int)($it['item_currency_id'] ?? 0) ?: null;
                        $itemRate = (float)($it['item_exch_rate'] ?? 1);
                        $no       = 'Baris ' . ($n + 1);
                        if ($bid <= 0 || $qty <= 0) throw new PoBusinessError("{$no}: material dan qty (> 0) wajib diisi.");
                        if ($price < 0) throw new PoBusinessError("{$no}: harga satuan tidak valid.");

                        // Validasi item_currency_id
                        if ($itemCur) {
                            $stCk = $db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id = :id");
                            $stCk->execute([':id' => $itemCur]);
                            $curCode = $stCk->fetchColumn();
                            if (!$curCode) throw new PoBusinessError("{$no}: mata uang item tidak ditemukan.");
                            if ($curCode === 'IDR') $itemRate = 1.0;
                        } else {
                            $itemRate = 1.0; // default IDR
                        }
                        if ($itemRate <= 0) $itemRate = 1.0;

                        $stBi->execute([':id' => $bid, ':p' => $prj]);
                        $bi = $stBi->fetch(PDO::FETCH_ASSOC);
                        if (!$bi) throw new PoBusinessError("{$no}: material tidak ada di RAB proyek ini.");

                        $tally[$bid] = ($tally[$bid] ?? 0) + $qty;
                        $limit = (float)$bi['qty_plan'] * (1 + (float)$bi['tol'] / 100);
                        $ord   = $ordered[$bid] ?? 0.0;
                        if ($ord + $tally[$bid] > $limit + PO_EPS) {
                            throw new PoBusinessError(
                                "{$bi['material_name']}: qty PO " . po_fmt($tally[$bid]) . " melebihi sisa kuota RAB (" .
                                po_fmt(max($limit - $ord, 0)) . " {$bi['unit']}). Ubah RAB bila memang perlu tambah."
                            );
                        }
                        $clean[] = ['bid' => $bid, 'mid' => (int)$bi['material_id'], 'name' => $bi['material_name'],
                                    'unit' => $bi['unit'], 'qty' => $qty, 'price' => $price,
                                    'item_cur' => $itemCur, 'item_rate' => $itemRate];
                    }

                    if ($id > 0) {
                        $st = $db->prepare("
                            UPDATE purchase_orders
                               SET project_id = :p, supplier_id = :s, po_date = CAST(:d AS date), notes = :n,
                                   currency_id = :c, exch_rate = :r, ppn_mode = :pm, ppn_rate = :prt, updated_at = NOW()   -- [PPN]
                             WHERE id = :id");
                        $st->execute([':p' => $prj, ':s' => $sup, ':d' => $date, ':n' => $notes, ':c' => $cur, ':r' => $rate, ':pm' => $ppnMode, ':prt' => $ppnRate, ':id' => $id]);   // [PPN]
                        $poNumber = $db->query("SELECT po_number FROM purchase_orders WHERE id = " . $id)->fetchColumn();
                        $db->prepare("DELETE FROM po_items WHERE po_id = :id")->execute([':id' => $id]);
                    } else {
                        if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                        $poNumber = CreateRefNo('purchase_orders', 'po_number', 'PO');
                        $st = $db->prepare("
                            INSERT INTO purchase_orders
                                (po_number, project_id, supplier_id, po_date, status, notes, currency_id, exch_rate, ppn_mode, ppn_rate)   -- [PPN]
                            VALUES (:no, :p, :s, CAST(:d AS date), 'Draft', :n, :c, :r, :pm, :prt)
                            RETURNING id");
                        $st->execute([':no' => $poNumber, ':p' => $prj, ':s' => $sup, ':d' => $date, ':n' => $notes, ':c' => $cur, ':r' => $rate, ':pm' => $ppnMode, ':prt' => $ppnRate]);   // [PPN]
                        $id = (int)$st->fetchColumn();
                    }

                    $stIns = $db->prepare("
                        INSERT INTO po_items (po_id, budget_item_id, material_id, description, qty, unit, unit_price, currency_id, exch_rate)
                        VALUES (:po, :b, :m, :desc, :q, :u, :pr, :ic, :ir)");
                    foreach ($clean as $c) {
                        $stIns->execute([':po' => $id, ':b' => $c['bid'], ':m' => $c['mid'], ':desc' => $c['name'],
                                         ':q' => $c['qty'], ':u' => $c['unit'], ':pr' => $c['price'],
                                         ':ic' => $c['item_cur'], ':ir' => $c['item_rate']]);
                    }

                    $db->commit();
                    sendJsonResponse(true, ['id' => $id, 'po_number' => $poNumber], "PO {$poNumber} berhasil disimpan.");
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (PoBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Ubah status PO
         * ------------------------------------------------------------------ */
        case 'change_status':
            $id = (int)($_POST['id'] ?? 0);
            $to = (string)($_POST['to'] ?? '');
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT po_number, status, supplier_id FROM purchase_orders WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $po = $st->fetch(PDO::FETCH_ASSOC);
                if (!$po) throw new PoBusinessError('PO tidak ditemukan.');
                if (!in_array($to, PO_TRANSITIONS[$po['status']] ?? [], true)) {
                    throw new PoBusinessError("Status {$po['status']} tidak dapat diubah ke {$to}.");
                }
                if (in_array($to, ['Dibatalkan', 'Draft'], true) && po_received_total($db, $id) > PO_EPS) {
                    throw new PoBusinessError('PO ini sudah ada barang yang diterima (GRN), tidak dapat dibatalkan / dikembalikan ke Draft.');
                }
                if (in_array($to, ['Diajukan', 'Disetujui'], true)) {
                    if (!$po['supplier_id']) throw new PoBusinessError('Supplier wajib diisi sebelum PO diajukan / disetujui.');
                    $st = $db->prepare("SELECT COUNT(*) FROM po_items WHERE po_id = :id");
                    $st->execute([':id' => $id]);
                    if ((int)$st->fetchColumn() === 0) throw new PoBusinessError('PO belum memiliki item.');
                }
                $st = $db->prepare("UPDATE purchase_orders SET status = :s, updated_at = NOW() WHERE id = :id");
                $st->execute([':s' => $to, ':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "PO {$po['po_number']} sekarang berstatus {$to}.");
            } catch (PoBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus PO (hanya Draft, belum dipakai GRN)
         * ------------------------------------------------------------------ */
        case 'delete_po':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT po_number, status FROM purchase_orders WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $po = $st->fetch(PDO::FETCH_ASSOC);
                if (!$po) throw new PoBusinessError('PO tidak ditemukan.');
                if ($po['status'] !== 'Draft') throw new PoBusinessError('Hanya PO berstatus Draft yang dapat dihapus. Gunakan Batalkan untuk PO lain.');

                $st = $db->prepare("SELECT COUNT(*) FROM grn_hdr WHERE po_id = :id");
                $st->execute([':id' => $id]);
                if ((int)$st->fetchColumn() > 0) throw new PoBusinessError('PO sudah memiliki GRN, tidak dapat dihapus.');

                $db->prepare("DELETE FROM po_items WHERE po_id = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM purchase_orders WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "PO {$po['po_number']} dihapus.");
            } catch (PoBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[poCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[poCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}