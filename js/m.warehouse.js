Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById("mainHeaderText").innerHTML = "Warehouse";
  document.getElementById('addData').onclick = function(event) {
    task='';
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Warehouse";
      frmEditor.getForm().reset();
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to access this window';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('editData').onclick = function(event) {
    task='';
    if (formAccess==3 || formAccess==4){
      frmEditor.getForm().reset();
      task = 'update';
      Ext.getCmp('frmEditor').getForm().load({url:'../php-crud/m.warehouseCRD.php',params:{id: dataRecord.id, task: 'read_edit'}});
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Warehouse";
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to edit this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('deleteData').onclick = function(event) {
    if (formAccess==4){
      task='delete';
      document.getElementById('popWdw').style.display='block';
      document.getElementById('popCancel').style.display='block';
      document.getElementById("popWdwTxt").innerHTML ='delete unit \'' + dataRecord.name + '\'  ?';
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.warehouse.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');}
  document.getElementById('saveEdit').onclick = function(event) {
    frmEditor.getForm().submit({
      method:'POST',
      params: {task: task},
      waitTitle:'Connecting',
      waitMsg:'Sending data...',
      success:function(form,action){
        var objResponse=Ext.util.JSON.decode(action.response.responseText);
        var messagesStatus=objResponse.status;
        var messages=objResponse.message;
        /*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
        document.getElementById("popWdwTxt").innerHTML=messages;
        document.getElementById('popWdw').style.display='block';

        mainDs.load({params:{task: 'read'}});
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display='none';
      },
      failure:function(form, action){
        var objResponse=Ext.util.JSON.decode(action.response.responseText);
        var messagesStatus=objResponse.status;
        var messages=objResponse.message;
        /*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
        document.getElementById("popWdwTxt").innerHTML=messages;
        document.getElementById('popWdw').style.display='block';
      }
    });
  };
  document.getElementById('popOK').onclick = function(event) {
    if (task=='delete') {
      deleteDs.load({
        params:{task: "delete", id: dataRecord.id },
        callback:function(records, operation, success){
          document.getElementById('popWdw').style.display='none';
          document.getElementById('popCancel').style.display='none';
          task='';

          var textMessages=Ext.util.JSON.decode(operation.callback.caller.caller.arguments[2].responseText);
          var messagesStatus=textMessages.status;
          var messages=textMessages.message;
          /*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
          document.getElementById("popWdwTxt").innerHTML=messages;
          document.getElementById('popWdw').style.display='block';
        }
      });
      mainDs.load();
      gridMain.getView().refresh(true);
    }
    document.getElementById('popWdw').style.display='none';
    document.getElementById('popCancel').style.display='none';
    task='';
  };
  document.getElementById('logData').onclick = function(event) {
    document.getElementById('logWdw').style.display='block';
    document.getElementById("logFormHeaderText").innerHTML = '&nbsp;' + dataRecord.name;
    GridHistory.setHeight(calculateDivHeight(['logForm','logFormHeader']));
    GridHistoryDs.load({params:{id:dataRecord.id}});
  };
  var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
  var deleteDs = new Ext.data.Store({url: '../php-crud/m.warehouseCRD.php'});
  var LocationDs = new Ext.data.Store({
    url: '../php-crud/m.locationCRD.php',
    baseParams:{task: 'read'},
    reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalRecord',fields :[{name: 'id'},{name: 'name'}]})
  });
  LocationDs.load();
  var warehouseField = [{name: 'id'}, {name: 'code'}, {name: 'total_row'}, {name: 'col_row'},{name: 'name'}, {name: 'description'}, {name: 'remark'}, {name: 'location_id'}, {name: 'location_name'}, {name: 'location_init'}, {name:'warehouse_name'}];
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.warehouseCRD.php',
		idProperty: 'id',
		baseParams:{task: 'read', start: 0, limit: PageSize},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalRecord', fields :warehouseField})
	});
	mainDs.load();

  var frmEditor = new stdForm({
    url      : '../php-crud/m.warehouseCRD.php',
    id       : 'frmEditor',
    reader   : new Ext.data.JsonReader({root : 'data',fields: warehouseField}),
    renderTo : 'editorFormMain',
    items    : [
      {columnWidth:1, items:RowSpace},
      {columnWidth:0.15, items: new myComboField({fieldLabel:'Location', id:'location_id', name:'location_id', store:LocationDs, valueField:'id',displayField:'name', hiddenName:'location_id', anchor: '90%'})},
      {columnWidth:0.15, items: new myTextField({fieldLabel:'Code', id:'code', name:'code', allowBlank: false, autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"}})},
      {columnWidth:0.5, items: new myTextField({fieldLabel:'Name', id:'name', name:'name', allowBlank: false, autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth:0.1, items: new myNumberField({fieldLabel:'Rows', id:'total_row', name:'total_row', decimalPrecision : 0})},
      {columnWidth:0.1, items: new myNumberField({fieldLabel:'Columns/Row', id:'col_row', name:'col_row', decimalPrecision : 0})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Description', id:'description', name:'description',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth:1, items:FieldSpaceTopLabel},
      {items: new myTextField({name: 'id',id:'id', hidden: true})}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

  var mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  var gridMain = new stdGridPaging({
    store  : mainDs,
    height : mainHeight,
    renderTo : 'divMainGrid',
    columns:[
      {header: "Location", dataIndex: 'location_name', width: 150, fixed: true, renderer: CellWrap},
      {header: "Initial", dataIndex: 'code', width: 100, fixed: true, renderer: CellWrap},
      {header: "Name", dataIndex: 'name', width: 200, renderer: CellWrap},
      {header: "Row", dataIndex: 'total_row', width: 70, align: 'right',renderer: format_int},
      {header: "Col/Row", dataIndex: 'col_row', width: 70, align: 'right',renderer: format_int},
      {header: "Description", dataIndex: 'description', width: 300, renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'id', hidden: true}
    ],
    pageSize:250,
    pageStore:mainDs
  });
  var GridHistoryDs = new historyDs({url: '../php-crud/m.departmentCRD.php'});
  var GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});
  

	document.getElementById('editorWdw').style.display='none';
	document.getElementById('logWdw').style.display='none';
	document.getElementById('linksOverlay').style.display='none';
});
