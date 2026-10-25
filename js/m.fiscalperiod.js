/*
  Document      : m.fiscalperiod.js
  Description   : Fiscal Period - Grid + Add Form + Close/Reopen action
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/m.fiscalperiodCRD.php (tabel accounting_periods)
=======
  Backend       : /rab-project/live/php-crud/m.fiscalperiodCRD.php (tabel accounting_periods)
>>>>>>> Stashed changes
  [FIX 2026-10-08]:
   - safeDecode(): respons non-JSON (mis. PHP fatal error) tidak lagi menampilkan "undefined".
   - Aksi close/reopen/delete memakai Ext.Ajax.request (cara lama via Store.load + operation.response
     tidak valid di ExtJS 3.4.1 sehingga pesan selalu "Selesai").
   - Store utama diberi listener 'exception'.
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Fiscal <em>Period</em>";

    var CRD = '../php-crud/m.fiscalperiodCRD.php';
    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // [FIX] decode JSON aman: kembalikan objek {success:false,message} bila respons bukan JSON
    function safeDecode(response) {
        try {
            return Ext.util.JSON.decode(response.responseText);
        } catch (e) {
            return { success: false, message: 'Respons server tidak valid (cek error_log PHP).' };
        }
    }

    // [FIX 3] Date -> 'YYYY-MM-DD' tanpa bergantung pada Date.prototype.format
    function toYmd(d) {
        if (!(d instanceof Date) || isNaN(d.getTime())) return '';
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    // [FIX] helper popup pesan
    function showPop(msg) {
        document.getElementById('popWdwTxt').innerHTML = msg || '';
        document.getElementById('popWdw').style.display = 'block';
    }

    // ============================================================
    // 1. Field definitions
    // ============================================================
    var periodField = [
        { name: 'id' }, { name: 'period_name' },
        { name: 'start_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'end_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'is_closed', type: 'boolean' }
    ];

    // ============================================================
    // 2. Main store & grid
    // ============================================================
    var mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, periodField),
        listeners: {
            // [FIX] wajib: tampilkan pesan bila muat data gagal
            exception: function () {
                showPop('Gagal memuat data periode dari server.');
            }
        }
    });
    mainDs.load();
    let mainHeight = (maxGridHeight(document.getElementById('divMainGrid')));

    var gridMain = new stdGridPaging({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,

        columns: [
            { header: 'Periode', dataIndex: 'period_name', width: 150, renderer: CellWrap },
            { header: 'Mulai', dataIndex: 'start_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'Selesai', dataIndex: 'end_date', width: 100, renderer: Ext.util.Format.dateRenderer('d M Y') },
            {
                header: 'Status', dataIndex: 'is_closed', width: 100, align: 'center',
                renderer: function (v) { return v ? '<span style="color:#c0392b;font-weight:bold;">Closed</span>' : '<span style="color:#2e9e4f;">Open</span>'; }
            }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                dataRecord = grid.getStore().getAt(rowIndex).data;
                mainBtnEnable('#deleteData');
                toggleCloseReopenButton();
            }
        },
        pageSize: 250,
        pageStore: mainDs
    });
    mainBtnDisable('#deleteData');

    // Fiscal Period tidak diedit isinya -- cuma bisa Add & Close/Reopen
    document.getElementById('editData').style.display = 'none';

    function toggleCloseReopenButton() {
        var btn = document.getElementById('closePeriod');
        if (!btn || !dataRecord) return;
        btn.style.display = 'block';
        btn.innerHTML = dataRecord.is_closed
            ? '<t>Reopen</t><i class="material-icons md-22 white">lock_open</i>'
            : '<t>Close</t><i class="material-icons md-22 white">lock</i>';
    }

    // ============================================================
    // 3. Editor Form (Add periode baru)
    // ============================================================
    var fldName = new myTextField({ fieldLabel: 'Nama Periode', id: 'period_name', name: 'period_name', allowBlank: false, emptyText: 'ex: 2026-08 atau Agustus 2026' });
    var fldStart = new myDateField({ fieldLabel: 'Tanggal Mulai', id: 'start_date', name: 'start_date', allowBlank: false });
    var fldEnd = new myDateField({ fieldLabel: 'Tanggal Selesai', id: 'end_date', name: 'end_date', allowBlank: false });
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 120,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 1, baseCls: 'x-plain' },
        items: [
            { items: fldName },
            { columnWidth: 0.5, items: fldStart },
            { columnWidth: 0.5, items: fldEnd },
            { items: fldId },
            { columnWidth: 1, items: FieldSpaceTopLabel }
        ]
    });
    frmEditor.on('clientvalidation', function (f, valid) { if (!valid) { saveEditDisable(); } else { saveEditEnable(); } });

    // ============================================================
    // 4. Toolbar
    // ============================================================
    document.getElementById('addData').onclick = function () {
        task = '';
        frmEditor.getForm().reset();
        if (getFormAccess() == 2 || getFormAccess() == 3 || getFormAccess() == 4) {
            task = 'create';
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Periode';
        } else {
            showPop('Anda tidak memiliki hak akses ke halaman ini.');
        }
    };

    var closeReopenBtn = document.getElementById('closePeriod');
    if (closeReopenBtn) {
        closeReopenBtn.onclick = function () {
            if (!dataRecord) {
                showPop('Pilih periode terlebih dahulu.');
                return;
            }
            if (getFormAccess() < 4) {
                showPop('Hanya akses tertinggi yang boleh menutup/membuka periode.');
                return;
            }
            var actionTask = dataRecord.is_closed ? 'reopen' : 'close';
            var confirmMsg = dataRecord.is_closed
                ? 'Buka kembali periode \'' + dataRecord.period_name + '\'?'
                : 'Tutup periode \'' + dataRecord.period_name + '\'? Setelah ditutup, jurnal baru tidak bisa masuk ke periode ini.';

            task = actionTask;
            document.getElementById('popCancel').style.display = 'block';
            showPop(confirmMsg);
        };
    }

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            showPop('Pilih data terlebih dahulu.');
            return;
        }
        if (dataRecord.is_closed) {
            showPop('Periode yang sudah ditutup tidak boleh dihapus.');
            return;
        }
        if (getFormAccess() == 4) {
            task = 'delete';
            document.getElementById('popCancel').style.display = 'block';
            showPop('Hapus periode \'' + dataRecord.period_name + '\' ?');
        } else {
            showPop('Anda tidak memiliki hak akses untuk menghapus data.');
        }
    };

    document.getElementById('printData').onclick = function (event) {
        window.open("../pdf/fiscalperiod.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    document.getElementById('saveEdit').onclick = function () {
        frmEditor.getForm().submit({
            url: CRD,
            method: 'POST',
            // [FIX 2] kirim tanggal eksplisit Y-m-d (field menampilkan 'd M y', bukan format DB)
            params: {
                task: task,
                start_ymd: toYmd(fldStart.getValue()),    // [FIX 3] jangan pakai Date.format (ditimpa library lain)
                end_ymd: toYmd(fldEnd.getValue())
            },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function (form, action) {
                var res = safeDecode(action.response);          // [FIX]
                // [FIX] server bisa membalas success:false dengan HTTP 200 -> tangani di sini
                if (res.success === false) {
                    showPop(res.message);
                    return;
                }
                showPop(res.message);
                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                // [FIX] action.response bisa undefined (client invalid / koneksi gagal)
                var res = action && action.response ? safeDecode(action.response)
                                                    : { message: 'Gagal menghubungi server atau form belum valid.' };
                showPop(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        if ((task == 'delete' || task == 'close' || task == 'reopen') && dataRecord) {
            var doTask = task;
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
            task = '';

            // [FIX] Ext.Ajax.request menggantikan Store.load + operation.response
            Ext.Ajax.request({
                url: CRD,
                method: 'POST',
                params: { task: doTask, id: dataRecord.id },
                success: function (response) {
                    var res = safeDecode(response);
                    showPop(res.message || 'Selesai');
                    if (res.success) {
                        dataRecord = null;
                        mainBtnDisable('#deleteData');
                        var btn = document.getElementById('closePeriod');
                        if (btn) btn.style.display = 'none';
                    }
                    mainDs.load();
                },
                failure: function () {
                    showPop('Gagal menghubungi server.');
                }
            });
        } else {
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
});