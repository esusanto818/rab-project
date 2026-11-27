<?php
//include "../php-class/crudClass.php";

require_once __DIR__ . '/../php-class/crudClass.php';

include __DIR__ . '/../php/dashboardData.php';

if (!isset($_SESSION)) {session_start();}
if(!isset($_SESSION["user_id"]) || is_null($_SESSION["user_id"])){header('Location: ../index.php');exit;}
$user_id=$_SESSION["user_id"];
$depart_name=$_SESSION["department"];
$username=$_SESSION["username"];
?>
<!doctype html>
<html lang="en">
  <head>
    <link rel="shortcut icon" href="/kmgi/favicon.ico" type="image/png">
    <link rel="icon" href="/kmgi/favicon.ico" type="image/png">
    <link rel="stylesheet" type="text/css" href="../css/ext-all.css">
    <link rel="stylesheet" type="text/css" href="../js-func/vis-4.21.0/dist/vis.css">
    <link rel="stylesheet" type="text/css" href="../css/xtheme-gray.css">
    <link rel="stylesheet" type="text/css" href="../css/myFonts.css"/>
    <link rel="stylesheet" type="text/css" href="../css/mainCSS.css"/>
    <link rel="stylesheet" type="text/css" href="../css/menu.css" />
    
    <title id="page-title">Menu</title>
  </head>
  <body>
    <script type="text/javascript" src="../js-ext/ext-base.js"></script>
    <script type="text/javascript" src="../js-ext/ext-all.js"></script>
    <script type="text/javascript" src="../js-func/jquery-3.2.1.min.js"></script>
    <script type="text/javascript" src="../js-func/Chart.bundle.min.js"></script>
    <script type="text/javascript" src="../js-func/vis-4.21.0/dist/vis.js"></script>
    <script type="text/javascript" src="../js/chartKMGI.js"></script>
    <script type="text/javascript" src="../js-func/mainFunction.js"></script>
    <script type="text/javascript" src="../js-func/ExtExtend.js"></script>
    <script type="text/javascript" src="../js/menu.grid.table.js"></script>
    <div class="container">
    <div class="layerBackGround"></div>
      <div id="top">
        <ul id="mainMenu" class="nav"><?php include 'menudata.php'; ?></ul>
      </div>
      <div id="content-area">
        <div id="content">
          <div class="mainSubBoxBlock" style="height: 175px; width: 90%; text-align: center; display: flex; overflow: auto">
            <c0>
              <div class="mainSubBox backColorBlack" style="height: 120px">
                <p class="headerInfo">Trimming&nbsp;&nbsp; ~ in pcs</p>
                <a style="font-size: .8rem"><?php echo $periodTrimming; ?></a>
                <table class="infoTable">
                  <tbody>
                    <tr style="height: 27px; font-size: 1rem">
                      <td ><?php echo $trimmingDay; ?></td>
                      <td ><?php echo $trimmingMonth; ?></td>
                      <td ><?php echo $trimmingTotal; ?></td>
                    </tr>
                    <tr style="height: 25px; font-size: .8rem"><td>Daily</td><td>Monthly</td><td>Total</td></tr>
                  </tbody>
                </table>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorBlack" style="height: 120px">
                <p class="headerInfo">RoundUp&nbsp;&nbsp; ~ in pcs</p>
                <a style="font-size: .8rem"><?php echo $periodRoundUp; ?></a>
                <table class="infoTable">
                  <tbody>
                    <tr style="height: 27px; font-size: 1rem">
                      <td><?php echo $roundupDay; ?></td>
                      <td><?php echo $roundupMonth; ?></td>
                      <td><?php echo $roundupTotal; ?></td>
                    </tr>
                    <tr style="height: 26px; font-size: .8rem"><td>Daily</td><td>Monthly</td><td>Total</td></tr>
                  </tbody>
                </table>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorBlack" style="height: 120px">
                <p class="headerInfo">Peeling (&#8709 45 up)&nbsp;&nbsp; ~ in pcs</p>
                <a style="font-size: .8rem"><?php echo $periodPeeling; ?></a>
                <table class="infoTable">
                  <tbody>
                    <tr style="height: 27px; font-size: 1rem">
                      <td><?php echo $peelingDay; ?></td>
                      <td><?php echo $peelingMonth; ?></td>
                      <td><?php echo $peelingTotal; ?></td>
                    </tr>
                    <tr style="height: 26px; font-size: .8rem"><td>Daily</td><td>Monthly</td><td>Total</td></tr>
                  </tbody>
                </table>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorBlack" style="height:120px">
                <p class="headerInfo">Clipper&nbsp;&nbsp; ~ in M3</p>
                <a style="font-size: .8rem"><?php echo $periodClipper; ?></a>
                <table class="infoTable">
                  <tbody>
                    <tr style="height: 27px; font-size: 1rem">
                      <td><?php echo $clipperDay; ?></td>
                      <td><?php echo $clipperMonth; ?></td>
                      <td><?php echo $clipperTotal; ?></td>
                    </tr>
                    <tr style="height: 26px; font-size: .8rem"><td>Daily</td><td>Monthly</td><td>Total</td></tr>
                  </tbody>
                </table>
              </div>
            </c0>
          </div>

          <div id="infoLogSupplier" class="infoSupplierLog"></div>
          <!-- <div class="chart-containerProduct"> -->
          <div class="mainSubBoxBlock" style="height: 200px; width: 90%; text-align: center; display: flex; overflow: auto">
            <c0 style="width: 20%"><div class="mainSubBox backColorGreen" style="height: 160px">
              <canvas id="prodChart01" style="margin-left:auto; margin-right:auto"></canvas>
            </div></c0>
            <c0 style="width: 20%"><div class="mainSubBox backColorOrange" style="height: 160px">
              <canvas id="prodChart02" style="margin-left:auto; margin-right:auto"></canvas>
            </div></c0>
            <c0 style="width: 20%"><div class="mainSubBox backColorPurple" style="height: 160px">
              <canvas id="prodChart03" style="margin-left:auto; margin-right:auto"></canvas>
            </div></c0>
            <c0 style="width: 20%"><div class="mainSubBox backColorRed" style="height: 160px">
              <canvas id="prodChart04" style="margin-left:auto; margin-right:auto"></canvas>
            </div></c0>
            <c0 style="width: 20%"><div class="mainSubBox backColorBlue" style="height: 160px">
              <canvas id="prodChart05" style="margin-left:auto; margin-right:auto"></canvas>
            </div></c0>
          </div>

          <div class="schPlywood">
            <div class="schPlywoodHeader">Production Schedule</div>
            <div id="schPlywoodData" class="schPlywoodData"></div>
          </div>

          <div class="mainSubBoxBlock" style="height: 175px; text-align: center; display: flex; overflow: auto">
            <c0>
              <div class="mainSubBox backColorGreen">
                <div class="mainSubBoxHdrInfo">
                  <div class="mainSubBoxHdrInfoText">
                    <a >Order&nbsp;&nbsp; ~ in Pcs</a>
                    <a style="font-size: 13px; padding-top: 4px"><?php echo $salesPeriod; ?></a>
                  </div >
                  <div class="mainSubBoxHdrInfoIcon"><i class="material-icons md-28 white">add_shopping_cart</i></div>
                </div>
                <div class="mainSubBoxData"><td><?php echo $orderQty; ?></td></div>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorOrange">
                <div class="mainSubBoxHdrInfo">
                  <div class="mainSubBoxHdrInfoText">
                    <a >Delivery&nbsp;&nbsp; ~ in Pcs</a>
                    <a style="font-size: 13px; padding-top: 4px"><?php echo $salesPeriod; ?></a>
                  </div >
                  <div class="mainSubBoxHdrInfoIcon"><i class="material-icons md-28 white">airport_shuttle</i></div>
                </div>
                <div class="mainSubBoxData"><td><?php echo $delivQty; ?></td></div>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorPurple">
                <div class="mainSubBoxHdrInfo">
                  <div class="mainSubBoxHdrInfoText">
                    <a >Outstanding&nbsp;&nbsp; ~ in Pcs</a>
                    <a style="font-size: 13px; padding-top: 4px"><?php echo $salesPeriod; ?></a>
                  </div >
                  <div class="mainSubBoxHdrInfoIcon"><i class="material-icons md-28 white">business</i></div>
                </div>
                <div class="mainSubBoxData"><td><?php echo $ostQty; ?></td></div>
              </div>
            </c0>
            <c0>
              <div class="mainSubBox backColorRed">
                <div class="mainSubBoxHdrInfo">
                  <div class="mainSubBoxHdrInfoText">
                    <a >Cancel&nbsp;&nbsp; ~ in Pcs</a>
                    <a style="font-size: 13px; padding-top: 4px"><?php echo $salesPeriod; ?></a>
                  </div >
                  <div class="mainSubBoxHdrInfoIcon"><i class="material-icons md-28 white">remove_shopping_cart</i></div>
                </div>
                <div class="mainSubBoxData"><td><?php echo $cancelQty; ?></td></div>
              </div>
            </c0>
          </div>

          <div class="chart-containerTop">
            <div class="left"><canvas id="myChart01" style="margin-left:auto; margin-right:auto"></canvas></div>
            <div class="right"><canvas id="myChart02" style="margin-left:auto; margin-right:auto"></canvas></div>
          </div>

          <!-- <div class="schPlywoodHeader" style="padding-top: 10px; padding-bottom: 0px; border-top-left-radius: 10px; border-top-right-radius: 10px">Sales vs Production</div> -->
          <div class="mainSubBoxBlock" style="height: 50px; width: 90%; text-align: center; display: flex; overflow: auto; border-top-left-radius: 10px; border-top-right-radius: 10px; backdrop-filter: blur(5px) contrast(1); -webkit-backdrop-filter: blur(5px) contrast(1)">
            <c0 style="width: 16.66%"><div class="mainSubBox"><button class="button-menu" id="0" type="button" >Sales/Production</button></div></c0>
            <c0 style="width: 16.66%"><div class="mainSubBox"><button class="button-menu" id="1" type="button" >Sales/Delivery</button></div></c0>
            <c0 style="width: 16.66%"><div class="mainSubBox"></div></c0>
            <c0 style="width: 16.66%"><div class="mainSubBox"></div></c0>
            <c0 style="width: 16.66%"><div class="mainSubBox"></div></c0>
            <c0 style="width: 16.66%"><div class="mainSubBox"></div></c0>
          </div>

          <div class="chart-containerBot">
            <div><canvas id="divSalesvsProduction" style="margin-left:auto; margin-right:auto"></canvas></div>
          </div>
        </div>
      </div>
    </div>
    <script type="text/javascript">
      $('.nav > li').css('width',$('#mainMenu').width() / <?php echo $menuWidth; ?>);
      // DOM element where the Timeline will be attached
      let container = document.getElementById('schPlywoodData');
      // Create a DataSet (allows two way data-binding)
      let groups = new vis.DataSet([
        {id: 0, content: 'Production Line 1', value: 1},
        {id: 1, content: 'Production Line 3', value: 3},
        {id: 2, content: 'Production Line 2', value: 2, className: 'blue'}
      ]);
      let items = new vis.DataSet([
        {id: 0, group: 0, content: 'Order X - item 0', start: new Date(2018, 12, 17), end: new Date(2018, 12, 21)},
        {id: 1, group: 0, content: 'Order Y - item 1', start: new Date(2018, 12, 21), end: new Date(2018, 12, 29)},
        {id: 2, group: 1, content: 'Order X - item 2', start: new Date(2018, 12, 16), end: new Date(2018, 12, 24)},
        {id: 3, group: 1, content: 'Order Z - item 3', start: new Date(2018, 12, 24), end: new Date(2019, 1, 10)},
        {id: 4, group: 1, content: 'Order Z - item 4', start: new Date(2019, 1, 10), end: new Date(2019, 1, 26)},
        {id: 5, group: 2, content: 'Order X - item 5', start: new Date(2018, 12, 10), end: new Date(2018, 12, 27)}
      ]);
      // Configuration for the Timeline
      let options = {
        // option groupOrder can be a property name or a sort function
        // the sort function must compare two groups and return a value
        //     > 0 when a > b
        //     < 0 when a < b
        //       0 when a == b
        groupOrder: function (a, b) {return a.value - b.value;},
        editable: false
      };
      // Create a Timeline
      function onSelect (properties) {alert('selected items: ' + properties.nodes);}
      let timeline = new vis.Timeline(container);
      timeline.setOptions(options);
      timeline.setGroups(groups);
      timeline.setItems(items);
      timeline.on('select', onSelect);
    </script>
</body>
</html>
