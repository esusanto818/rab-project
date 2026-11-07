let stdGridPaging = Ext.extend(Ext.grid.GridPanel, {
  stripeRows : true,
  width      : '100%',
  loadMask   : {msg:'Loading Data ...'},
  viewConfig : {forceFit : true, autoFill: false, scrollOffset: 10},
  initComponent: function() {
    this.sm = new Ext.grid.RowSelectionModel({
      singleSelect : false,
      listeners    : {rowselect : {fn: function(sm,index,record) {dataRecord=record.data;}}}
    });
    this.colModel = new Ext.grid.ColumnModel({
      columnLines : true,
      defaults    : {menuDisabled: true, resizable: false,sortable: false},
      columns     : this.columns
    });
    this.bbar = new Ext.PagingToolbar({
      pageSize    : this.pageSize,
      store       : this.pageStore,
      displayInfo : true,
      displayMsg  : 'Data {0} - {1} of {2}',
      emptyMsg    : 'No data to display'
    });
    stdGrid.superclass.initComponent.call(this);
  }
});

let stdGrid = Ext.extend(Ext.grid.GridPanel, {
  stripeRows  : true,
  width      : '100%',
  loadMask   : {msg:'Loading Data ...'},
  viewConfig : {forceFit : true, autoFill: false, scrollOffset: 10},
  initComponent: function() {
    this.colModel = new Ext.grid.ColumnModel({
      columnLines: true,
      defaults: {menuDisabled: true, resizable: false, sortable: false},
      columns: this.columns
    });
    /*this.sm = new Ext.grid.RowSelectionModel({
      singleSelect : false,
      listeners    : {rowselect : {fn: function(sm,index,record) {dataRecord=record.data;}}}
    });*/
    stdGrid.superclass.initComponent.call(this);
  }
});
/*
let stdGridEditor = Ext.extend(Ext.grid.EditorGridPanel, {
  stripeRows      : true,
  width           : '100%',
  loadMask        : {msg:'Loading Data ...'},
  enableKeyEvents : true,
  clicksToEdit    : 1,
  viewConfig      : {forceFit : true, autoFill: false, scrollOffset: 10},
  initComponent   : function() {
    this.colModel = new Ext.grid.ColumnModel({
      columnLines : true,
      defaults    : {menuDisabled: true, resizable: false,sortable: false},
      columns     : this.columns
    });
    this.sm = new Ext.grid.RowSelectionModel({
      singleSelect : true,
      listeners    : {
        rowselect : {fn: function(sm,index,record) {dataRecord=record.data;}},
        this.on('beforeedit', function(e) {
        setTimeout(function() {
            if (e.grid && e.grid.activeEditor && e.grid.activeEditor.field) {
                var field = e.grid.activeEditor.field;
                if (field.el) {
                    field.el.setStyle({
                        'height': '24px',
                        'line-height': '24px',
                        'margin': '0px'
                    });
                }
            }
        }, 10);
    
    }
    });
    stdGridEditor.superclass.initComponent.call(this);
  }
})*/
let stdGridEditor = Ext.extend(Ext.grid.EditorGridPanel, {
  stripeRows      : true,
  width           : '100%',
  loadMask        : {msg:'Loading Data ...'},
  enableKeyEvents : true,
  clicksToEdit    : 1,
  viewConfig      : {forceFit : true, autoFill: false, scrollOffset: 10},
  initComponent   : function() {
    this.colModel = new Ext.grid.ColumnModel({
      columnLines : true,
      defaults    : {menuDisabled: true, resizable: false, sortable: false},
      columns     : this.columns
    });

    // PERBAIKAN (v2): sebelumnya cuma cek `if (!this.sm && !this.selModel)`
    // dengan asumsi Ext.grid.GridPanel otomatis meng-alias this.selModel
    // ke this.sm. Ternyata tidak bisa diandalkan di versi ExtJS ini --
    // this.sm harus di-set EKSPLISIT, karena itu yang benar-benar dibaca
    // saat grid melakukan getSelectionModel().init(grid) untuk memicu
    // initEvents() (tempat listener cellclick milik ItemDeleter didaftarkan).
    if (this.selModel && !this.sm) {
      this.sm = this.selModel; // pakai selModel custom dari caller (mis. editorDeleter)
    }
    if (!this.sm) {
      this.sm = new Ext.grid.RowSelectionModel({
        singleSelect : true,
        listeners    : {
          rowselect : {fn: function(sm, index, record) { dataRecord = record.data; }}
        }
      });
    }

    // Auto-fix dimensi input sel saat user melakukan klik/edit
    this.on('beforeedit', function(e) {
      setTimeout(function() {
        if (e.grid && e.grid.activeEditor && e.grid.activeEditor.field) {
          var field = e.grid.activeEditor.field;
          if (field.el) {
            field.el.setStyle({
              'height': '22px',
              'line-height': '22px',
              'margin': '0px',
              'padding': '0px 4px'
            });
          }
        }
      }, 10);
    });

    stdGridEditor.superclass.initComponent.call(this);
  }
});

let readEmployeeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.employee.php',
  baseParams : {task: 'readEmployee', limit:999999},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :readEmployeeField})
});
let supplierDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.supplier.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :supplierField})
});
let supplierLogDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/mobile.data.php',
  baseParams : {task: 'supplierLog'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :supplierLogField})
});
let hphDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.hph.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :hphField})
});
let customerDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.customer.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :customerField})
});
let agentDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.agent.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :agentField})
});
let salesDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.sales.group.php',
  baseParams : {task: 'listsales'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :salesField})
});
let salesGroupDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.sales.group.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :salesGroupField})
});
let currencyDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.currencyCRD.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :currencyField})
});
let colorDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.colorCRD.php',
  baseParams : {task: 'read', limit:999999},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :colorField})
});
let shiptermsDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.shipment.terms.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :shipTermsField})
});
let incotermsDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.incoterms.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :incoTermsField})
});
let paytermsDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.payment.terms.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :payTermsField})
});
let itemGradeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.item.grade.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :itemGradeField})
});
let groupGradeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.item.group.php',
  baseParams : {task: 'read_detail'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :groupGradeField})
});
let woodTypeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.wood.type.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :woodTypeField})
});
let umDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.umCRD.php',
  baseParams : {task: 'read', limit:999999},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :umField})
});
let locationDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.locationCRD.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :locationField})
});
let departmentDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.departmentCRD.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :departmentField})
});
let warehouseDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.warehouseCRD.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :warehouseField})
});
let warehouseLog = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/mobile.data.php',
  baseParams : {task: 'warehouseLog'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :warehouseField})
});
let itemTypeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.item.type.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :itemTypeField})
});
let txTypeDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.transaction.type.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :txTypeField})
});
let onLeaveDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/m.onleave.php',
  baseParams : {task: 'read'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :onLeaveField})
});
let hphSupplier = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/mobile.data.php',
  baseParams : {task: 'hphLog'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :[{name: 'hph_id'}, {name: 'hph'}]})
});

let logStatusDs = Ext.extend (Ext.data.ArrayStore, {
  fields: ['status_id','status_name'],
  data: [[1,'Log End'], [2,'Log Core'], [3,'Log Asal'], [4,'Rotary'], [5,'Waste']]
});
let machineDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/mobile.data.php',
  baseParams : {task: 'machineSection'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields :machineField})
});
let locHWDs = Ext.extend (Ext.data.Store, {
  url        : '../php-crud/mobile.data.php',
  baseParams : {task: 'locWh'},
  reader     : new Ext.data.JsonReader({root: 'data', totalProperty: 'totalCount', fields:[{name: 'id'}, {name: 'warehouse_name'}]})
});
let cmbLocWhGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new locHWDs(),
  valueField: 'id',
  displayField: 'warehouse_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbMachineGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new machineDs(),
  valueField: 'machine_id',
  displayField: 'machine_no',
  forceSelection: true,
  selectOnFocus: true
});
let cmbGradeGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new itemGradeDs(),
  valueField: 'grade_id',
  displayField: 'grade_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbGroupGradeGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new groupGradeDs(),
  valueField: 'grade_id',
  displayField: 'grade_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbHPHGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new hphSupplier(),
  valueField: 'hph_id',
  displayField: 'hph',
  forceSelection: true,
  selectOnFocus: true
});
let cmbWoodTypeGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new woodTypeDs(),
  valueField: 'wood_id',
  displayField: 'wood_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbColorGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new colorDs(),
  valueField: 'color_id',
  displayField: 'color_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbOnLeaveGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new onLeaveDs(),
  valueField: 'leave_id',
  displayField: 'leave_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbLogStatusGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new Ext.data.ArrayStore({
    id: 0,
    fields: ['status_id','status_name'],
    data: [[1,'Log End'], [2,'Log Core'], [3,'Log Asal'], [4,'Rotary'], [5,'Waste']]
  }),
  valueField: 'status_id',
  displayField: 'status_name',
  forceSelection: true,
  selectOnFocus: true
});

let cmbDoorFinishingGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new Ext.data.ArrayStore({
    id: 0,
    fields: ['finishing_id','finishing_name'],
    data: [[1,'Ecoskin'], [2,'Waterbase']]
  }),
  valueField: 'finishing_id',
  displayField: 'finishing_name',
  forceSelection: true,
  selectOnFocus: true
});
let cmbDoorCoreGridEditor = Ext.extend(Ext.form.ComboBox, {
  typeAhead: true,
  triggerAction: 'all',
  lazyRender:true,
  mode: 'local',
  store: new Ext.data.ArrayStore({
    id: 0,
    fields: ['core_id','core_name'],
    data: [[1,'Solid'], [2,'Hollow']]
  }),
  valueField: 'core_id',
  displayField: 'core_name',
  forceSelection: true,
  selectOnFocus: true
});

let historyDs = Ext.extend(Ext.data.GroupingStore, {
  idProperty  : 'date',
  groupField  : ['date'],
  baseParams  : {task: 'HistoryData'},
  reader      : new Ext.data.JsonReader({root: 'history', totalProperty: 'totalCount', fields: historyField}),
  remoteSort  : true,
  remoteGroup : true
});
let historyGrid = Ext.extend(Ext.grid.GridPanel, {
  id         : 'GridHistory',
  width      : '100%',
  loadMask   : {msg:'Loading Data ...'},
  stripeRows : true,
  view       : new Ext.grid.GroupingView({
    forceFit          : true,
    autoFill          : false,
    markDirty         : false,
    groupTextTpl      : '{text} {[values.rs.length > 1 ? "" : ""]}',
    hideGroupedColumn : true,
    showGroupName     : true,
    scrollOffset      : 10
  }),
  initComponent: function() {
    this.colModel = new Ext.grid.ColumnModel({
      columnLines: true,
      defaults: {menuDisabled: true, resizable: false,sortable: false},
      columns:[
        {dataIndex: 'date', groupName:'Date  ', renderer: Ext.util.Format.dateRenderer('d F Y'), hidden: true},
        {header: 'Count',width: 50, dataIndex: 'count'},
        {header: 'Remark', width: 150, dataIndex: 'amend_name', renderer: CellWrap},
        {header: 'Before',width: 200, dataIndex: 'before_amendment', renderer: CellWrap},
        {header: 'After',width: 200, dataIndex: 'after_amendment', renderer: CellWrap},
        {header: 'Update By',width: 75, dataIndex: 'user_name'},
        {header: 'Time',width: 75, dataIndex: 'user_time'},
        {dataIndex: 'id',hidden: true}
      ]
    });
    this.sm = new Ext.grid.RowSelectionModel({
      singleSelect : false,
      listeners    : {rowselect : {fn: function(sm,index,record) {historyRecord=record.data;}}}
    });
    historyGrid.superclass.initComponent.call(this);
    historyGrid.superclass.initComponent.call(this.addClass('x-grid3-hd-row-user-3f474c'));
  }
});

let stdForm = Ext.extend(Ext.FormPanel, {
  region:'center',
  loadMask: {msg:'Loading Data ...'},
  labelWidth: 80,
  labelAlign: 'top',
  monitorValid: true,
  bodyStyle: 'background-color: transparent;',
  layout: 'column',
  scrollOffset      : 10,
  //autoScroll: 'true',
  defaults: {xtype:'container', layout:'form', columnWidth: 0.25, baseCls:'x-plain', autoScroll: 'true'},
  items: this.items,
  initComponent   : function() {
      this.reader = new Ext.data.JsonReader({root: 'data', fields: this.fields});
      stdForm.superclass.initComponent.call(this);
  }
});

