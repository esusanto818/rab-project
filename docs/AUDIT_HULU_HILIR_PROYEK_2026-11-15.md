# AUDIT MENYELURUH — RANTAI HULU-HILIR PROYEK (READ-ONLY)

**Tanggal:** 2026-11-15
**Scope:** Proyek → RAB (`budget_items`) → Material/Expenses → Subkon & SPK → Material Usage (`stock_movements`) → Progress Proyek
**Database:** `proyekGMN_test` (data aktual diperiksa, bukan hanya kode)
**Metode:** audit kode statis lintas lapisan (`views/`, `js/`, `php-crud/`, `php-func/`, `pdf/`, `*.PHP`) + audit kesiapan data aktual (query DB) + prinsip **sesimpel mungkin namun akurat** (memaksimalkan struktur tabel & `fiscal_config` yang sudah ada).
**Status:** READ-ONLY — tidak ada kode aplikasi maupun struktur DB yang diubah pada tahap ini. Rancangan patch ada di Bagian 9.

---

## 0. Rantai Hulu-Hilir & Kondisi Saat Ini (Peta)

```
projects (budget/manual progress/spent) ──┐
   │                                     │
   ▼                                     │
budget_items (RAB: Material/Upah/...)     │
   │  (category='Material' → material_id; non-material → expense_id)
   ▼                                     │
purchase.planning (qty_to_buy) ──► purchase_orders/po_items ──► grn_hdr/grn_dtl
   │                                        │                    │
   │                                        │                    ▼
   │                                        │            wh_receiving_hdr/dtl ──► stock_movements (IN)
   │                                        │                                    │
   │                                        │        warehouse.issuing (MATERIAL_OUT, OUT) ◄── spk (kuota)
   │                                        │                    │
   │                                        │                    ▼
   │                                        │        material_out_header/items (qty_out × unit_cost = HPP material)
   │                                        │                    │
   ▼                                        ▼                    ▼
project_progress_log ────────────────► project_wiprelease ──► journal_entries/lines
   (percent_claimed)                    (HPP = jurnal material_out saja)
                                              │
                                              ▼
                                    project_invoices → project_payments (hulu ke klien)
                                    subcon_contracts → subcon_progress → subcon_invoices (cabang subkon)
                                    spk_material_leftover_lots / _transactions (leftover — BELUM TERHUBUNG)
                                    material_leftover_header/items (retur sisa proyek — SUDAH BENAR)
```

**Kondisi data aktual (`proyekGMN_test`):**
- `budget_items` 66 baris, **semua category='Material'** (tidak ada baris Upah/Peralatan/... meski RAB_CATEGORIES mendefinisikannya).
- `spk`, `spk_items`, `spk_tasks`, `spk_task_materials`, `spk_task_expenses`, `spk_material_supply`, `spk_material_leftover_lots`, `spk_material_leftover_transactions`, `subcon_contracts`, `task_expenses`, `financial_expenses`, `material_receipt_header`, `material_issue_allocations`, `material_leftover_header` = **0 baris** (skema ada, belum dipakai).
- `material_out_header` 5 baris, **semua `spk_id` NULL**; `material_out_items.amount` **selalu 0** (18/18 baris; kolom tak pernah diisi saat insert; jurnal & view memakai `qty_out*unit_cost`).
- `stock_movements` 19 baris: hanya `IN/wh_receiving` dan `OUT/MATERIAL_OUT` (verified: 2 distinct pasangan, tak ada nilai lain).
- `journal_entries.reference_type` **bertipe `varchar`, bukan enum** (verified `information_schema.columns`) — pernyataan "enum 19 nilai" pada sesi audit sebelumnya **keliru**. Celah nyata: `material.leftoverCRD.php` memakai konstanta `'material_leftover'` (LO_JOURNAL_REF) yang belum pernah muncul di DB — namun karena varchar, **tidak akan error 22P02**; yang terjadi adalah nilai referensi asing yang tak dikenal di laporan.
- `fiscal_config`: 42 rule (bukan 45); `WH_LEFTOVER` (Dr 23/Cr 47) aktif; **`PRJ_DEP_APPLY` TIDAK ADA** — yang ada adalah `PAY_FROM_DEP` & `PRJ_PAY_DEP` (deposit klien); klaim "dipakai `project.paymentCRD.php:763`" pada sesi sebelumnya perlu pengecekan ulang baris 763.
- `accounting_periods`: 8 periode, **semua `is_closed=false`** (verified) — termasuk periode lampau (bukan penutupan, celah kontrol).
- `projects.spent`: **4 proyek punya nilai ≠ 0** (verified) — klaim "kolom mati, tidak pernah ditulis" pada sesi sebelumnya **keliru**; grep tidak menemukan write eksplisit, sehingga nilai kemungkinan dari migrasi/seed atau via `m.proyekCRD.update` — perlu audit tambahan sebelum menyimpulkan.
- Akses menu (`m_user_access_menu`): menu SPK(153)/Leftover(157)/Usage(154)/Issuing(144)/Progress(146)/Planning(138)/RAB(132) masing-masing 3 user; PO(24)/Subcon(158) 37 user; tidak ada menu aktif yatim.

---

## 1. AREA 1 — Sinkronisasi RAB → Pengeluaran/Usage → Progress Proyek

