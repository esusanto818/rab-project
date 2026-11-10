Ext.onReady(function() {
	Ext.QuickTips.init();
  let myMask = new Ext.LoadMask(Ext.getBody(), {msg:'Please wait...'});
  let deleteDs = new Ext.data.Store({url: '../php-crud/purchase.orderCRD.php'});
  ArrayItem=[], editReq=0, costStatus='local';
  let gridEditorHeight=0, gridItemHeight=0;
  function setGridEditorHeight(){
    gridEditorHeight = $('#editorForm').height() - $('#editorFormHeader').height() - $('#editorFormMain').height() - 2;
    frmGridEditor.setHeight(gridEditorHeight); gridEditor.setHeight(gridEditorHeight); gridCosts.setHeight(gridEditorHeight);
  }
  document.getElementById('mainHeaderText').innerHTML = 'Purchase <em>Order</em> ';
  document.getElementById('addData').onclick = function(event) {
    task='';
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      document.getElementById('editorWdw').style.display='block';
      document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;New P.O.';
      setGridEditorHeight();
      gridEditorDs.load({params:{po_hdr_id: 0}}); gridCostDs.load({params:{po_id: 0}});
			Ext.getCmp('frmGridEditor').doLayout();
      Ext.getCmp('frmEditor').getForm().reset();
      Ext.getCmp('currency_id').setValue('2');
      Ext.getCmp('supplier_id').getEl().dom.removeAttribute('readOnly'); Ext.getCmp('supplier_id').readOnly = false;
      Ext.getCmp('eta_date').focus('', 10);
    }else{
      document.getElementById('popWdwTxt').innerHTML = 'not enough permission to access this window';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('editData').onclick = function(event) {
    task='';
    if (parseInt(formAccess, 10) >= 2) {
      task = 'update';
      Ext.getCmp('frmEditor').getForm().load({
        url:'../php-crud/purchase.orderCRD.php',
        params:{id: dataRecord.id, task: 'read_edit'},
        success:function(form, action) {}
      });
      document.getElementById('editorWdw').style.display='block';
      document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit P.O.';
      setGridEditorHeight();
      Ext.getCmp('supplier_id').getEl().dom.setAttribute('readOnly', true);
      Ext.getCmp('supplier_id').readOnly = true;
    }else{
      document.getElementById('popWdwTxt').innerHTML ='not enough permission to access this window';
      document.getElementById('popWdw').style.display='block';
    }
    task='update';
  };
  document.getElementById('deleteData').onclick = function(event) {
    if (formAccess==4){
      task='delete';
      let employeeName=dataRecord.first_name + ' ' + dataRecord.last_name;
      document.getElementById('popWdw').style.display='block';
      document.getElementById('popCancel').style.display='block';
      document.getElementById('popWdwTxt').innerHTML = 'delete \'' + employeeName + '\'  ?';
    }else{
      document.getElementById('popWdwTxt').innerHTML ='not enough permission to delete this data';
      document.getElementById('popWdw').style.display='block';
    }
  };
  document.getElementById('printData').onclick = function(event) {
    if (poId > 0) {
        window.open("/rab-project/pdf/rpt_po_supplier_print.php?id=" + poId, '_blank');
    } else {
        Ext.Msg.alert('Warning', 'Pilih Sales Order terlebih dahulu.');
        // Panggil dengan rentang tanggal
        let startDate = '2019-07-01';
        let endDate   = '2019-07-31';
        window.open("/rab-project/pdf/rpt_po_list_by_date.php?start_date=" + startDate + "&end_date=" + endDate, '_blank');
    }
   
  };
  document.getElementById('saveEdit').onclick = function(event) {
		let arrItem = dataStore2Array(gridEditorDs).map(dataItem => {
      return {
				po_hdr_id         : dataItem.po_hdr_id,
        po_dtl_id         : dataItem.po_dtl_id,
        item_id           : dataItem.item_id,
        qty               : parseFloat($N(dataItem.total_qty,0)),
        remark            : $N(dataItem.remark,''),
        unit_price        : parseFloat($N(dataItem.unit_price,0)),
        additional_qty    : parseFloat($N(dataItem.additional_qty,0)),
        disc_item         : parseFloat($N(dataItem.disc_item,0)),
        disc_item_percent : (dataItem.disc_item_percent==undefined || dataItem.disc_item_percent==null || dataItem.disc_item_percent=='') ? 'false' : 'true',
        req_id            : dataItem.req_id
      };
    });
    let arrCost = dataStore2Array(gridCostDs).filter((dataCostDtl) => {return parseInt($N(dataCostDtl.amount,0))>0;}).map((dataCostDtl) => {
      return {
        po_hdr_id  : dataCostDtl.po_id,
        po_cost_id : dataCostDtl.po_cost_id,
        cost_id    : dataCostDtl.cost_id,
        amount     : parseFloat($N(dataCostDtl.amount,0)),
        po_include : (dataCostDtl.po_include==undefined || dataCostDtl.po_include==null || dataCostDtl.po_include=='') ? 'false' : 'true'
      };
    });

    Ext.getCmp('frmEditor').getForm().submit({
      method:'POST',
      params : {
        task        : task,
        ppn         : Ext.getCmp('po_ppn').getValue(),
        disc_pcnt   : Ext.getCmp('disc_percent').getValue(),
        allow_pcnt  : Ext.getCmp('allow_percent').getValue(),
        arrItemDtl  : Array2Json(arrItem),
        arrCostDtl  : Array2Json(arrCost)
      },
      waitTitle:'Connecting',
      waitMsg:'Sending data...',
      success:function(form,action){
        var objResponse=Ext.util.JSON.decode(action.response.responseText);
        var messagesStatus=objResponse.status; var messages=objResponse.message;
        /*document.getElementById('popWdwMainHeaderText').innerHTML=messagesStatus;*/
        document.getElementById('popWdwTxt').innerHTML=messages;
        document.getElementById('popWdw').style.display='block';
        mainDs.load({params:{task: 'read'}});
        frmEditor.getForm().reset();
        document.getElementById('editorWdw').style.display='none';
      },
      failure:function(form, action){
        var objResponse=Ext.util.JSON.decode(action.response.responseText);
        var messagesStatus=objResponse.status; var messages=objResponse.message;
        /*document.getElementById('popWdwMainHeaderText').innerHTML=messagesStatus;*/
        document.getElementById('popWdwTxt').innerHTML=messages;
        document.getElementById('popWdw').style.display='block';
      }
    });
  };
  document.getElementById('popOK').onclick = function(event) {
    if (deleteRow>0) {
      gridEditor.getStore().remove(deleteRecord); gridEditor.getView().refresh(); deleteRow=0;
    }
    if (task=='delete') {
      deleteDs.load({
        params:{task: 'delete', id: dataRecord.id },
        callback:function(records, operation, success){
          document.getElementById('popWdw').style.display='none';
          document.getElementById('popCancel').style.display='none';
          task='';
          var textMessages=Ext.util.JSON.decode(operation.callback.caller.caller.arguments[2].responseText);
          var messagesStatus=textMessages.status;
          var messages=textMessages.message;
          /*document.getElementById('popWdwMainHeaderText').innerHTML=messagesStatus;*/
          document.getElementById('popWdwTxt').innerHTML=messages;
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
    let employeeName=dataRecord.first_name + ' ' + dataRecord.last_name;
    document.getElementById('logFormHeaderText').innerHTML = employeeName + '  [' + dataRecord.supplier_name + ']';
    GridHistoryDs.load({params:{id:dataRecord.id}});
  };
	document.getElementById('addSaveEdit').onclick = function(event) {
		for (var v = 0; v < gridAddEditor.store.data.length; v++) {
			ItemData = gridAddEditor.store.data.items[v].data;
			if (ItemData.active) {
				gridEditorDs.add(new addRow({
					item_id        : ItemData.item_id,
					item_name      : ItemData.item_name,
					size           : ItemData.size,
					um_unit        : ItemData.um_unit,
					req_qty        : ItemData.os_qty,
					total_qty        : ItemData.os_qty,
					additional_qty : 0
				}));
			}
		}
    document.getElementById('addWdw').style.display='none';
  };
  /*data store for main grid viewer */
	let dataSupplier = new supplierDs(); dataSupplier.load();
	let dataCurrency = new currencyDs(); dataCurrency.load();
  let poField = [
    {name: 'id', type: 'int'},
    {name: 'supplier_id', type: 'int'},
    {name: 'po_ppn', type: 'boolean'},
    {name: 'price_include_ppn', type: 'boolean'},
    {name: 'currency_id', type: 'int'},
    {name: 'currency', type: 'string'},
    {name: 'currency_init', type: 'string'},
    {name: 'ref_no', type: 'string'},
    {name: 'tx_date', type:'date', dateFormat:'Y-m-d'},
    {name: 'supplier', type: 'string'},
    {name: 'remark', type: 'string'},
    {name: 'eta_date', type:'date', dateFormat:'Y-m-d'},
    {name: 'payment_period', type: 'int'},
    {name: 'exch_rate', type: 'float'},
    {name: 'total_amount', type: 'float'},
    {name: 'cost_name', type: 'string'},
    {name: 'disc', type: 'float'},
    {name: 'disc_po', type: 'float'},
    {name: 'disc_percent', type: 'boolean'},
    {name: 'disc_payment', type: 'boolean'},
    {name: 'allowance', type: 'float'},
    {name: 'allow_percent', type: 'boolean'},
    {name: 'total_payment', type: 'float'}
  ];
  let itemField = [
    {name : 'po_hdr_id', type: 'int'},
    {name : 'po_dtl_id', type: 'int'},
    {name : 'req_id', type: 'int'},
    {name : 'ref_no', type: 'string'},
    {name : 'tx_date', type:'date', dateFormat:'Y-m-d'},
    {name : 'item_id', type: 'int'},
    {name : 'item_name', type: 'string'},
    {name : 'req_qty', type: 'float'},
    {name : 'size', type: 'string'},
    {name : 'um_unit', type: 'string'},
    {name : 'issue_qty', type: 'float'},
    {name : 'os_qty', type: 'float'},
    {name : 'total_qty', type: 'float'},
    {name : 'additional_qty', type: 'float'},
    {name : 'unit_price', type: 'float'},
    {name : 'remark', type: 'string'},
    {name : 'disc', type: 'float'},
    {name : 'disc_item', type: 'float'},
    {name : 'disc_item_percent', type: 'boolean'},
    {name : 'total_amount', type: 'float'},
    {name : 'total_item', type: 'float'},
    {name : 'item_disc', type: 'float'},
    {name : 'item_amount', type: 'float'},
		{name : 'active', type: 'boolen'}
  ];
  mainDs = new Ext.data.Store({
    url: '../php-crud/purchase.orderCRD.php',
    baseParams:{task: 'read', start: 0, limit: PageSize},
    reader: new Ext.data.JsonReader({root: 'data',totalProperty: 'totalRecord',fields :poField})
  });
	mainDs.load();
  /*grid editor for main form */
  let costsField = [
    {name : 'cost_id', type:'integer'},
    {name : 'cost_name', type:'string'},
    {name : 'amount', type:'numeric'},
    {name : 'po_include', type:'boolean'},
    {name : 'po_cost_id', type:'integer'},
    {name : 'po_id', type:'integer'}
  ];
  let gridCostDs = new Ext.data.Store({
    url: '../php-crud/purchase.orderCRD.php',
    baseParams:{task: 'readcosts', costStatus: costStatus },
    reader: new Ext.data.JsonReader({root: 'data', fields : costsField})
  });
  gridCostDs.load({params:{po_id: 0}});
  let poIncludeCheckCol = new Ext.grid.CheckColumn({id:'poIncludeCheckCol',header:'<div> <i class="material-icons md-20">add_to_photos</i></div>',dataIndex:'po_include',width: 40, fixed: true, align: 'center', css: cssGridEditColumn});
  let gridCosts = new stdGridEditor({
    store   : gridCostDs,
		region  : 'east',
		width   : '20%',
    columns : [
      {header: 'Costs', dataIndex: 'cost_name', width: 150, renderer: CellWrap},
			{header: 'Amount', dataIndex: 'amount', width: 120, fixed: true, align: 'right', renderer: format_number, editor: new Ext.form.NumberField(), css: cssGridEditColumn },
      poIncludeCheckCol,
      {dataIndex: 'cost_id', hidden: true},
      {dataIndex: 'po_id', hidden: true},
      {dataIndex: 'po_cost_id', hidden: true},
    ],
    tbar: [ '<div id="gridHeaderText">Costs</div>', {xtype: 'tbfill'} ],
  });
  gridCosts.addClass('x-grid3-hd-row-user-3f474c');

  let gridEditorDs = new Ext.data.Store({
    url: '../php-crud/purchase.orderCRD.php',
    baseParams:{task: 'read_detail'},
    reader: new Ext.data.JsonReader({root: 'data', fields : itemField})
  });
  let addRow = Ext.data.Record.create(itemField);
  function totalQtyPO(val, x, store){
    return Ext.util.Format.number(parseFloat(store.data.req_qty)+parseFloat(store.data.additional_qty),'?0,0.00?');
  }
  let editorDeleter = new Extensive.grid.ItemDeleter();
	let discItemPercent = new Ext.grid.CheckColumn({ id: 'discItemPercent', header: '%', dataIndex: 'disc_item_percent', width: 40, fixed: true, align: 'center', css: cssGridEditColumn });

  let gridEditor = new stdGridEditor({
    store      : gridEditorDs,
    region     : 'center',
    selModel   : editorDeleter,
    columns    : [
      {header: 'Name',dataIndex: 'item_name',width: 200,align: 'left',renderer: CellWrap},
      {header: 'Size', dataIndex: 'size', width: 100, renderer: CellWrap},
      {header: '', dataIndex: 'um_unit', width: 50, fixed: true, renderer: CellWrap},
      {header: 'Req. Qty', dataIndex: 'req_qty', width: 100, align: 'right', renderer: format_number},
      {header: 'Addtn. Qty.', dataIndex: 'additional_qty', width: 100, align: 'right', renderer: format_number, editor: new Ext.form.NumberField(), css: cssGridEditColumn },
      {header: 'P.O. Qty',dataIndex: 'total_qty',width: 100, align: 'right', renderer: format_number},
      {header: 'Unit Price.', dataIndex: 'unit_price', width: 100, align: 'right', renderer: format_number, editor: new Ext.form.NumberField(), css: cssGridEditColumn },
      {header: 'Disc', dataIndex: 'disc_item', width: 100, align: 'right', renderer: format_number, editor: new Ext.form.NumberField(), css: cssGridEditColumn },
      discItemPercent,
			{header: "Remark",dataIndex: 'remark',width: 150, fixed: true, align: 'left',renderer: CellWrap, editor: new Ext.form.TextField({maskRe: /^([a-zA-Z0-9\/\\\(\)\&\+\@\#\-\:\_., "])/,autoCreate:{tag: "input",maxlength : 255, type: "text", size: "255", autocomplete: "off"}}), css: cssGridEditColumn},
      editorDeleter,
      {dataIndex: 'item_id', hidden: true},
      {dataIndex: 'po_hdr_id', hidden: true},
      {dataIndex: 'po_dtl_id', hidden: true}
    ],
    tbar: [
      '<div id="gridHeaderText">Items</div>',
      {xtype: 'tbfill'},
      {text: '&nbsp;&nbsp;&nbsp;Add Req.&nbsp;&nbsp;&nbsp;',
        ctCls: 'field-button green-field-button',
        id: 'btnAddItem',
        handler: function(){
          editReq=0;
          document.getElementById('addWdw').style.display='block';
          document.getElementById('addFormMain').style.display='none';
          document.getElementById('addFormGrid').style.display='block';
          document.getElementById('addFormHeaderText').innerHTML = '&nbsp;&nbsp;Add Item';
          document.getElementById('addFormHeaderSearch').style.display='block';
          $('#addFormHeaderText').css({'width':'32%'});
          ArrayItem=[]; ArrayItem  = dataStore2Array(gridEditorDs).map(itemData => itemData.item_id);
          gridAddEditorDs.load({params: {iteminfo: Array2Json(ArrayItem)}});
          gridItemHeight=(document.getElementById('addForm').clientHeight - document.getElementById('addFormHeader').clientHeight)-2;
          gridAddEditor.setHeight(gridItemHeight); gridAddEditor.getView().refresh();
        }
      }
    ],
    listeners: {
			validateedit: function(e) {
        if (e.field == 'additional_qty') {
					e.record.set('total_qty', $N(e.value, 0) + ($N(e.record.data.req_qty, 0)));
				}
			}
		}
  });
  gridEditor.addClass('x-grid3-gridEditor');

  let frmGridEditor =  new Ext.FormPanel({
    id        : 'frmGridEditor',
    renderTo  : 'editorFormGrid',
    width     : '100%',
    bodyStyle : 'background-color: transparent',
    layout    : 'border',
    border    : false,
    closable  : false,
		items     : [gridEditor, gridCosts]
  });

  /*main form editor*/
  let frmEditor = new stdForm({
    url: '../php-crud/purchase.orderCRD.php',
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({root : 'data',fields: poField}),
    renderTo: 'editorFormMain',
    items: [
      {id: 'usePPN', columnWidth: .1, labelAlign: 'right', labelWidth: 50, items: {xtype:'checkbox', labelSeparator : '&nbsp;&nbsp;-&nbsp;&nbsp;', name:'po_ppn', id:'po_ppn', cls:'fieldCheckClass regular-checkbox', checked: true, fieldLabel: 'PPN'}},
      {columnWidth: 0.2, items: new myTextField({fieldLabel:'Doc. No.', id:'ref_no', name:'ref_no', readOnly: true})},
      {columnWidth: 0.125, items: new myDateField({fieldLabel:'Doc. Date', id:'tx_date', name:'tx_date', readOnly: true})},
      {columnWidth: 0.55, items: new myComboField({fieldLabel:'Supplier', id:'supplier_id', name:'supplier_id', store: dataSupplier, valueField:'supplier_id',displayField:'supplier_name', hiddenName:'supplier_id', allowBlank: false,
        listeners : {
          select: function(combo, record, index){
            if (record.get('overseas')==true) {costStatus='overseas';}
          }
        }})},
      {columnWidth: 0.2, items: new myDateField({fieldLabel:'ETA', id:'eta_date', name:'eta_date',allowBlank: false})},
      {columnWidth: 0.15, items: new myNumberField({fieldLabel:'PayTerm (Days)', id:'payment_period', name:'payment_period', allowBlank:false,decimalPrecision : 0})},
      {columnWidth: 0.15, items: new myComboField({fieldLabel:'Currency', id:'currency_id', name:'currency_id', store: dataCurrency, valueField:'currency_id',displayField:'currency_init', hiddenName:'currency_id', allowBlank: false})},
      {columnWidth: 0.15, items: new myNumberField({fieldLabel:'Exchange Rate', id:'exch_rate', name:'exch_rate', allowBlank:true})},
      {columnWidth: 0.15, items: new myNumberField({fieldLabel:'Disc', id:'disc_po', name:'disc_po', allowBlank:true})},
      {id: 'percentPO', columnWidth: .04, labelWidth: 40, items: {xtype:'checkbox', name:'disc_percent', id:'disc_percent', cls:'fieldCheckClass regular-checkbox', hideLabel: true, boxLabel: '%'}},
      {columnWidth: 0.12, items: new myNumberField({fieldLabel:'Allowance (+/-)', id:'allowance', name:'allowance', allowBlank:true})},
      {id: 'percentAllow', columnWidth: .04, labelWidth: 40, items: {xtype:'checkbox', name:'allow_percent', id:'allow_percent', cls:'fieldCheckClass regular-checkbox', hideLabel: true, boxLabel: '%'}},
      {columnWidth:1, items: new myTextArea({fieldLabel:'Remark', id:'remark', name:'remark',autoCreate : {tag: 'textarea', autocomplete: 'off'}})},
      {items: new myTextField({name: 'id',id: 'id', hidden: true})}
    ]
  });
  frmEditor.on('clientvalidation', function(f, valid) {if (!valid) {saveEditDisable();} else {saveEditEnable();}});

  /* Grid data provider */
  gridAddEditorDs = new Ext.data.Store({
    url: '../php-crud/purchase.orderCRD.php',
    baseParams:{task: 'addreq'},
    reader: new Ext.data.JsonReader({root: 'data', fields : itemField})
  });
  let addCheckColumn = new Ext.grid.CheckColumn({id:'addCheckColumn',header:'<div style="text-align:center;"> <i class="material-icons md-20">playlist_add_check</i></div>',dataIndex:'active',width: 40, fixed: true, align: 'center'});
  let gridAddEditor = new stdGrid({
    store  : gridAddEditorDs,
    renderTo: 'addFormGrid',
    columns:[
      addCheckColumn,
      {header: 'Name', dataIndex: 'item_name', width: 150, renderer: CellWrap},
      {header: 'Size', dataIndex: 'size', width: 200, renderer: CellWrap},
      {header: 'Req. Qty', dataIndex: 'req_qty', width: 120, fixed: true, align: 'right',renderer: format_number},
      {header: 'Issue Qty',dataIndex: 'issue_qty',width: 120, fixed: true, align: 'right',renderer: format_number},
      {header: 'O/Standing',dataIndex: 'os_qty',width: 120, fixed: true, align: 'right',renderer: format_number},
      {header: '', dataIndex: 'um_unit', width: 80, fixed: true, renderer: CellWrap},
      {dataIndex: 'item_id', hidden: true},
    ]
  });
  gridAddEditor.addClass('x-grid3-gridAddItem');
	//Main Grid
  let GridDtlHeight=(maxGridHeight(document.getElementById('divMainGrid')));
  let mainHeight=Math.round(GridDtlHeight-(GridDtlHeight*0.5));
  let detailHeight=(GridDtlHeight-mainHeight+6);let poId=0;
  let poPPNCheck = new Ext.grid.CheckColumn({id: 'po_ppn', header: 'PPN', dataIndex: 'po_ppn', width: 60, fixed: true, align:'center', processEvent: function () { return false; }});
  let mainCheckColumn = new Ext.grid.CheckColumn({id: 'detailCheckColumn', header: '%', align: 'center', dataIndex: 'disc_percent', width: 50, fixed: true, processEvent: function () { return false; }});
  let gridMain = new stdGridPaging({
    store     : mainDs,
    height   : mainHeight,
    renderTo : 'divMainGrid',
    columns  :[
      poPPNCheck,
      {header: 'Doc. No.', dataIndex: 'ref_no', width: 145, fixed: true, renderer: CellWrap},
      //{header: 'Doc. Date', dataIndex: 'tx_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y')},
      {header: 'Supplier', dataIndex: 'supplier', width: 100, renderer: CellWrap},
      {header: 'ETA. Date', dataIndex: 'eta_date', width: 90, fixed: true, renderer: Ext.util.Format.dateRenderer('d M y')},
      {header: 'Exch.<br/>Rate',dataIndex: 'exch_rate',width: 100, align: 'right',renderer: format_number},
      {header: 'Term<br>Days', dataIndex: 'payment_period', width: 60, fixed: true, align: 'right'},
      //inclPPNCheck,
      {header: 'Amount',dataIndex: 'total_amount',width: 150, align: 'right',renderer: format_number},
      {dataIndex: 'currency_init', width: 50, fixed: true, renderer: CellWrap},
      {header: 'Disc', dataIndex: 'disc', width: 80, align: 'right',renderer: CellWrap},
      mainCheckColumn,
      //{header: 'Disc<br/>Payment', dataIndex: 'disc_payment', width: 100, align: 'right',renderer: format_number},
      {header: 'Cost', dataIndex: 'cost_name', width: 200, renderer: CellWrap},
      {header: 'Total<br/>Payment', dataIndex: 'total_payment', width: 150, align: 'right',renderer: format_number},
      {header: 'Remark', dataIndex: 'remark', width: 200, renderer: CellWrap},
      {dataIndex: 'id', hidden: true},
      {dataIndex: 'coststatus', hidden: true},
    ],
    listeners: {
      cellclick : function(grid, rowIndex, columnIndex, e){
        let record=grid.getStore().getAt(rowIndex).data;
        poId=record.id; costStatus=record.coststatus;
        gridDetailDs.load({params:{po_hdr_id: record.id}});
        gridEditorDs.load({params:{po_hdr_id: record.id}});
        gridCostDs.load({params:{po_id: record.id}});
      }
    },
    pageSize:250,
    pageStore:mainDs
  });
  //Detail Grid
  let gridDetailDs = new Ext.data.Store({
    url: '../php-crud/purchase.orderCRD.php',
    baseParams:{task: 'read_detail'},
    reader: new Ext.data.JsonReader({root: 'data', fields : itemField})
  });
  let detailCheckColumn = new Ext.grid.CheckColumn({id: 'detailCheckColumn', header: '%', dataIndex: 'disc_item_percent', width: 50, align:'center', processEvent: function () { return false; }});
  let gridDetail = new stdGrid({
    store  : gridDetailDs,
    height : detailHeight,
    renderTo: 'divDetailGrid',
    columns:[
      {header: 'Name',dataIndex: 'item_name',width: 200,align: 'left',renderer: CellWrap},
      {header: 'Size', dataIndex: 'size', width: 100, renderer: CellWrap},
      {header: '', dataIndex: 'um_unit', width: 50, renderer: CellWrap},
      {header: 'Req.<br/>Qty.', dataIndex: 'req_qty', width: 80, align: 'right',renderer: format_number},
      {header: 'Addtn.<br/>P.O. Qty.', dataIndex: 'additional_qty', width: 80, align: 'right',renderer: format_number},
      {header: 'Total<br/>P.O. Qty',dataIndex: 'total_qty',width: 80, align: 'right',renderer: totalQtyPO},
      {header: 'Unit Price.', dataIndex: 'unit_price', width: 100, align: 'right',renderer: format_number},
      {header: 'Disc', dataIndex: 'disc_item', width: 100, align: 'right',renderer: CellWrap},
      detailCheckColumn,
      {header: 'Disc Amnt', dataIndex: 'item_disc', width: 100, align: 'right',renderer: format_number},
      {header: 'Total Amnt', dataIndex: 'total_item', width: 100, align: 'right',renderer: format_number},
      {header: 'Remark',dataIndex: 'remark',width: 150, renderer: CellWrap}
    ]
  });
  /* History */
  let GridHistoryDs = new historyDs({url: '../php-crud/purchase.orderCRD.php'});
  let GridHistory = new historyGrid({store  : GridHistoryDs, renderTo: 'logFormMain'});

	/* disable unneeded element */
  document.getElementById('editorWdw').style.display='none';
  document.getElementById('addWdw').style.display='none';
  $('#percentPO, #percentAllow, #usePPN').css({'margin-top':'14px', 'margin-bottom':'4px'});
  $('label[for="po_ppn"]').css({'color':'#666', 'border':'none', 'font-size':'.85rem'});
});
