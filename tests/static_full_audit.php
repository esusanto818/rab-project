<?php
if (php_sapi_name() !== 'cli') exit("CLI only.\n");

$ROOT_DIR = realpath(__DIR__ . '/..');
$crudFiles = glob($ROOT_DIR . '/php-crud/*.php') ?: [];
$findings = ['CRITICAL' => [], 'HIGH' => [], 'MEDIUM' => []];

foreach ($crudFiles as $file) {
    $base = basename($file);
    $code = file_get_contents($file);
    $lines = explode("\n", $code);

    if (!preg_match('/(login|logout|auth|public)/i', $base)) {
        if (!preg_match('/\$_SESSION\s*\[\s*[\'"]user_id[\'"]\s*\]|check_session|cek_login/i', $code)) {
            $findings['CRITICAL'][] = "**[$base]** Tidak ditemukan validasi `\$_SESSION['user_id']` secara eksplisit di level endpoint.";
        }
        if (!preg_match('/role|permission|hak_akses|menu_id|is_admin|user\.valid\.php|FormAccess/i', $code)) {
            $findings['HIGH'][] = "**[$base]** Tidak ada pengecekan hak akses role/menu (RBAC) — berisiko *Broken Access Control* antar-role.";
        }
    }

    foreach ($lines as $idx => $line) {
        if (preg_match('/^\s*(\/\/|\/\*|\*|#)/', $line)) continue;
        if ($base === 'm.locationCRD.php' && strpos($line, "date('Y-m-d', strtotime") !== false) continue;
        if (preg_match('/(SELECT|INSERT|UPDATE|DELETE|WHERE|ORDER BY)\b.*\$_(POST|GET|REQUEST)\[/i', $line)) {
            $findings['CRITICAL'][] = "**[$base:" . ($idx + 1) . "]** Potensi SQL Injection: input `\$_POST/\$_GET` langsung disisipkan ke string SQL tanpa prepared statement.";
        }
    }

    if (preg_match('/(payment|grn|whrcv|issuing|invoice|closing|opname|wiprelease)/i', $base)) {
        if (!preg_match('/beginTransaction|BEGIN\b|inTransaction/i', $code)) {
            $findings['HIGH'][] = "**[$base]** Operasi finansial/persediaan multi-tabel berjalan tanpa blok transaksi atomik (`beginTransaction` / `COMMIT`).";
        }
        if (!preg_match('/FOR\s+UPDATE/i', $code)) {
            $findings['MEDIUM'][] = "**[$base]** Tidak menggunakan row-level lock (`FOR UPDATE`), berisiko *double-posting* saat submit paralel.";
        }
    }
}

$md = "# Fase 1: Full Verification Audit (Static & Security Scan)\n\n"
    . "- **Waktu Audit:** " . date('Y-m-d H:i:s') . "\n"
    . "- **Total Endpoint Dipindai:** `" . count($crudFiles) . "` file di `php-crud/`\n"
    . "- **Ringkasan Temuan:** `" . count($findings['CRITICAL']) . " CRITICAL` | `" . count($findings['HIGH']) . " HIGH` | `" . count($findings['MEDIUM']) . " MEDIUM`\n\n";

foreach (['CRITICAL', 'HIGH', 'MEDIUM'] as $sev) {
    $md .= "## Temuan $sev (" . count($findings[$sev]) . ")\n";
    if (empty($findings[$sev])) {
        $md .= "- Tidak ditemukan temuan pada kategori ini.\n\n";
    } else {
        foreach ($findings[$sev] as $item) {
            $md .= "- $item\n";
        }
        $md .= "\n";
    }
}

file_put_contents($ROOT_DIR . '/docs/01_FULL_VERIFICATION_AUDIT.md', $md);
echo "=== SELESAI FASE 1 AUDIT ===\n";
echo "CRITICAL : " . count($findings['CRITICAL']) . "\n";
echo "HIGH     : " . count($findings['HIGH']) . "\n";
echo "MEDIUM   : " . count($findings['MEDIUM']) . "\n";
echo "Laporan disimpan ke: docs/01_FULL_VERIFICATION_AUDIT.md\n";