let myComboField = Ext.extend(Ext.form.ComboBox, {
  typeAhead       : true
  ,mode           : 'local'
  ,forceSelection : true
  ,triggerAction  : 'all'
  ,selectOnFocus  : true
  ,cls            : 'fieldComboClass'
  ,labelSeparator : ' '
  ,allowBlank     : true
  ,anchor         : '98%'
  ,listeners      : {
    'focus': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});

let myDateField = Ext.extend(Ext.form.DateField, {
  format          : 'd M y'
  ,cls            : 'fieldComboClass'
  ,labelSeparator : ' '
  ,allowBlank     : true
  ,msgTarget      :'side'
  ,anchor         : '98%'
  ,listeners      : {
    'focus': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});

let myTimeField = Ext.extend(Ext.form.TimeField, {
  format          : 'H:i'
  ,cls            : 'fieldClass'
  ,labelSeparator : ' '
  ,minValue       : '00:00'
  ,maxValue       : '23:30'
  ,increment      : 30
  ,allowBlank     : true
  ,msgTarget      : 'side'
  ,anchor  : '98%'
  ,listeners: {
    'focus': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});

let myTextArea = Ext.extend(Ext.form.TextArea, {
  height         : '66',
  maskRe         : /^([a-zA-Z0-9\#\+\-\:\_\.\r\n\/\(\)\&\\\"\, "])/,
  labelSeparator : ' ',
  allowBlank     : true,
  msgTarget      :'side',
  anchor  : '98%',
  listeners      : {
    'focus' : function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur'  : function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});

let myTextField = Ext.extend(Ext.form.TextField, {
  maskRe          : /^([a-zA-Z0-9\/\\\(\)\&\+\@\#\-\:\_., "])/
  ,cls            : 'fieldTextClass'
  ,labelSeparator : ' '
  ,msgTarget      : 'side'
  ,allowBlank     : true
  ,anchor  : '98%'
  ,listeners: {
    'focus': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});
let myNumberField = Ext.extend(Ext.form.NumberField, {
  baseChars        : '0123456789,',
  style            : 'text-align: right',
  cls              : 'fieldNumberClass',
  labelSeparator   : ' ',
  decimalPrecision : 2,
  anchor  : '98%',
  //allowBlank       : false,
  setValue         : function(v){
    v = typeof v == 'number' ? v : String(v).replace(this.decimalSeparator, '.').replace(/,/g, '');
    //v = isNaN(v) ? '' : String(v).replace(".", this.decimalSeparator);
    if (this.decimalPrecision ==1) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.0');
    }
    else if (this.decimalPrecision ==2) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.00');
    }
    else if (this.decimalPrecision ==3) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.000');
    }
    else if (this.decimalPrecision ==4) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.0000');
    }
    else if (this.decimalPrecision ==5) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.00000');
    }
    else if (this.decimalPrecision ==6) {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000.000000');
    }
    else {
      v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), '000,000,000,000,000');
    }
    //v = isNaN(v) ? '' : Ext.util.Format.number(this.fixPrecision(String(v)), "0,000,000.00");
    this.setRawValue(v);
    return Ext.form.NumberField.superclass.setValue.call(this, v);
  },
  fixPrecision: function(value){
    let nan = isNaN(value);
    if (!this.allowDecimals || this.decimalPrecision == -1 || nan || !value) {
      return nan ? '' : value;
    }
    return parseFloat(value).toFixed(this.decimalPrecision);
  },
  validateValue: function(value){
    //if (!Ext.form.NumberField.superclass.validateValue.call(this, value)) {
    //    return false;
    //}
    if (value.length < 1) { // if it's blank and textfield didn't flag it then it's valid
      return true;
    }
    value = String(value).replace(this.decimalSeparator, '.').replace(/,/g, '');
    if (isNaN(value)) {
      this.markInvalid(String.format(this.nanText, value));
      return false;
    }
    let num = this.parseValue(value);
    if (num < this.minValue) {
      this.markInvalid(String.format(this.minText, this.minValue));
      return false;
    }
    if (num > this.maxValue) {
      this.markInvalid(String.format(this.maxText, this.maxValue));
      return false;
    }
    return true;
  },
  parseValue: function(value){
    value = parseFloat(String(value).replace(this.decimalSeparator, '.').replace(/,/g, ''));
    return isNaN(value) ? 0 : value;
  },
  listeners: {
    'focus': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#2d8cf0'; }},
    'blur': function(f) {if (f.getEl().dom.labels[0]) { f.getEl().dom.labels[0].style.color='#009688'; }}
  }
});

// Grid Deleter  --CellSelectionModel
Ext.ns('Extensive.grid');
//Extensive.grid.ItemDeleter = Ext.extend(Ext.grid.RowSelectionModel, {
Extensive.grid.ItemDeleter = Ext.extend(Ext.grid.CellSelectionModel, {
  width: 30,
  sortable: false,
  dataIndex: 0, // this is needed, otherwise there will be an error
  menuDisabled: true,
  fixed: true,
  id: 'deleter',
  singleSelect : true,
  initEvents: function(){
    Extensive.grid.ItemDeleter.superclass.initEvents.call(this);
    this.grid.on('cellclick', function(grid, rowIndex, columnIndex, e){
      if(columnIndex==grid.getColumnModel().getIndexById('deleter')) {
        document.getElementById('popWdw').style.display='block';
        document.getElementById('popCancel').style.display='block';
        /*document.getElementById("popWdwMainHeaderText").innerHTML='Warning';*/
        document.getElementById('popWdwTxt').innerHTML = 'Remove this data?<br/>';
        if (grid.id!=='gridEditor') {deleteRow=2;} else {deleteRow=1;}
        deleteRecord = grid.getStore().getAt(rowIndex);
      }
    });
  },
  renderer: function(v, p, record, rowIndex){return '<div><i class="material-icons md-20">delete_forever</i></div>';}
});
Ext.override(Ext.form.Checkbox, {
  onClick: function () {
    if (this.readOnly === true) this.el.dom.checked = this.originalValue;
    Ext.form.Checkbox.superclass.setValue.apply(this, arguments);
  }
});
Ext.override(Ext.data.Store,{
    addField: function(field){
      field = new Ext.data.Field(field);
      this.recordType.prototype.fields.replace(field);
      if(typeof field.defaultValue != 'undefined'){
        this.each(function(r){
          if(typeof r.data[field.name] == 'undefined'){
            r.data[field.name] = field.defaultValue;
          }
        });
      }
    },
    removeField: function(name){
      this.recordType.prototype.fields.removeKey(name);
      this.each(function(r){
        delete r.data[name];
        if(r.modified){
          delete r.modified[name];
        }
      });
    }
  });
Ext.override(Ext.grid.ColumnModel,{
  addColumn: function(column, colIndex){
    if(typeof column == 'string'){
      column = {header: column, dataIndex: column};
    }
    let config = this.config;
    this.config = [];
    if(typeof colIndex == 'number'){
      config.splice(colIndex, 0, column);
    }else{
      colIndex = config.push(column);
    }
    this.setConfig(config);
    return colIndex;
  },
  removeColumn: function(colIndex){
    let config = this.config;
    this.config = [config[colIndex]];
    config.splice(colIndex, 1);
    this.setConfig(config);
  }
});
Ext.override(Ext.grid.GridPanel,{
  addColumn: function(field, column, colIndex){
  if(!column){
    if(field.dataIndex){
      column = field;
      field = field.dataIndex;
    } else{
      column = field.name || field;
    }
  }
  this.store.addField(field);
  return this.colModel.addColumn(column, colIndex);
  },
  removeColumn: function(name, colIndex){
    this.store.removeField(name);
    if(typeof colIndex != 'number'){
      colIndex = this.colModel.findColumnIndex(name);
    }
    if(colIndex >= 0){
      this.colModel.removeColumn(colIndex);
    }
  }
});
/*
Start of  *** Ext.ux.MultiGrouping
*/
Ext.ux.MultiGroupingStore = Ext.extend(Ext.data.GroupingStore,{
    sortInfo: [],
    sort : function(field, dir){
        let f = [];
        if(Ext.isArray(field)){
            for(i=0,len=field.length; i<len; ++i){
              f.push(this.fields.get(field[i]));
            }
        }
        else{
           f.push(this.fields.get(field));
        }
        if(f.length < 1){
            return false;
        }
        if(!dir){
            if(this.sortInfo && this.sortInfo.length > 0 && this.sortInfo[0].field == f[0].name){ // toggle sort dir
                dir = (this.sortToggle[f[0].name] || 'ASC').toggle('ASC', 'DESC');
            }else{
                dir = f[0].sortDir;
            }
        }
        let st = (this.sortToggle) ? this.sortToggle[f[0].name] : null;
        let si = (this.sortInfo) ? this.sortInfo : null;
        this.sortToggle[f[0].name] = dir;
        this.sortInfo = [];
        for(i=0,len=f.length; i<len; ++i){
            this.sortInfo.push({field: f[i].name, direction: dir});
        }
        if(!this.remoteSort){
            this.applySort();
            this.fireEvent('datachanged', this);
        }else{
            if (!this.load(this.lastOptions)) {
                if (st) {
                    this.sortToggle[f[0].name] = st;
                }
                if (si) {
                    this.sortInfo = si;
                }
            }
        }
    },
    setDefaultSort : function(field, dir){
        dir = dir ? dir.toUpperCase() : 'ASC';
        this.sortInfo=[];
        if(!Ext.isArray(field))
            this.sortInfo.push({field: field, direction: dir});
        else{
          for(i=0,len=field.length; i<len; ++i){
            this.sortInfo.push({field: field[i].field, direction: dir});
            this.sortToggle[field[i]] = dir;
          }
        }
    },
    constructor: function(config){
        Ext.ux.MultiGroupingStore.superclass.constructor.apply(this,arguments);
    },
    groupBy : function(field, forceRegroup){
        if(!forceRegroup && this.groupField == field){
            return; // already grouped by this field
        }

        this.groupField = field;

        if(this.remoteGroup){
            if(!this.baseParams){
                this.baseParams = {};
            }
            this.baseParams['groupBy'] = field;
        }
        if(this.groupOnSort){
            this.sort(field);
            return;
        }
        if(this.remoteGroup){
            this.reload();
        }else{
            let si = this.sortInfo || [];
            if(si.field != field){
                this.applySort();
            }else{
                this.sortData(field);
            }
            this.fireEvent('datachanged', this);
        }
    },

    applySort : function(){
        let si = this.sortInfo;

        if(si && si.length > 0 && !this.remoteSort){
            this.sortData(si, si[0].direction);
        }

        if(!this.groupOnSort && !this.remoteGroup){
            let gs = this.getGroupState();
            if(gs && gs != this.sortInfo){
                this.sortData(this.groupField);
            }
        }
    },

    getGroupState : function(){
        return this.groupOnSort && this.groupField !== false ?
               (this.sortInfo ? this.sortInfo : undefined) : this.groupField;
    },

    sortData : function(flist, direction){
        direction = direction || 'ASC';

        let st=[];
        let o;
        for(i=0,len=flist.length; i < len; ++i){
            o = flist[i];
            st.push(this.fields.get(o.field ? o.field : o).sortType);
        }

        let fn = function(r1, r2){
            let v1=[];
            let v2=[];
            let len = flist.length;
            let o;
            let name;
            for(i=0; i<len; ++i){
              o = flist[i];
              name= o.field ? o.field : o;

              v1.push(st[i](r1.data[name]));
              v2.push(st[i](r2.data[name]));
            }

            let result;
            for(i=0; i<len; ++i){
                result = v1[i] > v2[i] ? 1 : (v1[i] < v2[i] ? -1 : 0);
                if(result != 0)
                    return result;
            }

            return result; //if it gets here, that means all fields are equal
        };

        this.data.sort(direction, fn);
        if(this.snapshot && this.snapshot != this.data){
            this.snapshot.sort(direction, fn);
        }
    }

});

Ext.ux.MultiGroupingView = Ext.extend(Ext.grid.GroupingView,{
    displayEmptyFields: false,
    displayFieldSeperator: ', ',

    renderRows : function(){
        let groupField = this.getGroupField();
        let eg = !!groupField;
        // if they turned off grouping and the last grouped field is hidden
        if(this.hideGroupedColumn) {
            let colIndexes = [];
            for(i=0,len=groupField.length; i<len; ++i){
                colIndexes.push(this.cm.findColumnIndex(groupField[i]));
            }
            if(!eg && this.lastGroupField !== undefined) {
                this.mainBody.update('');
                for(i=0,len=this.lastGroupField.length; i<len; ++i){
                    this.cm.setHidden(this.cm.findColumnIndex(this.lastGroupField[i]), false);
                }
                delete this.lastGroupField;
            }else if (eg && colIndexes.length > 0 && this.lastGroupField === undefined) {
                this.lastGroupField = groupField;
                for(i=0,len=colIndexes.length; i<len; ++i){
                    this.cm.setHidden(colIndexes[i], true);
                }
            }else if (eg && this.lastGroupField !== undefined && groupField !== this.lastGroupField) {
                this.mainBody.update('');
                for(i=0,len=this.lastGroupField.length; i<len; ++i){
                    this.cm.setHidden(this.cm.findColumnIndex(this.lastGroupField[i]), false);
                }
                this.lastGroupField = groupField;
                for(i=0,len=colIndexes.length; i<len; ++i){
                    this.cm.setHidden(colIndexes[i], true);
                }
            }
        }
        return Ext.ux.MultiGroupingView.superclass.renderRows.apply(this, arguments);
    },

 doRender : function(cs, rs, ds, startRow, colCount, stripe){
        if(rs.length < 1){
            return '';
        }
        let groupField = this.getGroupField();
        this.enableGrouping = !!groupField;

        if(!this.enableGrouping || this.isUpdating){
            return Ext.grid.GroupingView.superclass.doRender.apply(this, arguments);
        }

        let gstyle = 'width:'+this.getTotalWidth()+';';

        let gidPrefix = this.grid.getGridEl().id;

            let groups = [], curGroup, i, len, gid;
            for(i = 0, len = rs.length; i < len; i++)
            {
                let rowIndex = startRow + i;
                let r = rs[i];

                    let gvalue=[];
                    let fieldName;
                    let grpDisplayValues = [];
                    let v;
                    for(j=0,gfLen=groupField.length; j<gfLen; ++j){
                        fieldName = groupField[j];
                        v = r.data[fieldName];
                        if(v){
                          gvalue.push(v);
                          grpDisplayValues.push(fieldName.toUpperCase() + ' : ' + v);
                        }
                        else if(this.displayEmptyFields){
                          grpDisplayValues.push(fieldName.toUpperCase() + ' : ');
                        }
                    }

                    //g = this.getGroup(gvalue, r, groupRenderer, rowIndex, colIndexes[index], ds);
                    if(gvalue.length < 1 && this.emptyGroupText)
                        g = this.emptyGroupText;
                    else
                        g = grpDisplayValues.join(this.displayFieldSeperator);

                    if(!curGroup || curGroup.group != g)
                    {
                        gid = gidPrefix + '-gp-' + groupField + '-' + Ext.util.Format.htmlEncode(g);
                           // if state is defined use it, however state is in terms of expanded
                        // so negate it, otherwise use the default.
                        let isCollapsed  = typeof this.state[gid] !== 'undefined' ? !this.state[gid] : this.startCollapsed;
                        let gcls = isCollapsed ? 'x-grid-group-collapsed' : '';
                        curGroup = {
                            group: g,
                            gvalue: gvalue,
                            text: g,
                            groupId: gid,
                            startRow: rowIndex,
                            rs: [r],
                            cls: gcls,
                            style: gstyle
                        };
                        groups.push(curGroup);
                    }
                    else
                    {
                        curGroup.rs.push(r);
                    }
                    r._groupId = gid;
            }

        let buf = [];
        for(i = 0, len = groups.length; i < len; i++){
            let g = groups[i];
            this.doGroupStart(buf, g, cs, ds, colCount);
            buf[buf.length] = Ext.grid.GroupingView.superclass.doRender.call(
                    this, cs, g.rs, ds, g.startRow, colCount, stripe);

            this.doGroupEnd(buf, g, cs, ds, colCount);
        }
        return buf.join('');
    }

});

/*
End of  *** Ext.ux.MultiGrouping
*/
/*
VType
*/
Ext.apply(Ext.form.VTypes, {
  daterange : function(val, field) {
        let date = field.parseDate(val);

        if(!date){
            return false;
        }
        if (field.startDateField) {
            let start = Ext.getCmp(field.startDateField);
            if (!start.maxValue || (date.getTime() != start.maxValue.getTime())) {
                start.setMaxValue(date);
                start.validate();
            }
        }
        else if (field.endDateField) {
            let end = Ext.getCmp(field.endDateField);
            if (!end.minValue || (date.getTime() != end.minValue.getTime())) {
                end.setMinValue(date);
                end.validate();
            }
        }
        /*
         * Always return true since we're only using this vtype to set the
         * min/max allowed values (these are tested for after the vtype test)
         */
        return true;
    },
  daterangeText: 'Start date must be less than end date',
});
Ext.apply(Ext.form.VTypes, {
  password: function(val, field) {
      if (field.initialPassField) {
          let pwd = Ext.getCmp(field.initialPassField);
          return (val == pwd.getValue());
          return true;
      }
  },
  passwordText: 'Passwords do not match'
});