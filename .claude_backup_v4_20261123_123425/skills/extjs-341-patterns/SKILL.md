---
name: extjs-341-patterns
description: Standar wajib dan daftar anti-pattern ExtJS 3.4.1 pada proyek RAB-Project agar UI tidak blank atau error runtime.
---

# ExtJS 3.4.1 Strict Patterns & Anti-Patterns

Setiap pembuatan atau modifikasi file `js/*.js` WAJIB mematuhi aturan berikut (pelanggaran menyebabkan layar kosong / JS crash):

1. **Class Grid Editor yang Valid:**
   - WAJIB menggunakan `Ext.grid.EditorGridPanel` atau wrapper proyek `stdGridEditor`.
   - **DILARANG KERAS** mengarang class `Ext.grid.EditorGridModel` (class ini tidak ada di ExtJS 3.4.1 dan mematikan seluruh `Ext.onReady`).

2. **CheckColumn Event Handling:**
   - `Ext.grid.CheckColumn` di ExtJS 3.4.1 **TIDAK** memiliki event `listeners: { click: ... }`.
   - Toggle checkbox dan sinkronisasi nilai kolom lain (misal `active` <-> `access_level`) WAJIB ditangani via event `cellclick` pada GridPanel atau `processEvent` yang valid.

3. **Container Modal & Window:**
   - Jangan meletakkan `div` popup/modal tanpa CSS `position: fixed; z-index: 9999` atau biarkan tersembunyi (`display: none`) di akhir `Ext.onReady` agar tidak menutupi grid utama.
   - Selalu sediakan tombol aksi yang terlihat jelas di grid/toolbar untuk membuka detail/sub-form (jangan hanya mengandalkan `rowdblclick` atau ikon tersembunyi).

4. **CSS Word-Wrap Scoped ke ID Nyata:**
   - Targetkan CSS wrap ke ID/class yang benar-benar ada di `renderTo` (contoh: `#gridEditor .x-grid3-hd-inner, .x-grid3-gridEditor .x-grid3-hd-inner`).
   - Dilarang menargetkan `#editorFormGrid` jika tidak ada elemen dengan ID tersebut di DOM.

5. **Deklarasi Variabel & Store Exception:**
   - Deklarasikan semua `var` di baris paling atas fungsi sebelum dipakai (hindari hoisting bug).
   - Setiap `Ext.data.Store` wajib memiliki listener `exception` yang menampilkan pesan error ke user.
   - Dilarang hardcode path `../dev/` atau `/rab-project/`; gunakan variabel global `main_url`.
