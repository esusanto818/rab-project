<?php
/**
 * project.wipreleaseCRD.php
 * ============================================================
 * Backend Controller Pelepasan WIP -> HPP per proyek (project.wiprelease.js).
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Latar belakang:
 *   Warehouse Issuing memposting Dr WIP (1700) / Cr Persediaan (1500) (rule ISSUE_PROJECT).
 *   Pendapatan diakui saat penagihan termin, jadi biaya perlu dilepas dari WIP ke Beban Pokok (HPP)
 *   agar Laba Rugi menampilkan biaya proyek. Modul ini melakukannya:
 *     - per % progress  : HPP kumulatif = WIP kumulatif proyek x progress %
 *     - proyek selesai  : progress dianggap 100% (seluruh WIP dilepas)
 *
 * Rumus (server selalu menghitung ulang, nilai dari klien tidak dipercaya):
 *   WIP kumulatif  = SUM debit jurnal 'material_out' (issue PROJECT, posted) milik proyek
 *   sudah dilepas  = SUM project_wip_release.amount proyek
 *   nilai dilepas  = round(WIP kumulatif x pct / 100, 2) - sudah dilepas      (harus > 0)
 *   Issue baru setelah pelepasan menambah WIP kumulatif; pelepasan berikutnya otomatis menyesuaikan.
 *
 * Jurnal: AutoPostJournal rule 'WIP_RELEASE' (Dr HPP / Cr WIP), reference_type = 'wip_release',
 *   reference_id = project_wip_release.id, tanggal = tanggal pelepasan; dalam transaksi yang sama
 *   (gagal posting = pelepasan dibatalkan).
 * Hapus (reverse): hanya pelepasan TERAKHIR per proyek (agar snapshot pelepasan berikutnya tetap konsisten);
 *   jurnalnya dihapus lewat ReverseJournalByReference.
 * Akses: simpan >= 2, hapus >= 4 ($_SESSION['FormAccess']).
 * Butuh migrasi: sql/2026-10-16.project_wip_release.sql
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

const WR_EPS = 0.005;                          // toleransi nilai rupiah
const WR_JOURNAL_REF = 'wip_release';          // nilai enum journal_entries.reference_type
const WR_JOURNAL_RULE = 'WIP_RELEASE';         // rule_code di fiscal_config
const WR_ACCESS_ADD = 2;
const WR_ACCESS_DEL = 4;

class WrBusinessError extends Exception {}

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

function wr_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

function wr_access(): int {
    return intval($_SESSION['FormAccess'] ?? 0);
}

/**
 * Hitung angka pelepasan untuk satu proyek.
 * $mode 'complete' -> 100%; 'progress' -> $pctIn (null = projects.progress).
 */
