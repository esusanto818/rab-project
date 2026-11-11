<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/*
  Document      : Journal Entry - CRUD Handler
  Called from   : js/journal.entry.js
  Note          : - Jurnal manual pakai reference_type = 'manual_entry',
                    reference_id = 0 (tidak terkait dokumen sumber lain).
                  - Jurnal hanya boleh dibuat/diedit selagi status = 'draft'
                    dan periode masih Open.
                  - 'Batalkan posting' dilakukan lewat REVERSE: membuat jurnal
                    baru dengan baris debit/kredit dibalik, lalu status jurnal
                    asal diubah jadi 'reversed'. Ini menjaga jejak audit,
                    bukan menghapus/mengubah data historis.
                  - Total debit/kredit dihitung on-the-fly dari journal_lines,
                    tidak disimpan terpisah di header (sesuai struktur asli).
                  - Periode akuntansi pakai tabel accounting_periods.
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca jurnal tidak tersedia.']); exit; }
if (in_array($task, ['create', 'update', 'post', 'reverse', 'delete'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode harus POST untuk perubahan jurnal.']);
    exit;
}
$db   = new crud();

switch ($task) {

    // ==============================================================
    case 'read':
        $query = $_POST['query'] ?? null;
        $srch  = searchData($query, ['j.ref_no', 'j.description']);

        $where = "j.reference_type = 'manual_entry' or j.reference_type = 'closing_entry'" . ($srch['searchSQL'] ?? '');
        $limit = $srch['limit'] ?? 250;

        $sql = "
            SELECT j.id, j.ref_no, j.journal_date, j.period_id, p.period_name,
                   j.description, j.status,
                   COALESCE((SELECT SUM(debit)  FROM journal_lines WHERE journal_id = j.id), 0) AS total_debit,
                   COALESCE((SELECT SUM(credit) FROM journal_lines WHERE journal_id = j.id), 0) AS total_credit
            FROM journal_entries j
            LEFT JOIN accounting_periods p ON p.id = j.period_id
            
            ORDER BY j.journal_date DESC, j.id DESC
            LIMIT " . intval($limit);

        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'status' => 'Success', 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    case 'read_edit':
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM journal_entries WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => (bool)$row, 'data' => $row ?: new stdClass()]);
        break;

    // ==============================================================
    case 'read_lines':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => true, 'totalCount' => 0, 'data' => []]);
            exit;
        }
        $stmt = $db->prepare("
            SELECT l.id AS line_id, l.account_id, c.account_code, c.account_name,
                   l.debit, l.credit, l.description
            FROM journal_lines l
            LEFT JOIN chart_of_accounts c ON c.id = l.account_id
            WHERE l.journal_id = :id
            ORDER BY l.id ASC
        ");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;

    // ==============================================================
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        saveJournal($db, null, $_POST);
        break;

    // ==============================================================
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID jurnal tidak valid.']);
            exit;
        }

        $stmtChk = $db->prepare("SELECT status FROM journal_entries WHERE id = :id");
        $stmtChk->execute(['id' => $id]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] !== 'draft') {
            echo json_encode(['success' => false, 'message' => 'Hanya jurnal berstatus Draft yang boleh diedit.']);
            exit;
        }

        saveJournal($db, $id, $_POST);
        break;

    // ==============================================================
     case 'post':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $userId = $_SESSION['user_id'];
 
        $stmtUpd = $db->prepare("
            UPDATE journal_entries
            SET status = 'posted', posted_by = :uid, posted_at = now()
            WHERE id = :id AND status = 'draft'
        ");
        $stmtUpd->execute(['uid' => $userId, 'id' => $id]);
 
        if ($stmtUpd->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Gagal posting -- data tidak ditemukan atau status sudah bukan Draft (mungkin sudah di-post/reload halaman lain).']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Jurnal berhasil di-post.']);
        break;


    
    // ==============================================================
    case 'reverse':
        $access = (int)($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Hanya akses tertinggi yang boleh reverse jurnal.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID jurnal tidak valid.');

        $db->beginTransaction();
        try {
            // Lock source journal so concurrent requests cannot create duplicate reversals.
            $stmtChk = $db->prepare("SELECT * FROM journal_entries WHERE id = :id FOR UPDATE");
            $stmtChk->execute([':id' => $id]);
            $original = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$original || $original['status'] !== 'posted') {
                throw new Exception('Hanya jurnal berstatus Posted yang bisa di-reverse.');
            }

            $stmtP = $db->prepare("SELECT is_closed FROM accounting_periods WHERE id = :id FOR UPDATE");
            $stmtP->execute([':id' => $original['period_id']]);
            $period = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$period || $period['is_closed']) {
                throw new Exception('Periode jurnal asal tidak ditemukan atau sudah ditutup; reverse tidak diizinkan.');
            }

            $reverseNo = CreateRefNo('journal_entries', 'ref_no', 'JE');
            $stmtNew = $db->prepare("
                INSERT INTO journal_entries
                    (ref_no, journal_date, period_id, reference_type, reference_id,
                     description, status, posted_by, posted_at)
                VALUES
                    (:no, CURRENT_DATE, :period, 'manual_entry', 0,
                     :description, 'posted', :uid, NOW())
                RETURNING id
            ");
            $stmtNew->execute([
                ':no' => $reverseNo,
                ':period' => (int)$original['period_id'],
                ':description' => 'Reversal dari ' . $original['ref_no']
                    . (empty($original['description']) ? '' : ' - ' . $original['description']),
                ':uid' => (int)$_SESSION['user_id']
            ]);
            $newId = (int)$stmtNew->fetchColumn();
            if ($newId <= 0) throw new Exception('Gagal membuat jurnal reversal.');

            $stmtLines = $db->prepare("SELECT account_id, debit, credit, description FROM journal_lines WHERE journal_id = :id");
            $stmtLines->execute([':id' => $id]);
            $origLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
            if (!$origLines) throw new Exception('Jurnal asal tidak memiliki detail; reversal dibatalkan.');

            $insertLine = $db->prepare("
                INSERT INTO journal_lines (journal_id, account_id, debit, credit, description)
                VALUES (:journal_id, :account_id, :debit, :credit, :description)
            ");
            foreach ($origLines as $line) {
                $insertLine->execute([
                    ':journal_id' => $newId,
                    ':account_id' => $line['account_id'],
                    ':debit' => $line['credit'],
                    ':credit' => $line['debit'],
                    ':description' => $line['description']
                ]);
            }

            $markOriginal = $db->prepare("UPDATE journal_entries SET status = 'reversed' WHERE id = :id AND status = 'posted'");
            $markOriginal->execute([':id' => $id]);
            if ($markOriginal->rowCount() !== 1) throw new Exception('Status jurnal asal berubah; reversal dibatalkan.');

            $db->commit();
            echo json_encode(['success' => true, 'message' => "Jurnal berhasil di-reverse. Jurnal koreksi: {$reverseNo}"]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        break;

    case 'delete':
        $access = (int)($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('ID jurnal tidak valid.');

        $db->beginTransaction();
        try {
            $stmtChk = $db->prepare("SELECT status FROM journal_entries WHERE id = :id FOR UPDATE");
            $stmtChk->execute([':id' => $id]);
            $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Jurnal tidak ditemukan.');
            if ($row['status'] !== 'draft') {
                throw new Exception('Hanya jurnal berstatus Draft yang boleh dihapus.');
            }

            $db->prepare("DELETE FROM journal_lines WHERE journal_id = :id")->execute([':id' => $id]);
            $deleteHeader = $db->prepare("DELETE FROM journal_entries WHERE id = :id AND status = 'draft'");
            $deleteHeader->execute([':id' => $id]);
            if ($deleteHeader->rowCount() !== 1) throw new Exception('Jurnal gagal dihapus; status mungkin telah berubah.');

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Jurnal berhasil dihapus.']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali: ' . htmlspecialchars($task)]);
}

function saveJournal($db, $id, $post) {
    $journalDate = trim($post['journal_date'] ?? '');
    $periodId    = intval($post['period_id'] ?? 0);
    $desc        = $post['description'] ?? null;
    $linesJson   = $post['arrLines'] ?? '[]';
    $lines       = json_decode($linesJson, true);

    if (empty($journalDate)) {
        echo json_encode(['success' => false, 'message' => 'Tanggal jurnal wajib diisi.']);
        return;
    }
    if ($periodId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Periode akuntansi wajib dipilih.']);
        return;
    }

    $stmtP = $db->prepare("SELECT is_closed FROM accounting_periods WHERE id = :id");
    $stmtP->execute(['id' => $periodId]);
    $period = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        echo json_encode(['success' => false, 'message' => 'Periode akuntansi tidak ditemukan.']);
        return;
    }
    if ($period['is_closed']) {
        echo json_encode(['success' => false, 'message' => 'Periode ini sudah ditutup, tidak bisa menambah jurnal.']);
        return;
    }

    if (!is_array($lines) || count($lines) < 2) {
        echo json_encode(['success' => false, 'message' => 'Jurnal minimal harus punya 2 baris (debit & kredit).']);
        return;
    }

    $totalDebit = 0;
    $totalCredit = 0;
    $validLines = [];
    foreach ($lines as $l) {
        $accId  = intval($l['account_id'] ?? 0);
        $debit  = Str2N($l['debit'] ?? 0);
        $credit = Str2N($l['credit'] ?? 0);
        if ($accId <= 0) continue;
        if ($debit == 0 && $credit == 0) continue;
        if ($debit > 0 && $credit > 0) {
            echo json_encode(['success' => false, 'message' => 'Satu baris tidak boleh diisi debit dan kredit sekaligus.']);
            return;
        }
        $totalDebit  += $debit;
        $totalCredit += $credit;
        $validLines[] = ['account_id' => $accId, 'debit' => $debit, 'credit' => $credit, 'description' => $l['description'] ?? null];
    }

    if (count($validLines) < 2) {
        echo json_encode(['success' => false, 'message' => 'Jurnal minimal harus punya 2 baris valid (ada akun & nominal).']);
        return;
    }

    if (abs($totalDebit - $totalCredit) > 0.01) {
        echo json_encode(['success' => false, 'message' => "Jurnal tidak balance! Total Debit: {$totalDebit}, Total Kredit: {$totalCredit}"]);
        return;
    }

    $headerData = [
        'journal_date' => $journalDate,
        'period_id'    => $periodId,
        'description'  => $desc
    ];

    if ($id === null) {
        $journalNo = CreateRefNo('journal_entries', 'ref_no', 'JE');
        $headerData['ref_no']     = $journalNo;
        $headerData['reference_type'] = 'manual_entry';
        $headerData['reference_id']   = 0;
        $headerData['status']         = 'draft';

        $db->insert('journal_entries', $headerData);

        $stmtId = $db->prepare("SELECT id FROM journal_entries WHERE ref_no = :no");
        $stmtId->execute(['no' => $journalNo]);
        $newId = $stmtId->fetch(PDO::FETCH_ASSOC)['id'] ?? null;

        if ($newId) {
            insertLines($db, $newId, $validLines);
        }
        echo json_encode(['success' => true, 'message' => "Jurnal {$journalNo} berhasil disimpan."]);
    } else {
        $db->update('journal_entries', $headerData, "id = {$id}");
        $db->query("DELETE FROM journal_lines WHERE journal_id = {$id}");
        insertLines($db, $id, $validLines);
        echo json_encode(['success' => true, 'message' => 'Jurnal berhasil diperbarui.']);
    }
}

function insertLines($db, $journalId, $lines) {
    foreach ($lines as $l) {
        $db->insert('journal_lines', [
            'journal_id'  => $journalId,
            'account_id'  => $l['account_id'],
            'debit'       => $l['debit'],
            'credit'      => $l['credit'],
            'description' => $l['description']
        ]);
    }
}