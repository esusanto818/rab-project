<?php
    /*
    Document        : Pembayaran Termin
    Description     : Kelola pembayaran termin dari client per proyek + cetak tanda terima.
    File related    : - project.payment.js
                      - project.paymentCRD.php
                      - project.payment.print.php (cetak tanda terima)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Pembayaran Termin</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/frm.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/frm.css" />
>>>>>>> Stashed changes
        <style>
            #editorForm { top: 50%; width: 90%; height: 95%; }
            #addForm { top: 50%; width: 85%; height: 80%; }
        </style>
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/project.payment.js?v=20261023-fix3"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/project.payment.js?v=20261023-fix3"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Pembayaran <em>Termin</em>";
        }
    </script>
</html>