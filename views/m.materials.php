<?php
    /*
    Document        : Master Material
    Description     : Entry data master material (kode, nama, satuan,
                      estimasi harga) yang jadi sumber combo Material
                      di modul Master Proyek (RAB) & transaksi WMS.
    File related    : - m.materials.js
                      - m.materialsCRD.php (task: list, read_edit,
                        create, update, delete)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 90%; }
        </style>
        <title id="page-title">Material</title>
        <link rel="stylesheet" type="text/css" href="/rab-project/ext/resources/css/ext-all.css" />
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
>>>>>>> Stashed changes
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/m.materials.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/m.materials.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Master <em>Material</em>";
    </script>
</html>