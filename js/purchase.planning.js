/*
  Document      : purchase.planning.js
  Description   : Laporan sisa Budget RAB yang belum di-PO-kan. Grid
                   utama daftar Proyek, klik baris -> panel di bawah
                   nunjukin rincian per material (Budget, Sudah di-PO,
                   Sisa perlu dibeli). Bisa centang beberapa baris lalu
                   klik "Buat PO dari Terpilih" -> lempar data ke
                   halaman PO (po.php, router?id=24) lewat sessionStorage.
  Backend       : /rab-project/dev/php-crud/purchase.planningCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Purchase <em>Planning</em>";
    var CRD = main_url + '/php-crud/purchase.planningCRD.php';

    var dataRecord = null;
    var currentPlanningRows = [];
    var selectedRabIds = {};

    var projectField = [
        { name: 'id' }, { name: 'name' },
        { name: 'total_rab_count', type: 'int' },
        { name: 'material_count', type: 'int' },
        { name: 'total_remaining_value', type: 'float' }
    ];

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, projectField)
    });
    mainDs.load();

    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.6));
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'Nama Proyek', dataIndex: 'name', width: 300, renderer: CellWrap },
            {
                header: 'Jml Material Perlu Dibeli', dataIndex: 'material_count', width: 180, align: 'center',
                renderer: function (v, meta, record) {
                    var totalRab = record.get('total_rab_count');
                    if (totalRab === 0) {
                        return '<span style="color:#888888;">-- belum ada RAB --</span>';
                    }
                    if (v > 0) {
                        return '<span style="color:#e6a817;font-weight:bold;">' + v + ' dari ' + totalRab + '</span>';
                    }
                    return '<span style="color:#2e9e4f;">0 (lengkap, ' + totalRab + ' material)</span>';
                }
            },
            {
                header: 'Estimasi Nilai Perlu Dibeli', dataIndex: 'total_remaining_value', width: 200, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    selectedRabIds = {};
                    loadPlanningPanel(dataRecord.id, dataRecord.name);
                }
            }
        }
    });
    mainBtnDisable('#addData');
    mainBtnDisable('#editData');
    mainBtnDisable('#deleteData');

    // Inject Style Khusus Checkbox Custom (Mengatasi Override ExtJS)
    if (!document.getElementById('customChkStyle')) {
        var styleEl = document.createElement('style');
        styleEl.id = 'customChkStyle';
        styleEl.innerHTML = 
            '.planChk {' +
            '  -webkit-appearance: none !important;' +
            '  -moz-appearance: none !important;' +
            '  appearance: none !important;' +
            '  width: 18px !important;' +
            '  height: 18px !important;' +
            '  border: 1.5px solid #2bc1e2 !important;' +
            '  border-radius: 3px !important;' +
            '  outline: none !important;' +
            '  background-color: #0d2133 !important;' +
            '  cursor: pointer !important;' +
            '  vertical-align: middle !important;' +
            '  display: inline-block !important;' +
            '  position: relative !important;' +
            '}' +
            '.planChk:checked {' +
            '  background-color: #00bef8 !important;' +
            '  border-color: #00bef8 !important;' +
            '  background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%230d2133\' stroke-width=\'3.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpolyline points=\'20 6 9 17 4 12\'%3E%3C/polyline%3E%3C/svg%3E") !important;' +
            '  background-size: 14px 14px !important;' +
            '  background-position: center !important;' +
            '  background-repeat: no-repeat !important;' +
            '}';
        document.head.appendChild(styleEl);
    }

    // Slider (scrollbar) untuk tabel rincian + header tabel tetap terlihat saat di-scroll
    if (!document.getElementById('planScrollStyle')) {
        var scrollStyle = document.createElement('style');
        scrollStyle.id = 'planScrollStyle';
        scrollStyle.innerHTML =
            '#planDtlScroll { overflow-y: auto !important; overflow-x: auto !important; scrollbar-width: thin; scrollbar-color: #1f6f9f #061522; }' +
            '#planDtlScroll::-webkit-scrollbar { width: 10px; height: 10px; }' +
            '#planDtlScroll::-webkit-scrollbar-track { background: #061522; border-radius: 5px; }' +
            '#planDtlScroll::-webkit-scrollbar-thumb { background: #1f6f9f; border-radius: 5px; }' +
            '#planDtlScroll::-webkit-scrollbar-thumb:hover { background: #2b8fc7; }' +
            '#planDtlScroll thead th { position: sticky; top: 0; z-index: 2; background: #0d2838 !important; }';
        document.head.appendChild(scrollStyle);
    }

    // ------------------------------------------------------------
    // Panel Detail (di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var planDtlDiv = document.createElement('div');
    planDtlDiv.id = 'planDtlContainer';
    planDtlDiv.className = 'detail-panel-container';
    planDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(planDtlDiv, gridContainer.nextSibling);
    }

    // Tinggi area scroll = sisa tinggi layar di bawah judul panel (tombol "Buat PO" tetap kelihatan)
    function fitPlanDtl() {
        var sc = document.getElementById('planDtlScroll');
        if (!sc) return;
        var top = sc.getBoundingClientRect().top;
        var footer = 80;                                   // ruang tombol + padding panel
        sc.style.maxHeight = Math.max(160, window.innerHeight - top - footer) + 'px';
    }
    window.addEventListener('resize', fitPlanDtl);

    function loadPlanningPanel(projectId, projectName) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'load_planning', project_id: projectId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    planDtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">Gagal memuat data planning.</div>';
                    planDtlDiv.style.display = 'block';
                    return;
                }
                currentPlanningRows = res.data || [];
                renderPlanningPanel(projectId, projectName);
            },
            failure: function () {
                planDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
                planDtlDiv.style.display = 'block';
            }
        });
    }

    function renderPlanningPanel(projectId, projectName) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        // Deteksi material ganda di RAB proyek (satu material seharusnya satu baris RAB)
        var seenMat = {}, dupNames = [];
        Ext.each(currentPlanningRows, function (r) {
            if (seenMat[r.material_id]) {
                if (dupNames.indexOf(r.material_name) < 0) dupNames.push(r.material_name);
            } else {
                seenMat[r.material_id] = true;
            }
        });
        var dupNotice = dupNames.length ?
            '<div style="margin:0 0 10px 0; padding:8px 12px; background:#3b2f0b; border:1px solid #8a6d1c; border-radius:4px; color:#f5c542; font-size:12px;">' +
            '&#9888; RAB proyek ini memuat material ganda (' + Ext.util.Format.htmlEncode(dupNames.join(', ')) +
            '). Rapikan di halaman RAB / data sebelum membuat PO agar kuota tidak terhitung dobel.</div>' : '';

        var html = '<h3 class="detail-title">Sisa Perlu Dibeli: ' +
                   Ext.util.Format.htmlEncode(projectName || '') + '</h3>' +
                   '<hr class="detail-hr" />' + dupNotice +
                   '<div id="planDtlScroll">' +
                   '<table id="planTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th width="30" align="center"></th>' +
                   '<th align="left">Material</th>' +
                   '<th align="right">Budget RAB</th>' +
                   '<th align="right">Sudah di-PO</th>' +
                   '<th align="right">Sisa Perlu Beli</th>' +
                   '<th align="right">Qty mau di-PO</th>' +
                   '<th align="right">Harga Satuan</th>' +
                   '<th align="right">Est. Nilai Sisa</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!currentPlanningRows || currentPlanningRows.length === 0) {
            html += '<tr><td colspan="8" style="padding:15px; text-align:center; color:#557982;">Belum ada RAB material untuk proyek ini.</td></tr>';
        } else {
            Ext.each(currentPlanningRows, function (r) {
                var remaining = parseFloat(r.qty_remaining) || 0;
                var isDone = remaining <= 0.0001;
                
                var chkHtml = isDone ? '' : '<input type="checkbox" class="planChk" data-rab-id="' + r.rab_material_id + '" />';

                html += '<tr class="data-row" data-rab-id="' + r.rab_material_id + '">' +
                        '<td align="center">' + chkHtml + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.material_name || '-') + ' <span style="color:#668894;">(' + Ext.util.Format.htmlEncode(r.material_code || '') + ')</span></td>' +
                        '<td align="right">' + fmtQty(r.budget_qty) + ' ' + Ext.util.Format.htmlEncode(r.unit || '') + '</td>' +
                        '<td align="right">' + fmtQty(r.qty_ordered) + '</td>' +
                        '<td align="right" style="font-weight:bold; color:' + (isDone ? '#2e9e4f' : '#e6a817') + ';">' + (isDone ? 'Lengkap' : fmtQty(remaining)) + '</td>' +
                        '<td align="right">' + (isDone ? '-' :
                            '<input type="number" class="planQty" data-rab-id="' + r.rab_material_id + '" value="' + remaining + '" min="0.0001" max="' + remaining + '" step="any" style="width:100px; text-align:right; background:#0d2133; color:#fff; border:1px solid #1a4f5c; border-radius:3px; padding:3px;" />'
                        ) + '</td>' +
                        '<td align="right">' + fmtAmt(r.unit_price) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(r.remaining_value) + '</td>' +
                        '</tr>';
            });
        }
        
        html += '</tbody>' +
                '</table></div>' +
                '<div style="margin-top:15px; text-align:right;">' +
                '<button id="btnCreatePoFromPlanning" type="button" style="padding:8px 16px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Buat PO dari Terpilih &rarr;</button>' +
                '</div>';

        planDtlDiv.innerHTML = html;
        planDtlDiv.style.display = 'block';
        fitPlanDtl();

        // Wire checkbox listeners
        var checkboxes = planDtlDiv.querySelectorAll('.planChk');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].onchange = function () {
                var rabId = this.getAttribute('data-rab-id');
                if (this.checked) {
                    selectedRabIds[rabId] = true;
                } else {
                    delete selectedRabIds[rabId];
                }
            };
        }

        // Auto-centang checkbox jika input Qty difokuskan/diubah
        var qtyInputs = planDtlDiv.querySelectorAll('.planQty');
        for (var j = 0; j < qtyInputs.length; j++) {
            qtyInputs[j].onfocus = function () {
                var rabId = this.getAttribute('data-rab-id');
                var chk = planDtlDiv.querySelector('.planChk[data-rab-id="' + rabId + '"]');
                if (chk && !chk.checked) {
                    chk.checked = true;
                    selectedRabIds[rabId] = true;
                }
            };
        }

        document.getElementById('btnCreatePoFromPlanning').onclick = function () {
            var chosen = [];
            var hasInvalidQty = false;
            Ext.each(currentPlanningRows, function (r) {
                if (selectedRabIds[r.rab_material_id]) {
                    var qtyInput = planDtlDiv.querySelector('.planQty[data-rab-id="' + r.rab_material_id + '"]');
                    var qtyToOrder = qtyInput ? parseFloat(qtyInput.value) : parseFloat(r.qty_remaining);
                    var maxRemaining = parseFloat(r.qty_remaining) || 0;

                    if (!qtyToOrder || qtyToOrder <= 0 || qtyToOrder > maxRemaining + 0.0001) {
                        hasInvalidQty = true;
                        return;
                    }

                    chosen.push({
                        budget_item_id: r.rab_material_id,
                        material_id: r.material_id,
                        description: r.material_name,
                        unit: r.unit,
                        qty: qtyToOrder,
                        unit_price: r.unit_price
                    });
                }
            });

            if (hasInvalidQty) {
                Ext.Msg.alert('Peringatan', 'Ada qty yang tidak valid (harus > 0 dan tidak boleh melebihi sisa perlu beli).');
                return;
            }
            if (chosen.length === 0) {
                Ext.Msg.alert('Peringatan', 'Centang minimal 1 material yang mau dimasukkan ke PO.');
                return;
            }

            sessionStorage.setItem('po_draft_from_planning', Ext.util.JSON.encode({
                project_id: projectId,
                items: chosen
            }));
            window.location.href = 'router?id=24';
        };
    }

    document.getElementById('editorWdw').style.display = 'none';
});