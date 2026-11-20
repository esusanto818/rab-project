<?php
    /*
    Document        : Kartu Hutang & Aging AP
    Description     : Laporan kartu hutang per supplier + aging invoice supplier (non-void/cancel). Read-only. Menu seq 6039.
    File related    : - ap.aging.js
                      - ap.agingCRD.php
                      - sql/2026-11-17.ar_ap_aging_card.sql (view v_ap_card / v_ap_aging)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Kartu Hutang &amp; Aging</title>
        <link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="../css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="../css/frm.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="../js/ap.aging.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Kartu <em>Hutang</em> &amp; Aging";
        }
        if (document.getElementById('editorWdw')) {
            document.getElementById('editorWdw').style.display = 'none';
        }
    </script>
</html>
