<?php
/**
 * project.progressCRD.php
 * ============================================================
 * Backend Progress Proyek (level proyek) -- project.progress.js
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'], respons JSON {success, message, data}.
 *
 * Tujuan: progress proyek (projects.progress) TIDAK lagi bisa diketik bebas. Alurnya:
 *   lapangan mengajukan klaim (persen + bukti) -> orang LAIN memverifikasi / menolak -> baru projects.progress berubah.
 *
 * Pembanding otomatis (Earned Value sederhana, semua dalam mata uang dasar):
 *   Nilai hasil  = Budget (x kurs anggaran) x progress terverifikasi
 *   Biaya aktual = pemakaian material (issue PROJECT, posted) + biaya non-material (financial_expenses)
 *   Indeks biaya = Nilai hasil / Biaya aktual   (< PROG_CPI_YELLOW kuning, < PROG_CPI_RED merah)
 *
 * Aturan:
 *  1. Lapor progress   : akses >= PROG_ACCESS_REPORT; proyek harus berstatus Berjalan / Tertunda.
 *  2. Klaim            : persen harus LEBIH BESAR dari progress terverifikasi, maks 100; bukti wajib (PROG_REQUIRE_EVIDENCE);
 *                        hanya satu klaim yang boleh menunggu verifikasi per proyek.
 *  3. Verifikasi/tolak : akses >= PROG_ACCESS_VERIFY; pemverifikasi tidak boleh pelapor sendiri (PROG_ALLOW_SELF_VERIFY = false);
 *                        tolak wajib catatan; setujui saat indeks biaya MERAH wajib catatan. Saat disetujui, posisi
 *                        budget / biaya / indeks disimpan sebagai jejak audit (snap_*).
 *  4. Batal            : pelapor (atau akses verifikasi) dapat membatalkan klaim yang masih Diajukan.
 *  5. Bukti          : boleh berupa file unggahan (foto / PDF, maks 5 file x 5 MB -> /rab-project/uploads/progress/) dan/atau teks (no. BAP).
 *                      Beberapa bukti disimpan satu per baris di evidence_ref (butuh kolom text: sql/2026-10-13.progress_evidence.sql).
 *  Butuh sql/2026-10-12.project_progress.sql.
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

const PROG_ACCESS_REPORT    = 2;
const PROG_ACCESS_VERIFY    = 3;
const PROG_CPI_YELLOW       = 0.90;     // indeks biaya di bawah ini -> KUNING
const PROG_CPI_RED          = 0.80;     // indeks biaya di bawah ini -> MERAH
const PROG_REQUIRE_EVIDENCE = true;     // bukti (no. foto / BAP / link) wajib pada klaim
const PROG_ALLOW_SELF_VERIFY = false;   // false = pelapor tidak boleh memverifikasi klaimnya sendiri

class ProgBusinessError extends Exception {}

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

function prog_fmt($n): string { return number_format((float)$n, 2, ',', '.'); }

function prog_text($s, int $len): string {
    $s = trim((string)$s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $chars === false ? substr($s, 0, $len) : implode('', array_slice($chars, 0, $len));
}

function prog_need_access(int $min, string $what) {
    if (intval($_SESSION['FormAccess'] ?? 0) < $min) {
        throw new ProgBusinessError("Anda tidak memiliki hak akses untuk {$what}.");
    }
}

/** Status indikator dari indeks biaya. */
function prog_status(float $budgetBase, float $actual, ?float $cpi): string {
    if ($budgetBase <= 0) return 'NO BUDGET';
    if ($actual <= 0 || $cpi === null) return '-';
    if ($cpi < PROG_CPI_RED) return 'MERAH';
    if ($cpi < PROG_CPI_YELLOW) return 'KUNING';
    return 'OK';
}

/**
 * Metrik per proyek (satu proyek bila $projectId > 0). $progressOverride = hitung seolah-olah progress sebesar itu
 * (dipakai saat verifikasi untuk menghitung indeks pada progress yang diklaim).
 */
