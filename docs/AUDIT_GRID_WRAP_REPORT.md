# AUDIT GRID WRAP REPORT — Teks Grid/Tabel

Laporan ini menggabungkan dua gelombang audit: (A) modul Tagihan Klien, (B) modul Pembayaran Termin & Purchase Order (PO). Keduanya memakai pola yang sama: **override CSS scoped per container grid**, `forceFit:true → false`, pelebaran kolom; CSS global TIDAK diubah.

---

# BAGIAN A — Tagihan Klien (menu id 156)

**Tanggal:** 2026-11-17 · **Lingkup:** HANYA halaman Tagihan Klien (`views/project.invoice.php` + `js/project.invoice.js`) + CSS grid scoped halaman ini. **CSS global TIDAK diubah.**
**Halaman:** `views/router?id=156` → php_url `project.invoice.php`, js_url `project.invoice.js`.
**Perbaikan:** commit `3e68211` (committed).

## A.1 Masalah (gejala)

Grid utama Tagihan Klien memotong teks dengan `...` (ellipsis) dan/atau memotong isinya tanpa tanda, sehingga kolom **No. Invoice, Dokumen Tagihan, Tanggal, Jatuh Tempo, DPP/PPN/Total/Terbayar/Sisa, Status** tidak selalu terbaca penuh — terutama nominal panjang (mis. `1.000.000.000,00`) dan jatuh tempo overdue (`2026-12-31 (+5 hr)`).

## A.2 Akar masalah

ExtJS 3.4.1 (`css/ext-all.css`) memasang aturan global:

```css
.x-grid3-cell-inner,.x-grid3-hd-inner{
  overflow:hidden; -o-text-overflow:ellipsis; text-overflow:ellipsis;
  padding:3px 3px 3px 5px; white-space:nowrap
}
```

→ header & isi sel **selalu** `nowrap + ellipsis + clip`. `grddetail.css`/`mainCSS.css` tidak menghapus aturan ini (hanya warna/padding). Kolom Client/Proyek/Termin sudah memakai `CellWrap()` (`js-func/mainFunction.js:485`, `white-space:normal !important`), sedangkan kolom nomor invoice, tanggal, nominal, dan status **tidak** wrap. `viewConfig.forceFit:true` turut memaksa kolom menyusut agar pas lebar panel → memotong teks nominal/invoice.

## A.3 Perubahan (hanya halaman Tagihan Klien)

### `js/project.invoice.js`

**a. `injectCss()`** — tambahkan override **SCOPED** ke grid `#divMainGrid` saja (tidak menyentuh `.x-grid3-cell-inner` global/halaman lain):

```css
#divMainGrid .x-grid3-hd-inner,#divMainGrid .x-grid3-cell-inner{
  white-space:normal; overflow:visible;
  text-overflow:clip; -o-text-overflow:clip;
  word-wrap:break-word; overflow-wrap:break-word; word-break:break-word;
  line-height:1.25; vertical-align:middle;
}
#divMainGrid .x-grid3-hd-inner{ vertical-align:middle; padding:4px 5px; }
#divMainGrid .x-grid3-cell-inner{ padding:3px 5px; }
```

Text-align kolom TIDAK dipaksa (mengikuti ColumnModel: rata kanan angka, center tanggal/status, kiri teks).

**b. `viewConfig`** — `forceFit:true` → **`forceFit:false`** + `scrollOffset:0`. forceFit memaksa semua kolom menyusut supaya muat satu baris; dengan `forceFit:false` grid memakai lebar kolom definisi + scroll horizontal bila perlu, sehingga teks **wrap** alih-alih dipotong.

**c. Lebar kolom** (muat konten khas, tetap rapi):

| Kolom | Sebelum | Sesudah | Alasan |
|---|---|---|---|
| No. Invoice | 110 | 130 | `INV-2611-00057` |
| Dokumen Tagihan | 150 | 145 | `TAG-...` + tanggal 2 baris sudah muat |
| Tanggal | 82 | 90 | `DD/MM/YYYY` |
| Jatuh Tempo | 105 | 120 | memuat `(+N hr)` overdue |
| Client | 150 | 160 | nama client panjang |
| Proyek | 180 | 190 | nama kontrak panjang |
| Termin | 110 | 130 | nama termin panjang |
| DPP | 105 | 130 | `1.000.000.000,00` |
| PPN | 90 | 110 | nominal PPN |
| Total Tagihan | 115 | 140 | total + bold |
| Terbayar | 105 | 130 | nominal |
| Sisa | 105 | 130 | nominal |
| Status | 90 | 105 | `Belum Bayar` / `Sebagian` |