### 1.1 Progress bisa diklaim 100% tanpa material terpakai — **KRITIS**
- **File/baris:** `php-crud/project.progressCRD.php:271-314` (`submit_claim`), `:319-379` (`verify`); skema `project_progress_log.percent_claimed`.
- **Fakta:** `submit_claim` hanya memvalidasi tanggal, rentang 0–100, bukti, status proyek, `pct > prev`, dan satu klaim menunggu. **Tidak ada** pemeriksaan `material_out_items` / `stock_movements OUT` / `financial_expenses`. `verify` hanya ulang `pct > progress` + catatan CPI-merah.
- **Bukti data:** `projects.id=3` progress=100 & status 'Selesai' sementara seluruh DB hanya punya issue PROJECT di proyek 2; `project_progress_log.snap_cost_base` = 0.00 di semua baris "Diverifikasi" (biaya aktual tak pernah ada).
- **Celah tambahan:** `php-crud/m.proyekCRD.php:1667` & `:1701-1713` memungkinkan `progress` di-set manual via create/update proyek, sehingga komentar "satu-satunya jalur yang mengubah progress" di `project.progressCRD.php:370` **salah**.
- **Dampak:** progress adalah input ke CPI, gap usage-vs-progress, WIP release `%` (`project.wipreleaseCRD.php`), dan plafon issue (`warehouse.issuingCRD.php:85-117`). Progress palsu merusak semua kontrol turunan.

### 1.2 Realisasi HPP aktual: 3 rumus berbeda, tak ada yang lengkap — **KRITIS**
- `project.progressCRD.php:102-147` `prog_metrics()`: HPP = SUM(`material_out_items.qty_out*unit_cost` issue PROJECT posted) + SUM(`financial_expenses.amount`) — **tanpa subkon**.
- `php-func/project.analysis.php:83-132` `dash_project_context()`: biaya = material + expenses + **subkon** (`subcon_payment_certificates.gross_amount` APPROVED).
- `project.wipreleaseCRD.php:82-139` `wr_calc()`: WIP kumulatif = SUM debit jurnal `material_out` saja; HPP release = WIP × pct/100 — **material only**, expenses & subkon tak pernah dilepas ke HPP.
- **Akibat:** audit trail (`snap_cost_base` di `project_progress_log`) dan WIP release berbeda basis secara konstruksi; laporan P/L (`profitlossCRD.php`, `pdf/profitloss.php`) memakai basis perhitungan lain lagi.
- **Inkonsistensi status filter:** `project.progressCRD.php:111` mengecualikan `('CANCELLED','CANCELED','VOID','DIBATALKAN')` sedangkan `project.analysis.php:84` mengecualikan `('VOID','CANCELLED','CANCELED','BATAL')` — expense berstatus 'BATAL' dihitung di satu modul, tak di modul lain.

### 1.3 Kontrol pengeluaran vs RAB: qty-only, flag bukan block — **SEDANG**
- `warehouse.issuingCRD.php:303-317`: block keras = `qty > rab_qty × 1.05` (`ISSUE_ALLOWANCE_PCT=5`, baris 22); `is_overbudget` hanya flag tampilan. **Nilai (unit_price vs unit_price_plan) tak pernah dibandingkan** — issue dalam batas qty tapi harga 10× lipat tetap jalan.
- `v_material_budget_monitoring` (pg_views): menghitung `total_budget_cost` vs `total_actual_cost` tapi status hanya dari qty (`total_qty_out > qty_plan`) — value overage dihitung, tak ditegakkan.
- `financial_expenses` **tidak punya** `budget_item_id`/`task_id` dan tak ada kontrol nilai vs RAB sama sekali (skema: hanya `project_id, category, amount, reference_no, status`).
- Satu-satunya kontrol nilai: `warehouse.issuingCRD.php:85-117` `validateMaterialOutAgainstProgress()` memakai **`projects.budget`** (kolom header manual, `m.proyekCRD.php:1683-1713`), bukan `SUM(budget_items.qty_plan*unit_price_plan)`; dan cuma gate issue, bukan progress.

### 1.4 Temuan pendukung
- **[SEDANG]** `projects.spent` mati (default 0, tak pernah di-UPDATE; `dashboardCRD.php:11` bahkan menyatakan laba dihitung live "bukan kolom projects.spent").
- **[RENDAH]** `material_out_items.amount` selalu 0 (insert di `warehouse.issuingCRD.php:312-317` tak menyertakan kolom); konsisten karena semua konsumen memakai `qty_out*unit_cost`, tapi kolom mati menyesatkan.
- **[RENDAH]** Retur sisa (`WH_LEFTOVER`) mengurangi WIP tapi tak menyentuh `projects.progress`, `snap_cost_base` yang sudah beku, maupun `project_wip_release` yang sudah terbit; view `v_project_material_*_vs_progress` memakai issue kotor (tanpa netting retur) sehingga "pemakaian" di laporan ≠ `position` task di `material.leftoverCRD.php:211-226`.

---

## 2. AREA 2 — Subkon & SPK: material perusahaan vs material subkon + buy-back sisa

