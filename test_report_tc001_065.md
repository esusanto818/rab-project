# Test Report E2E Release Tagihan (TC-001 s/d TC-065)

**Tanggal eksekusi:** 2026-11-15 07:39:37  
**Baseline:** branch `dev` commit `7cb757c` | DB `proyekGMN_test` | base URL `http://127.0.0.1:8098`  
**Dokumen acuan:** `docs/TESTCASE_RELEASE_TAGIHAN_E2E_2026-11-14.md`  
**Ringkasan sementara:** PASS = 144, FAIL = 0, SKIP = 0

> Catatan: TC-029 (PDF) butuh verifikasi UI/manual — dicatat SKIP-UI bila tidak terotomasi. TC-060..TC-065 (Kelompok E) sudah terotomasi penuh via endpoint dashboard/PPN/analisis/auto-invoice/pembayaran.

<!-- BEGIN:Kelompok A — Persiapan & Invoice (fase DRAFT) -->
## Kelompok A — Persiapan & Invoice (fase DRAFT)

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-001 | Prasyarat sistem: fiscal_config & accounting_periods | PASS | rule aktif: PROJECT_INVOICE,PROJECT_INVOICE_PPN,PRJ_PAY_AR,PRJ_PAY_DEP,PRJ_DEP_APPLY \| periode 2026-11 is_closed=false |
| TC-002 | Simpan invoice baru = DRAFT | PASS | msg='Invoice INV-2611-00048 disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan re' \| status=draft \| jurnal=0 \| list_outstanding=tidak muncul (id=330, INV-2611-00048) |
| TC-003 | Edit & hapus invoice DRAFT | PASS | edit: OK \| hapus inv324: OK, baris hilang \| msg='Invoice INV-2611-00042 dihapus.' |
| TC-004 | Edit invoice RELEASED ditolak | PASS | http=200 \| success=false \| msg='Invoice INV-2611-00044 sudah diterbitkan (released); batalkan release dulu untuk mengubah.' |
| TC-005 | Hapus invoice RELEASED ditolak | PASS | http=200 \| msg='Invoice INV-2611-00044 sudah diterbitkan; batalkan release dulu sebelum menghapus.' \| baris masih ada=ya |
| TC-006 | Total DPP melebihi kontrak ditolak | PASS | msg='Total tagihan DPP (626,000,000.00) melebihi nilai kontrak (500,000,000.00).' \| contract_value=500,000,000.00 |
<!-- END:Kelompok A — Persiapan & Invoice (fase DRAFT) -->

<!-- BEGIN:Kelompok B — Release (draft -> released) & Unrelease -->
## Kelompok B — Release (draft -> released) & Unrelease

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-001 | Prasyarat sistem: fiscal_config & accounting_periods | PASS | rule aktif: PROJECT_INVOICE,PROJECT_INVOICE_PPN,PRJ_PAY_AR,PRJ_PAY_DEP,PRJ_DEP_APPLY \| periode 2026-11 is_closed=false |
| TC-002 | Simpan invoice baru = DRAFT | PASS | msg='Invoice INV-2611-00048 disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan re' \| status=draft \| jurnal=0 \| list_outstanding=tidak muncul (id=330, INV-2611-00048) |
| TC-003 | Edit & hapus invoice DRAFT | PASS | edit: OK \| hapus inv324: OK, baris hilang \| msg='Invoice INV-2611-00042 dihapus.' |
| TC-004 | Edit invoice RELEASED ditolak | PASS | http=200 \| success=false \| msg='Invoice INV-2611-00044 sudah diterbitkan (released); batalkan release dulu untuk mengubah.' |
| TC-005 | Hapus invoice RELEASED ditolak | PASS | http=200 \| msg='Invoice INV-2611-00044 sudah diterbitkan; batalkan release dulu sebelum menghapus.' \| baris masih ada=ya |
| TC-006 | Total DPP melebihi kontrak ditolak | PASS | msg='Total tagihan DPP (626,000,000.00) melebihi nilai kontrak (500,000,000.00).' \| contract_value=500,000,000.00 |
| TC-010 | Release satu invoice draft -> released | PASS | status=released \| release_no=TAG-2611-00013 \| tgl=2026-11-15 \| by=170 \| jurnal DPP:OK PPN:OK (dpp=10000000 ppn=1100000) \| list_outstanding=muncul |
| TC-011 | Release dengan catatan (release_note tersimpan) | PASS | msg='Dokumen tagihan TAG-2611-00014 untuk invoice INV-2611-00049 diterbitkan. Invoice' \| release_note DB='Tagihan Nov 2026' \| harap='Tagihan Nov 2026' (invoice id=331) |
| TC-012 | Release invoice sudah released ditolak | PASS | http=200 \| msg='Invoice INV-2611-00041 sudah diterbitkan (status: released).' \| status DB=released |
| TC-013 | Release invoice punya pembayaran ditolak | PASS | paid_amount=1000000 \| http=200 \| msg='Invoice INV-2611-00050 sudah diterbitkan (status: released).' \| status DB=released \| paid setelah=1000000 |
| TC-014 | Tanggal terbit tidak valid ditolak | PASS | http=200 \| msg='Tanggal terbit tidak valid.' \| status DB=draft \| release_no=NULL |
| TC-015 | Bulk release (filter DRAFT) | PASS | released=2 \| msg='2 tagihan berhasil diterbitkan (INV-2611-00051, INV-2611-00052).' \| nos=TAG-2611-00016,TAG-2611-00017 \| uniq=ya \| fmt=OK \| seq=OK \| jurnal d1=2 d2=1 \| inv3 (pra-migrasi) draft=ya \| inv3 journal_lines=2 |
| TC-016 | Bulk release tanpa pilihan ditolak | PASS | http=200 \| msg='Tidak ada invoice yang dipilih untuk diterbitkan.' |
| TC-017 | Unrelease sebelum bayar | PASS | msg='Penerbitan invoice INV-2611-00053 dibatalkan; kembali ke DRAFT.' \| status=draft \| no/date/by=array (
  0 => NULL,
  1 => NULL,
  2 => NULL,
) \| note='' \| jurnal before/after=1/0 \| list_outstanding=hilang |
| TC-018 | Unrelease sudah ada pembayaran ditolak | PASS | paid_amount=500000 \| http=200 \| msg='Invoice INV-2611-00054 sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan.' \| status DB=released |
<!-- END:Kelompok B — Release (draft -> released) & Unrelease -->