Alignment angka (rata kanan) & status (center) dipertahankan; hanya lebar/white-space yang berubah.

### `views/project.invoice.php`

Tidak diubah (grid & CSS seluruhnya di-JS; view hanya shell `<head>` + include CSS). Scope dipenuhi lewat JS.

## A.4 Tidak diubah (scope)

- Tidak ada perubahan `css/ext-all.css`, `css/grddetail.css`, `css/mainCSS.css` (aturan global memakai 39+ view lintas modul).
- Tidak ada perubahan modul lain / backend `project.invoiceCRD.php` / DB.

## A.5 Temuan di luar scope (dicatat saat gelombang A)

- Modul lain yang memakai `grddetail.css` juga berpotensi terpotong karena rule `ext-all.css` yang sama (diantaranya `project.payment.js` `forceFit:true`, `po.js` `forceFit:true` — **diperbaiki pada gelombang B, lihat di bawah**).
- `.detail-table` (panel detail invoice) tidak punya `white-space:nowrap`/ellipsis → tidak dipotong; tidak diubah.

## A.6 Cara verifikasi manual (modul invoice)

1. Buka `http://127.0.0.1:8098/views/router?id=156` (login bila diminta).
2. Menu TAGIHAN KLIEN → grid menampilkan invoice. Pastikan **header & isi sel** (No. Invoice, Dokumen Tagihan, Tanggal, Jatuh Tempo, Client, Proyek, Termin, DPP, PPN, Total Tagihan, Terbayar, Sisa, Status) terbaca utuh; tidak ada `...`.
3. Nominal panjang (mis. `1.000.000.000,00`) dan Jatuh Tempo overdue (`+N hr`) harus tampil penuh (boleh wrap 2 baris) atau grid scroll horizontal — bukan dipotong.
4. Angka tetap rata kanan, tanggal & status center, teks kiri.

---

# BAGIAN B — Pembayaran Termin & Purchase Order (PO)

**Tanggal:** 2026-11-17
**Lingkup:** modul Pembayaran Termin (`views/project.payment.php` + `js/project.payment.js`, print `views/project.payment.print.php` / `views/project.payment.list.print.php` + JS print) dan modul Purchase Order (`views/po.php` + `js/po.js`).
**Pola acuan:** commit `3e68211` (bagian A di atas) — override CSS **scoped per container grid**, `forceFit:true → false`, pelebaran `min-width` kolom; CSS global TIDAK diubah. Perubahan di bagian B ini masih **Uncommitted**.

## B.1 Masalah (gejala)

Di modul Pembayaran Termin dan PO, header & isi sel grid terpotong `...` (ellipsis) karena:

1. ExtJS 3.4.1 (`css/ext-all.css`) memasang aturan global
   `.x-grid3-cell-inner,.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}`
   → header & sel selalu nowrap + ellipsis.
2. `viewConfig: { forceFit: true }` pada grid utama & editor memaksa kolom menyusut agar muat lebar panel → teks/nominal panjang terpotong.
3. `js/project.payment.js` punya override header scoped LAMA yang mengunci tinggi: `.x-grid3-hd-inner{... height:36px !important; ...}` — header 2 baris tetap terkunci & terpotong.
4. Kolom nominal uang (DPP/PPN/Total/Teralokasi/Lebih Bayar/Deposit) & nomor dokumen (`PPAY-`/`PO-`) lebarnya tidak muat format `1.000.000.000,00`.

## B.2 Grid yang diaudit

