/*
Document: rab.js - EXTENDED VERSION
Support multi-category: Material, Upah, Peralatan, Transport, Lain-lain
Material: track by qty | Non-Material: track by amount
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    document.getElementById("mainHeaderText").innerHTML = "RAB <i>Material & Biaya</i>";
    var dataRecord = null;
    var rabCur = { id: null, code: 'IDR', baseCode: 'IDR', isBase: true, rate: 1, locked: null };
    var rateManual = false, rateReq = 0;
    var hdrForm = null, fCur, fRate, fRateDate, fRateInfo;
    var plafon = null;
    var currentCategory = 'Material'; // Default tab
    
    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function statusColor(v) {
        if (v === 'OVERBUDGET') return '#c0392b !important';
        if (v === 'WARNING') return '#e6a817 !important';
        if (v === 'NO BUDGET') return '#888888 !important';
        return '#27ae60 !important';
    }
    
    // Category tabs
    var CATEGORIES = ['Material', 'Upah', 'Peralatan', 'Transport', 'Konsumsi', 'Lain-lain'];
    var CATEGORY_ICONS = { 'Material': '📦', 'Upah': '👷', 'Peralatan': '🔧', 'Transport': '🚛', 'Konsumsi': '🍱', 'Lain-lain': '📋' };
    
    // Container
    var gridContainer = document.getElementById('divMainGrid');
    var rabDtlDiv = document.createElement('div');
    rabDtlDiv.id = 'rabDtlContainer';
    rabDtlDiv.className = 'detail-panel-container';
    rabDtlDiv.style.display = 'none';
    if (gridContainer && gridContainer.parentNode) gridContainer.parentNode.insertBefore(rabDtlDiv, gridContainer.nextSibling);
    
    function fitRabDtl() {
        var sc = document.getElementById('rabDtlScroll');
        if (!sc) return;
        var top = sc.getBoundingClientRect().top;
        sc.style.maxHeight = Math.max(160, window.innerHeight - top - 40) + 'px';
    }
    window.addEventListener('resize', fitRabDtl);
    
    function loadRabDetailPanel(projectId, projectName) {
        Ext.Ajax.request({
            url: '../dev/php-crud/rabCRD.php', method: 'POST',
            params: { task: 'load_rab', project_id: projectId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    rabDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal memuat detail RAB.</div>';
                    rabDtlDiv.style.display = 'block'; return;
                }
                renderRabDetailPanel(res.data.items || [], projectName, res.data.currency, res.data.plafon);
            },
            failure: function () {
                rabDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
                rabDtlDiv.style.display = 'block';
            }
        });
    }
    
    function renderRabDetailPanel(rows, projectName, cur, pf) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var fmtPct = function (v) { return Ext.util.Format.number(v || 0, '0.00') + '%'; };
        var code = (cur && cur.code) || 'IDR';
        
        // Group by category
        var byCategory = {};
        Ext.each(CATEGORIES, function(c) { byCategory[c] = []; });
        Ext.each(rows, function(r) {
            var cat = r.category || 'Lain-lain';
            if (!byCategory[cat]) byCategory[cat] = [];
            byCategory[cat].push(r);
        });
        
        var curLine = '<div style="padding:0 2px 10px 2px;">Mata uang: <b>' + Ext.util.Format.htmlEncode(code) + '</b></div>';
        var plafonLine = '';
        if (pf) {
            var base = (cur && cur.base_code) || 'IDR';
            var noContract = !(pf.contract_value > 0);
            var low = pf.reserve_total > 0 && pf.reserve_remaining < pf.reserve_total * 0.1;
            var remColor = noContract ? '#ff6b5e' : (pf.reserve_remaining <= 0 ? '#ff6b5e' : (low ? '#e6a817' : '#7fe0a0'));
            plafonLine = '<div style="padding:6px 10px; margin:0 2px 10px 2px; background:#1f2d3a; border-radius:4px; line-height:1.7;">' +
                (noContract ? '<span style="color:#ff6b5e;">Nilai Kontrak belum diisi.</span>' :
                'Nilai Kontrak: <b>' + base + ' ' + fmtAmt(pf.contract_value) + '</b> | Budget: <b>' + fmtAmt(pf.budget_base) + '</b> | Cadangan: <b>' + fmtAmt(pf.reserve_total) + '</b>') +
                '<br>Total RAB: <b>' + fmtAmt(pf.rab_total_base) + '</b> | Terpakai: <b>' + fmtAmt(pf.reserve_used) + '</b> | <span style="color:' + remColor + ';">Sisa: <b>' + fmtAmt(pf.reserve_remaining) + '</b></span></div>';
        }
        
        // Category tabs
        var tabsHtml = '<div style="display:flex; gap:4px; margin-bottom:10px; flex-wrap:wrap;">';
        Ext.each(CATEGORIES, function(cat) {
            var count = byCategory[cat] ? byCategory[cat].length : 0;
            var active = cat === currentCategory ? 'background:#22d3ee; color:#020c1b;' : 'background:#1f2d3a; color:#22d3ee;';
            tabsHtml += '<button onclick="switchRabCategory(\'' + cat + '\')" style="padding:6px 12px; border:1px solid #22d3ee; border-radius:4px; cursor:pointer; font-weight:bold; ' + active + '">' +
                CATEGORY_ICONS[cat] + ' ' + cat + ' (' + count + ')</button>';
        });
        tabsHtml += '</div>';
        
        var html = '<h3 class="detail-title">RAB: ' + Ext.util.Format.htmlEncode(projectName || '') + '</h3><hr class="detail-hr" />' + curLine + plafonLine + tabsHtml;
        html += '<div id="rabDtlScroll" style="overflow-y: auto; overflow-x: auto;">';
        
        var catRows = byCategory[currentCategory] || [];
        var isMaterial = currentCategory === 'Material';
        
        html += '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">';
        html += '<th align="left">Item</th>';
        if (isMaterial) {
            html += '<th align="right">Budget Qty</th><th align="right">Harga Satuan</th><th align="right">Total Budget</th><th align="right">Qty Terpakai</th><th align="right">% Terpakai</th>';
        } else {
            html += '<th align="right">Budget Amount</th><th align="right">Terpakai</th><th align="right">Sisa</th><th align="right">% Terpakai</th>';
        }
        html += '<th align="center">Status</th></tr></thead><tbody>';
        
        if (catRows.length === 0) {
            html += '<tr><td colspan="' + (isMaterial ? 7 : 5) + '" style="padding:15px; text-align:center; color:#557982;">Belum ada data ' + currentCategory + '.</td></tr>';
        } else {
            var grandTotal = 0;
            Ext.each(catRows, function (r) {
                var totalBudget = (parseFloat(r.budget_qty) || 0) * (parseFloat(r.unit_price) || 0);
                grandTotal += totalBudget;
                html += '<tr class="data-row"><td align="left">' + Ext.util.Format.htmlEncode(r.material_name || r.category || '-') +
                    (r.is_addendum ? ' <span style="color:#e6a817;">[TAMBAHAN]</span>' : '') + '</td>';
                if (isMaterial) {
                    html += '<td align="right">' + fmtQty(r.budget_qty) + ' ' + Ext.util.Format.htmlEncode(r.unit || '') + '</td>' +
                        '<td align="right">' + fmtAmt(r.unit_price) + '</td><td align="right" class="subtotal-val">' + fmtAmt(totalBudget) + '</td>' +
                        '<td align="right">' + fmtQty(r.total_qty_out) + '</td><td align="right">' + fmtPct(r.usage_pct) + '</td>';
                } else {
                    var actual = r.expense_actual || 0;
                    var pct = totalBudget > 0 ? (actual / totalBudget * 100) : 0;
                    var sisa = totalBudget - actual;
                    html += '<td align="right">' + fmtAmt(totalBudget) + '</td><td align="right">' + fmtAmt(actual) + '</td>' +
                        '<td align="right" style="color:' + (sisa < 0 ? '#c0392b' : '#27ae60') + ';">' + fmtAmt(sisa) + '</td>' +
                        '<td align="right">' + fmtPct(pct) + '</td>';
                }
                html += '<td align="center" style="font-weight:bold; color:' + statusColor(r.status) + ';">' + Ext.util.Format.htmlEncode(r.status || '-') + '</td></tr>';
            });
            html += '<tr class="data-row"><td colspan="' + (isMaterial ? 3 : 1) + '" align="right"><b>Total ' + currentCategory + '</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grandTotal) + '</b></td>' +
                '<td colspan="' + (isMaterial ? 3 : 3) + '"></td></tr>';
        }
        html += '</tbody></table></div>';
        rabDtlDiv.innerHTML = html;
        rabDtlDiv.style.display = 'block';
        fitRabDtl();
    }
    
    // Global function for tab switching
    window.switchRabCategory = function(cat) {
        currentCategory = cat;
        if (dataRecord) loadRabDetailPanel(dataRecord.id, dataRecord.name);
    };
    
    // Fields & Stores
    var projectField = [
        { name: 'id' }, { name: 'name' }, { name: 'client_name' }, { name: 'status' },
        { name: 'material_count', type: 'int' }, { name: 'total_budget_cost', type: 'float' },
        { name: 'total_budget_base', type: 'float' }, { name: 'total_actual_cost', type: 'float' },
        { name: 'currency_code' }, { name: 'budget_exch_rate', type: 'float' },
        { name: 'contract_value', type: 'float' }, { name: 'budget_base', type: 'float' },
        { name: 'expense_budget', type: 'float' }, { name: 'expense_actual', type: 'float' },
        { name: 'rab_status' }
    ];
    var materialField = [
        { name: 'id' }, { name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
        { name: 'estimated_price', type: 'float' }
    ];
    var rabItemField = [
        { name: 'id' }, { name: 'material_id' }, { name: 'expense_id' }, { name: 'material_code' }, { name: 'material_name' },
        { name: 'unit' }, { name: 'category' }, { name: 'item_name' },
        { name: 'budget_qty', type: 'float' }, { name: 'unit_price', type: 'float' },
        { name: 'tolerance_pct', type: 'float' },
        { name: 'total_qty_out', type: 'float' }, { name: 'remaining_qty', type: 'float' },
        { name: 'usage_pct', type: 'float' }, { name: 'status' },
        { name: 'orig_qty', type: 'float' }, { name: 'orig_price', type: 'float' },
        { name: 'is_addendum' }, { name: 'addendum_reason' }
    ];
    
    var materialDs = new Ext.data.Store({
        url: '../dev/php-crud/rabCRD.php', baseParams: { task: 'list_materials' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, materialField)
    });
    materialDs.load();

    var expenseField = [
        { name: 'id' }, { name: 'expense_code' }, { name: 'expense_name' },
        { name: 'category' }, { name: 'unit' }, { name: 'default_price', type: 'float' }
    ];
    var expenseDs = new Ext.data.Store({
        url: '../dev/php-crud/rabCRD.php',
        baseParams: { task: 'list_expense_options', category: 'All' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, expenseField),
        listeners: { load: function () { if (itemGrid) itemGrid.getView().refresh(); } }
    });
    expenseDs.load();
    
    var currencyDs = new Ext.data.Store({
        url: '../dev/php-crud/rabCRD.php', baseParams: { task: 'list_currencies' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'init' }, { name: 'name' }, { name: 'is_base' }])
    });
    currencyDs.load();
    
    var rabItemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, rabItemField) });
    
    mainDs = new Ext.data.Store({
        url: '../dev/php-crud/rabCRD.php', idProperty: 'id', baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, projectField)
    });
    mainDs.load();
    
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.5));
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: mainHeight,
        columns: [
            { header: 'Nama Proyek', dataIndex: 'name', width: 200, renderer: CellWrap },
            { header: 'Klien', dataIndex: 'client_name', width: 150, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Status', dataIndex: 'status', width: 100, align: 'center' },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 70, align: 'center' },
            { header: 'Material', dataIndex: 'material_count', width: 70, align: 'center' },
            { header: 'Budget Material', dataIndex: 'total_budget_cost', width: 120, align: 'right', renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Budget Biaya', dataIndex: 'expense_budget', width: 120, align: 'right', renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Terpakai Biaya', dataIndex: 'expense_actual', width: 120, align: 'right', renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Nilai Kontrak', dataIndex: 'contract_value', width: 120, align: 'right', renderer: function (v) { return v > 0 ? Ext.util.Format.number(v, '0,000.00') : '<span style="color:#ff6b5e;">-</span>'; } },
            { header: 'Status RAB', dataIndex: 'rab_status', width: 100, align: 'center', renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + Ext.util.Format.htmlEncode(v || '-') + '</span>'; } }
        ],
        viewConfig: { forceFit: true }, loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) { dataRecord = rec.data; mainBtnEnable('#editData'); loadRabDetailPanel(dataRecord.id, dataRecord.name); }
            }
        }
    });
    editDataDisable(); mainBtnDisable('#addData'); mainBtnDisable('#deleteData');
    
    // Editor Grid
    var itemGrid = new stdGridEditor({
        store: rabItemDs, renderTo: 'editorFormGrid', viewConfig: { forceFit: true, scrollOffset: 0 }, height: 300,
        columns: [
            {
                header: 'Kategori', dataIndex: 'category', width: 100,
                editor: new myComboField({
                    store: new Ext.data.ArrayStore({ fields: ['v'], data: CATEGORIES.map(function(c) { return [c]; }) }),
                    displayField: 'v', valueField: 'v', mode: 'local', triggerAction: 'all', forceSelection: true,
                    listeners: { select: function (combo, record) {
                        var row = itemGrid.getSelectionModel().getSelected();
                        if (row) {
                            row.set('category', record.get('v'));
                            row.set('expense_id', null);
                            if (record.get('v') !== 'Material') { 
                                row.set('material_id', null); 
                                row.set('material_name', ''); 
                                row.set('material_code', ''); 
                                row.set('item_name', ''); 
                            } else { 
                                row.set('item_name', ''); 
                            }
                            updateRabTotalLabel();
                        }
                    } }
                }),
                renderer: function (v) { return (CATEGORY_ICONS[v] || '') + ' ' + Ext.util.Format.htmlEncode(v || '-'); }
            },
            {
                header: 'Material / Item', dataIndex: 'material_id', width: 200,
                editor: new myComboField({
                    store: materialDs, displayField: 'material_name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true,
                    listeners: {
                        select: function (combo, record) {
                            var row = itemGrid.getSelectionModel().getSelected();
                            if (row) {
                                row.set('material_id', record.get('id'));
                                row.set('material_code', record.get('material_code'));
                                row.set('material_name', record.get('material_name'));
                                row.set('unit', record.get('unit'));
                                row.set('category', 'Material');
                                if (!row.get('unit_price')) row.set('unit_price', record.get('estimated_price'));
                                updateRabTotalLabel();
                            }
                        }
                    }
                }),
                renderer: function (v, meta, record) {
                    if (record.get('category') !== 'Material') {
                        var cat = record.get('category') || '';
                        var selectedExpense = String(record.get('expense_id') || '');
                        var options = '<option value="">Pilih ' + Ext.util.Format.htmlEncode(cat || 'Expense') + '...</option>';
                        expenseDs.each(function (ex) {
                            if (ex.get('category') === cat) {
                                var exId = String(ex.get('id'));
                                options += '<option value="' + exId + '"' + (exId === selectedExpense ? ' selected="selected"' : '') + '>' +
                                    Ext.util.Format.htmlEncode(ex.get('expense_code') + ' - ' + ex.get('expense_name')) + '</option>';
                            }
                        });
                        return '<select class="rab-expense-select" data-row="' + record.id + '" style="width:100%;max-width:100%;background:#0d2133;color:#fff;border:1px solid #1a4f5c;border-radius:3px;padding:3px;">' + options + '</select>';
                    }
                    var rec = materialDs.getById(v);
                    if (rec) return Ext.util.Format.htmlEncode(rec.get('material_name'));
                    if (record && record.get('material_name')) return '<span style="color:#e6a817;">' + Ext.util.Format.htmlEncode(record.get('material_name')) + '</span>';
                    return '<i>-- pilih material --</i>';
                }
            },
            { header: 'Satuan', dataIndex: 'unit', width: 60, align: 'center', editor: new myTextField() },
            { header: 'Qty / Amount', dataIndex: 'budget_qty', width: 100, align: 'right', editor: new myNumberField({ decimalPrecision: 4 }), renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Harga Satuan', dataIndex: 'unit_price', width: 110, align: 'right', editor: new myNumberField({ decimalPrecision: 2 }), renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Total', dataIndex: 'budget_qty', width: 120, align: 'right', renderer: function (v, meta, record) { var total = (parseFloat(record.get('budget_qty')) || 0) * (parseFloat(record.get('unit_price')) || 0); return '<b>' + Ext.util.Format.number(total, '0,000.00') + '</b>'; } },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center', renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + Ext.util.Format.htmlEncode(v || '-') + '</span>'; } },
            {
                id: 'rabDeleteCol', header: '', dataIndex: 'material_id', width: 32, align: 'center', fixed: true,
                renderer: function () { return '<span class="rabDeleteBtn" style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>'; }
            }
        ],
        listeners: {
            afteredit: function () { updateRabTotalLabel(); },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'rabDeleteCol') { grid.getStore().removeAt(rowIndex); updateRabTotalLabel(); }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Delegated handler for Expense Master selector in non-material RAB rows.
    (function bindExpenseSelector() {
        var el = document.getElementById('editorFormGrid');
        if (!el || el._rabExpenseSelectorBound) return;
        el._rabExpenseSelectorBound = true;
        el.addEventListener('change', function (evt) {
            var target = evt.target || evt.srcElement;
            if (!target || !target.className || String(target.className).indexOf('rab-expense-select') < 0) return;
            var rowKey = target.getAttribute('data-row');
            var row = null;
            rabItemDs.each(function (r) { if (String(r.id) === String(rowKey)) row = r; });
            if (!row) return;
            var idx = expenseDs.findBy(function (rec) { return String(rec.get('id')) === String(target.value); });
            if (idx < 0) return;
            var ex = expenseDs.getAt(idx);
            row.set('expense_id', parseInt(ex.get('id'), 10));
            row.set('item_name', ex.get('expense_name') || '');
            row.set('unit', ex.get('unit') || 'Unit');
            if (!(parseFloat(row.get('unit_price')) > 0)) row.set('unit_price', parseFloat(ex.get('default_price')) || 0);
            itemGrid.getView().refresh();
            updateRabTotalLabel();
        });
    })();
    
    // Inject UI
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('lblRabTotal')) {
            var totalsDiv = document.createElement('div');
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = 'Total RAB: <span id="lblRabTotal" style="color:#ffffff !important;">0.00</span><div id="lblRabPlafon" style="font-size:12px; font-weight:normal; margin-top:4px;"></div>';
            g.parentNode.insertBefore(totalsDiv, g.nextSibling);
            var btn = document.createElement('button');
            btn.id = 'btnAddRabRow'; btn.type = 'button'; btn.innerHTML = '+ Tambah Item';
            btn.style.cssText = 'margin-bottom:6px; padding:5px 12px; background:#2e6da4; color:#fff; border:none; border-radius:3px; cursor:pointer;';
            btn.onclick = addBlankRow;
            g.parentNode.insertBefore(btn, g);
            var hdr = document.createElement('div');
            hdr.id = 'rabHdrForm'; hdr.style.cssText = 'margin-bottom:8px;';
            g.parentNode.insertBefore(hdr, btn);
        }
    })();
    
    function updateRabTotalLabel() {
        var total = 0;
        rabItemDs.each(function (r) { total += (parseFloat(r.get('budget_qty')) || 0) * (parseFloat(r.get('unit_price')) || 0); });
        var el = document.getElementById('lblRabTotal');
        if (el) {
            var html = Ext.util.Format.number(total, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(rabCur.code);
            if (!rabCur.isBase) html += ' &asymp; ' + Ext.util.Format.htmlEncode(rabCur.baseCode) + ' ' + Ext.util.Format.number(total * (parseFloat(rabCur.rate) || 1), '0,000.00');
            el.innerHTML = html;
        }
        updatePlafonBar(total);
    }
    
    function curRate() { return parseFloat(fRate ? fRate.getValue() : rabCur.rate) || parseFloat(rabCur.rate) || 1; }
    
    function plafonCheck(total) {
        var res = { block: null, usesReserve: false, promptMsg: '' };
        if (!plafon) return res;
        var f = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var rate = curRate(), totalBase = total * rate, budgetBase = (plafon.budget || 0) * rate, contract = plafon.contract_value || 0;
        if (contract <= 0 && plafon.require_contract) res.block = 'Nilai Kontrak belum diisi.';
        else if (contract > 0 && budgetBase > contract + 0.01) res.block = 'Budget melebihi Nilai Kontrak.';
        else if (contract > 0 && totalBase > contract + 0.01) res.block = 'Total RAB melebihi Nilai Kontrak.';
        else if (plafon.phase === 'planning' && plafon.budget > 0 && totalBase > budgetBase + 0.01) res.block = 'Total RAB melebihi Budget. Cadangan hanya untuk proyek Berjalan.';
        else if (plafon.phase === 'running' && plafon.budget > 0 && totalBase > budgetBase + 0.01) {
            res.usesReserve = true;
            res.promptMsg = 'Memakai cadangan: <b>' + f(totalBase - budgetBase) + '</b> dari <b>' + f(Math.max(contract - budgetBase, 0)) + '</b> (sisa ' + f(Math.max(contract - totalBase, 0)) + ').<br>Alasan:';
        }
        return res;
    }
    
    function updatePlafonBar(total) {
        var el = document.getElementById('lblRabPlafon');
        if (!el || !plafon) { if (el) el.innerHTML = ''; return; }
        var f = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var rate = curRate(), totalBase = total * rate, budgetBase = (plafon.budget || 0) * rate, contract = plafon.contract_value || 0;
        if (contract <= 0) { el.innerHTML = '<span style="color:#ff6b5e;">Nilai Kontrak belum diisi.</span>'; return; }
        var chk = plafonCheck(total);
        var color = chk.block ? '#ff6b5e' : (chk.usesReserve ? '#e6a817' : '#7fe0a0');
        el.innerHTML = '<span style="color:' + color + ';">Kontrak ' + f(contract) + ' | Budget ' + f(budgetBase) + ' | Cadangan ' + f(Math.max(contract - budgetBase, 0)) + ' | RAB ' + f(totalBase) + ' | Sisa ' + f(contract - totalBase) + (chk.block ? ' (MELEBIHI)' : (chk.usesReserve ? ' (PAKAI CADANGAN)' : '')) + '</span>';
    }
    
    function baseCurrencyCode() {
        var code = 'IDR';
        currencyDs.each(function (r) { if (r.get('is_base') === true) code = r.get('init'); });
        return code;
    }
    
    function applyCurrencyHeaders() {
        var cm = itemGrid.getColumnModel();
        cm.setColumnHeader(4, 'Harga Satuan (' + rabCur.code + ')');
        cm.setColumnHeader(5, 'Total (' + rabCur.code + ')');
    }
    
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }
        fCur = new myComboField({ fieldLabel: 'Mata Uang', store: currencyDs, displayField: 'init', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, width: 110, listeners: { select: function () { onCurrencyChange(); } } });
        fRateDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Kurs', format: 'd/m/Y', value: new Date(), width: 120, listeners: { select: function () { if (!rateManual) refreshRate(); }, change: function () { if (!rateManual) refreshRate(); } } });
        fRate = new myNumberField({ fieldLabel: 'Kurs', decimalPrecision: 4, value: 1, width: 130, listeners: { change: function () { rateManual = true; rabCur.rate = fRate.getValue() || 1; updateRabTotalLabel(); } } });
        fRateInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });
        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 150, bodyStyle: 'background:transparent;', items: items }; };
        hdrForm = new Ext.form.FormPanel({ renderTo: 'rabHdrForm', border: false, layout: 'column', bodyStyle: 'background:transparent; padding:4px 2px;', items: [col(0.30, [fCur, fRateDate]), col(0.30, [fRate]), col(0.40, [fRateInfo])] });
    }
    
    function onCurrencyChange() {
        var rec = currencyDs.getById(fCur.getValue());
        if (!rec) return;
        rabCur.id = rec.get('id'); rabCur.code = rec.get('init'); rabCur.isBase = rec.get('is_base') === true; rabCur.baseCode = baseCurrencyCode();
        rateManual = false; applyCurrencyHeaders(); refreshRate(); itemGrid.getView().refresh(); updateRabTotalLabel();
    }
    
    function refreshRate() {
        var cid = fCur.getValue(), dt = fRateDate.getValue();
        if (!cid || !dt) return;
        var token = ++rateReq;
        Ext.Ajax.request({
            url: '../dev/php-crud/rabCRD.php', method: 'POST', params: { task: 'get_exchange_rate', currency_id: cid, date: ymd(dt) },
            success: function (response) {
                if (token !== rateReq) return;
                var res; try { res = Ext.util.JSON.decode(response.responseText); } catch (e) { return; }
                if (!res.success) { fRateInfo.setValue('<span style="color:#c0392b;">' + Ext.util.Format.htmlEncode(res.message || 'Gagal.') + '</span>'); return; }
                var d = res.data || {}, enc = Ext.util.Format.htmlEncode;
                rabCur.isBase = (d.source === 'BASE'); fRate.setReadOnly(rabCur.isBase);
                if (d.rate) { fRate.setValue(d.rate); rabCur.rate = d.rate; }
                fRateInfo.setValue(d.source === 'BASE' ? '<span style="color:#668894;">Mata uang dasar (kurs 1)</span>' : '<span style="color:#2e9e4f;">KMK ' + enc(d.kmk_no) + '</span>');
                updateRabTotalLabel();
            }
        });
    }
    
    function applyCurrencyFromServer(c) {
        c = c || {}; ensureHdrForm();
        rabCur = { id: c.id, code: c.code || 'IDR', baseCode: c.base_code || 'IDR', isBase: !!c.is_base, rate: parseFloat(c.exch_rate) || 1, locked: c.locked_reason || null };
        fCur.setValue(c.id); fRate.setValue(rabCur.rate); fRate.setReadOnly(rabCur.isBase);
        fRateDate.setValue((c.rate_date && Date.parseDate(c.rate_date, 'Y-m-d')) || new Date());
        rateManual = true; fCur.setDisabled(!!rabCur.locked);
        fRateInfo.setValue(rabCur.locked ? '<span style="color:#e6a817;">Mata uang terkunci.</span>' : (rabCur.isBase ? '<span style="color:#668894;">Mata uang dasar.</span>' : '<span style="color:#668894;">Kurs tersimpan.</span>'));
        applyCurrencyHeaders(); itemGrid.getView().refresh(); updateRabTotalLabel();
    }
    
    function addBlankRow() {
        var RabRecord = rabItemDs.recordType || Ext.data.Record.create(rabItemField);
        rabItemDs.add(new RabRecord({ id: 0, material_id: null, expense_id: null, material_code: '', material_name: '', unit: 'Unit', category: currentCategory, item_name: '', budget_qty: 0, unit_price: 0, tolerance_pct: 0, total_qty_out: 0, remaining_qty: 0, usage_pct: 0, status: 'ON TRACK', orig_qty: 0, orig_price: 0, is_addendum: false, addendum_reason: '' }));
        updateRabTotalLabel();
    }
    
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih proyek terlebih dahulu.'); return; }
        if (getFormAccess() < 3) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses.'); return; }
        Ext.Ajax.request({
            url: '../dev/php-crud/rabCRD.php', method: 'POST', params: { task: 'load_rab', project_id: dataRecord.id },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Gagal memuat RAB.'); return; }
                var loadedItems = res.data.items || [];
                Ext.each(loadedItems, function (i) { i.orig_qty = i.budget_qty; i.orig_price = i.unit_price; if (!i.category) i.category = 'Material'; if (!i.item_name) i.item_name = i.material_name; });
                plafon = res.data.plafon || null;
                rabItemDs.loadData({ data: loadedItems });
                document.getElementById('editorWdw').style.display = 'block';
                applyCurrencyFromServer(res.data.currency);
                document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;RAB -- ' + Ext.util.Format.htmlEncode(res.data.name || '');
            },
            failure: function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
        });
    };
    
    function sendSave(arrItems) {
        Ext.Ajax.request({
            url: '../dev/php-crud/rabCRD.php', method: 'POST',
            params: { task: 'save_rab', project_id: dataRecord.id, currency_id: fCur ? fCur.getValue() : 0, exch_rate: fRate ? (fRate.getValue() || 1) : 1, rate_date: (fRateDate && fRateDate.getValue()) ? ymd(fRateDate.getValue()) : '', items: Ext.util.JSON.encode(arrItems) },
            success: function (response) {
                var res; try { res = Ext.util.JSON.decode(response.responseText); } catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                if (res.success) { Ext.Msg.alert('Sukses', res.message || 'RAB berhasil disimpan.'); mainDs.load(); document.getElementById('editorWdw').style.display = 'none'; loadRabDetailPanel(dataRecord.id, dataRecord.name); }
                else Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan.');
            },
            failure: function () { Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
        });
    }
    
    document.getElementById('saveEdit').onclick = function () {
        if (!dataRecord) return;
        var arrItems = [], flagged = [], total = 0, hasInvalidRow = false, duplicateFound = false, materialSet = {};
        rabItemDs.each(function (r) {
            var cat = r.get('category') || 'Lain-lain';
            var materialId = r.get('material_id');
            var itemName = r.get('item_name') || r.get('material_name') || '';
            var qty = parseFloat(r.get('budget_qty')) || 0;
            var price = parseFloat(r.get('unit_price')) || 0;
            if (cat === 'Material' && (!materialId && qty <= 0)) return;
            if (cat !== 'Material' && qty <= 0) return;
            if (cat === 'Material' && (!materialId || qty <= 0)) { hasInvalidRow = true; return; }
            if (cat !== 'Material' && !(parseInt(r.get('expense_id'), 10) > 0)) { hasInvalidRow = true; return; }
            if (cat === 'Material') {
                if (materialSet[materialId]) duplicateFound = true; else materialSet[materialId] = true;
            }
            var origQty = parseFloat(r.get('orig_qty')) || 0;
            var origVal = origQty * (parseFloat(r.get('orig_price')) || 0);
            var isUp = origQty <= 0 || qty > origQty + 0.00005 || qty * price > origVal + 0.005;
            total += Math.round(qty * price * 100) / 100;
            var item = { id: r.get('id') || 0, material_id: cat === 'Material' ? materialId : 0, expense_id: cat === 'Material' ? 0 : (parseInt(r.get('expense_id'), 10) || 0), category: cat, item_name: itemName, unit: r.get('unit') || 'Unit', budget_qty: qty, unit_price: price, tolerance_pct: parseFloat(r.get('tolerance_pct')) || 0, addendum_reason: '' };
            if (isUp) flagged.push(item);
            arrItems.push(item);
        });
        if (hasInvalidRow) { Ext.Msg.alert('Peringatan', 'Ada baris Material atau Expense yang belum dipilih lengkap. Pilih item dari Master Expense untuk kategori non-Material.'); return; }
        if (duplicateFound) { Ext.Msg.alert('Peringatan', 'Terdapat duplikasi material.'); return; }
        var chk = plafonCheck(total);
        if (chk.block) { Ext.Msg.alert('Melebihi Plafon', chk.block); return; }
        if (chk.usesReserve && flagged.length) {
            Ext.Msg.prompt('Alasan Penambahan', chk.promptMsg, function (btn, text) {
                if (btn !== 'ok') return; text = Ext.util.Format.trim(text || '');
                if (!text) { Ext.Msg.alert('Peringatan', 'Alasan wajib diisi.'); return; }
                Ext.each(flagged, function (it) { it.addendum_reason = text; });
                sendSave(arrItems);
            }, this, true);
            return;
        }
        sendSave(arrItems);
    };
    
    document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; if (document.getElementById('popCancel')) document.getElementById('popCancel').style.display = 'none'; };
    document.getElementById('editorWdw').style.display = 'none';
});