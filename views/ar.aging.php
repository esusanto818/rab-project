<?php
    /*
    Document        : Kartu Piutang & Aging AR
    Description     : Laporan kartu piutang per klien + aging tagihan resmi (released). Read-only. Menu seq 6038.
    File related    : - ar.aging.js
                      - ar.agingCRD.php
                      - sql/2026-11-17.ar_ap_aging_card.sql (view v_ar_card / v_ar_aging)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Kartu Piutang &amp; Aging</title>
        <link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="../js/ar.aging.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Kartu <em>Piutang</em> &amp; Aging";
        }
        if (document.getElementById('editorWdw')) {
            document.getElementById('editorWdw').style.display = 'none';
        }
    </script>
</html>
