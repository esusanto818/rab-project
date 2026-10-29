<?php
/* Document      : purchaseinvoiceCRD.php
   Description   : Backend CRUD untuk Purchase Invoice
   [FIX] - Tambah pengecekan koneksi DB eksplisit + fallback pesan error
           supaya tidak pernah tampil pesan kosong ke frontend.
         - JOIN supplier diperbaiki: m_suppliers (bukan clients), sesuai FK asli.
         - purchase_invoice_dtl: nama kolom diperbaiki ke invoice_hdr_id /
           grn_hdr_id (sesuai skema asli, sebelumnya invoice_id / grn_dtl_id
           yang TIDAK ADA di tabel -> selalu gagal saat detail dibaca/disimpan).
         - read_invoice_detail: karena grn_hdr_id mengarah ke grn_hdr (level
           dokumen, bukan level item), di-JOIN + GROUP BY ke grn_dtl supaya
           qty/harga/nama item tetap bisa ditampilkan.
   [PPN - TAHAP 1b 2026-10-26] PPN Masukan di invoice supplier (tarif 11% default di UI):
         - total_amount = DPP + PPN (nilai hutang ke supplier, dipakai pembayaran); dpp_amount = jumlah matched GRN.
         - ppn_rate / ppn_amount (boleh diisi sesuai faktur pajak supplier; selisih dari hitungan dibatasi PI_PPN_TOLERANCE),
           faktur_pajak_no (unik per supplier) / faktur_pajak_date.
         - (SEBELUMNYA tidak ada jurnal di invoice supplier; sejak 2026-10-29 DIJURNAL, lihat bagian [JURNAL] di bawah.)
         Butuh migrasi: sql/2026-10-26.ppn_invoice.sql
   [JURNAL 2026-10-29] Invoice supplier kini dijurnal (keputusan: aktifkan sebelum pembayaran supplier pertama, agar Hutang Usaha
         terbentuk dan GRN Clearing ter-offset; tanpa ini pembayaran supplier membuat Hutang Usaha bersaldo debit):
         - rule PUR_INVOICE          : Dr 2600 GRN Clearing   / Cr 2100 Hutang Usaha  sebesar DPP
         - rule PURCHASE_INVOICE_PPN : Dr 2400 PPN Masukan    / Cr 2100 Hutang Usaha  sebesar PPN (hanya bila PPN > 0)
         - reference_type 'purchase_invoice', tanggal jurnal = tanggal invoice; edit = reverse + posting ulang; hapus = reverse.
         - create / update / delete dipindah ke PDO (class crud) dalam SATU transaksi bersama jurnal (AutoPostJournal butuh PDO,
           sedangkan koneksi pg_* tidak bisa berbagi transaksi). Task read tetap memakai pg_*.
         - delete ditolak bila invoice sudah ada pembayaran supplier.
         - task baru 'backfill_journal' (preview / run) untuk memposting jurnal invoice lama yang belum dijurnal.
         Butuh migrasi: sql/2026-10-29.purchase_invoice_journal.sql (menambah nilai enum reference_type 'purchase_invoice')
   [BARU 2026-10-08]
         - task 'read_invoice_print': data Tanda Terima Invoice (read-only, tidak mengubah data).
         - create: kolom created_by ikut diisi dari sesi (kolom sudah ada di tabel, sebelumnya kosong)
           supaya nama penerima tampil di cetakan. */

session_start();

if (file_exists('../php-func/function.all.php')) {
    require_once('../php-func/function.all.php');
}
// [JURNAL] class crud (PDO) untuk transaksi tulis + AutoPostJournal / ReverseJournalByReference
if (file_exists('../php-class/crudClass.php')) {
    require_once('../php-class/crudClass.php');
}

$task = isset($_POST['task']) ? $_POST['task'] : (isset($_GET['task']) ? $_GET['task'] : '');

@ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');

