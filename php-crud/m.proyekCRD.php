<?php
/**
 * m.proyekCRD.php
 * ============================================================
 * Backend Controller untuk Master Proyek (public.projects)
 * Beserta Integrasi AI Task Breakdown & Validasi Rentang Tanggal.
 * [AUTO-INV] + Penerbitan tagihan otomatis (DP / Termin 1-3 = 30-20-20-30) berdasar breakdown task.
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

$task = $_REQUEST['task'] ?? '';
$db   = new crud();

function mpJson(array $payload) {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function PrepOrFailMP($stmt, $context = '') {
    if ($stmt === false) {
        throw new Exception(($context ? "[{$context}] " : '') . 'Query gagal dijalankan.');
    }
    return $stmt;
}
function mpFetchAll($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function mpFetchOne($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function mpExec($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    return $stmt;
}


/* ============================================================
 * [AUTO-INV][NEW] Penerbitan tagihan (invoice) otomatis berdasarkan breakdown task AI
 * ------------------------------------------------------------
 * Aturan:
 *  - 4 slot: Down Payment, Termin 1, Termin 2, Pelunasan Proyek = 30-20-20-30 dari Nilai Kontrak
 *    (DP mengikuti projects.dp_min_pct bila diisi; sisanya dibagi proporsional 20:20:30).
 *  - Tgl invoice = tgl mulai task pemicu - 7 hari ; Jatuh tempo = tgl mulai task pemicu - 1 hari.
 *  - DP dipicu task pertama; Termin 1-3 dipicu task yang dipilih AI (fallback: titik 25% / 50% / 75% durasi kerja).
 *  - Analisis data tagihan & pembayaran: belum ada tagihan -> mulai dari DP; sudah ada n tagihan -> lanjut slot ke n+1.
 *  - Slot terakhir menyerap sisa agar total tagihan = Nilai Kontrak (tidak ada selisih pembulatan).
 *  - Tagihan otomatis yang belum dibayar ikut disesuaikan tanggalnya bila task di-breakdown ulang.
 *  - [PPN] Nilai slot = DPP dari Nilai Kontrak (bila ppn_inclusive, DPP = kontrak / (1 + tarif)); PPN Keluaran = DPP x projects.ppn_rate;
 *    amount = DPP + PPN, sama dengan project_invoiceCRD.php.
 *  - [JURNAL] Tiap tagihan diposting lewat AutoPostJournal (PROJECT_INVOICE + PROJECT_INVOICE_PPN) dalam transaksi yang sama; gagal posting = batal semua.
 *    Sinkron tanggal tagihan otomatis = reverse jurnal lama lalu posting ulang di tanggal baru.
 * Butuh migrasi: sql/2026-10-28.project_invoices_auto.sql
 * ============================================================ */
const MP_INV_LEAD_DAYS    = 7;                                    // tgl invoice = mulai task - 7 hari
const MP_INV_REST_WEIGHTS = [20, 20, 30];                         // bobot Termin 1..3 (DP dihitung terpisah)
const MP_INV_NAMES        = ['Down Payment', 'Termin 1', 'Termin 2', 'Pelunasan Proyek'];

const MP_JOURNAL_REF = 'project_invoice';          // sama dengan project_invoiceCRD.php (TI_JOURNAL_REF)
const MP_PPN_RULE    = 'PROJECT_INVOICE_PPN';      // Dr Piutang Usaha / Cr PPN Keluaran

function mpBool($v) { return $v === true || $v === 't' || $v === '1' || $v === 1; }

// [AUTO-INV][REFACTOR] panggilan Gemini dipakai bersama oleh breakdown task & analisis tagihan
function mpCallGemini($prompt, $timeout = 20) {
    $apiKey = getenv('GOOGLE_API_KEY');
    $candidateModels = ['gemini-3.6-flash','gemini-3.5-flash-lite'];
    $response = null; $httpCode = 0; $lastErrorMsg = '';

    foreach ($candidateModels as $model) {
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 16384]   // [AI-BOQ] BoQ + expense panjang
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) break 2;
            $lastErrorMsg = "Model {$model} (HTTP {$httpCode}): " . $response;
            if ($httpCode === 503 || $httpCode === 429) sleep(1); else break;
        }
    }
    if ($httpCode !== 200) {
        throw new Exception("Gagal terhubung ke Gemini API: {$lastErrorMsg}");
    }
    $apiResult = json_decode($response, true);
    $raw = $apiResult['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return trim(str_replace(['```json', '```'], '', $raw));
}

// [JURNAL] jurnal penagihan: DPP -> pendapatan, PPN -> PPN Keluaran (sama dengan ti_post_journal)
function mpPostInvoiceJournal($db, $invId, $invNo, $date, $dpp, $ppn, $projectName) {
    $jr = AutoPostJournal($db, [
        'rule_code' => 'PROJECT_INVOICE', 'reference_type' => MP_JOURNAL_REF, 'reference_id' => $invId,
        'journal_date' => $date, 'amount' => $dpp, 'description' => "Tagihan termin {$invNo} - {$projectName}",
    ]);
    if (!$jr['success']) throw new Exception('Gagal posting jurnal penagihan: ' . $jr['message']);
    if ($ppn > 0.01) {
        $jr = AutoPostJournal($db, [
            'rule_code' => MP_PPN_RULE, 'reference_type' => MP_JOURNAL_REF, 'reference_id' => $invId,
            'journal_date' => $date, 'amount' => $ppn, 'description' => "PPN Keluaran termin {$invNo} - {$projectName}",
        ]);
        if (!$jr['success']) throw new Exception('Gagal posting jurnal PPN Keluaran: ' . $jr['message']);
    }
}
function mpHasJournal($db, $invId) {
    return (bool)mpFetchOne($db, "SELECT 1 AS x FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1",
                            ['t' => MP_JOURNAL_REF, 'id' => $invId], 'cek jurnal');
}

// Persentase per slot: DP = dp_min_pct (default 30), sisanya proporsional 20:20:30 -> default 30-20-20-30
function mpSlotPcts($dpPct) {
    $dp   = ($dpPct > 0 && $dpPct < 100) ? (float)$dpPct : 30.0;
    $rest = 100 - $dp;
    $sw   = array_sum(MP_INV_REST_WEIGHTS);
    $p    = [$dp];
    foreach (MP_INV_REST_WEIGHTS as $w) $p[] = round($rest * $w / $sw, 2);
    return $p;
}

// Titik pemicu default (index task): DP = task 1; Termin 1-3 = task pertama yang dimulai setelah 25% / 50% / 75% total durasi
function mpDefaultAnchors(array $tasks) {
    $n = count($tasks); $cumBefore = []; $cum = 0;
    foreach ($tasks as $i => $t) {
        $d = max(1, (int)floor((strtotime($t['end_date']) - strtotime($t['start_date'])) / 86400) + 1);
        $cumBefore[$i] = $cum; $cum += $d;
    }
    $idx = [0];
    foreach ([0.25, 0.50, 0.75] as $target) {
        $prev = end($idx); $pick = $n - 1;
        for ($i = $prev + 1; $i < $n; $i++) {
            if ($cum > 0 && $cumBefore[$i] / $cum >= $target - 1e-9) { $pick = $i; break; }
        }
        $idx[] = $pick;
    }
    return $idx;
}

// Nomor invoice berurutan dari satu nomor awal (CreateRefNo membaca data ter-commit, jadi tidak aman dipanggil berulang dalam 1 transaksi)
function mpRefPlus($ref, $n) {
    if ($n === 0) return $ref;
    if (preg_match('/^(.*?)(\d+)$/', $ref, $m)) {
        return $m[1] . str_pad((string)((int)$m[2] + $n), strlen($m[2]), '0', STR_PAD_LEFT);
    }
    return $ref . '-' . $n;
}

function mpMinusDays($ymd, $days) { return date('Y-m-d', strtotime($ymd . " -{$days} days")); }

