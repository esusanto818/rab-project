Ext.onReady(function() {
	Ext.QuickTips.init();
	document.getElementById('addWdw').style.display='block';
	document.getElementById('addData').onclick = function(event) {
    document.getElementById("cst_type").style.pointerEvents = 'auto';
		document.getElementById("saveEdit").style.pointerEvents = 'auto';
		document.getElementById("saveEdit").style.backgroundColor = '#1D6788';
		document.getElementById("saveEdit").style.color = '#fff';
		task='';
		if (formAccess==2 || formAccess==3 || formAccess==4){
			task = 'create';
      gridEditorDs.load({params:{id: 0}});
			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "New Customer";

      var frmEditorHeight=(document.getElementById('editorForm').clientHeight - document.getElementById('editorFormHeader').clientHeight)+5;
      Ext.getCmp('frmEditor').setHeight(frmEditorHeight);

			frmEditor.getForm().reset();
			Ext.getCmp('init').focus('', 10);
			Ext.getCmp('country').setValue('1');
			Ext.getCmp('country').getEl().dom.removeAttribute('readOnly');
      Ext.getCmp('country').readOnly = false;
      Ext.getCmp('fsetnpwp').show();
      npwpNotAllowBlank();
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
			Ext.getCmp('frmEditor').getForm().load({
				url:'../php-crud/m.customerCRD.php',
				params:{id: dataRecord.id, task: 'readedit'},
				success:function(form, action) {
					var dt = action.result.data;
          cityDs.load({params: {province_id: dt.province},callback: function(success , r){if(success){Ext.getCmp('city').setValue(dt.city);}}});
          districtDs.load({params: {city_id: dt.city},callback: function(success , r){if(success){Ext.getCmp('district').setValue(dt.district);}}});
          villageDs.load({params: {district_id: dt.district},callback: function(success , r){if(success){Ext.getCmp('village').setValue(dt.village);}}});
          if (dt.cst_type==1) {Ext.getCmp('fsetnpwp').show();npwpNotAllowBlank();} else {Ext.getCmp('fsetnpwp').hide();npwpAllowBlank();}
				}
			});
			document.getElementById('editorWdw').style.display='block';
			document.getElementById("editorFormHeaderText").innerHTML = "Edit Customer";

      var frmEditorHeight=(document.getElementById('editorForm').clientHeight - document.getElementById('editorFormHeader').clientHeight)+5;
      Ext.getCmp('frmEditor').setHeight(frmEditorHeight);
      Ext.getCmp('country').getEl().dom.setAttribute('readOnly', true);
      Ext.getCmp('country').readOnly = true;
      document.getElementById("cst_type").style.pointerEvents = 'none';
		}else{
			document.getElementById("popWdwTxt").innerHTML ='not enough permission to access this window';
			document.getElementById('popWdw').style.display='block';
		}
		task='update';
	};
	document.getElementById('deleteData').onclick = function(event) {
		if (formAccess==4){
			task='delete'
			var customerName=dataRecord.name;
			document.getElementById('popWdw').style.display='block';
			document.getElementById('popCancel').style.display='block';
			document.getElementById("popWdwTxt").innerHTML = 'delete \'' + customerName + '\'  ?';
		}else{
			document.getElementById("popWdwTxt").innerHTML ='not enough permission to delete this data';
			document.getElementById('popWdw').style.display='block';
		}
	};
	document.getElementById('printData').onclick = function(event) {
		window.open("../pdf/m.customer.name.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
	};
	document.getElementById('saveEdit').onclick = function(event) {
		var arrContact = new Ext.data.ArrayStore({fields: contactField});
		arrContact.loadData(EmptyArr);
		for (i=0;i<gridEditorDs.data.length;i++){
			var dataFormGrid=gridEditorDs.data.items[i];
			arrContact.add(new addRow({
        cont_id          : dataFormGrid.data.cont_id
        ,name            : dataFormGrid.data.name
        ,position        : dataFormGrid.data.position
        ,cont_phone_id   : dataFormGrid.data.cont_phone_id
        ,phone           : dataFormGrid.data.phone
        ,cont_mobile_id  : dataFormGrid.data.cont_mobile_id
        ,mobile          : dataFormGrid.data.mobile
        ,cont_email_id   : dataFormGrid.data.cont_email_id
        ,email           : dataFormGrid.data.email
        ,cont_address_id : dataFormGrid.data.cont_address_id
        ,address         : dataFormGrid.data.address
        ,remark          : dataFormGrid.data.remark
			}));
		}

		frmEditor.getForm().submit({
			method:'POST',
			params: {
				task          : task
				,cust_type    : Ext.getCmp('customer_l').getValue() || 0
				,ass_addr_off : Ext.getCmp('as_addr').getValue()
				,arrContact   : DsArray2Json(arrContact)
			},
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
				params:{task: "delete", id: dataRecord.id }
				,callback:function(records, operation, success){
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
		var customerName=dataRecord.first_name + ' ' + dataRecord.last_name;
		document.getElementById("logFormHeaderText").innerHTML = customerName;
		GridHistoryDs.load({params:{id:dataRecord.id}});
	};

	var myMask = new Ext.LoadMask(Ext.getBody(), {msg:"Please wait..."});
	var deleteDs = new Ext.data.Store({url: '../php-crud/m.customerCRD.php'});
	var statusId=0, departmentId=0, gridEditorCheck=0;

  function npwpAllowBlank(){
    Ext.getCmp('npwp').allowBlank = true;
    Ext.getCmp('street').allowBlank = true;
    Ext.getCmp('street_no').allowBlank = true;
    Ext.getCmp('province').allowBlank = true;
    Ext.getCmp('city').allowBlank = true;
    Ext.getCmp('district').allowBlank = true;
    Ext.getCmp('village').allowBlank = true;
  }
  function npwpNotAllowBlank(){
    Ext.getCmp('npwp').allowBlank = false;
    Ext.getCmp('street').allowBlank = false;
    Ext.getCmp('street_no').allowBlank = false;
    Ext.getCmp('province').allowBlank = false;
    Ext.getCmp('city').allowBlank = false;
    Ext.getCmp('district').allowBlank = false;
    Ext.getCmp('village').allowBlank = false;
  }
	var currencyDs = new Ext.data.Store({
		url: '../php-crud/m.currencyCRD.php',
		baseParams:{task: 'read'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]
		})
	});
	currencyDs.load();
	var countryDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readcountry'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]
		})
	});
	countryDs.load();
	var provinceDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readprov', country_id:1},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]})
	});
	provinceDs.load();
	var cityDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readcity'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]})
	});
	var districtDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readdistrict'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name: 'id', type:'numeric'},{name: 'name', type:'string'}]})
	});
	var villageDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readvillage'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalCount',fields :[{name  : 'id', type:'numeric'},{name : 'name', type:'string'},{name : 'postalcode', type:'string'}]})
	});

	var customerField = [
    {name :'id'},
    {name  :'npwp_id'},
    {name :'npwp_address_id'},
    {name :'address_id'},
    {name :'payment_period_id'},
    {name :'customer_credit_limit_id'},
    {name :'office_email_id'},
    {name :'office_mobile_id'},
    {name :'office_phone_id'},
    {name :'office_fax_id'},
    {name :'init'},
    {name  :'name'},
    {name  :'address'},
    {name  :'contact_name'},
    {name  :'npwp'},
    {name  :'cr_limit'},
    {name  :'remark'},
    {name  :'country'},
    {name  :'cst_type'},
    {name :'limit_amount'},
    {name :'limit_currency'},
    {name :'payment_period'},
    {name :'office_email'},
    {name :'office_mobile'},
    {name :'office_phone'},
    {name :'office_fax'},
    {name :'as_addr'},
    {name :'village'},
    {name :'district'},
    {name :'city'},
    {name :'province'},
    {name :'postal_code'},
    {name :'street'},
    {name :'street_no'},
    {name :'block'},
    {name :'rt'},
    {name :'rw'},
	];
	mainDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		idProperty: 'id',
		baseParams:{task: 'read'},
		reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalRecord',fields :customerField})
	});
	mainDs.load();

	var contactField = [
    {name:'cont_id'},
    {name:'name'},
    {name:'position'},
    {name:'remark'},
    {name:'cont_address_id'},
    {name:'address'},
    {name:'cont_email_id'},
    {name:'email'},
    {name:'cont_mobile_id'},
    {name:'mobile'},
    {name:'cont_phone_id'},
    {name:'phone'},
    {name:'contact'},
	];
	var gridEditorDs = new Ext.data.Store({
		url: '../php-crud/m.customerCRD.php',
		baseParams:{task: 'readcontact'},
		reader: new Ext.data.JsonReader({root: 'data', totalProperty: 'totalRecord', fields : contactField})
	});
	var addRow = Ext.data.Record.create(contactField);
	var editorDeleter = new Extensive.grid.ItemDeleter();
	var gridEditor = new stdGridEditor({
    store    : gridEditorDs,
    id       : 'gridEditor',
    selModel : editorDeleter,
		height   : 150,
    columns  :[
			{header: "Name", dataIndex: 'name', width: 80, renderer: CellWrap},
			{header: "Position",dataIndex: 'position',width: 80,align: 'left',renderer: CellWrap},
			{header: "Address", dataIndex: 'address', width: 200, renderer: CellWrap},
			{header: "Contact", dataIndex: 'contact', width: 100, renderer: CellWrap},
			{header: "Remark", dataIndex: 'remark', width: 100, renderer: CellWrap},
			editorDeleter,
			{dataIndex :'phone', hidden: true},
			{dataIndex :'mobile', hidden: true},
			{dataIndex :'email', hidden: true},
			{dataIndex :'cont_id', hidden: true},
			{dataIndex :'cont_address_id', hidden: true},
			{dataIndex :'cont_email_id', hidden: true},
			{dataIndex :'cont_mobile_id', hidden: true},
			{dataIndex :'cont_phone_id', hidden: true},
    ],
		listeners: {
			cellclick : function(grid, rowIndex, columnIndex, e){
				var record=grid.getStore().getAt(rowIndex).data;
        frmAdd.getForm().reset();
        (Ext.getCmp('cont_addr').getValue()).replace(/\n/gi, '<br>')
        Ext.getCmp('cont_id').setValue(record.cont_id);
        Ext.getCmp('cont_address_id').setValue(record.cont_address_id);
        Ext.getCmp('cont_email_id').setValue(record.cont_email_id);
        Ext.getCmp('cont_mobile_id').setValue(record.cont_mobile_id);
        Ext.getCmp('cont_phone_id').setValue(record.cont_phone_id);
        Ext.getCmp('cont_name').setValue(record.name);
        Ext.getCmp('cont_position').setValue(record.position);
        Ext.getCmp('cont_phone').setValue(record.phone);
        Ext.getCmp('cont_mobile').setValue(record.mobile);
        Ext.getCmp('cont_email').setValue(record.email);
        Ext.getCmp('cont_addr').setValue((record.address).replace(/<br>/gi, '\n'));
        Ext.getCmp('cont_remark').setValue(record.remark);
			}
		}
  });
	gridEditor.addClass('x-grid3-gridEditor');

	var frmEditor = new stdForm({
    url: '../php-crud/m.customerCRD.php',
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({root : 'data',fields: customerField}),
    renderTo: 'editorFormMain',
    labelAlign: 'right',
    autoScroll: 'true',
    items: [
      { labelAlign: 'top',
        items : {
          xtype          : 'radiogroup',
          labelSeparator : '&nbsp;&nbsp;',
          cls            : 'fieldClass',
          fieldLabel     : 'Type ',
          id             : 'cst_type',
          name           : 'cst_type',
          items          : [
            {cls: 'regular-radio', boxLabel:'Local', id:'customer_l', name: 'c_type', inputValue:'1', checked:true},
            {cls: 'regular-radio', boxLabel:'Overseas', id:'customer_o', name: 'c_type', inputValue:'2', checked:false}
          ]
        }
      },
      {labelAlign: 'top', items: new myComboField({fieldLabel:'Country', id:'country', name:'country', store:countryDs, valueField:'id',displayField:'name', hiddenName:'country'})},
      {columnWidth: .5, items:spaceTextField},
      {labelAlign: 'top', items: new myTextField({fieldLabel:'Init', id:'init', name:'init', allowBlank:false, maskRe:/^([a-zA-Z0-9])/,autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"},listeners :{change:function(field){field.setValue(field.getValue().toUpperCase());}}})},
      {labelAlign: 'top', columnWidth: .75, items: new myTextField({	fieldLabel:'Name', id:'name', name:'name', allowBlank:false	,autoCreate : {tag: "input", maxlength : 200, type: "text", size: "200", autocomplete: "off"}, listeners :{change:function(field){field.setValue(field.getValue().toUpperCase());}}})},
      {labelAlign: 'top', items: new myTextField({fieldLabel:'Phone', id:'office_phone', name:'office_phone', allowBlank:true,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {labelAlign: 'top', items: new myTextField({fieldLabel:'Mobile', id:'office_mobile', name:'office_mobile', allowBlank:true,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {labelAlign: 'top', items: new myTextField({fieldLabel:'Fax', id:'office_fax', name:'office_fax', allowBlank:true,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {labelAlign: 'top', items: new myTextField({fieldLabel:'Email', id:'office_email', name:'office_email', allowBlank:true,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .95,xtype:"fieldset", cls:'myfieldset', title:'NPWP Info', flex:1, id:'fsetnpwp',
        items:[{
            layout : "column", baseCls:'x-plain'
            ,defaults: {xtype:'container', layout:'form', columnWidth: .25, baseCls:'x-plain'}
            ,items  : [
              {labelAlign: 'top', items: new myTextField({fieldLabel:'NPWP', id:'npwp', name:'npwp', allowBlank:true, maskRe: /^([0-9])/,autoCreate :{tag: "input", maxlength : 20, type: "text", size: "20", autocomplete: "off"}})},
							{labelAlign: 'right', labelWidth: 240, items: {xtype:'checkbox', labelSeparator : '&nbsp;&nbsp;-&nbsp;&nbsp;', name:'as_addr', id:'as_addr', cls:'fieldCheckClass regular-checkbox', checked: false, fieldLabel: 'Use NPWP address as office address'}},
							{columnWidth: .5, items:spaceTextField},
              {labelAlign: 'top', columnWidth: .75, items: new myTextField({fieldLabel:'Street', id:'street', name:'street', allowBlank:true,autoCreate :{tag: "input", maxlength : 200, type: "text", size: "200", autocomplete: "off"}
								})},
              {labelAlign: 'top', items: new myTextField({fieldLabel:'ZIP Code.', id:'postal_code', name:'postal_code', readOnly: true, allowBlank:true})},
              {labelAlign: 'top', items: new myTextField({fieldLabel:'Blok', id:'block', name:'block', allowBlank:true, autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"}
								})},
              {labelAlign: 'top', items: new myTextField({fieldLabel:'No', id:'street_no', name:'street_no', allowBlank:true, autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"}
								})},
              {labelAlign: 'top', items: new myTextField({fieldLabel:'RT', id:'rt', name:'rt', allowBlank:true, autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"}})},
              {labelAlign: 'top', items: new myTextField({fieldLabel:'RW', id:'rw', name:'rw', allowBlank:true,autoCreate :{tag: "input", maxlength : 50, type: "text", size: "50", autocomplete: "off"}})},
              {labelAlign: 'top', items: new myComboField({fieldLabel:'Province', id:'province', name:'province', store:provinceDs, valueField:'id',displayField:'name', hiddenName:'province',allowBlank:true
			          })},
              {labelAlign: 'top', items: new myComboField({fieldLabel:'City', id:'city', name:'city', store:cityDs, valueField:'id',displayField:'name', hiddenName:'city',allowBlank:true,
								listeners: {
									focus: function(){
										cityDs.load({params: {province_id: Ext.getCmp('province').getValue()}});
									}
								}
							})},
              {labelAlign: 'top', items: new myComboField({fieldLabel:'District', id:'district', name:'district', store:districtDs, valueField:'id',displayField:'name', hiddenName:'district',allowBlank:true
			            ,listeners: {focus: function(){districtDs.load({params: {city_id: Ext.getCmp('city').getValue()}});}}
			          })},
              {labelAlign: 'top', items: new myComboField({fieldLabel:'Village', id:'village', name:'village', store:villageDs, valueField:'id',displayField:'name', hiddenName:'village',allowBlank:true,listeners: {
								focus: function(){villageDs.load({params: {district_id: Ext.getCmp('district').getValue()}});}, select:function(combo,value){Ext.getCmp('postal_code').setValue(value.data.postalcode);}}})}
            ]
        }]
      },
      {labelAlign: 'top', columnWidth:1, items: new myTextArea({fieldLabel:'Address', id:'address', name:'address', allowBlank:false,autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {columnWidth: .95, xtype:"fieldset", cls:'myfieldset', title:'Credit Limit', flex:1,
        items:[{
          layout : "column", baseCls:'x-plain'
          ,defaults: {xtype:'container', layout:'form', columnWidth: .25, labelWidth: 100, baseCls:'x-plain'}
          ,items  : [
            {labelAlign: 'top', items: new myComboField({fieldLabel:'Currency', id:'limit_currency', name:'limit_currency', store:currencyDs,valueField:'id',displayField:'name', hiddenName:'limit_currency'})},
            {labelAlign: 'top', items: new myNumberField({fieldLabel:'Amount', id:'limit_amount', name:'limit_amount', allowBlank:true})},
            {labelAlign: 'top', labelWidth: 200,items: new myNumberField({fieldLabel:'Payment Period (in days)', id:'payment_period', name:'payment_period',allowBlank:true, decimalPrecision : 0})}
          ]
        }]
      },
      {labelAlign: 'top', columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark', allowBlank:true
          ,autoCreate : {tag: "textarea", autocomplete: "off"}})},
			{ columnWidth: .2, xtype: 'label', text: 'Contact Person', cls: 'x-form-item my-label-GroupLbl' },
			{ columnWidth: .65, items: FieldSpace },
      {columnWidth: .075, xtype: 'button',text: 'Add',cls:'field-button purple-field-button',id: 'btnAddContact',listeners: {click: function() {
          gridEditorCheck=0;
          frmAdd.getForm().reset();
          document.getElementById('addWdw').style.display='block';
          document.getElementById("addFormHeaderText").innerHTML = "Add Contact";}}
      },
    	{columnWidth: .075, xtype: 'button',text: 'Edit',cls:'field-button purple-field-button',id: 'btnEditContact',listeners: {click: function() {gridEditorCheck=1;
      	document.getElementById('addWdw').style.display='block';
        document.getElementById("addFormHeaderText").innerHTML = "Edit Contact";}}
      },
			{columnWidth:1, items:gridEditor},
      {items: new myTextField({name: 'id',id:'id', hidden: true})},
      {items: new myTextField({name: 'npwp_id',id:'npwp_id', hidden: true})},
      {items: new myTextField({name: 'npwp_address_id',id:'npwp_address_id', hidden: true})},
      {items: new myTextField({name: 'address_id',id:'address_id', hidden: true})},
      {items: new myTextField({name: 'payment_period_id',id:'payment_period_id', hidden: true})},
      {items: new myTextField({name: 'customer_credit_limit_id',id:'customer_credit_limit_id', hidden: true})},
      {items: new myTextField({name: 'office_email_id',id:'office_email_id', hidden: true})},
      {items: new myTextField({name: 'office_mobile_id',id:'office_mobile_id', hidden: true})},
      {items: new myTextField({name: 'office_phone_id',id:'office_phone_id', hidden: true})},
      {items: new myTextField({name: 'office_fax_id',id:'office_fax_id', hidden: true})},
    ]
	});
  npwpNotAllowBlank();

	frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable()} else {saveEditEnable()}});

	//document.getElementById('editorWdw').style.display='block';
	document.getElementById('customer_l').onclick = function(event) {
		Ext.getCmp('fsetnpwp').show();
		Ext.getCmp('country').setValue('1');
		Ext.getCmp('country').getEl().dom.setAttribute('readOnly', true);
		Ext.getCmp('country').readOnly = true;
    	npwpNotAllowBlank();
	}
	document.getElementById('customer_o').onclick = function(event) {
		Ext.getCmp('fsetnpwp').hide();
		Ext.getCmp('address').setValue('');
		Ext.getCmp('as_addr').setValue(false);
		Ext.getCmp('country').setValue(null);
		Ext.getCmp('country').getEl().dom.removeAttribute('readOnly');
		Ext.getCmp('country').readOnly = false;
    	npwpAllowBlank();
	}
	document.getElementById('as_addr').onclick = function(event) {
		if (Ext.getCmp('as_addr').getValue()) {
			var addrnpwp = Ext.getCmp('street').getValue()  || '';
			addrnpwp +=	(Ext.getCmp('block').getValue()==null || Ext.getCmp('block').getValue()=='') ? '' : ' Blok : ' + Ext.getCmp('block').getValue();
			addrnpwp +=	(Ext.getCmp('street_no').getValue()==null || Ext.getCmp('street_no').getValue()=='') ? '' : ' No : ' + Ext.getCmp('street_no').getValue();
			addrnpwp +=	(Ext.getCmp('rt').getValue()==null || Ext.getCmp('rt').getValue()=='') ? '' : ' RT : ' + Ext.getCmp('rt').getValue();
			addrnpwp +=	(Ext.getCmp('rw').getValue()==null || Ext.getCmp('rw').getValue()=='') ? '' : ' RW : ' + Ext.getCmp('rw').getValue();
			addrnpwp +=	(Ext.getCmp('city').getValue()==null || Ext.getCmp('city').getValue()=='') ? '' : '\n' + Ext.getCmp('city').getRawValue();
			addrnpwp +=	(Ext.getCmp('district').getValue()==null || Ext.getCmp('district').getValue()=='') ? '' : ' - ' + Ext.getCmp('district').getRawValue();
			addrnpwp +=	(Ext.getCmp('village').getValue()==null || Ext.getCmp('village').getValue()=='') ? '' : ' - ' + Ext.getCmp('village').getRawValue();
			addrnpwp +=	(Ext.getCmp('postal_code').getValue()==null || Ext.getCmp('postal_code').getValue()=='') ? '' : ' (' + Ext.getCmp('postal_code').getRawValue() + ')';
			addrnpwp +=	(Ext.getCmp('province').getValue()==null || Ext.getCmp('province').getValue()=='') ? '' : '\n' + Ext.getCmp('province').getRawValue() + ' - Indonesia';
			Ext.getCmp('address').setValue(addrnpwp);
		} else {
			Ext.getCmp('address').setValue('');
		}
	}
	document.getElementById('addSaveEdit').onclick = function(event) {
    var cont_contact = (Ext.getCmp('cont_phone').getValue()==null || Ext.getCmp('cont_phone').getValue()=='') ? '' : ' Phone : ' + Ext.getCmp('cont_phone').getValue();
    cont_contact += (Ext.getCmp('cont_mobile').getValue()==null || Ext.getCmp('cont_mobile').getValue()=='') ? '' : '<br> Mobile : ' + Ext.getCmp('cont_mobile').getValue();
    cont_contact += (Ext.getCmp('cont_email').getValue()==null || Ext.getCmp('cont_email').getValue()=='') ? '' : ' <br> Email : ' + Ext.getCmp('cont_email').getValue();

    if (gridEditorCheck==1) {
      var rec = Ext.getCmp('gridEditor').getSelectionModel().getSelected();
      rec.set('name', Ext.getCmp('cont_name').getValue());
      rec.set('position', Ext.getCmp('cont_position').getValue());
      rec.set('address', (Ext.getCmp('cont_addr').getValue()).replace(/\n/gi, '<br>'));
      rec.set('contact', cont_contact);
      rec.set('phone', Ext.getCmp('cont_phone').getValue());
      rec.set('mobile', Ext.getCmp('cont_mobile').getValue());
      rec.set('email', Ext.getCmp('cont_email').getValue());
      rec.set('remark', Ext.getCmp('cont_remark').getValue());
    } else {
      Ext.getCmp('gridEditor').store.add(new addRow({
        cont_id          : Ext.getCmp('cont_id').getValue()
        ,cont_address_id : Ext.getCmp('cont_address_id').getValue()
        ,cont_email_id   : Ext.getCmp('cont_email_id').getValue()
        ,cont_mobile_id  : Ext.getCmp('cont_mobile_id').getValue()
        ,cont_phone_id   : Ext.getCmp('cont_phone_id').getValue()
        ,name            : Ext.getCmp('cont_name').getValue()
        ,position        : Ext.getCmp('cont_position').getValue()
        ,address         : (Ext.getCmp('cont_addr').getValue()).replace(/\n/gi, '<br>')
        ,contact         : cont_contact
        ,phone           : Ext.getCmp('cont_phone').getValue()
        ,mobile          : Ext.getCmp('cont_mobile').getValue()
        ,email           : Ext.getCmp('cont_email').getValue()
        ,remark          : Ext.getCmp('cont_remark').getValue()
      }));
    }
		document.getElementById('addWdw').style.display='none';
	}

  var frmAdd = new stdForm({
    id       : 'frmAdd',
    renderTo : 'addFormMain',
    defaults : {xtype:'container', layout:'form', columnWidth: 0.333, baseCls:'x-plain'},
    items    : [
    	{columnWidth: .75, items: new myTextField({fieldLabel:'Name', id:'cont_name', name:'cont_name', allowBlank:false, autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {columnWidth: .25, items: new myTextField({fieldLabel:'Position', id:'cont_position', name:'cont_position',autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {items: new myTextField({fieldLabel:'Phone', id:'cont_phone', name:'cont_phone', maskRe:/^([0-9\- \(\)])/,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {items: new myTextField({fieldLabel:'Mobile', id:'cont_mobile', name:'cont_mobile', maskRe:/^([0-9\- \(\)])/,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})},
      {items: new myTextField({fieldLabel:'Email', id:'cont_email', name:'cont_email', maskRe:/^([a-zA-Z0-9\.@])/,autoCreate :{tag: "input", maxlength : 100, type: "text", size: "100", autocomplete: "off"}})
			},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Address', id:'cont_addr', name:'cont_addr', allowBlank:true,autoCreate : {tag: "textarea", autocomplete: "off"}})},
			{columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'cont_remark', name:'cont_remark', allowBlank:true,autoCreate : {tag: "textarea", autocomplete: "off"}})},
      {items: new myTextField({name: 'cont_id',id:'cont_id', hidden: true})},
      {items: new myTextField({name: 'cont_address_id',id:'cont_address_id', hidden: true})},
      {items: new myTextField({name: 'cont_email_id',id:'cont_email_id', hidden: true})},
      {items: new myTextField({name: 'cont_mobile_id',id:'cont_mobile_id', hidden: true})},
      {items: new myTextField({name: 'cont_phone_id',id:'cont_phone_id', hidden: true})}
		]
  });
  frmAdd.on('clientvalidation', function(f,valid) {if (!valid) {addSaveEditDisable()} else {addSaveEditEnable()}});

	var mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));
	var gridMain = new stdGridPaging({
		store  : mainDs
		,height : mainHeight
		,columns:[
			{header: "Init", dataIndex: 'init', width: 80, renderer: CellWrap}
			,{header: "Name", dataIndex: 'name', width: 100, renderer: CellWrap}
			,{header: "NPWP", dataIndex: 'npwp', width: 100, renderer: CellWrap}
			,{header: "Address", dataIndex: 'address', width: 200, renderer: CellWrap}
			,{header: "Contact", dataIndex: 'contact_name', width: 100, renderer: CellWrap}
			,{header: "Credit Limit", dataIndex: 'cr_limit', width: 100, renderer: CellWrap}
			,{header: "Remark", dataIndex: 'remark', width: 200, renderer: CellWrap}
			,{dataIndex: 'id', hidden: true}
		],
    listeners: {
      cellclick : function(grid, rowIndex, columnIndex, e){
        var record=grid.getStore().getAt(rowIndex).data;
        gridEditorDs.load({params:{id: record.id}});
      }
    },
    pageSize  : 250,
    pageStore : mainDs,
    renderTo  : 'divMainGrid'
	});
	var GridHistoryDs = new historyDs({url: '../php-crud/m.customerCRD.php'});
	var GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});
	document.getElementById('logWdw').style.display='none';
	document.getElementById('addWdw').style.display='none';
	document.getElementById('linksOverlay').style.display='none';
	document.getElementById('editorFormMain').style.maxHeight = window.innerHeight-110;
	document.getElementById('editorWdw').style.display='none';
  $("#cst_type").css({'height':'18px', 'padding-top': '3px'});

});
