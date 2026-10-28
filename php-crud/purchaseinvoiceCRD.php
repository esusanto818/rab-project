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
         - TIDAK ADA JURNAL di invoice supplier (prinsip: invoice = pemberitahuan tagihan, belum ada transaksi barang/uang).
           DPP / PPN / faktur pajak hanya disimpan sebagai data: proyeksi pengeluaran, analisis cash flow, dan Laporan PPN.
         Butuh migrasi: sql/2026-10-26.ppn_invoice.sql
   [BARU 2026-10-08]
         - task 'read_invoice_print': data Tanda Terima Invoice (read-only, tidak mengubah data).
         - create: kolom created_by ikut diisi dari sesi (kolom sudah ada di tabel, sebelumnya kosong)
           supaya nama penerima tampil di cetakan. */

session_start();

if (file_exists('../php-func/function.all.php')) {
    require_once('../php-func/function.all.php');
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
            pg_query("BEGIN");

            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            // [FIX] inv_no sekarang No. Ref Dokumen yang di-generate sistem,
            // bukan input user -> field ini sudah disabled di JS, jadi
            // $_POST['inv_no'] diabaikan sepenuhnya di sini.
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
            $ppn_amount_final = 0.0;     // diisi setelah DPP (jumlah matched) diketahui

            $invoice_details = isset($_POST['invoice_details']) ? json_decode($_POST['invoice_details'], true) : [];

            if ($task == 'create') {
                // [FIX] Sebelumnya manggil CreateRefNo('PINV', 'purchase_invoice_hdr', 'inv_no')
                // dengan asumsi urutan param (prefix, table, column) -> ternyata SALAH.
                // Dari log server: fungsi ini pakai param pertama sebagai NAMA TABEL
                // ("FROM PINV") dan param kedua sebagai nama kolom di substring() ->
                // query di dalam CreateRefNo() error "relation pinv does not exist",
                // $inv_no jadi kosong/null, lalu INSERT gagal (kolom NOT NULL).
                // Sampai urutan param yang benar dikonfirmasi, generate ref no sendiri
                // di sini saja (self-contained, tidak bergantung fungsi lain).
                $prefix = 'PINV-' . date('ym') . '-';
                $sqlSeq = "SELECT COUNT(*) + 1 AS next_seq FROM purchase_invoice_hdr WHERE inv_no LIKE $1";
                $resSeq = pg_query_params($sqlSeq, [$prefix . '%']);
                if (!$resSeq) throw new Exception(pgErrorOrFallback('Gagal generate No. Ref Dokumen.'));
                $rowSeq = pg_fetch_assoc($resSeq);
                $inv_no = $prefix . str_pad($rowSeq['next_seq'], 5, '0', STR_PAD_LEFT);

                // [CHG] + created_by (kolom sudah ada di tabel, sebelumnya tidak diisi)
                $created_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
                $sqlHdr = "INSERT INTO purchase_invoice_hdr (inv_no, supplier_invoice_no, invoice_date, supplier_id, remark, status, created_by)
                           VALUES ($1, $2, $3, $4, $5, 'posted', $6) RETURNING id";
                $resHdr = pg_query_params($sqlHdr, [$inv_no, $supplier_invoice_no, $invoice_date, $supplier_id, $remark, $created_by]);
                if (!$resHdr) throw new Exception(pgErrorOrFallback('Gagal membuat invoice baru.'));
                $rowHdr = pg_fetch_assoc($resHdr);
                $invoice_id = $rowHdr['id'];
            } else {
                $invoice_id = $id;
                // [FIX] inv_no sengaja tidak disertakan di SET -> No. Ref Dokumen immutable setelah dibuat.
                $sqlHdr = "UPDATE purchase_invoice_hdr SET supplier_invoice_no = $1, invoice_date = $2, supplier_id = $3, remark = $4
                           WHERE id = $5";
                $resHdr = pg_query_params($sqlHdr, [$supplier_invoice_no, $invoice_date, $supplier_id, $remark, $invoice_id]);
                if (!$resHdr) throw new Exception(pgErrorOrFallback('Gagal mengupdate invoice.'));

                // [FIX] invoice_hdr_id, bukan invoice_id
                pg_query_params("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = $1", [$invoice_id]);
            }

            $total_amount_header = 0;
            foreach ($invoice_details as $item) {
                // [FIX] field dari frontend juga perlu grn_hdr_id, lihat catatan di purchaseinvoice.js
                $grn_hdr_id = intval(isset($item['grn_hdr_id']) ? $item['grn_hdr_id'] : (isset($item['grn_dtl_id']) ? $item['grn_dtl_id'] : 0));
                $matched_amount = floatval($item['matched_amount']);
                $total_amount_header += $matched_amount;

                // [FIX] invoice_hdr_id, grn_hdr_id, bukan invoice_id, grn_dtl_id
                $sqlDtl = "INSERT INTO purchase_invoice_dtl (invoice_hdr_id, grn_hdr_id, matched_amount) VALUES ($1, $2, $3)";
                $resDtl = pg_query_params($sqlDtl, [$invoice_id, $grn_hdr_id, $matched_amount]);
                if (!$resDtl) throw new Exception(pgErrorOrFallback('Gagal menyimpan detail invoice.'));
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
                $resFp = pg_query_params("SELECT 1 FROM purchase_invoice_hdr WHERE supplier_id = $1 AND faktur_pajak_no = $2 AND id <> $3 LIMIT 1",
                                         [$supplier_id, $fp_no, $invoice_id]);
                if (!$resFp) throw new Exception(pgErrorOrFallback('Gagal memeriksa no. faktur pajak.'));
                if (pg_fetch_row($resFp)) throw new Exception('No. faktur pajak ' . $fp_no . ' sudah dipakai invoice lain dari supplier ini.');
            }
            // [PPN] total baru tidak boleh lebih kecil dari yang sudah dibayar
            $resPaid = pg_query_params("SELECT paid_amount FROM purchase_invoice_hdr WHERE id = $1", [$invoice_id]);
            $rowPaid = $resPaid ? pg_fetch_assoc($resPaid) : null;
            if ($rowPaid && floatval($rowPaid['paid_amount']) > $total_with_ppn + 0.005) {
                throw new Exception('Total invoice (' . number_format($total_with_ppn, 2) . ') lebih kecil dari yang sudah dibayar (' . number_format(floatval($rowPaid['paid_amount']), 2) . ').');
            }

            $resTot = pg_query_params("UPDATE purchase_invoice_hdr
                                          SET total_amount = $1, dpp_amount = $2, ppn_rate = $3, ppn_amount = $4,
                                              faktur_pajak_no = $5, faktur_pajak_date = $6
                                        WHERE id = $7",
                                     [$total_with_ppn, $dpp, $ppn_rate, $ppn_amount_final,
                                      $fp_no !== '' ? $fp_no : null, $fp_date !== '' ? $fp_date : null, $invoice_id]);
            if (!$resTot) throw new Exception(pgErrorOrFallback('Gagal menyimpan total invoice.'));

            pg_query("COMMIT");

            echo json_encode(['success' => true, 'message' => 'Data purchase invoice berhasil disimpan.']);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            pg_query("BEGIN");
            // [FIX] invoice_hdr_id, bukan invoice_id
            pg_query_params("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = $1", [$id]);
            pg_query_params("DELETE FROM purchase_invoice_hdr WHERE id = $1", [$id]);
            pg_query("COMMIT");
            echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Exception $e) {
    if (function_exists('pg_query')) {
        @pg_query("ROLLBACK");
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}