function wr_calc($db, int $projectId, string $mode, $pctIn, int $excludeReleaseId = 0): array {
    $st = $db->prepare("SELECT id, name, COALESCE(progress, 0) AS progress, status
                          FROM public.projects WHERE id = :id");
    $st->execute([':id' => $projectId]);
    $prj = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prj) throw new WrBusinessError('Proyek tidak ditemukan.');

    // WIP kumulatif = debit jurnal issue proyek (hanya yang benar-benar sudah berjurnal)
    $st = $db->prepare("
        SELECT COALESCE(SUM(jl.debit), 0)
          FROM public.journal_entries je
          JOIN public.journal_lines jl ON jl.journal_id = je.id
          JOIN public.material_out_header h ON h.id = je.reference_id
         WHERE je.reference_type::text = 'material_out' AND je.status::text = 'posted'
           AND h.project_id = :p AND h.issue_type = 'PROJECT' AND jl.debit > 0");
    $st->execute([':p' => $projectId]);
    $journaled = (float)$st->fetchColumn();

    // nilai issue menurut dokumen (pembanding: bila > journaled berarti ada issue yang belum berjurnal)
    $st = $db->prepare("
        SELECT COALESCE(SUM(i.qty_out * i.unit_cost), 0)
          FROM public.material_out_items i
          JOIN public.material_out_header h ON h.id = i.material_out_id
         WHERE h.project_id = :p AND h.status = 'posted' AND h.issue_type = 'PROJECT'");
    $st->execute([':p' => $projectId]);
    $issued = (float)$st->fetchColumn();

    $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM public.project_wip_release
                         WHERE project_id = :p AND id <> :x");
    $st->execute([':p' => $projectId, ':x' => $excludeReleaseId]);
    $released = (float)$st->fetchColumn();

    if ($mode === 'complete') {
        $pct = 100.0;
    } else {
        $pct = ($pctIn === null || $pctIn === '') ? (float)$prj['progress'] : (float)$pctIn;
    }
    if ($pct < 0 || $pct > 100) throw new WrBusinessError('Persentase progress harus antara 0 - 100.');
    $pct = round($pct, 2);

    $target = round($journaled * $pct / 100, 2);
    $amount = round($target - $released, 2);

    return [
        'project_id'      => (int)$prj['id'],
        'project_name'    => $prj['name'],
        'project_status'  => $prj['status'],
        'project_progress'=> (float)$prj['progress'],
        'mode'            => $mode,
        'progress_pct'    => $pct,
        'wip_cumulative'  => round($journaled, 2),
        'issued_cost'     => round($issued, 2),
        'unjournaled'     => round(max($issued - $journaled, 0), 2),
        'released_before' => round($released, 2),
        'target_released' => $target,
        'amount'          => $amount,
    ];
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) sendJsonResponse(false, [], 'Hak baca pelepasan WIP proyek tidak tersedia.');

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Combo proyek (+ progress & status)
         * ------------------------------------------------------------------ */
        case 'list_project_options':
            $rows = $db->query("
                SELECT id, name, COALESCE(progress, 0) AS progress, status
                  FROM public.projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['progress'] = (float)$r['progress'];
            }
            unset($r);
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Pratinjau perhitungan (sebelum disimpan)
         * ------------------------------------------------------------------ */
        case 'preview':
            $prj  = (int)($_REQUEST['project_id'] ?? 0);
            $mode = ($_REQUEST['mode'] ?? 'progress') === 'complete' ? 'complete' : 'progress';
            $pct  = $_REQUEST['progress_pct'] ?? null;
            try {
                if ($prj <= 0) throw new WrBusinessError('Proyek wajib dipilih.');
                sendJsonResponse(true, wr_calc($db, $prj, $mode, $pct));
            } catch (WrBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Daftar pelepasan (grid utama)
         * ------------------------------------------------------------------ */
        case 'list_releases':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (r.ref_no ILIKE :q1 OR p.name ILIKE :q2 OR COALESCE(r.remark, '') ILIKE :q3)";
                $like = '%' . $query . '%';
                $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
            }

            $st = $db->prepare("SELECT COUNT(*) FROM public.project_wip_release r
                                  JOIN public.projects p ON p.id = r.project_id {$where}");
            $st->execute($params);
            $totalCount = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT r.id, r.ref_no, r.release_date, r.project_id, p.name AS project_name,
                       r.mode, r.progress_pct, r.cumulative_cost, r.released_before, r.amount, r.remark,
                       (SELECT je.ref_no FROM public.journal_entries je
                         WHERE je.reference_type::text = 'wip_release' AND je.reference_id = r.id LIMIT 1) AS journal_no,
                       NOT EXISTS (SELECT 1 FROM public.project_wip_release x
                                    WHERE x.project_id = r.project_id AND x.id > r.id) AS is_latest
                  FROM public.project_wip_release r
                  JOIN public.projects p ON p.id = r.project_id
                  {$where}
                 ORDER BY r.release_date DESC, r.id DESC
                 LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['project_id'] = (int)$r['project_id'];
                $r['progress_pct'] = (float)$r['progress_pct'];
                $r['cumulative_cost'] = (float)$r['cumulative_cost'];
                $r['released_before'] = (float)$r['released_before'];
                $r['amount'] = (float)$r['amount'];
                $r['is_latest'] = ($r['is_latest'] === true || $r['is_latest'] === 't' || $r['is_latest'] === '1');
            }
            unset($r);
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => $totalCount]);
            break;

        /* ------------------------------------------------------------------
         * Simpan pelepasan + posting jurnal (satu transaksi)
         * ------------------------------------------------------------------ */
        case 'save_release':
            if (wr_access() < WR_ACCESS_ADD) sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk membuat pelepasan WIP.');
            $prj    = (int)($_POST['project_id'] ?? 0);
            $date   = (string)($_POST['release_date'] ?? '');
            $mode   = ($_POST['mode'] ?? 'progress') === 'complete' ? 'complete' : 'progress';
            $pctIn  = $_POST['progress_pct'] ?? null;
            $remark = trim((string)($_POST['remark'] ?? '')) ?: null;

            try {
                if ($prj <= 0) throw new WrBusinessError('Proyek wajib dipilih.');
                if (!wr_valid_date($date)) throw new WrBusinessError('Tanggal pelepasan tidak valid.');

                $db->beginTransaction();
                try {
                    // kunci baris proyek agar dua pelepasan bersamaan tidak saling menimpa
                    $st = $db->prepare("SELECT 1 FROM public.projects WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $prj]);
                    if (!$st->fetchColumn()) throw new WrBusinessError('Proyek tidak ditemukan.');

                    $c = wr_calc($db, $prj, $mode, $pctIn);
                    if ($c['wip_cumulative'] <= WR_EPS) {
                        throw new WrBusinessError('Proyek ini belum punya WIP berjurnal. Posting issue material (atau backfill jurnal issue) terlebih dahulu.');
                    }
                    if ($c['amount'] <= WR_EPS) {
                        throw new WrBusinessError('Tidak ada WIP yang perlu dilepas: target dilepas ' . number_format($c['target_released'], 2) .
                            ' sudah tercapai / terlampaui oleh pelepasan sebelumnya (' . number_format($c['released_before'], 2) . ').');
                    }

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $refNo = CreateRefNo('project_wip_release', 'ref_no', 'WIP');

                    $st = $db->prepare("
                        INSERT INTO public.project_wip_release
                            (ref_no, release_date, project_id, mode, progress_pct, cumulative_cost, released_before, amount, remark, created_by)
                        VALUES (:no, CAST(:d AS date), :p, :m, :pct, :cum, :rb, :a, :r, :u)
                        RETURNING id");
                    $st->execute([':no' => $refNo, ':d' => $date, ':p' => $prj, ':m' => $mode, ':pct' => $c['progress_pct'],
                                  ':cum' => $c['wip_cumulative'], ':rb' => $c['released_before'], ':a' => $c['amount'],
                                  ':r' => $remark, ':u' => (int)$_SESSION['user_id'] ?: null]);
                    $id = (int)$st->fetchColumn();

                    $jr = AutoPostJournal($db, [
                        'rule_code'      => WR_JOURNAL_RULE,
                        'reference_type' => WR_JOURNAL_REF,
                        'reference_id'   => $id,
                        'journal_date'   => $date,
                        'amount'         => $c['amount'],
                        'description'    => "Pelepasan WIP ke HPP {$refNo} - {$c['project_name']} (" . rtrim(rtrim(number_format($c['progress_pct'], 2, '.', ''), '0'), '.') . '%)',
                    ]);
                    if (!$jr['success']) throw new WrBusinessError('Gagal posting jurnal: ' . $jr['message']);

                    $db->commit();
                    sendJsonResponse(true, ['id' => $id, 'ref_no' => $refNo, 'amount' => $c['amount']],
                        "Pelepasan WIP {$refNo} berhasil diposting (" . number_format($c['amount'], 2) . ').');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (WrBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus (reverse) pelepasan: hanya yang terakhir per proyek
         * ------------------------------------------------------------------ */
        case 'delete_release':
            if (wr_access() < WR_ACCESS_DEL) sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk menghapus pelepasan WIP.');
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT ref_no, project_id FROM public.project_wip_release WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new WrBusinessError('Pelepasan tidak ditemukan.');

                $st = $db->prepare("SELECT COUNT(*) FROM public.project_wip_release WHERE project_id = :p AND id > :id");
                $st->execute([':p' => $r['project_id'], ':id' => $id]);
                if ((int)$st->fetchColumn() > 0) {
                    throw new WrBusinessError("Hanya pelepasan terakhir per proyek yang dapat dihapus. Hapus pelepasan yang lebih baru terlebih dahulu.");
                }

                ReverseJournalByReference($db, WR_JOURNAL_REF, $id);
                $db->prepare("DELETE FROM public.project_wip_release WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Pelepasan WIP {$r['ref_no']} dihapus dan jurnalnya dibatalkan.");
            } catch (WrBusinessError $e) {
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
    error_log('[project.wipreleaseCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[project.wipreleaseCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}