### 2.1 Pemisahan COMPANY vs SUBCON sudah ada di SPK, tapi belum menyentuh ledger — **SEDANG**
- `spkCRD.php:405-413`: `supply_source` COMPANY/SUBCON divalidasi; COMPANY → `spk_items` (izin issue gudang + kuota RAB), SUBCON → `spk_material_supply` (hanya rencana pasokan, tidak menyentuh stok). Sesuai desain `docs/SUBCONTRACT_MANAGEMENT_DESIGN.md` §4.6.
- `function.spk.php:34-102` `SpkMaterialQuota()`: kuota = RAB × %diizinkan (dibayar × 2) − SPK aktif − issue; `available = min(kuota, stok)`. Celah: `available` bisa **negatif** bila stok < kuota (min tak dibutuhkan), dan stok yang dipesan SPK subkon SUBCON-source ikut dihitung padahal tak akan di-issue.
- `material_out_header.spk_id` ada & `SpkIssueCount()` menghitung issue per SPK, tapi **`spk_id` tak pernah terisi** (5/5 baris NULL) → link SPK↔issue efektif mati; kuota SPK hanya penjaga rencana, bukan pelacak pemakaian.

### 2.2 Buy-back sisa material subkon: TIDAK ADA jalur yang berfungsi — **KRITIS**
- Desain §4.7 mewajibkan: kepemilikan hanya berpindah lewat transaksi tercatat; "Company stock changes only through posted warehouse/purchase/sales movement"; "Subcontractor-owned material cannot be sold by the company until ownership transfers".
- Fakta DB: `spk_material_leftover_lots` & `spk_material_leftover_transactions` = 0 baris; `inventory_doc_id` / `inventory_out_doc_id` selalu NULL.
- `leftoverCRD.php` (orphan, 217 baris) `post_transaction` hanya menyetel status POSTED + mengandalkan trigger `fn_post_spk_leftover_transaction`, yang nyatanya (definisi di DB) **hanya** mengurangi `qty_available` & membuat lot anak untuk BUY_FROM_SUBCON/SELL_TO_CLIENT — **tidak** menulis `stock_movements`, **tidak** membuat PO/GRN, **tidak** posting jurnal (hutang/kas), **tidak** menyentuh `fiscal_config`.
- Trigger `fn_validate_spk_leftover_transaction` mewajibkan `BUY_FROM_SUBCON` merujuk `material_leftover_header` POSTED — tapi header itu hanya dibuat oleh `material.leftoverCRD.php` yang `lo_net()`-nya butuh issue PROJECT dari stok perusahaan → material milik subkon **mustahil** punya dasar retur. Jalur buy-back terblokir struktural.
- Enum `journal_entries.reference_type` (19 nilai) tidak punya nilai untuk buy-back/sell-to-client; `fiscal_config` tidak punya rule untuknya.
- **Celah yang diminta user (jalur sederhana):** saat ini tidak ada jalur sama sekali — buy-back akan masuk lewat PO normal (kena validasi kuota RAB PO & stok proyek) atau manual jurnal (tanpa stok). Rancangan patch: lihat §9 (F-2.1).

### 2.3 Leftover SPK belum terhubung ke `stock_movements` — **SEDANG**
- `RETURN_COMPANY` di `leftoverCRD.php` hanya mengubah status lot; tidak ada movement IN, tidak ada jurnal. Bandingkan `material.leftoverCRD.php` (Area 3) yang sudah lengkap.

### 2.4 `spk_material_supply`: integritas kepemilikan dijaga CHECK DB, tapi tanpa jalur serah-terima & tanpa jembatan ke ledger — **SEDANG**
- CHECK **ada** di DB (terverifikasi via `pg_constraint`): `ck_spk_supply_owner` (COMPANY→owner COMPANY+subcon NULL; SUBCON→owner SUBCON+subcon terisi), `spk_material_supply_supply_source_check`, `_owner_type_check`, `ck_spk_supply_accepted`. Lapisan DB sudah benar untuk pemisahan kepemilikan.
- Celah yang tersisa: (a) `qty_received`/`qty_accepted` **tidak pernah diperbarui** kode mana pun → "material dibawa subkon" tak punya jejak serah-terima; (b) SUBCON supply tak tersambung ke ledger apa pun (didesain demikian, tetapi konsekuensinya: tidak ada cara menagih subkon atas material yang dibawanya bila proyek berakhir); (c) `SpkMaterialQuota()` tidak melihat `spk_material_supply`, sehingga material yang sama bisa direncanakan COMPANY dan SUBCON pada SPK berbeda tanpa peringatan tumpang-tindih.

---

## 3. AREA 3 — Retur Sisa Material Proyek Sendiri (Leftover Return)

### 3.1 Mekanisme sudah ADA dan BENAR — `material.leftoverCRD.php`
- Validasi batas sisa pemakaian: `lo_net()` (`material.leftoverCRD.php:61-77`) = issue PROJECT posted − retur posted; save menolak `qty > net + LO_EPS` (`:290-293`) dengan `pg_advisory_xact_lock(projectId, materialId)` (`:282`) anti-race.
- Kredit HPP: `$cost = avg_cost` issue bersih (`:294`), disimpan ke `material_leftover_items.unit_cost` & `stock_movements.unit_cost` (server, bukan input user); jurnal `WH_LEFTOVER` (Dr 23 Persediaan / Cr 47 WIP) via `AutoPostJournal` (`:301-307`) dalam transaksi ACID yang sama dengan stok.
- Reverse (`:322-359`): tolak bila stok hasil retur sudah terpakai; hapus jurnal (`ReverseJournalByReference`) & `stock_movements`.
- **Status:** fitur belum dipakai (0 baris) — bukan cacat, tapi belum ada regresi data.