// Analisis data proyek + tagihan + pembayaran klien -> rencana tagihan (belum menyimpan apa pun)
function mpBuildInvoicePlan($db, $projectId, $useAi = true) {
    $proj = mpFetchOne($db, "SELECT id, name, client_id, contract_value, dp_min_pct, ppn_rate, ppn_inclusive FROM projects WHERE id = :id", ['id' => $projectId], 'plan_proj');
    if (!$proj) throw new Exception('Proyek tidak ditemukan.');
    $cv = round((float)$proj['contract_value'], 2);
    if ($cv <= 0) throw new Exception('Nilai Kontrak (Plafon) proyek belum diisi, tagihan tidak dapat dihitung.');

    // [PPN] basis DPP: nilai kontrak sudah termasuk PPN -> DPP = kontrak / (1 + tarif)
    $ppnRate = round((float)$proj['ppn_rate'], 2);
    $base    = (mpBool($proj['ppn_inclusive']) && $ppnRate > 0) ? round($cv / (1 + $ppnRate / 100), 2) : $cv;

    $tasks = mpFetchAll($db, "SELECT id, task_name, start_date, end_date FROM public.project_tasks WHERE project_id = :p ORDER BY start_date ASC, id ASC", ['p' => $projectId], 'plan_tasks');
    if (!count($tasks)) throw new Exception('Breakdown task proyek belum ada. Jalankan ANALISIS & BAGI TASK (AI) terlebih dahulu.');

    $existing = mpFetchAll($db,
        "SELECT id, inv_no, termin_name, invoice_date, due_date, amount, CASE WHEN dpp_amount > 0 THEN dpp_amount ELSE amount END AS dpp,
                paid_amount, task_id, COALESCE(auto_generated, false) AS auto_generated
           FROM project_invoices WHERE project_id = :p ORDER BY invoice_date ASC, id ASC", ['p' => $projectId], 'plan_existing');
    $k = count($existing);
    $invoiced = 0.0; $invoicedDpp = 0.0; $paid = 0.0;
    foreach ($existing as $e) { $invoiced += (float)$e['amount']; $invoicedDpp += (float)$e['dpp']; $paid += (float)$e['paid_amount']; }

    // riwayat pembayaran klien (seluruh proyek klien yang sama) sebagai bahan analisis AI
    $cli = ['inv_count' => 0, 'invoiced' => 0, 'paid' => 0];
    if ($proj['client_id']) {
        $cli = mpFetchOne($db,
            "SELECT COUNT(*) AS inv_count, COALESCE(SUM(i.amount),0) AS invoiced, COALESCE(SUM(i.paid_amount),0) AS paid
               FROM project_invoices i JOIN projects p ON p.id = i.project_id WHERE p.client_id = :c",
            ['c' => (int)$proj['client_id']], 'plan_client') ?: $cli;
    }

    // slot yang bisa disinkronkan = tagihan otomatis yang belum ada pembayaran
    $syncable = [];
    for ($j = 0; $j < min($k, 4); $j++) {
        if (mpBool($existing[$j]['auto_generated']) && (float)$existing[$j]['paid_amount'] <= 0) $syncable[] = $j;
    }

    $rows = []; $analysis = ''; $aiUsed = false;
    $pcts = mpSlotPcts((float)$proj['dp_min_pct']);

    if ($k < 4 || count($syncable)) {
        // --- titik pemicu: default deterministik, lalu coba disempurnakan AI ---
        $anchors = mpDefaultAnchors($tasks);
        $n = count($tasks);
        if ($useAi && $n >= 4) {
            try {
                $tl = [];
                foreach ($tasks as $t) $tl[] = ['task_id' => (int)$t['id'], 'task_name' => $t['task_name'], 'start_date' => $t['start_date'], 'end_date' => $t['end_date']];
                $prompt = "Anda adalah Project Controller konstruksi. Tentukan task pemicu penerbitan tagihan termin ke klien.\n" .
                    "Data proyek: nama={$proj['name']}; nilai kontrak={$cv}; sudah ditagih={$invoiced}; sudah dibayar={$paid}; jumlah tagihan existing={$k}.\n" .
                    "Riwayat klien (semua proyek): jumlah tagihan={$cli['inv_count']}; total ditagih={$cli['invoiced']}; total dibayar={$cli['paid']}.\n" .
                    "Skema penagihan: Down Payment " . $pcts[0] . "% (dipicu task pertama), Termin 1 " . $pcts[1] . "%, Termin 2 " . $pcts[2] . "%, Pelunasan Proyek " . $pcts[3] . "%.\n" .
                    "Daftar task (urut tanggal mulai): " . json_encode($tl, JSON_UNESCAPED_UNICODE) . "\n" .
                    "Aturan: pilih 3 task BERBEDA dan berurutan (sesuai urutan tanggal mulai), bukan task pertama, sebagai pemicu Termin 1, 2, 3. " .
                    "Tagihan terbit sebelum task tersebut dimulai, jadi pilih task yang awal tahap kerjanya selaras dengan akumulasi persentase tagihan. " .
                    "Jika klien punya riwayat bayar lambat/outstanding besar, jelaskan itu di analysis.\n" .
                    "Jawab JSON MURNI: {\"termin1_task_id\":int,\"termin2_task_id\":int,\"termin3_task_id\":int,\"analysis\":\"2-3 kalimat Bahasa Indonesia\"}";
                $ai = json_decode(mpCallGemini($prompt), true);
                if (is_array($ai)) {
                    $byId = [];
                    foreach ($tasks as $i => $t) $byId[(int)$t['id']] = $i;
                    $ids = [(int)($ai['termin1_task_id'] ?? 0), (int)($ai['termin2_task_id'] ?? 0), (int)($ai['termin3_task_id'] ?? 0)];
                    $ok = true; $prev = 0; $cand = [0];
                    foreach ($ids as $tid) {
                        if (!isset($byId[$tid]) || $byId[$tid] <= $prev) { $ok = false; break; }   // wajib ada, berbeda, berurutan, bukan task pertama
                        $prev = $byId[$tid]; $cand[] = $prev;
                    }
                    if ($ok) { $anchors = $cand; $aiUsed = true; }
                    $analysis = trim((string)($ai['analysis'] ?? ''));
                }
            } catch (Throwable $e) {
                error_log('[m.proyekCRD][auto_invoice AI] ' . $e->getMessage());   // AI gagal -> tetap jalan dengan titik default
            }
        }
        if ($analysis === '') {
            $analysis = "Tagihan sudah {$k} dari 4 termin; ditagih " . number_format($invoiced, 2) . ", dibayar " . number_format($paid, 2) .
                        " dari nilai kontrak " . number_format($cv, 2) . ". Titik penagihan dihitung dari porsi durasi task.";
        }

        // --- susun baris per slot ---
        $remaining = round($base - $invoicedDpp, 2);     // [PPN] sisa dihitung atas DPP
        for ($j = 0; $j < 4; $j++) {
            $t = $tasks[min($anchors[$j], $n - 1)];
            $invDate = mpMinusDays($t['start_date'], MP_INV_LEAD_DAYS);        // [FIX] persis mulai task - 7 hari (tanpa dibatasi hari ini)
            $dueDate = mpMinusDays($t['start_date'], 1);                       // jatuh tempo = mulai task - 1 hari
            $row = ['slot' => $j, 'termin_name' => MP_INV_NAMES[$j], 'pct' => $pcts[$j], 'task_id' => (int)$t['id'],
                    'task_name' => $t['task_name'], 'task_start' => $t['start_date'],
                    'invoice_date' => $invDate, 'due_date' => $dueDate];
            if ($j < $k) {
                $e = $existing[$j];
                $row['termin_name'] = $e['termin_name']; $row['inv_no'] = $e['inv_no']; $row['invoice_id'] = (int)$e['id'];
                $row['amount'] = (float)$e['amount']; $row['dpp'] = (float)$e['dpp']; $row['pct'] = $base > 0 ? round($row['dpp'] / $base * 100, 2) : 0;
                $same = in_array($j, $syncable, true)
                    ? ((string)$e['invoice_date'] === $invDate && (string)$e['due_date'] === $dueDate && (int)$e['task_id'] === (int)$t['id']) : true;
                $row['action'] = (in_array($j, $syncable, true) && !$same) ? 'sync' : 'exists';
                if ($row['action'] === 'exists') { $row['invoice_date'] = $e['invoice_date']; $row['due_date'] = $e['due_date']; }
            } else {
                $planned = round($base * $pcts[$j] / 100, 2);
                $amt = ($j === 3) ? $remaining : min($planned, $remaining);   // slot terakhir menyerap sisa
                if ($amt <= 0) { $row['amount'] = 0; $row['dpp'] = 0; $row['ppn'] = 0; $row['action'] = 'skip'; }
                else {
                    $amt = round($amt, 2); $ppnAmt = ($ppnRate > 0) ? round($amt * $ppnRate / 100, 2) : 0.0;     // [PPN]
                    $row['dpp'] = $amt; $row['ppn'] = $ppnAmt; $row['amount'] = round($amt + $ppnAmt, 2);       // amount = total tagihan
                    $row['pct'] = round($amt / $base * 100, 2); $row['action'] = 'new'; $remaining = round($remaining - $amt, 2);
                }
            }
            $rows[] = $row;
        }
    } else {
        $analysis = 'Seluruh 4 termin sudah terbit untuk proyek ini.';
        foreach ($existing as $j => $e) {
            $rows[] = ['slot' => $j, 'termin_name' => $e['termin_name'], 'inv_no' => $e['inv_no'], 'invoice_id' => (int)$e['id'],
                       'amount' => (float)$e['amount'], 'dpp' => (float)$e['dpp'], 'pct' => round((float)$e['dpp'] / $base * 100, 2), 'invoice_date' => $e['invoice_date'],
                       'due_date' => $e['due_date'], 'task_id' => $e['task_id'], 'task_name' => '', 'task_start' => '', 'action' => 'exists'];
        }
    }

    $cnt = ['new' => 0, 'sync' => 0];
    foreach ($rows as $r) { if (isset($cnt[$r['action']])) $cnt[$r['action']]++; }
    return ['project' => $proj, 'k' => $k, 'rows' => $rows, 'analysis' => $analysis, 'ai_used' => $aiUsed,
            'new_count' => $cnt['new'], 'sync_count' => $cnt['sync'],
            'ppn_rate' => $ppnRate, 'summary' => ['contract_value' => $cv, 'dpp_base' => $base, 'invoiced' => round($invoiced, 2), 'paid' => round($paid, 2),
                          'client_invoiced' => (float)$cli['invoiced'], 'client_paid' => (float)$cli['paid']]];
}

// Simpan rencana: INSERT tagihan baru + jurnal, dan sinkron tanggal tagihan otomatis yang belum dibayar (satu transaksi)
function mpApplyInvoicePlan($db, array $plan) {
    $pid = (int)$plan['project']['id'];
    $db->beginTransaction();
    try {
        mpExec($db, "SELECT id FROM projects WHERE id = :id FOR UPDATE", ['id' => $pid], 'lock proyek');      // cegah klik ganda / proses paralel
        $cnt = (int)mpFetchOne($db, "SELECT COUNT(*) AS n FROM project_invoices WHERE project_id = :p", ['p' => $pid], 'recount')['n'];
        if ($cnt !== (int)$plan['k']) throw new Exception('Data tagihan proyek berubah saat diproses, silakan ulangi.');
        $pName = (string)$plan['project']['name'];

        $firstRef = null; $seq = 0; $made = 0; $synced = 0;
        foreach ($plan['rows'] as $r) {
            if ($r['action'] === 'new') {
                if ($firstRef === null) {
                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $firstRef = CreateRefNo('project_invoices', 'inv_no', 'INV');
                }
                $invNo  = mpRefPlus($firstRef, $seq++);
                $remark = "Tagihan otomatis {$r['termin_name']} ({$r['pct']}%): terbit " . MP_INV_LEAD_DAYS .
                          " hari sebelum task '{$r['task_name']}' dimulai ({$r['task_start']}).";
                // [PPN] kolom sama dengan project_invoiceCRD.php: amount = DPP + PPN
                $newId = (int)mpFetchOne($db,
                    "INSERT INTO project_invoices
                        (inv_no, project_id, termin_name, invoice_date, due_date, percentage, amount, paid_amount,
                         dpp_amount, ppn_rate, ppn_amount, remark, task_id, auto_generated, ai_note)
                     VALUES (:no, :p, :tn, CAST(:idt AS date), CAST(:ddt AS date), :pct, :amt, 0,
                             :dpp, :pr, :ppn, :rm, :tid, 't', :ai)
                     RETURNING id",
                    ['no' => $invNo, 'p' => $pid, 'tn' => $r['termin_name'], 'idt' => $r['invoice_date'], 'ddt' => $r['due_date'],
                     'pct' => $r['pct'], 'amt' => $r['amount'], 'dpp' => $r['dpp'], 'pr' => $plan['ppn_rate'], 'ppn' => $r['ppn'],
                     'rm' => $remark, 'tid' => $r['task_id'], 'ai' => $plan['analysis']],
                    'insert invoice otomatis')['id'];
                mpPostInvoiceJournal($db, $newId, $invNo, $r['invoice_date'], (float)$r['dpp'], (float)$r['ppn'], $pName);   // [JURNAL]
                $made++;
            } elseif ($r['action'] === 'sync') {
                $cur = mpFetchOne($db,
                    "SELECT inv_no, paid_amount, dpp_amount, ppn_amount, amount FROM project_invoices
                      WHERE id = :id AND auto_generated = true FOR UPDATE", ['id' => $r['invoice_id']], 'lock invoice sync');
                if (!$cur || (float)$cur['paid_amount'] > 0) continue;
                $pay = mpFetchOne($db, "SELECT COUNT(*) AS n FROM public.project_payment_items WHERE invoice_id = :id", ['id' => $r['invoice_id']], 'cek bayar');
                if ((int)$pay['n'] > 0) continue;                                   // sudah ada alokasi pembayaran -> jangan diubah
                mpExec($db,
                    "UPDATE project_invoices SET invoice_date = CAST(:idt AS date), due_date = CAST(:ddt AS date), task_id = :tid, ai_note = :ai
                      WHERE id = :id",
                    ['idt' => $r['invoice_date'], 'ddt' => $r['due_date'], 'tid' => $r['task_id'], 'ai' => $plan['analysis'], 'id' => $r['invoice_id']],
                    'sync invoice otomatis');
                // [JURNAL] tanggal berubah -> reverse jurnal lama, posting ulang di tanggal baru (sama dengan edit invoice manual)
                if (mpHasJournal($db, (int)$r['invoice_id'])) ReverseJournalByReference($db, MP_JOURNAL_REF, (int)$r['invoice_id']);
                $dpp = (float)$cur['dpp_amount'] > 0 ? (float)$cur['dpp_amount'] : (float)$cur['amount'];
                mpPostInvoiceJournal($db, (int)$r['invoice_id'], $cur['inv_no'], $r['invoice_date'], $dpp, (float)$cur['ppn_amount'], $pName);
                $synced++;
            }
        }
        $db->commit();
        return ['made' => $made, 'synced' => $synced];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/* ============================================================
 * [AI-BOQ] Helper analisis proyek -> BoQ (task + material + EXPENSE + harga min/max) -> master material -> RAB
 * ------------------------------------------------------------
 *  - AI memecah proyek menjadi paket pekerjaan (task). Tiap task berisi:
 *      materials : bahan (nama, spesifikasi, satuan, qty, harga MIN dan MAX)
 *      expenses  : biaya non-material (kategori RAB: Upah, Peralatan, Transport, Konsumsi, Lain-lain; master = expense_master) - wajib bila task tanpa material
 *  - Harga MIN dan MAX milik MASTER (materials.estimated_price_min/estimated_price; expense_master.default_price_min/default_price).
 *    Harga satuan RAB = MAX (budget_items.unit_price_plan); di modul RAB user boleh mengubahnya selama masih di antara min-max master.
 *  - Saat SIMPAN (satu transaksi): material baru dibuat di master -> semua material & expense masuk RAB (budget_items)
 *    -> task + alokasi (project_tasks, task_materials) disimpan. Total RAB tidak boleh melebihi nilai kontrak (plafon).
 *  - Butuh migrasi: sql/2026-11-06.budget_items_price_range.sql
 * ============================================================ */
const MP_EPS = 0.00005;
const MP_EXPENSE_CATEGORIES = ['Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'];   // [AI-BOQ] = RAB_CATEGORIES (rabCRD.php) selain Material
function mpExpCategory($c) { $c = trim((string)$c); return in_array($c, MP_EXPENSE_CATEGORIES, true) ? $c : 'Lain-lain'; }

function mpNorm($s) {                                   // kunci pencocokan nama
    $s = strtolower(trim((string)$s));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}
function mpNum($v) {                                    // angka dari AI / klien (toleran terhadap string)
    if (is_numeric($v)) return (float)$v;
    return (float)preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$v));
}
function mpIdr($v) { return number_format((float)$v, 0, ',', '.'); }

// Normalisasi pasangan harga: tidak negatif, min <= max, salah satu kosong -> samakan
function mpNormPrice($pMin, $pMax) {
    $pMin = max(0, round((float)$pMin, 2)); $pMax = max(0, round((float)$pMax, 2));
    if ($pMax <= 0 && $pMin > 0) $pMax = $pMin;
    if ($pMin > $pMax) { $t = $pMin; $pMin = $pMax; $pMax = $t; }
    if ($pMin <= 0) $pMin = $pMax;
    return [$pMin, $pMax];
}

// Kolom harga min master (materials.estimated_price_min, expense_master.default_price_min) sudah dimigrasi? Bila belum, halaman tetap terbaca; hanya SIMPAN yang ditolak.
function mpHasPriceRange($db) {
    static $has = null;
    if ($has === null) {
        $r = mpFetchOne($db, "SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ANY (current_schemas(false)) AND table_name = 'materials' AND column_name = 'estimated_price_min')
                                   + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ANY (current_schemas(false)) AND table_name = 'expense_master' AND column_name = 'default_price_min') AS n", [], 'has_price_range');
        $has = ((int)$r['n'] === 2);
    }
    return $has;
}

// Peta RAB proyek: material (category Material) & expense (MP_EXPENSE_CATEGORY) + master material (untuk cocokkan nama)
// [AI-BOQ] Task lama yang AMAN diganti saat analisis ulang: status 'Belum Mulai' dan tidak punya tagihan yang sudah dibayar
function mpTaskReplaceInfo($db, $projectId) {
    $rows = mpFetchAll($db, "SELECT t.id, t.status, COALESCE((SELECT SUM(i.paid_amount) FROM project_invoices i WHERE i.task_id = t.id), 0) AS paid
                               FROM public.project_tasks t WHERE t.project_id = :p", ['p' => $projectId], 'task_replace_info');
    $ids = [];
    foreach ($rows as $r) { if ($r['status'] === 'Belum Mulai' && (float)$r['paid'] <= 0) $ids[] = (int)$r['id']; }
    return ['total' => count($rows), 'replaceable_ids' => $ids];
}
function mpMode($v) { return ((string)$v === 'append') ? 'append' : 'replace'; }

// $ignoreTaskIds: task yang akan diganti -> alokasinya tidak dihitung (agar analisis ulang tidak menggandakan qty RAB)
function mpLoadMaterialContext($db, $projectId, array $ignoreTaskIds = []) {
    $ign = $ignoreTaskIds ? ' AND tm.task_id NOT IN (' . implode(',', array_map('intval', $ignoreTaskIds)) . ')' : '';
    $has = mpHasPriceRange($db);
    $mnSel = $has ? "CASE WHEN COALESCE(bi.category,'Material') = 'Material' THEN m.estimated_price_min ELSE em.default_price_min END" : 'NULL';
    $mxSel = "CASE WHEN COALESCE(bi.category,'Material') = 'Material' THEN m.estimated_price ELSE em.default_price END";
    $rows = mpFetchAll($db, "
        SELECT bi.id AS budget_item_id, bi.material_id, bi.expense_id, COALESCE(bi.category,'Material') AS category,
               COALESCE(m.material_name, bi.item_name) AS material_name,
               COALESCE(NULLIF(bi.unit,''), m.unit, '') AS unit,
               COALESCE(bi.qty_plan,0) AS qty_plan,
               COALESCE(bi.unit_price_plan,0) AS unit_price_plan,
               COALESCE({$mnSel}, 0) AS master_min, COALESCE({$mxSel}, 0) AS master_max,       -- rentang harga dari MASTER (mata uang dasar)
               COALESCE((SELECT SUM(tm.qty_budget) FROM public.task_materials tm WHERE tm.budget_item_id = bi.id{$ign}),0) AS qty_allocated
          FROM public.budget_items bi
          LEFT JOIN public.materials m ON m.id = bi.material_id
          LEFT JOIN expense_master em ON em.id = bi.expense_id
         WHERE bi.project_id = :pid
         ORDER BY bi.id", ['pid' => $projectId], 'ctx_rab');
    $master = mpFetchAll($db, "SELECT id, material_code, material_name, COALESCE(unit,'') AS unit, COALESCE(estimated_price,0) AS estimated_price,
                                      " . ($has ? 'COALESCE(estimated_price_min,0)' : '0') . " AS estimated_price_min
                                 FROM public.materials ORDER BY id", [], 'ctx_master');
    $expMaster = mpFetchAll($db, "SELECT id, expense_code, expense_name, COALESCE(category,'Lain-lain') AS category, COALESCE(unit,'') AS unit,
                                         COALESCE(default_price,0) AS default_price, " . ($has ? 'COALESCE(default_price_min,0)' : '0') . " AS default_price_min
                                    FROM expense_master WHERE active = true ORDER BY id", [], 'ctx_exp_master');
    $ctx = ['rab' => [], 'exp' => [], 'rabById' => [], 'rabByName' => [], 'rabByMid' => [],
            'expById' => [], 'expByName' => [], 'expByEid' => [], 'expMasterByName' => [], 'masterByName' => [], 'total_plan' => 0.0];
    foreach ($rows as $r) {
        $r['budget_item_id'] = (int)$r['budget_item_id'];
        $r['material_id'] = $r['material_id'] !== null ? (int)$r['material_id'] : 0;
        $r['expense_id'] = $r['expense_id'] !== null ? (int)$r['expense_id'] : 0;
        foreach (['qty_plan','qty_allocated','unit_price_plan','master_min','master_max'] as $f) $r[$f] = (float)$r[$f];
        $ctx['total_plan'] += $r['qty_plan'] * $r['unit_price_plan'];                 // total RAB proyek saat ini (mata uang anggaran)
        $k = mpNorm($r['material_name']);
        if ($r['category'] !== 'Material') {                                   // semua kategori non-material = biaya (Upah, Peralatan, ...)
            $ctx['exp'][] = $r; $ctx['expById'][$r['budget_item_id']] = $r;
            if ($k !== '' && !isset($ctx['expByName'][$k])) $ctx['expByName'][$k] = $r;
            if ($r['expense_id'] > 0 && !isset($ctx['expByEid'][$r['expense_id']])) $ctx['expByEid'][$r['expense_id']] = $r;
        } elseif ($r['category'] === 'Material' && $r['material_id'] > 0) {
            $ctx['rab'][] = $r; $ctx['rabById'][$r['budget_item_id']] = $r;
            if ($k !== '' && !isset($ctx['rabByName'][$k])) $ctx['rabByName'][$k] = $r;
            if (!isset($ctx['rabByMid'][$r['material_id']])) $ctx['rabByMid'][$r['material_id']] = $r;
        }
    }
    foreach ($expMaster as $e) {
        $k = mpNorm($e['expense_name']);
        if ($k !== '' && !isset($ctx['expMasterByName'][$k])) $ctx['expMasterByName'][$k] = $e;
    }
    foreach ($master as $m) {
        $k = mpNorm($m['material_name']);
        if ($k !== '' && !isset($ctx['masterByName'][$k])) $ctx['masterByName'][$k] = $m;
    }
    return $ctx;
}

// type: rab (sudah di RAB proyek) | master (material ada di master, belum di RAB) | new (belum ada)
function mpResolveLine($ctx, $bid, $name, $kind = 'materials') {
    $k = mpNorm($name);
    if ($kind === 'expenses') {
        if ($bid > 0 && isset($ctx['expById'][$bid])) return ['type' => 'rab', 'rab' => $ctx['expById'][$bid], 'master' => null];
        if ($k !== '' && isset($ctx['expByName'][$k])) return ['type' => 'rab', 'rab' => $ctx['expByName'][$k], 'master' => null];
        if ($k !== '' && isset($ctx['expMasterByName'][$k])) {                       // ada di expense_master
            $e = $ctx['expMasterByName'][$k];
            if (isset($ctx['expByEid'][(int)$e['id']])) return ['type' => 'rab', 'rab' => $ctx['expByEid'][(int)$e['id']], 'master' => null];
            return ['type' => 'master', 'rab' => null, 'master' => $e];
        }
        return ['type' => 'new', 'rab' => null, 'master' => null];
    }
    if ($bid > 0 && isset($ctx['rabById'][$bid])) return ['type' => 'rab', 'rab' => $ctx['rabById'][$bid], 'master' => null];
    if ($k !== '' && isset($ctx['rabByName'][$k])) return ['type' => 'rab', 'rab' => $ctx['rabByName'][$k], 'master' => null];
    if ($k !== '' && isset($ctx['masterByName'][$k])) {
        $m = $ctx['masterByName'][$k];
        if (isset($ctx['rabByMid'][(int)$m['id']])) return ['type' => 'rab', 'rab' => $ctx['rabByMid'][(int)$m['id']], 'master' => null];
        return ['type' => 'master', 'rab' => null, 'master' => $m];
    }
    return ['type' => 'new', 'rab' => null, 'master' => null];
}
function mpLineKey($res, $name, $kind = 'materials') {
    if ($res['type'] === 'rab') return 'b' . $res['rab']['budget_item_id'];
    return ($kind === 'expenses' ? 'x' : 'n') . mpNorm($name);
}

// Kode biaya otomatis EXP-00001 dst (expense_master)
function mpNextExpenseCode($db) {
    $r = mpFetchOne($db, "SELECT COALESCE(MAX(CAST(substring(expense_code from '^EXP-([0-9]+)$') AS integer)),0) + 1 AS n FROM expense_master", [], 'next_exp_code');
    return 'EXP-' . str_pad((string)$r['n'], 5, '0', STR_PAD_LEFT);
}

// Kode material otomatis MAT-00001 dst (dipanggil di dalam transaksi yang sudah mengunci)
function mpNextMaterialCode($db) {
    $r = mpFetchOne($db, "SELECT COALESCE(MAX(CAST(substring(material_code from '^MAT-([0-9]+)$') AS integer)),0) + 1 AS n FROM public.materials", [], 'next_mat_code');
    return 'MAT-' . str_pad((string)$r['n'], 5, '0', STR_PAD_LEFT);
}

/* Anotasi semua baris (materials + expenses) per task: cocokkan ke RAB/master, set status, harga min/max, total.
 * $tasks diubah in-place. Mengembalikan total (mata uang Rupiah):
 *   max/min = total BoQ, mat/exp = pecahan max, add = tambahan nilai RAB bila disimpan, newNames = material baru unik. */
function mpAnnotateTasks(array &$tasks, $ctx, $rate, $dropEmptyQty = false) {
    $running = [];
    $tot = ['max' => 0.0, 'min' => 0.0, 'mat' => 0.0, 'exp' => 0.0, 'add' => 0.0, 'newNames' => []];
    foreach ($tasks as &$t) {
        foreach (['materials', 'expenses'] as $kind) {
            $in = (isset($t[$kind]) && is_array($t[$kind])) ? $t[$kind] : [];
            $out = [];
            foreach ($in as $m) {
                if (!is_array($m)) continue;
                $name = trim((string)($m['material_name'] ?? ($m['expense_name'] ?? ($m['item_name'] ?? ($m['name'] ?? '')))));
                $bidIn = (int)($m['budget_item_id'] ?? 0);
                $qty = round(mpNum($m['qty_estimate'] ?? 0), 3);
                if ($name === '' && $bidIn <= 0) continue;
                if ($dropEmptyQty && $qty <= 0) continue;
                list($pMin, $pMax) = mpNormPrice(
                    mpNum($m['price_min'] ?? ($m['unit_price_min'] ?? 0)),
                    mpNum($m['price_max'] ?? ($m['unit_price_max'] ?? ($m['unit_price'] ?? ($m['unit_price_estimate'] ?? 0))))
                );
                list($aiMin, $aiMax) = mpNormPrice(                                          // harga asli AI (dipakai mengisi rentang master yang masih kosong)
                    mpNum($m['ai_min'] ?? ($m['price_min'] ?? ($m['unit_price_min'] ?? 0))),
                    mpNum($m['ai_max'] ?? ($m['price_max'] ?? ($m['unit_price_max'] ?? ($m['unit_price'] ?? ($m['unit_price_estimate'] ?? 0)))))
                );
                $unit = trim((string)($m['unit'] ?? ''));
                $res = mpResolveLine($ctx, $bidIn, $name, $kind);
                $key = mpLineKey($res, $name, $kind);
                $before = $running[$key] ?? 0.0;
                $running[$key] = $before + $qty;

                $status = $res['type']; $bid = null; $mid = null; $cat = '';
                if ($res['type'] === 'rab') {
                    $r = $res['rab'];
                    $bid = (int)$r['budget_item_id']; $mid = $r['material_id'] > 0 ? (int)$r['material_id'] : null;
                    $name = $r['material_name']; if ($r['unit'] !== '') $unit = $r['unit'];
                    if ($kind === 'expenses') $cat = mpExpCategory($r['category']);
                    $pMaxR = $r['unit_price_plan'] * $rate;                                  // harga satuan RAB (Rupiah) = harga yang dipakai
                    $mnR = (float)$r['master_min'];                                           // batas bawah dari master
                    list($pMin, $pMax) = mpNormPrice(($mnR > 0 && $mnR < $pMaxR) ? $mnR : $pMaxR, $pMaxR);
                    $over = $r['qty_allocated'] + $running[$key];
                    $status = ($over <= $r['qty_plan'] + MP_EPS) ? 'rab' : 'rab_topup';
                    $shortBefore = max(0, $r['qty_allocated'] + $before - $r['qty_plan']);
                    $tot['add'] += (max(0, $over - $r['qty_plan']) - $shortBefore) * $pMax;                 // hanya selisih yang menambah RAB
                } else {
                    if ($res['type'] === 'master' && $kind === 'materials') {
                        $mid = (int)$res['master']['id']; $name = $res['master']['material_name'];
                        if ($unit === '') $unit = $res['master']['unit'];
                        if ((float)$res['master']['estimated_price'] > 0) list($pMin, $pMax) = mpNormPrice((float)$res['master']['estimated_price_min'] ?: (float)$res['master']['estimated_price'], (float)$res['master']['estimated_price']);   // rentang = milik master
                    } elseif ($res['type'] === 'master') {                                   // biaya yang sudah ada di expense_master
                        $name = $res['master']['expense_name']; $cat = mpExpCategory($res['master']['category']);
                        if ($unit === '') $unit = $res['master']['unit'];
                        if ((float)$res['master']['default_price'] > 0) list($pMin, $pMax) = mpNormPrice((float)$res['master']['default_price_min'] ?: (float)$res['master']['default_price'], (float)$res['master']['default_price']);   // rentang = milik master
                    } elseif ($kind === 'materials') {
                        $tot['newNames'][$key] = true;
                    } else {
                        $cat = mpExpCategory($m['category'] ?? '');                          // biaya baru -> dibuat di expense_master
                    }
                    $tot['add'] += $qty * $pMax;
                }
                if ($unit === '') $unit = ($kind === 'expenses') ? 'ls' : 'unit';
                $tot['max'] += $qty * $pMax; $tot['min'] += $qty * $pMin;
                $tot[$kind === 'expenses' ? 'exp' : 'mat'] += $qty * $pMax;
                $out[] = [
                    'budget_item_id' => $bid, 'material_id' => $mid, 'material_name' => $name,
                    'spec' => trim((string)($m['spec'] ?? '')), 'unit' => $unit, 'qty_estimate' => $qty,
                    'price_min' => $pMin, 'price_max' => $pMax, 'unit_price' => $pMax, 'status' => $status,
                    'reason' => trim((string)($m['reason'] ?? '')), 'category' => $cat, 'ai_min' => $aiMin, 'ai_max' => $aiMax
                ];
            }
            $t[$kind] = $out;
        }
    }
    unset($t);
    return $tot;
}

// Penilaian plafon (Rupiah) - MENIRU aturan modul RAB (rabCRD.php plafonCheck):
//   total RAB > nilai kontrak            -> DITOLAK (blokir)
//   proyek 'Belum Mulai' & total > budget -> RAB menolak simpan ulang (tandai MERAH, simpan di sini tetap boleh)
//   proyek berjalan & total > budget      -> memakai cadangan (tandai KUNING; item ditandai addendum)
function mpPlafonFlags($proj, $rate, $existingIdr, $addIdr) {
    $after = $existingIdr + $addIdr;
    $budgetIdr = (float)($proj['budget'] ?? 0) * $rate;
    $contractIdr = (float)($proj['contract_value'] ?? 0) * $rate;
    $planning = (($proj['status'] ?? '') === 'Belum Mulai');
    $w = []; $sev = 0; $overContract = false; $overBudget = false; $usesReserve = false;
    if ($contractIdr <= 0) {
        $w[] = ['level' => 'danger', 'text' => 'Nilai kontrak (plafon) proyek belum diisi. Modul RAB tidak bisa menyimpan RAB sebelum nilai kontrak diisi.']; $sev = 2;
    } elseif ($after > $contractIdr + 0.01) {
        $overContract = true; $sev = 2;
        $w[] = ['level' => 'danger', 'text' => 'Hasil analisis LEBIH BESAR dari nilai kontrak: total RAB Rp ' . mpIdr($after) . ' vs kontrak Rp ' . mpIdr($contractIdr) .
                ' (lebih Rp ' . mpIdr($after - $contractIdr) . '). Tidak dapat disimpan; kurangi qty / harga / item.'];
    }
    if ($budgetIdr > 0 && $after > $budgetIdr + 0.01) {
        $overBudget = true;
        $diff = $after - $budgetIdr; $pct = round($diff / $budgetIdr * 100, 1);
        if ($planning) {
            $sev = 2;
            $w[] = ['level' => 'danger', 'text' => 'Hasil analisis LEBIH BESAR dari budget: total RAB Rp ' . mpIdr($after) . ' vs budget Rp ' . mpIdr($budgetIdr) .
                    ' (lebih Rp ' . mpIdr($diff) . ', ' . $pct . '%). Proyek masih Belum Mulai: modul RAB menolak total di atas budget, sehingga RAB tidak bisa disimpan ulang sampai total <= budget.'];
        } else {
            $usesReserve = true; $sev = max($sev, 1);
            $cad = $contractIdr > 0 ? max($contractIdr - $budgetIdr, 0) : 0;
            $w[] = ['level' => 'warning', 'text' => 'Hasil analisis LEBIH BESAR dari budget: total RAB Rp ' . mpIdr($after) . ' vs budget Rp ' . mpIdr($budgetIdr) .
                    ' (lebih Rp ' . mpIdr($diff) . ', ' . $pct . '%). Memakai cadangan Rp ' . mpIdr($diff) . ' dari Rp ' . mpIdr($cad) . '; item yang ditambah ditandai addendum.'];
        }
    }
    return [
        'rab_existing_idr' => round($existingIdr, 2), 'rab_after_idr' => round($after, 2),
        'budget_idr' => round($budgetIdr, 2), 'contract_idr' => round($contractIdr, 2),
        'over_contract' => $overContract, 'over_budget' => $overBudget, 'uses_reserve' => $usesReserve, 'planning' => $planning,
        'severity' => ($sev >= 2 ? 'danger' : ($sev === 1 ? 'warning' : 'ok')), 'warnings' => $w
    ];
}

// Ringkasan angka + peringatan untuk UI review (semua dalam Rupiah)
function mpPlanMeta($proj, $ctx, $rate, $tot, array $tasks) {
    $m = mpPlafonFlags($proj, $rate, $ctx['total_plan'] * $rate, $tot['add']);
    foreach ($tasks as $t) {
        if (empty($t['materials']) && empty($t['expenses'])) {
            $m['warnings'][] = ['level' => 'warning', 'text' => "Task '" . ($t['task_name'] ?? '') . "' belum punya material maupun biaya."];
            if ($m['severity'] === 'ok') $m['severity'] = 'warning';
        }
    }
    return $m;
}


$access = intval($_SESSION['FormAccess'] ?? 0);

try {
    switch ($task) {

        case 'read':
            $sql = "SELECT p.id, p.name, p.client_id, c.name AS client_name,
                           p.pic_id, e.employee_name AS pic_name,
                           p.location_id, loc.name AS location_name, p.location,
                           p.start_date, p.end_date, p.budget, p.contract_value, p.spent, p.progress,
                           p.status, p.notes, p.project_scope
                    FROM projects p
                    LEFT JOIN clients c ON c.id = p.client_id
                    LEFT JOIN m_employee e ON e.id = p.pic_id
                    LEFT JOIN m_location loc ON loc.id = p.location_id
                    ORDER BY p.id DESC";
            $data = mpFetchAll($db, $sql, [], 'read');
            mpJson(['success' => true, 'totalCount' => count($data), 'data' => $data]);
            break;

        case 'read_one':
            $id  = (int)($_REQUEST['id'] ?? 0);
            $row = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $id], 'read_one');
            if (!$row) throw new Exception('Data proyek tidak ditemukan.');
            mpJson(['success' => true, 'data' => $row]);
            break;

        case 'read_clients':
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM clients ORDER BY name", [], 'read_clients')]);
            break;

        case 'read_pics':
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, employee_name AS name
                   FROM m_employee
                  WHERE employee_name IS NOT NULL
                    AND (active IS NOT FALSE OR id IN (SELECT pic_id FROM projects WHERE pic_id IS NOT NULL))
                  ORDER BY employee_name", [], 'read_pics')]);
            break;

        case 'read_locations':
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM m_location ORDER BY name", [], 'read_locations')]);
            break;

        case 'read_tasks':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            // [AI-BOQ] material & biaya (expense) dipisah; items = rincian JSON utk panel detail (harga min/max); total_* memakai harga MAX
            $hasRange = mpHasPriceRange($db);
            $pj0 = mpFetchOne($db, "SELECT COALESCE(NULLIF(budget_exch_rate, 0), 1) AS r FROM projects WHERE id = :id", ['id' => $projectId], 'read_tasks_rate');
            $rateLit = sprintf('%.6F', ($pj0 && (float)$pj0['r'] > 0) ? (float)$pj0['r'] : 1.0);
            // harga satuan = unit_price_plan (= MAX); batas bawah dari master (mata uang dasar -> mata uang anggaran)
            $mnRaw = $hasRange ? "CASE WHEN COALESCE(bi.category,'Material') = 'Material' THEN m.estimated_price_min ELSE em.default_price_min END" : 'NULL';
            $pMaxSql = 'COALESCE(bi.unit_price_plan, 0)';
            $pMinSql = "LEAST(COALESCE(NULLIF({$mnRaw}, 0) / {$rateLit}, COALESCE(bi.unit_price_plan, 0)), COALESCE(bi.unit_price_plan, 0))";
            $joins = "JOIN public.budget_items bi ON bi.id = tm.budget_item_id
                             LEFT JOIN public.materials m ON m.id = bi.material_id
                             LEFT JOIN expense_master em ON em.id = bi.expense_id";
            $tasks = mpFetchAll($db, "
                SELECT t.*,
                       COALESCE((
                           SELECT string_agg(bi.item_name || ': ' || tm.qty_budget::text || ' ' || COALESCE(bi.unit,''), ', ' ORDER BY bi.item_name)
                             FROM public.task_materials tm
                             {$joins}
                            WHERE tm.task_id = t.id AND COALESCE(bi.category,'Material') = 'Material'
                       ), '') AS materials,
                       COALESCE((
                           SELECT string_agg(bi.item_name || ': ' || tm.qty_budget::text || ' ' || COALESCE(bi.unit,''), ', ' ORDER BY bi.item_name)
                             FROM public.task_materials tm
                             {$joins}
                            WHERE tm.task_id = t.id AND COALESCE(bi.category,'Material') <> 'Material'
                       ), '') AS expenses,
                       COALESCE((
                           SELECT json_agg(json_build_object(
                                      'kind', CASE WHEN COALESCE(bi.category,'Material') = 'Material' THEN 'M' ELSE 'E' END,
                                      'name', bi.item_name, 'unit', COALESCE(bi.unit,''), 'qty', tm.qty_budget,
                                      'pmin', {$pMinSql}, 'pmax', {$pMaxSql})
                                  ORDER BY (COALESCE(bi.category,'Material') = 'Material') DESC, bi.item_name)
                             FROM public.task_materials tm
                             {$joins}
                            WHERE tm.task_id = t.id
                       ), '[]') AS items_json,
                       COALESCE((SELECT SUM(tm.qty_budget * {$pMaxSql}) FROM public.task_materials tm
                                   {$joins} WHERE tm.task_id = t.id), 0) AS total_max,
                       COALESCE((SELECT SUM(tm.qty_budget * {$pMinSql}) FROM public.task_materials tm
                                   {$joins} WHERE tm.task_id = t.id), 0) AS total_min
                  FROM public.project_tasks t
                 WHERE t.project_id = :pid
                 ORDER BY t.start_date ASC, t.id ASC
            ", ['pid' => $projectId], 'read_tasks');
            foreach ($tasks as &$tk) {
                $tk['items'] = json_decode($tk['items_json'], true) ?: [];
                unset($tk['items_json']);
                $tk['total_max'] = (float)$tk['total_max']; $tk['total_min'] = (float)$tk['total_min'];
            }
            unset($tk);
            // [AI-BOQ] penanda plafon: total RAB proyek vs budget / nilai kontrak (aturan sama dengan modul RAB)
            $summary = null;
            $pj = mpFetchOne($db, "SELECT status, budget, contract_value, budget_exch_rate FROM projects WHERE id = :id", ['id' => $projectId], 'read_tasks_proj');
            if ($pj) {
                $rt = mpFetchOne($db, "SELECT COALESCE(SUM(qty_plan * unit_price_plan),0) AS s FROM public.budget_items WHERE project_id = :p", ['p' => $projectId], 'read_tasks_rab');
                $rt0 = (float)($pj['budget_exch_rate'] ?? 1); if ($rt0 <= 0) $rt0 = 1;
                $summary = mpPlafonFlags($pj, $rt0, (float)$rt['s'] * $rt0, 0.0);
                $tm = 0.0; foreach ($tasks as $tk2) $tm += $tk2['total_max'];
                $summary['task_total_max_idr'] = round($tm * $rt0, 2);
            }
            mpJson(['success' => true, 'data' => $tasks, 'summary' => $summary]);
            break;

        // [AUTO-INV][NEW] daftar tagihan proyek untuk panel detail
        case 'read_invoices':
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            $inv = mpFetchAll($db,
                "SELECT i.id, i.inv_no, i.termin_name, i.invoice_date, i.due_date, i.percentage, i.amount, i.paid_amount, i.remark,
                        COALESCE(i.auto_generated, false) AS auto_generated, t.task_name
                   FROM project_invoices i LEFT JOIN public.project_tasks t ON t.id = i.task_id
                  WHERE i.project_id = :pid ORDER BY i.invoice_date ASC, i.id ASC", ['pid' => $projectId], 'read_invoices');
            foreach ($inv as &$iv) { $iv['auto_generated'] = mpBool($iv['auto_generated']); }
            unset($iv);
            mpJson(['success' => true, 'data' => $inv]);
            break;

        // [AUTO-INV][NEW] mode=preview: hanya analisis & rencana ; mode=issue: simpan tagihan
        case 'auto_invoice':
            if ($access < 3) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menerbitkan tagihan.']);
            }
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) throw new Exception('ID Proyek tidak valid.');
            $mode = ($_REQUEST['mode'] ?? 'preview') === 'issue' ? 'issue' : 'preview';

            $plan = mpBuildInvoicePlan($db, $projectId, true);
            if ($mode === 'preview') {
                mpJson(['success' => true, 'data' => $plan['rows'], 'analysis' => $plan['analysis'], 'ai_used' => $plan['ai_used'],
                        'summary' => $plan['summary'], 'new_count' => $plan['new_count'], 'sync_count' => $plan['sync_count'],
                        'message' => 'Rencana tagihan siap.']);
            }
            if ($plan['new_count'] + $plan['sync_count'] === 0) {
                mpJson(['success' => true, 'data' => $plan['rows'], 'made' => 0, 'synced' => 0, 'message' => 'Tidak ada tagihan baru yang perlu diterbitkan.']);
            }
            $res = mpApplyInvoicePlan($db, $plan);
            mpJson(['success' => true, 'data' => $plan['rows'], 'analysis' => $plan['analysis'], 'made' => $res['made'], 'synced' => $res['synced'],
                    'message' => "{$res['made']} tagihan diterbitkan, {$res['synced']} tagihan disesuaikan tanggalnya."]);
            break;

        // [AI-BOQ][REWORK] Analisis & pecah task + material + expense. Hanya DRAFT: belum ada yang ditulis ke database.
        case 'ai_generate_tasks':
            if ($access < 2) mpJson(['success' => false, 'message' => 'Akses ditolak.']);
            set_time_limit(200);                                           // [AI-BOQ] output BoQ panjang -> panggilan AI lebih lama
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) throw new Exception('ID Proyek tidak valid.');

            $proj = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $projectId], 'ai_fetch_proj');
            if (!$proj) throw new Exception('Proyek tidak ditemukan.');
            $rate = (float)($proj['budget_exch_rate'] ?? 1);               // kurs mata uang RAB ke Rupiah
            if ($rate <= 0) $rate = 1;

            // RAB yang sudah ada: dikirim ke AI agar nama yang sama dipakai ulang; sisa kuota dipakai untuk "tambah dari RAB"
            $tri = mpTaskReplaceInfo($db, $projectId);                       // [AI-BOQ] task lama yang akan diganti tidak dihitung sebagai alokasi
            $ctx = mpLoadMaterialContext($db, $projectId, $tri['replaceable_ids']);
            $existingForAi = []; $candMat = []; $candExp = [];
            foreach (['materials' => $ctx['rab'], 'expenses' => $ctx['exp']] as $kind => $list) {
                foreach ($list as $r) {
                    $existingForAi[] = ['jenis' => ($kind === 'expenses' ? 'expense' : 'material'), 'nama' => $r['material_name'], 'unit' => $r['unit'], 'qty_plan' => $r['qty_plan']];
                    $remaining = max(0, $r['qty_plan'] - $r['qty_allocated']);
                    if ($remaining <= MP_EPS) continue;
                    $cMaxR = $r['unit_price_plan'] * $rate; $cMn = (float)$r['master_min'];
                    list($cMin, $cMax) = mpNormPrice(($cMn > 0 && $cMn < $cMaxR) ? $cMn : $cMaxR, $cMaxR);   // harga satuan RAB = max; min dari master
                    $cand = [
                        'budget_item_id' => $r['budget_item_id'], 'material_id' => $r['material_id'],
                        'material_name' => $r['material_name'], 'unit' => $r['unit'], 'qty_plan' => $r['qty_plan'],
                        'qty_already_allocated' => $r['qty_allocated'], 'qty_remaining' => $remaining,
                        'price_min' => $cMin, 'price_max' => $cMax, 'unit_price' => $cMax,
                        'category' => ($kind === 'expenses' ? mpExpCategory($r['category']) : '')
                    ];
                    if ($kind === 'expenses') $candExp[] = $cand; else $candMat[] = $cand;
                }
            }

            $pName  = $proj['name'];
            $pScope = $proj['project_scope'] ?: 'Tidak ada spesifikasi khusus';
            $pStart = $proj['start_date'] ?: date('Y-m-d');
            $pEnd   = $proj['end_date'] ?: date('Y-m-d', strtotime('+30 days'));
            $pBudget = $proj['budget'];
            $pYear  = date('Y');
            $existingJson = json_encode($existingForAi, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $prompt = <<<PROMPT
Anda adalah Construction Project Planner sekaligus Quantity Surveyor (estimator) di Indonesia.
Susun WBS proyek dan Bill of Quantities (BoQ) kasar: setiap Task adalah satu paket pekerjaan, berisi daftar MATERIAL
(lengkap dengan spesifikasi standar, satuan, estimasi volume) dan daftar EXPENSE (biaya non-material), keduanya dengan
kisaran harga pasar {$pYear} (Rupiah): harga MINIMUM dan harga MAKSIMUM per satuan.

DATA PROYEK:
Nama: {$pName}
Lingkup / spesifikasi: {$pScope}
Tanggal mulai: {$pStart}
Tanggal selesai: {$pEnd}
Budget: {$pBudget}

ITEM YANG SUDAH ADA DI RAB PROYEK (JSON; pakai nama PERSIS sama bila item yang sama dibutuhkan):
{$existingJson}

ATURAN:
1. Buat 5 sampai 9 Task berurutan logis sesuai lingkup (contoh gudang baja: Persiapan & Pekerjaan Tanah; Pondasi/Sipil/Lantai; Struktur Baja & Atap; Dinding & Finishing Arsitektural; MEP/Fasilitas Pelengkap; Pembersihan & Serah Terima). Sesuaikan dengan jenis proyek sebenarnya.
2. Setiap Task: task_name, description, assumption (asumsi perhitungan volume, contoh "baja 40 kg/m2, luas 3.750 m2"), start_date, end_date (YYYY-MM-DD, wajib di dalam rentang proyek).
3. Hitung volume dari dimensi pada lingkup. Bila dimensi tidak disebut, buat asumsi wajar dan tuliskan pada assumption.
4. materials = bahan fisik. Task pekerjaan fisik utama WAJIB memuat material selengkap yang dibutuhkan di lapangan (utama + pendukung + aksesori), umumnya 4 sampai 10 material per Task. Contoh: struktur baja = baja WF, besi CNP/gording, atap spandek, baut & anchor bolt, cat anti karat, insulasi; sipil = ready mix beton (mutu K sesuai fungsi), wiremesh, besi beton, batu belah & semen, floor hardener; dinding = bata ringan, semen mortar, cladding, rolling door, pintu darurat.
5. expenses = biaya NON-material (setiap expense WAJIB punya category: Upah, Peralatan, Transport, Konsumsi, atau Lain-lain; upah/jasa tenaga/subkon = Upah, sewa alat = Peralatan, angkut/mobilisasi = Transport, makan-minum pekerja = Konsumsi, perizinan/overhead/lainnya = Lain-lain): upah/jasa tenaga kerja, jasa fabrikasi & ereksi, sewa alat berat, subkontraktor, mobilisasi/demobilisasi, perizinan, pekerjaan tanah (cut & fill, pemadatan), MEP berbasis jasa, K3, pembersihan, serah terima, overhead lapangan.
6. SETIAP Task WAJIB berisi minimal satu item pada materials ATAU expenses; tidak boleh keduanya kosong. Task tanpa material (persiapan lahan, pekerjaan tanah, mobilisasi, perizinan, pembersihan, serah terima) WAJIB diisi expenses yang rinci. Task fisik sebaiknya memuat materials DAN expenses (jasa pasang/ereksi/sewa alat).
7. Setiap item: nama (material_name atau expense_name, tanpa spesifikasi panjang), spec (spesifikasi/keterangan singkat), unit (satuan baku: kg, m2, m3, m1, batang, lembar, sak, set, pail, roll, unit, truk untuk material; ls, hari, OH, bulan, m2, m3, kg, unit untuk expense), qty_estimate (angka > 0), unit_price_min dan unit_price_max (angka Rupiah per satuan; min <= max, ikuti kisaran harga pasar), reason (singkat).
8. Gunakan angka murni pada JSON (tanpa titik pemisah ribuan, tanpa teks "Rp").
9. Item yang sama boleh muncul di beberapa Task dengan qty terbagi; jangan menggandakan kebutuhan. Jangan memasukkan upah/jasa sebagai material; jangan memasukkan bahan sebagai expense.
10. Berikan analysis: 2-3 kalimat ringkasan estimasi biaya kasar (material dan expense) dan catatan hal yang belum tercakup.

KELUARKAN JSON MURNI berbentuk object:
{
  "analysis": "string",
  "tasks": [
    {
      "task_name": "string",
      "description": "string",
      "assumption": "string",
      "start_date": "YYYY-MM-DD",
      "end_date": "YYYY-MM-DD",
      "materials": [
        { "material_name": "string", "spec": "string", "unit": "string", "qty_estimate": 10, "unit_price_min": 90000, "unit_price_max": 110000, "reason": "string" }
      ],
      "expenses": [
        { "expense_name": "string", "category": "Upah", "spec": "string", "unit": "string", "qty_estimate": 1, "unit_price_min": 15000, "unit_price_max": 30000, "reason": "string" }
      ]
    }
  ]
}
PROMPT;

            $aiRawText = mpCallGemini($prompt, 120) ?: '{}';
            $plan = json_decode($aiRawText, true);
            if (is_array($plan) && isset($plan[0]['task_name'])) $plan = ['tasks' => $plan];   // toleransi: AI mengembalikan array saja
            if (!is_array($plan) || empty($plan['tasks']) || !is_array($plan['tasks'])) {
                throw new Exception('AI gagal menghasilkan rencana Task dan material yang valid.');
            }

            $validDate = function($v) {
                $d = DateTime::createFromFormat('!Y-m-d', $v);
                return $d && $d->format('Y-m-d') === $v;
            };
            $covered = true; $coverageNotes = []; $normalizedTasks = [];
            foreach ($plan['tasks'] as $idx => $t) {
                $tName  = trim((string)($t['task_name'] ?? ''));
                $tStart = (string)($t['start_date'] ?? '');
                $tEnd   = (string)($t['end_date'] ?? '');
                if ($tName === '') throw new Exception('Nama Task ke-' . ($idx + 1) . ' kosong.');
                if (!$validDate($tStart) || !$validDate($tEnd) || $tStart > $tEnd) {
                    throw new Exception('Tanggal Task "' . $tName . '" tidak valid.');
                }
                if ($tStart < $pStart || $tEnd > $pEnd) {
                    $covered = false;
                    $coverageNotes[] = "Task '{$tName}' berada di luar rentang tanggal proyek.";
                }
                $normalizedTasks[] = [
                    'task_name' => $tName, 'description' => trim((string)($t['description'] ?? '')),
                    'assumption' => trim((string)($t['assumption'] ?? '')), 'start_date' => $tStart, 'end_date' => $tEnd,
                    'materials' => is_array($t['materials'] ?? null) ? $t['materials'] : [],
                    'expenses'  => is_array($t['expenses'] ?? null) ? $t['expenses'] : []
                ];
            }
            $tot = mpAnnotateTasks($normalizedTasks, $ctx, $rate, true);            // cocokkan RAB/master, harga min/max, total
            $meta = mpPlanMeta($proj, $ctx, $rate, $tot, $normalizedTasks);
            $cnt = ['m' => 0, 'e' => 0];
            foreach ($normalizedTasks as $nt) { $cnt['m'] += count($nt['materials']); $cnt['e'] += count($nt['expenses']); }

            mpJson(array_merge([
                'success' => true,
                'message' => 'Draft Task, material dan biaya (expense) berhasil dibuat. Periksa dan ubah sebelum menyimpan; total memakai harga MAKSIMUM.',
                'coverage_valid' => $covered,
                'notes' => $coverageNotes,
                'analysis' => trim((string)($plan['analysis'] ?? '')),
                'boq_total' => round($tot['max'], 2), 'boq_total_min' => round($tot['min'], 2),
                'mat_total' => round($tot['mat'], 2), 'exp_total' => round($tot['exp'], 2),
                'data' => ['project_id' => $projectId, 'tasks' => $normalizedTasks],
                'available_materials' => $candMat, 'available_expenses' => $candExp,
                'project_range' => ['start' => $pStart, 'end' => $pEnd],
                'existing_tasks' => ['total' => $tri['total'], 'replaceable' => count($tri['replaceable_ids']), 'kept' => $tri['total'] - count($tri['replaceable_ids'])],
                'material_count' => $cnt['m'], 'expense_count' => $cnt['e'], 'new_material_count' => count($tot['newNames'])
            ], $meta));
            break;

        // [AI-BOQ][NEW] Hitung ulang draft yang diedit user (tanpa AI, tanpa tulis DB): status RAB/master/baru, total, plafon
        case 'resolve_plan':
            if ($access < 2) mpJson(['success' => false, 'message' => 'Akses ditolak.']);
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            $plan = json_decode($_REQUEST['plan_json'] ?? '', true);
            if ($projectId <= 0 || !is_array($plan) || !isset($plan['tasks']) || !is_array($plan['tasks'])) throw new Exception('Data draft tidak valid.');
            $proj = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $projectId], 'resolve_proj');
            if (!$proj) throw new Exception('Proyek tidak ditemukan.');
            $rate = (float)($proj['budget_exch_rate'] ?? 1);
            if ($rate <= 0) $rate = 1;
            $mode = mpMode($_REQUEST['mode'] ?? 'replace');                  // [AI-BOQ] replace = task lama (belum dimulai) diganti; append = ditambahkan
            $ctx = mpLoadMaterialContext($db, $projectId, $mode === 'append' ? [] : mpTaskReplaceInfo($db, $projectId)['replaceable_ids']);
            $tasksIn = $plan['tasks'];
            $tot = mpAnnotateTasks($tasksIn, $ctx, $rate, false);                   // qty 0 dipertahankan agar baris yang sedang diedit tidak hilang
            $meta = mpPlanMeta($proj, $ctx, $rate, $tot, $tasksIn);
            mpJson(array_merge([
                'success' => true, 'message' => 'OK',
                'boq_total' => round($tot['max'], 2), 'boq_total_min' => round($tot['min'], 2),
                'mat_total' => round($tot['mat'], 2), 'exp_total' => round($tot['exp'], 2),
                'new_material_count' => count($tot['newNames']),
                'data' => ['project_id' => $projectId, 'tasks' => $tasksIn]
            ], $meta));
            break;

        // [AI-BOQ][REWORK] Simpan: (1) buat material master yang belum ada -> (2) material & expense masuk RAB -> (3) task + alokasi.
        case 'save_ai_task_plan':
            if ($access < 2) mpJson(['success' => false, 'message' => 'Akses ditolak.']);
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            $planRaw = $_REQUEST['plan_json'] ?? '';
            if ($projectId <= 0 || $planRaw === '') throw new Exception('Data rencana Task tidak lengkap.');
            $plan = json_decode($planRaw, true);
            if (!is_array($plan) || empty($plan['tasks']) || !is_array($plan['tasks'])) {
                throw new Exception('Format rencana Task tidak valid. Minimal satu Task.');
            }
            if (!mpHasPriceRange($db)) throw new Exception('Kolom harga min master (materials.estimated_price_min / expense_master.default_price_min) belum ada. Jalankan migrasi sql/2026-11-06.budget_items_price_range.sql terlebih dahulu.');
            $proj = mpFetchOne($db, "SELECT id, status, start_date, end_date, budget, budget_exch_rate, contract_value FROM projects WHERE id = :id", ['id' => $projectId], 'save_ai_plan_project');
            if (!$proj) throw new Exception('Proyek tidak ditemukan.');
            $rate = (float)($proj['budget_exch_rate'] ?? 1);
            if ($rate <= 0) $rate = 1;

            $db->beginTransaction();
            try {
                mpExec($db, "SELECT id FROM projects WHERE id = :id FOR UPDATE", ['id' => $projectId], 'lock_proyek');   // cegah simpan ganda paralel
                $db->query("SELECT pg_advisory_xact_lock(771001)");                                                       // serialisasi kode material otomatis
                // [AI-BOQ] Mode ganti: hapus task lama yang belum dimulai (beserta alokasinya) agar analisis ulang TIDAK menggandakan task maupun qty RAB.
                // Task yang sudah berjalan / punya tagihan terbayar dipertahankan. Tagihan otomatis dilepas dulu dari task lama lalu disambung ulang ke task baru.
                $mode = mpMode($_REQUEST['mode'] ?? 'replace');
                $replaced = 0;
                if ($mode === 'replace') {
                    $delIds = mpTaskReplaceInfo($db, $projectId)['replaceable_ids'];
                    if ($delIds) {
                        $inList = implode(',', array_map('intval', $delIds));
                        $db->exec("UPDATE project_invoices SET task_id = NULL WHERE task_id IN ({$inList})");
                        $db->exec("DELETE FROM public.task_materials WHERE task_id IN ({$inList})");
                        $db->exec("DELETE FROM public.project_tasks WHERE id IN ({$inList})");
                        $replaced = count($delIds);
                    }
                }
                $ctx = mpLoadMaterialContext($db, $projectId);

                // ---- Pass 1: validasi baris, kelompokkan per item lintas task ----
                $tasksClean = []; $agg = [];
                foreach ($plan['tasks'] as $t) {
                    $tName  = trim((string)($t['task_name'] ?? ''));
                    $tDesc  = trim((string)($t['description'] ?? ''));
                    $tStart = (string)($t['start_date'] ?? '');
                    $tEnd   = (string)($t['end_date'] ?? '');
                    $d1 = DateTime::createFromFormat('!Y-m-d', $tStart);
                    $d2 = DateTime::createFromFormat('!Y-m-d', $tEnd);
                    if ($tName === '' || !$d1 || $d1->format('Y-m-d') !== $tStart || !$d2 || $d2->format('Y-m-d') !== $tEnd || $tStart > $tEnd) {
                        throw new Exception('Data Task "' . $tName . '" tidak valid (nama / tanggal). Simpan dibatalkan.');
                    }
                    $lines = [];
                    foreach (['materials', 'expenses'] as $kind) {
                        $label = ($kind === 'expenses') ? 'Biaya' : 'Material';
                        foreach ((is_array($t[$kind] ?? null) ? $t[$kind] : []) as $m) {
                            $name = trim((string)($m['material_name'] ?? ($m['expense_name'] ?? '')));
                            $qty  = round(mpNum($m['qty_estimate'] ?? 0), 3);
                            $unit = trim((string)($m['unit'] ?? ''));
                            $bid  = (int)($m['budget_item_id'] ?? 0);
                            if ($qty <= 0 || ($name === '' && $bid <= 0)) throw new Exception("{$label} pada Task \"{$tName}\" tidak valid (nama / qty).");
                            if (strlen($name) > 255) $name = substr($name, 0, 255);
                            list($pMin, $pMax) = mpNormPrice(mpNum($m['price_min'] ?? 0), mpNum($m['price_max'] ?? ($m['unit_price'] ?? 0)));
                            list($aiMin, $aiMax) = mpNormPrice(mpNum($m['ai_min'] ?? 0), mpNum($m['ai_max'] ?? 0));
                            $res = mpResolveLine($ctx, $bid, $name, $kind);          // server menentukan sendiri: rab / master / new
                            if ($res['type'] !== 'rab' && $pMax <= 0 && !($res['type'] === 'master' && (float)($kind === 'expenses' ? $res['master']['default_price'] : $res['master']['estimated_price']) > 0)) {
                                throw new Exception("Harga {$label} \"{$name}\" belum diisi (Task \"{$tName}\").");
                            }
                            $key = mpLineKey($res, $name, $kind);
                            if (!isset($agg[$key])) {
                                $agg[$key] = ['kind' => $kind, 'res' => $res, 'name' => $name, 'unit' => $unit, 'pmin' => $pMin, 'pmax' => $pMax,
                                              'qty' => 0.0, 'spec' => trim((string)($m['spec'] ?? '')), 'category' => mb_substr($tName, 0, 100), 'aimin' => $aiMin, 'aimax' => $aiMax,
                                              'cat' => mpExpCategory($m['category'] ?? '')];     // kategori RAB utk biaya baru
                            }
                            if ($agg[$key]['spec'] === '') $agg[$key]['spec'] = trim((string)($m['spec'] ?? ''));
                            $agg[$key]['pmax'] = max($agg[$key]['pmax'], $pMax);
                            if ($pMin > 0 && ($agg[$key]['pmin'] <= 0 || $pMin < $agg[$key]['pmin'])) $agg[$key]['pmin'] = $pMin;
                            $agg[$key]['qty'] = round($agg[$key]['qty'] + $qty, 3);
                            $lines[] = ['key' => $key, 'qty' => $qty];
                        }
                    }
                    $tasksClean[] = ['name' => $tName, 'desc' => $tDesc, 'start' => $tStart, 'end' => $tEnd, 'lines' => $lines];
                }

                // ---- Pass 2: master (material / expense_master) bila belum ada -> RAB proyek ----
                $stMatIns = $db->prepare("
                    INSERT INTO public.materials (material_code, material_name, unit, category, active, estimated_price, estimated_price_min)
                    VALUES (:code, :name, :unit, :cat, true, :pmax, :pmin) RETURNING id");
                $stExpIns = $db->prepare("
                    INSERT INTO expense_master (expense_code, expense_name, category, unit, default_price, default_price_min, active)
                    VALUES (:code, :name, :cat, :unit, :price, :pmin, true) RETURNING id");
                $stRabIns = $db->prepare("
                    INSERT INTO public.budget_items (project_id, category, material_id, expense_id, item_name, unit, qty_plan,
                                                     unit_price_plan, tolerance_pct, notes)
                    VALUES (:pid, :cat, :mid, :eid, :iname, :unit, :qty, :pplan, 0, :notes) RETURNING id");   // harga satuan RAB = MAX; rentang min-max ada di master
                $newMaterials = 0; $newExpMaster = 0; $newRabMat = 0; $newRabExp = 0; $topups = 0; $touched = []; $filledRange = 0;
                foreach ($agg as $key => &$a) {
                    $res = $a['res'];
                    // [AI-BOQ] Master yang rentang harganya masih kosong diisi dari estimasi AI (hanya mengisi yang kosong, tidak menimpa nilai yang sudah ada)
                    $mtype = null; $mid0 = 0; $curMax = 0.0; $curMin = 0.0;
                    if ($res['type'] === 'rab') {
                        $r0 = $res['rab'];
                        if ($r0['material_id'] > 0) { $mtype = 'M'; $mid0 = $r0['material_id']; } elseif ($r0['expense_id'] > 0) { $mtype = 'E'; $mid0 = $r0['expense_id']; }
                        $curMax = (float)$r0['master_max']; $curMin = (float)$r0['master_min'];
                    } elseif ($res['type'] === 'master') {
                        $mtype = ($a['kind'] === 'expenses') ? 'E' : 'M'; $mid0 = (int)$res['master']['id'];
                        $curMax = (float)($mtype === 'E' ? $res['master']['default_price'] : $res['master']['estimated_price']);
                        $curMin = (float)($mtype === 'E' ? $res['master']['default_price_min'] : $res['master']['estimated_price_min']);
                    }
                    if ($mtype && $mid0 > 0 && ($curMax <= 0 || $curMin <= 0)) {
                        $nMax = $curMax > 0 ? $curMax : (float)$a['aimax'];
                        $nMin = $curMin > 0 ? $curMin : (((float)$a['aimin'] > 0 && (float)$a['aimin'] < $nMax) ? (float)$a['aimin'] : 0.0);
                        if ($nMax > 0 && ($nMax != $curMax || $nMin != $curMin)) {
                            if ($mtype === 'M') mpExec($db, "UPDATE public.materials SET estimated_price = CAST(:mx AS numeric), estimated_price_min = CASE WHEN CAST(:mn AS numeric) > 0 THEN CAST(:mn AS numeric) ELSE estimated_price_min END WHERE id = :id", ['mx' => $nMax, 'mn' => $nMin, 'id' => $mid0], 'fill_master_range');
                            else                mpExec($db, "UPDATE expense_master SET default_price = CAST(:mx AS numeric), default_price_min = CASE WHEN CAST(:mn AS numeric) > 0 THEN CAST(:mn AS numeric) ELSE default_price_min END WHERE id = :id", ['mx' => $nMax, 'mn' => $nMin, 'id' => $mid0], 'fill_master_range_exp');
                            $filledRange++;
                        }
                    }
                    if ($res['type'] === 'rab') {
                        $r = $res['rab'];
                        $a['bid'] = (int)$r['budget_item_id'];
                        $need = round($r['qty_allocated'] + $a['qty'], 3);          // RAB harus menampung seluruh alokasi task
                        if ($need > $r['qty_plan'] + MP_EPS) {
                            mpExec($db, "UPDATE public.budget_items SET qty_plan = :q WHERE id = :id", ['q' => $need, 'id' => $a['bid']], 'topup_rab');
                            $topups++; $touched[$a['bid']] = true;
                        }
                        continue;
                    }
                    $mid = null; $eid = null; $isExp = ($a['kind'] === 'expenses'); $cat = 'Material';
                    if (!$isExp) {
                        if ($res['type'] === 'master') {
                            $mid = (int)$res['master']['id'];
                            if ($a['unit'] === '') $a['unit'] = $res['master']['unit'];
                            if ((float)$res['master']['estimated_price'] > 0) list($a['pmin'], $a['pmax']) = mpNormPrice((float)$res['master']['estimated_price_min'] ?: (float)$res['master']['estimated_price'], (float)$res['master']['estimated_price']);   // pakai rentang master
                            $a['name'] = $res['master']['material_name'];
                        } else {                                                      // material baru -> buat dulu di master
                            if ($a['unit'] === '') $a['unit'] = 'unit';
                            $stMatIns->execute([':code' => mpNextMaterialCode($db), ':name' => $a['name'], ':unit' => mb_substr($a['unit'], 0, 30),
                                                ':cat' => $a['category'], ':pmax' => $a['pmax'], ':pmin' => $a['pmin']]);
                            $mid = (int)$stMatIns->fetchColumn();
                            if ($mid <= 0) throw new Exception('Gagal membuat material baru "' . $a['name'] . '".');
                            $newMaterials++;
                        }
                    } else {
                        if ($res['type'] === 'master') {                              // biaya sudah ada di expense_master, belum di RAB
                            $eid = (int)$res['master']['id']; $cat = mpExpCategory($res['master']['category']);
                            if ($a['unit'] === '') $a['unit'] = $res['master']['unit'];
                            if ((float)$res['master']['default_price'] > 0) list($a['pmin'], $a['pmax']) = mpNormPrice((float)$res['master']['default_price_min'] ?: (float)$res['master']['default_price'], (float)$res['master']['default_price']);   // pakai rentang master
                            $a['name'] = $res['master']['expense_name'];
                        } else {                                                      // biaya baru -> buat dulu di expense_master
                            if ($a['unit'] === '') $a['unit'] = 'ls';
                            $cat = mpExpCategory($a['cat']);
                            $stExpIns->execute([':code' => mpNextExpenseCode($db), ':name' => mb_substr($a['name'], 0, 150), ':cat' => $cat,
                                                ':unit' => mb_substr($a['unit'], 0, 30), ':price' => $a['pmax'], ':pmin' => $a['pmin']]);
                            $eid = (int)$stExpIns->fetchColumn();
                            if ($eid <= 0) throw new Exception('Gagal membuat biaya baru "' . $a['name'] . '" di master biaya.');
                            $newExpMaster++;
                        }
                    }
                    $notes = trim(($a['spec'] !== '' ? ($isExp ? 'Keterangan: ' : 'Spesifikasi: ') . $a['spec'] . '. ' : '') .
                                  ($isExp ? 'Biaya non-material dari analisis AI.' : 'Dibuat dari analisis AI.'));
                    $stRabIns->execute([
                        ':pid' => $projectId, ':cat' => $cat, ':mid' => $mid, ':eid' => $eid,
                        ':iname' => $a['name'], ':unit' => mb_substr($a['unit'], 0, 30), ':qty' => $a['qty'],
                        ':pplan' => round($a['pmax'] / $rate, 2),                    // harga satuan RAB = MAX (mata uang anggaran)
                        ':notes' => $notes
                    ]);
                    $a['bid'] = (int)$stRabIns->fetchColumn();
                    if ($a['bid'] <= 0) throw new Exception('Gagal memasukkan "' . $a['name'] . '" ke RAB proyek.');
                    $touched[$a['bid']] = true;
                    if ($isExp) $newRabExp++; else $newRabMat++;
                }
                unset($a);

                // Plafon: total RAB (qty x harga RAB, mata uang anggaran) tidak boleh melebihi nilai kontrak; budget hanya diperingatkan
                $rt = mpFetchOne($db, "SELECT COALESCE(SUM(qty_plan * unit_price_plan),0) AS s FROM public.budget_items WHERE project_id = :p", ['p' => $projectId], 'rab_total_after');
                $rabAfter = (float)$rt['s'];
                $contract = (float)($proj['contract_value'] ?? 0); $budget = (float)($proj['budget'] ?? 0);
                if ($contract > 0 && $rabAfter > $contract + 0.01) {
                    throw new Exception('Total RAB Rp ' . mpIdr($rabAfter * $rate) . ' melebihi nilai kontrak (plafon) Rp ' . mpIdr($contract * $rate) .
                                        '. Kurangi qty / harga / item lalu simpan lagi. Tidak ada data yang tersimpan.');
                }
                // Proyek berjalan & total > budget = memakai cadangan: item yang ditambah ditandai addendum (sama seperti RAB)
                $usesReserve = (($proj['status'] ?? '') !== 'Belum Mulai') && $budget > 0 && $rabAfter > $budget + 0.01;
                if ($usesReserve && $touched) {
                    $uid = (int)($_SESSION['user_id'] ?? 0);
                    $stAdd = $db->prepare("UPDATE public.budget_items SET is_addendum = true, addendum_reason = :r, addendum_by = :by, addendum_at = NOW() WHERE id = :id");
                    foreach (array_keys($touched) as $tid) {
                        $stAdd->execute([':r' => 'Analisis AI: memakai cadangan anggaran', ':by' => $uid > 0 ? $uid : null, ':id' => $tid]);
                    }
                }

                // ---- Pass 3: task + alokasi (material & expense memakai task_materials -> budget_items) ----
                $stTask = $db->prepare("
                    INSERT INTO public.project_tasks (project_id, task_name, description, start_date, end_date, status)
                    VALUES (:pid, :tname, :tdesc, :tsd, :ted, 'Belum Mulai')
                    RETURNING id");
                $stMaterial = $db->prepare("
                    INSERT INTO public.task_materials (task_id, budget_item_id, qty_budget)
                    VALUES (:tid, :bid, :qty)");
                $taskCount = 0; $allocCount = 0;
                foreach ($tasksClean as $tc) {
                    $stTask->execute([':pid' => $projectId, ':tname' => $tc['name'], ':tdesc' => $tc['desc'], ':tsd' => $tc['start'], ':ted' => $tc['end']]);
                    $taskId = (int)$stTask->fetchColumn();
                    if ($taskId <= 0) throw new Exception('Gagal mendapatkan ID Task baru.');
                    $perTask = [];                                                    // item sama di satu task digabung
                    foreach ($tc['lines'] as $ln) {
                        $bid = $agg[$ln['key']]['bid'];
                        $perTask[$bid] = round(($perTask[$bid] ?? 0) + $ln['qty'], 3);
                    }
                    foreach ($perTask as $bid => $qty) {
                        $stMaterial->execute([':tid' => $taskId, ':bid' => $bid, ':qty' => $qty]);
                        $allocCount++;
                    }
                    $taskCount++;
                }
                $db->commit();

                $warn = ($budget > 0 && $rabAfter > $budget + 0.01)
                    ? ' PERHATIAN: total RAB Rp ' . mpIdr($rabAfter * $rate) . ' LEBIH BESAR dari budget proyek Rp ' . mpIdr($budget * $rate) .
                      ($usesReserve ? ' (memakai cadangan; item ditandai addendum).' : ' (proyek Belum Mulai: RAB tidak bisa disimpan ulang sampai total <= budget).') : '';

                $invResult = null; $invError = '';
                if ($access >= 3) {
                    try {
                        $invoicePlan = mpBuildInvoicePlan($db, $projectId, true);
                        $invoiceApplied = mpApplyInvoicePlan($db, $invoicePlan);
                        $invResult = ['made' => $invoiceApplied['made'], 'synced' => $invoiceApplied['synced']];
                    } catch (Throwable $ie) {
                        error_log('[m.proyekCRD][auto_invoice_after_ai_plan] ' . $ie->getMessage());
                        $invError = $ie->getMessage();
                    }
                }
                mpJson([
                    'success' => true,
                    'message' => "{$taskCount} Task dan {$allocCount} alokasi (material + biaya) disimpan. Master baru: {$newMaterials} material, {$newExpMaster} biaya; item RAB baru: {$newRabMat} material, {$newRabExp} biaya; RAB ditambah qty: {$topups}. " . ($replaced > 0 ? " {$replaced} task lama (belum dimulai) diganti." : ($mode === 'append' ? ' Task lama dipertahankan (mode tambah).' : '')) . ($filledRange > 0 ? " Rentang harga master diisi untuk {$filledRange} item." : '') . $warn,
                    'task_count' => $taskCount, 'allocation_count' => $allocCount,
                    'new_material_count' => $newMaterials, 'new_expense_master_count' => $newExpMaster, 'new_rab_count' => $newRabMat + $newRabExp,
                    'invoice' => $invResult, 'invoice_error' => $invError
                ]);
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                throw $ex;
            }
            break;

        case 'create':
        case 'update':
            if ($task === 'create' && $access < 2) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menambah proyek.']);
            }
            if ($task === 'update' && $access < 3) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk mengubah proyek.']);
            }

            $id             = (int)($_REQUEST['id'] ?? 0);
            $name           = trim($_REQUEST['name'] ?? '');
            $client_id      = ($_REQUEST['client_id'] ?? '') !== '' ? (int)$_REQUEST['client_id'] : null;
            $pic_id         = ($_REQUEST['pic_id'] ?? '') !== '' ? (int)$_REQUEST['pic_id'] : null;
            $location_id    = ($_REQUEST['location_id'] ?? '') !== '' ? (int)$_REQUEST['location_id'] : null;
            $start_date     = ($_REQUEST['start_date'] ?? '') ?: null;
            $end_date       = ($_REQUEST['end_date'] ?? '') ?: null;
            $status         = $_REQUEST['status'] ?? 'Berjalan';
            $notes          = $_REQUEST['notes'] ?? '';
            $project_scope  = $_REQUEST['project_scope'] ?? '';

            // [PPN][NEW] pengaturan penagihan: tarif PPN, nilai kontrak termasuk PPN, retensi, DP minimum.
            // Tidak dikirim (UI lama) -> null: saat update nilai lama dipertahankan, saat tambah memakai default kolom.
            $numOrNull = function ($k) { return (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') ? (float)str_replace(',', '', $_REQUEST[$k]) : null; };
            $ppn_rate      = $numOrNull('ppn_rate');
            $retention_pct = $numOrNull('retention_pct');
            $dp_min_pct    = $numOrNull('dp_min_pct');
            $ppn_inclusive = isset($_REQUEST['ppn_inclusive']) && $_REQUEST['ppn_inclusive'] !== ''
                             ? (in_array((string)$_REQUEST['ppn_inclusive'], ['1', 'true', 't'], true) ? 't' : 'f') : null;   // boolean PDO pgsql: 't' / 'f'
            foreach (['Tarif PPN' => $ppn_rate, 'Retensi' => $retention_pct, 'DP Minimum' => $dp_min_pct] as $lbl => $v) {
                if ($v !== null && ($v < 0 || $v > 100)) throw new Exception("{$lbl} harus antara 0 - 100%.");
            }

            $budget_raw     = $_REQUEST['budget'] ?? '';
            $cv_raw         = $_REQUEST['contract_value'] ?? '';
            
            $budget         = ($budget_raw !== '') ? (float)str_replace(',', '', $budget_raw) : null;
            $contract_value = ($cv_raw !== '') ? (float)str_replace(',', '', $cv_raw) : null;
            $progress       = (isset($_REQUEST['progress']) && $_REQUEST['progress'] !== '') ? (int)$_REQUEST['progress'] : null;

            if ($name === '') throw new Exception('Nama Proyek wajib diisi.');
            if (!in_array($status, ['Belum Mulai', 'Berjalan', 'Tertunda', 'Selesai'], true)) {
                throw new Exception('Status proyek tidak valid.');
            }
            if ($progress !== null && ($progress < 0 || $progress > 100)) throw new Exception('Progress harus antara 0-100.');
            if ($end_date && $start_date && $end_date < $start_date) {
                throw new Exception('Tanggal Selesai tidak boleh sebelum Tanggal Mulai.');
            }

            if ($task === 'create') {
                $newId = (int)mpFetchOne($db,
                    "INSERT INTO projects
                        (name, client_id, pic_id, location_id, start_date, end_date, budget, contract_value, progress, status, notes, project_scope,
                         ppn_rate, ppn_inclusive, retention_pct, dp_min_pct, created_at, updated_at)
                     VALUES (:name, :cid, :pid, :lid, :sd, :ed, :budget, :cv, :progress, :status, :notes, :scope,
                         COALESCE(CAST(:ppn AS numeric), 0), COALESCE(CAST(:ppninc AS boolean), false), COALESCE(CAST(:ret AS numeric), 0), COALESCE(CAST(:dpmin AS numeric), 30), NOW(), NOW())
                     RETURNING id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date,
                     'budget' => $budget ?? 0, 'cv' => $contract_value ?? 0,
                     'progress' => $progress ?? 0, 'status' => $status, 'notes' => $notes, 'scope' => $project_scope,
                     'ppn' => $ppn_rate, 'ppninc' => $ppn_inclusive, 'ret' => $retention_pct, 'dpmin' => $dp_min_pct],
                    'insert projects')['id'];
                mpJson(['success' => true, 'id' => $newId, 'message' => "Proyek '{$name}' berhasil ditambahkan."]);
            } else {
                if (!$id) throw new Exception('ID proyek tidak valid.');
                mpExec($db,
                    "UPDATE projects SET
                        name = :name, client_id = :cid, pic_id = :pid, location_id = :lid,
                        start_date = :sd, end_date = :ed,
                        budget = COALESCE(CAST(:budget AS numeric), budget),
                        contract_value = COALESCE(CAST(:cv AS numeric), contract_value),
                        progress = COALESCE(CAST(:progress AS smallint), progress),
                        status = :status, notes = :notes, project_scope = :scope,
                        ppn_rate = COALESCE(CAST(:ppn AS numeric), ppn_rate),
                        ppn_inclusive = COALESCE(CAST(:ppninc AS boolean), ppn_inclusive),
                        retention_pct = COALESCE(CAST(:ret AS numeric), retention_pct),
                        dp_min_pct = COALESCE(CAST(:dpmin AS numeric), dp_min_pct),
                        updated_at = NOW()
                     WHERE id = :id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date,
                     'budget' => $budget, 'cv' => $contract_value, 'progress' => $progress,
                     'status' => $status, 'notes' => $notes, 'scope' => $project_scope, 'id' => $id,
                     'ppn' => $ppn_rate, 'ppninc' => $ppn_inclusive, 'ret' => $retention_pct, 'dpmin' => $dp_min_pct],
                    'update projects');
                mpJson(['success' => true, 'message' => "Proyek '{$name}' berhasil diperbarui."]);
            }
            break;

        case 'delete':
            if ($access < 4) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus proyek.']);
            }
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID proyek tidak valid.');

            $refs = mpFetchOne($db,
                "SELECT
                    (SELECT COUNT(*) FROM purchase_orders WHERE project_id = :id1) AS po_count,
                    (SELECT COUNT(*) FROM project_invoices WHERE project_id = :id2) AS termin_count,
                    (SELECT COUNT(*) FROM material_out_header WHERE project_id = :id3) AS mout_count",
                ['id1' => $id, 'id2' => $id, 'id3' => $id], 'cek referensi proyek');
            $refCount = (int)$refs['po_count'] + (int)$refs['termin_count'] + (int)$refs['mout_count'];
            if ($refCount > 0) {
                throw new Exception("Proyek ini tidak bisa dihapus karena sudah punya transaksi terkait.");
            }

            mpExec($db, "DELETE FROM projects WHERE id = :id", ['id' => $id], 'delete projects');
            mpJson(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
            break;

        default:
            mpJson(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Throwable $e) {
    error_log('[m.proyekCRD] ' . $e->getMessage());
    mpJson(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi kesalahan pada server.']);
}