<?php
/**
 * Security patch for legacy ProyekGMN router.
 * Compatible with PHP 7.x.
 */
require_once __DIR__ . '/php-func/app.url.php';

ini_set('pcre.jit', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function router_is_ajax_request()
{
    $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        ? strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) : '';
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';

    return ($requestedWith === 'xmlhttprequest')
        || (strpos($accept, 'application/json') !== false);
}

function router_respond_denied($status, $message)
{
    http_response_code($status);
    if (router_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'message' => $message));
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

function router_require_login()
{
    if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])
        || intval($_SESSION['user_id']) <= 0) {
        if (router_is_ajax_request()) {
            router_respond_denied(401, 'Session telah berakhir. Silakan login kembali.');
        }
        header('Location: ' . rtrim(BASE_URL, '/') . '/index.php');
        exit;
    }
}

function router_normalize_menu_path($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('\\', '/', $value);
    return strtolower(basename($value));
}

/**
 * Map a direct CRUD/PDF endpoint to its parent view menu.
 * A successful match means the user has access to the menu, not necessarily
 * permission for every operation. CRUD handlers must still enforce operation
 * permissions (read/create/update/delete/post/reverse) server-side.
 */
function router_authorize_endpoint($db, $userId, $endpointType, $fileName)
{
    $candidates = array();
    $base = basename($fileName);

    if ($endpointType === 'crud') {
        if (preg_match('/CRD\.php$/i', $base)) {
            $candidates[] = preg_replace('/CRD\.php$/i', '.php', $base);
        }
        // Explicit aliases for legacy endpoints whose parent menu filename differs.
        $legacyAliases = array(
            'project.invoiceCRD.php' => array('project.invoice.php', 'projectinvoice.php'),
            'projectprogressCRD.php' => array('project.progress.php', 'project.progress.m.php'),
            'ppn.backfillCRD.php'    => array('ppn.report.php'),
            'm.taxexchangerate.pdf.php' => array('m.taxexchangerate.php'),
        );
        if (isset($legacyAliases[$base])) {
            $candidates = array_merge($candidates, $legacyAliases[$base]);
        }
    } elseif ($endpointType === 'pdf') {
        $candidates[] = $base; // wrapper file inside /pdf
        if (preg_match('/\.pdf\.php$/i', $base)) {
            $candidates[] = preg_replace('/\.pdf\.php$/i', '.php', $base);
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates)));
    if (!$candidates) {
        return false;
    }

    $sql = "
        SELECT f.id, f.title, f.php_url, u.access_level
        FROM m_form_name f
        INNER JOIN m_user_access_menu u ON u.form_id = f.id
        WHERE u.user_id = :user_id
          AND COALESCE(u.access_level, 0) > 0
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array('user_id' => $userId));

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $menuFile = router_normalize_menu_path($row['php_url']);
        if ($menuFile !== '' && in_array($menuFile, array_map('strtolower', $candidates), true)) {
            $_SESSION['FormAccess'] = $row['access_level'];
            $_SESSION['form_id'] = intval($row['id']);
            $_SESSION['current_menu_id'] = intval($row['id']);
            return $row;
        }
    }
    return false;
}

router_require_login();

$requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$userId = intval($_SESSION['user_id']);

require_once __DIR__ . '/php-class/crudClass.php';

