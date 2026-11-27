Ext.onReady(function() {
  Ext.QuickTips.init();

  // Scoped state variables
  let task = '';
  let deleteRow = 0;
  let deleteRecord = null;
  let gridEditorCheck = 0;

  // DOM Helper
  const setDisplay = (id, displayStyle) => {
    const el = document.getElementById(id);
    if (el) el.style.display = displayStyle;
  };

  const setHtml = (id, html) => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
  };

  setDisplay('addWdw', 'block');

  // Event Handlers
  document.getElementById('addData').onclick = function() {
    const saveBtn = document.getElementById("saveEdit");
    saveBtn.style.pointerEvents = 'auto';
    saveBtn.style.backgroundColor = '#1D6788';
    saveBtn.style.color = '#fff';
    
    if (formAccess==2 || formAccess==3 || formAccess==4){
      task = 'create';
      gridEditorDs.load({ params: { id: 0 } });
      setDisplay('editorWdw', 'block');
      setHtml("editorFormHeaderText", "&nbsp;&nbsp;New Supplier");
      frmEditor.getForm().reset();
      Ext.getCmp('init').focus('', 10);
    } else {
      task = '';
      setHtml("popWdwTxt", 'not enough permission to access this window');
      setDisplay('popWdw', 'block');
    }
  };

  document.getElementById('editData').onclick = function() {
    if ( formAccess==3 || formAccess==4){
      task = 'update';
      frmEditor.getForm().reset();
      Ext.getCmp('frmEditor').getForm().load({
        url: '../php-crud/m.supplierCRD.php',
        params: { id: dataRecord.id, task: 'readedit' },
        success: function(form, action) {
          let dt = action.result.data;
        }
      });
      setDisplay('editorWdw', 'block');
      setHtml("editorFormHeaderText", "&nbsp;&nbsp;Edit Supplier");
    } else {
      task = '';
      setHtml("popWdwTxt", 'not enough permission to access this window');
      setDisplay('popWdw', 'block');
    }
  };

  document.getElementById('deleteData').onclick = function() {
    if (formAccess === 4) {
      task = 'delete';
      let supplierName = dataRecord.name;
      setDisplay('popWdw', 'block');
      setDisplay('popCancel', 'block');
      setHtml("popWdwTxt", `delete '${supplierName}' ?`);
    } else {
      task = '';
      setHtml("popWdwTxt", 'not enough permission to delete this data');
      setDisplay('popWdw', 'block');
    }
  };

  document.getElementById('printData').onclick = function() {
    window.open(
      "../pdf/m.supplier.php", 
      'sub', 
      'toolbar=no,location=no,status=no,directories=no,menubar=no'
    );
  };

  document.getElementById('saveEdit').onclick = function() {
    let arrContact = new Ext.data.ArrayStore({ fields: contactField });
    let records = gridEditorDs.getRange();

    records.forEach(rec => {
      arrContact.add(new addRow({
        cont_id         : rec.get('cont_id'),
        name            : rec.get('name'),
        position        : rec.get('position'),
        cont_phone_id   : rec.get('cont_phone_id'),
        phone           : rec.get('phone'),
        cont_mobile_id  : rec.get('cont_mobile_id'),
        mobile          : rec.get('mobile'),
        cont_email_id   : rec.get('cont_email_id'),
        email           : rec.get('email'),
        cont_address_id : rec.get('cont_address_id'),
        address         : rec.get('address'),
        remark          : rec.get('remark')
      }));
    });

    frmEditor.getForm().submit({
      method: 'POST',
      params: { 
        task: task, 
        logActive: Ext.getCmp('logActive').getValue(), 
        arrContact: DsArray2Json(arrContact)
      },
      waitTitle: 'Connecting',
      waitMsg: 'Sending data...',
      success: function(form, action) {
        let objResponse = Ext.util.JSON.decode(action.response.responseText);
        setHtml("popWdwTxt", objResponse.message);
        setDisplay('popWdw', 'block');

        mainDs.load({ params: { task: 'read' } });
        frmEditor.getForm().reset();
        setDisplay('editorWdw', 'none');
      },
      failure: function(form, action) {
        let objResponse = Ext.util.JSON.decode(action.response.responseText);
        setHtml("popWdwTxt", objResponse ? objResponse.message : 'Save failed');
        setDisplay('popWdw', 'block');
      }
    });
  };

  document.getElementById('popOK').onclick = function() {
    if (deleteRow > 0 && deleteRecord) {
      gridEditor.getStore().remove(deleteRecord);
      gridEditor.getView().refresh();
      deleteRow = 0;
      deleteRecord = null;
    }

    if (task === 'delete') {
      deleteDs.load({
        params: { task: "delete", id: dataRecord.id },
        callback: function(records, operation, success) {
          setDisplay('popWdw', 'none');
          setDisplay('popCancel', 'none');
          
          let responseText = operation.response ? operation.response.responseText : '{}';
          let textMessages = Ext.util.JSON.decode(responseText);
          
          setHtml("popWdwTxt", textMessages.message || 'Action completed');
          setDisplay('popWdw', 'block');
          
          mainDs.load({
            callback: function() {
              gridMain.getView().refresh(true);
            }
          });
        }
      });
    } else {
      setDisplay('popWdw', 'none');
      setDisplay('popCancel', 'none');
    }
    
    task = '';
  };

  document.getElementById('logData').onclick = function() {
    setDisplay('logWdw', 'block');
    setHtml("logFormHeaderText", dataRecord.name);
    GridHistoryDs.load({ params: { id: dataRecord.id } });
  };

  let myMask = new Ext.LoadMask(Ext.getBody(), { msg: "Please wait..." });
  let deleteDs = new Ext.data.Store({ url: '../php-crud/m.supplierCRD.php' });

  let supplierField = [
    { name: 'id' }, { name: 'address_id' }, { name: 'office_email_id' },
    { name: 'office_mobile_id' }, { name: 'office_phone_id' }, { name: 'office_fax_id' },
    { name: 'init' }, { name: 'name' }, { name: 'address' }, { name: 'contact_name' },
    { name: 'remark' }, { name: 'office_email' }, { name: 'office_mobile' },
    { name: 'office_phone' }, { name: 'office_fax' },
    { name: 'log', type: 'bool' }, { name: 'overseas', type: 'bool' }
  ];

  let mainDs = new Ext.data.Store({
    url: '../php-crud/m.supplierCRD.php',
    idProperty: 'id',
    baseParams: { task: 'read' },
    reader: new Ext.data.JsonReader({
      root: 'data',
      totalProperty: 'totalRecord',
      fields: supplierField
    })
  });
  mainDs.load();

  let contactField = [
    { name: 'cont_id' }, { name: 'name' }, { name: 'position' },
    { name: 'remark' }, { name: 'cont_address_id' }, { name: 'address' },
    { name: 'cont_email_id' }, { name: 'email' }, { name: 'cont_mobile_id' },
    { name: 'mobile' }, { name: 'cont_phone_id' }, { name: 'phone' }, { name: 'contact' }
  ];

  let gridEditorDs = new Ext.data.Store({
    url: '../php-crud/m.supplierCRD.php',
    baseParams: { task: 'readcontact' },
    reader: new Ext.data.JsonReader({
      root: 'data',
      totalProperty: 'totalRecord',
      fields: contactField
    })
  });

  let addRow = Ext.data.Record.create(contactField);
  let editorDeleter = new Extensive.grid.ItemDeleter();

  let gridEditor = new stdGridEditor({
    store: gridEditorDs,
    renderTo: 'editorFormGrid',
    viewConfig: { forceFit: true, scrollOffset: 0 },
    height: 200,
    selModel: editorDeleter,
    columns: [
      { header: "Name", dataIndex: 'name', width: 80, renderer: CellWrap },
      { header: "Position", dataIndex: 'position', width: 80, align: 'left', renderer: CellWrap },
      { header: "Address", dataIndex: 'address', width: 200, renderer: CellWrap },
      { header: "Contact", dataIndex: 'contact', width: 100, renderer: CellWrap },
      { header: "Remark", dataIndex: 'remark', width: 100, renderer: CellWrap },
      editorDeleter,
      { dataIndex: 'phone', hidden: true }, { dataIndex: 'mobile', hidden: true },
      { dataIndex: 'email', hidden: true }, { dataIndex: 'cont_id', hidden: true },
      { dataIndex: 'cont_address_id', hidden: true }, { dataIndex: 'cont_email_id', hidden: true },
      { dataIndex: 'cont_mobile_id', hidden: true }, { dataIndex: 'cont_phone_id', hidden: true }
    ],
    listeners: {
      cellclick: function(grid, rowIndex, columnIndex, e) {
        let record = grid.getStore().getAt(rowIndex);
        frmAdd.getForm().reset();
        
        let addrVal = record.get('address');
        let formattedAddr = addrVal ? addrVal.replace(/<br\s*\/?>/gi, '\n') : '';

        Ext.getCmp('cont_addr').setValue(formattedAddr);
        Ext.getCmp('cont_id').setValue(record.get('cont_id'));
        Ext.getCmp('cont_address_id').setValue(record.get('cont_address_id'));
        Ext.getCmp('cont_email_id').setValue(record.get('cont_email_id'));
        Ext.getCmp('cont_mobile_id').setValue(record.get('cont_mobile_id'));
        Ext.getCmp('cont_phone_id').setValue(record.get('cont_phone_id'));
        Ext.getCmp('cont_name').setValue(record.get('name'));
        Ext.getCmp('cont_position').setValue(record.get('position'));
        Ext.getCmp('cont_phone').setValue(record.get('phone'));
        Ext.getCmp('cont_mobile').setValue(record.get('mobile'));
        Ext.getCmp('cont_email').setValue(record.get('email'));
        Ext.getCmp('cont_remark').setValue(record.get('remark'));
      }
    }
  });
  gridEditor.addClass('x-grid3-gridEditor');

  let frmEditor = new stdForm({
    url: '../php-crud/m.supplierCRD.php',
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({ root: 'data', fields: supplierField }),
    renderTo: 'editorFormMain',
    items: [
      { labelAlign: 'right', labelWidth: 125, items: { xtype: 'checkbox', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;', name: 'log', id: 'logActive', cls: 'fieldCheckClass regular-checkbox', checked: true, fieldLabel: 'Supplier Log', anchor: '90%' } },
      { labelAlign: 'right', labelWidth: 125, items: { xtype: 'checkbox', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;', name: 'overseas', id: 'overseasActive', cls: 'fieldCheckClass regular-checkbox', checked: true, fieldLabel: 'Overseas', anchor: '90%' } },
      { columnWidth: .5, items: FieldSpaceTopLabel },
      { items: new myTextField({ fieldLabel: 'Init', id: 'init', name: 'init', allowBlank: false, maskRe: /^([a-zA-Z0-9])/, autoCreate: { tag: "input", maxlength: 50, type: "text", size: "50", autocomplete: "off" }, listeners: { change: function(field) { field.setValue(field.getValue().toUpperCase()); } } }) },
      { columnWidth: .75, items: new myTextField({ fieldLabel: 'Name', id: 'name', name: 'name', allowBlank: false, autoCreate: { tag: "input", maxlength: 200, type: "text", size: "200", autocomplete: "off" }, listeners: { change: function(field) { field.setValue(field.getValue().toUpperCase()); } } }) },
      { items: new myTextField({ fieldLabel: 'Phone', id: 'office_phone', name: 'office_phone', allowBlank: true, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Mobile', id: 'office_mobile', name: 'office_mobile', allowBlank: true, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Fax', id: 'office_fax', name: 'office_fax', allowBlank: true, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Email', id: 'office_email', name: 'office_email', allowBlank: true, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { columnWidth: .5, items: new myTextArea({ fieldLabel: 'Address', id: 'address', name: 'address', allowBlank: false, autoCreate: { tag: "textarea", autocomplete: "off" } }) },
      { columnWidth: .5, items: new myTextArea({ fieldLabel: 'Remark', id: 'remark', name: 'remark', allowBlank: true, autoCreate: { tag: "textarea", autocomplete: "off" } }) },
      { columnWidth: .8, xtype: 'label', text: 'Contact Person', cls: 'x-form-item my-label-styleBl' },
      {
        columnWidth: .1, xtype: 'button', text: 'Add', cls: 'btn-dark', id: 'btnAddContact',
        listeners: {
          click: function() {
            gridEditorCheck = 0;
            frmAdd.getForm().reset();
            setDisplay('addWdw', 'block');
            setHtml("addFormHeaderText", "&nbsp;&nbsp;Add Contact");
          }
        }
      },
      {
        columnWidth: .1, xtype: 'button', text: 'Edit', cls: 'btn-dark', id: 'btnEditContact',
        listeners: {
          click: function() {
            gridEditorCheck = 1;
            setDisplay('addWdw', 'block');
            setHtml("addFormHeaderText", "&nbsp;&nbsp;Edit Contact");
          }
        }
      },
      { items: new myTextField({ name: 'id', id: 'id', hidden: true }) },
      { items: new myTextField({ name: 'address_id', id: 'address_id', hidden: true }) },
      { items: new myTextField({ name: 'office_email_id', id: 'office_email_id', hidden: true }) },
      { items: new myTextField({ name: 'office_mobile_id', id: 'office_mobile_id', hidden: true }) },
      { items: new myTextField({ name: 'office_phone_id', id: 'office_phone_id', hidden: true }) },
      { items: new myTextField({ name: 'office_fax_id', id: 'office_fax_id', hidden: true }) }
    ]
  });

  frmEditor.on('clientvalidation', function(f, valid) {
    if (!valid) { saveEditDisable(); } else { saveEditEnable(); }
  });
document.getElementById('addSaveEdit').onclick = function(event) {
  let phone = Ext.getCmp('cont_phone').getValue();
  let mobile = Ext.getCmp('cont_mobile').getValue();
  let email = Ext.getCmp('cont_email').getValue();

  let cont_contact = (phone == null || phone === '') ? '' : ' Phone : ' + phone;
  cont_contact += (mobile == null || mobile === '') ? '' : '<br> Mobile : ' + mobile;
  cont_contact += (email == null || email === '') ? '' : ' <br> Email : ' + email;

  let formattedAddress = (Ext.getCmp('cont_addr').getValue() || '').replace(/\n/gi, '<br>');

  if (gridEditorCheck === 1) {
    let sm = gridEditor.getSelectionModel();
    let rec = null;

    // Safely retrieve selected record regardless of SelectionModel type
    if (typeof sm.getSelected === 'function') {
      rec = sm.getSelected();
    } else if (typeof sm.getSelections === 'function') {
      rec = sm.getSelections()[0];
    } else if (typeof sm.getSelectedCell === 'function') {
      let cell = sm.getSelectedCell();
      if (cell) {
        rec = gridEditor.getStore().getAt(cell[0]);
      }
    }

    if (rec) {
      rec.set('name', Ext.getCmp('cont_name').getValue());
      rec.set('position', Ext.getCmp('cont_position').getValue());
      rec.set('address', formattedAddress);
      rec.set('contact', cont_contact);
      rec.set('phone', phone);
      rec.set('mobile', mobile);
      rec.set('email', email);
      rec.set('remark', Ext.getCmp('cont_remark').getValue());
      gridEditor.getView().refresh();
    } else {
      Ext.Msg.alert('Warning', 'Please select a row in the contact grid first.');
      return;
    }
  } else {
    gridEditor.store.add(new addRow({
      cont_id          : Ext.getCmp('cont_id').getValue(),
      cont_address_id : Ext.getCmp('cont_address_id').getValue(),
      cont_email_id   : Ext.getCmp('cont_email_id').getValue(),
      cont_mobile_id  : Ext.getCmp('cont_mobile_id').getValue(),
      cont_phone_id   : Ext.getCmp('cont_phone_id').getValue(),
      name            : Ext.getCmp('cont_name').getValue(),
      position        : Ext.getCmp('cont_position').getValue(),
      address         : formattedAddress,
      contact         : cont_contact,
      phone           : phone,
      mobile          : mobile,
      email           : email,
      remark          : Ext.getCmp('cont_remark').getValue()
    }));
  }
  document.getElementById('addWdw').style.display = 'none';
};

  let frmAdd = new stdForm({
    id: 'frmAdd',
    renderTo: 'addFormMain',
    items: [
      { columnWidth: .5, items: new myTextField({ fieldLabel: 'Name', id: 'cont_name', name: 'cont_name', allowBlank: false, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Position', id: 'cont_position', name: 'cont_position', autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: FieldSpaceTopLabel },
      { items: new myTextField({ fieldLabel: 'Phone', id: 'cont_phone', name: 'cont_phone', maskRe: /^([0-9\- \(\)])/, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Mobile', id: 'cont_mobile', name: 'cont_mobile', maskRe: /^([0-9\- \(\)])/, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { items: new myTextField({ fieldLabel: 'Email', id: 'cont_email', name: 'cont_email', maskRe: /^([a-zA-Z0-9\.@])/, autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" } }) },
      { columnWidth: .5, items: new myTextArea({ fieldLabel: 'Address', id: 'cont_addr', name: 'cont_addr', allowBlank: true, autoCreate: { tag: "textarea", autocomplete: "off" } }) },
      { columnWidth: .5, items: new myTextArea({ fieldLabel: 'Remark', id: 'cont_remark', name: 'cont_remark', allowBlank: true, autoCreate: { tag: "textarea", autocomplete: "off" } }) },
      { columnWidth: 1, items: FieldSpace },
      { items: new myTextField({ name: 'cont_id', id: 'cont_id', hidden: true }) },
      { items: new myTextField({ name: 'cont_address_id', id: 'cont_address_id', hidden: true }) },
      { items: new myTextField({ name: 'cont_email_id', id: 'cont_email_id', hidden: true }) },
      { items: new myTextField({ name: 'cont_mobile_id', id: 'cont_mobile_id', hidden: true }) },
      { items: new myTextField({ name: 'cont_phone_id', id: 'cont_phone_id', hidden: true }) }
    ]
  });

  frmAdd.on('clientvalidation', function(f, valid) {
    if (!valid) { addSaveEditDisable(); } else { addSaveEditEnable(); }
  });

  let logCheckColumn = new Ext.grid.CheckColumn({ id: 'logCheckColumn', header: 'Log', dataIndex: 'log', width: 50, fixed: true, align: 'center', processEvent: function() { return false; } });
  let overseasCheckColumn = new Ext.grid.CheckColumn({ id: 'overseasCheckColumn', header: 'Overseas', dataIndex: 'overseas', width: 90, fixed: true, align: 'center', processEvent: function() { return false; } });
  
  let mainHeight = maxGridHeight(document.getElementById('divMainGrid'));
  let gridMain = new stdGridPaging({
    store: mainDs,
    renderTo: 'divMainGrid',
    height: mainHeight,
    columns: [
      logCheckColumn,
      overseasCheckColumn,
      { header: "Init", dataIndex: 'init', width: 80, fixed: true, renderer: CellWrap },
      { header: "Name", dataIndex: 'name', width: 150, renderer: CellWrap },
      { header: "Address", dataIndex: 'address', width: 200, renderer: CellWrap },
      { header: "Contact", dataIndex: 'contact_name', width: 100, renderer: CellWrap },
      { header: "Remark", dataIndex: 'remark', width: 200, renderer: CellWrap },
      { dataIndex: 'id', hidden: true }
    ],
    listeners: {
      cellclick: function(grid, rowIndex, columnIndex, e) {
        let record = grid.getStore().getAt(rowIndex);
        gridEditorDs.load({ params: { id: record.get('id') } });
      }
    },
    pageSize: 250,
    pageStore: mainDs
  });
  
  gridMain.addClass('x-grid3-hd-row-user-dTeal');
  
  let GridHistoryDs = new historyDs({ url: '../php-crud/m.form.name.php' });
  let GridHistory = new historyGrid({ store: GridHistoryDs, renderTo: 'logFormMain' });
  
  setDisplay('editorWdw', 'none');
  setDisplay('addWdw', 'none');
  
  $('label[for="logActive"], label[for="overseasActive"]').css({ 
    'color': '#666', 
    'border': 'none', 
    'font-size': '.85rem' 
  });
});