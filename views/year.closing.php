<?php
    /*
    Document        : Tutup Buku Tahunan
    Description     : Jurnal penutup tahunan: pendapatan & beban ke Laba Ditahan.
    File related    : - year.closing.js
                      - year.closingCRD.php
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
    // [PORTABEL] path dasar aplikasi mengikuti lokasi file ini (mis. /proyek, /rab-project, /rab-project/dev); tidak hard-code
    $B = rtrim(dirname(dirname($_SERVER["SCRIPT_NAME"])), "/");
?>
<html>
    <head>
        <title id="page-title">Tutup Buku Tahunan</title>
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="<?php echo $B; ?>/css/frm.css" />
        <style>
            #editorForm { top: 50%; width: 80%; height: 85%; }
            #addForm { top: 50%; width: 80%; height: 85%; }
        </style>
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="<?php echo $B; ?>/js/year.closing.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Tutup Buku <em>Tahunan</em>";
        }
    </script>
</html>