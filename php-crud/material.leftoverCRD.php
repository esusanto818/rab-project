<?php
/**
 * material.leftoverCRD.php
 * ============================================================
 * Backend "Material Left Over" = RETUR SISA MATERIAL PROYEK ke gudang.  Front end: js/material.leftover.js,
 * views/material.leftover.php.
 *
 * Konsep : material yang sudah di-issue ke proyek (masuk WIP) tetapi tidak terpakai (sisa / left over) dikembalikan ke gudang:
 *          stok bertambah kembali dan WIP proyek berkurang, sehingga biaya proyek (dan HPP saat Pelepasan WIP) tidak terlalu besar.
 * Aturan :
 *  1. Qty retur sisa per material <= qty terissue bersih proyek = SUM(issue posted tipe PROJECT) - SUM(retur sisa posted).
 *  2. Nilai retur = biaya rata-rata issue bersih proyek untuk material itu (nilai issue bersih / qty issue bersih), sehingga
 *     WIP proyek berkurang tepat sebesar nilai yang dulu dibebankan. Stok masuk (stock_movements IN, reference_type
 *     'MATERIAL_LEFTOVER') dengan biaya tersebut -> membentuk layer FIFO baru di gudang tujuan.
 *  3. Jurnal (satu transaksi dengan stok): aturan fiscal_config WH_LEFTOVER = Dr 1500 Persediaan Bahan Baku / Cr 1700 WIP Proyek,
 *     reference_type 'material_leftover'. Gagal posting = seluruh transaksi dibatalkan.
 *  4. Reverse hanya bila stok hasil retur belum terpakai (stok gudang >= qty retur); jurnal & stok ikut dibatalkan.
 *  5. Tidak mengubah kuota RAB di Warehouse Issuing (retur sisa tidak mengurangi 'sudah di-issue').
 * Task   : lookup_projects | lookup_warehouses | lookup_materials | list | detail | position | save | reverse
 * Migrasi: sql/2026-10-27.material_leftover.sql dan sql/2026-10-27.leftover_journal_enum.sql
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

const LO_EPS = 0.00005;                       // toleransi qty
const LO_JOURNAL_REF = 'material_leftover';   // nilai enum journal_entries.reference_type
const LO_JOURNAL_RULE = 'WH_LEFTOVER';        // fiscal_config: Dr Persediaan Bahan Baku / Cr WIP Proyek
const LO_STOCK_REF = 'MATERIAL_LEFTOVER';     // stock_movements.reference_type

class LoError extends Exception {}

function lo_send($success, $data = [], $message = '', $extra = []) {
    $resp = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $resp['data'] = $data;
        if (!isset($resp['totalCount'])) $resp['totalCount'] = is_array($data) ? count($data) : 0;
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function lo_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/** Qty & nilai issue bersih proyek untuk satu material (issue PROJECT posted - retur sisa posted). */
function lo_net($db, int $projectId, int $materialId): array {
    $st = $db->prepare("
        SELECT COALESCE(SUM(i.qty_out), 0) AS q, COALESCE(SUM(i.qty_out * i.unit_cost), 0) AS a
          FROM public.material_out_items i JOIN public.material_out_header h ON h.id = i.material_out_id
         WHERE h.project_id = :p AND i.material_id = :m AND h.status = 'posted' AND h.issue_type = 'PROJECT'");
    $st->execute([':p' => $projectId, ':m' => $materialId]);
    $iss = $st->fetch(PDO::FETCH_ASSOC);
    $st = $db->prepare("
        SELECT COALESCE(SUM(i.qty), 0) AS q, COALESCE(SUM(i.qty * i.unit_cost), 0) AS a
          FROM public.material_leftover_items i JOIN public.material_leftover_header h ON h.id = i.header_id
         WHERE h.project_id = :p AND i.material_id = :m AND h.status = 'posted'");
    $st->execute([':p' => $projectId, ':m' => $materialId]);
    $lo = $st->fetch(PDO::FETCH_ASSOC);
    $q = round((float)$iss['q'] - (float)$lo['q'], 4);
    $a = round((float)$iss['a'] - (float)$lo['a'], 2);
    return ['qty' => max($q, 0), 'amount' => max($a, 0), 'avg_cost' => ($q > LO_EPS) ? round(max($a, 0) / $q, 4) : 0.0];
}

function lo_onhand($db, int $materialId, int $warehouseId): float {
    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type = 'IN' THEN qty ELSE -qty END), 0)
                          FROM public.stock_movements WHERE item_id = :m AND warehouse_id = :w");
    $st->execute([':m' => $materialId, ':w' => $warehouseId]);
    return round((float)$st->fetchColumn(), 4);
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    lo_send(false, [], 'Sesi berakhir, silakan login ulang.');
}
$userId = (int)$_SESSION['user_id'];
$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        case 'lookup_projects':
            $rows = $db->query("SELECT id, name FROM public.projects ORDER BY name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            lo_send(true, $rows);
            break;

        // Gudang bersama (gudang utama): semua gudang aktif sebagai tujuan retur sisa
        case 'lookup_warehouses':
            $rows = $db->query("SELECT id, code, name FROM public.warehouses WHERE active = true ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            lo_send(true, $rows);
            break;

        // Material yang pernah di-issue ke proyek dan masih punya qty bersih > 0
        case 'lookup_materials':
            $pid = (int)($_REQUEST['project_id'] ?? 0);
            $q   = trim((string)($_REQUEST['query'] ?? ''));
            if ($pid <= 0) lo_send(true, []);
            $st = $db->prepare("
                SELECT m.id AS material_id, m.material_code, m.material_name, m.unit
                  FROM public.materials m
                 WHERE (m.material_name ILIKE :q OR m.material_code ILIKE :q)
                   AND EXISTS (SELECT 1 FROM public.material_out_items i JOIN public.material_out_header h ON h.id = i.material_out_id
                                WHERE h.project_id = :p AND i.material_id = m.id AND h.status = 'posted' AND h.issue_type = 'PROJECT')
                 ORDER BY m.material_name LIMIT 100");
            $st->execute([':q' => '%' . $q . '%', ':p' => $pid]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $n = lo_net($db, $pid, (int)$r['material_id']);
                if ($n['qty'] <= LO_EPS) continue;
                $out[] = ['material_id' => (int)$r['material_id'], 'material_code' => $r['material_code'], 'material_name' => $r['material_name'],
                          'unit' => $r['unit'], 'net_qty' => $n['qty'], 'avg_cost' => $n['avg_cost']];
            }
            lo_send(true, $out);
            break;

        case 'list':
            $start = max((int)($_REQUEST['start'] ?? 0), 0);
            $limit = min(max((int)($_REQUEST['limit'] ?? 250), 1), 1000);
            $q     = trim((string)($_REQUEST['query'] ?? ''));
            $pid   = (int)($_REQUEST['project_id'] ?? 0);
            $where = []; $params = [];
            if ($q !== '') { $where[] = "(h.ref_no ILIKE :q OR p.name ILIKE :q OR w.name ILIKE :q OR h.remark ILIKE :q)"; $params[':q'] = '%' . $q . '%'; }
            if ($pid > 0)  { $where[] = "h.project_id = :pid"; $params[':pid'] = $pid; }
            $w = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $from = "FROM public.material_leftover_header h
                     JOIN public.projects p ON p.id = h.project_id
                     JOIN public.warehouses w ON w.id = h.warehouse_id
                     LEFT JOIN (SELECT header_id, COUNT(*) AS line_count, SUM(qty * unit_cost) AS total_amount
                                  FROM public.material_leftover_items GROUP BY header_id) t ON t.header_id = h.id";
            $st = $db->prepare("SELECT COUNT(*) {$from} {$w}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT h.id, h.ref_no, h.doc_date, h.status, h.remark, h.project_id, p.name AS project_name,
                                       h.warehouse_id, w.name AS warehouse_name, COALESCE(t.line_count, 0) AS line_count,
                                       COALESCE(t.total_amount, 0) AS total_amount
                                {$from} {$w} ORDER BY h.doc_date DESC, h.id DESC LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['project_id'] = (int)$r['project_id']; $r['warehouse_id'] = (int)$r['warehouse_id'];
                $r['line_count'] = (int)$r['line_count']; $r['total_amount'] = round((float)$r['total_amount'], 2);
            }
            unset($r);
            lo_send(true, $rows, '', ['totalCount' => $total]);
            break;

        case 'detail':
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id < 1) throw new LoError('ID dokumen tidak valid.');
            $st = $db->prepare("SELECT i.id, i.material_id, m.material_code, m.material_name, m.unit, i.qty, i.unit_cost,
                                       (i.qty * i.unit_cost) AS amount, i.notes
                                  FROM public.material_leftover_items i JOIN public.materials m ON m.id = i.material_id
                                 WHERE i.header_id = :id ORDER BY i.id");
            $st->execute([':id' => $id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['qty'] = (float)$r['qty']; $r['unit_cost'] = (float)$r['unit_cost']; $r['amount'] = round((float)$r['amount'], 2); }
            unset($r);
            lo_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Posisi material per proyek: issue, retur sisa, bersih (terpakai) vs RAB
         * ------------------------------------------------------------------ */
        case 'position':
            $pid = (int)($_REQUEST['project_id'] ?? 0);
            $q   = trim((string)($_REQUEST['query'] ?? ''));
            $where = ["h.status = 'posted'", "h.issue_type = 'PROJECT'"]; $params = [];
            if ($pid > 0) { $where[] = "h.project_id = :pid"; $params[':pid'] = $pid; }
            $w = implode(' AND ', $where);
            $sql = "
                SELECT k.project_id, p.name AS project_name, k.material_id, m.material_code, m.material_name, m.unit,
                       COALESCE(rab.q, 0) AS rab_qty, k.issued_qty, k.issued_amt,
                       COALESCE(lo.q, 0) AS lo_qty, COALESCE(lo.a, 0) AS lo_amt
                  FROM (SELECT h.project_id, i.material_id, SUM(i.qty_out) AS issued_qty, SUM(i.qty_out * i.unit_cost) AS issued_amt
                          FROM public.material_out_items i JOIN public.material_out_header h ON h.id = i.material_out_id
                         WHERE {$w} GROUP BY h.project_id, i.material_id) k
                  JOIN public.projects p ON p.id = k.project_id
                  JOIN public.materials m ON m.id = k.material_id
                  LEFT JOIN (SELECT project_id, material_id, SUM(qty_plan) AS q FROM public.budget_items
                              WHERE material_id IS NOT NULL GROUP BY project_id, material_id) rab
                         ON rab.project_id = k.project_id AND rab.material_id = k.material_id
                  LEFT JOIN (SELECT h.project_id, i.material_id, SUM(i.qty) AS q, SUM(i.qty * i.unit_cost) AS a
                               FROM public.material_leftover_items i JOIN public.material_leftover_header h ON h.id = i.header_id
                              WHERE h.status = 'posted' GROUP BY h.project_id, i.material_id) lo
                         ON lo.project_id = k.project_id AND lo.material_id = k.material_id";
            if ($q !== '') { $sql .= " WHERE (p.name ILIKE :q OR m.material_name ILIKE :q OR m.material_code ILIKE :q)"; $params[':q'] = '%' . $q . '%'; }
            $sql .= " ORDER BY p.name, m.material_name LIMIT 2000";
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['project_id'] = (int)$r['project_id']; $r['material_id'] = (int)$r['material_id'];
                foreach (['rab_qty', 'issued_qty', 'issued_amt', 'lo_qty', 'lo_amt'] as $f) $r[$f] = (float)$r[$f];
                $r['net_qty'] = round($r['issued_qty'] - $r['lo_qty'], 4);
                $r['net_amt'] = round($r['issued_amt'] - $r['lo_amt'], 2);
                $r['used_pct'] = $r['rab_qty'] > 0 ? round($r['net_qty'] / $r['rab_qty'] * 100, 1) : null;     // terpakai bersih vs RAB
            }
            unset($r);
            lo_send(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Simpan & posting retur sisa (stok masuk + jurnal, satu transaksi)
         * ------------------------------------------------------------------ */
        case 'save':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $whId      = (int)($_POST['warehouse_id'] ?? 0);
            $date      = (string)($_POST['doc_date'] ?? '');
            $remark    = trim((string)($_POST['remark'] ?? '')) ?: null;
            $details   = json_decode($_POST['details'] ?? '[]', true);
            try {
                if ($projectId < 1) throw new LoError('Proyek wajib dipilih.');
                if ($whId < 1) throw new LoError('Gudang tujuan wajib dipilih.');
                if (!lo_valid_date($date)) throw new LoError('Tanggal tidak valid.');
                if (!is_array($details) || !count($details)) throw new LoError('Tambahkan minimal satu material.');

                $lines = [];                                                  // gabung baris ganda per material
                foreach ($details as $d) {
                    $mid = (int)($d['material_id'] ?? 0);
                    $qty = round((float)($d['qty'] ?? 0), 4);
                    if ($mid < 1) throw new LoError('Material pada detail tidak valid.');
                    if ($qty <= 0) throw new LoError('Qty retur sisa harus lebih besar dari 0.');
                    if (!isset($lines[$mid])) $lines[$mid] = ['qty' => 0.0, 'notes' => ''];
                    $lines[$mid]['qty'] = round($lines[$mid]['qty'] + $qty, 4);
                    if (!empty($d['notes'])) $lines[$mid]['notes'] = trim((string)$d['notes']);
                }

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT id, name FROM public.projects WHERE id = :id");
                    $st->execute([':id' => $projectId]);
                    $prj = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prj) throw new LoError('Proyek tidak ditemukan.');
                    $st = $db->prepare("SELECT id, active FROM public.warehouses WHERE id = :id");
                    $st->execute([':id' => $whId]);
                    $wh = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$wh || !in_array($wh['active'], [true, 't', 'true', 1, '1'], true)) throw new LoError('Gudang tidak ditemukan / tidak aktif.');

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $refNo = CreateRefNo('material_leftover_header', 'ref_no', 'MLO');
                    $st = $db->prepare("INSERT INTO public.material_leftover_header (ref_no, project_id, warehouse_id, doc_date, status, remark, created_by)
                                        VALUES (:r, :p, :w, CAST(:d AS date), 'posted', :rm, :u) RETURNING id");
                    $st->execute([':r' => $refNo, ':p' => $projectId, ':w' => $whId, ':d' => $date, ':rm' => $remark, ':u' => $userId]);
                    $hdrId = (int)$st->fetchColumn();

                    $totalAmt = 0.0;
                    $stItem = $db->prepare("INSERT INTO public.material_leftover_items (header_id, material_id, qty, unit_cost, notes)
                                            VALUES (:h, :m, :q, :c, :n)");
                    $stMov = $db->prepare("INSERT INTO public.stock_movements
                                (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost, reference_type, reference_id, reference_no, remark, created_by)
                                VALUES (CAST(:d AS date), :m, NULL, :w, 'IN', :q, :c, :rt, :rid, :rn, :rm, :u)");
                    foreach ($lines as $mid => $ln) {
                        $db->prepare("SELECT pg_advisory_xact_lock(CAST(:a AS integer), CAST(:b AS integer))")->execute([':a' => $projectId, ':b' => $mid]);   // serialkan qty bersih proyek+material
                        $st = $db->prepare("SELECT material_name FROM public.materials WHERE id = :id");
                        $st->execute([':id' => $mid]);
                        $name = $st->fetchColumn();
                        if (!$name) throw new LoError("Material ID {$mid} tidak ditemukan.");

                        $net = lo_net($db, $projectId, $mid);
                        if ($net['qty'] <= LO_EPS) throw new LoError("{$name}: tidak ada qty issue bersih untuk proyek ini (belum pernah di-issue / sudah diretur seluruhnya).");
                        if ($ln['qty'] > $net['qty'] + LO_EPS) {
                            throw new LoError("{$name}: qty retur sisa (" . rtrim(rtrim(number_format($ln['qty'], 4, '.', ''), '0'), '.') .
                                ") melebihi qty issue bersih proyek (" . rtrim(rtrim(number_format($net['qty'], 4, '.', ''), '0'), '.') . ").");
                        }
                        $cost = $net['avg_cost'];                                           // biaya rata-rata issue bersih proyek
                        $stItem->execute([':h' => $hdrId, ':m' => $mid, ':q' => $ln['qty'], ':c' => $cost, ':n' => $ln['notes'] !== '' ? $ln['notes'] : null]);
                        $stMov->execute([':d' => $date, ':m' => $mid, ':w' => $whId, ':q' => $ln['qty'], ':c' => $cost, ':rt' => LO_STOCK_REF,
                                         ':rid' => $hdrId, ':rn' => $refNo, ':rm' => 'Retur sisa material proyek ' . $prj['name'], ':u' => $userId]);
                        $totalAmt += round($ln['qty'] * $cost, 2);
                    }
                    $totalAmt = round($totalAmt, 2);
                    if ($totalAmt > 0.004) {
                        $jr = AutoPostJournal($db, [
                            'rule_code' => LO_JOURNAL_RULE, 'reference_type' => LO_JOURNAL_REF, 'reference_id' => $hdrId,
                            'journal_date' => $date, 'amount' => $totalAmt, 'description' => "Retur sisa material {$refNo} - {$prj['name']}",
                        ]);
                        if (!$jr['success']) throw new LoError('Gagal posting jurnal: ' . $jr['message']);
                    }
                    $db->commit();
                    lo_send(true, ['id' => $hdrId, 'ref_no' => $refNo], "Retur sisa material diposting. No: {$refNo} (nilai " . number_format($totalAmt, 2) . ').');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (LoError $e) {
                lo_send(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Reverse: hanya bila stok hasil retur belum terpakai
         * ------------------------------------------------------------------ */
        case 'reverse':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT id, ref_no, status, warehouse_id FROM public.material_leftover_header WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $h = $st->fetch(PDO::FETCH_ASSOC);
                if (!$h) throw new LoError('Dokumen tidak ditemukan.');
                if ($h['status'] !== 'posted') throw new LoError('Hanya dokumen berstatus posted yang dapat di-reverse.');
                $whId = (int)$h['warehouse_id'];

                $st = $db->prepare("SELECT i.material_id, i.qty, m.material_name FROM public.material_leftover_items i
                                      JOIN public.materials m ON m.id = i.material_id WHERE i.header_id = :id");
                $st->execute([':id' => $id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $db->prepare("SELECT pg_advisory_xact_lock(CAST(:a AS integer), CAST(:b AS integer))")->execute([':a' => (int)$it['material_id'], ':b' => $whId]);
                    $onhand = lo_onhand($db, (int)$it['material_id'], $whId);
                    if ($onhand + LO_EPS < (float)$it['qty']) {
                        throw new LoError("Tidak dapat di-reverse: stok {$it['material_name']} di gudang (" . rtrim(rtrim(number_format($onhand, 4, '.', ''), '0'), '.') .
                            ") kurang dari qty retur (" . rtrim(rtrim(number_format((float)$it['qty'], 4, '.', ''), '0'), '.') . "); stok sudah terpakai.");
                    }
                }
                $st = $db->prepare("SELECT 1 FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
                $st->execute([':t' => LO_JOURNAL_REF, ':id' => $id]);
                if ($st->fetchColumn()) ReverseJournalByReference($db, LO_JOURNAL_REF, $id);
                $db->prepare("DELETE FROM public.stock_movements WHERE reference_type = :t AND reference_id = :id")->execute([':t' => LO_STOCK_REF, ':id' => $id]);
                $db->prepare("UPDATE public.material_leftover_header SET status = 'reversed', reversed_at = NOW(), reversed_by = :u WHERE id = :id")
                   ->execute([':u' => $userId, ':id' => $id]);
                $db->commit();
                lo_send(true, [], "Dokumen {$h['ref_no']} di-reverse; stok dan jurnal dibatalkan.");
            } catch (LoError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                lo_send(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            lo_send(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[material.leftoverCRD] ' . $e->getMessage());
    lo_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[material.leftoverCRD] ' . $e->getMessage());
    lo_send(false, [], 'System Error: ' . $e->getMessage());
}