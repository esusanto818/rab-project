<?php
/**
 * projectprogressCRD.php
 * ============================================================
 * Backend Project Progress (halaman mobile project.progress.m.js).
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Task: list_projects | list_progress | save_progress | photo
 *
 * Aturan:
 *  1. Progress dicatat PER PROYEK (persen 0-100) + catatan + foto (maks 5) + GPS (opsional).
 *  2. IDEMPOTEN: client_uuid dibuat di HP. Kiriman ulang dengan uuid sama tidak membuat data ganda
 *     (penting untuk antrean offline / sinyal putus di tengah upload).
 *  3. projects.progress otomatis = persen entri terbaru (berdasarkan progress_date, id).
 *  4. Foto disimpan di luar web root bila memungkinkan (../uploads/progress), nama file acak,
 *     diverifikasi MIME asli (finfo), dan hanya disajikan lewat task=photo (wajib login).
 * Butuh migrasi: sql/2026-10-13.project_progress.sql
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new crud();

const PRG_ACCESS_ADD = 2;                 // level akses minimal untuk menyimpan progress (samakan dengan PO)
const PRG_MAX_PHOTOS = 5;                 // [BARU] maks foto per entri
const PRG_MAX_BYTES  = 5 * 1024 * 1024;   // [BARU] maks 5 MB per foto (klien sudah mengompres)
const PRG_ALLOWED    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

class PrgBusinessError extends Exception {}

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
    header('Content-Type: application/json');
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function prg_upload_dir(): string {
    $dir = __DIR__ . '/../uploads/progress';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    return $dir;
}

/** Normalisasi $_FILES['photos'] (multi) menjadi array flat. */
function prg_files(): array {
    $out = [];
    if (empty($_FILES['photos'])) return $out;
    $f = $_FILES['photos'];
    if (!is_array($f['name'])) {
        $f = ['name' => [$f['name']], 'tmp_name' => [$f['tmp_name']], 'error' => [$f['error']], 'size' => [$f['size']]];
    }
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['tmp' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => (int)$f['size'][$i]];
    }
    return $out;
}

// ---- Sesi wajib ---------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    http_response_code(401);                       // klien mobile: 401 = antrean offline TETAP disimpan, minta login ulang
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); sendJsonResponse(false, [], 'Anda tidak memiliki hak baca progres proyek.'); }


