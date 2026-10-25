<?php
// 1. Matikan PCRE JIT untuk mencegah warning alokasi memori pada PHP 7
ini_set('pcre.jit', '0');

// 2. Memulai session jika belum aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Cek Autentikasi User (Keamanan Dasar)
if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
        exit;
    }
    header('Location: index.php');
    exit;
}

// =========================================================================
// 3.1 BYPASS ROUTER KHUSUS FILE PDF / REPORT DIRECT
// =========================================================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

if (preg_match('/\/pdf\/([a-zA-Z0-9_\-\.]+\.php)/i', $requestUri, $matches)) {
    $pdfFileName = $matches[1];
    $possiblePaths = [
        __DIR__ . '/pdf/' . $pdfFileName,
        __DIR__ . '/../pdf/' . $pdfFileName,
        $_SERVER['DOCUMENT_ROOT'] . '/proyekgmn/pdf/' . $pdfFileName
    ];

    foreach ($possiblePaths as $pdfFilePath) {
        if (file_exists($pdfFilePath)) {
            chdir(dirname($pdfFilePath));
            require_once $pdfFilePath;
            exit;
        }
    }
}

// =========================================================================
// 3.2 BYPASS ROUTER KHUSUS AJAX POST / REQUEST CRUD EXTJS (FIX UTAMA)
// =========================================================================
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['task']) || $isAjax) {
    
    // Jika request mengarah langsung ke file CRUD di /php-crud/
    if (preg_match('/\/php-crud\/([a-zA-Z0-9_\-\.]+\.php)/i', $requestUri, $matches)) {
        $crudFile = __DIR__ . '/php-crud/' . $matches[1];
        if (file_exists($crudFile)) {
            chdir(__DIR__ . '/php-crud');
            require_once $crudFile;
            exit;
        }
    }
}
// =========================================================================

// 4. Load Database Class secara Absolut
require_once __DIR__ . '/php-class/crudClass.php';

// 5. Tangkap ID Menu dari Query String / Request / Session
$user_id = intval($_SESSION["user_id"]);
$form_id = 0;

if (isset($_REQUEST['menu_id']) && intval($_REQUEST['menu_id']) > 0) {
    $form_id = intval($_REQUEST['menu_id']);
} elseif (isset($_REQUEST['form_id']) && intval($_REQUEST['form_id']) > 0) {
    $form_id = intval($_REQUEST['form_id']);
} elseif (isset($_GET['id'])) {
    $form_id = intval($_GET['id']);
} elseif (isset($_GET['menu'])) {
    $form_id = intval($_GET['menu']);
} elseif (preg_match('/menu=(\d+)/i', $requestUri, $matches)) {
    $form_id = intval($matches[1]);
} elseif (isset($_SESSION['current_menu_id'])) {
    $form_id = intval($_SESSION['current_menu_id']);
}

if ($form_id <= 0) {
    http_response_code(400);
    die("Akses ditolak: Parameter menu ID tidak valid.");
}

// Simpan Form ID aktif ke Session agar request AJAX berikutnya dapat mengingatnya
$_SESSION['current_menu_id'] = $form_id;

try {
    $db = new crud();

    // 6. Verifikasi Hak Akses User & Ambil URL Target
    $sql = "
        SELECT f.id, f.title, f.php_url, u.access_level
        FROM m_form_name f
        INNER JOIN m_user_access_menu u ON u.form_id = f.id
        WHERE f.id = :form_id 
          AND u.user_id = :user_id
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        'form_id' => $form_id,
        'user_id' => $user_id
    ]);
    
    $formData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$formData) {
        http_response_code(403);
        die("Akses ditolak: Anda tidak memiliki izin untuk membuka halaman ini.");
    }

    // Simpan Access Level ke Session agar dibaca oleh ExtJS formAccess
    $_SESSION['FormAccess'] = $formData['access_level'];

    $targetFile = trim($formData['php_url'] ?? '');

    if (empty($targetFile)) {
        http_response_code(404);
        die("Error: File tujuan belum dikonfigurasi di database.");
    }

    // 7. Sanitasi Path File (Hapus prefix ../, php/, atau /)
    $cleanFileName = preg_replace('/^(\.\.\/|php\/|\/)+/', '', $targetFile);
    $filePath = __DIR__ . '/views/' . $cleanFileName;

    if (file_exists($filePath)) {
        // Ubah working directory ke /php agar include internal file tidak error
        chdir(__DIR__ . '/views');

        // Tangkap output HTML dengan Buffer
        ob_start();
        include $cleanFileName;
        $htmlOutput = ob_get_clean();

        // A. Otomatis disisipkan <base href="/proyekgmn/"> agar semua path CSS/JS tidak broken
        /*
        if (stripos($htmlOutput, '<head>') !== false) {
            $htmlOutput = preg_replace('/<head>/i', "<head>\n  <base href=\"/proyekgmn/\">", $htmlOutput, 1);
        } else {
            $htmlOutput = "<base href=\"/proyekgmn/\">\n" . $htmlOutput;
        }
        */
        // B. AUTO-REPLACE STRING PATH RELATIF TANPA UpBAH DUSUN FILE DARI FRONTEND
        $patterns = [
<<<<<<< Updated upstream
            '="../css/'      => '="/rab-project/dev/css/',
            '="../js/'       => '="/rab-project/dev/js/',
            '="../js-ext/'   => '="/rab-project/js-ext/',
            '="../pdf/'      => '="/rab-project/pdf/',
            '="../tcpdf_new/'=> '="/rab-project/tcpdf_new/',
            '="../js-func/'  => '="/rab-project/js-func/',
            '="../php-crud/' => '="/rab-project/dev/php-crud/',
=======
            '="../css/'      => '="/rab-project/live/css/',
            '="../js/'       => '="/rab-project/live/js/',
            '="../js-ext/'   => '="/rab-project/live/js-ext/',
            '="../pdf/'      => '="/rab-project/live/pdf/',
            '="../tcpdf_new/'=> '="/rab-project/live/tcpdf_new/',
            '="../js-func/'  => '="/rab-project/live/js-func/',
            '="../php-crud/' => '="/rab-project/live/php-crud/',
>>>>>>> Stashed changes

        ];
        $htmlOutput = strtr($htmlOutput, $patterns);

        echo $htmlOutput;
        exit;
    } else {
        http_response_code(404);
        die("Error 404: File <b>" . htmlspecialchars($cleanFileName) . "</b> tidak ditemukan pada lokasi server.");
    }

} catch (Exception $e) {
    error_log("Router Error: " . $e->getMessage());
    http_response_code(500);
    die("Terjadi kesalahan pada server saat memproses navigasi.");
}
?>