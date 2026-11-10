<?php
/**
 * Document      : m.taxexchangerate.pdf.php
 * Description   : Print View / PDF Export for Kurs Pajak Kemenkeu
 */
set_time_limit(0);
if (!isset($_SESSION)) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// PDF kurs pajak tetap mengikuti autentikasi dan hak baca menu Master Kurs Pajak.
require_once __DIR__ . '/../php-func/user.valid.php';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    exit('Anda tidak memiliki hak baca Master Kurs Pajak.');
}

require_once 'db_connection.PHP';

$hdrId = intval($_GET['id'] ?? $_REQUEST['id'] ?? 0);

if ($hdrId <= 0) {
    $stmtLast = $pdo->query("SELECT id FROM tax_exchange_rate_hdr ORDER BY start_date DESC LIMIT 1");
    $hdrId = $stmtLast->fetchColumn();
}

$hdr = null;
$details = [];

if ($hdrId) {
    $stmtHdr = $pdo->prepare("SELECT * FROM tax_exchange_rate_hdr WHERE id = :id");
    $stmtHdr->execute(['id' => $hdrId]);
    $hdr = $stmtHdr->fetch(PDO::FETCH_ASSOC);

    $stmtDtl = $pdo->prepare("
        SELECT * FROM tax_exchange_rate_dtl 
        WHERE hdr_id = :hdr_id 
        ORDER BY currency_code ASC
    ");
    $stmtDtl->execute(['hdr_id' => $hdrId]);
    $details = $stmtDtl->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daftar Kurs Pajak Kemenkeu</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h2 {
            margin: 0 0 5px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header p {
            margin: 3px 0;
            font-size: 13px;
        }
        .info-table {
            width: 100%;
            margin-bottom: 15px;
            font-size: 12px;
        }
        .info-table td {
            padding: 3px 0;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #ccc;
            padding: 8px 10px;
        }
        table.data-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .up { color: #2e9e4f; font-weight: bold; }
        .down { color: #c0392b; font-weight: bold; }
        .flat { color: #555; }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 10px;
            color: #777;
        }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 15px;">
        <button onclick="window.print();" style="padding: 8px 16px; font-weight: bold; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px;">🖨️ Cetak / Print PDF</button>
    </div>

    <div class="header">
        <h2>KEMENTERIAN KEUANGAN REPUBLIK INDONESIA</h2>
        <p><strong>KURS PAJAK MINGGUAN (TUKAR VALUTA ASING TERHADAP RUPIAH)</strong></p>
    </div>

    <?php if ($hdr): ?>
        <table class="info-table">
            <tr>
                <td width="18%"><strong>Nomor KMK</strong></td>
                <td width="2%">:</td>
                <td width="80%"><strong><?= htmlspecialchars($hdr['kmk_no']) ?></strong></td>
            </tr>
            <tr>
                <td><strong>Masa Berlaku</strong></td>
                <td>:</td>
                <td><?= date('d-m-Y', strtotime($hdr['start_date'])) ?> s/d <?= date('d-m-Y', strtotime($hdr['end_date'])) ?></td>
            </tr>
            <tr>
                <td><strong>Tanggal Update DB</strong></td>
                <td>:</td>
                <td><?= date('d-m-Y H:i', strtotime($hdr['created_at'])) ?></td>
            </tr>
        </table>

        <table class="data-table">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th width="12%">Kode ISO</th>
                    <th width="35%">Nama Mata Uang</th>
                    <th width="12%">Multiplier</th>
                    <th width="18%">Kurs KMK (IDR)</th>
                    <th width="18%">Kurs Murni / Unit (IDR)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                foreach ($details as $row): 
                    $dirClass = ($row['change_direction'] === 'UP') ? 'up' : (($row['change_direction'] === 'DOWN') ? 'down' : 'flat');
                    $dirIcon = ($row['change_direction'] === 'UP') ? '▲' : (($row['change_direction'] === 'DOWN') ? '▼' : '▬');
                ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="text-center"><strong><?= htmlspecialchars($row['currency_code']) ?></strong></td>
                        <td class="text-left"><?= htmlspecialchars($row['currency_name']) ?></td>
                        <td class="text-center"><?= number_format($row['unit_multiplier'], 0) ?></td>
                        <td class="text-right"><strong>Rp <?= number_format($row['rate'], 2, ',', '.') ?></strong></td>
                        <td class="text-right">Rp <?= number_format($row['rate_per_unit'], 4, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center; color: red;">Data Kurs Pajak tidak ditemukan.</p>
    <?php endif; ?>

    <div class="footer">
        Dicetak pada: <?= date('d-m-Y H:i:s') ?> | Sistem ERP proyekgmn
    </div>

</body>
</html>
