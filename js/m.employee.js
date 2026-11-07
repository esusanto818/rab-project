Ext.onReady(function() {
	Ext.QuickTips.init();
  document.getElementById('dataPicture').style.display='block';
  document.getElementById("mainHeaderText").innerHTML = "Employee <em>List</em> ";
	document.getElementById('dataPicPrev').src='../img/no_image.png';

	document.getElementById('addData').onclick = function(event) {
		document.getElementById('dataPicPrev').src='../img/no_image.png';
		task='';
		if (formAccess==2 || formAccess==3 || formAccess==4){
			task = 'create';
			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Employee";

      var frmEditorHeight=(document.getElementById('editorForm').clientHeight - document.getElementById('editorFormHeader').clientHeight);
      Ext.getCmp('frmEditor').setHeight(frmEditorHeight);
      Ext.getCmp('frmEditor').doLayout(); // recalc column widths now that the panel is actually visible

			frmEditor.getForm().reset();

      Ext.getCmp('department_id').allowBlank = false;
      Ext.getCmp('department_id').validateValue(Ext.getCmp('department_id').getValue()); //force update
      Ext.getCmp('status_id').allowBlank = false;
      Ext.getCmp('status_id').validateValue(Ext.getCmp('status_id').getValue()); //force update

			Ext.getCmp('employee_name').focus('', 10);
			Ext.getCmp('fSetDepart').hide();
			Ext.getCmp('btnTransferDept').disable();
			Ext.getCmp('fSetStatus').hide();
			Ext.getCmp('btnChangeSts').disable();

      comboEnable('department_id');
      comboEnable('status_id');
      comboEnable('section_id');

		}else{
			document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
			document.getElementById('popWdw').style.display='block';
		}
	};
	document.getElementById('editData').onclick = function(event) {
		task='';
    sectionDs.load({params: {department_id: dataRecord.department_id}});
		if (formAccess==3 || formAccess==4){
			frmEditor.getForm().reset();
			task = 'update';
      Ext.getCmp('department_id').allowBlank = true;
      Ext.getCmp('department_id').validateValue(Ext.getCmp('department_id').getValue()); //force update
      Ext.getCmp('status_id').allowBlank = true;
      Ext.getCmp('status_id').validateValue(Ext.getCmp('status_id').getValue()); //force update
			Ext.getCmp('frmEditor').getForm().load({
				url:'../php-crud/m.employeeCRD.php',
				params:{id: dataRecord.id, task: 'read_edit'},
				success:function(form, action) {
					var dt = action.result.data;
          sectionDs.load({params: {department_id: dt.department_id}});
					document.getElementById('dataPicPrev').src=(dt.photo_url==null)? '../img/no_image.png' : dt.photo_url;
					Ext.getCmp('transferDepartFrom').setValue(dt.department_name);
					Ext.getCmp('transferDepartTo').setValue(dt.department_id);
          Ext.getCmp('transferSectionFrom').setValue(dt.section_name);
          Ext.getCmp('transferSectionTo').setValue(dt.section_id);
					Ext.getCmp('transferDepartStart').setValue(transferDate);
					Ext.getCmp('changeStatusFrom').setValue(dt.status_name);
					Ext.getCmp('changeStatusTo').setValue(dt.status_id);
					Ext.getCmp('changeStatusStart').setValue(transferDate);
					statusId=dt.status_id;
					departmentId=dt.department_id;
          sectionId=dt.section_id;
				}
			});

			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Employee";

      var frmEditorHeight=(document.getElementById('editorForm').clientHeight - document.getElementById('editorFormHeader').clientHeight);
      Ext.getCmp('frmEditor').setHeight(frmEditorHeight);
      Ext.getCmp('frmEditor').doLayout(); // recalc column widths now that the panel is actually visible

			Ext.getCmp('fSetDepart').hide();
			Ext.getCmp('btnTransferDept').enable();
			Ext.getCmp('fSetStatus').hide();
			Ext.getCmp('btnChangeSts').enable();

      comboDisable('department_id');
      comboDisable('status_id');
      comboDisable('section_id');

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
	document.getElementById('printData').onclick = function(event) {window.open("../pdf/m.employee.list.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');};

	document.getElementById('saveEdit').onclick = function(event) {
    var genderValue = getRadioGroupValue(Ext.getCmp('gender'));
    var martialValue = getRadioGroupValue(Ext.getCmp('martial_status'));
		var pFile = document.getElementById('dataPicPrev').src;
		var pFileName = document.getElementById('imgfile').value==''? pFile.split('/')[pFile.split('/').length-1]:document.getElementById('imgfile').value.split('\\')[document.getElementById('imgfile').value.split('\\').length-1];
		frmEditor.getForm().submit({
			method:'POST',
			params: {task: task, photo: pFile, photoname: pFileName, martialValue: martialValue, genderValue: genderValue},
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
    var employeeName=dataRecord.employee_name;
    document.getElementById("logFormHeaderText").innerHTML = employeeName + '  [' + dataRecord.department_name + ']';
    GridHistory.setHeight(calculateDivHeight(['logForm','logFormHeader']));
    GridHistoryDs.load({params:{id:dataRecord.id}});
  };

	var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
	var statusId=0, departmentId=0; sectionId=0;
  var xDate = new Date();
  var transferDate = xDate.addDays(-2);

	var deleteDs = new Ext.data.Store({url: '../php-crud/m.employeeCRD.php'});
	var departmentDs = new Ext.data.Store({
    url: '../php-crud/m.departmentCRD.php',
    baseParams:{task: 'read'},
    reader: new Ext.data.JsonReader({
      root: 'data',
      totalProperty: 'totalCount',
      fields:[
        {name: 'id', type:'numeric'},
        {name: 'name', type:'string', convert : function(v, rec) {return rec.location_init + ' ~ ' + capitalizeEachWord(rec.name);}}
      ]
    })
  });
  departmentDs.load();
  var sectionDs = new Ext.data.Store({
    url: '../php-crud/m.departmentCRD.php',
    baseParams:{task: 'read_detail'},
    reader: new Ext.data.JsonReader({
      root: 'data',
      totalProperty: 'totalCount',
      fields:[
        {name: 'id', type:'numeric'},
        {name: 'seq_no', type:'numeric'},
        {name: 'name', type:'string', convert : function(v, rec) {return capitalizeEachWord(rec.section_name);}}
      ]
    })
  });
  sectionDs.load();
  var statusDs = new Ext.data.Store({
    url: '../php-crud/m.employee.status.php',
    baseParams:{task: 'read'},
    reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields:[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]})
  });
  statusDs.load();

  var employeeField = [
    {name:'id'},
    {name:'nik'},
    {name:'employee_name'},
    {name:'address'},
    {name:'id_number'},
    {name:'id_address'},
    {name:'npwp_no'},
    {name:'bank_account_no'},
    {name:'phone_number'},
    {name:'birth_date', type:'date', dateFormat:'Y-m-d'},
    {name:'place_birth'},
    {name:'gender'},
    {name:'martial_status'},
    {name:'emergency_name'},
    {name:'emergency_phone'},
    {name:'emergency_relation'},
    {name:'emergency_address'},
    {name:'from_date', type:'date', dateFormat:'Y-m-d'},
    {name:'remark'},
    {name:'employee_department_id'},
    {name:'department_id'},
    {name:'department_name', type:'string', convert : function(v, rec) {
      //return rec.location_init + (rec.department_name==null)? '' : ' ~ ' + capitalizeEachWord(rec.department_name);
      depatName=(!rec.department_name || !rec.department_name.length)? '' : (' - ' + capitalizeEachWord(rec.department_name));
      return rec.location_init + depatName;
    }},
    {name:'employee_status_id'},
    {name:'status_id'},
    {name:'status_name'},
    {name:'employee_section_id'},
    {name:'section_id'},
    {name:'section_name'},
    {name:'photo'},
    {name:'photo_url'},
    {name:'email'}
  ];
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.employeeCRD.php',
		baseParams:{task: 'read'},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalRecord', fields :employeeField})
	});
	mainDs.load();

  var frmEditor = new stdForm({
    url: '../php-crud/m.employeeCRD.php',
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({root : 'data',fields: employeeField}),
    renderTo: 'editorFormMain',
    labelAlign: 'right',
    autoScroll: 'true',
    items: [
      {items: new myTextField({name: 'id',id:'id', hidden: true})},
      {items: new myTextField({name: 'employee_department_id',id:'employee_department_id', hidden: true})},
      {items: new myTextField({name: 'employee_status_id',id:'employee_status_id', hidden: true})},
      {items: new myTextField({name: 'employee_section_id',id:'employee_section_id', hidden: true})},
      //{columnWidth: 1, xtype:'label', text:'* Personal Info', cls:'x-form-item my-label-styleBl'},
			{ columnWidth: .3, labelAlign: 'top', xtype: 'label', text: 'Personal Info', cls: 'x-form-item my-label-GroupLbl', style: 'clear:both;' },
			{ columnWidth: .7, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'} },
      {columnWidth: .15,labelAlign: 'top', style: 'clear:both;', items: new myTextField({fieldLabel:'NIK', id:'nik', name:'nik', readOnly:true})},
      {columnWidth: .35, labelAlign: 'top', items: new myTextField({fieldLabel:'Name', id:'employee_name', name:'employee_name',allowBlank: false,autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"},listeners :{change:function(field){field.setValue(field.getValue().toUpperCase());}}})},
      {columnWidth: .5, labelAlign: 'top', items: new myTextArea({fieldLabel:'Address', height: '55', id:'address', name:'address', allowBlank: false, autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth: .16, labelAlign: 'top', style: 'clear:both;', items: {xtype:'radiogroup', labelSeparator:'&nbsp;', cls:'fieldRadioClass', fieldLabel:'Gender ', id: 'gender',items : [{cls: 'regular-radio', boxLabel: 'Male', name: 'gender',inputValue: 'M',checked: true}, {cls: 'regular-radio', boxLabel: 'Female', name: 'gender',inputValue: 'F'}]}},
      {columnWidth: .34, labelAlign: 'top', items: {
        xtype:'radiogroup', labelSeparator:'&nbsp;', cls:'fieldRadioClass', fieldLabel:'Marital',  id: 'martial_status', allowBlank: true,
        items : [
          {cls: 'regular-radio', boxLabel: 'TK',name: 'martial_status',inputValue: 'TK',checked: true},
          {cls: 'regular-radio', boxLabel: 'K0',name: 'martial_status',inputValue: 'K0'},
          {cls: 'regular-radio', boxLabel: 'K1',name: 'martial_status',inputValue: 'K1'},
          {cls: 'regular-radio', boxLabel: 'K2',name: 'martial_status',inputValue: 'K2'},
          {cls: 'regular-radio', boxLabel: 'K3',name: 'martial_status',inputValue: 'K3'}
        ]
      }},
      {columnWidth: .18,labelAlign: 'top', items: new myTextField({fieldLabel:'Phone', id:'phone_number', name:'phone_number',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .18, labelAlign: 'top', items: new myTextField({fieldLabel:'NPWP No.', id:'npwp_no', name:'npwp_no',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .14, labelAlign: 'top', items: new myTextField({fieldLabel:'Bank Account No.', id:'bank_account_no', name:'bank_account_no', autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      //{columnWidth:1, xtype:'label', text:'* ID Information', cls:'x-form-item my-label-styleBl'},
			{ columnWidth: .3, xtype: 'label', text: 'ID Information', cls: 'x-form-item my-label-GroupLbl', style: 'clear:both;' },
			{ columnWidth: .7, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'} },
      {columnWidth: .2, labelAlign: 'top', style: 'clear:both;', items: new myTextField({fieldLabel:'ID No.', id:'id_number', name:'id_number', allowBlank: false, autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .2, labelAlign: 'top', items: new myTextField({fieldLabel:'Place of Birth', id:'place_birth', name:'place_birth', allowBlank: false, autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .2, labelAlign: 'top', items: new myDateField({fieldLabel:'Date of Birth', id:'birth_date', name:'birth_date', allowBlank: false})},
      {columnWidth: .4, labelAlign: 'top', items: new myTextArea({fieldLabel:'ID Address', height: '55', id:'id_address', name:'id_address', allowBlank: false, autoCreate : {tag: "textarea", autocomplete: "off"}})},
      //{columnWidth:1, xtype:'label', text:'* In Case of Emergency Please Notify', cls:'x-form-item my-label-styleBl'},
			{ columnWidth: .3, xtype: 'label', text: 'In Case of Emergency Please Notify', cls: 'x-form-item my-label-GroupLbl', style: 'clear:both;' },
			{ columnWidth: .7, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'} },
      {columnWidth: .2, labelAlign: 'top', style: 'clear:both;', items: new myTextField({fieldLabel:'Name', id:'emergency_name', name:'emergency_name',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .2, labelAlign: 'top', items: new myTextField({fieldLabel:'Phone', id:'emergency_phone', name:'emergency_phone',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .2, labelAlign: 'top', items: new myTextField({fieldLabel:'Relationship', id:'emergency_relation', name:'emergency_relation',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .4, labelAlign: 'top', items: new myTextArea({fieldLabel:'Address', height: '55', id:'emergency_address', name:'emergency_address',autoCreate : {tag: "textarea", autocomplete: "off"}})},
      //{columnWidth:1, xtype:'label', text:'* Public Info', cls:'x-form-item my-label-styleBl'},
			{ columnWidth: .3, xtype: 'label', text: 'Public Info', cls: 'x-form-item my-label-GroupLbl', style: 'clear:both;' },
			{ columnWidth: .7, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'} },
			{columnWidth: .15, xtype: 'button',text: 'Transfer Depart', style: 'clear:both;', cls:'field-button grey-field-button',id: 'btnTransferDept',listeners: {click: function() {Ext.getCmp('fSetDepart').show(); Ext.getCmp('btnTransferDept').disable();}}},
      {columnWidth: .15, xtype: 'button',text: 'Change Status',cls:'field-button grey-field-button',id: 'btnChangeSts',listeners: {click: function() {Ext.getCmp('fSetStatus').show(); Ext.getCmp('btnChangeSts').disable();}}},
      {columnWidth: .7, items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleButton'}},
      {columnWidth: .95, xtype:"fieldset", cls:'myfieldset', title:'Transfer to new department/section', flex:1, id:'fSetDepart',
        items:[{
					anchor:'98%',
          layout : "column", baseCls:'x-plain', defaults: {xtype:'container', layout:'form', columnWidth: .175, baseCls:'x-plain'},
          items  : [
            {columnWidth: .5, labelAlign: 'top', items: new myTextField({fieldLabel:'From Department', id:'transferDepartFrom', name:'transferDepartFrom',readOnly:true})},
            {columnWidth: .5, labelAlign: 'top', items: new myComboField({fieldLabel:'To Department', id:'transferDepartTo', name:'transferDepartTo', store:departmentDs,valueField:'id',displayField:'name', hiddenName:'transferDepartTo'})},
            {columnWidth: .5, labelAlign: 'top', items: new myTextField({fieldLabel:'From Section', id:'transferSectionFrom', name:'transferSectionFrom',readOnly:true})},
            {columnWidth: .5, labelAlign: 'top',
              items: new myComboField({
                fieldLabel:'To Section', id:'transferSectionTo', name:'transferSectionTo', store:sectionDs, valueField:'id',
                displayField:'name', hiddenName:'transferSectionTo',
                listeners: {focus: function(){sectionDs.load({params: {department_id: Ext.getCmp('transferDepartTo').getValue()}}); Ext.getCmp('transferSectionTo').setValue(sectionId);}}
              })
            },
            {columnWidth: .5, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'}},
            {columnWidth: .2, labelAlign: 'top', items: new myDateField({fieldLabel:'Start Date', id:'transferDepartStart', minValue: transferDate, name:'transferDepartStart'})},
            {columnWidth: .2, labelAlign: 'top', items: new myTimeField({fieldLabel: 'Start Time', id: 'start_time', name: 'start_time', })},
            {columnWidth: .1, items: {anchor: '90%', xtype:'button', text:'Discard', cls:'field-button red-field-button', id:'btnDiscardTransfer', listeners: {click: function() {Ext.getCmp('fSetDepart').hide(); Ext.getCmp('btnTransferDept').enable(); Ext.getCmp('transferDepartTo').setValue(departmentId); Ext.getCmp('transferSectionTo').setValue(sectionId);}}}}
          ]
        }]
      },

      {columnWidth: .95, xtype:'fieldset', cls:'myfieldset', title:'Change to new status', flex:1, id:'fSetStatus',
        items:[{
					anchor:'98%',
          layout : "column", baseCls:'x-plain', defaults: {xtype:'container', layout:'form', columnWidth: .25, baseCls:'x-plain'},
          items  : [
            {labelAlign: 'top', items: new myTextField({fieldLabel:'Current Status', id:'changeStatusFrom', name:'changeStatusFrom',readOnly:true})},
            {labelAlign: 'top', items: new myComboField({fieldLabel:'To', id:'changeStatusTo', name:'changeStatusTo', store:statusDs,valueField:'id',displayField:'name', hiddenName:'changeStatusTo'})},
            {columnWidth: .2, labelAlign: 'top', items: new myDateField({fieldLabel:'Start Date', id:'changeStatusStart', minValue: transferDate, name:'changeStatusStart'})},
            {columnWidth: .2, labelAlign: 'top', items: {xtype:'label', text:'-', cls:'x-form-item my-label-styleW'}},
            {columnWidth:.1, items: {anchor: '90%', xtype:'button', text:'Discard', cls:'field-button red-field-button', id:'btnDiscardChange', listeners: {click: function() {Ext.getCmp('fSetStatus').hide();Ext.getCmp('btnChangeSts').enable(); Ext.getCmp('changeStatusTo').setValue(statusId);}}}}
          ]
        }]
      },

      {columnWidth: .4, labelAlign: 'top', items: new myComboField({fieldLabel:'Department', id:'department_id', name:'department_id', store:departmentDs,valueField:'id',displayField:'name', hiddenName:'department_id', allowBlank: false})},
      {columnWidth: .6, labelAlign: 'top', items: new myComboField({fieldLabel:'Section', id:'section_id', name:'section_id', store:sectionDs, valueField:'id',displayField:'name', hiddenName:'section_id', listeners: {focus: function(){if (task=='create') {sectionDs.load({params: {department_id: Ext.getCmp('department_id').getValue()}});}}}})},
      {columnWidth: .4, labelAlign: 'top', items: new myComboField({fieldLabel:'Status', id:'status_id', name:'status_id', store:statusDs,valueField:'id',displayField:'name', hiddenName:'status_id', allowBlank: false})},
      {columnWidth: .2, labelAlign: 'top',  items: new myDateField({fieldLabel:'Join Date', id:'from_date', name:'from_date', allowBlank: false})},
      {columnWidth: .4, labelAlign: 'top', items: new myTextField({fieldLabel:'Email', id:'email', name:'email',autoCreate:{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: 1, labelAlign: 'top', items: new myTextArea({fieldLabel:'Remark', height: '55', id:'remark', name:'remark',autoCreate : {tag: "textarea", autocomplete: "off"}})}
    ]
	});
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

	var mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));
	function genderName(val, x, store){return '<div style="white-space:normal !important;">'+(val=='M'?'Male':'Female')+'</div>';}
  function departmentName(val, x, store){return '<div style="white-space:normal !important;">'+val+'</div>';}
  var gridMain = new stdGridPaging({
    id      : 'gridMain',
    store   : mainDs,
    height  : mainHeight,
    columns : [
      {header    : 'Department', dataIndex: 'department_name', width: 150, renderer: departmentName},
      {header    : 'Section', dataIndex: 'section_name', width: 250, renderer: CellWrap},
      {header    : 'NIK', dataIndex: 'nik', width: 100, renderer: CellWrap},
      {header    : 'Employee Name', dataIndex: 'employee_name', width: 250, renderer: CellWrap},
      {header    : 'F/M', dataIndex: 'gender', width: 70, renderer: genderName},
      {header    : 'Marital', dataIndex: 'martial_status', width: 80},
      {header    : 'Status', dataIndex: 'status_name', width: 80, renderer: CellWrap},
      {header    : 'Join Date', dataIndex: 'from_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M y')},
      //{header    : 'Remark', dataIndex: 'remark', width: 250, renderer: CellWrap},
      {dataIndex : 'department_id', hidden: true},
      {dataIndex : 'id', hidden: true}
    ],
    pageSize  : 250,
    pageStore : mainDs,
    renderTo  : 'divMainGrid'
  });
  var GridHistoryDs = new historyDs({url: '../php-crud/m.employeeCRD.php'});
  var GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});


	document.getElementById('editorWdw').style.display='none';
	document.getElementById('logWdw').style.display='none';

	Ext.getCmp('fSetDepart').hide();
	Ext.getCmp('fSetStatus').hide();

  // set nik(id) css using jquery
  $("#nik").prop('disabled', true);
});