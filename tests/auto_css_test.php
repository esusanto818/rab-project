<?php
if (php_sapi_name() !== 'cli') {
    exit("Jalankan dari terminal CLI.\n");
}

$ROOT_DIR = is_dir(__DIR__ . '/../views') ? realpath(__DIR__ . '/..') : realpath(__DIR__ . '/../..');
$passed   = 0;
$failed   = 0;
$logs     = [];

function report(bool $ok, string $kode, string $pesan, array $details = []): void {
    global $passed, $failed, $logs;
    if ($ok) {
        echo "\033[32m[PASS]\033[0m [$kode] $pesan\n";
        $logs[] = "- **[PASS]** `[$kode]` $pesan";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m [$kode] $pesan\n";
        $logs[] = "- **[FAIL]** `[$kode]` $pesan";
        foreach (array_slice($details, 0, 10) as $d) {
            echo "       \033[33m-> $d\033[0m\n";
            $logs[] = "  - `$d`";
        }
        if (count($details) > 10) {
            $sisa = count($details) - 10;
            echo "       \033[33m-> ... dan $sisa temuan lainnya\033[0m\n";
            $logs[] = "  - *... dan $sisa temuan lainnya*";
        }
        $failed++;
    }
}

echo "=== MEMINDAI UNIFIKASI CSS REPO: $ROOT_DIR ===\n\n";

// [CSS-01] Keberadaan File CSS Utama
$cssFiles = glob($ROOT_DIR . '/css/*.css') ?: [];
$validCss = array_filter($cssFiles, fn($f) => filesize($f) > 500);
report(
    count($validCss) >= 1,
    'CSS-01',
    "Ditemukan " . count($validCss) . " file CSS valid di folder css/"
);

// [CSS-02] Tag <style> Tertanam di dalam views/ & includes/
$embeddedStyles = [];
$viewFiles = array_merge(
    glob($ROOT_DIR . '/views/*.php') ?: [],
    glob($ROOT_DIR . '/includes/*.php') ?: []
);

foreach ($viewFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/<style\b[^>]*>/i', $content, $matches)) {
        $embeddedStyles[] = str_replace($ROOT_DIR . '/', '', $file) . " (" . count($matches) . " blok <style>)";
    }
}
report(
    count($embeddedStyles) === 0,
    'CSS-02',
    count($embeddedStyles) === 0
        ? "Bersih: Tidak ada tag <style> tertanam pada " . count($viewFiles) . " file di views/ & includes/"
        : "Ditemukan " . count($embeddedStyles) . " file masih mengandung tag <style> lokal",
    $embeddedStyles
);

// [CSS-03] Inline Style Warna/Background Keras di views/ & js/
$inlineColorViolations = [];
$jsFiles = array_merge(
    glob($ROOT_DIR . '/js/*.js') ?: [],
    glob($ROOT_DIR . '/js-ext/*.js') ?: [],
    glob($ROOT_DIR . '/js-func/*.js') ?: []
);
$scanTargets = array_merge($viewFiles, $jsFiles);

foreach ($scanTargets as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $rel   = str_replace($ROOT_DIR . '/', '', $file);
    foreach ($lines as $num => $line) {
        if (preg_match('/^\s*(\/\/|\/\*|\*|#)/', $line)) continue;
        if (preg_match('/style\s*=\s*["\'][^"\']*(background(-color)?|color|border-color)\s*:\s*(#[0-9a-fA-F]{3,8}|rgb|rgba|white|black|yellow|amber|slate)/i', $line)) {
            $inlineColorViolations[] = "$rel:" . ($num + 1);
        }
    }
}
report(
    count($inlineColorViolations) === 0,
    'CSS-03',
    count($inlineColorViolations) === 0
        ? "Bersih: Tidak ada inline style warna keras pada " . count($scanTargets) . " file views/ & js/"
        : "Ditemukan " . count($inlineColorViolations) . " baris dengan inline style warna/background manual",
    $inlineColorViolations
);

