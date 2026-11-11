<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * settingsCRD.php
 * ============================================================
 * Backend Pengaturan Sistem (settings.js). Pola sama dengan rabCRD.php:
 * PDO (class crud), dispatch lewat $_REQUEST['task'], respons JSON {success, message, data}.
 *
 * Data:
 *  - company_profile  (1 baris): nama, alamat, kontak, NPWP, 2 logo (dokumen & aplikasi), pelengkap dokumen.
 *  - finance_settings (1 baris): mata uang dasar (pembukuan) + mata uang laporan keuangan final,
 *                                jenis kurs default, dasar kurs laba rugi, awal tahun fiskal, desimal.
 *
 * Task      : load_settings, save_company, save_document, save_finance,
 *             upload_logo, delete_logo, logo (GET, mengalirkan gambar)
 *
 * Aturan:
 *  - Semua task butuh login; task tulis butuh hak akses form >= SET_ACCESS_WRITE.
 *  - Logo: hanya PNG / JPEG / WEBP (isi file diperiksa, bukan hanya ekstensi), maks 1 MB, maks 4000 px.
 *    SVG sengaja ditolak (bisa memuat script).
 *  - Mata uang dasar terkunci begitu sudah ada transaksi (jurnal, stok, PO, pembayaran, invoice proyek).
 *    Mata uang laporan boleh diganti kapan saja (hanya cara penyajian laporan).
 *
 * Butuh: sql/2026-10-01.settings.sql
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const SET_ACCESS_WRITE = 3;
const SET_MAX_LOGO     = 1048576;          // 1 MB
const SET_MAX_PIXEL    = 4000;
const SET_RATE_TYPES   = ['KMK', 'BI', 'MANUAL'];
const SET_RATE_BASIS   = ['TRANSACTION', 'AVERAGE', 'PERIOD_END'];
const SET_LOGO_COLS    = ['document' => 'logo_document', 'app' => 'logo_app'];     // whitelist nama kolom
const SET_LOGO_MIMES   = ['image/png', 'image/jpeg', 'image/webp'];

$task = $_REQUEST['task'] ?? '';
if ($task !== 'logo') {
    header('Content-Type: application/json');
}

$db = new crud();

class SetBusinessError extends Exception {}

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $response['data'] = $data;
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function set_bool($v): bool {
    return $v === true || $v === 't' || $v === '1' || $v === 1;
}

function set_require_write(): void {
    if (intval($_SESSION['FormAccess'] ?? 0) < SET_ACCESS_WRITE) {
        sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk mengubah pengaturan.');
    }
}

function set_text($key, int $max, bool $required = false, string $label = ''): ?string {
    $v = trim((string)($_POST[$key] ?? ''));
    if ($required && $v === '') throw new SetBusinessError(($label ?: $key) . ' wajib diisi.');
    if (mb_strlen($v) > $max) throw new SetBusinessError(($label ?: $key) . " maksimal {$max} karakter.");
    return $v === '' ? null : $v;
}

/** Alasan mata uang dasar tidak boleh diganti (null = masih boleh). */
function set_base_lock_reason($db): ?string {
    $checks = [
        'journal_entries'    => 'jurnal',
        'stock_movements'    => 'pergerakan stok',
        'purchase_orders'    => 'PO',
        'supplier_payments'  => 'pembayaran supplier',
        'project_invoices'   => 'invoice proyek',
    ];
    foreach ($checks as $table => $label) {
        $reg = $db->query("SELECT to_regclass('public.{$table}')")->fetchColumn();
        if (!$reg) continue;
        if ((int)$db->query("SELECT COUNT(*) FROM public.{$table}")->fetchColumn() > 0) {
            return "Sudah ada transaksi ({$label}).";
        }
    }
    return null;
}