function prog_metrics($db, int $projectId = 0, string $query = '', ?int $progressOverride = null): array {
    $where = []; $params = [];
    if ($projectId > 0) { $where[] = 'p.id = :id'; $params[':id'] = $projectId; }
    if ($query !== '') { $where[] = 'p.name ILIKE :q'; $params[':q'] = '%' . $query . '%'; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $db->prepare("
        WITH iss AS (
            SELECT moh.project_id, SUM(moi.qty_out * moi.unit_cost) AS c
              FROM material_out_items moi
              JOIN material_out_header moh ON moh.id = moi.material_out_id AND moh.status = 'posted' AND moh.issue_type = 'PROJECT'
             GROUP BY moh.project_id
        ), oth AS (
            SELECT project_id, SUM(amount) AS c
              FROM financial_expenses
             WHERE project_id IS NOT NULL AND UPPER(COALESCE(status, '')) NOT IN ('CANCELLED', 'CANCELED', 'VOID', 'DIBATALKAN')
             GROUP BY project_id
        ), pend AS (
            SELECT project_id, COUNT(*) AS n FROM project_progress_log WHERE status = 'Diajukan' GROUP BY project_id
        )
        SELECT p.id, p.name, p.status, COALESCE(p.progress, 0) AS progress,
               COALESCE(p.budget, 0) * COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_base,
               COALESCE(p.contract_value, 0) AS contract_value,
               COALESCE(iss.c, 0) AS cost_material, COALESCE(oth.c, 0) AS cost_other,
               COALESCE(pend.n, 0) AS pending_count
          FROM projects p
          LEFT JOIN iss ON iss.project_id = p.id
          LEFT JOIN oth ON oth.project_id = p.id
          LEFT JOIN pend ON pend.project_id = p.id
          {$w}
         ORDER BY p.id DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['progress'] = $progressOverride !== null ? $progressOverride : (int)$r['progress'];
        $r['pending_count'] = (int)$r['pending_count'];
        $bb = round((float)$r['budget_base'], 2);
        $cm = round((float)$r['cost_material'], 2);
        $co = round((float)$r['cost_other'], 2);
        $actual = round($cm + $co, 2);
        $ev = round($bb * $r['progress'] / 100, 2);
        $cpi = $actual > 0 ? round($ev / $actual, 4) : null;
        $r['budget_base'] = $bb; $r['contract_value'] = (float)$r['contract_value'];
        $r['cost_material'] = $cm; $r['cost_other'] = $co; $r['cost_actual'] = $actual;
        $r['earned_value'] = $ev;
        $r['cost_pct'] = $bb > 0 ? round($actual / $bb * 100, 2) : null;
        $r['cpi'] = $cpi;
        $r['cpi_status'] = prog_status($bb, $actual, $cpi);
    }
    return $rows;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}
$userId = (int)$_SESSION['user_id'];
$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Daftar proyek + indikator progress vs biaya
         * ------------------------------------------------------------------ */
        case 'list_projects':
            $rows = prog_metrics($db, 0, trim((string)($_REQUEST['query'] ?? '')));
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)], '',
                             ['thresholds' => ['yellow' => PROG_CPI_YELLOW, 'red' => PROG_CPI_RED]]);
            break;

        /* ------------------------------------------------------------------
         * Riwayat klaim satu proyek
         * ------------------------------------------------------------------ */
        case 'list_log':
            $pid = (int)($_REQUEST['project_id'] ?? 0);
            if ($pid <= 0) throw new ProgBusinessError('Proyek tidak valid.');
            $st = $db->prepare("
                SELECT l.id, l.project_id, l.report_date, l.percent_claimed, l.prev_percent, l.reported_by,
                       COALESCE(er.employee_name, ur.login_name) AS reporter_name,
                       l.evidence_ref, l.note, l.status, l.verified_by,
                       COALESCE(ev.employee_name, uv.login_name) AS verifier_name,
                       l.verified_at, l.verify_note, l.snap_cost_base, l.snap_cpi, l.created_at
                  FROM project_progress_log l
                  LEFT JOIN m_user_system ur ON ur.id = l.reported_by
                  LEFT JOIN m_employee er ON er.id = ur.employee_id
                  LEFT JOIN m_user_system uv ON uv.id = l.verified_by
                  LEFT JOIN m_employee ev ON ev.id = uv.employee_id
                 WHERE l.project_id = :p
                 ORDER BY l.report_date DESC, l.id DESC");
            $st->execute([':p' => $pid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['percent_claimed'] = (int)$r['percent_claimed'];
                $r['prev_percent'] = (int)$r['prev_percent'];
                $r['reported_by'] = $r['reported_by'] !== null ? (int)$r['reported_by'] : null;
                $r['snap_cost_base'] = $r['snap_cost_base'] !== null ? (float)$r['snap_cost_base'] : null;
                $r['snap_cpi'] = $r['snap_cpi'] !== null ? (float)$r['snap_cpi'] : null;
            }
            sendJsonResponse(true, $rows, '', ['user_id' => $userId, 'allow_self_verify' => PROG_ALLOW_SELF_VERIFY]);      // [CHANGED] + flag untuk tampilan tombol
            break;

        /* ------------------------------------------------------------------
         * [NEW] Unggah bukti progress (foto / PDF, maks 5 MB) -> /rab-project/uploads/progress/, kembalikan URL.
         *       Pola sama dengan upload bukti pengiriman di warehouse.issuingCRD.php.
         * ------------------------------------------------------------------ */
        case 'upload_evidence':
            prog_need_access(PROG_ACCESS_REPORT, 'mengunggah bukti progress');
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $code = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : -1;
                throw new ProgBusinessError('File gagal diunggah (kode ' . $code . '). Cek batas upload_max_filesize / post_max_size di php.ini.');
            }
            $f = $_FILES['file'];
            if ($f['size'] > 5 * 1024 * 1024) throw new ProgBusinessError('Ukuran file maksimal 5 MB.');
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                        'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext])) throw new ProgBusinessError('Tipe file tidak diizinkan. Gunakan JPG, PNG, WEBP, GIF atau PDF.');
            if (function_exists('finfo_open')) {                 // cek isi file, bukan hanya ekstensi
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
                if ($mime !== $allowed[$ext]) throw new ProgBusinessError('Isi file tidak sesuai dengan ekstensinya.');
            }
            $dir = dirname(__DIR__) . '/uploads/progress';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new ProgBusinessError('Folder uploads/progress tidak bisa dibuat. Buat manual dan beri izin tulis untuk user web server.');
            }
            $ht = $dir . '/.htaccess';                           // larang eksekusi script di folder upload
            if (!file_exists($ht)) {
                @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|pl|cgi)$\">\n    Require all denied\n</FilesMatch>\n");
            }
            $name = 'prg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                throw new ProgBusinessError('Gagal menyimpan file. Cek izin tulis folder uploads/progress.');
            }
            sendJsonResponse(true, ['url' => '/rab-project/uploads/progress/' . $name, 'name' => $name]);
            break;

        /* ------------------------------------------------------------------
         * Ajukan klaim progress
         * ------------------------------------------------------------------ */
        case 'submit_claim':
            prog_need_access(PROG_ACCESS_REPORT, 'melaporkan progress');
            $pid  = (int)($_POST['project_id'] ?? 0);
            $date = (string)($_POST['report_date'] ?? '');
            $pct  = (int)round((float)($_POST['percent_claimed'] ?? -1));
            $note = prog_text($_POST['note'] ?? '', 1000);
            $evid = prog_text($_POST['evidence_ref'] ?? '', 2000);      // [CHANGED] banyak bukti (URL + teks), satu per baris

            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) throw new ProgBusinessError('Tanggal laporan tidak valid.');
            if ($date > date('Y-m-d')) throw new ProgBusinessError('Tanggal laporan tidak boleh di masa depan.');
            if ($pct < 0 || $pct > 100) throw new ProgBusinessError('Persen progress harus antara 0 - 100.');
            if (PROG_REQUIRE_EVIDENCE && $evid === '') throw new ProgBusinessError('Bukti (no. foto / BAP / link) wajib diisi.');

            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT name, status, COALESCE(progress, 0) AS progress FROM projects WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $pid]);
                $pj = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pj) throw new ProgBusinessError('Proyek tidak ditemukan.');
                if (!in_array($pj['status'], ['Berjalan', 'Tertunda'], true)) {
                    throw new ProgBusinessError("Progress hanya bisa dilaporkan untuk proyek berstatus Berjalan / Tertunda (status sekarang: {$pj['status']}).");
                }
                $cur = (int)$pj['progress'];
                if ($pct <= $cur) {
                    throw new ProgBusinessError("Klaim {$pct}% harus lebih besar dari progress terverifikasi saat ini ({$cur}%).");
                }
                $st = $db->prepare("SELECT 1 FROM project_progress_log WHERE project_id = :p AND status = 'Diajukan'");
                $st->execute([':p' => $pid]);
                if ($st->fetchColumn()) throw new ProgBusinessError('Masih ada klaim progress yang menunggu verifikasi. Verifikasi / batalkan dulu.');

                $st = $db->prepare("
                    INSERT INTO project_progress_log (project_id, report_date, percent_claimed, prev_percent, reported_by, note, evidence_ref, status)
                    VALUES (:p, CAST(:d AS date), :pct, :prev, :u, :n, :e, 'Diajukan') RETURNING id");
                $st->execute([':p' => $pid, ':d' => $date, ':pct' => $pct, ':prev' => $cur, ':u' => $userId,
                              ':n' => $note !== '' ? $note : null, ':e' => $evid !== '' ? $evid : null]);
                $id = (int)$st->fetchColumn();
                $db->commit();
                sendJsonResponse(true, ['id' => $id], "Klaim progress {$pct}% untuk {$pj['name']} diajukan, menunggu verifikasi.");
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Verifikasi / tolak klaim
         * ------------------------------------------------------------------ */
        case 'verify':
            prog_need_access(PROG_ACCESS_VERIFY, 'memverifikasi progress');
            $id       = (int)($_POST['id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            $vnote    = prog_text($_POST['verify_note'] ?? '', 1000);
            if (!in_array($decision, ['Diverifikasi', 'Ditolak'], true)) throw new ProgBusinessError('Keputusan tidak valid.');

            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM project_progress_log WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $cl = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cl) throw new ProgBusinessError('Klaim tidak ditemukan.');
                if ($cl['status'] !== 'Diajukan') throw new ProgBusinessError("Klaim ini sudah berstatus {$cl['status']}.");
                if (!PROG_ALLOW_SELF_VERIFY && (int)$cl['reported_by'] === $userId) {
                    throw new ProgBusinessError('Klaim tidak boleh diverifikasi oleh pelapornya sendiri. Minta PM / QS lain memverifikasi.');
                }
                $pid = (int)$cl['project_id'];
                $pct = (int)$cl['percent_claimed'];

                $st = $db->prepare("SELECT name, COALESCE(progress, 0) AS progress FROM projects WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $pid]);
                $pj = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pj) throw new ProgBusinessError('Proyek tidak ditemukan.');

                if ($decision === 'Ditolak') {
                    if ($vnote === '') throw new ProgBusinessError('Alasan penolakan wajib diisi.');
                    $st = $db->prepare("UPDATE project_progress_log SET status = 'Ditolak', verified_by = :u, verified_at = NOW(), verify_note = :n WHERE id = :id");
                    $st->execute([':u' => $userId, ':n' => $vnote, ':id' => $id]);
                    $db->commit();
                    sendJsonResponse(true, [], "Klaim {$pct}% ditolak.");
                }

                // Setujui: hitung posisi biaya pada progress yang diklaim, simpan sebagai jejak audit
                if ($pct <= (int)$pj['progress']) {
                    throw new ProgBusinessError("Klaim {$pct}% tidak lebih besar dari progress terverifikasi sekarang ({$pj['progress']}%). Tolak atau batalkan klaim ini.");
                }
                $m = prog_metrics($db, $pid, '', $pct)[0] ?? null;
                if (!$m) throw new ProgBusinessError('Data proyek tidak ditemukan.');
                if ($m['cpi'] !== null && $m['cpi'] < PROG_CPI_RED && $vnote === '') {
                    throw new ProgBusinessError('Indeks biaya pada progress ' . $pct . '% adalah ' . number_format($m['cpi'], 2, ',', '.') .
                        ' (di bawah ' . number_format(PROG_CPI_RED, 2, ',', '.') . '): biaya terpakai ' . prog_fmt($m['cost_actual']) .
                        ' jauh melebihi nilai pekerjaan ' . prog_fmt($m['earned_value']) . '. Isi catatan verifikasi bila tetap disetujui.');
                }
                $st = $db->prepare("
                    UPDATE project_progress_log
                       SET status = 'Diverifikasi', verified_by = :u, verified_at = NOW(), verify_note = :n,
                           snap_budget_base = :sb, snap_cost_base = :sc, snap_cpi = :cpi
                     WHERE id = :id");
                $st->execute([':u' => $userId, ':n' => $vnote !== '' ? $vnote : null, ':sb' => $m['budget_base'],
                              ':sc' => $m['cost_actual'], ':cpi' => $m['cpi'], ':id' => $id]);
                $st = $db->prepare("UPDATE projects SET progress = :p WHERE id = :id");      // satu-satunya jalur yang mengubah progress
                $st->execute([':p' => $pct, ':id' => $pid]);
                $db->commit();
                sendJsonResponse(true, [], "Progress {$pj['name']} terverifikasi {$pct}%." .
                    ($m['cpi'] !== null ? ' Indeks biaya ' . number_format($m['cpi'], 2, ',', '.') . ' (' . $m['cpi_status'] . ').' : ''));
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Batalkan klaim yang masih Diajukan
         * ------------------------------------------------------------------ */
        case 'cancel_claim':
            prog_need_access(PROG_ACCESS_REPORT, 'membatalkan klaim progress');
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT status, reported_by, percent_claimed FROM project_progress_log WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $cl = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cl) throw new ProgBusinessError('Klaim tidak ditemukan.');
                if ($cl['status'] !== 'Diajukan') throw new ProgBusinessError("Klaim berstatus {$cl['status']} tidak dapat dibatalkan.");
                if ((int)$cl['reported_by'] !== $userId && intval($_SESSION['FormAccess'] ?? 0) < PROG_ACCESS_VERIFY) {
                    throw new ProgBusinessError('Hanya pelapor (atau pemverifikasi) yang dapat membatalkan klaim ini.');
                }
                $st = $db->prepare("UPDATE project_progress_log SET status = 'Dibatalkan', verified_by = :u, verified_at = NOW() WHERE id = :id");
                $st->execute([':u' => $userId, ':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], 'Klaim ' . (int)$cl['percent_claimed'] . '% dibatalkan.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (ProgBusinessError $e) {
    sendJsonResponse(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[project.progressCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[project.progressCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}