// [CSS-04] Pemisahan CSS dari JavaScript (Inject <style> via JS)
$jsCssInject = [];
foreach ($jsFiles as $file) {
    $content = file_get_contents($file);
    $rel     = str_replace($ROOT_DIR . '/', '', $file);
    if (preg_match('/createElement\s*\(\s*[\'"]style[\'"]\s*\)|<style/i', $content)) {
        $jsCssInject[] = $rel;
    }
}
report(
    count($jsCssInject) === 0,
    'CSS-04',
    count($jsCssInject) === 0
        ? "Bersih: Tidak ada injeksi elemen <style> di dalam " . count($jsFiles) . " file JavaScript"
        : "Ditemukan " . count($jsCssInject) . " file JS menyuntikkan CSS secara dinamis",
    $jsCssInject
);

// [CSS-05] Kelengkapan Komponen UI di CSS Terpusat
$combinedCss = '';
foreach ($validCss as $cf) {
    $combinedCss .= "\n" . file_get_contents($cf);
}
$hasBtnRule  = (bool)preg_match('/(\.btn|\.button|x-btn)/i', $combinedCss);
$hasGridRule = (bool)preg_match('/(\.grid|x-grid|table)/i', $combinedCss);
$hasFormRule = (bool)preg_match('/(\.form|x-form|input|select)/i', $combinedCss);

report(
    $hasBtnRule && $hasGridRule && $hasFormRule,
    'CSS-05',
    "Seluruh aturan UI inti (Button, Grid, Form) terdefinisi di file CSS terpusat"
);

// [CSS-06] Validasi Tautan <link rel="stylesheet"> & Cek Legacy CSS Terpecah
$brokenCssLinks = [];
$legacyMultiCss = [];
foreach ($viewFiles as $file) {
    $content = file_get_contents($file);
    $rel     = str_replace($ROOT_DIR . '/', '', $file);
    if (preg_match_all('/<link[^>]+href=["\']([^"\']+\.css(?:\?[^"\']*)?)["\']/i', $content, $matches)) {
        // Cek apakah masih memanggil CSS lama yang terpisah (header.black.css, grddetail.css, frm.css)
        foreach ($matches[1] as $href) {
            if (preg_match('/(header\.black\.css|grddetail\.css|frm\.css|mainCSS\.css)/i', $href)) {
                $legacyMultiCss[] = "$rel -> " . basename(preg_replace('/\?.*$/', '', $href));
            }
            if (preg_match('/^(https?:)?\/\//i', $href) || strpos($href, '<?') !== false) continue;
            $cleanPath = preg_replace('/\?.*$/', '', $href);
            // Normalisasi prefix /rab-project/dev/ atau /proyekgmn-dev/
            $cleanPath = preg_replace('#^/(rab-project/dev|proyekgmn-dev)/#i', '', $cleanPath);
            $cleanPath = ltrim(preg_replace('/^(\.\.\/)+/', '', $cleanPath), '/');
            if (!file_exists($ROOT_DIR . '/' . $cleanPath)) {
                $brokenCssLinks[] = "$rel -> $href";
            }
        }
    }
}
report(
    count($brokenCssLinks) === 0,
    'CSS-06',
    count($brokenCssLinks) === 0
        ? "Seluruh tautan <link rel=\"stylesheet\"> di views/ mengarah ke file fisik yang valid"
        : "Ditemukan " . count($brokenCssLinks) . " tautan CSS mati (404)",
    $brokenCssLinks
);

report(
    count($legacyMultiCss) === 0,
    'CSS-07',
    count($legacyMultiCss) === 0
        ? "Seluruh modul sudah memakai single unified CSS (bebas file CSS terpisah lama)"
        : "Ditemukan " . count($legacyMultiCss) . " pemanggilan file CSS terpisah lama (header.black/grddetail/frm)",
    $legacyMultiCss
);

if (is_dir($ROOT_DIR . '/docs')) {
    $reportMd = "# Laporan Automated CSS Unification Test\n\n"
              . "- **Waktu Eksekusi:** " . date('Y-m-d H:i:s') . "\n"
              . "- **Ringkasan:** `$passed PASS` | `$failed FAIL`\n\n"
              . "## Detail Hasil Audit CSS\n" . implode("\n", $logs) . "\n";
    file_put_contents($ROOT_DIR . '/docs/CSS_UNIFICATION_TEST_REPORT.md', $reportMd);
}

echo "\n=== HASIL AKHIR CSS AUDIT: $passed PASS | $failed FAIL ===\n";
exit($failed > 0 ? 1 : 0);
