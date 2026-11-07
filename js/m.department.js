Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById("mainHeaderText").innerHTML = "Department <em>Name</em>";
  document.getElementById('addData').onclick = function(event) {
    task=''; addSectionCount=0;
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Department";
      let gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 10;
      gridEditor.setHeight(gridEditorHeight);
      gridEditorDs.load({params:{department_id: 0}});
      frmEditor.getForm().reset();
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to access this window';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('editData').onclick = function(event) {
    task='update';
    if (formAccess==3 || formAccess==4){
      frmEditor.getForm().reset();
      task = 'update';
      Ext.getCmp('frmEditor').getForm().load({
        url:'../php-crud/m.departmentCRD.php',
        params:{id: dataRecord.id, task: 'read_edit'},
        success:function(form, action) {}
      });
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Department";
      let gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 10;
      gridEditor.setHeight(gridEditorHeight);
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to access this window';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('deleteData').onclick = function(event) {
    if (formAccess==4){
      task='delete';
      document.getElementById('popWdw').style.display='block';
      document.getElementById('popCancel').style.display='block';
      document.getElementById("popWdwTxt").innerHTML ='delete \'' + dataRecord.name + '\'  ?';
    }else{
      document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.department.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');};
  document.getElementById('saveEdit').onclick = function(event) {
    let arrSection = dataStore2Array(gridEditorDs).filter((dataSection) => {return parseInt($N(dataSection.seq_no,0))>0;}).map((dataSection) => {
      return {
        id            : parseInt(dataSection.id),
        department_id : parseInt(dataSection.department_id),
        seq_no        : parseInt($N(dataSection.seq_no,0)),
        section_name  : $N(dataSection.section_name,''),
        line_no       : parseInt($N(dataSection.line_no,0)),
        data_output   : dataSection.data_output,
        piece_rate    : dataSection.piece_rate,
        warehouse_id  : dataSection.warehouse_id,
        share_amt     : dataSection.share_amt,
        operator      : dataSection.operator,
        remark        : $N(dataSection.remark,'')
      };
    });

    frmEditor.getForm().submit({
      method:'POST',
      params: {task: task, arrSection:Array2Json(arrSection)},
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
    });
  };
  document.getElementById('popOK').onclick = function(event) {
    if (deleteRow>0) {
      addSectionCount--;
      gridEditor.getStore().remove(deleteRecord);
      gridEditor.getView().refresh();
      deleteRow=0;
    }
    if (task=='delete') {
      deleteDs.load({
        params:{task: "delete", id: dataRecord.id },
        callback:function(records, operation, success){
          document.getElementById('popWdw').style.display='none';
          document.getElementById('popCancel').style.display='none';

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
    GridHistory.setHeight(calculateDivHeight(['logForm','logFormHeader']));
    GridHistoryDs.load({params:{id:dataRecord.id}});
  };
  let addSectionCount=0;
  let myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
  let deleteDs = new Ext.data.Store({url: '../php-crud/m.departmentCRD.php'});
  let LocationDs = new Ext.data.Store({
    url: '../php-crud/m.locationCRD.php',
    baseParams:{task: 'read'},
    reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalRecord',fields :[{name: 'id'},{name: 'name'}]})
  });
  LocationDs.load();
	let departmentFields = [{name: 'id'}, {name: 'seq_no'}, {name: 'init'}, {name: 'name'}, {name: 'remark'}, {name: 'location_id'}, {name: 'location_name'}];
  let sectionFields = [{name: 'id'}, {name: 'department_id'}, {name: 'seq_no'}, {name: 'section_name'}, {name: 'line_no'}, {name: 'data_output'}, {name: 'warehouse_id'}, {name: 'warehouse'}, {name: 'piece_rate'}, {name: 'operator'}, {name: 'share_amt'},{name: 'remark'}];
  let addSection = Ext.data.Record.create(sectionFields);
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.departmentCRD.php',
		idProperty: 'id',
		baseParams:{task: 'read', start: 0, limit: PageSize},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :departmentFields})
	});
	mainDs.load();
  /*grid editor for main form */
  let gridEditorDs = new Ext.data.Store({url: '../php-crud/m.departmentCRD.php', idProperty: 'size', baseParams:{task: 'read_detail'}, reader: new Ext.data.JsonReader({root: 'data', fields : sectionFields})});
  let addRow = Ext.data.Record.create(sectionFields);
  let editorDeleter = new Extensive.grid.ItemDeleter();
  let edDataCheck = new Ext.grid.CheckColumn({id: 'edDataCheck', header: 'Data', dataIndex: 'data_output', width: 80, fixed: true, align: 'center', css: cssGridEditColumn});
  let edRateCheck = new Ext.grid.CheckColumn({id: 'edRateCheck', header: 'Calc', dataIndex: 'piece_rate', width: 80, fixed: true, align: 'center', css: cssGridEditColumn});
  let edShareCheck = new Ext.grid.CheckColumn({id: 'edShareCheck', header: 'Share', dataIndex: 'share_amt', width: 80, fixed: true, align: 'center', css: cssGridEditColumn});

  let cmbLocWh = new cmbLocWhGridEditor({id: 'warehouse_id'});  cmbLocWh.getStore().load({params:{location_id: myLocation}});

  let gridEditor = new stdGridEditor({
    store    : gridEditorDs,
    id       : 'gridEditor',
    selModel : editorDeleter,
    columns  :[
      {header: "Seq", dataIndex: 'seq_no', width: 80, fixed: true, align: 'right', renderer: format_seq, editor: new Ext.form.NumberField(), css: cssGridEditColumn},
      {header: "Name", dataIndex: 'section_name', width: 200, renderer: CellWrap, editor: new Ext.form.TextField()},
      {header: "Line", dataIndex: 'line_no', width: 70, fixed: true, align: 'right', renderer: format_seq, editor: new Ext.form.NumberField(), css: cssGridEditColumn},
      {header: "Opr", dataIndex: 'operator', width: 70, fixed: true, align: 'right', renderer: format_seq, editor: new Ext.form.NumberField(), css: cssGridEditColumn},
      edDataCheck,
      {header: "Warehouse", dataIndex: 'warehouse_id', width: 150, fixed: true, renderer: Ext.ux.renderer.Combo(Ext.getCmp('warehouse_id')), editor: cmbLocWh, css: cssGridEditColumn},
      edRateCheck, edShareCheck,
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap, editor: new Ext.form.TextField()},
      editorDeleter,
      {dataIndex: 'id', hidden: true},
      {dataIndex: 'department_id', hidden: true}
    ],
    listeners: {
      beforeedit: function (e) {
        cmbLocWh.getStore().load({params:{location_id: myLocation}});
      },
      cellclick : function(grid, rowIndex, columnIndex, e){
        let record=grid.getStore().getAt(rowIndex).data;
      }
    }
  });
  gridEditor.addClass('x-grid3-gridEditor');
  //Editor Form
  let frmEditor = new stdForm({
    url      : '../php-crud/m.departmentCRD.php',
    id       : 'frmEditor',
    reader   : new Ext.data.JsonReader({root : 'data',fields: departmentFields}),
    renderTo : 'editorFormMain',
    items    : [
      {items: new myComboField({fieldLabel:'Location', id:'location_id', name:'location_id', store:LocationDs, valueField:'id',displayField:'name', hiddenName:'location_id', })},
      {columnWidth: 0.1, items: new myNumberField({fieldLabel:'Seq', id:'seq_no', name:'seq_no', allowBlank:false,decimalPrecision : 0})},
      {columnWidth:0.2, items: new myTextField({fieldLabel:'Initial', id:'init', name:'init', allowBlank: false, autoCreate :{tag: "input", maxlength : 8, type: "text", size: "8", autocomplete: "off"}, listeners :{change:function(field){field.setValue(field.getValue().toUpperCase());}}})},
      {columnWidth:0.45, items: new myTextField({fieldLabel:'Name', id:'name', name:'name', allowBlank: false, autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      //{columnWidth:0.13, xtype:'label', text:'Section\'s', cls:'x-form-item my-label-styleR'},{columnWidth:0.77, items:ButtonSpace},
      {columnWidth: .5, id:'listSection', xtype:'label', text:'Section\'s', cls:'x-form-item my-label-styleBl'},
      {columnWidth:0.4, items:ButtonSpace},
      {columnWidth: 0.08, xtype: 'button', text: 'Add', cls:'field-button grey-field-button', id: 'btnAddItem',
        listeners: {
          click: function() {
            let coll = new addSection({id: null, department_id: Ext.getCmp('id').getValue(), seq_no: null, section_name: '', line_no:'', remark: ''});
            Ext.getCmp('gridEditor').stopEditing(addSectionCount,0);
            gridEditorDs.insert(addSectionCount, coll);
            Ext.getCmp('gridEditor').startEditing(addSectionCount,0);
            addSectionCount++;
          }
        }
      },
      {columnWidth:1, items:gridEditor},
      {items: new myTextField({name: 'id',id:'id', hidden: true})}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});
  //Main Grid
  let GridDtlHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  let mainHeight=Math.round(GridDtlHeight-(GridDtlHeight*0.5));
  let detailHeight=(GridDtlHeight-mainHeight);
  let gridMain = new stdGridPaging({
    renderTo: 'divMainGrid',
    store  : mainDs,
    height : mainHeight,
    columns:[
      {header: "Location", dataIndex: 'location_name', width: 150, fixed: true, renderer: CellWrap},
      {header: "Seq", dataIndex: 'seq_no', width: 100, fixed: true, align: 'right',renderer: format_int},
      {header: "Initial", dataIndex: 'init', width: 100, fixed: true, renderer: CellWrap},
      {header: "Name", dataIndex: 'name', width: 300, renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'id', hidden: true}
    ],
    listeners: {
      cellclick : function(grid, rowIndex, columnIndex, e){
        gridEditorDs.load({params:{department_id: 0}});
        let record=grid.getStore().getAt(rowIndex).data;
        gridDetailDs.load({params:{department_id: record.id}});
        gridEditorDs.load({params:{department_id: record.id}});
      }
    },
    pageSize:250,
    pageStore:mainDs
  });
  //Detail Grid
  let mainDataCheck = new Ext.grid.CheckColumn({header: 'Data',dataIndex: 'data_output', width: 80, fixed: true, align: 'center', processEvent: function () { return false;}});
  let mainRateCheck = new Ext.grid.CheckColumn({header: 'Calc',dataIndex: 'piece_rate', width: 80, fixed: true, align: 'center', processEvent: function () { return false;}});
  let mainShareCheck = new Ext.grid.CheckColumn({header: 'Share',dataIndex: 'share_amt', width: 80, fixed: true, align: 'center', processEvent: function () { return false;}});
  let gridDetailDs = new Ext.data.Store({url: '../php-crud/m.departmentCRD.php', baseParams:{task: 'read_detail'}, reader: new Ext.data.JsonReader({root: 'data', fields : sectionFields})});
  gridDetailDs.on('load', function(){addSectionCount = gridDetailDs.getTotalCount();});
  let gridDetail = new stdGrid({
    store  : gridDetailDs,
    height : detailHeight,
    renderTo: 'divDetailGrid',
    columns:[
      {header: "Seq", dataIndex: 'seq_no', width: 80, fixed: true, align: 'right',renderer: format_int},
      {header: "Name", dataIndex: 'section_name', width: 200, renderer: CellWrap},
      {header: "Line", dataIndex: 'line_no', width: 80, fixed: true, align: 'right',renderer: format_int},
      {header: "Opr", dataIndex: 'operator', width: 80, fixed: true, align: 'right',renderer: format_int},
      mainDataCheck,
      {header: "Warehouse", dataIndex: 'warehouse', width: 200, renderer: CellWrap},
      mainRateCheck, mainShareCheck,
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'id', hidden: true}
    ]
  });

  /* History */
  let GridHistoryDs = new historyDs({url: '../php-crud/m.departmentCRD.php'});
  let GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});


  document.getElementById('editorWdw').style.display='none';
  document.getElementById('addWdw').style.display='none';
  $("#listSection").css({'margin-top':'17px'});
});