### 3.2 BLOKIR EKSEKUSI: `reference_type` varchar menerima nilai tak dikenal `material_leftover` — **KRITIS**
- `material.leftoverCRD.php:38` `LO_JOURNAL_REF='material_leftover'` dikirim ke `AutoPostJournal` → insert `journal_entries.reference_type`. Karena kolom bertipe **varchar** (bukan enum — lihat §0), insert **tidak gagal**; yang terjadi adalah baris jurnal bertulis nilai referensi yang tak dikenal seluruh sistem (laporan/jurnal tak mengenal `material_leftover`), dan migrasi yang disebut komentar file (`sql/2026-10-27.leftover_journal_enum.sql`) **tidak ada** di `sql/`.
- Akibat: begitu ada data issue, retur sisa pertama akan menulis jurnal dengan `reference_type` yang tak terdaftar — silent inconsistency, bukan error DB.
- **Patch paling sederhana:** tambahkan `material_leftover` ke daftar referensi tipe yang dikenal (dokumentasi + aplikasi di `function.all.php`/`AutoPostJournal`) + validasi `in_array` saat insert, atau ubah kolom jadi enum asli lalu `ALTER TYPE ... ADD VALUE 'material_leftover'`. (Verified: `AutoPostJournal` `function.all.php:465` & `JrnPost` `journal.stock.php:59` sama sekali tidak memvalidasi `reference_type` terhadap daftar — inilah yang membuat nilai asing bisa masuk diam-diam.)

### 3.3 File duplikat/orphan
- `leftoverCRD.php` (varian SPK leftover, "[NEW 2026-11-13]") tak dipanggil views/js mana pun (router tak punya mapping) — orphan yang membingungkan; dokumentasikan atau hapus.

---

## 4. AREA 4 — Material Titipan Klien (Client-Supplied, Rp 0)

### 4.1 Tidak ada flag kepemilikan di mana pun — **KRITIS**
- `materials`, `budget_items`, `task_materials`, `spk_material_supply` (pemilik hanya COMPANY/SUBCON, tanpa CLIENT) — tidak ada kolom `is_client_supplied`/`source`/`owner` untuk klien. Grep seluruh codebase (`client_supplied|titipan|CLIENT_SUPPLY`) = 0 hit fungsional.
- Akibat berantai: D2–D6 di bawah.

### 4.2 Titipan klien tetap masuk `qty_to_buy` penuh — **KRITIS**
- `purchase.planningCRD.php:137-150,165-169` & `poCRD.php:122-125,210-212,276,383-384`: `qty_to_buy = GREATEST(qty_plan − qty_ordered − stok, 0)` tanpa parameter sumber material. Item titipan klien akan muncul sebagai kebutuhan beli penuh → duplikasi pembelian.

### 4.3 GRN menerima `unit_cost = 0` → layer FIFO Rp 0 tercemar — **KRITIS**
- `grnCRD.php:510-530` hanya menolak harga yang sama dengan PO inklusif PPN; tidak ada `unit_cost > 0`. `whrcvCRD.php:298-301` memakai `unit_cost_base` (bisa 0) → `PostStockMovement IN unit_cost=0`.
- Dampak: `WhFifoAllocate` (`php-func/function.stock.php:115-141`) mengeluarkan layer termurah dulu → layer Rp 0 titipan klien terlebih dahulu → HPP proyek Rp 0 untuk material perusahaan; `view_stock_balance` mencampur fisik tanpa pemisahan kepemilikan → aset persediaan di neraca overstated.

### 4.4 Jurnal Rp 0: perilaku tidak konsisten — **SEDANG**
- `function.all.php:477` `AutoPostJournal` menolak `amount <= 0`; `whrcvCRD.php:337-347` **melempar Exception** bila jurnal gagal → seluruh WH Rcv dengan total 0 ditolak, bertentangan komentar baris 22 ("item dengan unit_cost=0 tetap valid"). `JrnPost` (`php-func/journal.stock.php:59-61`) malah mengembalikan null diam-diam untuk amount ≤ 0.
- Untuk titipan klien yang sah, yang dibutuhkan justru: movement IN fisik **tanpa** jurnal rupiah — saat ini mustahil karena (a) tak ada flag, (b) GRN biasa akan gagal jurnal saat total 0.

### 4.5 Hal positif
- `stock.opnameCRD.php:265` menolak `unit_cost ≤ 0` untuk selisih lebih — satu-satunya modul yang konsisten; pola ini layak ditiru GRN/whrcv untuk barang non-titipan.

---

## 5. AREA 5a — Kontrol RAB → PO (ringkasan; detail dari agen RAB→PO menyusul)

- PO quota: `poCRD.php:92` (limit nilai RAB × tolerance), `:202-216` (`qtyLimit`/`valLimit` per baris RAB × tolerance), `:276` `qty_to_buy` via `po_net_need()`, `:383-384` block qty > kebutuhan bersih; `purchase.planningCRD.php:232-233` kuota serupa.
- Temuan awal: toleransi diterapkan sebagai batas keras (OK); `is_addendum` ikut terhitung via SUM qty_plan (OK); **belum diverifikasi:** celah kategori non-material & mismatch gudang GRN (menunggu laporan agen).