// [FIX] Pengecekan koneksi eksplisit. Sebelumnya kode di sini hanya
// mengecek function_exists('pg_connect') === false, yang praktis tidak
// pernah true (pg_connect selalu ada kalau ekstensi pgsql terpasang),
// jadi baris pg_connect() manual di bawahnya tidak pernah jalan. Kalau
// function.all.php gagal membuka koneksi, pg_query() di bawah akan gagal
// TANPA pesan (pg_last_error() bisa balik string kosong kalau memang
// belum ada koneksi aktif sama sekali) -> di frontend muncul pesan
// default "Gagal memuat data main grid." tanpa detail.
if (!function_exists('pg_query')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ekstensi PostgreSQL (pgsql) tidak tersedia di server.'
    ]);
    exit;
}

$testConn = @pg_query("SELECT 1");
if (!$testConn) {
    echo json_encode([
        'success' => false,
        'message' => 'Tidak ada koneksi database aktif. Cek path/isi function.all.php (../php-func/function.all.php) dan pastikan pg_connect() berhasil dipanggil sebelum file ini dieksekusi.'
    ]);
    exit;
}

// [FIX] Helper supaya pesan error TIDAK PERNAH kosong ke frontend.
function pgErrorOrFallback($fallback = 'Terjadi kesalahan pada database.') {
    $err = pg_last_error();
    return $err !== '' ? $err : $fallback;
}

// [PPN] ---------------------------------------------------------------
const PI_PPN_TOLERANCE   = 100.00;                    // selisih maks (Rp) antara PPN input (faktur) dan hitungan DPP x tarif
const PI_JOURNAL_REF     = 'purchase_invoice';        // [JURNAL] reference_type jurnal invoice supplier