<!-- BEGIN:Kelompok C — Pembayaran Termin & Jurnal Otomatis -->
## Kelompok C — Pembayaran Termin & Jurnal Otomatis

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-001 | Prasyarat sistem: fiscal_config & accounting_periods | PASS | rule aktif: PROJECT_INVOICE,PROJECT_INVOICE_PPN,PRJ_PAY_AR,PRJ_PAY_DEP,PRJ_DEP_APPLY \| periode 2026-11 is_closed=false |
| TC-002 | Simpan invoice baru = DRAFT | PASS | msg='Invoice INV-2611-00048 disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan re' \| status=draft \| jurnal=0 \| list_outstanding=tidak muncul (id=330, INV-2611-00048) |
| TC-003 | Edit & hapus invoice DRAFT | PASS | edit: OK \| hapus inv324: OK, baris hilang \| msg='Invoice INV-2611-00042 dihapus.' |
| TC-004 | Edit invoice RELEASED ditolak | PASS | http=200 \| success=false \| msg='Invoice INV-2611-00044 sudah diterbitkan (released); batalkan release dulu untuk mengubah.' |
| TC-005 | Hapus invoice RELEASED ditolak | PASS | http=200 \| msg='Invoice INV-2611-00044 sudah diterbitkan; batalkan release dulu sebelum menghapus.' \| baris masih ada=ya |
| TC-006 | Total DPP melebihi kontrak ditolak | PASS | msg='Total tagihan DPP (626,000,000.00) melebihi nilai kontrak (500,000,000.00).' \| contract_value=500,000,000.00 |
| TC-010 | Release satu invoice draft -> released | PASS | status=released \| release_no=TAG-2611-00013 \| tgl=2026-11-15 \| by=170 \| jurnal DPP:OK PPN:OK (dpp=10000000 ppn=1100000) \| list_outstanding=muncul |
| TC-011 | Release dengan catatan (release_note tersimpan) | PASS | msg='Dokumen tagihan TAG-2611-00014 untuk invoice INV-2611-00049 diterbitkan. Invoice' \| release_note DB='Tagihan Nov 2026' \| harap='Tagihan Nov 2026' (invoice id=331) |
| TC-012 | Release invoice sudah released ditolak | PASS | http=200 \| msg='Invoice INV-2611-00041 sudah diterbitkan (status: released).' \| status DB=released |
| TC-013 | Release invoice punya pembayaran ditolak | PASS | paid_amount=1000000 \| http=200 \| msg='Invoice INV-2611-00050 sudah diterbitkan (status: released).' \| status DB=released \| paid setelah=1000000 |
| TC-014 | Tanggal terbit tidak valid ditolak | PASS | http=200 \| msg='Tanggal terbit tidak valid.' \| status DB=draft \| release_no=NULL |
| TC-015 | Bulk release (filter DRAFT) | PASS | released=2 \| msg='2 tagihan berhasil diterbitkan (INV-2611-00051, INV-2611-00052).' \| nos=TAG-2611-00016,TAG-2611-00017 \| uniq=ya \| fmt=OK \| seq=OK \| jurnal d1=2 d2=1 \| inv3 (pra-migrasi) draft=ya \| inv3 journal_lines=2 |
| TC-016 | Bulk release tanpa pilihan ditolak | PASS | http=200 \| msg='Tidak ada invoice yang dipilih untuk diterbitkan.' |
| TC-017 | Unrelease sebelum bayar | PASS | msg='Penerbitan invoice INV-2611-00053 dibatalkan; kembali ke DRAFT.' \| status=draft \| no/date/by=array (
  0 => NULL,
  1 => NULL,
  2 => NULL,
) \| note='' \| jurnal before/after=1/0 \| list_outstanding=hilang |
| TC-018 | Unrelease sudah ada pembayaran ditolak | PASS | paid_amount=500000 \| http=200 \| msg='Invoice INV-2611-00054 sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan.' \| status DB=released |
| TC-020 | Bayar termin released (IDR, lunas) | PASS | payment_no=PPAY-2611-00003 \| paid_amount=8000000 \| jurnal PRJ_PAY_AR: OK \| msg='Pembayaran PPAY-2611-00003 berhasil disimpan.' |
| TC-021 | Bayar sebagian (50%) | PASS | paid_amount=3000000 \| sisa DB=3000000 \| list_outstanding remaining=3000000 \| msg='Pembayaran PPAY-2611-00004 berhasil disimpan.' |
| TC-022 | Lebih bayar -> deposit client | PASS | excess_amount=2000000 \| deposit saldo=2000000 \| jurnal PRJ_PAY_DEP: OK \| PRJ_PAY_AR (kas 7jt / piutang 5jt): DrKas=7,000,000.00 CrPiutang(20)=5,000,000.00 OK \| msg='Pembayaran PPAY-2611-00005 berhasil disimpan. Lebih bayar 2,000,000.00 masuk deposit clien' |
| TC-023 | Pakai deposit client (tanpa kas/bank) | PASS | deposit sebelum=2000000 sesudah=0 \| jurnal PRJ_DEP_APPLY: OK \| msg='Pembayaran PPAY-2611-00006 berhasil disimpan. Deposit dipaka' |
| TC-024 | Lunas penuh dari deposit (tanpa kas) | PASS | payment id=36 \| paid_amount=1000000 \| deposit sebelum=1500000 sesudah=500000 \| msg='Pembayaran PPAY-2611-00008 berhasil disimpan. Deposit dipakai 1,000,000.00.' |
| TC-025 | Multi-termin lintas proyek | PASS | payment_no=PPAY-2611-00009 \| paid A=2000000 B=3000000 \| jurnal PRJ_PAY_AR total 5.000.000: OK |
| TC-026 | Multi-currency (USD + kurs) | PASS | rate=15000 \| pay USD=266.67 \| paid_amount IDR=4000000 \| jurnal PRJ_PAY_AR 4.000.000 IDR: OK \| msg='Pembayaran PPAY-2611-00010 berhasil disimpan.' |
| TC-027 | Hapus pembayaran (jurnal di-reverse) | PASS | payment id=39 \| jurnal lines before/after=2/0 \| paid before/after=3000000/0 \| baris hilang=ya \| msg='Pembayaran PPAY-2611-00011 dihapus dan saldo termin dikembalikan.' |
| TC-028 | Hapus pembayaran sumber deposit terpakai | PASS | http=200 \| msg='Pembayaran ini tidak bisa dihapus: deposit yang dihasilkannya sudah dipakai pembayaran lain (kekurangan 500,000.00). Hapus pembayaran yang memakai deposit tersebut terlebih dahulu.' \| pembayaran sumber masih ada=ya |
| TC-029 | Cetak invoice released (PDF) | PASS | draft: STATUS: DRAFT ada \| released: header TAG + Tgl Terbit ada (release_no=TAG-2611-00031) |
| TC-030 | Faktur pajak duplikat ditolak | PASS | set A: OK \| duplikat B: ditolak \| msg='No. faktur pajak 000.000-2611.TC030 sudah dipakai invoice INV-2611-00068.' |
<!-- END:Kelompok C — Pembayaran Termin & Jurnal Otomatis -->