try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Daftar proyek untuk dipilih (kartu di HP)
         * ------------------------------------------------------------------ */
        case 'list_projects':
            $rows = $db->query("
                SELECT p.id, p.name, p.status, COALESCE(p.progress, 0) AS progress, p.location
                  FROM public.projects p
                 ORDER BY (p.status = 'Berjalan') DESC, p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['progress'] = (int)$r['progress']; }
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)]);
            break;

        /* ------------------------------------------------------------------
         * Riwayat progress satu proyek (terbaru dulu) + id foto
         * ------------------------------------------------------------------ */
        case 'list_progress':
            $prj = (int)($_REQUEST['project_id'] ?? 0);
            if ($prj <= 0) throw new PrgBusinessError('Proyek tidak valid.');
            $st = $db->prepare("
                SELECT pp.id, pp.progress_date, pp.percent, pp.notes, pp.latitude, pp.longitude,
                       u.login_name AS created_by_name, pp.created_at,
                       (SELECT COALESCE(json_agg(ph.id ORDER BY ph.id), '[]'::json)
                          FROM public.project_progress_photos ph WHERE ph.progress_id = pp.id) AS photos
                  FROM public.project_progress pp
                  LEFT JOIN public.m_user_system u ON u.id = pp.created_by
                 WHERE pp.project_id = :p
                 ORDER BY pp.progress_date DESC, pp.id DESC
                 LIMIT 50");
            $st->execute([':p' => $prj]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['percent'] = (float)$r['percent'];
                $r['photos'] = json_decode($r['photos'], true) ?: [];
            }
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)]);
            break;

        /* ------------------------------------------------------------------
         * Simpan progress (multipart: field + photos[]). Idempoten via client_uuid.
         * ------------------------------------------------------------------ */
        case 'save_progress':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PrgBusinessError('Metode harus POST.');
            $access = (int)($_SESSION['FormAccess'] ?? 0);
            if ($access < PRG_ACCESS_ADD) throw new PrgBusinessError('Anda tidak memiliki hak akses untuk mencatat progress.');

            $prj   = (int)($_POST['project_id'] ?? 0);
            $pct   = round((float)($_POST['percent'] ?? -1), 2);
            $date  = (string)($_POST['progress_date'] ?? '');
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            $uuid  = strtolower(trim((string)($_POST['client_uuid'] ?? '')));
            $lat   = ($_POST['latitude']  ?? '') !== '' ? round((float)$_POST['latitude'], 6)  : null;
            $lng   = ($_POST['longitude'] ?? '') !== '' ? round((float)$_POST['longitude'], 6) : null;
            $userId = (int)$_SESSION['user_id'];

            $written = [];     // file yang sudah ditulis ke disk (dihapus bila transaksi gagal)
            try {
                if ($prj <= 0) throw new PrgBusinessError('Proyek wajib dipilih.');
                if ($pct < 0 || $pct > 100) throw new PrgBusinessError('Persentase progress harus 0 - 100.');
                $d = DateTime::createFromFormat('Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) throw new PrgBusinessError('Tanggal tidak valid.');
                if (!preg_match('/^[a-f0-9\-]{8,64}$/', $uuid)) throw new PrgBusinessError('ID klien tidak valid.');
                if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
                if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;

                $files = prg_files();
                if (count($files) > PRG_MAX_PHOTOS) throw new PrgBusinessError('Maksimal ' . PRG_MAX_PHOTOS . ' foto per laporan.');

                $db->beginTransaction();
                try {
                    // Idempotensi: uuid sudah ada -> anggap sukses (kiriman ulang dari antrean)
                    $st = $db->prepare("SELECT id FROM public.project_progress WHERE client_uuid = :u");
                    $st->execute([':u' => $uuid]);
                    $dup = $st->fetchColumn();
                    if ($dup) {
                        $db->rollBack();
                        sendJsonResponse(true, ['id' => (int)$dup, 'duplicate' => true], 'Laporan sudah tersimpan sebelumnya.');
                    }

                    $st = $db->prepare("SELECT 1 FROM public.projects WHERE id = :id");
                    $st->execute([':id' => $prj]);
                    if (!$st->fetchColumn()) throw new PrgBusinessError('Proyek tidak ditemukan.');

                    $st = $db->prepare("
                        INSERT INTO public.project_progress
                            (project_id, progress_date, percent, notes, latitude, longitude, client_uuid, created_by)
                        VALUES (:p, CAST(:d AS date), :pc, :n, :la, :lo, :u, :by)
                        RETURNING id");
                    $st->execute([':p' => $prj, ':d' => $date, ':pc' => $pct, ':n' => $notes,
                                  ':la' => $lat, ':lo' => $lng, ':u' => $uuid, ':by' => $userId]);
                    $progId = (int)$st->fetchColumn();

                    // Foto: validasi MIME asli, nama acak, simpan
                    $dir = prg_upload_dir();
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $stPh = $db->prepare("INSERT INTO public.project_progress_photos (progress_id, file_name, file_size)
                                          VALUES (:g, :f, :s)");
                    foreach ($files as $i => $f) {
                        $no = 'Foto ' . ($i + 1);
                        if ($f['error'] !== UPLOAD_ERR_OK) throw new PrgBusinessError("{$no}: upload gagal (kode {$f['error']}).");
                        if ($f['size'] <= 0 || $f['size'] > PRG_MAX_BYTES) throw new PrgBusinessError("{$no}: ukuran harus di bawah 5 MB.");
                        if (!is_uploaded_file($f['tmp'])) throw new PrgBusinessError("{$no}: file tidak valid.");
                        $mime = $finfo->file($f['tmp']);
                        if (!isset(PRG_ALLOWED[$mime])) throw new PrgBusinessError("{$no}: hanya JPG / PNG / WEBP.");
                        $name = $prj . '_' . $progId . '_' . bin2hex(random_bytes(8)) . '.' . PRG_ALLOWED[$mime];
                        if (!move_uploaded_file($f['tmp'], $dir . '/' . $name)) throw new Exception("{$no}: gagal menyimpan file.");
                        $written[] = $dir . '/' . $name;
                        $stPh->execute([':g' => $progId, ':f' => $name, ':s' => $f['size']]);
                    }

                    // projects.progress = persen entri terbaru (tanggal lalu / backdate tidak menimpa yang lebih baru)
                    $db->prepare("
                        UPDATE public.projects
                           SET progress = (SELECT ROUND(percent)::smallint FROM public.project_progress
                                            WHERE project_id = :p ORDER BY progress_date DESC, id DESC LIMIT 1),
                               updated_at = NOW()
                         WHERE id = :p")->execute([':p' => $prj]);

                    $db->commit();
                    sendJsonResponse(true, ['id' => $progId], 'Progress berhasil disimpan.');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    foreach ($written as $w) { @unlink($w); }
                    throw $e;
                }
            } catch (PrgBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Sajikan foto (hanya untuk user login) - folder upload tidak diakses langsung
         * ------------------------------------------------------------------ */
        case 'photo':
            $pid = (int)($_REQUEST['id'] ?? 0);
            $st = $db->prepare("SELECT file_name FROM public.project_progress_photos WHERE id = :id");
            $st->execute([':id' => $pid]);
            $fn = $st->fetchColumn();
            $path = $fn ? prg_upload_dir() . '/' . basename($fn) : '';
            if (!$fn || !is_file($path)) {
                http_response_code(404);
                sendJsonResponse(false, [], 'Foto tidak ditemukan.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (ob_get_length()) { ob_clean(); }
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=86400');
            readfile($path);
            exit;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PrgBusinessError $e) {
    sendJsonResponse(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[projectprogressCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[projectprogressCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}