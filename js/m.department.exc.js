<script type="text/javascript">
	Ext.onReady(function() {
		Ext.QuickTips.init();
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    document.getElementById("mainHeaderText").innerHTML = "Executive <em>List</em> ";
    document.getElementById('addData').onclick = function(event) {
      task='';
      if (formAccess==2 || formAccess==3 || formAccess==4){
        task = 'create';
        document.getElementById('editorWdw').style.display='block';
        document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Executive";
        var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 5;
        gridEditor.setHeight(gridEditorHeight);

        gridDetailDs.load({params:{employee_id: 0}});
        gridEditorDs.load({params:{employee_id: 0}});
        Ext.getCmp('frmEditor').getForm().reset();
        Ext.getCmp('name').focus('', 10);
      }else{
        document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
        document.getElementById('popWdw').style.display='block';
      }
    };
    document.getElementById('editData').onclick = function(event) {
      task='';
      if (formAccess==3 || formAccess==4){
        task = 'update';
        Ext.getCmp('frmEditor').getForm().load({url:'../php-crud/m.department.exc.php', params:{id: dataRecord.id, task: 'read_edit'}});
        document.getElementById('editorWdw').style.display='block';
        document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Executive";
        var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom- 5;
        gridEditor.setHeight(gridEditorHeight);
      }else{
        document.getElementById("popWdwTxt").innerHTML ='not enough permission to access this window';
        document.getElementById('popWdw').style.display='block';
      }
      task='update';
    };
    document.getElementById('deleteData').onclick = function(event) {
      if (formAccess==4){
        task='delete';
        var employeeName=dataRecord.employee_name;
        document.getElementById('popWdw').style.display='block';
        document.getElementById('popCancel').style.display='block';
        document.getElementById("popWdwTxt").innerHTML = 'delete \'' + employeeName + '\'  ?';
      }else{
        document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
        document.getElementById('popWdw').style.display='block';
      }
    };
    document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.form.name.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');};
    document.getElementById('saveEdit').onclick = function(event) {
      var arrExc = new Ext.data.ArrayStore({fields: excField});
      arrExc.loadData(EmptyArr);
      for (i=0;i<gridEditorDs.data.length;i++){
        var dataExcGrid=gridEditorDs.data.items[i];
        arrExc.add(new addRow({
          id:dataExcGrid.data.id,
          employee_id: Ext.getCmp('id').getValue(),
          department_id: dataExcGrid.data.department_id,
          from_date: dataExcGrid.data.from_date,
          to_date: dataExcGrid.data.to_date,
          remark: dataExcGrid.data.remark
        }));
      }
      var excDept = [];
      excDept = DsArray2Json(arrExc);
      Ext.getCmp('frmEditor').getForm().submit({
        method:'POST',
        params: {task: task,excDept:excDept},
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
      var employeeName=dataRecord.employee_name + ' ' + dataRecord.last_name;
      document.getElementById("logFormHeaderText").innerHTML = employeeName + '  [' + dataRecord.department_name + ']';
      GridHistory.setHeight(calculateDivHeight(['logForm','logFormHeader']));
      GridHistoryDs.load({params:{id:dataRecord.id}});
    };

		var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
    var deleteDs = new Ext.data.Store({url: '../php-crud/m.department.exc.php'});
    var btnRow='';

    var departmentDs = new Ext.data.Store({
      url: '../php-crud/m.departmentCRD.php',
      baseParams:{task: 'read'},
      reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]})
    });
    departmentDs.load();
    var employeeField =[
      {name: 'id', type:'numeric'},
      {name: 'name',type:'string',convert : function(v, rec) {return rec.employee_name;}},
      {name:'employee_name'},
      {name:'last_name'},
      {name:'gender',convert : function(v, rec) {return rec.gender=='M' ? 'MALE' : 'FEMALE';}},
      {name:'from_date', type:'date', dateFormat:'Y-m-d'},
      {name:'department_name'},
      {name:'status_name'}
    ];
    var departExcDs = new Ext.data.Store({url: '../php-crud/m.employee.php',baseParams:{task: 'read', formId:'5' },reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :employeeField})
    });
    departExcDs.load();

    var excField = [
      {name:'id'},
      {name:'employee_id'},
      {name:'department_id'},
      {name:'department_name'},
      {name:'from_date', type:'date', dateFormat:'Y-m-d'},
      {name:'to_date', type:'date', dateFormat:'Y-m-d'},
      {name:'remark'}
    ];
    var mainDs = new Ext.data.Store({url: '../php-crud/m.department.exc.php',idProperty: 'id',baseParams:{task: 'read'},reader: new Ext.data.JsonReader({root: 'data',fields :employeeField})});
    mainDs.load();

    var addRow = Ext.data.Record.create(excField);
    var gridEditorDs = new Ext.data.Store({url: '../php-crud/m.department.exc.php',idProperty: 'size',baseParams:{task: 'read_detail'},reader: new Ext.data.JsonReader({root: 'data',fields : excField})});
    var editorDeleter = new Extensive.grid.ItemDeleter();
    var gridEditor = new stdGridEditor({
      store    : gridEditorDs,
      id       : 'gridEditor',
      selModel : editorDeleter,
      height: 200,
      columns  :[
        {header: "Department",dataIndex: 'department_name',width: 200},
        {header: "Start Date", dataIndex: 'from_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y')},
        {header: "End Date", dataIndex: 'to_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y')},
        {header: "Remark", dataIndex: 'remark', width: 250},
        {dataIndex: 'department_id', hidden: true},
        {dataIndex: 'id', hidden: true},
        editorDeleter
      ],
      listeners: {
        cellclick : function(grid, rowIndex, columnIndex, e){
          var record=grid.getStore().getAt(rowIndex).data;
          Ext.getCmp('add_id').setValue(record.id);
          Ext.getCmp('add_name').setValue(record.department_id);
          Ext.getCmp('add_from_date').setValue(record.from_date);
          Ext.getCmp('add_to_date').setValue(record.to_date);
          Ext.getCmp('add_remark').setValue(record.remark);
        }
      }
    });
    gridEditor.addClass('x-grid3-gridEditor');
    var frmEditor = new stdForm({
      url: '../php-crud/m.department.exc.php',
      id: 'frmEditor',
      reader: new Ext.data.JsonReader({root : 'data',fields: employeeField}),
      renderTo: 'editorFormMain',
      labelAlign: 'top',
      autoScroll: 'true',
      items: [
        {items: new myTextField({name: 'id',id:'id', hidden: true})},
        {items: new myTextField({name: 'employee_department_id',id:'employee_department_id', hidden: true})},
        {items: new myComboField({
          fieldLabel:'Name', id:'name', name:'name', store:departExcDs, valueField:'id',displayField:'name', hiddenName:'name',
          listeners: {
            select: function(combo, record, index){
                Ext.getCmp('gender').setValue(record.get('gender'));
                Ext.getCmp('department_name').setValue(record.get('department_name'));
                Ext.getCmp('status_name').setValue(record.get('status_name'));
                Ext.getCmp('from_date').setValue(record.get('from_date'));
            }
          }
        })},
        {columnWidth: .1,items: new myTextField({fieldLabel:'Gender', id:'gender', name:'gender', readOnly: true})},
        {items: new myTextField({fieldLabel:'Department', id:'department_name', name:'department_name', readOnly: true})},
        {items: new myTextField({fieldLabel:'Status', id:'status_name', name:'status_name', readOnly: true})},
        {columnWidth: .15,items: new myDateField({fieldLabel:'Hire Date', id:'from_date', name:'from_date', readOnly: true})},
        {columnWidth: 1, items: ButtonSpace},
        {columnWidth: .1, xtype: 'button',text: 'Add',cls:'field-button blue-field-button', id: 'btnAddItem',
          listeners: {click: function() {
            btnRow='add';
            frmAdd.getForm().reset();
            document.getElementById('addWdw').style.display='block';
            document.getElementById('addFormMain').style.display='block';
            document.getElementById('addFormGrid').style.display='none';
            document.getElementById("addFormHeaderText").innerHTML = "&nbsp;&nbsp;Add Department";
            Ext.getCmp('frmAdd').doLayout();
          }}
        },
        {columnWidth: .1, xtype: 'button',text: 'Edit',cls:'field-button blue-field-button',
          listeners: {click: function() {
            btnRow='edit';
            document.getElementById('addWdw').style.display='block';
            document.getElementById('addFormMain').style.display='block';
            document.getElementById('addFormGrid').style.display='none';
            document.getElementById("addFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Department";
            Ext.getCmp('frmAdd').doLayout();
          }}
        },{columnWidth:1, items:gridEditor}
      ]
    });
    frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});
        
    document.getElementById('addSaveEdit').onclick = function(event) {
      if (btnRow=='add') {
        Ext.getCmp('gridEditor').store.add(new addRow({
          id: Ext.getCmp('add_id').getValue(),
          department_id: Ext.getCmp('add_name').getValue(),
          department_name: Ext.getCmp('add_name').getRawValue(),
          from_date: Ext.getCmp('add_from_date').getValue(),
          to_date: Ext.getCmp('add_to_date').getValue(),
          remark: Ext.getCmp('add_remark').getValue()
        }));
      } else {
        var editRecord = Ext.getCmp('gridEditor').getSelectionModel().getSelected();
        editRecord.set('id',Ext.getCmp('add_id').getValue());
        editRecord.set('department_id',Ext.getCmp('add_name').getValue());
        editRecord.set('department_name',Ext.getCmp('add_name').getRawValue());
        editRecord.set('from_date',Ext.getCmp('add_from_date').getValue());
        editRecord.set('to_date',Ext.getCmp('add_to_date').getValue());
        editRecord.set('remark',Ext.getCmp('add_remark').getValue());
      }
      document.getElementById('addWdw').style.display='none';
      btnRow='';
      frmAdd.getForm().reset();
    };

    var frmAdd = new stdForm({
      url: '../php-crud/m.department.exc.php',
      id: 'frmAdd',
      reader: new Ext.data.JsonReader({root : 'data',fields: excField}),
      renderTo: 'addFormMain',
      labelAlign: 'top',
      items: [
        {items: new myTextField({name: 'add_id',id:'add_id', hidden: true})},
        {columnWidth: 0.4, items: new myComboField({fieldLabel:'Department', id:'add_name', name:'add_name', store:departmentDs, valueField:'id',displayField:'name', hiddenName:'name'})},
        {columnWidth: 0.3, items: new myDateField({fieldLabel:'Start Date', id:'add_from_date', name:'add_from_date'})},
        {columnWidth: 0.3, items: new myDateField({fieldLabel:'End Date', id:'add_to_date', name:'add_to_date'})},
        {columnWidth: 1, items: new myTextArea({fieldLabel:'Remark', height: '55', id:'add_remark', name:'add_remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
        {columnWidth: 1, items: ButtonSpace}
      ]
    });
    frmAdd.on('clientvalidation', function(f, valid) {if (!valid) {addSaveEditDisable();} else {addSaveEditEnable();}});

    var GridDtlHeight=(maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight=Math.round(GridDtlHeight-(GridDtlHeight*0.5));
    var detailHeight=(GridDtlHeight-mainHeight+6);
    var gridMain = new stdGridPaging({
      store  : mainDs
      ,height : mainHeight
      ,renderTo: 'divMainGrid'
      ,columns:[
        {header: "Executive Name ", dataIndex: 'name', width: 250, renderer: CellWrap},
        {header: "Department", dataIndex: 'department_name', width: 150, renderer: CellWrap},
        {header: "Status", dataIndex: 'status_name', width: 100, renderer: CellWrap},
        {header: "Hire Date", dataIndex: 'from_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y')},
        {header: "Remark", dataIndex: 'remark', width: 250, renderer: CellWrap},
        {dataIndex: 'department_id', hidden: true},
        {dataIndex: 'id', hidden: true}
      ]
      ,listeners: {
        cellclick : function(grid, rowIndex, columnIndex, e){
          var record=grid.getStore().getAt(rowIndex).data;
          gridDetailDs.load({params:{employee_id: record.id}});
          gridEditorDs.load({params:{employee_id: record.id}});
        }
      }
      ,pageSize:250
      ,pageStore:mainDs
    });
    //Detail Grid
    var gridDetailDs = new Ext.data.Store({url: '../php-crud/m.department.exc.php', baseParams:{task: 'read_detail'}, reader: new Ext.data.JsonReader({root: 'data', fields : excField})});
    var gridDetail = new stdGrid({
      store  : gridDetailDs
      ,height : detailHeight
      ,renderTo: 'divDetailGrid'
      ,columns:[
        {header: "Department",dataIndex: 'department_name',width: 300,align: 'left',renderer: CellWrap},
        {header: "Start Date", dataIndex: 'from_date', width: 150, renderer: Ext.util.Format.dateRenderer('d M Y')},
        {header: "End Date", dataIndex: 'to_date', width: 150,renderer: Ext.util.Format.dateRenderer('d M Y')},
        {header: "Remark", dataIndex: 'remark', width: 250, renderer: CellWrap},
        {dataIndex: 'department_id', hidden: true},
        {dataIndex: 'id', hidden: true}
      ]
    });
    
    /* History */
    var GridHistoryDs = new historyDs({url: '../php-crud/m.employee.php'});
    var GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});
    
    /* end of History */

    document.getElementById('editorWdw').style.display='none';
    document.getElementById('logWdw').style.display='none';
	});
</script>