/** [JURNAL] pastikan enum reference_type sudah memuat 'purchase_invoice' (bila kolom berupa enum). */
function PiAssertJournalRefType($db) {
    $st = $db->query("SELECT t.typtype, EXISTS (SELECT 1 FROM pg_enum e WHERE e.enumtypid = t.oid AND e.enumlabel = '" . PI_JOURNAL_REF . "') AS has_label
                        FROM pg_attribute a
                        JOIN pg_class c ON c.oid = a.attrelid
                        JOIN pg_type t ON t.oid = a.atttypid
                       WHERE c.relname = 'journal_entries' AND a.attname = 'reference_type' AND NOT a.attisdropped
                       LIMIT 1");
    $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if ($r && $r['typtype'] === 'e' && !in_array($r['has_label'], [true, 't', '1', 1], true)) {
        throw new Exception("Tipe jurnal 'purchase_invoice' belum terdaftar di database. Jalankan sql/2026-10-29.purchase_invoice_journal.sql terlebih dahulu.");
    }
}

function PiHasJournal($db, $invoiceId) {
    $st = $db->prepare("SELECT 1 FROM journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => PI_JOURNAL_REF, ':id' => $invoiceId]);
    return (bool)$st->fetchColumn();
}

/** [JURNAL] posting jurnal invoice supplier: DPP (GRN Clearing -> Hutang) + PPN Masukan (-> Hutang). Panggil di dalam transaksi. */
function PiPostJournal($db, $invoiceId, $invNo, $supplierInvNo, $invDate, $dpp, $ppn) {
    $desc = 'Invoice supplier ' . $invNo . ($supplierInvNo !== '' ? ' (' . $supplierInvNo . ')' : '');
    if ($dpp > 0.005) {
        $r = AutoPostJournal($db, [
            'rule_code' => 'PUR_INVOICE', 'reference_type' => PI_JOURNAL_REF, 'reference_id' => $invoiceId,
            'journal_date' => $invDate, 'amount' => $dpp, 'description' => $desc,
        ]);
        if (!$r['success']) throw new Exception('Gagal posting jurnal invoice: ' . $r['message']);
    }
    if ($ppn > 0.005) {
        $r = AutoPostJournal($db, [
            'rule_code' => 'PURCHASE_INVOICE_PPN', 'reference_type' => PI_JOURNAL_REF, 'reference_id' => $invoiceId,
            'journal_date' => $invDate, 'amount' => $ppn, 'description' => 'PPN Masukan ' . $desc,
        ]);
        if (!$r['success']) throw new Exception('Gagal posting jurnal PPN Masukan: ' . $r['message']);
    }
}

try {
    switch ($task) {
        case 'read':
            $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
            $start  = isset($_POST['start']) ? intval($_POST['start']) : 0;

            $sqlTotal = "SELECT COUNT(*) AS total FROM purchase_invoice_hdr";
            $resTotal = pg_query($sqlTotal);
            if (!$resTotal) throw new Exception(pgErrorOrFallback('Gagal menghitung total data invoice.'));

            $rowTotal = pg_fetch_assoc($resTotal);
            $totalCount = intval($rowTotal['total']);

            // [FIX] supplier_id FK sebenarnya mengarah ke m_suppliers(id),
            // bukan clients(id) — sebelumnya JOIN ke clients membuat
            // supplier_name selalu kosong/salah.
            $sql = "SELECT h.*, s.name AS supplier_name
                    FROM purchase_invoice_hdr h
                    LEFT JOIN m_suppliers s ON s.id = h.supplier_id
                    ORDER BY h.id DESC LIMIT $limit OFFSET $start";
            $result = pg_query($sql);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat data main grid.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['total_amount'] = floatval(isset($row['total_amount']) ? $row['total_amount'] : 0);
                $row['paid_amount'] = floatval(isset($row['paid_amount']) ? $row['paid_amount'] : 0);
                $data[] = $row;
            }

            echo json_encode([
                'success' => true,
                'totalCount' => $totalCount,
                'data' => $data
            ]);
            break;

        case 'read_available_grn':
            // [BARU] Daftar GRN (grn_hdr) yang bisa dipilih untuk di-match ke invoice.
            // Difilter berdasarkan supplier yang sama dengan invoice, status masih
            // open/partial. Agregasi qty/harga dari grn_dtl (lihat catatan di
            // read_invoice_detail soal grn_hdr_id mengarah ke level dokumen).
            $supplier_id = isset($_REQUEST['supplier_id']) ? intval($_REQUEST['supplier_id']) : 0;

            $sql = "SELECT h.id AS grn_hdr_id, h.ref_no AS grn_ref_no, h.grn_date, h.supplier_id,
                           string_agg(DISTINCT gd.item_name, ', ') AS item_name,
                           SUM(gd.qty_received) AS qty_received,
                           CASE WHEN SUM(gd.qty_received) > 0
                                THEN SUM(gd.qty_received * gd.unit_cost) / SUM(gd.qty_received)
                                ELSE 0 END AS unit_cost,
                           SUM(gd.qty_received * gd.unit_cost) AS grn_total,
                           COALESCE(im.already_matched, 0) AS already_matched,
                           SUM(gd.qty_received * gd.unit_cost) - COALESCE(im.already_matched, 0) AS remaining
                    FROM grn_hdr h
                    JOIN grn_dtl gd ON gd.grn_hdr_id = h.id
                    LEFT JOIN (
                        SELECT grn_hdr_id, SUM(matched_amount) AS already_matched
                        FROM purchase_invoice_dtl
                        GROUP BY grn_hdr_id
                    ) im ON im.grn_hdr_id = h.id
                    WHERE h.status IN ('open', 'partial')
                      AND ($1 = 0 OR h.supplier_id = $1)
                    GROUP BY h.id, h.ref_no, h.grn_date, h.supplier_id, im.already_matched
                    -- [BARU] Invoicing partial dibolehkan: GRN tetap muncul selama
                    -- masih ada sisa nilai yang belum di-match ke invoice manapun.
                    HAVING SUM(gd.qty_received * gd.unit_cost) - COALESCE(im.already_matched, 0) > 0
                    ORDER BY h.grn_date DESC";

            $result = pg_query_params($sql, [$supplier_id]);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat daftar GRN.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['qty_received'] = floatval($row['qty_received']);
                $row['unit_cost'] = floatval($row['unit_cost']);
                $row['grn_total'] = floatval($row['grn_total']);
                $row['already_matched'] = floatval($row['already_matched']);
                $row['remaining'] = floatval($row['remaining']);
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_supplier':
            // [FIX] konsisten pakai m_suppliers juga di sini.
            $sql = "SELECT id, name, COALESCE(is_pkp, false) AS is_pkp FROM m_suppliers WHERE active IS NOT FALSE ORDER BY name ASC";   // [PPN] is_pkp
            $result = pg_query($sql);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat daftar supplier.'));
            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['is_pkp'] = ($row['is_pkp'] === 't' || $row['is_pkp'] === true);   // [PPN]
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_invoice_detail':
            $invoice_id = isset($_REQUEST['invoice_id']) ? intval($_REQUEST['invoice_id']) : 0;

            // [FIX] Kolom sebelumnya (d.invoice_id, d.grn_dtl_id) tidak ada
            // di tabel purchase_invoice_dtl -> query selalu gagal.
            // grn_hdr_id mengarah ke grn_hdr (dokumen), jadi di-JOIN +
            // GROUP BY ke grn_dtl untuk tetap dapat qty/harga/nama item.
            $sql = "SELECT d.id, d.invoice_hdr_id, d.grn_hdr_id, d.matched_amount, d.remark,
                           h.ref_no AS grn_ref_no,
                           string_agg(DISTINCT gd.item_name, ', ') AS item_name,
                           SUM(gd.qty_received) AS qty_received,
                           CASE WHEN SUM(gd.qty_received) > 0
                                THEN SUM(gd.qty_received * gd.unit_cost) / SUM(gd.qty_received)
                                ELSE 0 END AS unit_cost,
                           SUM(gd.qty_received * gd.unit_cost) AS grn_total
                    FROM purchase_invoice_dtl d
                    JOIN grn_hdr h ON h.id = d.grn_hdr_id
                    LEFT JOIN grn_dtl gd ON gd.grn_hdr_id = h.id
                    WHERE d.invoice_hdr_id = $1
                    GROUP BY d.id, d.invoice_hdr_id, d.grn_hdr_id, d.matched_amount, d.remark, h.ref_no";

            $result = pg_query_params($sql, [$invoice_id]);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat detail invoice.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['matched_amount'] = floatval($row['matched_amount']);
                $row['qty_received'] = floatval($row['qty_received']);
                $row['unit_cost'] = floatval($row['unit_cost']);
                $row['grn_total'] = floatval($row['grn_total']);
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_invoice_print':
            // [BARU] Data Tanda Terima Invoice (header + GRN yang di-match) untuk dicetak.
            $invoice_id = isset($_REQUEST['invoice_id']) ? intval($_REQUEST['invoice_id']) : 0;
            if ($invoice_id <= 0) throw new Exception('Invoice tidak valid.');

            $sqlH = "SELECT h.id, h.inv_no, h.supplier_invoice_no, h.invoice_date, h.created_at, h.status,
                            h.remark, h.total_amount, s.name AS supplier_name,
                            h.dpp_amount, h.ppn_rate, h.ppn_amount, h.faktur_pajak_no, h.faktur_pajak_date,   -- [PPN]
                            COALESCE(e.employee_name, u.login_name) AS created_by_name
                     FROM purchase_invoice_hdr h
                     LEFT JOIN m_suppliers s ON s.id = h.supplier_id
                     LEFT JOIN m_user_system u ON u.id = h.created_by
                     LEFT JOIN m_employee e ON e.id = u.employee_id
                     WHERE h.id = $1";
            $resH = pg_query_params($sqlH, [$invoice_id]);
            if (!$resH) throw new Exception(pgErrorOrFallback('Gagal memuat header invoice.'));
            $hdr = pg_fetch_assoc($resH);
            if (!$hdr) throw new Exception('Invoice tidak ditemukan.');
            $hdr['total_amount'] = floatval($hdr['total_amount']);
            $hdr['dpp_amount'] = floatval($hdr['dpp_amount']);     // [PPN]
            $hdr['ppn_rate'] = floatval($hdr['ppn_rate']);         // [PPN]
            $hdr['ppn_amount'] = floatval($hdr['ppn_amount']);     // [PPN]

            $sqlD = "SELECT d.id, d.grn_hdr_id, d.matched_amount, d.remark,
                            g.ref_no AS grn_ref_no, g.grn_date,
                            p.po_number AS po_ref_no,
                            UPPER(TRIM(cur.init)) AS currency_code,
                            (SELECT string_agg(DISTINCT gd.item_name, ', ') FROM grn_dtl gd WHERE gd.grn_hdr_id = g.id) AS item_name
                     FROM purchase_invoice_dtl d
                     JOIN grn_hdr g ON g.id = d.grn_hdr_id
                     LEFT JOIN purchase_orders p ON p.id = g.po_id
                     LEFT JOIN m_currency cur ON cur.id = g.currency_id
                     WHERE d.invoice_hdr_id = $1
                     ORDER BY d.id";
            $resD = pg_query_params($sqlD, [$invoice_id]);
            if (!$resD) throw new Exception(pgErrorOrFallback('Gagal memuat rincian invoice.'));
            $items = [];
            while ($row = pg_fetch_assoc($resD)) {
                $row['matched_amount'] = floatval($row['matched_amount']);
                $items[] = $row;
            }
            echo json_encode(['success' => true, 'data' => ['header' => $hdr, 'items' => $items]]);
            break;

        case 'create':
        case 'update':
            // [JURNAL] seluruh penulisan memakai PDO dalam satu transaksi (header + detail + total + jurnal)
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            // [FIX] inv_no adalah No. Ref Dokumen yang di-generate sistem, bukan input user -> $_POST['inv_no'] diabaikan.
            $supplier_invoice_no = isset($_POST['supplier_invoice_no']) ? $_POST['supplier_invoice_no'] : '';
            $invoice_date = isset($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
            $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
            $remark = isset($_POST['remark']) ? $_POST['remark'] : '';
            // [PPN] input PPN Masukan (ppn_rate 0 = tanpa PPN; ppn_amount kosong = hitung otomatis DPP x tarif)
            $ppn_rate   = isset($_POST['ppn_rate']) && $_POST['ppn_rate'] !== '' ? round(floatval($_POST['ppn_rate']), 2) : 0.0;
            $ppn_amount_in = (isset($_POST['ppn_amount']) && $_POST['ppn_amount'] !== '') ? round(floatval($_POST['ppn_amount']), 2) : null;
            $fp_no      = isset($_POST['faktur_pajak_no']) ? trim($_POST['faktur_pajak_no']) : '';
            $fp_date    = isset($_POST['faktur_pajak_date']) ? trim($_POST['faktur_pajak_date']) : '';
            if ($ppn_rate < 0 || $ppn_rate > 100) throw new Exception('Tarif PPN harus antara 0 - 100%.');
            if ($fp_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fp_date)) throw new Exception('Tanggal faktur pajak tidak valid (YYYY-MM-DD).');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice_date)) throw new Exception('Tanggal invoice tidak valid (YYYY-MM-DD).');
            if ($supplier_id <= 0) throw new Exception('Supplier wajib dipilih.');

            $invoice_details = isset($_POST['invoice_details']) ? json_decode($_POST['invoice_details'], true) : [];
            if (!is_array($invoice_details) || !count($invoice_details)) throw new Exception('Minimal satu GRN harus di-match ke invoice.');

            $db = new crud();
            $db->beginTransaction();
            PiAssertJournalRefType($db);

            if ($task == 'create') {
                // No. Ref Dokumen PINV-YYMM-NNNNN: nomor terakhir bulan ini + 1 (aman setelah ada invoice yang dihapus)
                $prefix = 'PINV-' . date('ym') . '-';
                $stSeq = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(inv_no, 5) AS integer)), 0) + 1
                                         FROM purchase_invoice_hdr WHERE inv_no LIKE :p AND inv_no ~ '-[0-9]{5}$'");
                $stSeq->execute([':p' => $prefix . '%']);
                $inv_no = $prefix . str_pad((string)$stSeq->fetchColumn(), 5, '0', STR_PAD_LEFT);

                $created_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
                $stHdr = $db->prepare("INSERT INTO purchase_invoice_hdr (inv_no, supplier_invoice_no, invoice_date, supplier_id, remark, status, created_by)
                                       VALUES (:no, :sno, CAST(:d AS date), :sup, :rm, 'posted', :cb) RETURNING id");
                $stHdr->execute([':no' => $inv_no, ':sno' => $supplier_invoice_no, ':d' => $invoice_date, ':sup' => $supplier_id, ':rm' => $remark, ':cb' => $created_by]);
                $invoice_id = (int)$stHdr->fetchColumn();
            } else {
                $invoice_id = $id;
                $stCur = $db->prepare("SELECT inv_no FROM purchase_invoice_hdr WHERE id = :id FOR UPDATE");
                $stCur->execute([':id' => $invoice_id]);
                $inv_no = $stCur->fetchColumn();
                if ($inv_no === false) throw new Exception('Invoice tidak ditemukan.');

                // [FIX] inv_no sengaja tidak ikut di SET -> No. Ref Dokumen immutable setelah dibuat.
                $stUp = $db->prepare("UPDATE purchase_invoice_hdr SET supplier_invoice_no = :sno, invoice_date = CAST(:d AS date), supplier_id = :sup, remark = :rm WHERE id = :id");
                $stUp->execute([':sno' => $supplier_invoice_no, ':d' => $invoice_date, ':sup' => $supplier_id, ':rm' => $remark, ':id' => $invoice_id]);
                $db->prepare("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = :id")->execute([':id' => $invoice_id]);
            }

            $total_amount_header = 0;
            $stDtl = $db->prepare("INSERT INTO purchase_invoice_dtl (invoice_hdr_id, grn_hdr_id, matched_amount) VALUES (:h, :g, :m)");
            foreach ($invoice_details as $item) {
                $grn_hdr_id = intval(isset($item['grn_hdr_id']) ? $item['grn_hdr_id'] : (isset($item['grn_dtl_id']) ? $item['grn_dtl_id'] : 0));
                $matched_amount = round(floatval($item['matched_amount']), 2);
                if ($grn_hdr_id <= 0) throw new Exception('GRN pada rincian invoice tidak valid.');
                if ($matched_amount <= 0) throw new Exception('Nominal matched harus lebih besar dari 0.');
                $total_amount_header += $matched_amount;
                $stDtl->execute([':h' => $invoice_id, ':g' => $grn_hdr_id, ':m' => $matched_amount]);
            }

            // [PPN] DPP = jumlah matched; PPN = DPP x tarif (atau nilai faktur dalam toleransi); total hutang = DPP + PPN
            $dpp = round($total_amount_header, 2);
            $ppn_calc = ($ppn_rate > 0) ? round($dpp * $ppn_rate / 100, 2) : 0.0;
            if ($ppn_rate > 0 && $ppn_amount_in !== null) {
                if (abs($ppn_amount_in - $ppn_calc) > PI_PPN_TOLERANCE) {
                    throw new Exception('PPN yang diinput (' . number_format($ppn_amount_in, 2) . ') berbeda dari hitungan DPP x ' . $ppn_rate . '% (' .
                        number_format($ppn_calc, 2) . ') lebih dari toleransi ' . number_format(PI_PPN_TOLERANCE, 2) . '. Kosongkan kolom PPN untuk hitung otomatis.');
                }
                $ppn_amount_final = $ppn_amount_in;
            } else {
                $ppn_amount_final = $ppn_calc;
            }
            $total_with_ppn = round($dpp + $ppn_amount_final, 2);

            // [PPN] no. faktur pajak unik per supplier
            if ($fp_no !== '') {
                $stFp = $db->prepare("SELECT 1 FROM purchase_invoice_hdr WHERE supplier_id = :s AND faktur_pajak_no = :f AND id <> :id LIMIT 1");
                $stFp->execute([':s' => $supplier_id, ':f' => $fp_no, ':id' => $invoice_id]);
                if ($stFp->fetchColumn()) throw new Exception('No. faktur pajak ' . $fp_no . ' sudah dipakai invoice lain dari supplier ini.');
            }
            // [PPN] total baru tidak boleh lebih kecil dari yang sudah dibayar
            $stPaid = $db->prepare("SELECT paid_amount FROM purchase_invoice_hdr WHERE id = :id");
            $stPaid->execute([':id' => $invoice_id]);
            $paidNow = floatval($stPaid->fetchColumn());
            if ($paidNow > $total_with_ppn + 0.005) {
                throw new Exception('Total invoice (' . number_format($total_with_ppn, 2) . ') lebih kecil dari yang sudah dibayar (' . number_format($paidNow, 2) . ').');
            }

            $stTot = $db->prepare("UPDATE purchase_invoice_hdr
                                      SET total_amount = :t, dpp_amount = :dpp, ppn_rate = :pr, ppn_amount = :pa,
                                          faktur_pajak_no = :fn, faktur_pajak_date = CAST(:fd AS date)
                                    WHERE id = :id");
            $stTot->execute([':t' => $total_with_ppn, ':dpp' => $dpp, ':pr' => $ppn_rate, ':pa' => $ppn_amount_final,
                             ':fn' => $fp_no !== '' ? $fp_no : null, ':fd' => $fp_date !== '' ? $fp_date : null, ':id' => $invoice_id]);

            // [JURNAL] edit: buang jurnal lama lalu posting ulang sesuai nilai terbaru
            if (PiHasJournal($db, $invoice_id)) ReverseJournalByReference($db, PI_JOURNAL_REF, $invoice_id);
            PiPostJournal($db, $invoice_id, $inv_no, $supplier_invoice_no, $invoice_date, $dpp, $ppn_amount_final);

            $db->commit();

            echo json_encode(['success' => true, 'message' => 'Data purchase invoice berhasil disimpan.']);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id <= 0) throw new Exception('ID invoice tidak valid.');
            $db = new crud();
            $db->beginTransaction();

            $stLk = $db->prepare("SELECT inv_no, paid_amount FROM purchase_invoice_hdr WHERE id = :id FOR UPDATE");
            $stLk->execute([':id' => $id]);
            $rowLk = $stLk->fetch(PDO::FETCH_ASSOC);
            if (!$rowLk) throw new Exception('Invoice tidak ditemukan.');
            $stPay = $db->prepare("SELECT 1 FROM supplier_payment_items WHERE invoice_id = :id LIMIT 1");
            $stPay->execute([':id' => $id]);
            if (floatval($rowLk['paid_amount']) > 0.005 || $stPay->fetchColumn()) {
                throw new Exception('Invoice ' . $rowLk['inv_no'] . ' sudah ada pembayaran supplier, tidak dapat dihapus. Hapus pembayarannya terlebih dahulu.');
            }

            if (PiHasJournal($db, $id)) ReverseJournalByReference($db, PI_JOURNAL_REF, $id);   // [JURNAL] jurnal ikut dibatalkan
            $db->prepare("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = :id")->execute([':id' => $id]);
            $db->prepare("DELETE FROM purchase_invoice_hdr WHERE id = :id")->execute([':id' => $id]);
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus.']);
            break;

        // [JURNAL] Backfill sekali pakai: posting jurnal untuk invoice lama yang belum dijurnal.
        //   task=backfill_journal            -> preview (hanya baca)
        //   task=backfill_journal&confirm=YA -> jalankan (POST)
        case 'backfill_journal':
            $db = new crud();
            $stList = $db->query("SELECT h.id, h.inv_no, h.supplier_invoice_no, h.invoice_date, h.dpp_amount, h.ppn_amount, h.total_amount
                                    FROM purchase_invoice_hdr h
                                   WHERE LOWER(h.status) NOT IN ('cancelled','canceled','dibatalkan','void','reversed')
                                     AND NOT EXISTS (SELECT 1 FROM journal_entries j WHERE j.reference_type::text = '" . PI_JOURNAL_REF . "' AND j.reference_id = h.id)
                                   ORDER BY h.id");
            $todo = $stList->fetchAll(PDO::FETCH_ASSOC);
            $run = (isset($_POST['confirm']) && $_POST['confirm'] === 'YA' && $_SERVER['REQUEST_METHOD'] === 'POST');
            if ($run && count($todo)) {
                $db->beginTransaction();
                PiAssertJournalRefType($db);
                foreach ($todo as $r) {
                    PiPostJournal($db, (int)$r['id'], $r['inv_no'], (string)$r['supplier_invoice_no'], $r['invoice_date'],
                                  round(floatval($r['dpp_amount']), 2), round(floatval($r['ppn_amount']), 2));
                }
                $db->commit();
            }
            echo json_encode(['success' => true, 'totalCount' => count($todo), 'data' => $todo,
                              'message' => $run ? count($todo) . ' invoice berhasil dijurnal.'
                                                : count($todo) . ' invoice belum dijurnal (preview, belum ada yang diubah). Kirim POST task=backfill_journal&confirm=YA untuk menjalankan.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Exception $e) {
    if (isset($db) && is_object($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {      // [JURNAL] transaksi PDO
        $db->rollBack();
    }
    if (function_exists('pg_query')) {
        @pg_query("ROLLBACK");
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}