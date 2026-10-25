<?php
    /*
    Document        : Fiscal Period
    Description     : Master periode akuntansi (buka/tutup periode)
    File related    : - m.fiscalperiodCRD.php (CRUD)
    Note            : Tombol icon 'closeReopenData' perlu ditambahkan di
                       toolbar template (many-template-icn.php atau di
                       halaman ini langsung) -- lihat instruksi di chat.
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
    

?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 50%;}
            #editorFormHeaderMenu {
                position: relative;
                float: right;
                width: 30%; 
            }
             #editorFormHeaderText {
                position: relative;
                float: right;
                width: 50%; 
            }
        </style>
        <title id="page-title">Fiscal Period</title>
<<<<<<< Updated upstream
        <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/header.black.css" />
=======
        <link rel="stylesheet" type="text/css" href="/rab-project/live/css/header.black.css" />
>>>>>>> Stashed changes
    </head>
    <body>
    </body>
    <!-- 1. DEKLARASIKAN DAHULU VARIABEL SAKRAL DI WINDOW/GLOBAL -->
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    
    <!-- 2. BARU PANGGIL SKRIP EXTERNAL JS -->
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/m.fiscalperiod.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/m.fiscalperiod.js"></script>
>>>>>>> Stashed changes
    
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Fiscal <em>Period</em>";
    </script>
</html>