| File | Grid / Container | Temuan |
|---|---|---|
| `js/project.payment.js` | gridMain `renderTo:'divMainGrid'` (cls `.ppMainGrid`), `viewConfig forceFit:true` | Header kunci `height:36px !important`; kolom nominal (`Jumlah Diterima` 120, `Total Diterima` 130, `Teralokasi` 120, `Lebih Bayar` 100, `Deposit Dipakai` 110) & `No. Pembayaran` 130 berpotensi terpotong; forceFit memaksa kolom menyusut. |
| `js/project.payment.js` | editor grid `renderTo:'editorFormGrid'` (`stdGridEditor`), `forceFit:true` | Header panjang (`Tagihan incl. PPN (IDR)`, `Sisa Tagihan (IDR)`, `Bayar Sekarang`) & nominal 110–120 terpotong; `Tgl Termin` 85 kurang muat `DD/MM/YYYY`; `No. Dokumen` (`TAG-...`) 100 kurang. |
| `js/project.payment.js` | deposit history `ppDepositHistory` (window grid) | `forceFit:true`; kolom 120–130; `Referensi` belum wrap; header `Lebih Bayar (+)`/`Dipakai (-)` berpotensi terpotong. |
| `js/po.js` | gridMain daftar PO `renderTo:'divMainGrid'`, `forceFit:true` | Kolom `DPP` 115 / `PPN` 100 / `Total` 120 / `Setara Anggaran` 150 (fmt + kode anggaran) berpotensi terpotong; `No. PO` 130 kurang muat `PO-2611-00012`. |
| `js/po.js` | `itemGrid` editor item PO `renderTo:'editorFormGrid'`, `forceFit:true` | `Material (RAB proyek)` 230, `Sisa Nilai RAB` 110, `Harga Satuan` 100, `Subtotal` 110, `Setara Anggaran` 110, `Sumber` 95 ('Titipan Klien') berpotensi terpotong; header `<br/>` (`Stok<br/>Tersedia`, `Perlu<br/>Beli`) di-lock ext-all nowrap. |
| `js/po.js` | panel detail `dtlDiv` (HTML table `.detail-table`) | Kolom Material (deskripsi panjang) tidak wrap eksplisit → berpotensi dorong lebar tabel detail. |
| `views/project.payment.print.php` | tanda terima cetak (`table.items`) | `.r-al{white-space:nowrap}` pada `td` juga → nama proyek/termin panjang terpotong saat cetak (A4 portrait). |
| `views/project.payment.list.print.php` | daftar pembayaran cetak | Hanya `.num{white-space:nowrap}` pada angka; `th/td` umum tidak punya wrap eksplisit → proyek/client panjang berpotensi terpotong. |
| `js/purchase.order.js` (menu legacy, `views/purchase.order.php`) | `GridDtl`/`gridEditor`/`gridCosts`/`gridAddEditor`/main | Sudah memakai `CellWrap` di hampir semua kolom teks; `stdGrid*/stdGridEditor` (`js-func/ExtExtend.js`) tetap `forceFit:true` global — tidak diubah agar tidak merusak lusinan modul lain yang memakai helper yang sama. Tercatat sebagai temuan luar scope. |

## B.3 Perbaikan yang diterapkan

### `js/project.payment.js`
- **`injectHeaderCss()` (sekitar baris 271–292)** — mengganti override LAMA yang mengunci `height:36px !important` dengan override **scoped** ke `.ppMainGrid`, `#editorFormGrid`, dan `.ppDepGrid`:
  - `.x-grid3-hd-inner`: `white-space:normal !important; overflow:visible !important; text-overflow:clip !important; word-wrap/overflow-wrap/word-break:break-word !important; height:auto !important; min-height:34px; line-height:1.3` — header boleh 2 baris rapi; `text-align` TIDAK dipaksa (ikuti ColumnModel).
  - `.x-grid3-cell-inner`: `white-space:normal !important; overflow:visible; text-overflow:clip; height:auto !important; line-height:1.35` — sel data wrap; align angka kanan tetap dihormati karena hanya `white-space` yang berubah.
  - `.x-grid3-row td` / `.x-grid3-hd` / `.x-grid3-header-offset`: `height:auto !important` agar baris bertambah tinggi saat wrap.
- **gridMain**: `viewConfig { forceFit:true }` → **`{ forceFit:false, scrollOffset:0 }`**; lebar kolom: `No. Pembayaran` 130→140, `Tanggal` 90→95, `Metode` 100→110, `Akun` 140→150, `Mata Uang` 70→75, `Jumlah Diterima` 120→140, `Total Diterima (IDR)` 130→150, `Teralokasi (IDR)` 120→140, `Lebih Bayar (IDR)` 100→120, `Deposit Dipakai (IDR)` 110→130, `Referensi` 120→130.
- **editor grid alokasi termin**: `forceFit:true` → **`false`**; `Proyek` 170→175, `Termin` 150→155, `No. Invoice` 110→125, `No. Dokumen` 100→120, `Tgl Termin` 85→92, `%` 50→55, `Tagihan incl. PPN (IDR)` 110→140, `Terbayar (IDR)` 110→135, `Sisa Tagihan (IDR)` 110→140, `Bayar Sekarang` 120→140, `Setara IDR` 110→135.
- **deposit history window**: `cls:'ppDepGrid'` (scope CSS), `forceFit:true` → **`false`**, `Tanggal` 90→95, `No. Pembayaran` 130→140, `Referensi` 120→130, nominal 120→130.

