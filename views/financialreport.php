<?php
    /*
    Document        : Financial Report (Neraca & Laba Rugi)
    Description     : Laporan keuangan turunan dari Trial Balance (read-only)
    File related    : - m.financialreportCRD.php (report handler)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <title id="page-title">Financial Report</title>
        <link rel="stylesheet" type="text/css" href="../ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/rab-project/dev/js/financialreport.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Financial <em>Report</em>";
        document.getElementById('editorWdw').style.display = 'none';

    </script>
</html>
