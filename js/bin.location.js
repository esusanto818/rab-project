/*
  Document      : bin.location.js
  Description   : Master Bin Location (Lokasi Rak per Gudang) - WMS
                  Cascading Store & Filter Bin Location base on Warehouse
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/bin.locationCRD.php
=======
  Backend       : /rab-project/live/php-crud/bin.locationCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Bin <em>Location</em>";
    }

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        if (typeof window.formAccess !== 'undefined' && window.formAccess !== null) {
            return parseInt(window.formAccess, 10);
        }
        if (typeof formAccess !== 'undefined' && formAccess !== null) {
            return parseInt(formAccess, 10);
        }
        return 0;
    }

    // Container Wrapper Setup
    var gridContainer = document.getElementById('divMainGrid');
    var mainWrapper = document.getElementById('binMainWrapper');
    if (!mainWrapper && gridContainer && gridContainer.parentNode) {
        mainWrapper = document.createElement('div');
        mainWrapper.id = 'binMainWrapper';
        mainWrapper.style.cssText = 'display:flex; flex-direction:column; gap:15px; width:100%;';
        gridContainer.parentNode.insertBefore(mainWrapper, gridContainer);
        mainWrapper.appendChild(gridContainer);
    }

    var binDtlDiv = document.createElement('div');
    binDtlDiv.id = 'binDtlContainer';
    binDtlDiv.style.cssText = 'background:rgba(255,255,255,0.06); color:#fff; border-radius:4px; border:1px solid rgba(255,255,255,0.18); font-size:13px; max-height:260px; overflow-y:auto; display:none; position:relative; flex-shrink:0;';
    if (mainWrapper) { mainWrapper.appendChild(binDtlDiv); }

    // ------------------------------------------------------------
    // 1. STORES
    // ------------------------------------------------------------
    var binField = [
        { name: 'id' }, { name: 'warehouse_id' }, { name: 'warehouse_name' },
        { name: 'code' }, { name: 'name' }, { name: 'zone' }, { name: 'rack' }, { name: 'level' }, { name: 'location_name' },
        { name: 'active', type: 'boolean' }
    ];

    // Store Warehouse Master
    var warehouseDs = new Ext.data.Store({
<<<<<<< Updated upstream
        url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
        url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
        baseParams: { task: 'read_warehouse' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }])
    });
    warehouseDs.load();

    // Store Bin Location Cascading (Filtered by Warehouse)
    var binByWarehouseDs = new Ext.data.Store({
<<<<<<< Updated upstream
        url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
        url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
        baseParams: { task: 'read_bin_by_warehouse' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'code' }, { name: 'name' }, { name: 'zone' }, { name: 'rack' }, { name: 'level' }
        ])
    });

    // Main Store Grid
    var mainDs = new Ext.data.Store({
<<<<<<< Updated upstream
        url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
        url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data' }, binField)
    });

    // ------------------------------------------------------------
    // 2. MAIN GRID PANEL
    // ------------------------------------------------------------
    var calcHeight = 320;
    try {
        if (typeof maxGridHeight === 'function' && gridContainer) {
            var h = maxGridHeight(gridContainer);
            if (h && h > 200) { calcHeight = Math.max(220, Math.round(h * 0.55)); }
        }
    } catch (e) { calcHeight = 320; }

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: calcHeight,
        loadMask: true,
        stripeRows: true,
        viewConfig: { forceFit: true },
        columns: [
            { header: 'Kode Bin', dataIndex: 'code', width: 110, renderer: CellWrap },
        { header: 'Nama Bin', dataIndex: 'name', width: 160, renderer: CellWrap },
        { header: 'Location', dataIndex: 'location_name', width: 140, renderer: CellWrap }, // <-- Kolom baru di sini
        { header: 'Gudang', dataIndex: 'warehouse_name', width: 150, renderer: CellWrap },
        { header: 'Zona', dataIndex: 'zone', width: 80 },
        { header: 'Rak', dataIndex: 'rack', width: 80 },
        { header: 'Level', dataIndex: 'level', width: 80 },
        {
            header: 'Status', dataIndex: 'active', width: 80, align: 'center',
            renderer: function (v) {
                var isAct = (v === true || v === 'true' || v === 't' || v === 1 || v === '1');
                return isAct ? '<span style="color:#4ade80;font-weight:bold;">Aktif</span>' : '<span style="color:#888;">Nonaktif</span>';
            }
        }
        ],
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                    loadBinDetail(dataRecord.id, dataRecord.code, dataRecord.warehouse_name);
                }
            }
        }
    });

    mainDs.load();
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. DETAIL VIEW STOK REALTIME
    // ------------------------------------------------------------
    function loadBinDetail(binId, binCode, warehouseName) {
        Ext.Ajax.request({
<<<<<<< Updated upstream
            url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
            url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
            method: 'POST',
            params: { task: 'read_bin_detail', bin_id: binId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    binDtlDiv.innerHTML = '<div style="padding:15px; color:yellow;">Gagal memuat detail stok Bin Location.</div>';
                    binDtlDiv.style.display = 'block';
                    return;
                }
                renderBinDetailView(res.data || [], binCode, warehouseName);
            },
            failure: function () {
                binDtlDiv.innerHTML = '<div style="padding:15px; color:red;">Gagal terhubung ke server.</div>';
                binDtlDiv.style.display = 'block';
            }
        });
    }

    function renderBinDetailView(rows, binCode, warehouseName) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var html = '<div style="position:sticky; top:0; background:#1e222d; z-index:10; padding:12px 15px 0 15px; border-bottom:1px solid rgba(255,255,255,0.2);">' +
                   '  <h3 style="margin:0 0 10px 0; color:#ffffff !important; font-size:14px;">' +
                   '    Rincian Stok Barang pada Bin: ' + Ext.util.Format.htmlEncode(binCode || '') + ' (' + Ext.util.Format.htmlEncode(warehouseName || '') + ')' +
                   '  </h3>' +
                   '  <table width="100%" style="border-collapse:collapse; font-size:12.5px;">' +
                   '    <thead><tr>' +
                   '      <th align="left" style="width:20%; padding:8px 6px; color:#cccccc !important;">Kode Item</th>' +
                   '      <th align="left" style="width:45%; padding:8px 6px; color:#cccccc !important;">Nama Barang / Item</th>' +
                   '      <th align="left" style="width:15%; padding:8px 6px; color:#cccccc !important;">Grade</th>' +
                   '      <th align="right" style="width:20%; padding:8px 6px; color:#cccccc !important;text-align: right !important;">Qty Stok</th>' +
                   '    </tr></thead>' +
                   '  </table></div>';

        html += '<div style="padding:0 15px 12px 15px;"><table width="100%" style="border-collapse:collapse; font-size:12.5px;"><tbody>';
        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="4" style="padding:20px; text-align:center; color:#aaaaaa !important;">Tidak ada barang tersimpan di lokasi rak/bin ini.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var qty = parseFloat(r.qty) || 0;
                html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.08);">' +
                        '  <td style="width:20%; padding:7px 6px; color:#ffffff !important; font-family:monospace;">' + Ext.util.Format.htmlEncode(r.item_code || '-') + '</td>' +
                        '  <td style="width:45%; padding:7px 6px; color:#ffffff !important;">' + Ext.util.Format.htmlEncode(r.item_name || '-') + '</td>' +
                        '  <td style="width:15%; padding:7px 6px; color:#ffffff !important;">' + Ext.util.Format.htmlEncode(r.grade_name || '-') + '</td>' +
                        '  <td style="width:20%; padding:7px 6px; color:#4ade80 !important; font-weight:bold;" align="right">' + fmtQty(qty) + '</td>' +
                        '</tr>';
            });
        }
        html += '</tbody></table></div>';

        binDtlDiv.innerHTML = html;
        binDtlDiv.style.display = 'block';
        binDtlDiv.scrollTop = 0;
    }

    // ------------------------------------------------------------
    // 4. FORM EDITOR & FIELD DEFINITION (CASCADING FILTERING)
    // ------------------------------------------------------------
    var fldWarehouse = new myComboField({
        fieldLabel: 'Gudang',
        id: 'warehouse_id',
        name: 'warehouse_id',
        hiddenName: 'warehouse_id',
        store: warehouseDs,
        displayField: 'name',
        valueField: 'id',
        allowBlank: false,
        listeners: {
            select: function (combo, record) {
                var whId = combo.getValue();
                fldBinLocation.clearValue();
                binByWarehouseDs.load({ params: { warehouse_id: whId } });
            }
        }
    });

    var fldBinLocation = new myComboField({
        fieldLabel: 'Lokasi Bin',
        id: 'bin_location_id',
        name: 'bin_location_id',
        hiddenName: 'bin_location_id',
        store: binByWarehouseDs,
        displayField: 'code',
        valueField: 'id',
        mode: 'local',
        triggerAction: 'all',
        emptyText: 'Pilih Bin Location...'
    });

    var fldCode = new myTextField({ fieldLabel: 'Kode Bin', id: 'code', name: 'code', allowBlank: false });
    var fldName = new myTextField({ fieldLabel: 'Nama Bin', id: 'name', name: 'name' });
    var fldZone = new myTextField({ fieldLabel: 'Zona', id: 'zone', name: 'zone' });
    var fldRack = new myTextField({ fieldLabel: 'Rak', id: 'rack', name: 'rack' });
    var fldLevel = new myTextField({ fieldLabel: 'Level', id: 'level', name: 'level' });
    
    var fldActive = new Ext.form.Checkbox({
        fieldLabel: 'Status Aktif',
        labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;',
        id: 'active',
        name: 'active',
        cls: 'fieldCheckClass regular-checkbox',
        inputValue: 'true',
        checked: true
    });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldWarehouse },
            { items: fldCode },
            { items: fldName },
            { items: fldZone },
            { items: fldRack },
            { items: fldLevel },
            { columnWidth: 0.5, labelAlign: 'right', labelWidth: 130, items: fldActive }
        ]
    });

    // ------------------------------------------------------------
    // 5. ACTION HANDLERS
    // ------------------------------------------------------------
    var btnAdd = document.getElementById('addData');
    if (btnAdd) {
        btnAdd.onclick = function () {
            if (getFormAccess() >= 2) {
                task = 'create';
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'block';
                document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Bin Location Baru';
                fldActive.setValue(true);
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menambah data.');
            }
        };
    }

    var btnEdit = document.getElementById('editData');
    if (btnEdit) {
        btnEdit.onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data yang ingin diedit.');
                return;
            }
            if (getFormAccess() >= 3) {
                task = 'update';
                frmEditor.getForm().reset();

                document.getElementById('editorWdw').style.display = 'block';
                document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Bin Location';

                fldWarehouse.setValue(dataRecord.warehouse_id);
                fldCode.setValue(dataRecord.code);
                fldName.setValue(dataRecord.name || '');
                fldZone.setValue(dataRecord.zone || '');
                fldRack.setValue(dataRecord.rack || '');
                fldLevel.setValue(dataRecord.level || '');
                
                // Load bin location store berdasarkan warehouse ID record yang diedit
                binByWarehouseDs.load({
                    params: { warehouse_id: dataRecord.warehouse_id },
                    callback: function () {
                        fldBinLocation.setValue(dataRecord.id);
                    }
                });

                var isActive = (
                    dataRecord.active === true || dataRecord.active === 'true' || 
                    dataRecord.active === 't' || dataRecord.active === 1 || dataRecord.active === '1'
                );
                fldActive.setValue(isActive);
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengedit.');
            }
        };
    }

    var btnDel = document.getElementById('deleteData');
    if (btnDel) {
        btnDel.onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data yang ingin dihapus.');
                return;
            }
            if (getFormAccess() >= 4) {
                Ext.Msg.confirm('Konfirmasi Hapus', "Hapus Bin Location '" + dataRecord.code + "'?", function (btn) {
                    if (btn === 'yes') {
                        Ext.Ajax.request({
<<<<<<< Updated upstream
                            url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
                            url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
                            method: 'POST',
                            params: { task: 'delete', id: dataRecord.id },
                            success: function (response) {
                                var res = Ext.util.JSON.decode(response.responseText);
                                Ext.Msg.alert('Informasi', res.message);
                                if (res.success) {
                                    binDtlDiv.style.display = 'none';
                                    mainDs.load();
                                }
                            },
                            failure: function () {
                                Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
                            }
                        });
                    }
                });
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses menghapus data.');
            }
        };
    }

    var btnSave = document.getElementById('saveEdit');
    if (btnSave) {
        btnSave.onclick = function () {
            if (!fldWarehouse.getValue()) {
                Ext.Msg.alert('Peringatan', 'Gudang wajib dipilih.');
                return;
            }
            if (!fldCode.getValue()) {
                Ext.Msg.alert('Peringatan', 'Kode wajib diisi.');
                return;
            }

            var currentId = (task === 'update' && dataRecord) ? dataRecord.id : null;

            Ext.Ajax.request({
<<<<<<< Updated upstream
                url: '/rab-project/dev/php-crud/bin.locationCRD.php',
=======
                url: '/rab-project/live/php-crud/bin.locationCRD.php',
>>>>>>> Stashed changes
                method: 'POST',
                params: {
                    task: task,
                    id: currentId,
                    warehouse_id: fldWarehouse.getValue(),
                    code: fldCode.getValue(),
                    name: fldName.getValue(),
                    zone: fldZone.getValue(),
                    rack: fldRack.getValue(),
                    level: fldLevel.getValue(),
                    active: fldActive.getValue() ? 'true' : 'false'
                },
                success: function (response) {
                    var res = Ext.util.JSON.decode(response.responseText);
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message);
                    if (res.success) {
                        binDtlDiv.style.display = 'none';
                        mainDs.load();
                        document.getElementById('editorWdw').style.display = 'none';
                    }
                },
                failure: function () {
                    Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
                }
            });
        };
    }

    if (document.getElementById('editorWdw')) {
        document.getElementById('editorWdw').style.display = 'none';
    }
});