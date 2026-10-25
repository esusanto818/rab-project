<?php
    /*
    Document        : Bin Location (Lokasi Rak)
    Description     : Master data lokasi rak per gudang untuk WMS.
    File related    : - bin.location.js
                      - bin.locationCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 70%; height: auto;}
        </style>
        <title id="page-title">Bin Location</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />        
>>>>>>> Stashed changes
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
    </head>
    <body>
    </body>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/bin.location.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/bin.location.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById("mainHeaderText")) {
            document.getElementById("mainHeaderText").innerHTML = "Bin <em>Location</em>";
        }
    </script>
</html>