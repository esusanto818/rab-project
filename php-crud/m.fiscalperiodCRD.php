<?php
/*
  Document      : Fiscal Period - CRUD Handler
  Called from   : js/m.fiscalperiod.js
  Note          : Mengelola tabel accounting_periods (id, period_name, start_date,
                  end_date, is_closed).
  [FIX 2026-10-08]:
   - is_closed boolean: PDO execute([...]) mengubah PHP false menjadi '' -> PostgreSQL
     error 22P02. Sekarang memakai literal SQL true/false, bukan bind dari PHP.
   - Semua respons dijamin JSON (try/catch global + header JSON).
   - Tutup buku dibungkus transaksi (BEGIN/COMMIT/ROLLBACK).
*/
ini_set('display_errors', '0');                     // [FIX] jangan bocorkan error HTML ke respons JSON
ob_start();                                         // [FIX] buffer agar output liar bisa dibuang

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

header('Content-Type: application/json');           // [FIX] selalu JSON

// [FIX] helper respons tunggal: bersihkan buffer lalu kirim JSON
function fp_json(array $payload, int $httpCode = 200) {
    if ($httpCode !== 200) { http_response_code($httpCode); }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// [FIX 2] normalisasi tanggal ke Y-m-d. Field tanggal UI menampilkan '01 Oct 26' (d M y),
// jadi terima beberapa format; kembalikan null bila tidak ada yang cocok.
function fp_normalize_date($str) {
    $str = trim((string)$str);
    if ($str === '') { return null; }
    foreach (['Y-m-d', 'd M y', 'd M Y', 'd/m/Y', 'd-m-Y'] as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $str);
        $err = DateTime::getLastErrors();
        if ($dt && (!$err || ($err['warning_count'] == 0 && $err['error_count'] == 0))) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    fp_json(["success" => false, "message" => "Session expired. Silakan login kembali."], 401);
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) fp_json(['success'=>false,'message'=>'Hak baca periode akuntansi tidak tersedia.'], 403);
if (in_array($task, ['create', 'close', 'reopen', 'delete'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fp_json(['success' => false, 'message' => 'Metode harus POST untuk perubahan periode akuntansi.'], 405);
}
$db   = new crud();

try {
    switch ($task) {

        // ==============================================================
        case 'read':
            $query = $_POST['query'] ?? null;
            $srch  = searchData($query, ['period_name']);

            $where = "1=1" . ($srch['searchSQL'] ?? '');
            $limit = $srch['limit'] ?? 250;

            $sql = "
                SELECT id, period_name, start_date, end_date, is_closed
                FROM accounting_periods
                WHERE {$where}
                ORDER BY start_date DESC
                LIMIT " . intval($limit);

            $stmt = $db->query($sql);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            fp_json(['success' => true, 'status' => 'Success', 'totalCount' => count($data), 'data' => $data]);
            break;

        // ==============================================================
        case 'read_edit':
            $id = intval($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM accounting_periods WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            fp_json(['success' => (bool)$row, 'data' => $row ?: new stdClass()]);
            break;

        // ==============================================================
        // Combo dropdown periode (hanya periode yang masih terbuka)
        case 'read_open':
            $stmt = $db->query("SELECT id, period_name FROM accounting_periods WHERE is_closed = false ORDER BY start_date");
            fp_json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==============================================================
        case 'create':
            $access = intval($_SESSION['FormAccess'] ?? 0);
            if ($access < 2) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            }

            $periodName = trim($_POST['period_name'] ?? '');
            // [FIX 2] utamakan start_ymd/end_ymd (Y-m-d dari JS), fallback ke field form (d M y dll)
            $rawStart   = trim($_POST['start_ymd'] ?? '');
            $rawEnd     = trim($_POST['end_ymd'] ?? '');
            $fldStart   = trim($_POST['start_date'] ?? '');
            $fldEnd     = trim($_POST['end_date'] ?? '');

            if ($periodName === '' || ($rawStart === '' && $fldStart === '') || ($rawEnd === '' && $fldEnd === '')) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Nama periode, tanggal mulai, dan tanggal selesai wajib diisi.']);
            }
            // [FIX 3] coba start_ymd dulu; bila tidak valid, lanjut ke nilai field form
            $startDate = fp_normalize_date($rawStart) ?? fp_normalize_date($fldStart);
            $endDate   = fp_normalize_date($rawEnd)   ?? fp_normalize_date($fldEnd);
            if ($startDate === null || $endDate === null) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Format tanggal tidak valid.']);
            }
            if (strtotime($endDate) < strtotime($startDate)) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
            }

            $stmtChk = $db->prepare("SELECT id FROM accounting_periods WHERE period_name = :name");
            $stmtChk->execute(['name' => $periodName]);
            if ($stmtChk->fetch()) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Periode dengan nama ini sudah ada.']);
            }

            // [FIX] cek tumpang tindih tanggal dengan periode lain
            $stmtOv = $db->prepare("
                SELECT period_name FROM accounting_periods
                 WHERE start_date <= CAST(:e AS date) AND end_date >= CAST(:s AS date) LIMIT 1");
            $stmtOv->execute([':s' => $startDate, ':e' => $endDate]);
            $ov = $stmtOv->fetch(PDO::FETCH_ASSOC);
            if ($ov) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => "Rentang tanggal tumpang tindih dengan periode '{$ov['period_name']}'."]);
            }

            // [FIX UTAMA] is_closed ditulis sebagai literal SQL false (bukan bind PHP false -> '' -> error 22P02)
            $stmtIns = $db->prepare("
                INSERT INTO accounting_periods (period_name, start_date, end_date, is_closed)
                VALUES (:n, CAST(:s AS date), CAST(:e AS date), false)");
            $stmtIns->execute([':n' => $periodName, ':s' => $startDate, ':e' => $endDate]);

            fp_json(['success' => true, 'status' => 'Success', 'message' => "Periode '{$periodName}' berhasil ditambahkan."]);
            break;

        // ==============================================================
        // Tutup Buku -- jurnal penutup (Pendapatan/Beban -> Laba Ditahan), lalu kunci periode.
        case 'close':
            $access = intval($_SESSION['FormAccess'] ?? 0);
            if ($access < 4) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Hanya akses tertinggi yang boleh menutup periode.']);
            }
            $id = intval($_POST['id'] ?? 0);

            $stmtPer = $db->prepare("SELECT period_name, start_date, end_date, is_closed FROM accounting_periods WHERE id = :id");
            $stmtPer->execute(['id' => $id]);
            $period = $stmtPer->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Periode tidak ditemukan.']);
            }
            if ($period['is_closed']) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Periode ini sudah ditutup.']);
            }

            // 1. Tidak boleh ada jurnal Draft di periode ini
            $stmtDraft = $db->prepare("SELECT COUNT(*) AS cnt FROM journal_entries WHERE period_id = :id AND status = 'draft'");
            $stmtDraft->execute(['id' => $id]);
            $draftCount = intval($stmtDraft->fetch(PDO::FETCH_ASSOC)['cnt']);
            if ($draftCount > 0) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => "Tidak bisa tutup buku: masih ada {$draftCount} jurnal berstatus Draft di periode ini. Post atau hapus dulu sebelum menutup periode."]);
            }

            // 2. Akun Laba Ditahan (kode 3200, fallback nama)
            $stmtRE = $db->prepare("SELECT id, account_name FROM chart_of_accounts WHERE account_code = '3200' AND is_active = true LIMIT 1");
            $stmtRE->execute();
            $reAccount = $stmtRE->fetch(PDO::FETCH_ASSOC);
            if (!$reAccount) {
                $stmtRE2 = $db->prepare("SELECT id, account_name FROM chart_of_accounts WHERE account_type = 'equity' AND account_name ILIKE '%laba ditahan%' AND is_active = true LIMIT 1");
                $stmtRE2->execute();
                $reAccount = $stmtRE2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$reAccount) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Akun Laba Ditahan tidak ditemukan (cari kode 3200 atau nama mengandung "Laba Ditahan"). Buat akun ini dulu di Chart of Accounts sebelum tutup buku.']);
            }
            $retainedEarningsId = $reAccount['id'];

            // 3. Saldo Pendapatan & Beban khusus periode ini
            $sqlIS = "
                SELECT c.id, c.account_type,
                       COALESCE(SUM(
                           CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                                ELSE l.debit - l.credit END
                       ), 0) AS amount
                FROM chart_of_accounts c
                JOIN journal_lines l ON l.account_id = c.id
                JOIN journal_entries j ON j.id = l.journal_id
                    AND j.status IN ('posted', 'reversed')
                    AND j.journal_date BETWEEN :start_date AND :end_date
                WHERE c.is_active = true AND c.account_type IN ('revenue', 'expense')
                GROUP BY c.id, c.account_type
                HAVING COALESCE(SUM(CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) != 0
            ";
            $stmtIS = $db->prepare($sqlIS);
            $stmtIS->execute(['start_date' => $period['start_date'], 'end_date' => $period['end_date']]);
            $isRows = $stmtIS->fetchAll(PDO::FETCH_ASSOC);

            $closingLines = [];
            $revenueTotal = 0;
            $expenseTotal = 0;
            foreach ($isRows as $r) {
                $amt = floatval($r['amount']);
                if ($r['account_type'] === 'revenue') {
                    $closingLines[] = ['account_id' => $r['id'], 'debit' => $amt, 'credit' => 0];   // nolkan revenue
                    $revenueTotal += $amt;
                } else {
                    $closingLines[] = ['account_id' => $r['id'], 'debit' => 0, 'credit' => $amt];   // nolkan expense
                    $expenseTotal += $amt;
                }
            }

            $netIncome    = $revenueTotal - $expenseTotal;
            $closingRefNo = null;

            if (!empty($closingLines)) {
                // Baris penyeimbang ke Laba Ditahan
                if ($netIncome >= 0) {
                    $closingLines[] = ['account_id' => $retainedEarningsId, 'debit' => 0, 'credit' => $netIncome];
                } else {
                    $closingLines[] = ['account_id' => $retainedEarningsId, 'debit' => abs($netIncome), 'credit' => 0];
                }

                $sumDebit  = array_sum(array_column($closingLines, 'debit'));
                $sumCredit = array_sum(array_column($closingLines, 'credit'));
                if (abs($sumDebit - $sumCredit) > 0.01) {
                    fp_json(['success' => false, 'status' => 'Error', 'message' => 'Perhitungan jurnal penutup tidak balance, tutup buku dibatalkan. Hubungi administrator.']);
                }
            }

            // [FIX] seluruh penulisan (jurnal penutup + kunci periode) dalam satu transaksi
            $db->beginTransaction();
            try {
                if (!empty($closingLines)) {
                    $closingRefNo = CreateRefNo('journal_entries', 'ref_no', 'JCL');

                    // [FIX] prepared statement + RETURNING id (tanpa query ulang berdasar ref_no)
                    $stmtJe = $db->prepare("
                        INSERT INTO journal_entries
                            (ref_no, journal_date, period_id, reference_type, reference_id, description, status, posted_by, posted_at)
                        VALUES
                            (:ref, CAST(:jd AS date), :pid, :rt, 0, :descr, 'posted', :uid, NOW())
                        RETURNING id");
                    $stmtJe->execute([
                        ':ref'   => $closingRefNo,
                        ':jd'    => $period['end_date'],
                        ':pid'   => $id,
                        ':rt'    => 'closing_entry',      // pastikan nilai ini ada di enum reference_type
                        ':descr' => 'Jurnal Penutup - ' . $period['period_name'],
                        ':uid'   => $_SESSION['user_id'],
                    ]);
                    $closingJournalId = (int)$stmtJe->fetchColumn();
                    if ($closingJournalId <= 0) { throw new Exception('Gagal membuat header jurnal penutup.'); }

                    $stmtJl = $db->prepare("
                        INSERT INTO journal_lines (journal_id, account_id, debit, credit, description)
                        VALUES (:jid, :acc, :d, :c, :descr)");
                    foreach ($closingLines as $l) {
                        $stmtJl->execute([
                            ':jid'   => $closingJournalId,
                            ':acc'   => $l['account_id'],
                            ':d'     => round($l['debit'], 2),
                            ':c'     => round($l['credit'], 2),
                            ':descr' => 'Tutup buku ' . $period['period_name'],
                        ]);
                    }
                }

                // 4. Kunci periode  [FIX] literal true
                $stmtLock = $db->prepare("UPDATE accounting_periods SET is_closed = true WHERE id = :id");
                $stmtLock->execute([':id' => $id]);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;     // ditangkap catch global -> JSON error
            }

            $msg = $closingRefNo
                ? "Periode berhasil ditutup. Jurnal penutup: {$closingRefNo} (Laba/Rugi periode: " . number_format($netIncome, 2, ',', '.') . ")"
                : "Periode berhasil ditutup. Tidak ada aktivitas Pendapatan/Beban di periode ini, jurnal penutup tidak diperlukan.";
            fp_json(['success' => true, 'message' => $msg]);
            break;

        // ==============================================================
        case 'reopen':
            $access = intval($_SESSION['FormAccess'] ?? 0);
            if ($access < 4) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Hanya akses tertinggi yang boleh membuka kembali periode.']);
            }
            $id = intval($_POST['id'] ?? 0);

            $stmtChk = $db->prepare("SELECT COUNT(*) AS cnt FROM journal_entries WHERE period_id = :id AND reference_type = 'closing_entry' AND status = 'posted'");
            $stmtChk->execute(['id' => $id]);
            $hasClosing = intval($stmtChk->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;

            // [FIX] literal false (sebelumnya $db->update(... false) -> '' -> error 22P02)
            $stmtOpen = $db->prepare("UPDATE accounting_periods SET is_closed = false WHERE id = :id");
            $stmtOpen->execute([':id' => $id]);

            $msg = 'Periode berhasil dibuka kembali.';
            if ($hasClosing) {
                $msg .= ' PERHATIAN: periode ini punya jurnal penutup yang sudah posting -- kalau mau input jurnal baru lagi di periode ini, reverse dulu jurnal penutupnya lewat menu Journal Entry supaya tidak double-hitung Laba Ditahan saat tutup buku berikutnya.';
            }
            fp_json(['success' => true, 'message' => $msg]);
            break;

        // ==============================================================
        case 'delete':
            $access = intval($_SESSION['FormAccess'] ?? 0);
            if ($access < 4) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Akses ditolak.']);
            }
            $id = intval($_POST['id'] ?? 0);

            $stmtChk = $db->prepare("SELECT is_closed FROM accounting_periods WHERE id = :id");
            $stmtChk->execute(['id' => $id]);
            $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Periode tidak ditemukan.']);
            }
            if ($row['is_closed']) {
                fp_json(['success' => false, 'status' => 'Error', 'message' => 'Periode yang sudah ditutup tidak boleh dihapus.']);
            }

            // [FIX] prepared statement (sebelumnya interpolasi string)
            $stmtDel = $db->prepare("DELETE FROM accounting_periods WHERE id = :id");
            $stmtDel->execute([':id' => $id]);
            fp_json(['success' => true, 'message' => 'Periode berhasil dihapus.']);
            break;

        // ==============================================================
        default:
            fp_json(['success' => false, 'status' => 'Error', 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
    }

} catch (PDOException $e) {
    // [FIX] error DB -> log + JSON (tidak lagi fatal error HTML)
    error_log('[m.fiscalperiodCRD] ' . $e->getMessage());
    fp_json(['success' => false, 'status' => 'Error', 'message' => 'Database Error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[m.fiscalperiodCRD] ' . $e->getMessage());
    fp_json(['success' => false, 'status' => 'Error', 'message' => 'System Error: ' . $e->getMessage()]);
}