---

## 6. Data Readiness (wajib per CLAUDE.md §6.3)

| Item | Status | Dampak |
|---|---|---|
| `fiscal_config.PRJ_DEP_APPLY` | **TIDAK ADA** (dipakai `project.paymentCRD.php:763`) | Pelunasan termin pakai deposit akan gagal posting jurnal |
| `reference_type` varchar menerima `'material_leftover'` | **BELUM TERDAFTAR** | Retur sisa material menulis jurnal dengan referensi tak dikenal (silent, bukan error 22P02) |
| `accounting_periods` | Semua `is_closed=false` termasuk periode lampau | Tak ada penutupan periode — kontrol periode lemah |
| `m_user_access_menu` | Semua menu aktif punya ≥3 pengguna; 0 orphan | Sehat |
| Data pra-migrasi berjurnal | `spk`/`subcon_contracts`/`material_leftover_*` = 0 baris | Tidak ada risiko double-posting migrasi |
| `financial_expenses`/`task_expenses` | 0 baris | Jalur expense RAB belum teruji data |

---

## 7. Ringkasan Temuan Kritis (peringkat)

| # | ID | Temuan | Lokasi utama | Severity |
|---|----|--------|--------------|----------|
| 1 | A1 | Progress 100% tanpa material terpakai; progress bisa di-set manual | project.progressCRD.php:271-379; m.proyekCRD.php:1667,1701 | KRITIS |
| 2 | A2 | HPP realisasi 3 rumus berbeda; WIP release material-only | project.analysis.php:83-132; wipreleaseCRD.php:82-139 | KRITIS |
| 3 | D1 | Tidak ada flag client-supplied di tabel mana pun | materials/budget_items/task_materials/spk_material_supply | KRITIS |
| 4 | D2 | Titipan klien masuk qty_to_buy penuh di Planning & PO | purchase.planningCRD.php:145; poCRD.php:122-124 | KRITIS |
| 5 | D3 | GRN terima unit_cost=0 → FIFO Rp 0 tercemar | grnCRD.php:510-530; function.stock.php:115 | KRITIS |
| 6 | B1 | Buy-back subkon tak punya jalur: tak ada stok/jurnal/PO, terblokir struktural | leftoverCRD.php:200-207; fn_post_spk_leftover_transaction | KRITIS |
| 7 | C2 | `reference_type` varchar menerima nilai tak dikenal 'material_leftover' → jurnal silent-inconsistency | material.leftoverCRD.php:38 vs information_schema | KRITIS |
| 8 | E? | (menunggu agen RAB→PO) | — | — |

Temuan SEDANG/RENDAH lengkap: A3-A9, B2-B3, C1-C5, D4-D7, E4 pada bagian terkait.

### 7b. Klaim dalam brief audit yang **DITOLAK** setelah verifikasi ulang (transparansi wajib)

Brief sesi meminta 5 temuan dimasukkan sebagai "sudah terverifikasi". Verifikasi langsung ke kode + DB membuktikan 3 di antaranya **tidak akurat seperti dinyatakan**; yang terbukti dicatat sebagai temuan pengganti:

| Klaim brief | Verdict | Bukti aktual (baris/query) | Temuan pengganti yang sah |
|---|---|---|---|
| "SQL injection di `leftoverCRD.php`" | **TIDAK TERBUKTI** | Semua input di-cast: `(int)` baris 34, 50, 62-67, 107-118; string query tanpa parameter hanya memakai variabel integer (`$userId`, `$tx_id`) di baris 180 & 202. Tidak ada `pg_query()` berisi input mentah. | File tetap bermasalah sebagai **orphan** (§3.3) + pola `cmdQ("... {$tx_id}")` rawan bila refactor nanti menyamakan variabel non-int → masuk F-2.3 (ganti `cmdQ` dengan `prepare`). |
| "E1 delete RAB tanpa guard" | **TIDAK TERBUKTI** | `rabCRD.php:602-621` sudah `pg` parameterized; `m.proyekCRD.php:1595-1597` memanggil `mpItemBlock()` (PO/SPK/subkon/task_expenses/issue) sebelum hapus; DB punya trigger `trg_guard_budget_item_task_allocations` (`fn_guard_budget_item_task_allocations`: block DELETE bila ada alokasi task; block UPDATE identitas; block qty/nilai turun di bawah alokasi). | Celah yang benar: guard **tidak menutup** penghapusan via `save_rab` "hapus item tak di-grid" (`rabCRD.php:602-621`) bila item sudah dipakai PO — karena penghapusan di sana tidak memanggil `mpItemBlock`. → F-5.2. |
| "E4 mismatch CHECK kategori" | **SEBAGIAN BENAR (bentuknya bukan "CHECK hilang", tapi MISMATCH)** | CHECK DB **ada**: `budget_items_category_check` mengizinkan `['Material','Upah','Alat','Lain-lain']` — **`'Alat'`, bukan `'Peralatan'`**. Sedangkan `RAB_CATEGORIES` di `rabCRD.php:28` memakai `'Peralatan'`. | Celah nyata: input `'Peralatan'` dari UI/API dinormalisasi jadi `'Lain-lain'` (`rabCRD.php:369/453/501/522`), lalu baris ber-`material_id>0` **dibuang diam-diam** (`$cat !== 'Material' && $eid <= 0 → continue` di `:453/501/522`) → data hilang tanpa peringatan; dan normalisasi `in_array` case-sensitive membuat `'material'` lolak demote. CHECK DB-lah yang menyelamatkan insert (tanpanya, `'Peralatan'` akan gagal 23514). → F-5.3. |
| "SPK subcon material supply" | **SEBAGIAN BENAR** | Validasi `supply_source` ada (`spkCRD.php:413`), tapi tanpa CHECK DB & tanpa jalur penerimaan. | §2.4 (baru). |
| "Client-Supplied Rp 0" | **BENAR** | Grep `client_supplied|titipan|CLIENT_SUPPLY` = 0 hit fungsional; `qty_to_buy` tanpa parameter sumber; GRN terima `unit_cost=0`. | D1–D3 (§4). |

