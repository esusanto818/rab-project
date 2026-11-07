Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById("mainHeaderText").innerHTML = "Location <em>Name</em>";
  document.getElementById('addData').onclick = function(event) {
    task='';
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Location";
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
      Ext.getCmp('frmEditor').getForm().load({url:'../php-crud/m.locationCRD.php',params:{id: dataRecord.id, task: 'read_edit'}});
      document.getElementById('editorWdw').style.display='block';
      document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Location";
      locHourDs.load({
        params:{id: dataRecord.id},
        callback: function(success , r){
          if(success){
            locHourDs.each(function(r){
              Ext.getCmp('start_date'+r.data.shift).setValue(r.data.from_date);
              Ext.getCmp(r.data.shift+((dayName[r.data.day]).substring(0,3)).toLowerCase()+'_start').setValue((r.data.from_time).substring(0,5));
              Ext.getCmp(r.data.shift+((dayName[r.data.day]).substring(0,3)).toLowerCase()+'_end').setValue((r.data.to_time).substring(0,5));
            });
          }
        }
      });
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
  document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.location.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');};
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

  let myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
  let deleteDs = new Ext.data.Store({url: '../php-crud/m.locationCRD.php'});

	let locationFields = [{name: 'id'}, {name: 'init'}, {name: 'name'}, {name: 'remark'}];
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.locationCRD.php',
		idProperty: 'id',
		baseParams:{task: 'read', start: 0, limit: PageSize},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :locationFields})
	});
	mainDs.load();
  let locHourFields = [{name: 'id'}, {name: 'location_id'}, {name: 'day'}, {name: 'shift'}, {name: 'from_time'}, {name: 'to_time'}, {name: 'from_date', type:'date', dateFormat:'Y-m-d'}];
  let locHourDs = new Ext.data.Store({
    url: '../php-crud/m.locationCRD.php',
    baseParams:{task: 'read_hour'},
    reader: new Ext.data.JsonReader({root: 'data', fields : locHourFields})
  });
  let frmEditor = new stdForm({
    url      : '../php-crud/m.locationCRD.php',
    id       : 'frmEditor',
    reader   : new Ext.data.JsonReader({root : 'data',fields: locationFields}),
    renderTo : 'editorFormMain',
    defaults : {xtype:'container', layout:'form', columnWidth: 0.071, baseCls:'x-plain'},
    items    : [
      {items: new myTextField({name: 'id',id:'id', hidden: true})},
      {columnWidth:1, items:RowSpace},
      {columnWidth:0.25, items: new myTextField({
        fieldLabel:'Initial', id:'init', name:'init', allowBlank: false,
        autoCreate :{tag: "input", maxlength : 8, type: "text", size: "8", autocomplete: "off"},
        listeners :{change:function(field){field.setValue(field.getValue().toUpperCase());}}
      })},
      {columnWidth:0.5, items: new myTextField({fieldLabel:'Name', id:'name', name:'name', allowBlank: false, autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth:1, items:RowSpace},
      {columnWidth:1, xtype:'label', text:'SHIFT I', cls:'x-form-item my-label-styleBl'},
      {columnWidth:0.16, items: new myDateField({ fieldLabel:'Shift I Start Date', id:'start_date1', name:'start_date1', allowBlank : false}), cls: 'teal-label'}, {columnWidth:0.84, items:spaceTextArea},

      {items: new myTimeField({fieldLabel : "Sunday", emptyText: 'Start...', id : '1sun_start',name: '1sun_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1sun_end',name: '1sun_end', allowBlank : false, anchor: '65%'})},

      {items: new myTimeField({fieldLabel : "Monday", emptyText: 'Start...', id : '1mon_start',name: '1mon_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1mon_end',name: '1mon_end', allowBlank : false, anchor: '65%'})},

      {items: new myTimeField({fieldLabel : "Tuesday", emptyText: 'Start...', id : '1tue_start',name: '1tue_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1tue_end',name: '1tue_end', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Wednesday", emptyText: 'Start...', id : '1wed_start',name: '1wed_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1wed_end',name: '1wed_end', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Thursday", emptyText: 'Start...', id : '1thu_start',name: '1thu_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1thu_end',name: '1thu_end', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Friday", emptyText: 'Start...', id : '1fri_start',name: '1fri_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1fri_end',name: '1fri_end', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Saturday", emptyText: 'Start...', id : '1sat_start',name: '1sat_start', allowBlank : false, anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '1sat_end',name: '1sat_end', allowBlank : false, anchor: '65%'})},
      {columnWidth:1, items:RowSpace},
      {columnWidth:1, xtype:'label', text:'SHIFT II', cls:'x-form-item my-label-styleBl'},
      {columnWidth:0.16, items: new myDateField({ fieldLabel:'Shift II Start Date', id:'start_date2', name:'start_date2'}), cls: 'teal-label'}, {columnWidth:0.84, items:spaceTextArea},
      {items: new myTimeField({fieldLabel : "Sunday", emptyText: 'Start...', id : '2sun_start',name: '2sun_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2sun_end',name: '2sun_end', anchor: '65%'})},

      {items: new myTimeField({fieldLabel : "Monday", emptyText: 'Start...', id : '2mon_start',name: '2mon_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2mon_end',name: '2mon_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Tuesday", emptyText: 'Start...', id : '2tue_start',name: '2tue_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2tue_end',name: '2tue_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Wednesday", emptyText: 'Start...', id : '2wed_start',name: '2wed_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2wed_end',name: '2wed_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Thursday", emptyText: 'Start...', id : '2thu_start',name: '2thu_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2thu_end',name: '2thu_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Friday", emptyText: 'Start...', id : '2fri_start',name: '2fri_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2fri_end',name: '2fri_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Saturday", emptyText: 'Start...', id : '2sat_start',name: '2sat_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '2sat_end',name: '2sat_end', anchor: '65%'})},
      {columnWidth:1, items:RowSpace},
      {columnWidth:1, xtype:'label', text:'SHIFT III', cls:'x-form-item my-label-styleBl'},
      {columnWidth:0.16, items: new myDateField({ fieldLabel:'Shift III Start Date', id:'start_date3', name:'start_date3'}), cls: 'teal-label'}, {columnWidth:0.84, items:spaceTextArea},
      {items: new myTimeField({fieldLabel : "Sunday", emptyText: 'Start...', id : '3sun_start',name: '3sun_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3sun_end',name: '3sun_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Monday", emptyText: 'Start...', id : '3mon_start',name: '3mon_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3mon_end',name: '3mon_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Tuesday", emptyText: 'Start...', id : '3tue_start',name: '3tue_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3tue_end',name: '3tue_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Wednesday", emptyText: 'Start...', id : '3wed_start',name: '3wed_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3wed_end',name: '3wed_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Thursday", emptyText: 'Start...', id : '3thu_start',name: '3thu_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3thu_end',name: '3thu_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Friday", emptyText: 'Start...', id : '3fri_start',name: '3fri_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3fri_end',name: '3fri_end', anchor: '65%'})},
      {items: new myTimeField({fieldLabel : "Saturday", emptyText: 'Start...', id : '3sat_start',name: '3sat_start', anchor: '65%'})},
      {items: new myTimeField({emptyText: 'End...', id : '3sat_end',name: '3sat_end', anchor: '65%'})},
      {columnWidth:1, items:FieldSpaceTopLabel}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});
  //Main Grid
  let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  let gridMain = new stdGridPaging({
    renderTo: 'divMainGrid',
    store  : mainDs,
    height : mainHeight,
    columns:[
      {header: "Initial", dataIndex: 'init', width: 100, fixed: true, renderer: CellWrap},
      {header: "Name", dataIndex: 'name', width: 250, renderer: CellWrap},
      {header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap},
      {dataIndex: 'id', hidden: true}
    ],
    pageSize:250,
    pageStore:mainDs
  });
  /* History */
  let GridHistoryDs = new historyDs({url: '../php-crud/m.locationCRD.php'});
  let GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});
  

  document.getElementById('editorWdw').style.display='none';
  document.getElementById('addWdw').style.display='none';
});
