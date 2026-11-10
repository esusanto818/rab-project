<?php
/**
 * year.closingCRD.php
 * ============================================================
 * Backend Tutup Buku Tahunan (jurnal penutup) -- year.closing.js.
 * Pola sama dengan poCRD.php / project.wipreleaseCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Konsep:
 *   Akhir tahun, saldo akun Pendapatan & Beban tahun itu "dinolkan" dan selisihnya (laba/rugi) dipindah ke akun ekuitas
 *   Laba Ditahan (default 3200). Dibuat SATU jurnal (reference_type 'closing_entry', reference_id = tahun buku),
 *   tanggal 31 Desember, status posted:
 *     - tiap akun pendapatan/beban bersaldo : Dr (pendapatan, saldo kredit) / Cr (beban, saldo debit) sebesar saldonya
 *     - akun Laba Ditahan                    : Cr bila laba, Dr bila rugi
 *   Saldo tahun dihitung dari jurnal posted/reversed bertanggal 1 Jan - 31 Des tahun itu, KECUALI jurnal 'closing_entry'.
 *   [PILIH AKUN] Klien mengirim account_ids (JSON) = akun pendapatan/beban yang ikut ditutup (checklist; default semua).
 *   Hanya akun terpilih yang dinolkan; laba/rugi yang dipindah ke Laba Ditahan = selisih akun terpilih saja.
 *   Akun yang tidak dipilih tetap bersaldo (tidak ikut ditutup). Tanpa account_ids = semua akun.
 *
 * Aturan:
 *   1. Satu tahun hanya bisa ditutup sekali (tabel year_closing, fiscal_year unik).
 *   2. Tahun harus ditutup berurutan: semua tahun sebelumnya yang punya jurnal harus sudah ditutup.
 *   3. Periode akuntansi yang memuat 31 Desember harus ada & masih terbuka saat jurnal dibuat.
 *   4. Tahun yang belum berakhir hanya boleh ditutup dengan konfirmasi (force=1), untuk uji coba.
 *   5. Opsi lock_periods: setelah jurnal dibuat, semua periode tahun itu dikunci (is_closed = true);
 *      id yang dikunci disimpan agar bisa dibuka kembali.
 *   6. Buka kembali (hapus penutupan): hanya tahun yang PALING AKHIR ditutup; periode yang tadi dikunci dibuka lagi
 *      dan jurnal penutupnya dihapus (ReverseJournalByReference).
 *   Laporan Laba Rugi (layar & cetak) mengabaikan jurnal 'closing_entry'; Neraca & Trial Balance memasukkannya.
 * Akses: tutup / buka kembali >= 3 ($_SESSION['FormAccess']).
 * Butuh migrasi: sql/2026-10-18.year_closing_and_recon.sql
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

const YC_EPS = 0.005;
const YC_REF = 'closing_entry';     // nilai enum journal_entries.reference_type
const YC_ACCESS = 3;

class YcBusinessError extends Exception {}

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function yc_access(): int { return intval($_SESSION['FormAccess'] ?? 0); }
function yc_bool($v): bool { return ($v === true || $v === 't' || $v === '1' || $v === 1); }

/** Tahun yang punya jurnal (selain closing_entry), urut naik. */
function yc_journal_years($db): array {
    $st = $db->query("SELECT DISTINCT EXTRACT(YEAR FROM journal_date)::int AS y
                        FROM public.journal_entries
                       WHERE status::text IN ('posted', 'reversed') AND reference_type::text <> 'closing_entry'
                       ORDER BY y");
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Peta tahun -> nomor jurnal penutup untuk tahun yang sudah ditutup. */
function yc_closed_map($db): array {
    $map = [];
    foreach ($db->query("SELECT fiscal_year, journal_no FROM public.year_closing")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['fiscal_year']] = $r['journal_no'];
    }
    return $map;
}

/** Cek syarat penutupan. Mengembalikan ['blockers'=>[], 'warnings'=>[], 'closing_date'=>, 'period'=>, 'not_ended'=>bool, 'periods'=>[]]. */
function yc_state($db, int $year): array {
    $blockers = []; $warnings = [];
    $closingDate = sprintf('%04d-12-31', $year);
    $yearStart = sprintf('%04d-01-01', $year);
    $closed = yc_closed_map($db);

    if (isset($closed[$year])) {
        $blockers[] = "Tahun {$year} sudah ditutup (jurnal " . ($closed[$year] ?: '-') . ').';
    }
    foreach (yc_journal_years($db) as $y) {
        if ($y < $year && !isset($closed[$y])) $blockers[] = "Tutup buku tahun {$y} terlebih dahulu (penutupan harus berurutan).";
    }

    $st = $db->prepare("SELECT id, period_name, is_closed FROM public.accounting_periods
                         WHERE CAST(:d AS date) BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1");
    $st->execute([':d' => $closingDate]);
    $period = $st->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        $blockers[] = "Tidak ada periode akuntansi yang memuat {$closingDate}. Buat periodenya dulu di menu Fiscal Period.";
    } elseif (yc_bool($period['is_closed'])) {
        $blockers[] = "Periode {$period['period_name']} (memuat {$closingDate}) sudah ditutup. Buka kembali periodenya dulu agar jurnal penutup bisa dibuat.";
    }

    $notEnded = ($closingDate > date('Y-m-d'));
    if ($notEnded) $warnings[] = "Tahun {$year} belum berakhir (hari ini " . date('Y-m-d') . '). Penutupan sebelum akhir tahun hanya untuk uji coba.';

    $st = $db->prepare("SELECT id, period_name, is_closed FROM public.accounting_periods
                         WHERE start_date >= CAST(:s AS date) AND end_date <= CAST(:e AS date) ORDER BY start_date");
    $st->execute([':s' => $yearStart, ':e' => $closingDate]);
    $periods = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $periods[] = ['id' => (int)$p['id'], 'period_name' => $p['period_name'], 'is_closed' => yc_bool($p['is_closed'])];
    }

    return ['blockers' => $blockers, 'warnings' => $warnings, 'closing_date' => $closingDate,
            'period' => $period ?: null, 'not_ended' => $notEnded, 'periods' => $periods];
}

/** Hitung baris jurnal penutup untuk satu tahun. */
function yc_calc($db, int $year): array {
    $st = $db->prepare("
        SELECT c.id, c.account_code, c.account_name, c.account_type::text AS account_type,
               COALESCE(SUM(l.credit - l.debit), 0) AS net_credit
          FROM public.chart_of_accounts c
          JOIN public.journal_lines l ON l.account_id = c.id
          JOIN public.journal_entries j ON j.id = l.journal_id
               AND j.status::text IN ('posted', 'reversed')
               AND j.reference_type::text <> 'closing_entry'
               AND j.journal_date BETWEEN CAST(:s AS date) AND CAST(:e AS date)
         WHERE c.account_type::text IN ('revenue', 'expense')
         GROUP BY c.id, c.account_code, c.account_name, c.account_type
         ORDER BY c.account_code ASC");
    $st->execute([':s' => sprintf('%04d-01-01', $year), ':e' => sprintf('%04d-12-31', $year)]);

    $revenue = []; $expense = []; $lines = [];
    $revTotal = 0.0; $expTotal = 0.0; $net = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nc = round((float)$r['net_credit'], 2);
        if (abs($nc) < YC_EPS) continue;
        $isRev = ($r['account_type'] === 'revenue');
        $balance = $isRev ? $nc : -$nc;                     // saldo menurut sifat akun (pendapatan = kredit, beban = debit)
        $debit  = $nc > 0 ? $nc : 0.0;                      // menolkan akun: saldo kredit -> didebit, saldo debit -> dikredit
        $credit = $nc < 0 ? -$nc : 0.0;
        $item = ['account_id' => (int)$r['id'], 'account_code' => $r['account_code'], 'account_name' => $r['account_name'],
                 'type' => $isRev ? 'revenue' : 'expense', 'balance' => $balance, 'debit' => $debit, 'credit' => $credit];
        if ($isRev) { $revenue[] = $item; $revTotal += $balance; } else { $expense[] = $item; $expTotal += $balance; }
        $lines[] = $item;
        $net += $nc;                                         // laba = jumlah (kredit - debit) seluruh akun pendapatan & beban
    }
    return ['revenue' => $revenue, 'expense' => $expense, 'lines' => $lines,
            'revenue_total' => round($revTotal, 2), 'expense_total' => round($expTotal, 2), 'net_income' => round($net, 2)];
}

/**
 * [PILIH AKUN] Saring hasil yc_calc ke akun terpilih saja dan hitung ulang total.
 * Id yang tidak ada di hasil hitung (bukan akun pendapatan/beban bersaldo tahun itu) ditolak.
 */
function yc_filter(array $calc, array $ids): array {
    $known = [];
    foreach ($calc['lines'] as $ln) { $known[$ln['account_id']] = true; }
    $set = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (!isset($known[$id])) throw new YcBusinessError('Akun terpilih tidak valid / tidak bersaldo pada tahun ini (ID ' . $id . ').');
        $set[$id] = true;
    }
    $revenue = []; $expense = []; $lines = []; $rt = 0.0; $et = 0.0; $net = 0.0;
    foreach ($calc['lines'] as $ln) {
        if (!isset($set[$ln['account_id']])) continue;
        $lines[] = $ln;
        if ($ln['type'] === 'revenue') { $revenue[] = $ln; $rt += $ln['balance']; } else { $expense[] = $ln; $et += $ln['balance']; }
        $net += $ln['debit'] - $ln['credit'];
    }
    return ['revenue' => $revenue, 'expense' => $expense, 'lines' => $lines,
            'revenue_total' => round($rt, 2), 'expense_total' => round($et, 2), 'net_income' => round($net, 2),
            'selected_count' => count($lines), 'total_count' => count($calc['lines'])];
}