---

## 8. Jawaban Pertanyaan Spesifik User

1. **Apakah progress fisik bisa diklaim 100% sementara material belum dipakai / pengeluaran melampaui RAB tanpa kontrol?**
   Ya — A1: tidak ada validasi sama sekali antara klaim progress dengan material usage; dan progress bisa di-set manual di master proyek. Pengeluaran melampaui RAB dikontrol hanya untuk issue material (kuanti × 105%), bukan nilai, dan tidak untuk expenses; progress sendiri tanpa kontrol.
2. **Bagaimana perhitungan realisasi HPP aktual proyek saat ini?**
   Tiga rumus tak searasi (§1.2): progress/CPI = material+expenses; dashboard = material+expenses+subkon; WIP release = jurnal material_out saja. Tidak ada satu pun "realisasi HPP" yang otoritatif.
3. **Bagaimana tabel & kode SPK membedakan COMPANY vs SUBCON?**
   `spk_items` (COMPANY, izin issue gudang + kuota RAB) vs `spk_material_supply` (SUBCON, rencana pasokan tanpa stok) + `spk_work_items.material_supply` (COMPANY/SUBCON/MIXED) — sudah benar di level perencanaan, tetapi tidak pernah tersambung ke ledger (`spk_id` NULL di semua issue; SUBCON supply tak punya movement).
4. **Jalur paling sederhana untuk buy-back sisa subkon → stock_movements IN + jurnal persediaan vs hutang/kas tanpa terblokir kuota RAB PO?**
   Saat ini **tidak ada** jalur. Rancangan paling sederhana (§9.1): tambahkan `transaction_type='BUY_FROM_SUBCON'` yang membuat **PO khusus subkon** (bukan dari RAB material proyek) atau langsung posting `stock_movements IN` dengan `reference_type` varchar baru (`'subcon_leftover_buyback'`) + jurnal dari 2 rule fiscal_config baru (`LEFTOVER_BUYBACK_INV` Dr Persediaan/Cr Hutang Subkon; `LEFTOVER_BUYBACK_PAY` Dr Hutang/Cr Kas) — tanpa menyentuh kuota RAB PO karena PO buy-back memakai `budget_item_id NULL`/flag `source='SUBCON_LEFTOVER'` yang dikecualikan dari `qty_to_buy`.
5. **Apakah sudah ada mekanisme retur sisa material proyek sendiri?**
   Sudah — `material.leftoverCRD.php` (Area 3) lengkap & valid, tapi terblokir eksekusi oleh `reference_type` `'material_leftover'` yang belum terdaftar di sistem (C2) dan belum teruji data (0 baris).
6. **Bagaimana agar item titipan klien terlacak fisik dengan Rp 0 tanpa jurnal rupiah, dan otomatis qty_to_buy=0?**
   Rancangan (§9 F-4.1): 1 kolom flag di RAB (`budget_items.material_source='CLIENT_SUPPLIED'`) + perilaku: (a) `qty_to_buy` di planning/PO langsung 0 untuk flag tersebut (skip, bukan GREATEST); (b) GRN/whrcv untuk flag tersebut memaksa `unit_cost=0` server-side, posting `stock_movements IN` fisik, **skip** `AutoPostJournal` saat amount=0 (bukan lempar exception); (c) FIFO mengeluarkan layer titipan dengan biaya 0 dan jurnal `ISSUE_PROJECT` otomatis null saat issueValue=0 (`JrnPost` sudah diam-diam null — perlu explicit guard agar WIP tak tercemar).

---

## 9. Rancangan Patch Paling Sederhana (BELUM DITERAPKAN)

