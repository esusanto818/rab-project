<?php
// Izinkan ngrok melewati halaman peringatan secara otomatis
header('ngrok-skip-browser-warning: 1');

define('BASE_PATH', '/var/www/html/rab-project/dev/');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// 1. Include Dependensi Utama
include_once __DIR__ . "/../php-func/function.all.php";
include_once __DIR__ . "/../php-class/crudClass.php";

// Resolve Nama File dari URI saat ini
$url_text = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = explode("/", $url_text);
$url_file = end($file); // Mengambil nama file paling akhir (misal: m.gemstone.php / m.gemstoneCRD.php)

// Resolver Koneksi Database Global
global $conn, $dbconn;
if (!isset($conn) || !$conn) {
    if (isset($dbconn) && $dbconn) {
        $conn = $dbconn;
    } elseif (isset($GLOBALS['dbconn'])) {
        $conn = $GLOBALS['dbconn'];
    } elseif (isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
}

// Validasi Koneksi DB
if (!$conn) {
    die("Error Fatal: Variabel koneksi DB (\$conn) bernilai NULL di user.valid.php.");
}

// 2. Redirect jika User Belum Login
if (!isset($_SESSION['user_id']) || is_null($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Session telah berakhir. Silakan login kembali."]);
        exit;
    } else {
        header('Location: ../index.php');
        exit;
    }
}

$userId = $_SESSION["user_id"];

// 3. Tangkap Form/Menu ID dari Query Parameter (jika ada)
$formId = null;
if (isset($_REQUEST['menu_id']) && !empty($_REQUEST['menu_id'])) {
    $formId = intval($_REQUEST['menu_id']);
} elseif (isset($_REQUEST['form_id']) && !empty($_REQUEST['form_id'])) {
    $formId = intval($_REQUEST['form_id']);
} elseif (isset($_GET['id']) && !empty($_GET['id']) && !isset($_POST['task'])) {
    // Hanya tangkap ?id= dari GET jika bukan request POST CRUD
    $formId = intval($_GET['id']);
} elseif (isset($_SESSION['form_id'])) {
    $formId = intval($_SESSION['form_id']);
}

// 4. Resolve Access Level dari Database
$formAccess = null;

if (!is_null($formId) && $formId > 0) {
    // Jika $formId diketahui, Query berdasarkan user_id & form_id
    $sql = "SELECT access_level FROM m_user_access_menu WHERE user_id = $1 AND form_id = $2";
    $output = pg_query_params($conn, $sql, array($userId, $formId));
    if ($output && pg_num_rows($output) > 0) {
        $objid = pg_fetch_array($output, null, PGSQL_ASSOC);
        $formAccess = $objid["access_level"];
    }
}

// Fallback: Jika $formId belum ditemukan atau query gagal, Query berdasarkan php_url
if (is_null($formAccess)) {
    // Cari nama file dasar jika dipanggil via CRD (misal m.gemstoneCRD.php -> m.gemstone.php)
    $clean_url_file = str_replace("CRD.php", ".php", $url_file);
    
    $sql = "SELECT a.access_level, b.id as real_form_id 
            FROM m_user_access_menu a 
            LEFT JOIN m_form_name b ON a.form_id = b.id 
            WHERE a.user_id = $1 AND (b.php_url = $2 OR b.php_url = $3)";
    $output = pg_query_params($conn, $sql, array($userId, $url_file, $clean_url_file));

    if ($output && pg_num_rows($output) > 0) {
        $objid = pg_fetch_array($output, null, PGSQL_ASSOC);
        $formAccess = $objid["access_level"];
        if (is_null($formId) || $formId <= 0) {
            $formId = intval($objid["real_form_id"]);
        }
    }
}

// 5. Verifikasi Akhir Hak Akses
if (is_null($formAccess) || intval($formAccess) <= 0) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Akses ditolak: Anda tidak memiliki hak akses ke modul ini."]);
        exit;
    } else {
        header("Location: ../index.php");
        exit;
    }
}

// Simpan Informasi Akses ke Session
$_SESSION["FormAccess"] = $formAccess;
$_SESSION["form_id"]    = $formId;

// 6. Build Sub-Menu Links untuk Sidebar/Tab (Hanya dipanggil pada Request Halaman Utama)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($formId)) {
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
      ORDER BY g.seq, f.title";

    $output = pg_query_params($conn, $sql, array(intval($formId)));

    if ($output) {
        $rows = pg_num_rows($output);
        $cGroup = '';

        if ($rows > 0) {
            while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
                if ($cGroup == '') {
                    $linkMenu .= '<div id="groupMenu">' . htmlspecialchars($objid["name"]) . '</div>';
                    $linkMenu .= '<div class="Hline-separatorMid"></div>';
                }
                if ($objid["seq"] <> $cGroup && $cGroup <> '') {
                    $linkMenu .= '<div id="groupMenu">' . htmlspecialchars($objid["name"]) . '</div>';
                    $linkMenu .= '<div class="Hline-separatorMid"></div>';
                }
                $targetUrl = !empty($objid["php_url"]) ? $objid["php_url"] : "#";
                $linkMenu .= '<a href="' . htmlspecialchars($targetUrl) . '" target="_blank">' . htmlspecialchars($objid["title"]) . '</a>';
                $cGroup = $objid["seq"];
            }
            $_SESSION["linkMenu"] = $linkMenu;
        }
    }
}
?>