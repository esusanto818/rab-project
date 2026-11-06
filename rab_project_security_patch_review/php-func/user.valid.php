<?php
/**
 * Hardened legacy menu validation.
 * Keeps the existing PostgreSQL access lookup and sidebar generation.
 */
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once __DIR__ . '/app.url.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once __DIR__ . '/function.all.php';
include_once __DIR__ . '/../php-class/crudClass.php';

$requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$urlFile = basename($requestPath);

global $conn, $dbconn;
if (empty($conn)) {
    if (!empty($dbconn)) {
        $conn = $dbconn;
    } elseif (!empty($GLOBALS['dbconn'])) {
        $conn = $GLOBALS['dbconn'];
    } elseif (!empty($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
}

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_SERVER['HTTP_ACCEPT']) &&
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])
    || intval($_SESSION['user_id']) <= 0) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'message' => 'Session telah berakhir. Silakan login kembali.'));
        exit;
    }
    header('Location: ' . rtrim(BASE_URL, '/') . '/index.php');
    exit;
}

if (!$conn) {
    error_log('user.valid.php: PostgreSQL connection is unavailable.');
    http_response_code(500);
    exit('Terjadi kesalahan koneksi server. Silakan hubungi administrator.');
}

$userId = intval($_SESSION['user_id']);
$formId = 0;

if (isset($_REQUEST['menu_id']) && is_numeric($_REQUEST['menu_id'])) {
    $formId = intval($_REQUEST['menu_id']);
} elseif (isset($_REQUEST['form_id']) && is_numeric($_REQUEST['form_id'])) {
    $formId = intval($_REQUEST['form_id']);
} elseif (
    preg_match('~(?:^|/)views/router/?$~i', $requestPath)
    && isset($_GET['id']) && ctype_digit((string)$_GET['id'])
) {
    // Legacy /views/router?id=<form_id>; do not confuse record ids with menu ids.
    $formId = intval($_GET['id']);
} elseif (isset($_SESSION['form_id']) && is_numeric($_SESSION['form_id'])) {
    $formId = intval($_SESSION['form_id']);
} elseif (isset($_SESSION['current_menu_id']) && is_numeric($_SESSION['current_menu_id'])) {
    $formId = intval($_SESSION['current_menu_id']);
}

$formAccess = null;

if ($formId > 0) {
    $sql = 'SELECT access_level FROM m_user_access_menu WHERE user_id = $1 AND form_id = $2 LIMIT 1';
    $result = pg_query_params($conn, $sql, array($userId, $formId));
    if ($result && pg_num_rows($result) > 0) {
        $row = pg_fetch_assoc($result);
        $formAccess = $row['access_level'];
    }
}

// Fallback untuk halaman lama yang dibuka berdasarkan nama file.
// CRUD direct request tetap harus divalidasi oleh router/endpoint; jangan
// mengandalkan fallback nama file ini sebagai otorisasi operasi.
if ($formAccess === null) {
    $cleanUrlFile = preg_replace('/CRD\.php$/i', '.php', $urlFile);
    $cleanUrlFile = preg_replace('/\.pdf\.php$/i', '.php', $cleanUrlFile);

    $sql = "
        SELECT a.access_level, b.id AS real_form_id
        FROM m_user_access_menu a
        INNER JOIN m_form_name b ON a.form_id = b.id
        WHERE a.user_id = $1
          AND (LOWER(BTRIM(b.php_url)) = LOWER($2)
               OR LOWER(BTRIM(b.php_url)) = LOWER($3))
          AND COALESCE(a.access_level, 0) > 0
        LIMIT 1
    ";
    $result = pg_query_params($conn, $sql, array($userId, $urlFile, $cleanUrlFile));

    if ($result && pg_num_rows($result) > 0) {
        $row = pg_fetch_assoc($result);
        $formAccess = $row['access_level'];
        if ($formId <= 0) {
            $formId = intval($row['real_form_id']);
        }
    }
}

if ($formAccess === null || intval($formAccess) <= 0 || $formId <= 0) {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'message' => 'Akses ditolak: Anda tidak memiliki hak akses ke modul ini.'));
        exit;
    }
    http_response_code(403);
    exit('Akses ditolak: Anda tidak memiliki hak akses ke modul ini.');
}

$_SESSION['FormAccess'] = $formAccess;
$_SESSION['form_id'] = $formId;
$_SESSION['current_menu_id'] = $formId;

// Build sidebar links only for the active page request.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $linkMenu = '';
    $sql = "
      SELECT g.seq, g.name, f.title, f.php_url
      FROM m_form_links l
      LEFT JOIN m_form_name f ON f.id = l.link_form_id
      LEFT JOIN (
        SELECT id, name, COALESCE(remark, '') AS remark, seq
        FROM (
          (SELECT d.id, h.name || ' -- ' || d.name AS name, d.remark, d.seq
           FROM m_form_group d
           LEFT JOIN (
             SELECT name, seq FROM m_form_group WHERE SUBSTRING(seq, 3, 2) = '00'
           ) h ON SUBSTRING(h.seq, 1, 2) = SUBSTRING(d.seq, 1, 2)
           WHERE SUBSTRING(d.seq, 3, 2) <> '00' AND SUBSTRING(d.seq, 1, 2) <> '00')
          UNION
          (SELECT h.id, h.name, h.remark, seq
           FROM m_form_group h
           WHERE SUBSTRING(seq, 3, 2) = '00' AND SUBSTRING(seq, 1, 2) <> '00')
        ) data
        ORDER BY seq
      ) g ON g.id = f.group_id
      WHERE l.form_id = $1
      ORDER BY g.seq, f.title
    ";

    $result = pg_query_params($conn, $sql, array($formId));
    if ($result && pg_num_rows($result) > 0) {
        $currentGroup = null;
        while ($row = pg_fetch_assoc($result)) {
            $groupName = isset($row['name']) ? $row['name'] : '';
            $seq = isset($row['seq']) ? $row['seq'] : '';

            if ($seq !== $currentGroup) {
                if ($groupName !== '') {
                    $linkMenu .= '<div id="groupMenu">' . htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') . '</div>';
                    $linkMenu .= '<div class="Hline-separatorMid"></div>';
                }
                $currentGroup = $seq;
            }

            $targetUrl = !empty($row['php_url']) ? $row['php_url'] : '#';
            $title = isset($row['title']) ? $row['title'] : '';
            $linkMenu .= '<a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8')
                . '" target="_blank">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    }
    $_SESSION['linkMenu'] = $linkMenu;
}
?>