| ID | Patch | Sentuhan |
|----|-------|----------|
| F-1.1 | `submit_claim`/`verify`: tambah validasi `percent_claimed ≤ f(progress)` berbasis usage material (opsional soft-block warning; hard-block bila 100% dengan 0 issue). Hapus kemampuan set `progress` manual di m.proyekCRD (atau khusus admin). | progressCRD, m.proyekCRD |
| F-1.2 | Satukan formula HPP: buat view `v_project_actual_cost` (material issue bersih + expenses + subkon tersertifikasi) dan pakai di prog_metrics, dash_project_context, wr_calc. | function.all/project.analysis/wipreleaseCRD |
| F-1.3 | `material_out_items.amount` diisi saat insert (qty×cost) atau drop kolom; samakan filter status expense jadi 1 konstanta. | issuingCRD, progressCRD, analysis |
| F-2.1 | Buy-back subkon: PO khusus + reference_type baru + 2 rule fiscal_config (rincian §9.1). | leftoverCRD/subconCRD/poCRD + migrasi |
| F-2.2 | Isi `spk_id` saat issuing memilih SPK; `available=min(kuota,stok)` jangan negatif. | issuingCRD, function.spk |
| F-2.3 | Ganti `cmdQ("... {$id}")` di `leftoverCRD.php:180,202` dengan `prepare/execute` (hardening, bukan bug saat ini). | leftoverCRD |
| F-3.1 | Daftarkan `material_leftover` sebagai `reference_type` yang dikenal (varchar: tambahkan ke daftar/validasi aplikasi, atau konversi kolom ke enum + `ALTER TYPE ADD VALUE`) + uji retur sisa E2E (rincian §9.2). | material.leftoverCRD / sql/ |
| F-3.2 | Dokumentasikan/hapus orphan `leftoverCRD.php`. | php-crud |
| F-4.1 | Flag client-supplied: 1 kolom di RAB + 3 perilaku (rincian §9.3). | rabCRD, planningCRD, poCRD, grnCRD, whrcvCRD |
| F-5.1 | Tambah rule `PRJ_DEP_APPLY` ke fiscal_config (data readiness). | settings/sql |
| F-5.2 | `save_rab`: tolak penghapusan item RAB yang sudah dipakai (panggil `mpItemBlock` untuk setiap `budget_item_id` lama yang hilang dari grid) — tutup celah yang tidak ada di `save_rab` tapi ada di `m.proyekCRD.edit`. | rabCRD |
| F-5.3 | Samakan daftar kategori: ubah CHECK DB jadi `['Material','Upah','Peralatan','Transport','Konsumsi','Lain-lain']` (atau normalisasi PHP ke `'Alat'`) + case-insensitive + validasi ketat (bukan demote diam). | sql/ + rabCRD + m.proyekCRD |
| F-6.1 | Tutup periode akuntansi lampau (is_closed) atau kontrol tanggal transaksi. | m.fiscalperiodCRD |

---

## 9.1 Desain solusi paling sederhana — Subcon Buy-Back (B1)

**Prinsip:** memakai PO normal sebagai kendaraan (sudah punya alur PO → GRN → WH Rcv → stok → jurnal → supplier payment), hanya menambahkan **1 flag + 2 pengecualian**, tanpa tabel baru.

1. **Migrasi (1 file, ~10 baris):**
   - `ALTER TYPE journal_reference ADD VALUE 'subcon_leftover_buyback';` — **atau** bila kolom tetap varchar (keadaan saat ini, verified): cukup tambahkan nilai ke daftar referensi tipe yang dikenal aplikasi + validasi insert.
   - 2 rule `fiscal_config`: `LEFTOVER_BUYBACK_INV` (Dr Persediaan / Cr Hutang Subkon) & `LEFTOVER_BUYBACK_PAY` (Dr Hutang Subkon / Cr Kas-Bank) — pakai akun yang sama dengan `SUBCON_WORK`/`SUBCON_PAY` agar tidak ada CoA baru.
   - Kolom `purchase_orders.source VARCHAR(20) DEFAULT 'RAB'` + CHECK (`'RAB','SUBCON_LEFTOVER'`); idem `po_items.source`.
2. **`poCRD.php`:** bila `source='SUBCON_LEFTOVER'` → `budget_item_id` wajib NULL, `supplier_id` wajib = subkon_id (validasi join ke `subcontractors`), dan **skip** kuota RAB (`po_net_need`/`qtyLimit` hanya untuk source='RAB'). Harga = `unit_price` input (harga beli-back, wajib > 0).
3. **`grnCRD.php`/`whrcvCRD.php`:** GRN biasa untuk PO tersebut; `reference_type` jurnal = `subcon_leftover_buyback` (bukan `wh_receiving`) bila PO.source-nya bukan RAB — sehingga jurnal masuk akun Hutang Subkon, bukan Hutang Supplier. Stok IN tetap jalan (kepemilikan pindah ke perusahaan saat GRN posting).
4. **`leftoverCRD.php`:** `post_transaction` untuk `BUY_FROM_SUBCON` kini: (a) buat/buka PO khusus itu (atau wajib isi `po_id` di form), (b) setelah GRN posting, lot jadi `PROCESSED`, `inventory_doc_id` = wh_receiving_hdr.id.
5. **Pelunasan:** supplier payment biasa (subkon terdaftar sebagai supplier) — tanpa mekanisme baru.
6. **Mengapa ini paling sederhana:** tidak ada tabel baru, tidak ada jalur jurnal manual, kuota RAB tidak terganggu (PO buy-back bukan dari RAB), dan audit trail = PO→GRN→jurnal→bayar, persis seperti pembelian normal.

## 9.2 Desain solusi paling sederhana — Leftover Return proyek sendiri (C2)

Fitur `material.leftoverCRD.php` sudah lengkap & benar (§3.1); **satu-satunya blocker adalah `reference_type` yang belum terdaftar**.

