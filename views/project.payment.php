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
    $B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
?>
<html>
    <head>
        <title id="page-title">Pembayaran Termin</title>
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/frm.css" />
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
    <script type="text/javascript" src="/rab-project/dev/js/project.payment.js?v=20261023-fix3"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Pembayaran <em>Termin</em>";
        }
    </script>
</html>