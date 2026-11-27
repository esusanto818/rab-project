/*
  Document      : purchase.planning.js
  Description   : Laporan sisa Budget RAB yang belum di-PO-kan. Grid
                   utama daftar Proyek, klik baris -> panel di bawah
                   nunjukin rincian per material (Budget, Sudah di-PO,
                   Sisa perlu dibeli). Bisa centang beberapa baris lalu
                   klik "Buat PO dari Terpilih" -> lempar data ke
                   halaman PO (po.php, router?id=24) lewat sessionStorage.
  Backend       : /rab-project/dev/php-crud/purchase.planningCRD.php
  [INVENTORY]   : Tiap material ditampilkan stok yang tersedia untuk proyek
                   ini (qty_on_hand: gudang milik proyek + gudang bersama;
                   stok milik proyek LAIN tidak dihitung) dan qty_to_buy =
                   sisa perlu beli dikurangi stok. Tombol "Cek Inventory"
                   memvalidasi pilihan sebelum dibawa ke PO.
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
                        return '<span >-- belum ada RAB --</span>';
                    }
                    if (v > 0) {
                        return '<span style="font-weight:bold">' + v + ' dari ' + totalRab + '</span>';
                    }
                    return '<span >0 (lengkap, ' + totalRab + ' material)</span>';
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
        /* [CSS-UNIFIED] Style node dipindahkan ke css/app-theme.css */
    }

    // Slider (scrollbar) untuk tabel rincian + header tabel tetap terlihat saat di-scroll
    if (!document.getElementById('planScrollStyle')) {
        /* [CSS-UNIFIED] Style node dipindahkan ke css/app-theme.css */
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
                    planDtlDiv.innerHTML = '<div style="padding:10px">Gagal memuat data planning.</div>';
                    planDtlDiv.style.display = 'block';
                    return;
                }
                currentPlanningRows = res.data || [];
                renderPlanningPanel(projectId, projectName);
            },
            failure: function () {
                planDtlDiv.innerHTML = '<div style="padding:10px">Gagal terhubung ke server.</div>';
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
            '<div style="margin:0 0 10px 0; padding:8px 12px; ; ; font-size:12px">' +
            '&#9888; RAB proyek ini memuat material ganda (' + Ext.util.Format.htmlEncode(dupNames.join(', ')) +
            '). Rapikan di halaman RAB / data sebelum membuat PO agar kuota tidak terhitung dobel.</div>' : '';

        // [CLIENT-SUPPLIED F-4.1] banner bila ada material titipan klien: tidak dibeli via PO (qty_to_buy 0, Rp 0)
        var csNotice = '';
        var csNames = [];
        Ext.each(currentPlanningRows || [], function (r) {
            if (String(r.material_source || '').toUpperCase() === 'CLIENT_SUPPLIED') { csNames.push(r.material_name); }
        });
        if (csNames.length) {
            csNotice = '<div style="margin:0 0 10px 0; padding:8px 12px; ; ; font-size:12px">' +
                       '&#9432; ' + csNames.length + ' material merupakan <b>Titipan Klien</b> (' +
                       Ext.util.Format.htmlEncode(csNames.slice(0, 3).join(', ')) + (csNames.length > 3 ? ', …' : '') +
                       '): tidak dibeli via PO (qty_to_buy = 0, Rp 0), jadi tidak dapat dicentang di sini.</div>';
        }

        // [INVENTORY] ringkasan ketersediaan stok untuk header panel
        var stockNotice = '';
        var stockShort = [];
        Ext.each(currentPlanningRows || [], function (r) {
            var onHand = parseFloat(r.qty_on_hand) || 0;
            var toBuy  = parseFloat(r.qty_to_buy) || 0;
            if (onHand > 0.0001 && toBuy <= 0.0001) stockShort.push(r.material_name);
        });
        if (stockShort.length) {
            stockNotice = '<div style="margin:0 0 10px 0; padding:8px 12px; ; ; font-size:12px">' +
                          '&#10003; ' + stockShort.length + ' material sudah cukup dari stok sendiri (' +
                          Ext.util.Format.htmlEncode(stockShort.slice(0, 3).join(', ')) + (stockShort.length > 3 ? ', …' : '') +
                          '), tidak perlu dibeli lagi.</div>';
        }

        var html = '<h3 class="detail-title">Sisa Perlu Dibeli: ' +
                   Ext.util.Format.htmlEncode(projectName || '') + '</h3>' +
                   '<hr class="detail-hr" />' + dupNotice + stockNotice + csNotice +
                   '<div id="planDtlScroll">' +
                   '<table id="planTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th width="30" align="center"></th>' +
                   '<th align="left">Material</th>' +
                   '<th align="center" title="Sumber material: Perusahaan (dibeli via PO) / Titipan Klien (Rp 0, tidak dibeli via PO)">Sumber</th>' +
                   '<th align="right">Budget RAB</th>' +
                   '<th align="right">Sudah di-PO</th>' +
                   '<th align="right">Sisa Perlu Beli</th>' +
                   '<th align="right" title="Stok di gudang proyek sendiri + gudang bersama (bukan milik proyek lain)">Stok Tersedia</th>' +
                   '<th align="right" title="Sisa perlu beli dikurangi stok tersedia">Perlu Beli (Net)</th>' +
                   '<th align="right">Qty mau di-PO</th>' +
                   '<th align="right">Harga Satuan</th>' +
                   '<th align="right">Est. Nilai Sisa</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!currentPlanningRows || currentPlanningRows.length === 0) {
            html += '<tr><td colspan="11" style="padding:15px; text-align:center">Belum ada RAB material untuk proyek ini.</td></tr>';
        } else {
            Ext.each(currentPlanningRows, function (r) {
                var remaining = parseFloat(r.qty_remaining) || 0;
                var onHand    = parseFloat(r.qty_on_hand) || 0;
                var toBuy     = parseFloat(r.qty_to_buy) || 0;
                var isDone    = remaining <= 0.0001;
                // [INVENTORY] material stoknya sudah mencukupi -> tidak perlu dibeli, checkbox di-nonaktifkan
                var noNeed    = !isDone && remaining > 0.0001 && toBuy <= 0.0001;
                // [CLIENT-SUPPLIED F-4.1] titipan klien: tidak dibeli via PO -> checkbox & input qty di-nonaktifkan
                var isClient  = String(r.material_source || '').toUpperCase() === 'CLIENT_SUPPLIED';
                var srcBadge  = isClient
                    ? '<span style="font-weight:bold" title="Material titipan klien: tidak dibeli via PO (qty_to_buy = 0, Rp 0)">&#9679; Titipan Klien</span>'
                    : '<span >Perusahaan</span>';

                var chkHtml = (isDone || noNeed || isClient) ? '' : '<input type="checkbox" class="planChk" data-rab-id="' + r.rab_material_id + '" />';

                html += '<tr class="data-row" data-rab-id="' + r.rab_material_id + '">' +
                        '<td align="center">' + chkHtml + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.material_name || '-') + ' <span >(' + Ext.util.Format.htmlEncode(r.material_code || '') + ')</span></td>' +
                        '<td align="center">' + srcBadge + '</td>' +
                        '<td align="right">' + fmtQty(r.budget_qty) + ' ' + Ext.util.Format.htmlEncode(r.unit || '') + '</td>' +
                        '<td align="right">' + fmtQty(r.qty_ordered) + '</td>' +
                        '<td align="right" style="font-weight:bold">' + (isDone ? 'Lengkap' : fmtQty(remaining)) + '</td>' +
                        '<td align="right" >' + fmtQty(onHand) + '</td>' +
                        '<td align="right" style="font-weight:bold">' + fmtQty(toBuy) + '</td>' +
                        '<td align="right">' + (isDone || noNeed || isClient ? '<span >' + (isDone ? 'Lengkap' : (isClient ? 'Titipan Klien' : 'Cukup stok')) + '</span>' :
                            '<input type="number" class="planQty" data-rab-id="' + r.rab_material_id + '" value="' + toBuy + '" min="0.0001" max="' + remaining + '" step="any" style="width:100px; text-align:right; ; ; padding:3px" />'
                        ) + '</td>' +
                        '<td align="right">' + fmtAmt(r.unit_price) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(r.remaining_value) + '</td>' +
                        '</tr>';
            });
        }

        html += '</tbody>' +
                '</table></div>' +
                '<div style="margin-top:15px; text-align:right">' +
                '<button id="btnCheckInventory" type="button" style="padding:8px 16px; ; ; cursor:pointer; font-weight:bold; margin-right:8px">Cek Inventory</button>' +
                '<button id="btnCreatePoFromPlanning" type="button" style="padding:8px 16px; ; ; cursor:pointer; font-weight:bold">Buat PO dari Terpilih &rarr;</button>' +
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

        // [INVENTORY] Tombol Cek Inventory: validasi pilihan ke backend sebelum ke PO.
        document.getElementById('btnCheckInventory').onclick = function () {
            var picked = [];
            Ext.each(currentPlanningRows, function (r) {
                if (selectedRabIds[r.rab_material_id]) {
                    var qtyInput = planDtlDiv.querySelector('.planQty[data-rab-id="' + r.rab_material_id + '"]');
                    var qty = qtyInput ? parseFloat(qtyInput.value) : parseFloat(r.qty_to_buy) || 0;
                    if (qty > 0) {
                        picked.push({ rab_material_id: r.rab_material_id, material_id: r.material_id, qty: qty });
                    }
                }
            });
            if (!picked.length) {
                Ext.Msg.alert('Peringatan', 'Centang minimal 1 material yang mau diperiksa inventory-nya.');
                return;
            }
            Ext.Ajax.request({
                url: CRD,
                method: 'POST',
                params: { task: 'check_inventory', project_id: projectId, items: Ext.util.JSON.encode(picked) },
                success: function (response) {
                    var res = Ext.util.JSON.decode(response.responseText);
                    if (!res.success) {
                        Ext.Msg.alert('Gagal', res.message || 'Gagal memeriksa inventory.');
                        return;
                    }
                    var bad = [];
                    Ext.each(res.data || [], function (row) {
                        if (!row.ok) bad.push(row.message);
                    });
                    if (!bad.length) {
                        Ext.Msg.alert('Inventory OK', 'Semua material terpilih tersedia di stok proyek / tidak perlu dibeli. Lanjutkan ke "Buat PO dari Terpilih".');
                    } else {
                        Ext.Msg.alert('Hasil Cek Inventory', bad.join('<br/>'));
                    }
                },
                failure: function () {
                    Ext.Msg.alert('Peringatan', 'Gagal terhubung ke server untuk pemeriksaan inventory.');
                }
            });
        };

        document.getElementById('btnCreatePoFromPlanning').onclick = function () {
            var chosen = [];
            var hasInvalidQty = false;
            Ext.each(currentPlanningRows, function (r) {
                if (selectedRabIds[r.rab_material_id]) {
                    var qtyInput = planDtlDiv.querySelector('.planQty[data-rab-id="' + r.rab_material_id + '"]');
                    // [INVENTORY] default qty = kebutuhan bersih (sisa perlu beli - stok tersedia), bukan sisa RAB penuh
                    var qtyToOrder = qtyInput ? parseFloat(qtyInput.value) : (parseFloat(r.qty_to_buy) || 0);
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