1. Migrasi 1 baris bila kolom dikonversi ke enum: `ALTER TYPE "journal_entries_reference_type" ADD VALUE 'material_leftover';` — **keadaan saat ini: kolom varchar** (verified), sehingga solusi sesederhana adalah menambahkan `'material_leftover'` ke daftar referensi tipe yang dikenal di `AutoPostJournal`/`JrnPost` (validasi `in_array`) + dokumentasi, tanpa `ALTER TYPE`.
2. Uji E2E: issue PROJECT 1 unit → retur sisa 1 unit → pastikan `stock_movements` IN + jurnal WH_LEFTOVER (Dr 23 / Cr 47) terbit + reverse berjalan.
3. Opsional: tambahkan `reference_type` ke dokumentasi menu (F-3.2) dan hapus/ganti nama orphan `leftoverCRD.php` agar tidak membingungkan.

## 9.3 Desain solusi paling sederhana — Client-Supplied Rp 0 (D1–D4)

**Prinsip:** 1 kolom flag + 3 perilaku; tanpa tabel baru, tanpa memecah FIFO.

1. **Migrasi:** `ALTER TABLE budget_items ADD COLUMN material_source VARCHAR(20) NOT NULL DEFAULT 'COMPANY' CHECK (material_source IN ('COMPANY','CLIENT_SUPPLIED'));` — kolom di RAB (bukan di `materials`, karena kepemilikan bersifat per-proyek, bukan per-material).
2. **`rabCRD.php`:** form RAB punya dropdown Sumber (Perusahaan / Titipan Klien); `save_rab` menyimpan flag tersebut.
3. **`purchase.planningCRD.php` & `poCRD.php`:** `qty_to_buy` untuk baris `material_source='CLIENT_SUPPLIED'` = **0 / skip** (bukan `GREATEST(...)`) — titipan klien tidak dibeli.
4. **`grnCRD.php`/`whrcvCRD.php`:** GRN untuk PO titipan klien: `unit_cost` dipaksa 0 server-side; `stock_movements` IN tetap ditulis (fisik masuk, terlacak); `AutoPostJournal` **skip** bila total = 0 (ganti throw jadi `if ($total > 0)`), sesuai komentar baris 22 yang sudah ada.
5. **FIFO & issue:** `WhFifoAllocate` mengeluarkan layer termurah dulu → layer Rp 0 titipan klien keluar duluan, HPP = Rp 0, otomatis benar tanpa perubahan kode; tambahkan guard eksplisit di `warehouse.issuingCRD.php`: bila `issueValue == 0` untuk material CLIENT_SUPPLIED, jurnal `ISSUE_PROJECT` di-skip (bukan error).
6. **Pelaporan:** `view_stock_balance` tambah kolom `material_source` (join ke RAB terakhir) agar fisik titipan klien tidak dicampur aset perusahaan.

## 9.4 Urutan eksekusi patch (data readiness dulu)

1. F-3.1 (daftar reference_type) + F-5.1 (`PRJ_DEP_APPLY`) — migrasi paling kecil, unlock fitur yang sudah jalan.
2. F-4.1 (client-supplied) — butuh kolom RAB; uji GRN Rp 0 dulu di data 1 proyek.
3. F-2.1 (buy-back) — butuh reference_type baru + 2 rule fiscal_config; uji end-to-end PO→GRN→jurnal→bayar.
4. F-1.x (progress & HPP) — paling berdampak ke laporan; terakhir, setelah data dasar bersih.
5. F-2.2/F-2.3/F-3.2/F-5.2/F-5.3/F-6.1 — hardening & housekeeping, bisa paralel.

---

## 10. Lampiran Bukti Query DB (read-only)

- `SELECT DISTINCT movement_type, reference_type FROM stock_movements` → hanya IN/wh_receiving, OUT/MATERIAL_OUT (2 pasangan, verified).
- `SELECT DISTINCT issue_type FROM material_out_header` → PROJECT(1), SUPPLIER_RETURN(4).
- `material_out_items.amount` = 0.00 untuk semua 18 baris; `material_issue_allocations` 0 baris (FIFO via `WhFifoAllocate` tidak menulis tabel alokasi).
- `journal_entries` 32 baris: reference_type terpakai = wh_receiving, supplier_return, project_payment, project_invoice, material_out, wip_release (verified; `material_leftover` belum pernah muncul).
- `journal_entries.reference_type` bertipe **varchar**, bukan enum (verified `information_schema.columns`) — koreksi atas asumsi sesi audit sebelumnya.
- `fiscal_config` 42 rule (verified, bukan 45); `WH_LEFTOVER` (Dr 23/Cr 47) aktif; `PRJ_DEP_APPLY` tidak ada (yang ada: `PAY_FROM_DEP`, `PRJ_PAY_DEP`).
- `project_progress_log` 11 baris; `snap_cost_base` = 0.00 di semua baris terverifikasi.
- `projects`: id 3 progress=100 'Selesai' tanpa issue material; id 8 progress=78 spent=0; `spent` ≠ 0 hanya pada id 1–4 (nilai seed/migrasi — tidak ada write runtime di kode, verified via grep `UPDATE projects`).
- `warehouses`: 93 gudang, hanya id 1 (proyek 8) & 2 (proyek 9) milik proyek; id 3 Gudang Pusat (project_id NULL).

*(Dokumen ini tahap AUDIT saja. Patch menunggu persetujuan user.)*
