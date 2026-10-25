<?php
    /*
    Document      : Master Kurs Pajak (Tax Exchange Rate)
    Description   : Master Data Kurs Pajak Kemenkeu Mingguan (Non-IDR Payment Reference)
    File related  : - m.taxexchangerate.js
                    - m.taxexchangerateCRD.php (CRUD)
                    - m.taxexchangerate.pdf.php (Printing)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/grddetail.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/grddetail.css" />
>>>>>>> Stashed changes
        <style>
            #editorForm { top: 30%; width: 65%; }
        </style>
        <title id="page-title">Kurs Pajak (KMK)</title>
    </head>
    <body>
    </body>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/m.taxexchangerate.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/m.taxexchangerate.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        var formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 4)); ?>;
    </script>
</html>
