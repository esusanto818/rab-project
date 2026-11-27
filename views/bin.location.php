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
        
        <title id="page-title">Bin Location</title>
<script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript" src="/rab-project/dev/js/bin.location.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById("mainHeaderText")) {
            document.getElementById("mainHeaderText").innerHTML = "Bin <em>Location</em>";
        }
    </script>
</html>