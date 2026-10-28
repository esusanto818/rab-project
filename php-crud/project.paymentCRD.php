<?php
/**
 * project.paymentCRD.php
 * ============================================================
 * Backend Controller Pembayaran Termin per Proyek (project.payment.js)
 * dan data cetak Tanda Terima (project.payment.print.js).
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Sumber data:[cite: 5]
 *   project_invoices       = tagihan termin per proyek (inv_no, termin_name, amount, paid_amount)[cite: 5]
 *   project_payments       = header pembayaran dari client (total, teralokasi, lebih bayar)[cite: 5]
 *   project_payment_items  = alokasi pembayaran ke tiap termin[cite: 5]
 *   projects, clients, chart_of_accounts (akun kas/bank)[cite: 5]
 *
 * Aturan:[cite: 5]
 *  1. [MULTI-PROYEK] Satu pembayaran = satu CLIENT; alokasi boleh ke termin beberapa proyek milik client yang sama[cite: 5]
 *     (validasi: projects.client_id dari setiap termin harus sama dengan client pembayaran)[cite: 5].
 *  2. Alokasi per termin tidak boleh melebihi sisa tagihan (amount - paid_amount)[cite: 5].
 *  3. total_amount >= allocated_amount; selisihnya disimpan di excess_amount (lebih bayar)[cite: 5].
 *  4. Simpan / hapus pembayaran memperbarui project_invoices.paid_amount dalam satu transaksi[cite: 5].
 *  5. Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang[cite: 5].
 *  6. Termin baru: total semua termin proyek tidak boleh melebihi contract_value (bila diisi)[cite: 5].
 *  7. [MULTI-CURRENCY] Pembayaran punya mata uang + kurs (default IDR, kurs 1)[cite: 5]. Input klien (total_amount, amount_paid) = mata uang
 *     pembayaran; tagihan termin tetap IDR[cite: 5]. Yang disimpan: total_amount / allocated_amount / excess_amount / amount_paid = IDR[cite: 5]
 *     (supaya project_invoices.paid_amount & laporan lama tetap konsisten), nilai asli di total_amount_cur / amount_cur[cite: 5].
 *     Nilai setara IDR = nilai x kurs, di-snap ke sisa tagihan bila selisih hanya akibat pembulatan 2 desimal[cite: 5].
 *     Butuh migrasi: sql/2026-10-15.project_payment_currency.sql[cite: 5]
 *  8. [NEW] delete_termin: hapus termin salah entry, hanya bila belum pernah dibayar (paid_amount = 0 & tidak ada project_payment_items)[cite: 5].
 *  9. [JURNAL] save_payment memposting jurnal otomatis (AutoPostJournal, function.all.php) dalam transaksi yang sama; gagal posting = pembayaran
 *     dibatalkan (rollback)[cite: 5]. Debit = akun Kas/Bank pilihan user (WAJIB diisi), kredit mengikuti fiscal_config[cite: 5]:
 *       PRJ_PAY_AR  : bagian yang dialokasikan ke termin  -> kredit Piutang Usaha[cite: 5]
 *       PRJ_PAY_DEP : bagian lebih bayar (excess)          -> kredit Deposit / Uang Muka Client[cite: 5]
 *     reference_type = 'project_payment'[cite: 5]. delete_payment menghapus jurnalnya (ReverseJournalByReference)[cite: 5].
 *     Butuh migrasi: sql/2026-10-16.project_payment_journal.sql[cite: 5]
 * 10. [JURNAL-INVOICE][NEW] save_termin memposting jurnal penagihan termin (fiscal_config PROJECT_INVOICE[cite: 5]:
 *     Dr Piutang Usaha / Cr Pendapatan) dengan reference_type = 'project_invoice', dalam transaksi yang sama[cite: 5]
 *     (gagal posting = termin tidak tersimpan)[cite: 5]. delete_termin menghapus jurnal penagihannya[cite: 5].
 *     Task backfill_termin_journal = sekali jalan, memposting jurnal untuk termin lama yang belum punya jurnal[cite: 5].
 * 11. [PPN - TAHAP 2] PPN Keluaran di termin. Nilai termin yang diinput = DPP (tanpa PPN); tarif default 11%, 0 = tanpa PPN.
 *     project_invoices.amount = TOTAL tagihan (DPP + PPN) agar alokasi pembayaran, sisa tagihan, dan laporan lama tidak berubah;
 *     rinciannya di dpp_amount / ppn_rate / ppn_amount (+ faktur_pajak_no / faktur_pajak_date, opsional).
 *     Kontrol nilai kontrak membandingkan total DPP termin dengan contract_value (kontrak dianggap di luar PPN).
 *     Jurnal: PROJECT_INVOICE (Dr Piutang / Cr Pendapatan) senilai DPP + PROJECT_INVOICE_PPN (Dr Piutang / Cr PPN Keluaran)
 *     senilai PPN; keduanya reference_type 'project_invoice' sehingga delete_termin ikut menghapus keduanya.
 *     Butuh migrasi: sql/2026-10-26.ppn_invoice.sql
 * 12. [PPN] Task update_faktur_pajak = isi / ubah No. & Tgl Faktur Pajak keluaran setelah termin dibuat (faktur biasanya terbit belakangan).
 *     Hanya untuk termin yang memuat PPN; boleh walau sudah ada pembayaran; no. faktur unik antar termin; kosong = hapus.
 *     Tgl faktur menentukan masa pajak di Laporan PPN (jurnal tidak berubah).
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

const PP_EPS = 0.01;                                         // toleransi nilai rupiah[cite: 5]
const PP_JOURNAL_REF = 'project_payment';                    // [JURNAL] nilai enum journal_entries.reference_type[cite: 5]
const PP_METHODS = ['BANK_TRANSFER', 'CASH', 'GIRO', 'CEK']; // metode bayar yang diizinkan (varchar(20))[cite: 5]
const PP_INV_JOURNAL_REF = 'project_invoice';                // [JURNAL-INVOICE][NEW] nilai enum journal_entries.reference_type untuk penagihan termin[cite: 5]

const PP_INV_PPN_RULE = 'PROJECT_INVOICE_PPN';               // [PPN] fiscal_config: Dr Piutang Usaha / Cr PPN Keluaran

class PpBusinessError extends Exception {}

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

/** Validasi tanggal format YYYY-MM-DD.[cite: 5] */
function pp_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/** [JURNAL-INVOICE][NEW] Cek apakah termin sudah punya jurnal penagihan.[cite: 5] */
function pp_invoice_has_journal($db, int $invId): bool {
    $st = $db->prepare("SELECT 1 FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => PP_INV_JOURNAL_REF, ':id' => $invId]);
    return (bool)$st->fetchColumn();
}

/** [JURNAL-INVOICE][NEW] Posting jurnal penagihan termin (rule PROJECT_INVOICE). Gagal -> lempar PpBusinessError agar transaksi di-rollback.[cite: 5] */
function pp_post_invoice_journal($db, int $invId, string $invNo, string $date, float $amount, string $projectName, float $ppn = 0.0): void {
    // [PPN] $amount = DPP (pendapatan); $ppn = PPN Keluaran (0 = tanpa PPN)
    $jr = AutoPostJournal($db, [
        'rule_code'      => 'PROJECT_INVOICE',
        'reference_type' => PP_INV_JOURNAL_REF,
        'reference_id'   => $invId,
        'journal_date'   => $date,
        'amount'         => $amount,
        'description'    => "Tagihan termin {$invNo} - {$projectName}",
    ]);
    if (!$jr['success']) throw new PpBusinessError('Gagal posting jurnal penagihan: ' . $jr['message']);

    if ($ppn > PP_EPS) {                                   // [PPN] jurnal PPN Keluaran terpisah, reference sama
        $jr = AutoPostJournal($db, [
            'rule_code'      => PP_INV_PPN_RULE,
            'reference_type' => PP_INV_JOURNAL_REF,
            'reference_id'   => $invId,
            'journal_date'   => $date,
            'amount'         => $ppn,
            'description'    => "PPN Keluaran termin {$invNo} - {$projectName}",
        ]);
        if (!$jr['success']) throw new PpBusinessError('Gagal posting jurnal PPN Keluaran: ' . $jr['message']);
    }
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * [AI-VARIANCE] Ambil hasil analisis terakhir yang sudah tersimpan.
         * Digunakan oleh cetak tanda terima agar hasilnya sama dengan panel di layar,
         * tanpa menjalankan analisis Gemini ulang.
         * ------------------------------------------------------------------ */
        case 'latest_project_variance':
            $prjId = (int)($_REQUEST['project_id'] ?? 0);
            if ($prjId <= 0) sendJsonResponse(false, [], 'Proyek wajib dipilih.');

            $st = $db->prepare("
                SELECT id, project_id, total_budget, total_actual, variance_amount,
                       variance_percentage, risk_level, ai_summary, recommendations, created_at
                  FROM public.ai_variance_logs
                 WHERE project_id = :pid
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1
            ");
            $st->execute([':pid' => $prjId]);
            $log = $st->fetch(PDO::FETCH_ASSOC);
            if (!$log) {
                sendJsonResponse(false, [], 'Belum ada hasil analisis AI tersimpan untuk proyek ini.');
            }
            $recommendations = $log['recommendations'] ?? [];
            if (is_string($recommendations)) {
                $decoded = json_decode($recommendations, true);
                $recommendations = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($recommendations)) $recommendations = [];

            sendJsonResponse(true, [
                'id' => (int)$log['id'],
                'project_id' => (int)$log['project_id'],
                'total_budget' => (float)$log['total_budget'],
                'total_actual' => (float)$log['total_actual'],
                'variance_amount' => (float)$log['variance_amount'],
                'variance_percentage' => (float)$log['variance_percentage'],
                'risk_level' => $log['risk_level'],
                'ai_summary' => $log['ai_summary'],
                'recommendations' => $recommendations,
                'created_at' => $log['created_at']
            ], 'Hasil analisis AI terakhir berhasil dimuat.');
            break;

        /* ------------------------------------------------------------------
         * [AI-VARIANCE] Analisis Varian Anggaran Proyek menggunakan Google Gemini API
         * ------------------------------------------------------------------ */
        case 'analyze_project_variance':
            $prjId = (int)($_REQUEST['project_id'] ?? 0);
            if ($prjId <= 0) sendJsonResponse(false, [], 'Proyek wajib dipilih.');

            try {
                // 1. Ambil data agregat RAB vs Aktual (Termin / Pengeluaran) dari database existing
                $st = $db->prepare("
                    SELECT 
                        p.id AS project_id,
                        p.name AS project_name,
                        COALESCE(p.contract_value, 0) AS total_budget,
                        COALESCE((SELECT SUM(amount) FROM public.project_invoices WHERE project_id = p.id), 0) AS total_invoiced,
                        COALESCE((SELECT SUM(amount_paid) FROM public.project_payment_items pi JOIN public.project_invoices vi ON vi.id = pi.invoice_id WHERE vi.project_id = p.id), 0) AS total_actual
                    FROM public.projects p
                    WHERE p.id = :id
                ");
                $st->execute([':id' => $prjId]);
                $prjData = $st->fetch(PDO::FETCH_ASSOC);
                
                if (!$prjData) {
                    sendJsonResponse(false, [], 'Proyek tidak ditemukan.');
                }

                $totalBudget = (float)$prjData['total_budget'];
                $totalActual = (float)$prjData['total_actual'];
                $varianceAmount = $totalActual - $totalBudget;
                $variancePercentage = $totalBudget > 0 ? ($varianceAmount / $totalBudget) * 100 : 0;

                // 2. Susun Prompt untuk Google Gemini API
                $prompt = "
                    Anda adalah Construction Cost Estimator dan System Analyst ahli untuk sistem ERP RAB Kontraktor. 
                    Analisis data varian anggaran proyek konstruksi berikut:
                    - Nama Proyek: {$prjData['project_name']}
                    - Total Anggaran / Kontrak (RAB): {$totalBudget}
                    - Total Pengeluaran / Realisasi Aktual: {$totalActual}
                    - Selisih Varian: {$varianceAmount} (" . number_format($variancePercentage, 2) . "%)
                    
                    Berikan analisis dalam format JSON murni dengan struktur kunci berikut tanpa markdown backtick tambahan:
                    - \"risk_level\": string (\"LOW\", \"MEDIUM\", \"HIGH\", atau \"CRITICAL\")
                    - \"ai_summary\": string (ringkasan eksekutif mengenai kondisi overbudget atau efisiensi biaya proyek)
                    - \"recommendations\": array of strings (poin-poin tindakan mitigasi konkret untuk kontraktor)
                ";
                $apiKey = getenv('GOOGLE_API_KEY');
                // Daftar model cadangan (fallback) untuk mengantisipasi trafik tinggi / error 503
                $candidateModels = ['gemini-3.6-flash','gemini-3.5-flash-lite'];

                $payload = json_encode([
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json'
                    ]
                ]);

                $response = null;
                $httpCode = 0;
                $lastErrorMsg = '';

                // 3. Loop mencoba ke model cadangan & sistem retry otomatis jika menemui kendala 503/429
                foreach ($candidateModels as $model) {
                    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                    
                    for ($attempt = 1; $attempt <= 2; $attempt++) {
                        $ch = curl_init($apiUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'x-goog-api-key: ' . $apiKey
                        ]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($httpCode === 200) {
                            break 2; // Keluar dari kedua loop jika sukses
                        }

                        $lastErrorMsg = "Model {$model} (HTTP {$httpCode}): " . $response;
                        
                        if ($httpCode === 503 || $httpCode === 429) {
                            sleep(1); // Jeda 1 detik sebelum retry
                        } else {
                            break; // Lanjut ke model berikutnya jika error selain 503/429
                        }
                    }
                }

                if ($httpCode !== 200) {
                    throw new Exception("Layanan Gemini API sedang padat beban. Silakan coba beberapa saat lagi. ({$lastErrorMsg})");
                }

                $apiResult = json_decode($response, true);
                $aiRawText = $apiResult['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                
                // Bersihkan format markdown jika terbawa
                $aiRawText = trim(str_replace(['```json', '```'], '', $aiRawText));
                $aiParsed = json_decode($aiRawText, true);

                // 4. Simpan hasil analisis ke tabel log ai_variance_logs
                $stIns = $db->prepare("
                    INSERT INTO public.ai_variance_logs 
                    (project_id, total_budget, total_actual, variance_amount, variance_percentage, risk_level, ai_summary, recommendations, created_by)
                    VALUES (:pid, :tb, :ta, :va, :vp, :rl, :sum, CAST(:rec AS jsonb), :uid)
                    RETURNING id, created_at
                ");
                $stIns->execute([
                    ':pid' => $prjId,
                    ':tb'  => $totalBudget,
                    ':ta'  => $totalActual,
                    ':va'  => $varianceAmount,
                    ':vp'  => $variancePercentage,
                    ':rl'  => $aiParsed['risk_level'] ?? 'LOW',
                    ':sum' => $aiParsed['ai_summary'] ?? '',
                    ':rec' => json_encode($aiParsed['recommendations'] ?? []),
                    ':uid' => (int)($_SESSION['user_id'] ?? 0) ?: null
                ]);
                $logRow = $stIns->fetch(PDO::FETCH_ASSOC);

                sendJsonResponse(true, [
                    'id'                  => (int)$logRow['id'],
                    'project_id'          => $prjId,
                    'total_budget'        => $totalBudget,
                    'total_actual'        => $totalActual,
                    'variance_amount'     => $varianceAmount,
                    'variance_percentage' => $variancePercentage,
                    'risk_level'          => $aiParsed['risk_level'] ?? 'LOW',
                    'ai_summary'          => $aiParsed['ai_summary'] ?? '',
                    'recommendations'     => $aiParsed['recommendations'] ?? [],
                    'created_at'          => $logRow['created_at']
                ], 'Analisis varian AI berhasil dijalankan.');

            } catch (Exception $e) {
                sendJsonResponse(false, [], 'Gagal memproses AI: ' . $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Combo proyek (+ client & nilai kontrak)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_project_options':
            $rows = $db->query("
                SELECT p.id, p.name, p.client_id, c.name AS client_name,
                       COALESCE(p.contract_value, 0) AS contract_value
                  FROM public.projects p
                  LEFT JOIN public.clients c ON c.id = p.client_id
                 ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['client_id'] = $r['client_id'] !== null ? (int)$r['client_id'] : null;
                $r['contract_value'] = (float)$r['contract_value'];
            }
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-PROYEK] Combo client (hanya client yang punya proyek)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_client_options':
            $rows = $db->query("
                SELECT c.id, c.name
                  FROM public.clients c
                 WHERE EXISTS (SELECT 1 FROM public.projects p WHERE p.client_id = c.id)
                 ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-CURRENCY] Combo mata uang (is_base = IDR)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_currencies':
            $rows = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['is_base'] = (strtoupper(trim((string)$r['init'])) === 'IDR');
            }
            unset($r);
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-CURRENCY] Kurs default: kurs pajak KMK (tax_exchange_rate_*) pada tanggal bayar.[cite: 5]
         * ------------------------------------------------------------------ */
        case 'get_exchange_rate':
            $cid  = (int)($_REQUEST['currency_id'] ?? 0);
            $date = (string)($_REQUEST['date'] ?? '');
            if (!pp_valid_date($date)) $date = date('Y-m-d');

            $st = $db->prepare("SELECT init FROM public.m_currency WHERE id = :id");
            $st->execute([':id' => $cid]);
            $curRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) sendJsonResponse(false, [], 'Mata uang tidak ditemukan.');
            $code = strtoupper(trim((string)$curRow['init']));
            if ($code === 'IDR') sendJsonResponse(true, ['rate' => 1, 'source' => 'IDR']);

            $base = "SELECT COALESCE(d.rate_per_unit, d.rate / NULLIF(d.unit_multiplier, 0)) AS rate,
                            h.kmk_no, h.start_date, h.end_date
                       FROM public.tax_exchange_rate_dtl d
                       JOIN public.tax_exchange_rate_hdr h ON h.id = d.hdr_id
                      WHERE (d.currency_id = :cid OR UPPER(d.currency_code) = :code)";
            $st = $db->prepare($base . " AND CAST(:d AS date) BETWEEN h.start_date AND h.end_date
                                          ORDER BY h.start_date DESC, h.id DESC LIMIT 1");
            $st->execute([':cid' => $cid, ':code' => $code, ':d' => $date]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $source = 'periode';
            if (!$r || $r['rate'] === null) {
                $st = $db->prepare($base . " ORDER BY h.end_date DESC, h.id DESC LIMIT 1");
                $st->execute([':cid' => $cid, ':code' => $code]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $source = 'terakhir';
            }
            if (!$r || $r['rate'] === null) sendJsonResponse(true, ['rate' => null, 'source' => 'none']);
            sendJsonResponse(true, [
                'rate'       => round((float)$r['rate'], 4),
                'source'     => $source,
                'kmk_no'     => $r['kmk_no'],
                'start_date' => date('d/m/Y', strtotime($r['start_date'])),
                'end_date'   => date('d/m/Y', strtotime($r['end_date'])),
            ]);
            break;

        /* ------------------------------------------------------------------
         * Combo akun kas / bank (chart_of_accounts aktif)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_account_options':
            $rows = $db->query("
                SELECT id, account_code, account_name
                  FROM public.chart_of_accounts
                 WHERE is_active = TRUE
                 ORDER BY account_code ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['label'] = $r['account_code'] . ' - ' . $r['account_name'];
            }
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Termin proyek yang masih punya sisa tagihan + ringkasan kontrak[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_outstanding':
            $cli = (int)($_REQUEST['client_id'] ?? 0);
            if ($cli <= 0) sendJsonResponse(true, ['data' => [], 'totalCount' => 0], '', ['summary' => ['projects' => []]]);

            $st = $db->prepare("
                SELECT x.invoice_id, x.project_id, x.project_name, x.inv_no, x.termin_name, x.invoice_date,
                       x.termin_no, x.percentage, x.amount, x.paid_amount, (x.amount - x.paid_amount) AS remaining,
                       x.dpp_amount, x.ppn_amount, x.ppn_rate,
                       x.faktur_pajak_no, x.faktur_pajak_date      -- [PPN]
                  FROM (
                        SELECT v.id AS invoice_id, v.project_id, p.name AS project_name,
                               v.inv_no, v.termin_name, v.invoice_date, v.percentage,
                               v.amount, v.paid_amount, v.dpp_amount, v.ppn_amount, v.ppn_rate, v.faktur_pajak_no, v.faktur_pajak_date, p.client_id,   -- [PPN]
                               ROW_NUMBER() OVER (PARTITION BY v.project_id ORDER BY v.invoice_date ASC, v.id ASC) AS termin_no
                          FROM public.project_invoices v
                          JOIN public.projects p ON p.id = v.project_id
                       ) x
                 WHERE x.client_id = :c AND (x.amount - x.paid_amount) > 0
                 ORDER BY x.invoice_date ASC, x.invoice_id ASC");
            $st->execute([':c' => $cli]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['invoice_id']  = (int)$r['invoice_id'];
                $r['project_id']  = (int)$r['project_id'];
                $r['termin_no']   = (int)$r['termin_no'];
                $r['percentage']  = $r['percentage'] !== null ? (float)$r['percentage'] : null;
                $r['amount']      = (float)$r['amount'];
                $r['dpp_amount']  = (float)$r['dpp_amount'];     // [PPN]
                $r['ppn_amount']  = (float)$r['ppn_amount'];     // [PPN]
                $r['ppn_rate']    = (float)$r['ppn_rate'];       // [PPN]
                $r['paid_amount'] = (float)$r['paid_amount'];
                $r['remaining']   = (float)$r['remaining'];
            }
            unset($r);

            $st = $db->prepare("
                SELECT p.id AS project_id, p.name AS project_name,
                       COALESCE(p.contract_value, 0)  AS contract_value,
                       COALESCE(SUM(v.amount), 0)      AS total_termin,
                       COALESCE(SUM(CASE WHEN v.dpp_amount > 0 THEN v.dpp_amount ELSE v.amount END), 0) AS total_dpp,   -- [PPN] dasar banding kontrak
                       COALESCE(SUM(v.paid_amount), 0) AS total_paid
                  FROM public.projects p
                  LEFT JOIN public.project_invoices v ON v.project_id = p.id
                 WHERE p.client_id = :c
                 GROUP BY p.id, p.name, p.contract_value
                 ORDER BY p.name ASC");
            $st->execute([':c' => $cli]);
            $projects = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as &$pr) {
                $pr['project_id']     = (int)$pr['project_id'];
                $pr['contract_value'] = (float)$pr['contract_value'];
                $pr['total_termin']   = (float)$pr['total_termin'];
                $pr['total_dpp']      = (float)$pr['total_dpp'];          // [PPN]
                $pr['total_paid']     = (float)$pr['total_paid'];
            }
            unset($pr);
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)], '', ['summary' => ['projects' => $projects]]);
            break;

        /* ------------------------------------------------------------------
         * Tambah termin (tagihan) baru untuk proyek[cite: 5]
         * ------------------------------------------------------------------ */
        case 'save_termin':
            $prj  = (int)($_POST['project_id'] ?? 0);
            $cliChk = (int)($_POST['client_id'] ?? 0);
            $name = trim((string)($_POST['termin_name'] ?? ''));
            $date = (string)($_POST['invoice_date'] ?? '');
            $pct  = ($_POST['percentage'] ?? '') !== '' ? round((float)$_POST['percentage'], 2) : null;
            $amt  = round((float)($_POST['amount'] ?? 0), 2);          // [PPN] nilai termin = DPP (tanpa PPN)
            $ppnRate = round((float)($_POST['ppn_rate'] ?? 0), 2);     // [PPN] 0 = tanpa PPN
            $fpNo    = trim((string)($_POST['faktur_pajak_no'] ?? '')) ?: null;
            $fpDate  = (string)($_POST['faktur_pajak_date'] ?? '');

            try {
                if ($prj <= 0) throw new PpBusinessError('Proyek wajib dipilih.');
                if ($name === '') throw new PpBusinessError('Nama termin wajib diisi.');
                if (!pp_valid_date($date)) throw new PpBusinessError('Tanggal termin tidak valid.');
                if ($pct !== null && ($pct < 0 || $pct > 100)) throw new PpBusinessError('Persentase harus antara 0 - 100.');
                if ($ppnRate < 0 || $ppnRate > 100) throw new PpBusinessError('Tarif PPN harus antara 0 - 100%.');                    // [PPN]
                if ($fpDate !== '' && !pp_valid_date($fpDate)) throw new PpBusinessError('Tanggal faktur pajak tidak valid.');       // [PPN]

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT COALESCE(contract_value, 0) AS cv, client_id, name FROM public.projects WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $prj]);
                    $prjRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prjRow) throw new PpBusinessError('Proyek tidak ditemukan.');
                    if ($cliChk > 0 && (int)$prjRow['client_id'] !== $cliChk) throw new PpBusinessError('Proyek tidak termasuk client yang dipilih.');
                    $contract = (float)$prjRow['cv'];

                    if ($amt <= 0 && $pct !== null && $contract > 0) $amt = round($contract * $pct / 100, 2);
                    if ($amt <= 0) throw new PpBusinessError('Nilai termin harus lebih dari 0.');

                    // [PPN] banding kontrak memakai DPP (termin lama tanpa dpp_amount dibaca dari amount)
                    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN dpp_amount > 0 THEN dpp_amount ELSE amount END), 0) FROM public.project_invoices WHERE project_id = :p");
                    $st->execute([':p' => $prj]);
                    $existing = (float)$st->fetchColumn();
                    if ($contract > 0 && $existing + $amt > $contract + PP_EPS) {
                        throw new PpBusinessError('Total termin DPP (' . number_format($existing + $amt, 2) . ') melebihi nilai kontrak (' . number_format($contract, 2) . ').');   // [PPN]
                    }

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $invNo = CreateRefNo('project_invoices', 'inv_no', 'INV');
                    $st = $db->prepare("
                        INSERT INTO public.project_invoices
                            (inv_no, project_id, termin_name, invoice_date, percentage, amount, paid_amount,
                             dpp_amount, ppn_rate, ppn_amount, faktur_pajak_no, faktur_pajak_date)          -- [PPN]
                        VALUES (:no, :p, :n, CAST(:d AS date), :pct, :a, 0,
                                :dpp, :pr, :ppn, :fn, CAST(NULLIF(:fd, '') AS date))
                        RETURNING id");
                    // [PPN] amount = total tagihan (DPP + PPN); PPN dibulatkan di level dokumen
                    $ppnAmt = ($ppnRate > 0) ? round($amt * $ppnRate / 100, 2) : 0.0;
                    $total  = round($amt + $ppnAmt, 2);
                    $st->execute([':no' => $invNo, ':p' => $prj, ':n' => $name, ':d' => $date, ':pct' => $pct, ':a' => $total,
                                  ':dpp' => $amt, ':pr' => $ppnRate, ':ppn' => $ppnAmt, ':fn' => $fpNo, ':fd' => $fpDate]);
                    $newId = (int)$st->fetchColumn();
                    pp_post_invoice_journal($db, $newId, $invNo, $date, $amt, (string)$prjRow['name'], $ppnAmt);   // [PPN] DPP + PPN
                    $db->commit();
                    sendJsonResponse(true, ['id' => $newId, 'inv_no' => $invNo],
                        "Termin {$invNo} berhasil dibuat." . ($ppnAmt > 0 ? ' DPP ' . number_format($amt, 2) . ' + PPN ' . number_format($ppnAmt, 2) . ' = ' . number_format($total, 2) . '.' : ''));
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (PpBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Daftar pembayaran (grid utama)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'list_payments':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (pp.payment_no ILIKE :q1 OR c.name ILIKE :q2 OR COALESCE(pp.reference_no, '') ILIKE :q3
                          OR EXISTS (SELECT 1 FROM public.project_payment_items xi
                                       JOIN public.project_invoices xv ON xv.id = xi.invoice_id
                                       JOIN public.projects xp ON xp.id = xv.project_id
                                      WHERE xi.payment_id = pp.id AND xp.name ILIKE :q4))";
                $like = '%' . $query . '%';
                $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
            }

            $st = $db->prepare("SELECT COUNT(*)
                                  FROM public.project_payments pp
                                  JOIN public.clients c ON c.id = pp.client_id {$where}");
            $st->execute($params);
            $totalCount = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT pp.id, pp.payment_no, pp.payment_date, pp.client_id, c.name AS client_name,
                       pp.account_id, a.account_name, pp.payment_method,
                       pp.total_amount, pp.allocated_amount, pp.excess_amount,
                       pp.reference_no, pp.notes,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
                       COALESCE(NULLIF(pp.exch_rate, 0), 1) AS exch_rate,
                       COALESCE(pp.total_amount_cur, pp.total_amount) AS total_amount_cur,
                       (SELECT string_agg(DISTINCT p.name, ', ')
                          FROM public.project_payment_items i
                          JOIN public.project_invoices v ON v.id = i.invoice_id
                          JOIN public.projects p ON p.id = v.project_id
                         WHERE i.payment_id = pp.id) AS project_names
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
                  LEFT JOIN public.m_currency cur ON cur.id = pp.currency_id
                  {$where}
                 ORDER BY pp.payment_date DESC, pp.id DESC
                 LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['client_id'] = (int)$r['client_id'];
                $r['account_id'] = $r['account_id'] !== null ? (int)$r['account_id'] : null;
                $r['total_amount'] = (float)$r['total_amount'];
                $r['allocated_amount'] = (float)$r['allocated_amount'];
                $r['excess_amount'] = (float)$r['excess_amount'];
                $r['exch_rate'] = (float)$r['exch_rate'];
                $r['total_amount_cur'] = (float)$r['total_amount_cur'];
            }
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => $totalCount]);
            break;

        /* ------------------------------------------------------------------
         * Detail pembayaran (header + item)[cite: 5]
         * ------------------------------------------------------------------ */
        case 'load_payment':
            $id = (int)($_REQUEST['id'] ?? 0);
            $st = $db->prepare("
                SELECT pp.id, pp.payment_no, pp.payment_date, pp.client_id, c.name AS client_name,
                       c.address AS client_address, c.phone AS client_phone,
                       pp.account_id, a.account_code, a.account_name, pp.payment_method,
                       pp.total_amount, pp.allocated_amount, pp.excess_amount,
                       pp.reference_no, pp.notes, pp.created_at,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
                       COALESCE(NULLIF(pp.exch_rate, 0), 1) AS exch_rate,
                       COALESCE(pp.total_amount_cur, pp.total_amount) AS total_amount_cur,
                       e.employee_name AS created_by_name
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
                  LEFT JOIN public.m_currency cur ON cur.id = pp.currency_id
                  LEFT JOIN public.m_user_system u ON u.id = pp.created_by
                  LEFT JOIN public.m_employee e ON e.id = u.employee_id
                 WHERE pp.id = :id");
            $st->execute([':id' => $id]);
            $hdr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) sendJsonResponse(false, [], 'Pembayaran tidak ditemukan.');
            $hdr['id'] = (int)$hdr['id'];
            $hdr['total_amount'] = (float)$hdr['total_amount'];
            $hdr['allocated_amount'] = (float)$hdr['allocated_amount'];
            $hdr['excess_amount'] = (float)$hdr['excess_amount'];
            $hdr['exch_rate'] = (float)$hdr['exch_rate'];
            $hdr['total_amount_cur'] = (float)$hdr['total_amount_cur'];

            $st = $db->prepare("
                SELECT i.id, i.invoice_id, v.inv_no, v.termin_name, v.invoice_date, v.percentage, v.amount AS invoice_amount,
                       p.id AS project_id, p.name AS project_name, i.amount_paid,
                       COALESCE(i.amount_cur, i.amount_paid) AS amount_cur,
                       (v.amount - COALESCE((SELECT SUM(x.amount_paid)
                                               FROM public.project_payment_items x
                                              WHERE x.invoice_id = v.id AND x.payment_id <= i.payment_id), 0)) AS sisa_after
                  FROM public.project_payment_items i
                  JOIN public.project_invoices v ON v.id = i.invoice_id
                  JOIN public.projects p ON p.id = v.project_id
                 WHERE i.payment_id = :id
                 ORDER BY v.invoice_date ASC, i.id ASC");
            $st->execute([':id' => $id]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['invoice_id'] = (int)$it['invoice_id'];
                $it['project_id'] = (int)$it['project_id'];
                $it['percentage'] = $it['percentage'] !== null ? (float)$it['percentage'] : null;
                $it['invoice_amount'] = (float)$it['invoice_amount'];
                $it['amount_paid'] = (float)$it['amount_paid'];
                $it['amount_cur'] = (float)$it['amount_cur'];
                $it['sisa_after'] = (float)$it['sisa_after'];
            }
            sendJsonResponse(true, ['header' => $hdr, 'items' => $items]);
            break;

        /* ------------------------------------------------------------------
         * Simpan pembayaran baru[cite: 5]
         * ------------------------------------------------------------------ */
        case 'save_payment':
            $cli    = (int)($_POST['client_id'] ?? 0);
            $date   = (string)($_POST['payment_date'] ?? '');
            $acc    = (int)($_POST['account_id'] ?? 0) ?: null;
            $method = strtoupper(trim((string)($_POST['payment_method'] ?? 'BANK_TRANSFER')));
            $total  = round((float)($_POST['total_amount'] ?? 0), 2);
            $ref    = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes  = trim((string)($_POST['notes'] ?? '')) ?: null;
            $items  = json_decode($_POST['items'] ?? '[]', true);
            $curIn  = (int)($_POST['currency_id'] ?? 0) ?: null;
            $rateIn = (float)($_POST['exch_rate'] ?? 1);

            try {
                if (!pp_valid_date($date)) throw new PpBusinessError('Tanggal pembayaran tidak valid.');
                if (!$acc) throw new PpBusinessError('Akun Kas/Bank wajib dipilih.');
                if ($cli <= 0) throw new PpBusinessError('Client wajib dipilih.');
                if (!in_array($method, PP_METHODS, true)) throw new PpBusinessError('Metode pembayaran tidak valid.');
                if (!is_array($items) || !count($items)) throw new PpBusinessError('Alokasikan pembayaran ke minimal satu termin.');

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT 1 FROM public.clients WHERE id = :id");
                    $st->execute([':id' => $cli]);
                    if (!$st->fetchColumn()) throw new PpBusinessError('Client tidak ditemukan.');
                    $clientId = $cli;

                    $st = $db->prepare("
                        SELECT id, UPPER(TRIM(init)) AS code FROM public.m_currency
                         WHERE id = COALESCE(CAST(:id AS bigint), (SELECT id FROM public.m_currency WHERE UPPER(TRIM(init)) = 'IDR' LIMIT 1))");
                    $st->execute([':id' => $curIn]);
                    $curRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$curRow) throw new PpBusinessError('Mata uang tidak ditemukan.');
                    $curId  = (int)$curRow['id'];
                    $isBase = ($curRow['code'] === 'IDR');
                    $rate   = $isBase ? 1.0 : round($rateIn, 4);
                    if (!$isBase && $rate <= 0) throw new PpBusinessError("Kurs {$curRow['code']} wajib diisi.");

                    if ($acc) {
                        $st = $db->prepare("SELECT 1 FROM public.chart_of_accounts WHERE id = :id");
                        $st->execute([':id' => $acc]);
                        if (!$st->fetchColumn()) throw new PpBusinessError('Akun kas/bank tidak ditemukan.');
                    }

                    $stInv = $db->prepare("
                        SELECT v.id, v.inv_no, v.project_id, v.amount, v.paid_amount,
                               p.client_id, p.name AS project_name
                          FROM public.project_invoices v
                          JOIN public.projects p ON p.id = v.project_id
                         WHERE v.id = :id FOR UPDATE OF v");
                    $clean = []; $seen = []; $allocated = 0.0; $allocatedIdr = 0.0;
                    foreach ($items as $n => $it) {
                        $invId = (int)($it['invoice_id'] ?? 0);
                        $pay   = round((float)($it['amount_paid'] ?? 0), 2);
                        $no    = 'Baris ' . ($n + 1);
                        if ($invId <= 0 || $pay <= 0) throw new PpBusinessError("{$no}: termin dan nilai bayar (> 0) wajib diisi.");
                        if (isset($seen[$invId])) throw new PpBusinessError("{$no}: termin dobel.");
                        $seen[$invId] = true;

                        $stInv->execute([':id' => $invId]);
                        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
                        if (!$inv) throw new PpBusinessError("{$no}: termin tidak ditemukan.");
                        if ((int)$inv['client_id'] !== $clientId) throw new PpBusinessError("{$no}: termin {$inv['inv_no']} ({$inv['project_name']}) bukan milik client ini.");
                        $sisa = round((float)$inv['amount'] - (float)$inv['paid_amount'], 2);
                        $payIdr = round($pay * $rate, 2);
                        if (abs($payIdr - $sisa) <= 0.005 * $rate + PP_EPS) $payIdr = $sisa;
                        if ($payIdr > $sisa + PP_EPS) {
                            throw new PpBusinessError("Termin {$inv['inv_no']} ({$inv['project_name']}): nilai bayar " . number_format($pay, 2) . " {$curRow['code']}" .
                                " (setara " . number_format($payIdr, 2) . " IDR) melebihi sisa tagihan (" . number_format($sisa, 2) . " IDR).");
                        }
                        $allocated    += $pay;
                        $allocatedIdr += $payIdr;
                        $clean[] = ['inv' => $invId, 'pay' => $payIdr, 'cur' => $pay];
                    }
                    $allocated = round($allocated, 2);
                    $allocatedIdr = round($allocatedIdr, 2);
                    if ($total <= 0) throw new PpBusinessError('Jumlah diterima harus lebih dari 0.');
                    if ($total + PP_EPS < $allocated) throw new PpBusinessError('Jumlah diterima lebih kecil dari total alokasi termin.');
                    $excessCur = round($total - $allocated, 2);
                    $excess    = round($excessCur * $rate, 2);
                    $totalIdr  = round($allocatedIdr + $excess, 2);

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $payNo = CreateRefNo('project_payments', 'payment_no', 'PPAY');

                    $st = $db->prepare("
                        INSERT INTO public.project_payments
                            (payment_no, client_id, account_id, payment_date, payment_method,
                             total_amount, allocated_amount, excess_amount, deposit_applied,
                             reference_no, notes, created_by, currency_id, exch_rate, total_amount_cur)
                        VALUES (:no, :c, :a, CAST(:d AS date), :m, :t, :al, :ex, 0, :r, :n, :u, :cur, :rate, :tc)
                        RETURNING id");
                    $st->execute([':no' => $payNo, ':c' => $clientId, ':a' => $acc, ':d' => $date, ':m' => $method,
                                  ':t' => $totalIdr, ':al' => $allocatedIdr, ':ex' => $excess, ':r' => $ref, ':n' => $notes,
                                  ':u' => (int)$_SESSION['user_id'] ?: null,
                                  ':cur' => $curId, ':rate' => $rate, ':tc' => $total]);
                    $payId = (int)$st->fetchColumn();

                    $stIns = $db->prepare("INSERT INTO public.project_payment_items (payment_id, invoice_id, amount_paid, amount_cur) VALUES (:p, :i, :a, :ac)");
                    $stUpd = $db->prepare("UPDATE public.project_invoices SET paid_amount = paid_amount + :a WHERE id = :i");
                    foreach ($clean as $c) {
                        $stIns->execute([':p' => $payId, ':i' => $c['inv'], ':a' => $c['pay'], ':ac' => $c['cur']]);
                        $stUpd->execute([':a' => $c['pay'], ':i' => $c['inv']]);
                    }

                    $stCli = $db->prepare("SELECT name FROM public.clients WHERE id = :id");
                    $stCli->execute([':id' => $clientId]);
                    $cliName = (string)$stCli->fetchColumn();
                    $jrnParts = [];
                    if ($allocatedIdr > PP_EPS) $jrnParts[] = ['PRJ_PAY_AR',  $allocatedIdr, "Pembayaran termin {$payNo} - {$cliName}"];
                    if ($excess > PP_EPS)       $jrnParts[] = ['PRJ_PAY_DEP', $excess,       "Lebih bayar (deposit) {$payNo} - {$cliName}"];
                    foreach ($jrnParts as $jp) {
                        $jr = AutoPostJournal($db, [
                            'rule_code'        => $jp[0],
                            'reference_type'   => PP_JOURNAL_REF,
                            'reference_id'     => $payId,
                            'journal_date'     => $date,
                            'amount'           => $jp[1],
                            'description'      => $jp[2],
                            'debit_account_id' => $acc,
                        ]);
                        if (!$jr['success']) throw new PpBusinessError('Gagal posting jurnal: ' . $jr['message']);
                    }

                    $db->commit();
                    sendJsonResponse(true, ['id' => $payId, 'payment_no' => $payNo], "Pembayaran {$payNo} berhasil disimpan.");
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (PpBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus termin[cite: 5]
         * ------------------------------------------------------------------ */
        case 'delete_termin':
            $id     = (int)($_POST['id'] ?? 0);
            $cliChk = (int)($_POST['client_id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("
                    SELECT v.inv_no, v.paid_amount, p.client_id
                      FROM public.project_invoices v
                      JOIN public.projects p ON p.id = v.project_id
                     WHERE v.id = :id FOR UPDATE OF v");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new PpBusinessError('Termin tidak ditemukan.');
                if ($cliChk > 0 && (int)$inv['client_id'] !== $cliChk) throw new PpBusinessError('Termin bukan milik client yang dipilih.');

                $st = $db->prepare("SELECT COUNT(*) FROM public.project_payment_items WHERE invoice_id = :id");
                $st->execute([':id' => $id]);
                if ((float)$inv['paid_amount'] > PP_EPS || (int)$st->fetchColumn() > 0) {
                    throw new PpBusinessError("Termin {$inv['inv_no']} sudah memiliki pembayaran, tidak dapat dihapus.");
                }

                if (pp_invoice_has_journal($db, $id)) ReverseJournalByReference($db, PP_INV_JOURNAL_REF, $id);
                $db->prepare("DELETE FROM public.project_invoices WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Termin {$inv['inv_no']} dihapus.");
            } catch (PpBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * [PPN] Isi / ubah faktur pajak keluaran sebuah termin
         * ------------------------------------------------------------------ */
        case 'update_faktur_pajak':
            $id     = (int)($_POST['id'] ?? 0);
            $cliChk = (int)($_POST['client_id'] ?? 0);
            $fpNo   = trim((string)($_POST['faktur_pajak_no'] ?? ''));
            $fpDate = trim((string)($_POST['faktur_pajak_date'] ?? ''));
            $db->beginTransaction();
            try {
                if ($fpDate !== '' && !pp_valid_date($fpDate)) throw new PpBusinessError('Tanggal faktur pajak tidak valid.');
                if (strlen($fpNo) > 30) throw new PpBusinessError('No. faktur pajak maksimal 30 karakter.');
                if ($fpNo === '' && $fpDate !== '') throw new PpBusinessError('Isi no. faktur pajak, atau kosongkan tanggalnya.');

                $st = $db->prepare("
                    SELECT v.inv_no, v.ppn_amount, p.client_id
                      FROM public.project_invoices v
                      JOIN public.projects p ON p.id = v.project_id
                     WHERE v.id = :id FOR UPDATE OF v");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new PpBusinessError('Termin tidak ditemukan.');
                if ($cliChk > 0 && (int)$inv['client_id'] !== $cliChk) throw new PpBusinessError('Termin bukan milik client yang dipilih.');
                if ((float)$inv['ppn_amount'] <= PP_EPS) throw new PpBusinessError("Termin {$inv['inv_no']} tidak memuat PPN, tidak ada faktur pajak.");

                if ($fpNo !== '') {                       // no. faktur pajak keluaran unik antar termin
                    $st = $db->prepare("SELECT inv_no FROM public.project_invoices WHERE faktur_pajak_no = :n AND id <> :id LIMIT 1");
                    $st->execute([':n' => $fpNo, ':id' => $id]);
                    $dup = $st->fetchColumn();
                    if ($dup) throw new PpBusinessError("No. faktur pajak {$fpNo} sudah dipakai termin {$dup}.");
                }
                $st = $db->prepare("UPDATE public.project_invoices
                                       SET faktur_pajak_no = NULLIF(:n, ''), faktur_pajak_date = CAST(NULLIF(:d, '') AS date)
                                     WHERE id = :id");
                $st->execute([':n' => $fpNo, ':d' => $fpDate, ':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], $fpNo === '' ? "Faktur pajak termin {$inv['inv_no']} dikosongkan." : "Faktur pajak termin {$inv['inv_no']} tersimpan.");
            } catch (PpBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus pembayaran[cite: 5]
         * ------------------------------------------------------------------ */
        case 'delete_payment':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT payment_no FROM public.project_payments WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $pay = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new PpBusinessError('Pembayaran tidak ditemukan.');

                $st = $db->prepare("SELECT invoice_id, amount_paid FROM public.project_payment_items WHERE payment_id = :id");
                $st->execute([':id' => $id]);
                $stUpd = $db->prepare("UPDATE public.project_invoices SET paid_amount = GREATEST(paid_amount - :a, 0) WHERE id = :i");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $stUpd->execute([':a' => $it['amount_paid'], ':i' => $it['invoice_id']]);
                }
                ReverseJournalByReference($db, PP_JOURNAL_REF, $id);
                $db->prepare("DELETE FROM public.project_payment_items WHERE payment_id = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM public.project_payments WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Pembayaran {$pay['payment_no']} dihapus dan saldo termin dikembalikan.");
            } catch (PpBusinessError $e) {
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
    error_log('[project.paymentCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[project.paymentCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}