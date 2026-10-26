Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById("mainHeaderText").innerHTML = "Form <em>Name</em> ";
  document.getElementById('addFormHeaderSearch').style.display='block';
	document.getElementById('addData').onclick = function(event) {
		task='';
    frmEditor.getForm().reset();
    gridEditorDs.load({params:{form_id: 0}});
		if (formAccess==2 || formAccess==3 || formAccess==4){
			task = 'create';
			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Form Name";
      var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 5;
      gridEditor.setHeight(gridEditorHeight);
			frmEditor.getForm().reset();
		}else{
			document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
			document.getElementById('popWdw').style.display='block';
		}
	};
	document.getElementById('editData').onclick = function(event) {
		task='';
		if (formAccess==3 || formAccess==4){
			frmEditor.getForm().reset();
			task = 'update';
			Ext.getCmp('frmEditor').getForm().load({url:'../dev/php-crud/m.form.nameCRD.php', params:{id: dataRecord.id, task: 'read_edit'}});
			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Form Name";
      var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 5;
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
			document.getElementById("popWdwTxt").innerHTML = 'delete \'' + dataRecord.title + '\'  ?';
		}else{
			document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
			document.getElementById('popWdw').style.display='block';
		}
	};
	document.getElementById('printData').onclick = function(event) {
		window.open("../pdf/m.form.name.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
	};
	document.getElementById('saveEdit').onclick = function(event) {
    var arrForm = new Ext.data.ArrayStore({fields: FormField});
    arrForm.loadData(EmptyArr);
    for (i=0;i<gridEditorDs.data.length;i++){
      var dataFormGrid=gridEditorDs.data.items[i];
      arrForm.add(new addRow({
        link_id:dataFormGrid.data.link_id,
        link_form_id:dataFormGrid.data.link_form_id
      }));
    }
		frmEditor.getForm().submit({
			method:'POST',
			params: {task: task, arrForm: DsArray2Json(arrForm), _tna: Ext.getCmp('tna').getValue(), _active: Ext.getCmp('active').getValue()},
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
        gridEditorDs.load({params:{form_id: 0}});
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
    if (deleteRow>0) {
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
      task='';
		}
		document.getElementById('popWdw').style.display='none';
		document.getElementById('popCancel').style.display='none';
	};
	document.getElementById('logData').onclick = function(event) {
		document.getElementById('logWdw').style.display='block';
		document.getElementById("logFormHeaderText").innerHTML = '&nbsp;[' + dataRecord.group_name +'] '+dataRecord.title;
    GridHistory.setHeight(calculateDivHeight(['logForm','logFormHeader']));
		GridHistoryDs.load({params:{id:dataRecord.id}});
	};

	var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
	var deleteDs = new Ext.data.Store({url: '../dev/php-crud/m.form.nameCRD.php'});

	var GroupDs = new Ext.data.Store({
		url: '../dev/php-crud/m.form.groupCRD.php',
		baseParams:{task: 'read'},
		reader: new Ext.data.JsonReader({
      root: 'data',totalProperty: 'totalRecord',
      fields :[{name: 'id', type:'numeric'},{name: 'grp_name', type:'string'}]
    })
	});
	GroupDs.load();
  var departmentDs = new Ext.data.Store({
    url: '../dev/php-crud/m.department.php',
    baseParams:{task: 'read', formId:'3' },
    reader: new Ext.data.JsonReader({
      root: 'data', totalProperty: 'totalCount',
      fields :[{name: 'id'},{name: 'department_name',type:'string',convert : function(v, rec) {return rec.name;}}]
    })
  });
  departmentDs.load();
	var FormField = [
		{name: 'id'},
    {name: 'link_id'},
    {name: 'link_form_id'},
		{name: 'group_id'},
		{name: 'group_name'},
		{name: 'seq'},
		{name: 'title'},
		{name: 'php_url'},
		{name: 'js_url'},
		{name: 'remark'},
    {name: 'department_id'},
    {name: 'main_table'},
    {name: 'tna'},
    { name: 'active', type: 'boolean' }
	];
	mainDs = new Ext.data.Store({
		url: '../dev/php-crud/m.form.nameCRD.php',
		idProperty: 'id',
		baseParams:{task: 'read'},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalRecord', fields :FormField})
	});
	mainDs.load();

  //Editor Form
  var gridEditorDs = new Ext.data.Store({
    url: '../dev/php-crud/m.form.nameCRD.php',
    baseParams:{task: 'read_detail'},
    reader: new Ext.data.JsonReader({root: 'data', fields : FormField})
  });
  var addRow = Ext.data.Record.create(FormField);
  var editorDeleter = new Extensive.grid.ItemDeleter();
  var gridEditor = new stdGridEditor({
    store: gridEditorDs,
    id:'gridEditor',
    selModel: editorDeleter,
    columns:[
      {header: "Group", dataIndex: 'group_name', width: 150, renderer: CellWrap},
      {header: "Form Name",dataIndex: 'title',width: 250,align: 'left',renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      editorDeleter,
      {dataIndex: 'link_id', hidden: true},
      {dataIndex: 'link_form_id', hidden: true}
    ]
  });
  gridEditor.addClass('x-grid3-gridEditor');
  var frmEditor = new stdForm({
    url: '../dev/php-crud/m.form.nameCRD.php',
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({root : 'data',fields: FormField}),
    renderTo: 'editorFormMain',
    items: [
      {columnWidth: 0.35, items: new myComboField({fieldLabel:'Group', id:'group_id', name:'group_id', store:GroupDs, valueField:'id',displayField:'grp_name', hiddenName:'group_id'})},
      { columnWidth: .2, id: 'isactive', labelAlign: 'right', labelWidth: 200, items: { xtype: 'checkbox', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;', name: 'active', id: 'active', cls: 'fieldCheckClass regular-checkbox', checked: false, fieldLabel: 'form active' } },
      {columnWidth:45, items:RowSpace},
      {columnWidth: 0.15, items: new myTextField({fieldLabel:'Seq', id:'seq', name:'seq', allowBlank:false, maskRe:/^([0-9])/,autoCreate :{tag: "input", maxlength : 4, type: "text", size: "4", autocomplete: "off"}})},
      {columnWidth: 0.4, items: new myTextField({fieldLabel:'Title', id:'title', name:'title', allowBlank:false,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: 0.2, items: new myTextField({fieldLabel:'PHP URL', id:'php_url', name:'php_url', allowBlank:false,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: 0.2, items: new myTextField({fieldLabel:'JS URL', id:'js_url', name:'js_url', allowBlank:false,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: 0.55, items: new myComboField({fieldLabel:'Department', id:'department_id', name:'department_id', store:departmentDs, valueField:'id',displayField:'department_name', hiddenName:'department_id', allowBlank:false})},
      {columnWidth: 0.2, items: new myTextField({fieldLabel:'Main Data', id:'main_table', name:'main_table', allowBlank:false,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      { columnWidth: .2, id: 'TnA', labelAlign: 'right', labelWidth: 200, items: { xtype: 'checkbox', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;', name: 'tna', id: 'tna', cls: 'fieldCheckClass regular-checkbox', checked: false, fieldLabel: 'Use for TnA Monitoring' } },

      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      //{columnWidth:0.13, xtype:'label', text:'Links :', cls:'x-form-item my-label-styleR'},{columnWidth:0.77, items:ButtonSpace},
      //{columnWidth: .5, xtype:'label', text:'Links', cls:'x-form-item my-label-styleBl'},{columnWidth:0.4, items:ButtonSpace},
			{ columnWidth: .2, xtype: 'label', text: 'Link\'s', cls: 'x-form-item my-label-GroupLbl' },
      {columnWidth:0.7, items:FieldSpace},
			{columnWidth: 0.08, xtype: 'button', text: 'Add', cls:'field-button green-field-button', id: 'btnAddItem',
        listeners: {
          click: function() {
            document.getElementById('addWdw').style.display='block';
            document.getElementById("addFormHeaderText").innerHTML = "&nbsp;&nbsp;Add Link";
            arrayForm=[];
            if (Ext.getCmp('id').getValue()!==null && Ext.getCmp('id').getValue().length > 0) {arrayForm.push(Ext.getCmp('id').getValue());}
            for (v=0;v<gridEditor.store.data.length;v++){
              formData=gridEditor.store.data.items[v];
              arrayForm.push(formData.data.link_form_id);
            }
            gridAddEditorDs.load({params: {iteminfo: Array2Json(arrayForm)}});
            var gridItemHeight=(document.getElementById('addForm').clientHeight - document.getElementById('addFormHeader').clientHeight)-2;
            gridAddEditor.setHeight(gridItemHeight);
          }
        }
      },{columnWidth:1, items:gridEditor},{items: new myTextField({name: 'id',id:'id', hidden: true})}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

  document.getElementById('addSaveEdit').onclick = function(event) {
    for (v=0;v<gridAddEditor.store.data.length;v++){
      formData=gridAddEditor.store.data.items[v];
      if (formData.data.active) {
        Ext.getCmp('gridEditor').store.add(new addRow({
          link_id      : formData.data.link_id,
          link_form_id : formData.data.link_form_id,
          group_name   : formData.data.group_name,
          title        : formData.data.title,
          remark       : formData.data.remark
        }));
      }
    }
    document.getElementById('addWdw').style.display='none';
  };

  var gridAddEditorDs = new Ext.data.Store({
    url: '../dev/php-crud/m.form.nameCRD.php',
    baseParams:{task: 'addform'},
    reader: new Ext.data.JsonReader({root: 'data', fields : FormField})
  });
  var addCheckLink = new Ext.grid.CheckColumn({id: 'addCheckLink', header:'<img class="header-icon-grid" style="vertical-align:middle;" src="../img/icon/add_check.png"/>',dataIndex: 'active',width: 30, fixed: true, align: 'center'});
  var gridAddEditor = new stdGrid({
    store  : gridAddEditorDs,
    renderTo: 'addFormGrid',
    columns:[
      addCheckLink,
      {header: "Group", dataIndex: 'group_name', width: 150, renderer: CellWrap},
      {header: "Name", dataIndex: 'title', width: 250, renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'link_form_id', hidden: true}
    ]
  });
  gridAddEditor.addClass('x-grid3-gridAddItem');

  var GridDtlHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  var mainHeight=Math.round(GridDtlHeight-(GridDtlHeight*0.3));
  var detailHeight=(GridDtlHeight-mainHeight);
	var gridMain = new stdGridPaging({
		store  : mainDs,
    renderTo: 'divMainGrid',
		height : mainHeight,
		columns:[
			{header: "#", dataIndex: 'id', width: 20, renderer: CellWrap},
			{header: "Group ", dataIndex: 'group_name', width: 200, renderer: CellWrap},
			{header: "Seq", dataIndex: 'seq', width: 50, renderer: CellWrap},
      {id: 'gridCellRibbon', header: "Title", dataIndex: 'title', width: 100,
        renderer : function(value, meta, store) {
          if (store.data.tna==true) { meta.attr='style= "padding:4px 0px !important; text-align: center;"'; return '<div class="grid-ribbon ribbon-red-strip">'+value+'</div>'; }
          else { return '<div style="white-space:normal !important;">'+value+'</div>'; }
        }
      },
			{header: "PHP URL", dataIndex: 'php_url', width: 100, renderer: CellWrap},
			{header: "JS URL", dataIndex: 'js_url', width: 100, renderer: CellWrap},
			{header: "Remark", dataIndex: 'remark', width: 250, renderer: CellWrap},
       { 
                header: "Status", dataIndex: 'active', width: 90, align: 'center', 
                renderer: function(v) {
                    return (v === true || v === 'true' || v === 1 || v === '1')
                        ? '<span style="color:#2e9e4f;font-weight:bold;">Aktif</span>'
                        : '<span style="color:#c0392b;font-weight:bold;">Nonaktif</span>';
                } 
            },
		],
    listeners: {
      cellclick : function(grid, rowIndex, columnIndex, e){
        var record=grid.getStore().getAt(rowIndex).data;
        gridDetailDs.load({params:{form_id: record.id}});
        gridEditorDs.load({params:{form_id: record.id}});
      },
      celldblclick: function(dv, record, item, index, e) {}
    },
		pageSize:250,
		pageStore:mainDs
	});
  //Detail Grid
  var gridDetailDs = new Ext.data.Store({
    url: '../dev/php-crud/m.form.nameCRD.php',
    baseParams:{task: 'read_detail'},
    reader: new Ext.data.JsonReader({root: 'data', fields : FormField})
  });
  var gridDetail = new stdGrid({
    store  : gridDetailDs,
    renderTo: 'divDetailGrid',
    height : detailHeight,
    columns:[
      {header: "Group",dataIndex: 'group_name',width: 200,align: 'left',renderer: CellWrap},
      {header: "Form Name",dataIndex: 'title',width: 200,align: 'left',renderer: CellWrap},
      {header: "Remark",dataIndex: 'remark',width: 300,align: 'left',renderer: CellWrap}
    ]
  });
  var GridHistoryDs = new historyDs({url: '../dev/php-crud/m.form.nameCRD.php'});
	var GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});
  document.getElementById('editorWdw').style.display='none';
  document.getElementById('addWdw').style.display='none';

  $('#TnA').css({ 'margin-top': '14px', 'margin-bottom': '4px' });
  $('label[for="tna"]').css({ 'color': '#666', 'border': 'none', 'font-size': '.85rem' });
    $('#isactive').css({ 'margin-top': '14px', 'margin-bottom': '4px' });
  $('label[for="isactive"]').css({ 'color': '#666', 'border': 'none', 'font-size': '.85rem' });
});
