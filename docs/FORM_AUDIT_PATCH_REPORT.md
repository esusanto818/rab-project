# Laporan Audit & Patching Form + Toolbar

- **Waktu:** 2026-11-27 10:37:54
- **Total Patch Diterapkan:** `1`
- **Sisa Syntax Error:** `29`

## Rincian Audit & Perbaikan
- **[PATCH]** Menggabungkan layout dasar (`mainCSS`, `header.black`, `grddetail`, `frm`) & `Material Icons` ke `css/app-theme.css`.
- **[ERROR]** `js/ap.aging.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/ap.aging.js:250 | }); | ^`
- **[ERROR]** `js/ar.aging.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/ar.aging.js:253 | }); | ^`
- **[ERROR]** `js/bin.location.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/bin.location.js:390 | }); | ^`
- **[ERROR]** `js/financialreport.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/financialreport.js:74 |         , |         ^`
- **[ERROR]** `js/journal.entry.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/journal.entry.js:197 |                    'class="btn-toolbar-print"'transparent\';">' + |                                               ^^^^^^^^^^^^`
- **[ERROR]** `js/m.department.exc.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/m.department.exc.js:1 | <script type="text/javascript"> | ^`
- **[ERROR]** `js/m.proyek.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/m.proyek.js:61 |              '<td + nw + '">Tagihan</td><td align="right" + nw + '">%</td><td align="right" + nw + '">Total (DPP+PPN)</td>' + |                           ^^^^^^^^^^^^^^^^^^^^^^^^^`
- **[ERROR]** `js/m.user.system.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/m.user.system.js:611 |                              + |                              ^`
- **[ERROR]** `js/material.leftover.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/material.leftover.js:287 | }); | ^`
- **[ERROR]** `js/po.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/po.js:146 |                 html += '<button type="button" class="poActionBtn app-btn app-btn-primary" data-to="' + enc(to) + '" + bg + |                                                                                                                   ^^^^^^^^^`
- **[ERROR]** `js/ppn.report.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/ppn.report.js:212 | }); | ^`
- **[ERROR]** `js/project.invoice.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/project.invoice.js:605 | }); | ^`
- **[ERROR]** `js/project.payment.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/project.payment.js:1021 |             , |             ^`
- **[ERROR]** `js/project.payment.print.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/project.payment.print.js:189 | })(); | ^`
- **[ERROR]** `js/project.progress.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/project.progress.js:480 | }); | ^`
- **[ERROR]** `js/purchase.order.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/purchase.order.js:480 | }); | ^`
- **[ERROR]** `js/purchase.return.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/purchase.return.js:319 |                 actHtml += '<button type="button" class="rtActionBtn app-btn app-btn-primary" data-act="' + a[0] + '" + a[2] + |                                                                                                                    ^^^^^^^^^^^`
- **[ERROR]** `js/rab.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/rab.js:100 |             tabsHtml += '<button onclick="switchRabCategory(\'' + cat + '\')" + active + '" class="app-btn app-btn-primary">' + |                                                                                           ^^^^^^^^^`
- **[ERROR]** `js/rab_err.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/rab_err.js:396 |     }); |      ^`
- **[ERROR]** `js/settings.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/settings.js:321 |         , |         ^`
- **[ERROR]** `js/stock.card.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/stock.card.js:216 | }); | ^`
- **[ERROR]** `js/stock.card.m.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/stock.card.m.js:564 |         '<button id="btnSyncOne" type="button" + BTN + '" class="app-btn app-btn-dark">Sinkron gudang ini</button>' + |                                                         ^^^^^^^^^`
- **[ERROR]** `js/stock.opname.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/stock.opname.js:35 |         return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' + bg + |                                                                               ^^^^^^^^`
- **[ERROR]** `js/subcon.invoice.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/subcon.invoice.js:23 |         return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' + bg + |                                                                               ^^^^^^^^`
- **[ERROR]** `js/subcon.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/subcon.js:38 |         return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' + bg + |                                                                               ^^^^^^^^`
- **[ERROR]** `js/supplier.payment.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/supplier.payment.js:535 |         , |         ^`
- **[ERROR]** `js/warehouse.issuing.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/warehouse.issuing.js:252 |                 rb2 += '<button type="button" id="whPrintBtn" + bs + '" class="app-btn app-btn-primary">Cetak Ulang Surat Jalan (COPY)</button>'; |                                                                       ^^^^^^^^^`
- **[ERROR]** `js/whrcv.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/whrcv.js:668 | }); | ^`
- **[ERROR]** `js/year.closing.js (JS Syntax Error): /home/myadmin/proyekgmn-dev/js/year.closing.js:299 |             html += '<div style="margin-top:8px"><button type="button" class="ycAllBtn app-btn app-btn-dark" + btnCss + '">Pilih Semua</button>' + |                                                                                                                          ^^^^^^^^^^^^^^^^^^^^^^^^^`