function set_currency_exists($db, int $id): bool {
    $st = $db->prepare("SELECT 1 FROM public.m_currency WHERE id = :id");
    $st->execute([':id' => $id]);
    return (bool)$st->fetchColumn();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    if ($task === 'logo') { if (ob_get_length()) ob_clean(); exit; }
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}
$userId = (int)$_SESSION['user_id'];
if (in_array($task, ['save_company', 'save_document', 'save_finance', 'upload_logo', 'delete_logo'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    sendJsonResponse(false, [], 'Metode harus POST untuk perubahan pengaturan sistem.');
}

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Muat semua pengaturan
         * ------------------------------------------------------------------ */
        case 'load_settings':
            $company = $db->query("
                SELECT company_name, legal_name, address, city, province, postal_code, country,
                       phone, email, website, tax_id, doc_footer, doc_signer_name, doc_signer_title,
                       (logo_document IS NOT NULL) AS has_logo_document,
                       (logo_app IS NOT NULL)      AS has_logo_app,
                       COALESCE(EXTRACT(EPOCH FROM logo_document_updated_at)::bigint, 0) AS logo_document_v,
                       COALESCE(EXTRACT(EPOCH FROM logo_app_updated_at)::bigint, 0)      AS logo_app_v
                  FROM public.company_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($company) {
                $company['has_logo_document'] = set_bool($company['has_logo_document']);
                $company['has_logo_app']      = set_bool($company['has_logo_app']);
                $company['logo_document_v']   = (int)$company['logo_document_v'];
                $company['logo_app_v']        = (int)$company['logo_app_v'];
            }

            $finance = $db->query("SELECT base_currency_id, report_currency_id, default_rate_type, rate_basis_pl,
                                          fiscal_year_start_month, amount_decimals
                                     FROM public.finance_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($finance) {
                $finance['base_currency_id']        = (int)$finance['base_currency_id'];
                $finance['report_currency_id']      = (int)$finance['report_currency_id'];
                $finance['fiscal_year_start_month'] = (int)$finance['fiscal_year_start_month'];
                $finance['amount_decimals']         = (int)$finance['amount_decimals'];
            }

            $currencies = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($currencies as &$c) $c['id'] = (int)$c['id'];

            sendJsonResponse(true, [
                'company'          => $company,
                'finance'          => $finance,
                'currencies'       => $currencies,
                'base_lock_reason' => set_base_lock_reason($db),
            ]);
            break;

        /* ------------------------------------------------------------------
         * Simpan profil perusahaan
         * ------------------------------------------------------------------ */
        case 'save_company':
            set_require_write();
            try {
                $name  = set_text('company_name', 150, true, 'Nama perusahaan');
                $legal = set_text('legal_name', 200);
                $addr  = set_text('address', 1000);
                $city  = set_text('city', 100);
                $prov  = set_text('province', 100);
                $post  = set_text('postal_code', 20);
                $ctry  = set_text('country', 100);
                $phone = set_text('phone', 50);
                $email = set_text('email', 150);
                $web   = set_text('website', 150);
                $tax   = set_text('tax_id', 50);
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new SetBusinessError('Format email tidak valid.');
                }
            } catch (SetBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }

            $st = $db->prepare("
                INSERT INTO public.company_profile
                    (id, company_name, legal_name, address, city, province, postal_code, country,
                     phone, email, website, tax_id, updated_by)
                VALUES (1, :name, :legal, :addr, :city, :prov, :post, :ctry, :phone, :email, :web, :tax, :uid)
                ON CONFLICT (id) DO UPDATE SET
                    company_name = EXCLUDED.company_name, legal_name = EXCLUDED.legal_name,
                    address = EXCLUDED.address, city = EXCLUDED.city, province = EXCLUDED.province,
                    postal_code = EXCLUDED.postal_code, country = EXCLUDED.country,
                    phone = EXCLUDED.phone, email = EXCLUDED.email, website = EXCLUDED.website,
                    tax_id = EXCLUDED.tax_id, updated_at = NOW(), updated_by = EXCLUDED.updated_by");
            $st->execute([':name' => $name, ':legal' => $legal, ':addr' => $addr, ':city' => $city, ':prov' => $prov,
                          ':post' => $post, ':ctry' => $ctry, ':phone' => $phone, ':email' => $email,
                          ':web' => $web, ':tax' => $tax, ':uid' => $userId]);
            sendJsonResponse(true, [], 'Profil perusahaan disimpan.');
            break;

        /* ------------------------------------------------------------------
         * Simpan pelengkap dokumen (footer + penandatangan)
         * ------------------------------------------------------------------ */
        case 'save_document':
            set_require_write();
            try {
                $footer = set_text('doc_footer', 1000);
                $sname  = set_text('doc_signer_name', 150);
                $stitle = set_text('doc_signer_title', 100);
            } catch (SetBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            $st = $db->prepare("
                INSERT INTO public.company_profile (id, doc_footer, doc_signer_name, doc_signer_title, updated_by)
                VALUES (1, :f, :n, :t, :uid)
                ON CONFLICT (id) DO UPDATE SET
                    doc_footer = EXCLUDED.doc_footer, doc_signer_name = EXCLUDED.doc_signer_name,
                    doc_signer_title = EXCLUDED.doc_signer_title, updated_at = NOW(), updated_by = EXCLUDED.updated_by");
            $st->execute([':f' => $footer, ':n' => $sname, ':t' => $stitle, ':uid' => $userId]);
            sendJsonResponse(true, [], 'Pengaturan dokumen disimpan.');
            break;

        /* ------------------------------------------------------------------
         * Simpan pengaturan keuangan
         * ------------------------------------------------------------------ */
        case 'save_finance':
            set_require_write();
            $base   = (int)($_POST['base_currency_id'] ?? 0);
            $report = (int)($_POST['report_currency_id'] ?? 0);
            $rtype  = (string)($_POST['default_rate_type'] ?? '');
            $basis  = (string)($_POST['rate_basis_pl'] ?? '');
            $month  = (int)($_POST['fiscal_year_start_month'] ?? 0);
            $dec    = (int)($_POST['amount_decimals'] ?? -1);

            try {
                if (!$base || !set_currency_exists($db, $base))     throw new SetBusinessError('Mata uang dasar wajib dipilih.');
                if (!$report || !set_currency_exists($db, $report)) throw new SetBusinessError('Mata uang laporan keuangan wajib dipilih.');
                if (!in_array($rtype, SET_RATE_TYPES, true))        throw new SetBusinessError('Jenis kurs tidak valid.');
                if (!in_array($basis, SET_RATE_BASIS, true))        throw new SetBusinessError('Dasar kurs laba rugi tidak valid.');
                if ($month < 1 || $month > 12)                      throw new SetBusinessError('Bulan awal tahun fiskal harus 1-12.');
                if ($dec < 0 || $dec > 4)                           throw new SetBusinessError('Jumlah desimal harus 0-4.');

                $cur = $db->query("SELECT base_currency_id FROM public.finance_settings WHERE id = 1")->fetchColumn();
                if ($cur !== false && (int)$cur !== $base) {
                    $lock = set_base_lock_reason($db);
                    if ($lock !== null) {
                        throw new SetBusinessError('Mata uang dasar tidak dapat diubah. ' . $lock);
                    }
                }
            } catch (SetBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }

            $st = $db->prepare("
                INSERT INTO public.finance_settings
                    (id, base_currency_id, report_currency_id, default_rate_type, rate_basis_pl,
                     fiscal_year_start_month, amount_decimals, updated_by)
                VALUES (1, :b, :r, :t, :p, :m, :d, :uid)
                ON CONFLICT (id) DO UPDATE SET
                    base_currency_id = EXCLUDED.base_currency_id, report_currency_id = EXCLUDED.report_currency_id,
                    default_rate_type = EXCLUDED.default_rate_type, rate_basis_pl = EXCLUDED.rate_basis_pl,
                    fiscal_year_start_month = EXCLUDED.fiscal_year_start_month,
                    amount_decimals = EXCLUDED.amount_decimals, updated_at = NOW(), updated_by = EXCLUDED.updated_by");
            $st->execute([':b' => $base, ':r' => $report, ':t' => $rtype, ':p' => $basis,
                          ':m' => $month, ':d' => $dec, ':uid' => $userId]);
            sendJsonResponse(true, [], 'Pengaturan keuangan disimpan.');
            break;

        /* ------------------------------------------------------------------
         * Upload logo (multipart: type = document|app, file)
         * ------------------------------------------------------------------ */
        case 'upload_logo':
            set_require_write();
            $type = (string)($_POST['type'] ?? '');
            if (!isset(SET_LOGO_COLS[$type])) sendJsonResponse(false, [], 'Jenis logo tidak dikenal.');
            $col = SET_LOGO_COLS[$type];

            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $msg = ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))
                    ? 'Ukuran file melebihi batas server.' : 'File logo belum dipilih atau gagal diunggah.';
                sendJsonResponse(false, [], $msg);
            }
            if ($f['size'] > SET_MAX_LOGO) sendJsonResponse(false, [], 'Ukuran logo maksimal 1 MB.');
            if (!is_uploaded_file($f['tmp_name'])) sendJsonResponse(false, [], 'Berkas tidak valid.');

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!in_array($mime, SET_LOGO_MIMES, true)) {
                sendJsonResponse(false, [], 'Format logo harus PNG, JPEG, atau WEBP.');
            }
            $info = @getimagesize($f['tmp_name']);
            if (!$info) sendJsonResponse(false, [], 'File bukan gambar yang valid.');
            if ($info[0] > SET_MAX_PIXEL || $info[1] > SET_MAX_PIXEL) {
                sendJsonResponse(false, [], 'Dimensi logo maksimal ' . SET_MAX_PIXEL . ' x ' . SET_MAX_PIXEL . ' piksel.');
            }

            $db->exec("INSERT INTO public.company_profile (id) VALUES (1) ON CONFLICT (id) DO NOTHING");
            $fp = fopen($f['tmp_name'], 'rb');
            $st = $db->prepare("UPDATE public.company_profile
                                   SET {$col} = :img, {$col}_mime = :mime, {$col}_updated_at = NOW(),
                                       updated_at = NOW(), updated_by = :uid
                                 WHERE id = 1");
            $st->bindParam(':img', $fp, PDO::PARAM_LOB);
            $st->bindValue(':mime', $mime);
            $st->bindValue(':uid', $userId, PDO::PARAM_INT);
            $st->execute();
            fclose($fp);
            sendJsonResponse(true, [], 'Logo berhasil diunggah.');
            break;

        case 'delete_logo':
            set_require_write();
            $type = (string)($_POST['type'] ?? '');
            if (!isset(SET_LOGO_COLS[$type])) sendJsonResponse(false, [], 'Jenis logo tidak dikenal.');
            $col = SET_LOGO_COLS[$type];
            $db->prepare("UPDATE public.company_profile
                             SET {$col} = NULL, {$col}_mime = NULL, {$col}_updated_at = NOW(),
                                 updated_at = NOW(), updated_by = :uid WHERE id = 1")
               ->execute([':uid' => $userId]);
            sendJsonResponse(true, [], 'Logo dihapus.');
            break;

        /* ------------------------------------------------------------------
         * Alirkan gambar logo (GET ?task=logo&type=document|app&v=...)
         * ------------------------------------------------------------------ */
        case 'logo':
            $type = (string)($_GET['type'] ?? '');
            if (!isset(SET_LOGO_COLS[$type])) { http_response_code(404); if (ob_get_length()) ob_clean(); exit; }
            $col = SET_LOGO_COLS[$type];

            $st = $db->prepare("SELECT {$col}, {$col}_mime FROM public.company_profile WHERE id = 1");
            $st->execute();
            $st->bindColumn(1, $img, PDO::PARAM_LOB);
            $st->bindColumn(2, $mime);
            if (!$st->fetch(PDO::FETCH_BOUND) || $img === null) {
                http_response_code(404);
                if (ob_get_length()) ob_clean();
                exit;
            }
            $bin = is_resource($img) ? stream_get_contents($img) : $img;
            if (is_string($bin) && strncmp($bin, '\\x', 2) === 0) {           // bytea format hex (jaga-jaga)
                $bin = hex2bin(substr($bin, 2));
            }
            if (!in_array($mime, SET_LOGO_MIMES, true) || $bin === '' || $bin === false) {
                http_response_code(404);
                if (ob_get_length()) ob_clean();
                exit;
            }
            if (ob_get_length()) { ob_clean(); }
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($bin));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=86400');
            echo $bin;
            exit;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[settingsCRD] ' . $e->getMessage());
    if ($task === 'logo') { http_response_code(500); if (ob_get_length()) ob_clean(); exit; }
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[settingsCRD] ' . $e->getMessage());
    if ($task === 'logo') { http_response_code(500); if (ob_get_length()) ob_clean(); exit; }
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}