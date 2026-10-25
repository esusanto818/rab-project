<<<<<<< Updated upstream
<link rel="shortcut icon" href="/rab-project/dev/assets/logo512.png" type="image/x-icon">
<link rel="icon" href="/rab-project/dev/favicon.ico" type="image/x-icon">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/dev/ext-3.4.1/resources/css/ext-all.css">
<!-- <link rel="stylesheet" type="text/css" href="/rab-project/dev/css/ext-all.css"> -->
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/ButtonColumn.css">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/myFonts.css">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/mainCSS.css">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/popUp.css">
<link rel="stylesheet" type="text/css" href="/rab-project/dev/css/logWdw.css">
=======
<link rel="shortcut icon" href="/rab-project/live/assets/logo512.png" type="image/x-icon">
<link rel="icon" href="/rab-project/live/favicon.ico" type="image/x-icon">
<link rel="stylesheet" type="text/css" href="/rab-project/live/ext-3.4.1/resources/css/ext-all.css">
<!-- <link rel="stylesheet" type="text/css" href="/rab-project/live/css/ext-all.css"> -->
<link rel="stylesheet" type="text/css" href="/rab-project/live/css/ButtonColumn.css">
<link rel="stylesheet" type="text/css" href="/rab-project/live/css/myFonts.css">
<link rel="stylesheet" type="text/css" href="/rab-project/live/css/mainCSS.css">
<link rel="stylesheet" type="text/css" href="/rab-project/live/css/popUp.css">
<link rel="stylesheet" type="text/css" href="/rab-project/live/css/logWdw.css">
>>>>>>> Stashed changes
<div id="container">
  <div class="layerBackGround"></div>
  <div id="content">
    <?php include BASE_PATH . "/live/includes/div-header-icn.php"; ?>
    <div id="divMainGrid"></div>
    <div id="divDetailGrid"></div>
    <?php include BASE_PATH . "/live/includes/div-burger-menu.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-prn-wdw.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-editor-wdw.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-add-wdw.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-upload-wdw.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-popUp.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-log.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-chart.php"; ?>
    <?php include BASE_PATH . "/live/includes/div-loading-wdw.php"; ?>
    <div id="linksOverlay" style="display:none;"></div>
  </div>
  <?php include BASE_PATH . "/live/includes/div-links-menu.php"; ?>
</div>
<<<<<<< Updated upstream
<!-- <script type="text/javascript" src="/rab-project/dev/js-ext/ext-base.js"></script>
<script type="text/javascript" src="/rab-project/dev/js-ext/ext-all.js"></script> -->
<script type="text/javascript" src="/rab-project/dev/ext-3.4.1/adapter/ext/ext-base.js"></script>
<script type="text/javascript" src="/rab-project/dev/ext-3.4.1/ext-all.js"></script>  
<script type="text/javascript" src="/rab-project/dev/js-func/jquery-3.2.1.min.js"></script>
<script type="text/javascript" src="/rab-project/dev/js-func/Chart.min.js"></script>
<script type="text/javascript" src="/rab-project/dev/js-func/mainFunction.js"></script>
<script type="text/javascript" src="/rab-project/dev/js-func/ExtExtend.js"></script>
=======
<!-- <script type="text/javascript" src="/rab-project/live/js-ext/ext-base.js"></script>
<script type="text/javascript" src="/rab-project/live/js-ext/ext-all.js"></script> -->
<script type="text/javascript" src="/rab-project/live/ext-3.4.1/adapter/ext/ext-base.js"></script>
<script type="text/javascript" src="/rab-project/live/ext-3.4.1/ext-all.js"></script>  
<script type="text/javascript" src="/rab-project/live/js-func/jquery-3.2.1.min.js"></script>
<script type="text/javascript" src="/rab-project/live/js-func/Chart.min.js"></script>
<script type="text/javascript" src="/rab-project/live/js-func/mainFunction.js"></script>
<script type="text/javascript" src="/rab-project/live/js-func/ExtExtend.js"></script>
>>>>>>> Stashed changes
  
<script type="text/javascript">
  let mainDs = '', gridAddEditorDs = '';
  function capitalizeEachWord(str) {return str.replace(/\w\S*/g, function(txt) {return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();});}
  function periodName(val, x, store){
    if (val==0) {pName='Daily';} else if (val==1) {pName='Weekly';} else if (val==2) {pName='Monthly';} else {pName='Yearly';}
    return '<div style="white-space:normal !important;">'+pName+'</div>';
  }
  Ext.EventManager.addListener(Ext.getBody(), 'keydown', function(e){
    if(e.getTarget().type != 'text' && e.getTarget().type != 'textarea' && e.getTarget().type != 'password' && e.getKey() == '8' ){e.preventDefault();}
    if (e.getTarget().id == 'q' && (e.getKey() == '13' || e.getKey() == '9')) {
      SearchData=document.getElementById('q').value;
      mainDs.load({params: {query: SearchData}});
      //mainDs.baseParams = {task: 'read', start: 0, limit: 50};
    } else if (e.getTarget().id == 'qAdd' && (e.getKey() == '13' || e.getKey() == '9')) {
      SearchData=document.getElementById('qAdd').value;
      gridAddEditorDs.load({params: {query: SearchData, iteminfo: Array2Json(ArrayItem)}});
    }
  });
</script>