try {
    $db = new crud();

    // Direct PDF/report endpoint: authenticate AND authorize against parent menu.
    if (preg_match('~(?:^|/)pdf/([A-Za-z0-9_.-]+\.php)$~i', $requestPath, $matches)) {
        $pdfName = $matches[1];
        $pdfPath = realpath(__DIR__ . '/pdf/' . $pdfName);
        $pdfRoot = realpath(__DIR__ . '/pdf');

        if (!$pdfPath || !$pdfRoot || strpos($pdfPath, $pdfRoot . DIRECTORY_SEPARATOR) !== 0
            || !is_file($pdfPath)) {
            router_respond_denied(404, 'File laporan tidak ditemukan.');
        }

        if (!router_authorize_endpoint($db, $userId, 'pdf', $pdfName)) {
            router_respond_denied(403, 'Anda tidak memiliki hak akses untuk laporan ini.');
        }

        chdir(dirname($pdfPath));
        require $pdfPath;
        exit;
    }

    // Direct CRUD endpoint: authenticate AND authorize against parent menu.
    if (preg_match('~(?:^|/)php-crud/([A-Za-z0-9_.-]+\.php)$~i', $requestPath, $matches)) {
        $crudName = $matches[1];
        $crudPath = realpath(__DIR__ . '/php-crud/' . $crudName);
        $crudRoot = realpath(__DIR__ . '/php-crud');

        if (!$crudPath || !$crudRoot || strpos($crudPath, $crudRoot . DIRECTORY_SEPARATOR) !== 0
            || !is_file($crudPath)) {
            router_respond_denied(404, 'Endpoint tidak ditemukan.');
        }

        
        $isDashboardApi = (strcasecmp($crudName, 'dashboardCRD.php') === 0);

        if (
            !$isDashboardApi &&
            !router_authorize_endpoint($db, $userId, 'crud', $crudName)
        ) {
            router_respond_denied(403, 'Anda tidak memiliki hak akses ke modul ini.');
        }

        chdir(dirname($crudPath));
        require $crudPath;
        exit;
    }

    // Normal view routing. Do not use stale session menu id as authorization
    // when the request explicitly supplies a different or invalid menu id.
    $formId = 0;
    if (isset($_REQUEST['menu_id']) && is_numeric($_REQUEST['menu_id'])) {
        $formId = intval($_REQUEST['menu_id']);
    } elseif (isset($_REQUEST['form_id']) && is_numeric($_REQUEST['form_id'])) {
        $formId = intval($_REQUEST['form_id']);
    } elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $formId = intval($_GET['id']);
    } elseif (isset($_GET['menu']) && is_numeric($_GET['menu'])) {
        $formId = intval($_GET['menu']);
    } elseif (isset($_SESSION['current_menu_id'])) {
        $formId = intval($_SESSION['current_menu_id']);
    }

    if ($formId <= 0) {
        router_respond_denied(400, 'Parameter menu ID tidak valid.');
    }

    $sql = "
        SELECT f.id, f.title, f.php_url, u.access_level
        FROM m_form_name f
        INNER JOIN m_user_access_menu u ON u.form_id = f.id
        WHERE f.id = :form_id
          AND u.user_id = :user_id
          AND COALESCE(u.access_level, 0) > 0
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array('form_id' => $formId, 'user_id' => $userId));
    $formData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$formData) {
        router_respond_denied(403, 'Anda tidak memiliki izin untuk membuka halaman ini.');
    }

    $targetFile = trim((string)$formData['php_url']);
    if ($targetFile === '' || strpos($targetFile, "\0") !== false) {
        router_respond_denied(404, 'File tujuan belum dikonfigurasi.');
    }

    // Resolve only inside /views; reject traversal and symlink escape.
    $viewRoot = realpath(__DIR__ . '/views');
    $relative = ltrim(str_replace('\\', '/', $targetFile), '/');
    $relative = preg_replace('~^(?:\.\./|php/)+~i', '', $relative);
    $viewPath = realpath(__DIR__ . '/views/' . $relative);

    if (!$viewRoot || !$viewPath || strpos($viewPath, $viewRoot . DIRECTORY_SEPARATOR) !== 0
        || !is_file($viewPath) || strtolower(pathinfo($viewPath, PATHINFO_EXTENSION)) !== 'php') {
        router_respond_denied(404, 'File halaman tidak ditemukan pada lokasi yang diizinkan.');
    }

    $_SESSION['FormAccess'] = $formData['access_level'];
    $_SESSION['form_id'] = intval($formData['id']);
    $_SESSION['current_menu_id'] = intval($formData['id']);

    chdir($viewRoot);
    ob_start();
    include $viewPath;
    $htmlOutput = ob_get_clean();

    $appBase = rtrim(BASE_URL, '/');

    $patterns = array(
        '="../css/'       => '="' . $appBase . '/css/',
        '="../js/'        => '="' . $appBase . '/js/',
        '="../js-ext/'    => '="' . $appBase . '/js-ext/',
        '="../pdf/'       => '="' . $appBase . '/pdf/',
        '="../tcpdf_new/' => '="' . $appBase . '/tcpdf_new/',
        '="../js-func/'   => '="' . $appBase . '/js-func/',
        '="../php-crud/'  => '="' . $appBase . '/php-crud/'
    );

    echo strtr($htmlOutput, $patterns);
    exit;

} catch (Exception $e) {
    error_log('Router security patch error: ' . $e->getMessage());
    router_respond_denied(500, 'Terjadi kesalahan server saat memproses permintaan.');
}
?>
