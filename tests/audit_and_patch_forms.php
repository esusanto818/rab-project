<?php
if (php_sapi_name() !== 'cli') exit("Jalankan via CLI.\n");

$ROOT_DIR  = realpath(__DIR__ . '/..');
$THEME_CSS = $ROOT_DIR . '/css/app-theme.css';
$logs      = [];
$patched   = 0;
$errors    = [];

echo "=== MEMULAI AUDIT & PATCHING SELURUH FORM & MODUL JS ===\n\n";

// ---------------------------------------------------------
// 1. Gabungkan Layout Core CSS Lama ke css/app-theme.css
//    (Mengembalikan tampilan Toolbar Atas & Layout Form tanpa memecah single CSS)
// ---------------------------------------------------------
$themeContent = file_get_contents($THEME_CSS);
if (strpos($themeContent, 'CORE LEGACY LAYOUT & TOOLBAR RESTORATION') === false) {
    $legacyCore = "\n\n/* ==========================================================\n"
                . "   CORE LEGACY LAYOUT & TOOLBAR RESTORATION\n"
                . "   ========================================================== */\n"
                . "@import url('https://googleapis.com');\n";

    $coreFiles = ['mainCSS.css', 'header.black.css', 'grddetail.css', 'frm.css'];
    foreach ($coreFiles as $cf) {
        $path = $ROOT_DIR . '/css/' . $cf;
        if (file_exists($path)) {
            $cssRaw = file_get_contents($path);
            // Hapus pemaksaan warna tombol lama agar palet UAT v3 tetap dominan
            $cssRaw = preg_replace('/\.(?:grey|purple|blue|green|red)-field-button\s*\{[^}]*\}/i', '', $cssRaw);
            $legacyCore .= "\n/* --- Layout Base from css/$cf --- */\n" . $cssRaw . "\n";
        }
    }

    // Pastikan tombol toolbar atas sejajar rapi dengan ikonnya
    $legacyCore .= <<<CSS

/* Perapian Toolbar Atas & Material Icons */
.material-icons {
    font-family: 'Material Icons';
    font-weight: normal;
    font-style: normal;
    font-size: 18px;
    line-height: 1;
    letter-spacing: normal;
    text-transform: none;
    display: inline-block;
    white-space: nowrap;
    word-wrap: normal;
    direction: ltr;
    vertical-align: middle;
}
#divToolbar a, .toolbar-top a, .header-links a {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    text-decoration: none;
    border-radius: 4px;
}
CSS;

    // Sisipkan di bagian ATAS app-theme.css agar aturan UAT v3 di bawah tetap menang (cascade)
    file_put_contents($THEME_CSS, $legacyCore . "\n\n" . $themeContent);
    echo "[PATCH] Layout toolbar & Material Icons digabungkan ke css/app-theme.css\n";
    $logs[] = "- **[PATCH]** Menggabungkan layout dasar (`mainCSS`, `header.black`, `grddetail`, `frm`) & `Material Icons` ke `css/app-theme.css`.";
    $patched++;
}

// ---------------------------------------------------------
// 2. Audit & Patching Seluruh Views (PHP Lint + Tag HTML Rusak)
// ---------------------------------------------------------
$viewFiles = glob($ROOT_DIR . '/views/*.php') ?: [];
foreach ($viewFiles as $vf) {
    $rel  = str_replace($ROOT_DIR . '/', '', $vf);
    $code = file_get_contents($vf);
    $orig = $code;

    // Deteksi dan perbaiki sisa karakter '<' yatim tepat setelah </button> atau di awal baris HTML
    $code = preg_replace('/(<\/button>)\s*<\s*(\r?\n)/i', "$1$2", $code);
    $code = preg_replace('/^\s*<\s*$/m', '', $code);

    // Pastikan Material Icons font tersedia jika view memakai <i class="material-icons">
    if (strpos($code, 'material-icons') !== false && strpos($code, 'Material+Icons') === false) {
        $code = str_ireplace(
            '</head>',
            "    <link href=\"https://googleapis.com\" rel=\"stylesheet\" />\n</head>",
            $code
        );
    }

    if ($code !== $orig) {
        file_put_contents($vf, $code);
        echo "[PATCH] Memperbaiki markup HTML/Icons pada $rel\n";
        $logs[] = "- **[PATCH]** Memperbaiki markup/ikon pada `$rel`.";
        $patched++;
    }

    // Cek PHP Syntax (php -l)
    exec('php -l ' . escapeshellarg($vf) . ' 2>&1', $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $errors[] = "$rel (PHP Syntax Error): " . implode(' ', $lintOut);
    }
}
echo "[AUDIT] Selesai memeriksa " . count($viewFiles) . " file form di views/\n";