### `js/po.js`
- **IIFE `injectGridWrapCss()` baru (setelah baris 123)** — override scoped ke `#divMainGrid` & `#editorFormGrid` (aturan sama seperti di atas: wrap + clip + `height:auto` pada hd-inner/cell-inner/row td/header-offset). Tidak menyentuh CSS global.
- **gridMain daftar PO**: `viewConfig { forceFit:true }` → **`{ forceFit:false, scrollOffset:0 }`**; `No. PO` 130→145, `Tanggal` 90→95, `Mata Uang` 80→85, `DPP` 115→130, `PPN` 100→115, `Total` 120→135, `Setara Anggaran` 150→170, `Status` 95→105.
- **itemGrid editor PO**: `forceFit:true` → **`false`**; `Material (RAB proyek)` 230→240, `Sisa Kuota Qty` 90→100, `Sumber` 95→105, `Sisa Nilai RAB` 110→125, `Harga Satuan` 100→115, `Subtotal` 110→125, `Setara Anggaran` 110→130.
- **panel detail (`renderDetailPanel`)**: `<td>` deskripsi material ditambah inline `white-space:normal !important; overflow-wrap:break-word; word-wrap:break-word;` (pola sama dengan `CellWrap`) — tidak ada logika yang berubah.

### `views/project.payment.print.php` & `views/project.payment.list.print.php`
- Tambah aturan scoped pada `<style>` halaman: `table.items td, table.items th` (dan `table td/th` untuk list print) → `white-space:normal; overflow-wrap:break-word; word-wrap:break-word; line-height:1.35;`, dengan pengecualian `td.r-al` / `td.num` tetap `white-space:nowrap` agar angka tidak patah.

### Tidak diubah (scope)
- `css/ext-all.css`, `css/grddetail.css`, `css/mainCSS.css`, `css/frm_err1.css` (aturan global memakai 39+ view lintas modul).
- `js-func/ExtExtend.js` (`stdGrid*` helper) — forceFit-nya dipakai lusinan modul; perubahan global berisiko regresi.
- `js/purchase.order.js` (menu legacy `views/purchase.order.php`) — sudah `CellWrap` di hampir semua kolom; tercatat sebagai temuan luar scope.
- Backend (`php-crud/project.paymentCRD.php`, `poCRD.php`) & logika bisnis JS: TIDAK ada perubahan. Hanya presentasi (CSS, `viewConfig`, `width`, header text).

## B.4 Verifikasi

- `node --check js/po.js` → OK; `node --check js/project.payment.js` → OK; `node --check js/project.payment.print.js` → OK; `node --check js/project.payment.list.print.js` → OK.
- `php -l views/project.payment.print.php` → bersih; `php -l views/project.payment.list.print.php` → bersih.
- Tidak ada `echo`/`debug` baru; kompatibel PHP 7.4 (hanya view shell, tidak ada sintaks PHP baru).
- Verifikasi manual yang disarankan: buka halaman Pembayaran Termin & PO, pastikan header (mis. `Tagihan incl. PPN (IDR)`, `Setara Anggaran`, `Deposit Dipakai (IDR)`) & sel (`PPAY-2611-00012`, `1.000.000.000,00`, `PO-2611-00012`, `TAG-...`, nama proyek/supplier panjang) terbaca utuh — wrap 2 baris bila perlu atau scroll horizontal, bukan ellipsis; angka tetap rata kanan; tanggal/status center.

## B.5 Status Git

Perubahan gelombang B (bagian ini) **Uncommitted** (sesuai instruksi: JANGAN commit). Perbaikan gelombang A (`js/project.invoice.js`) sudah committed di `3e68211`.

## B.6 Catatan model / sub-agent

Percobaan awal penulisan laporan sempat menimpa file report invoice (untracked) dengan versi gabungan WIP; hal itu diperbaiki dengan menulis ulang file gabungan ini (bagian A dikembalikan dari isi asli yang dibaca sebelum tertimpa). Tidak ada commit dibuat.
