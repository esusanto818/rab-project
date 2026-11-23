---
name: extjs-341-patterns
description: Pola baku komponen frontend ExtJS 3.4.1 (GridPanel, JsonStore, ComboBox, Window, FormPanel) untuk mencegah sintaks ExtJS versi modern (Ext.define). Gunakan HANYA untuk file yang memang halaman ExtJS (`js/*.js` dan view yang memuat `Ext.*`). JANGAN dipakai untuk halaman Tailwind + JS biasa seperti `views/dashboard.php`.
---

# Standar & Pola Baku ExtJS 3.4.1 (`rab-project`)

> Cakupan: hanya file yang sudah memakai ExtJS. Halaman non-ExtJS (mis. `views/dashboard.php`: Tailwind + `fetch` + Chart.js) mengikuti pola file itu sendiri; jangan dikonversi ke ExtJS.

1. **Larangan Sintaks ExtJS 4+**:
   - DILARANG menggunakan `Ext.define()`, `Ext.create()`, `Ext.data.Model`, atau `proxy: { type: 'ajax' }`.
   - Wajib gunakan sintaks ExtJS 3.4.1 murni: `new Ext.grid.GridPanel({...})`, `new Ext.grid.EditorGridPanel({...})`, `new Ext.data.JsonStore({...})`, `new Ext.Window({...})`.
2. **Konfigurasi Wajib `Ext.data.JsonStore`**:
   - Wajib mendefinisikan `url`, `root: 'data'`, `totalProperty: 'totalCount'`, `idProperty`, dan array `fields`.
   - Wajib memasang `listeners: { exception: function(proxy, type, action, options, res) { ... } }` untuk menangkap kegagalan respon JSON backend.
3. **Manipulasi Form & Grid Aman**:
   - Cek null sebelum memanggil `.getSelectionModel().getSelected()` pada aksi Edit/Hapus.
   - Gunakan `Ext.util.JSON.encode(arrDetail)` saat mengirim data detail bertingkat melalui parameter `Ext.Ajax.request`.
   - Bersihkan referensi window/store saat ditutup (`closeAction: 'close'`) agar tidak terjadi kebocoran memori browser.