// ---------------------------------------------------------
// 3. Audit & Patching Seluruh Modul JavaScript (js/*.js)
// ---------------------------------------------------------
$jsFiles = glob($ROOT_DIR . '/js/*.js') ?: [];
$hasNode = trim((string)shell_exec('which node 2>/dev/null')) !== '';

foreach ($jsFiles as $jf) {
    $rel  = str_replace($ROOT_DIR . '/', '', $jf);
    $code = file_get_contents($jf);
    $orig = $code;

    // Deteksi sisa karakter '<' yatim di dalam string HTML JS setelah </button>
    $code = preg_replace('/(<\/button>)\s*<\s*([\x27"])/i', '$1$2', $code);

    // Perbaiki kemungkinan kutip ganda bertabrakan pada class="app-btn ..." di dalam string JS kutip ganda
    $code = preg_replace_callback('/"([^"\n]*<button\b[^"\n]*)"/', function ($m) {
        return '"' . str_replace('class="app-btn', 'class=\\"app-btn', str_replace('app-btn-primary"', 'app-btn-primary\\"', str_replace('app-btn-dark"', 'app-btn-dark\\"', str_replace('app-btn-danger"', 'app-btn-danger\\"', $m[1])))) . '"';
    }, $code);

    if ($code !== $orig) {
        file_put_contents($jf, $code);
        echo "[PATCH] Memperbaiki escaping/tag tombol pada $rel\n";
        $logs[] = "- **[PATCH]** Memperbaiki escaping/tag `<button>` pada `$rel`.";
        $patched++;
    }

    // Cek JS Syntax jika Node.js terpasang
    if ($hasNode) {
        unset($jsOut);
        exec('node --check ' . escapeshellarg($jf) . ' 2>&1', $jsOut, $jsCode);
        if ($jsCode !== 0) {
            $errors[] = "$rel (JS Syntax Error): " . implode(' | ', array_slice($jsOut, 0, 3));
        }
    }
}
echo "[AUDIT] Selesai memeriksa " . count($jsFiles) . " file modul di js/\n\n";

// ---------------------------------------------------------
// 4. Tampilkan Hasil & Simpan Laporan
// ---------------------------------------------------------
if (count($errors) > 0) {
    echo "\033[31m[ERROR DITEMUKAN (" . count($errors) . ")]:\033[0m\n";
    foreach ($errors as $e) {
        echo "  -> $e\n";
        $logs[] = "- **[ERROR]** `$e`";
    }
} else {
    echo "\033[32m[OK] Seluruh " . count($viewFiles) . " views & " . count($jsFiles) . " modul JS bebas syntax error!\033[0m\n";
    $logs[] = "- **[PASS]** Seluruh `" . count($viewFiles) . "` file views & `" . count($jsFiles) . "` file JS lulus pemeriksaan sintaks.";
}

if (is_dir($ROOT_DIR . '/docs')) {
    $rep = "# Laporan Audit & Patching Form + Toolbar\n\n"
         . "- **Waktu:** " . date('Y-m-d H:i:s') . "\n"
         . "- **Total Patch Diterapkan:** `$patched`\n"
         . "- **Sisa Syntax Error:** `" . count($errors) . "`\n\n"
         . "## Rincian Audit & Perbaikan\n" . implode("\n", $logs) . "\n";
    file_put_contents($ROOT_DIR . '/docs/FORM_AUDIT_PATCH_REPORT.md', $rep);
}

exit(count($errors) > 0 ? 1 : 0);