<!-- BEGIN:Kelompok D — Skenario Negatif, Rollback Atomik, Double-Release, Hak Akses -->
## Kelompok D — Skenario Negatif, Rollback Atomik, Double-Release, Hak Akses

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-001 | Prasyarat sistem: fiscal_config & accounting_periods | PASS | rule aktif: PROJECT_INVOICE,PROJECT_INVOICE_PPN,PRJ_PAY_AR,PRJ_PAY_DEP,PRJ_DEP_APPLY \| periode 2026-11 is_closed=false |
| TC-002 | Simpan invoice baru = DRAFT | PASS | msg='Invoice INV-2611-00048 disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan re' \| status=draft \| jurnal=0 \| list_outstanding=tidak muncul (id=330, INV-2611-00048) |
| TC-003 | Edit & hapus invoice DRAFT | PASS | edit: OK \| hapus inv324: OK, baris hilang \| msg='Invoice INV-2611-00042 dihapus.' |
| TC-004 | Edit invoice RELEASED ditolak | PASS | http=200 \| success=false \| msg='Invoice INV-2611-00044 sudah diterbitkan (released); batalkan release dulu untuk mengubah.' |
| TC-005 | Hapus invoice RELEASED ditolak | PASS | http=200 \| msg='Invoice INV-2611-00044 sudah diterbitkan; batalkan release dulu sebelum menghapus.' \| baris masih ada=ya |
| TC-006 | Total DPP melebihi kontrak ditolak | PASS | msg='Total tagihan DPP (626,000,000.00) melebihi nilai kontrak (500,000,000.00).' \| contract_value=500,000,000.00 |
| TC-010 | Release satu invoice draft -> released | PASS | status=released \| release_no=TAG-2611-00013 \| tgl=2026-11-15 \| by=170 \| jurnal DPP:OK PPN:OK (dpp=10000000 ppn=1100000) \| list_outstanding=muncul |
| TC-011 | Release dengan catatan (release_note tersimpan) | PASS | msg='Dokumen tagihan TAG-2611-00014 untuk invoice INV-2611-00049 diterbitkan. Invoice' \| release_note DB='Tagihan Nov 2026' \| harap='Tagihan Nov 2026' (invoice id=331) |
| TC-012 | Release invoice sudah released ditolak | PASS | http=200 \| msg='Invoice INV-2611-00041 sudah diterbitkan (status: released).' \| status DB=released |
| TC-013 | Release invoice punya pembayaran ditolak | PASS | paid_amount=1000000 \| http=200 \| msg='Invoice INV-2611-00050 sudah diterbitkan (status: released).' \| status DB=released \| paid setelah=1000000 |
| TC-014 | Tanggal terbit tidak valid ditolak | PASS | http=200 \| msg='Tanggal terbit tidak valid.' \| status DB=draft \| release_no=NULL |
| TC-015 | Bulk release (filter DRAFT) | PASS | released=2 \| msg='2 tagihan berhasil diterbitkan (INV-2611-00051, INV-2611-00052).' \| nos=TAG-2611-00016,TAG-2611-00017 \| uniq=ya \| fmt=OK \| seq=OK \| jurnal d1=2 d2=1 \| inv3 (pra-migrasi) draft=ya \| inv3 journal_lines=2 |
| TC-016 | Bulk release tanpa pilihan ditolak | PASS | http=200 \| msg='Tidak ada invoice yang dipilih untuk diterbitkan.' |
| TC-017 | Unrelease sebelum bayar | PASS | msg='Penerbitan invoice INV-2611-00053 dibatalkan; kembali ke DRAFT.' \| status=draft \| no/date/by=array (
  0 => NULL,
  1 => NULL,
  2 => NULL,
) \| note='' \| jurnal before/after=1/0 \| list_outstanding=hilang |
| TC-018 | Unrelease sudah ada pembayaran ditolak | PASS | paid_amount=500000 \| http=200 \| msg='Invoice INV-2611-00054 sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan.' \| status DB=released |
| TC-020 | Bayar termin released (IDR, lunas) | PASS | payment_no=PPAY-2611-00003 \| paid_amount=8000000 \| jurnal PRJ_PAY_AR: OK \| msg='Pembayaran PPAY-2611-00003 berhasil disimpan.' |
| TC-021 | Bayar sebagian (50%) | PASS | paid_amount=3000000 \| sisa DB=3000000 \| list_outstanding remaining=3000000 \| msg='Pembayaran PPAY-2611-00004 berhasil disimpan.' |
| TC-022 | Lebih bayar -> deposit client | PASS | excess_amount=2000000 \| deposit saldo=2000000 \| jurnal PRJ_PAY_DEP: OK \| PRJ_PAY_AR (kas 7jt / piutang 5jt): DrKas=7,000,000.00 CrPiutang(20)=5,000,000.00 OK \| msg='Pembayaran PPAY-2611-00005 berhasil disimpan. Lebih bayar 2,000,000.00 masuk deposit clien' |
| TC-023 | Pakai deposit client (tanpa kas/bank) | PASS | deposit sebelum=2000000 sesudah=0 \| jurnal PRJ_DEP_APPLY: OK \| msg='Pembayaran PPAY-2611-00006 berhasil disimpan. Deposit dipaka' |
| TC-024 | Lunas penuh dari deposit (tanpa kas) | PASS | payment id=36 \| paid_amount=1000000 \| deposit sebelum=1500000 sesudah=500000 \| msg='Pembayaran PPAY-2611-00008 berhasil disimpan. Deposit dipakai 1,000,000.00.' |
| TC-025 | Multi-termin lintas proyek | PASS | payment_no=PPAY-2611-00009 \| paid A=2000000 B=3000000 \| jurnal PRJ_PAY_AR total 5.000.000: OK |
| TC-026 | Multi-currency (USD + kurs) | PASS | rate=15000 \| pay USD=266.67 \| paid_amount IDR=4000000 \| jurnal PRJ_PAY_AR 4.000.000 IDR: OK \| msg='Pembayaran PPAY-2611-00010 berhasil disimpan.' |
| TC-027 | Hapus pembayaran (jurnal di-reverse) | PASS | payment id=39 \| jurnal lines before/after=2/0 \| paid before/after=3000000/0 \| baris hilang=ya \| msg='Pembayaran PPAY-2611-00011 dihapus dan saldo termin dikembalikan.' |
| TC-028 | Hapus pembayaran sumber deposit terpakai | PASS | http=200 \| msg='Pembayaran ini tidak bisa dihapus: deposit yang dihasilkannya sudah dipakai pembayaran lain (kekurangan 500,000.00). Hapus pembayaran yang memakai deposit tersebut terlebih dahulu.' \| pembayaran sumber masih ada=ya |
| TC-029 | Cetak invoice released (PDF) | PASS | draft: STATUS: DRAFT ada \| released: header TAG + Tgl Terbit ada (release_no=TAG-2611-00031) |
| TC-030 | Faktur pajak duplikat ditolak | PASS | set A: OK \| duplikat B: ditolak \| msg='No. faktur pajak 000.000-2611.TC030 sudah dipakai invoice INV-2611-00068.' |
| TC-040 | Bayar termin DRAFT | PASS | msg='Baris 1: termin INV-2611-00070 (Proyek A-1 E2E-REL) belum diterbitkan (status draft). Rele' \| harap='belum diterbitkan (status draft)' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| status DB='draft' |
| TC-041 | Termin silang client | PASS | msg='Baris 1: termin INV-2611-00071 (Proyek B-1 E2E-REL) bukan milik client ini.' \| harap='bukan milik client ini' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| inv INV-2611-00071 milik clientB=58 dialokasikan ke clientA=57 |
| TC-042 | Bayar melebihi sisa | PASS | msg='Termin INV-2611-00072 (Proyek A-1 E2E-REL): nilai bayar 3,000,000.00 IDR (setara 3,000,000' \| harap='melebihi sisa tagihan' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-043 | Termin dobel | PASS | msg='Baris 2: termin dobel.' \| harap='termin dobel' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-044 | Kas/bank kosong | PASS | msg='Akun Kas/Bank wajib dipilih.' \| harap='Akun Kas/Bank wajib dipilih.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-045 | Metode tidak valid | PASS | msg='Metode pembayaran tidak valid.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak |
| TC-046 | Kurs kosong untuk non-IDR | PASS | msg='Kurs USD wajib diisi.' \| harap='Kurs USD wajib diisi.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-047 | Deposit tidak cukup | PASS | msg='Deposit client tidak cukup (tersedia 1,000,000.00 IDR).' \| harap='Deposit client tidak cukup (tersedia' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| saldo deposit clientA saat uji=1000000 \| depIn=5.000.000 |
| TC-048 | Deposit > alokasi | PASS | msg='Deposit yang dipakai melebihi total alokasi termin.' \| harap='Deposit yang dipakai melebihi total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| deposit dibuat setup (lebih bayar 3.000.000) |
| TC-049 | Kas + deposit < alokasi | PASS | msg='Kas diterima + deposit lebih kecil dari total alokasi termin.' \| harap='Kas diterima + deposit lebih kecil dari total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-050 | Jumlah diterima <= 0 | PASS | msg='Jumlah diterima harus lebih dari 0.' \| harap='Jumlah diterima harus lebih dari 0.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-051 | Jumlah diterima < alokasi | PASS | msg='Jumlah diterima lebih kecil dari total alokasi termin.' \| harap='Jumlah diterima lebih kecil dari total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-052 | Tanpa alokasi | PASS | msg='Alokasikan pembayaran ke minimal satu termin.' \| harap='Alokasikan pembayaran ke minimal satu termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-053 | Periode akuntansi tutup -> ROLLBACK | PASS | periode 2026-11 ditutup sementara \| msg='Gagal posting jurnal: Periode akuntansi untuk tanggal 2026-11-15 sudah ditutup. Jurnal otomatis tidak bisa dib' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-054 | Rule fiscal nonaktif -> ROLLBACK | PASS | PRJ_PAY_AR dinonaktifkan sementara \| msg='Gagal posting jurnal: Rule posting 'PRJ_PAY_AR' tidak ditemukan atau tidak aktif di fiscal_config.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-055 | Akses level rendah ditolak | PASS | http=403 \| msg='Hak akses tidak mencukupi untuk operasi invoice proyek ini.' \| status DB=draft \| release_no=NULL |
| TC-056 | Metode bukan POST (GET) | PASS | http=405 \| msg='Metode harus POST untuk perubahan invoice proyek.' \| curl_err='' \| status DB=draft |
| TC-057 | release_note > 200 karakter ditolak | PASS | http=200 \| msg='Catatan penerbitan terlalu panjang (maksimal 200 karakter).' \| status DB=draft \| release_no=NULL \| panjang note=201 |
| TC-058 | Pencegahan double release (single + bulk atomik) | PASS | release_no tetap='TAG-2611-00046' \| release ke-2: msg='Invoice INV-2611-00088 sudah diterbitkan (status: released).' \| bulk: msg='Invoice INV-2611-00088 sudah diterbitkan (status: released).' \| draft tetap draft=ya \| jumlah TAG 42->42 |
<!-- END:Kelompok D — Skenario Negatif, Rollback Atomik, Double-Release, Hak Akses -->

<!-- BEGIN:Kelompok E — Release Layer pada Dashboard, Laporan PPN, Konteks AI, Invoice Otomatis, Ringkasan Kontrak -->
## Kelompok E — Release Layer pada Dashboard, Laporan PPN, Konteks AI, Invoice Otomatis, Ringkasan Kontrak

| TC | Skenario | Hasil | Bukti / Catatan |
|----|----------|-------|------------------|
| TC-001 | Prasyarat sistem: fiscal_config & accounting_periods | PASS | rule aktif: PROJECT_INVOICE,PROJECT_INVOICE_PPN,PRJ_PAY_AR,PRJ_PAY_DEP,PRJ_DEP_APPLY \| periode 2026-11 is_closed=false |
| TC-002 | Simpan invoice baru = DRAFT | PASS | msg='Invoice INV-2611-00048 disimpan sebagai DRAFT. Terbitkan (Release) agar menjadi tagihan re' \| status=draft \| jurnal=0 \| list_outstanding=tidak muncul (id=330, INV-2611-00048) |
| TC-003 | Edit & hapus invoice DRAFT | PASS | edit: OK \| hapus inv324: OK, baris hilang \| msg='Invoice INV-2611-00042 dihapus.' |
| TC-004 | Edit invoice RELEASED ditolak | PASS | http=200 \| success=false \| msg='Invoice INV-2611-00044 sudah diterbitkan (released); batalkan release dulu untuk mengubah.' |
| TC-005 | Hapus invoice RELEASED ditolak | PASS | http=200 \| msg='Invoice INV-2611-00044 sudah diterbitkan; batalkan release dulu sebelum menghapus.' \| baris masih ada=ya |
| TC-006 | Total DPP melebihi kontrak ditolak | PASS | msg='Total tagihan DPP (626,000,000.00) melebihi nilai kontrak (500,000,000.00).' \| contract_value=500,000,000.00 |
| TC-010 | Release satu invoice draft -> released | PASS | status=released \| release_no=TAG-2611-00013 \| tgl=2026-11-15 \| by=170 \| jurnal DPP:OK PPN:OK (dpp=10000000 ppn=1100000) \| list_outstanding=muncul |
| TC-011 | Release dengan catatan (release_note tersimpan) | PASS | msg='Dokumen tagihan TAG-2611-00014 untuk invoice INV-2611-00049 diterbitkan. Invoice' \| release_note DB='Tagihan Nov 2026' \| harap='Tagihan Nov 2026' (invoice id=331) |
| TC-012 | Release invoice sudah released ditolak | PASS | http=200 \| msg='Invoice INV-2611-00041 sudah diterbitkan (status: released).' \| status DB=released |
| TC-013 | Release invoice punya pembayaran ditolak | PASS | paid_amount=1000000 \| http=200 \| msg='Invoice INV-2611-00050 sudah diterbitkan (status: released).' \| status DB=released \| paid setelah=1000000 |
| TC-014 | Tanggal terbit tidak valid ditolak | PASS | http=200 \| msg='Tanggal terbit tidak valid.' \| status DB=draft \| release_no=NULL |
| TC-015 | Bulk release (filter DRAFT) | PASS | released=2 \| msg='2 tagihan berhasil diterbitkan (INV-2611-00051, INV-2611-00052).' \| nos=TAG-2611-00016,TAG-2611-00017 \| uniq=ya \| fmt=OK \| seq=OK \| jurnal d1=2 d2=1 \| inv3 (pra-migrasi) draft=ya \| inv3 journal_lines=2 |
| TC-016 | Bulk release tanpa pilihan ditolak | PASS | http=200 \| msg='Tidak ada invoice yang dipilih untuk diterbitkan.' |
| TC-017 | Unrelease sebelum bayar | PASS | msg='Penerbitan invoice INV-2611-00053 dibatalkan; kembali ke DRAFT.' \| status=draft \| no/date/by=array (
  0 => NULL,
  1 => NULL,
  2 => NULL,
) \| note='' \| jurnal before/after=1/0 \| list_outstanding=hilang |
| TC-018 | Unrelease sudah ada pembayaran ditolak | PASS | paid_amount=500000 \| http=200 \| msg='Invoice INV-2611-00054 sudah memiliki pembayaran, penerbitannya tidak dapat dibatalkan.' \| status DB=released |
| TC-020 | Bayar termin released (IDR, lunas) | PASS | payment_no=PPAY-2611-00003 \| paid_amount=8000000 \| jurnal PRJ_PAY_AR: OK \| msg='Pembayaran PPAY-2611-00003 berhasil disimpan.' |
| TC-021 | Bayar sebagian (50%) | PASS | paid_amount=3000000 \| sisa DB=3000000 \| list_outstanding remaining=3000000 \| msg='Pembayaran PPAY-2611-00004 berhasil disimpan.' |
| TC-022 | Lebih bayar -> deposit client | PASS | excess_amount=2000000 \| deposit saldo=2000000 \| jurnal PRJ_PAY_DEP: OK \| PRJ_PAY_AR (kas 7jt / piutang 5jt): DrKas=7,000,000.00 CrPiutang(20)=5,000,000.00 OK \| msg='Pembayaran PPAY-2611-00005 berhasil disimpan. Lebih bayar 2,000,000.00 masuk deposit clien' |
| TC-023 | Pakai deposit client (tanpa kas/bank) | PASS | deposit sebelum=2000000 sesudah=0 \| jurnal PRJ_DEP_APPLY: OK \| msg='Pembayaran PPAY-2611-00006 berhasil disimpan. Deposit dipaka' |
| TC-024 | Lunas penuh dari deposit (tanpa kas) | PASS | payment id=36 \| paid_amount=1000000 \| deposit sebelum=1500000 sesudah=500000 \| msg='Pembayaran PPAY-2611-00008 berhasil disimpan. Deposit dipakai 1,000,000.00.' |
| TC-025 | Multi-termin lintas proyek | PASS | payment_no=PPAY-2611-00009 \| paid A=2000000 B=3000000 \| jurnal PRJ_PAY_AR total 5.000.000: OK |
| TC-026 | Multi-currency (USD + kurs) | PASS | rate=15000 \| pay USD=266.67 \| paid_amount IDR=4000000 \| jurnal PRJ_PAY_AR 4.000.000 IDR: OK \| msg='Pembayaran PPAY-2611-00010 berhasil disimpan.' |
| TC-027 | Hapus pembayaran (jurnal di-reverse) | PASS | payment id=39 \| jurnal lines before/after=2/0 \| paid before/after=3000000/0 \| baris hilang=ya \| msg='Pembayaran PPAY-2611-00011 dihapus dan saldo termin dikembalikan.' |
| TC-028 | Hapus pembayaran sumber deposit terpakai | PASS | http=200 \| msg='Pembayaran ini tidak bisa dihapus: deposit yang dihasilkannya sudah dipakai pembayaran lain (kekurangan 500,000.00). Hapus pembayaran yang memakai deposit tersebut terlebih dahulu.' \| pembayaran sumber masih ada=ya |
| TC-029 | Cetak invoice released (PDF) | PASS | draft: STATUS: DRAFT ada \| released: header TAG + Tgl Terbit ada (release_no=TAG-2611-00031) |
| TC-030 | Faktur pajak duplikat ditolak | PASS | set A: OK \| duplikat B: ditolak \| msg='No. faktur pajak 000.000-2611.TC030 sudah dipakai invoice INV-2611-00068.' |
| TC-040 | Bayar termin DRAFT | PASS | msg='Baris 1: termin INV-2611-00070 (Proyek A-1 E2E-REL) belum diterbitkan (status draft). Rele' \| harap='belum diterbitkan (status draft)' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| status DB='draft' |
| TC-041 | Termin silang client | PASS | msg='Baris 1: termin INV-2611-00071 (Proyek B-1 E2E-REL) bukan milik client ini.' \| harap='bukan milik client ini' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| inv INV-2611-00071 milik clientB=58 dialokasikan ke clientA=57 |
| TC-042 | Bayar melebihi sisa | PASS | msg='Termin INV-2611-00072 (Proyek A-1 E2E-REL): nilai bayar 3,000,000.00 IDR (setara 3,000,000' \| harap='melebihi sisa tagihan' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-043 | Termin dobel | PASS | msg='Baris 2: termin dobel.' \| harap='termin dobel' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-044 | Kas/bank kosong | PASS | msg='Akun Kas/Bank wajib dipilih.' \| harap='Akun Kas/Bank wajib dipilih.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-045 | Metode tidak valid | PASS | msg='Metode pembayaran tidak valid.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak |
| TC-046 | Kurs kosong untuk non-IDR | PASS | msg='Kurs USD wajib diisi.' \| harap='Kurs USD wajib diisi.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-047 | Deposit tidak cukup | PASS | msg='Deposit client tidak cukup (tersedia 1,000,000.00 IDR).' \| harap='Deposit client tidak cukup (tersedia' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| saldo deposit clientA saat uji=1000000 \| depIn=5.000.000 |
| TC-048 | Deposit > alokasi | PASS | msg='Deposit yang dipakai melebihi total alokasi termin.' \| harap='Deposit yang dipakai melebihi total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 \| deposit dibuat setup (lebih bayar 3.000.000) |
| TC-049 | Kas + deposit < alokasi | PASS | msg='Kas diterima + deposit lebih kecil dari total alokasi termin.' \| harap='Kas diterima + deposit lebih kecil dari total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-050 | Jumlah diterima <= 0 | PASS | msg='Jumlah diterima harus lebih dari 0.' \| harap='Jumlah diterima harus lebih dari 0.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-051 | Jumlah diterima < alokasi | PASS | msg='Jumlah diterima lebih kecil dari total alokasi termin.' \| harap='Jumlah diterima lebih kecil dari total alokasi termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-052 | Tanpa alokasi | PASS | msg='Alokasikan pembayaran ke minimal satu termin.' \| harap='Alokasikan pembayaran ke minimal satu termin.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-053 | Periode akuntansi tutup -> ROLLBACK | PASS | periode 2026-11 ditutup sementara \| msg='Gagal posting jurnal: Periode akuntansi untuk tanggal 2026-11-15 sudah ditutup. Jurnal otomatis tidak bisa dib' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-054 | Rule fiscal nonaktif -> ROLLBACK | PASS | PRJ_PAY_AR dinonaktifkan sementara \| msg='Gagal posting jurnal: Rule posting 'PRJ_PAY_AR' tidak ditemukan atau tidak aktif di fiscal_config.' \| PPAY baru=tidak ada \| paid_amount berubah=tidak \| jurnal pembayaran=0 |
| TC-055 | Akses level rendah ditolak | PASS | http=403 \| msg='Hak akses tidak mencukupi untuk operasi invoice proyek ini.' \| status DB=draft \| release_no=NULL |
| TC-056 | Metode bukan POST (GET) | PASS | http=405 \| msg='Metode harus POST untuk perubahan invoice proyek.' \| curl_err='' \| status DB=draft |
| TC-057 | release_note > 200 karakter ditolak | PASS | http=200 \| msg='Catatan penerbitan terlalu panjang (maksimal 200 karakter).' \| status DB=draft \| release_no=NULL \| panjang note=201 |
| TC-058 | Pencegahan double release (single + bulk atomik) | PASS | release_no tetap='TAG-2611-00046' \| release ke-2: msg='Invoice INV-2611-00088 sudah diterbitkan (status: released).' \| bulk: msg='Invoice INV-2611-00088 sudah diterbitkan (status: released).' \| draft tetap draft=ya \| jumlah TAG 42->42 |
| TC-060 | Aging AR hanya menghitung released (abaikan draft) | PASS | http=200 \| ar.total=5,550,000.00 harap=5,550,000.00 \| ar.count=1 \| draft di proyek=2 (sisa 9325000) \| draft dihitung=TIDAK \| msg='' |
| TC-061 | Pipeline & action center hanya menghitung released | PASS | pipeline counts={"Lunas":0,"Jatuh Tempo (Overdue)":0,"Sebagian":0,"Belum Bayar":1} (jumlah=1, released=1) \| action center: inv_overdue=0 (harap 0) inv_due_soon=0 (harap 0) faktur_missing=1 (harap 1) \| draft bermasalah di DB=3 (overdue/due-soon/ber-PPN tanpa faktur) tetap tidak dihitung \| http pipe=200 ac=200 |
| TC-062 | Laporan PPN keluaran hanya memuat released | PASS | http=200 \| baris=10 \| draft id=327 muncul=tidak \| released id=328 muncul=ya \| msg='' |
| TC-063 | Konteks AI hanya menghitung released (inv_cnt/billed_dpp) | PASS | http=200 \| tertagih_dpp=5,000,000.00 harap=5,000,000.00 \| inv_cnt=1 harap=1 \| draft di DB=4 (DPP 7.500.000) ikut=TIDAK \| log tersimpan=tidak (verifikasi via SQL context) \| msg='Belum ada hasil analisis tersimpan. Tekan Analisis Ulang untuk membuat analisis ' |
| TC-063 | Konteks AI (draft ber-PPN tidak masuk released) | PASS | draft id=327 ada di released list=TIDAK \| released ids=328 |
| TC-064 | Invoice otomatis = DRAFT tanpa jurnal; sync tanggal tidak posting jurnal | PASS | issue-1: made=4 (http=200) \| semua draft=ya release_no NULL=ya auto_generated=ya \| jurnal before/after1/after2=0/0/0 \| issue-2: made=0 synced=2 (http=200) \| tanggal invoice berubah=ya \| tetap draft=ya \| msg1='4 tagihan diterbitkan, 0 tagihan disesuaikan tanggalnya.' \| msg2='0 tagihan diterbitkan, 2 tagihan disesuaikan tanggalnya.' |
| TC-065 | Outstanding hanya released; draft dilaporkan terpisah di ringkasan | PASS | http=200 \| draft id=327 di outstanding=tidak \| released id=328 di outstanding=ya \| ringkasan proyek E: total_termin=5,550,000.00 (harap 5,550,000.00) total_dpp=5,000,000.00 (harap 5,000,000.00) total_draft=13,500,000.00 (harap 13,500,000.00) draft_count=4 (harap 4) \| msg='' |
<!-- END:Kelompok E — Release Layer pada Dashboard, Laporan PPN, Konteks AI, Invoice Otomatis, Ringkasan Kontrak -->
