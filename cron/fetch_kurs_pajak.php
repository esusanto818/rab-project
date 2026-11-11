<?php
// Block direct HTTP access before loading application dependencies.
if (
    PHP_SAPI !== 'cli' &&
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(404);
    exit;
}

/**
 * Cron Task / Scraper: fetch_kurs_pajak.php
 * Mengambil data Kurs Pajak mingguan dari Kemenkeu (https://fiskal.kemenkeu.go.id/informasi-publik/kurs-pajak)
 * dan menyimpannya secara otomatis ke database lokal proyekgmn (tax_exchange_rate_hdr & dtl).
 *
 * Jalankan via CLI / Cron setiap hari Rabu:
 * 0 8 * * 3 php /home/myadmin/proyekgmn/cron/fetch_kurs_pajak.php
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

function parseIndonesianDate($dateStr) {
    $months = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'Mei' => '05', 'Jun' => '06', 'Jul' => '07', 'Agt' => '08',
        'Sep' => '09', 'Okt' => '10', 'Nov' => '11', 'Desember' => '12'
    ];

    $parts = preg_split('/\s+/', trim($dateStr));
    if (count($parts) >= 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $month = $months[$parts[1]] ?? '01';
        $year = $parts[2];
        return "$year-$month-$day";
    }
    return date('Y-m-d');
}

function fetchAndStoreTaxExchangeRates($pdo = null) {
    // 1. Resolve PDO Connection if null
    if ($pdo === null) {
        global $pdo, $db_conn, $conn, $db;
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            // $pdo exists locally
        } else {
            // Gunakan koneksi standar aplikasi; jangan menyimpan credential database di source.
            require_once __DIR__ . '/../php-class/crudClass.php';
            try {
                $pdo = new crud();
            } catch (PDOException $e) {
                return ['success' => false, 'message' => "Database Connection Error: " . $e->getMessage()];
            }
        }
    }

    $url = "https://fiskal.kemenkeu.go.id/informasi-publik/kurs-pajak";

    $html = false;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
    }

    if ($html === false || empty($html)) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36\r\n"
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];
        $context = stream_context_create($opts);
        $html = @file_get_contents($url, false, $context);
    }

    if (empty($html)) {
        return ['success' => false, 'message' => "Gagal mengambil HTML dari $url"];
    }

    // 1. Extract KMK No
    $kmkNo = 'KMK Kurs Pajak';
    if (preg_match('/KMK\s+Nomor\s+([^\<]+)/i', $html, $matches)) {
        $kmkNo = trim($matches[1]);
    }

    // 2. Extract Tanggal Berlaku (Start Date & End Date)
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+6 days'));
    if (preg_match('/Tanggal\s+berlaku:\s*(\d+\s+[A-Za-z]+\s+\d{4})\s*-\s*(\d+\s+[A-Za-z]+\s+\d{4})/i', $html, $matches)) {
        $startDate = parseIndonesianDate($matches[1]);
        $endDate = parseIndonesianDate($matches[2]);
    }

    // 3. Extract File URL salinan KMK
    $fileUrl = null;
    if (preg_match('/<a[^>]+class=["\']view-pdf["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
        $fileUrl = "https://fiskal.kemenkeu.go.id" . $matches[1];
    }

    // Check existing header
    $stmtCheck = $pdo->prepare("SELECT id FROM tax_exchange_rate_hdr WHERE start_date = :start_date AND end_date = :end_date");
    $stmtCheck->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $existingHdr = $stmtCheck->fetch();

    if ($existingHdr) {
        $hdrId = $existingHdr['id'];
        $stmtUpdateHdr = $pdo->prepare("UPDATE tax_exchange_rate_hdr SET kmk_no = :kmk_no, file_url = :file_url WHERE id = :id");
        $stmtUpdateHdr->execute(['kmk_no' => $kmkNo, 'file_url' => $fileUrl, 'id' => $hdrId]);

        $pdo->prepare("DELETE FROM tax_exchange_rate_dtl WHERE hdr_id = :hdr_id")->execute(['hdr_id' => $hdrId]);
    } else {
        $stmtInsHdr = $pdo->prepare("INSERT INTO tax_exchange_rate_hdr (kmk_no, start_date, end_date, file_url, remark) VALUES (:kmk_no, :start_date, :end_date, :file_url, 'Auto-synced via Kemenkeu') RETURNING id");
        $stmtInsHdr->execute([
            'kmk_no' => $kmkNo,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'file_url' => $fileUrl
        ]);
        $hdrId = $stmtInsHdr->fetchColumn();
    }

    // Load m_currency map (Code -> ID)
    $currMap = [];
    $stmtCurr = $pdo->query("SELECT id, UPPER(TRIM(init)) AS code FROM m_currency WHERE init IS NOT NULL AND init != ''");
    while ($row = $stmtCurr->fetch()) {
        $currMap[$row['code']] = $row['id'];
    }

    // 4. Parse Table Rows
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $trMatches);
    $insertedCount = 0;

    $stmtInsDtl = $pdo->prepare("
        INSERT INTO tax_exchange_rate_dtl 
        (hdr_id, currency_id, currency_code, currency_name, unit_multiplier, rate, rate_per_unit, prev_rate, change_amount, change_direction) 
        VALUES 
        (:hdr_id, :currency_id, :currency_code, :currency_name, :unit_multiplier, :rate, :rate_per_unit, :prev_rate, :change_amount, :change_direction)
    ");

    foreach ($trMatches[1] as $trHtml) {
        if (strpos($trHtml, '<th') !== false || strpos($trHtml, 'Mata Uang') !== false) {
            continue;
        }

        $currencyCode = '';
        $currencyName = '';

        if (preg_match('/<span[^>]*class=[\'"]hidden-xs[\'"][^>]*>\s*([^\<\)]+)\s*\(([A-Z]{3})\)\s*<\/span>/i', $trHtml, $mCurr)) {
            $currencyName = trim($mCurr[1]);
            $currencyCode = trim($mCurr[2]);
        } elseif (preg_match('/([A-Z]{3})/', $trHtml, $mCode)) {
            $currencyCode = trim($mCode[1]);
            $currencyName = $currencyCode;
        }

        if (empty($currencyCode)) continue;

        $rate = 0.0;
        if (preg_match('/<div[^>]*class=[\'"][^\'"]*m-l-5[^\'"]*[\'"][^>]*>\s*([\d\.,]+)\s*<\/div>/i', $trHtml, $mRate)) {
            $rateStr = str_replace('.', '', $mRate[1]);
            $rateStr = str_replace(',', '.', $rateStr);
            $rate = floatval($rateStr);
        }

        if ($rate <= 0) continue;

        $changeAmount = 0.0;
        $direction = 'FLAT';

        if (strpos($trHtml, 'curr-up') !== false) {
            $direction = 'UP';
        } elseif (strpos($trHtml, 'curr-down') !== false) {
            $direction = 'DOWN';
        }

        if (preg_match('/td[^>]*curr-change[^>]*>\s*([\d\.,]+)/i', $trHtml, $mChange)) {
            $changeStr = str_replace('.', '', $mChange[1]);
            $changeStr = str_replace(',', '.', $changeStr);
            $changeAmount = floatval($changeStr);
        }

        $multiplier = ($currencyCode === 'JPY') ? 100.0 : 1.0;
        $ratePerUnit = $rate / $multiplier;
        $currencyId = $currMap[$currencyCode] ?? null;

        $prevRate = 0.0;
        if ($direction === 'UP') {
            $prevRate = $rate - $changeAmount;
        } elseif ($direction === 'DOWN') {
            $prevRate = $rate + $changeAmount;
        } else {
            $prevRate = $rate;
        }

        $stmtInsDtl->execute([
            'hdr_id' => $hdrId,
            'currency_id' => $currencyId,
            'currency_code' => $currencyCode,
            'currency_name' => $currencyName,
            'unit_multiplier' => $multiplier,
            'rate' => $rate,
            'rate_per_unit' => $ratePerUnit,
            'prev_rate' => $prevRate,
            'change_amount' => $changeAmount,
            'change_direction' => $direction
        ]);

        $insertedCount++;
    }

    return [
        'success' => true,
        'hdr_id' => $hdrId,
        'kmk_no' => $kmkNo,
        'period' => "$startDate s/d $endDate",
        'items_count' => $insertedCount
    ];
}

// Runner hanya aktif jika file ini dieksekusi langsung melalui CLI.
// Pemanggilan melalui require_once dari aplikasi tidak menjalankan runner.
// Parameter HTTP ?run tidak lagi dapat memicu proses sinkronisasi.
if (
    PHP_SAPI === 'cli' &&
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    echo "=== FETCHING KURS PAJAK KEMENKEU ===\n";
    global $pdo;
    $res = fetchAndStoreTaxExchangeRates($pdo ?? null);

    if ($res['success']) {
        echo "SUKSES! KMK: {$res['kmk_no']} | Periode: {$res['period']} | Total Items: {$res['items_count']}\n";
    } else {
        echo "ERROR: {$res['message']}\n";
    }
}