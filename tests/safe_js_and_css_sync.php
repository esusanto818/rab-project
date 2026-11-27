<?php
if (php_sapi_name() !== 'cli') exit("CLI only.\n");

$ROOT_DIR  = realpath(__DIR__ . '/..');
$THEME_CSS = $ROOT_DIR . '/css/app-theme.css';

// 1. Terapkan HANYA transformasi AST/Properti aman pada js/*.js (tanpa regex HTML <button>)
$jsFiles = glob($ROOT_DIR . '/js/*.js') ?: [];
foreach ($jsFiles as $jf) {
    $code = file_get_contents($jf);

    // A. Ganti cls warna lama ExtJS ke standar UAT v3
    $code = preg_replace('/field-button\s+red-field-button/i', 'btn-danger', $code);
    $code = preg_replace('/field-button\s+green-field-button/i', 'btn-primary', $code);
    $code = preg_replace('/field-button\s+(?:grey|blue|purple)-field-button/i', 'btn-dark', $code);

    // B. Hapus style: 'clear:both' manual pada config Ext.Button
    $code = preg_replace('/,?\s*\bstyle\s*:\s*[\'"]clear\s*:\s*both;?[\'"]/i', '', $code);

    // C. Hapus IIFE injeksi <style> (CSS-04) yang sudah dipindahkan ke css/app-theme.css
    $code = preg_replace(
        '/\(\s*function\s*[a-zA-Z0-9_]*\s*\(\s*\)\s*\{[\s\S]*?createElement\s*\(\s*[\'"]style[\'"]\s*\)[\s\S]*?\}\s*\)\s*\(\s*\)\s*;?/i',
        '/* [CSS-UNIFIED] Styling dipindahkan ke css/app-theme.css */',
        $code
    );

    file_put_contents($jf, $code);
}

// 2. Pastikan css/app-theme.css menata seluruh <button> HTML di dalam area aplikasi secara terpusat
$theme = file_get_contents($THEME_CSS);
if (strpos($theme, 'GLOBAL HTML BUTTON UAT V3 OVERRIDE') === false) {
    $globalBtnCss = <<<CSS

/* ==========================================================
   GLOBAL HTML BUTTON UAT V3 OVERRIDE (TANPA UBAH STRING JS)
   ========================================================== */
#divMainGrid button,
.x-window button,
.x-panel button,
.poActionBtn,
.rtActionBtn,
.ycAllBtn {
    border-radius: 6px !important;
    font-family: 'Inter', sans-serif !important;
    font-weight: 600 !important;
    padding: 5px 12px !important;
    cursor: pointer;
    transition: background-color 0.15s ease !important;
}
CSS;
    file_put_contents($THEME_CSS, $theme . "\n" . $globalBtnCss . "\n");
}

// 3. Verifikasi sintaks seluruh 88 file JS menggunakan node --check
$jsErr = 0;
foreach ($jsFiles as $jf) {
    unset($out);
    exec('node --check ' . escapeshellarg($jf) . ' 2>&1', $out, $ret);
    if ($ret !== 0) {
        echo "\033[31m[JS ERROR]\033[0m " . basename($jf) . ": " . implode(' | ', array_slice($out, 0, 2)) . "\n";
        $jsErr++;
    }
}

echo "\n=== HASIL VERIFIKASI SINTAKS JS: " . (count($jsFiles) - $jsErr) . "/" . count($jsFiles) . " VALID | $jsErr ERROR ===\n";
exit($jsErr > 0 ? 1 : 0);
