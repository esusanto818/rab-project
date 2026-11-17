# AUDIT GRID WRAP — Tagihan Klien (menu id 156)

**Tanggal:** 2026-11-17 · **Lingkup:** HANYA halaman Tagihan Klien (`views/project.invoice.php` + `js/project.invoice.js`) + CSS grid scoped halaman ini. **CSS global TIDAK diubah.**
**Halaman:** `views/router?id=156` → php_url `project.invoice.php`, js_url `project.invoice.js`.

---

## 1. Masalah (gejala)

Grid utama Tagihan Klien memotong teks dengan `...` (ellipsis) dan/atau memotong isinya tanpa tanda, sehingga kolom **No. Invoice, Dokumen Tagihan, Tanggal, Jatuh Tempo, DPP/PPN/Total/Terbayar/Sisa, Status** tidak selalu terbaca penuh — terutama nominal panjang (mis. `1.000.000.000,00`) dan jatuh tempo overdue (`2026-12-31 (+5 hr)`).

## 2. Akar masalah

ExtJS 3.4.1 (`css/ext-all.css`) memasang aturan global:

```css
.x-grid3-cell-inner,.x-grid3-hd-inner{
  overflow:hidden; -o-text-overflow:ellipsis; text-overflow:ellipsis;
  padding:3px 3px 3px 5px; white-space:nowrap
}
```

→ header & isi sel **selalu** `nowrap + ellipsis + clip`. `grddetail.css`/`mainCSS.css` tidak menghapus aturan ini (hanya warna/padding). Kolom Client/Proyek/Termin sudah memakai `CellWrap()` (`js-func/mainFunction.js:485`, `white-space:normal !important`), sedangkan kolom nomor invoice, tanggal, nominal, dan status **tidak** wrap. `viewConfig.forceFit:true` turut memaksa kolom menyusut agar pas lebar panel → memotong teks nominal/invoice.

## 3. Perubahan (hanya halaman Tagihan Klien)

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

## 4. Tidak diubah (scope)

- Tidak ada perubahan `css/ext-all.css`, `css/grddetail.css`, `css/mainCSS.css` (aturan global memakai 39+ view lintas modul: Pembayaran Termin, Dashboard, Warehouse Issuing, PO, GRN, dsb.).
- Tidak ada perubahan modul lain / backend `project.invoiceCRD.php` / DB.

## 5. Temuan di luar scope (dicatat, TIDAK diperbaiki)

- Modul lain yang memakai `grddetail.css` juga berpotensi terpotong karena rule `ext-all.css` yang sama (contoh: `project.payment.js` `forceFit:true`, `po.js` `forceFit:true`, dashboard, gudang). Bila diminta di gelombang berikutnya, terapkan pola override scoped `<container> .x-grid3-cell-inner{...}` yang sama per halaman (atau buat `css/grid-wrap.css` yang di-link per halaman) — **jangan** ubah `ext-all.css` global tanpa regresi penuh.
- `.detail-table` (panel detail invoice) tidak punya `white-space:nowrap`/ellipsis → tidak dipotong; tidak diubah.
- Panel detail / editor (form) tidak termasuk scope grid; tidak diubah.

## 6. Cara verifikasi manual

1. Buka `http://127.0.0.1:8098/views/router?id=156` (login bila diminta).
2. Menu TAGIHAN KLIEN → grid menampilkan invoice. Pastikan **header & isi sel** (No. Invoice, Dokumen Tagihan, Tanggal, Jatuh Tempo, Client, Proyek, Termin, DPP, PPN, Total Tagihan, Terbayar, Sisa, Status) terbaca utuh; tidak ada `...`.
3. Nominal panjang (mis. `1.000.000.000,00`) dan Jatuh Tempo overdue (`+N hr`) harus tampil penuh (boleh wrap 2 baris) atau grid scroll horizontal — bukan dipotong.
4. Angka tetap rata kanan, tanggal & status center, teks kiri.

## 7. Catatan model / sub-agent

Percobaan delegasi ke sub-agent `grid-wrap-auditor` **gagal** (model error). Seluruh pekerjaan audit-wrap ini dilakukan manual di thread utama dengan scope ketat sesuai permintaan: hanya halaman Tagihan Klien. Menu id 156 = `project.invoice.php` / `project.invoice.js`.

## 8. Status Git

- Semua perubahan **Uncommitted**. Tidak ada commit dibuat.
- Verifikasi: `php -l views/project.invoice.php` bersih; `node --check js/project.invoice.js` lolos. Tidak menjalankan E2E (audit murni tampilan).