function yc_retained($db, int $id): array {
    $st = $db->prepare("SELECT id, account_code, account_name, account_type::text AS account_type, is_active
                          FROM public.chart_of_accounts WHERE id = :id");
    $st->execute([':id' => $id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new YcBusinessError('Akun Laba Ditahan tidak ditemukan.');
    if ($a['account_type'] !== 'equity') throw new YcBusinessError('Akun penampung laba harus bertipe ekuitas (equity).');
    if (!yc_bool($a['is_active'])) throw new YcBusinessError('Akun penampung laba tidak aktif.');
    return $a;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) sendJsonResponse(false, [], 'Hak baca tutup buku tahunan tidak tersedia.');

try {
    switch ($task) {

        /* Combo tahun: tahun yang punya jurnal + status penutupan */
        case 'list_years':
            $closed = yc_closed_map($db);
            $years = yc_journal_years($db);
            foreach (array_keys($closed) as $y) { if (!in_array($y, $years, true)) $years[] = $y; }
            sort($years);
            $rows = [];
            foreach ($years as $y) {
                $rows[] = ['year' => $y, 'closed' => isset($closed[$y]),
                           'label' => $y . (isset($closed[$y]) ? ' (sudah ditutup)' : '')];
            }
            sendJsonResponse(true, array_reverse($rows));
            break;

        /* Combo akun ekuitas penampung laba; 3200 = default */
        case 'list_accounts':
            $rows = $db->query("SELECT id, account_code, account_name FROM public.chart_of_accounts
                                 WHERE account_type::text = 'equity' AND is_active = true ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['id' => (int)$r['id'], 'label' => $r['account_code'] . ' - ' . $r['account_name'],
                          'is_default' => ($r['account_code'] === '3200')];
            }
            sendJsonResponse(true, $out);
            break;

        /* Pratinjau jurnal penutup */
        case 'preview':
            $year = (int)($_REQUEST['year'] ?? 0);
            $accId = (int)($_REQUEST['retained_account_id'] ?? 0);
            try {
                if ($year < 2000 || $year > 2100) throw new YcBusinessError('Tahun buku tidak valid.');
                $state = yc_state($db, $year);
                $calc = yc_calc($db, $year);
                $ra = null;
                if ($accId > 0) { $ra = yc_retained($db, $accId); }
                else { $state['blockers'][] = 'Pilih akun Laba Ditahan.'; }
                if (!count($calc['lines'])) $state['blockers'][] = "Tidak ada saldo pendapatan / beban pada tahun {$year}.";
                sendJsonResponse(true, [
                    'year' => $year, 'closing_date' => $state['closing_date'],
                    'revenue' => $calc['revenue'], 'expense' => $calc['expense'],
                    'revenue_total' => $calc['revenue_total'], 'expense_total' => $calc['expense_total'], 'net_income' => $calc['net_income'],
                    'retained_account' => $ra ? ($ra['account_code'] . ' - ' . $ra['account_name']) : null,
                    'blockers' => $state['blockers'], 'warnings' => $state['warnings'], 'not_ended' => $state['not_ended'],
                    'periods' => $state['periods'],
                ]);
            } catch (YcBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* Daftar penutupan (grid utama) */
        case 'list_closings':
            $st = $db->query("
                SELECT yc.id, yc.fiscal_year, yc.closing_date, yc.journal_no, yc.revenue_total, yc.expense_total, yc.net_income,
                       yc.locked_periods, yc.remark, a.account_code || ' - ' || a.account_name AS retained_account,
                       NOT EXISTS (SELECT 1 FROM public.year_closing x WHERE x.fiscal_year > yc.fiscal_year) AS is_latest
                  FROM public.year_closing yc
                  LEFT JOIN public.chart_of_accounts a ON a.id = yc.retained_account_id
                 ORDER BY yc.fiscal_year DESC");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['fiscal_year'] = (int)$r['fiscal_year'];
                $r['revenue_total'] = (float)$r['revenue_total'];
                $r['expense_total'] = (float)$r['expense_total'];
                $r['net_income'] = (float)$r['net_income'];
                $r['locked_count'] = strlen(trim((string)$r['locked_periods'])) ? count(explode(',', $r['locked_periods'])) : 0;
                $r['is_latest'] = yc_bool($r['is_latest']);
            }
            unset($r);
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)]);
            break;

        /* Proses tutup buku: satu transaksi (jurnal + catatan + kunci periode) */
        case 'save_closing':
            if (yc_access() < YC_ACCESS) sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk tutup buku.');
            $year = (int)($_POST['year'] ?? 0);
            $accId = (int)($_POST['retained_account_id'] ?? 0);
            $lock = ((int)($_POST['lock_periods'] ?? 0) === 1);
            $force = ((int)($_POST['force'] ?? 0) === 1);
            $remark = trim((string)($_POST['remark'] ?? '')) ?: null;
            $selIds = null;      // [PILIH AKUN] null = semua akun; array = hanya akun terpilih
            if (isset($_POST['account_ids'])) {
                $selIds = json_decode((string)$_POST['account_ids'], true);
                if (!is_array($selIds)) $selIds = [];
            }

            try {
                if ($year < 2000 || $year > 2100) throw new YcBusinessError('Tahun buku tidak valid.');
                if ($selIds !== null && !count($selIds)) throw new YcBusinessError('Pilih minimal satu akun yang akan ditutup.');
                $db->beginTransaction();
                try {
                    $db->query("SELECT pg_advisory_xact_lock(7002)");     // cegah dua proses tutup buku bersamaan

                    $state = yc_state($db, $year);
                    if (count($state['blockers'])) throw new YcBusinessError(implode(' ', $state['blockers']));
                    if ($state['not_ended'] && !$force) throw new YcBusinessError("Tahun {$year} belum berakhir. Konfirmasi diperlukan untuk menutup lebih awal.");
                    $ra = yc_retained($db, $accId);
                    $calc = yc_calc($db, $year);
                    if (!count($calc['lines'])) throw new YcBusinessError("Tidak ada saldo pendapatan / beban pada tahun {$year}.");
                    $calc = yc_filter($calc, $selIds === null ? array_column($calc['lines'], 'account_id') : $selIds);    // [PILIH AKUN]
                    if (!count($calc['lines'])) throw new YcBusinessError('Pilih minimal satu akun yang akan ditutup.');

                    $net = $calc['net_income'];
                    $desc = "Jurnal penutup tahun {$year} - " . ($net >= 0 ? 'laba' : 'rugi') . ' bersih ' . number_format(abs($net), 2, '.', ',') .
                            ($calc['selected_count'] < $calc['total_count'] ? " [{$calc['selected_count']} dari {$calc['total_count']} akun]" : '');

                    $refNo = function_exists('JournalNextRefNo') ? JournalNextRefNo($db) : CreateRefNo('journal_entries', 'ref_no', 'JV');
                    $st = $db->prepare("
                        INSERT INTO public.journal_entries
                            (ref_no, journal_date, period_id, reference_type, reference_id, description, status, posted_by, posted_at)
                        VALUES (:no, CAST(:d AS date), :p, 'closing_entry', :rid, :desc, 'posted', :u, now())
                        RETURNING id");
                    $st->execute([':no' => $refNo, ':d' => $state['closing_date'], ':p' => (int)$state['period']['id'],
                                  ':rid' => $year, ':desc' => $desc, ':u' => (int)$_SESSION['user_id'] ?: null]);
                    $jid = (int)$st->fetchColumn();

                    $stL = $db->prepare("INSERT INTO public.journal_lines (journal_id, account_id, debit, credit, description)
                                         VALUES (:j, :a, :d, :c, :t)");
                    $sumD = 0.0; $sumC = 0.0;
                    foreach ($calc['lines'] as $ln) {
                        $stL->execute([':j' => $jid, ':a' => $ln['account_id'], ':d' => $ln['debit'], ':c' => $ln['credit'],
                                       ':t' => "Penutupan {$ln['account_code']} tahun {$year}"]);
                        $sumD += $ln['debit']; $sumC += $ln['credit'];
                    }
                    // baris Laba Ditahan: kredit bila laba, debit bila rugi
                    $reD = $net < 0 ? -$net : 0.0; $reC = $net > 0 ? $net : 0.0;
                    $stL->execute([':j' => $jid, ':a' => (int)$ra['id'], ':d' => $reD, ':c' => $reC,
                                   ':t' => "Laba (rugi) tahun {$year} ke {$ra['account_code']}"]);
                    $sumD += $reD; $sumC += $reC;
                    if (abs($sumD - $sumC) > YC_EPS) throw new Exception('Jurnal penutup tidak seimbang (' . $sumD . ' vs ' . $sumC . ').');

                    // kunci periode tahun ini (bila dipilih) -- simpan id agar bisa dibuka kembali
                    $locked = [];
                    if ($lock) {
                        foreach ($state['periods'] as $p) {
                            if (!$p['is_closed']) { $locked[] = (int)$p['id']; }
                        }
                        // periode Desember (dipakai jurnal ini) masih terbuka saat dicek -> ikut dikunci
                        $stU = $db->prepare("UPDATE public.accounting_periods SET is_closed = true WHERE id = :id");
                        foreach ($locked as $pid) { $stU->execute([':id' => $pid]); }
                    }

                    $st = $db->prepare("
                        INSERT INTO public.year_closing
                            (fiscal_year, closing_date, journal_id, journal_no, revenue_total, expense_total, net_income,
                             retained_account_id, locked_periods, remark, created_by)
                        VALUES (:y, CAST(:d AS date), :j, :no, :rt, :et, :ni, :ra, :lp, :r, :u)");
                    $st->execute([':y' => $year, ':d' => $state['closing_date'], ':j' => $jid, ':no' => $refNo,
                                  ':rt' => $calc['revenue_total'], ':et' => $calc['expense_total'], ':ni' => $net,
                                  ':ra' => (int)$ra['id'], ':lp' => implode(',', $locked), ':r' => $remark,
                                  ':u' => (int)$_SESSION['user_id'] ?: null]);

                    $db->commit();
                    sendJsonResponse(true, ['year' => $year, 'journal_no' => $refNo, 'net_income' => $net],
                        "Tutup buku tahun {$year} berhasil. Jurnal {$refNo}, " . ($net >= 0 ? 'laba' : 'rugi') . ' bersih ' .
                        number_format(abs($net), 2) . ($lock ? ', ' . count($locked) . ' periode dikunci.' : '.'));
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (YcBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* Buka kembali: hapus penutupan TERAKHIR, buka kunci periode, hapus jurnal penutup */
        case 'delete_closing':
            if (yc_access() < YC_ACCESS) sendJsonResponse(false, [], 'Anda tidak memiliki hak akses untuk membuka kembali tutup buku.');
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $db->query("SELECT pg_advisory_xact_lock(7002)");
                $st = $db->prepare("SELECT fiscal_year, journal_no, locked_periods FROM public.year_closing WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new YcBusinessError('Data penutupan tidak ditemukan.');
                $year = (int)$r['fiscal_year'];

                $st = $db->prepare("SELECT COUNT(*) FROM public.year_closing WHERE fiscal_year > :y");
                $st->execute([':y' => $year]);
                if ((int)$st->fetchColumn() > 0) {
                    throw new YcBusinessError("Hanya penutupan tahun terakhir yang dapat dibuka kembali. Buka kembali tahun yang lebih baru terlebih dahulu.");
                }

                // buka kunci periode yang dikunci oleh penutupan ini (supaya jurnal penutup di periode Desember bisa dihapus)
                $ids = array_filter(array_map('intval', explode(',', (string)$r['locked_periods'])));
                $stU = $db->prepare("UPDATE public.accounting_periods SET is_closed = false WHERE id = :id");
                foreach ($ids as $pid) { $stU->execute([':id' => $pid]); }

                ReverseJournalByReference($db, YC_REF, $year);
                $db->prepare("DELETE FROM public.year_closing WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Tutup buku tahun {$year} dibuka kembali; jurnal {$r['journal_no']} dihapus dan " . count($ids) . ' periode dibuka.');
            } catch (YcBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[year.closingCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[year.closingCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}