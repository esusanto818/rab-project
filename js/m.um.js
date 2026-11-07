Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById("mainHeaderText").innerHTML = "Unit Measurement";
  document.getElementById('addData').onclick = function(event) {
    task='';
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Unit";
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
      Ext.getCmp('frmEditor').getForm().load({
        url:'../php-crud/m.umCRD.php'
        ,params:{id: dataRecord.id, task: 'read_edit'}
        ,success:function(form, action) {}
      });
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Unit";
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to edit this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('deleteData').onclick = function(event) {
    if (formAccess==4){
      task='delete'
      document.getElementById('popWdw').style.display='block';
      document.getElementById('popCancel').style.display='block';
      document.getElementById("popWdwTxt").innerHTML ='delete unit \'' + dataRecord.name + '\'  ?';
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.um.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');};
  document.getElementById('saveEdit').onclick = function(event) {
    frmEditor.getForm().submit({
      method:'POST',
      params: {task: task},
      waitTitle:'Connecting',
      waitMsg:'Sending data...',
      success:function(form,action){
        let objResponse=Ext.util.JSON.decode(action.response.responseText);
        let messagesStatus=objResponse.status;
        let messages=objResponse.message;
        /*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
        document.getElementById("popWdwTxt").innerHTML=messages;
        document.getElementById('popWdw').style.display='block';

        mainDs.load({params:{task: 'read'}});
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display='none';
      },
      failure:function(form, action){
        let objResponse=Ext.util.JSON.decode(action.response.responseText);
        let messagesStatus=objResponse.status;
        let messages=objResponse.message;
        /*document.getElementById("popWdwMainHeaderText").innerHTML=messagesStatus;*/
        document.getElementById("popWdwTxt").innerHTML=messages;
        document.getElementById('popWdw').style.display='block';
      }
    })
  };
  document.getElementById('popOK').onclick = function(event) {
    if (task=='delete') {
      deleteDs.load({
        params:{task: "delete", id: dataRecord.id }
        ,callback:function(records, operation, success){
          document.getElementById('popWdw').style.display='none';
          document.getElementById('popCancel').style.display='none';
          task='';

          let textMessages=Ext.util.JSON.decode(operation.callback.caller.caller.arguments[2].responseText);
          let messagesStatus=textMessages.status;
          let messages=textMessages.message;
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
    let historyHeight=calculateDivHeight(['logForm','logFormHeader']);
    GridHistory.setHeight(historyHeight);
    GridHistoryDs.load({params:{id:dataRecord.id}});
  };
  let myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
  let deleteDs = new Ext.data.Store({url: '../php-crud/m.umCRD.php'});
	let umField = [{name: 'id'},{name: 'init'},{name: 'name'},{name: 'description'},{name: 'remark'}];
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.umCRD.php'
		,idProperty: 'id'
		,baseParams:{task: 'read', start: 0, limit: PageSize}
		,reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalRecord',fields :umField})
	});
	mainDs.load();

  let frmEditor = new stdForm({
    url      : '../php-crud/m.umCRD.php',
    id       : 'frmEditor',
    reader   : new Ext.data.JsonReader({root : 'data',fields: umField}),
    renderTo : 'editorFormMain',
    defaults : {xtype:'container', layout:'form', columnWidth: 0.3, baseCls:'x-plain'},
    items    : [
      {items: new myTextField({name: 'id',id:'id', hidden: true})},
      {columnWidth:1, items:RowSpace},
      {items: new myTextField({
        fieldLabel:'Initial', id:'init', name:'init', allowBlank: false,
        autoCreate :{tag: "input", maxlength : 8, type: "text", size: "8", autocomplete: "off"},
        listeners :{change:function(field){}}
      })},
      {columnWidth:0.7, items: new myTextField({fieldLabel:'Name', id:'name', name:'name', allowBlank: false, autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Description', id:'description', name:'description',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth:1, items:RowSpace}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

  let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  let gridMain = new stdGridPaging({
    renderTo: 'divMainGrid',
    store  : mainDs,
    height : mainHeight,
    columns:[
      {header: "Initial", dataIndex: 'init', width: 100, fixed: true, renderer: CellWrap},
      {header: "Name", dataIndex: 'name', width: 250, renderer: CellWrap},
      {header: "Description", dataIndex: 'description', width: 250, renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'id', hidden: true}
    ],
    pageSize:250,
    pageStore:mainDs
  });
  let GridHistoryDs = new historyDs({url: '../php-crud/m.umCRD.php'});
  let GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});


  document.getElementById('editorWdw').style.display='none';
  document.getElementById('addWdw').style.display='none';

});
