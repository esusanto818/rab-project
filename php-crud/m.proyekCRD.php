<?php
/**
 * m.proyekCRD.php
 * ============================================================
 * Backend Controller untuk Master Proyek (public.projects)
 * Beserta Integrasi AI Task Breakdown & Validasi Rentang Tanggal.
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
            $tasks = mpFetchAll($db, "SELECT * FROM public.project_tasks WHERE project_id = :pid ORDER BY start_date ASC", ['pid' => $projectId], 'read_tasks');
            mpJson(['success' => true, 'data' => $tasks]);
            break;

        case 'ai_generate_tasks':
            if ($access < 2) {
                mpJson(['success' => false, 'message' => 'Akses ditolak.']);
            }
            $projectId = (int)($_REQUEST['project_id'] ?? 0);
            if ($projectId <= 0) throw new Exception('ID Proyek tidak valid.');

            $proj = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $projectId], 'ai_fetch_proj');
            if (!$proj) throw new Exception('Proyek tidak ditemukan.');

            $pName   = $proj['name'];
            $pScope  = $proj['project_scope'] ?: 'Tidak ada spesifikasi khusus';
            $pStart  = $proj['start_date'] ?: date('Y-m-d');
            $pEnd    = $proj['end_date'] ?: date('Y-m-d', strtotime('+30 days'));
            $pBudget = $proj['budget'];

            $prompt = "
                Anda adalah Construction Project Manager dan Expert Planner ahli. 
                Tolong buatkan rincian Breakdown Struktur Pekerjaan (WBS) atau sub-task untuk proyek konstruksi berikut:
                - Nama Proyek: {$pName}
                - Definisi / Spesifikasi Proyek (Lingkup): {$pScope}
                - Tanggal Mulai Proyek: {$pStart}
                - Tanggal Selesai Proyek: {$pEnd}
                - Total Budget: {$pBudget}

                Aturan Ketat:
                1. Sesuaikan sub-task dengan dimensi/spesifikasi pada Definisi Proyek (misal: pekerjaan pondasi, struktur, atap, atau luasan gudang/rumah tersebut).
                2. Pecah proyek menjadi 5 hingga 9 sub-task logis.
                3. Setiap sub-task HARUS memiliki rentang tanggal (start_date dan end_date) format YYYY-MM-DD.
                4. Rentang tanggal sub-task harus berada di dalam atau tercover sepenuhnya di antara Tanggal Mulai ({$pStart}) dan Tanggal Selesai ({$pEnd}) proyek.
                
                Berikan respons dalam format JSON MURNI berupa array of objects tanpa markdown backtick tambahan:
                [
                  {
                    \"task_name\": \"string\",
                    \"description\": \"string\",
                    \"start_date\": \"YYYY-MM-DD\",
                    \"end_date\": \"YYYY-MM-DD\"
                  }
                ]
            ";
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
                        'contents' => [['parts' => [['text' => $prompt]]]] ,
                        'generationConfig' => ['responseMimeType' => 'application/json']
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                    
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
            $aiRawText = $apiResult['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            $aiRawText = trim(str_replace(['```json', '```'], '', $aiRawText));
            $tasks = json_decode($aiRawText, true);

            if (!is_array($tasks) || empty($tasks)) {
                throw new Exception('AI gagal menghasilkan format task yang valid.');
            }

            $db->beginTransaction();
            try {
                mpExec($db, "DELETE FROM public.project_tasks WHERE project_id = :pid", ['pid' => $projectId], 'del_old_tasks');
                
                $stIns = $db->prepare("
                    INSERT INTO public.project_tasks (project_id, task_name, description, start_date, end_date, status)
                    VALUES (:pid, :tname, :tdesc, :tsd, :ted, 'Belum Mulai')
                ");

                $covered = true;
                $coverageNotes = [];

                foreach ($tasks as $t) {
                    $tName = $t['task_name'] ?? 'Pekerjaan';
                    $tDesc = $t['description'] ?? '';
                    $tStart = $t['start_date'] ?? $pStart;
                    $tEnd = $t['end_date'] ?? $pEnd;

                    if ($tStart < $pStart || $tEnd > $pEnd) {
                        $covered = false;
                        $coverageNotes[] = "Task '{$tName}' diluar rentang tanggal proyek!";
                    }

                    $stIns->execute([
                        ':pid' => $projectId,
                        ':tname' => $tName,
                        ':tdesc' => $tDesc,
                        ':tsd' => $tStart,
                        ':ted' => $tEnd
                    ]);
                }

                $db->commit();
                mpJson([
                    'success' => true, 
                    'message' => 'Proyek berhasil dipecah oleh AI berdasarkan spesifikasi teknis!',
                    'coverage_valid' => $covered,
                    'notes' => $coverageNotes
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