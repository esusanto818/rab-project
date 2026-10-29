<?php
/**
 * ppn_backfillCRD.php  --  SKRIP SEKALI PAKAI (hapus dari server setelah selesai)
 * ============================================================
 * Memperbaiki tagihan termin (project_invoices) lama yang tersimpan TANPA PPN sehingga Laporan PPN kosong.
 *
 * Keputusan (Opsi A): nominal tagihan yang sudah ada DIANGGAP SUDAH TERMASUK PPN.
 *   dpp = amount / (1 + tarif/100) ; ppn = amount - dpp ; amount (total tagihan) TIDAK berubah
 *   -> alokasi pembayaran, sisa tagihan, dan piutang tidak bergeser.
 *   Tarif = projects.ppn_rate. Proyek bertarif 0 dilewati.
 * Yang diubah per tagihan (dalam satu transaksi, gagal = batal semua):
 *   1. project_invoices : dpp_amount, ppn_rate, ppn_amount
 *   2. jurnal penagihan : reverse jurnal lama (reference_type 'project_invoice'), posting ulang
 *                         PROJECT_INVOICE (DPP) + PROJECT_INVOICE_PPN (PPN), tanggal = tgl invoice
 *   3. projects.ppn_inclusive = true untuk proyek yang tertagih (agar Master Proyek konsisten dengan Opsi A)
 * Yang dilewati: tagihan yang sudah punya PPN (ppn_amount > 0) atau proyek bertarif 0.
 *
 * Task : preview (GET/POST, hanya baca)  |  run (POST, wajib confirm=YA)
 * Param: project_id (opsional, 0 = semua proyek yang memenuhi syarat)
 * Respons JSON standar {success, message, data, totalCount}.
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

const BF_JOURNAL_REF = 'project_invoice';          // sama dengan project_paymentCRD.php / m_proyekCRD.php
const BF_PPN_RULE    = 'PROJECT_INVOICE_PPN';      // Dr Piutang Usaha / Cr PPN Keluaran

class BfError extends Exception {}

function bf_send($success, $data = [], $message = '') {
    $resp = ['success' => $success, 'message' => $message];
    if ($success) { $resp['data'] = $data; $resp['totalCount'] = is_array($data) ? count($data) : 0; }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Tagihan yang perlu diperbaiki + hasil hitung DPP / PPN (Opsi A). */
function bf_candidates($db, int $projectId): array {
    $st = $db->prepare("
        SELECT v.id, v.inv_no, v.termin_name, v.invoice_date, v.amount, v.paid_amount,
               p.id AS project_id, p.name AS project_name, p.ppn_rate AS project_rate
          FROM public.project_invoices v
          JOIN public.projects p ON p.id = v.project_id
         WHERE COALESCE(v.ppn_amount, 0) <= 0
           AND COALESCE(p.ppn_rate, 0) > 0
           AND v.amount > 0
           AND (:p = 0 OR v.project_id = :p)
         ORDER BY v.project_id, v.invoice_date, v.id");
    $st->execute([':p' => $projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $rate = round((float)$r['project_rate'], 2);
        $amt  = round((float)$r['amount'], 2);
        $dpp  = round($amt / (1 + $rate / 100), 2);
        $r['id']          = (int)$r['id'];
        $r['project_id']  = (int)$r['project_id'];
        $r['ppn_rate']    = $rate;
        $r['amount']      = $amt;
        $r['paid_amount'] = (float)$r['paid_amount'];
        $r['dpp_amount']  = $dpp;
        $r['ppn_amount']  = round($amt - $dpp, 2);
        unset($r['project_rate']);
    }
    unset($r);
    return $rows;
}

function bf_has_journal($db, int $invId): bool {
    $st = $db->prepare("SELECT 1 FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => BF_JOURNAL_REF, ':id' => $invId]);
    return (bool)$st->fetchColumn();
}

function bf_post_journal($db, array $r): void {
    $jr = AutoPostJournal($db, [
        'rule_code' => 'PROJECT_INVOICE', 'reference_type' => BF_JOURNAL_REF, 'reference_id' => $r['id'],
        'journal_date' => $r['invoice_date'], 'amount' => $r['dpp_amount'],
        'description' => "Tagihan termin {$r['inv_no']} - {$r['project_name']}",
    ]);
    if (!$jr['success']) throw new BfError("{$r['inv_no']}: gagal posting jurnal penagihan: " . $jr['message']);
    if ($r['ppn_amount'] > 0.005) {
        $jr = AutoPostJournal($db, [
            'rule_code' => BF_PPN_RULE, 'reference_type' => BF_JOURNAL_REF, 'reference_id' => $r['id'],
            'journal_date' => $r['invoice_date'], 'amount' => $r['ppn_amount'],
            'description' => "PPN Keluaran termin {$r['inv_no']} - {$r['project_name']}",
        ]);
        if (!$jr['success']) throw new BfError("{$r['inv_no']}: gagal posting jurnal PPN Keluaran: " . $jr['message']);
    }
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    bf_send(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? 'preview';
$pid  = (int)($_REQUEST['project_id'] ?? 0);

try {
    switch ($task) {

        case 'preview':
            $rows = bf_candidates($db, $pid);
            bf_send(true, $rows, count($rows) ? count($rows) . ' tagihan akan diperbaiki (belum ada yang diubah).' : 'Tidak ada tagihan yang perlu diperbaiki.');
            break;

        case 'run':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new BfError('Gunakan metode POST.');
            if (($_POST['confirm'] ?? '') !== 'YA') throw new BfError("Kirim confirm=YA untuk menjalankan. Jalankan task=preview dulu.");

            $db->beginTransaction();
            try {
                $rows = bf_candidates($db, $pid);
                if (!count($rows)) throw new BfError('Tidak ada tagihan yang perlu diperbaiki.');

                $stUp  = $db->prepare("UPDATE public.project_invoices SET dpp_amount = :d, ppn_rate = :r, ppn_amount = :p WHERE id = :id");
                $stInc = $db->prepare("UPDATE public.projects SET ppn_inclusive = true WHERE id = :id");
                $projects = [];
                foreach ($rows as $r) {
                    $stUp->execute([':d' => $r['dpp_amount'], ':r' => $r['ppn_rate'], ':p' => $r['ppn_amount'], ':id' => $r['id']]);
                    if (bf_has_journal($db, $r['id'])) ReverseJournalByReference($db, BF_JOURNAL_REF, $r['id']);   // buang jurnal lama (sebesar total)
                    bf_post_journal($db, $r);                                                                       // posting ulang: DPP + PPN
                    $projects[$r['project_id']] = true;
                }
                foreach (array_keys($projects) as $projId) $stInc->execute([':id' => $projId]);   // Opsi A: nilai kontrak dianggap sudah termasuk PPN

                $db->commit();
                bf_send(true, $rows, count($rows) . ' tagihan berhasil diperbaiki (DPP/PPN terisi, jurnal diposting ulang). Hapus file ini dari server.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            bf_send(false, [], 'Aksi/Task tidak dikenal.');
    }
} catch (BfError $e) {
    bf_send(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[ppn_backfillCRD] ' . $e->getMessage());
    bf_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[ppn_backfillCRD] ' . $e->getMessage());
    bf_send(false, [], 'System Error: ' . $e->getMessage());
}