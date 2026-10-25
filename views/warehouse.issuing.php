<?php
    /*
    Document        : Warehouse Issuing
    Description     : Material out ke proyek (FIFO, batas RAB +5%).
    File related    : - warehouse.issuing.js
                      - warehouse.issuingCRD.php
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Warehouse Issuing</title>
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
            #editorForm { top: 50%; width: 90%; height: 85%; }
            #addForm { top: 50%; width: 85%; height: 70%; }
            #whMatBar .x-btn, 
            #whMatBar .x-btn button,
            #whMatBar button {
                background: #0e842040 !important;          /* Warna Emerald Green */
                background-image: none !important;        /* Hilangkan gradient bawaan ExtJS */
                border: 1px solid #0e8420 !important;     /* Border Emerald lebih gelap */
                color: #ffffff !important;                /* Warna teks Hitam */
                font-weight: 800 !important;              /* Teks Bold */
                border-radius: 6px !important;            /* Sudut melengkung */
                padding: 3px 10px !important;
                cursor: pointer !important;
                transition: background-color 0.2s ease !important;
            }

            /* Efek saat mouse di-hover di atas tombol */
            #whMatBar .x-btn:hover, 
            #whMatBar .x-btn button:hover,
            #whMatBar button:hover {
                background: #34d399 !important;          /* Emerald lebih terang saat hover */
                color: #000000 !important;
            }
            /* Menghilangkan garis border/outline di dalam teks tombol */
            #whMatBar .x-btn button,
            #whMatBar button,
            #whMatBar .x-btn * {
                border: none !important;
                outline: none !important;
                box-shadow: none !important;
                background: transparent !important;
                background-color: transparent !important;
                background-image: none !important;
            }
        </style>
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/warehouse.issuing.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/warehouse.issuing.js"></script>
>>>>>>> Stashed changes
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Warehouse <em>Issuing</em>";
        }
    </script>
</html>