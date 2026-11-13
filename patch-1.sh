#!/usr/bin/env bash
# patch-1.sh : bersihkan function.all.php + patch invoice/whrcv/grn
# Jalankan: cd ~/proyekgmn-dev && bash patch-1.sh
set -euo pipefail
cd "$(dirname "$0")"
[ -d php-func ] && [ -d php-crud ] || { echo "ERROR: jalankan di ~/proyekgmn-dev"; exit 1; }
command -v php >/dev/null || { echo "ERROR: php CLI tidak ada"; exit 1; }
command -v python3 >/dev/null || { echo "ERROR: python3 tidak ada"; exit 1; }

S=$(date +%Y%m%d-%H%M%S)
FILES="php-func/function.all.php php-crud/purchaseinvoiceCRD.php php-crud/whrcvCRD.php php-crud/grnCRD.php"
echo "== Backup =="
for f in $FILES; do [ -f "$f" ] && cp -p "$f" "$f.bak-$S" && echo "  $f.bak-$S"; done

python3 - <<'PY'
import re, pathlib
log = []
def rd(p): return pathlib.Path(p).read_text()
def wr(p,s): pathlib.Path(p).write_text(s)

def rf(s, name, new):
    m = re.search(r'function\s+'+name+r'\s*\(', s)
    if not m: return s, False
    i = s.index('{', m.end()); d = 0; j = i
    while j < len(s):
        if s[j] == '{': d += 1
        elif s[j] == '}':
            d -= 1
            if d == 0: break
        j += 1
    return s[:m.start()] + new + s[j+1:], True

SETPG = r'''function SetPgAppUser($connOverride = null) {
    global $conn;
    $c = $connOverride ?: $conn;
    $u = $_SESSION['username'] ?? $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'system';
    $res = pg_query_params($c, "SELECT set_config('app.username', $1, true)", [$u]);
    if ($res === false) throw new Exception('[SetPgAppUser] ' . pg_last_error($c));
}'''

CHECK = r'''function CheckCRUD($task, $userId, $formId) {
    global $conn;
    $st = pg_query_params($conn, "SELECT access_level FROM m_user_access_menu WHERE user_id = $1 AND form_id = $2", [$userId, $formId]);
    if ($st === false) throw new Exception('[CheckCRUD] ' . pg_last_error($conn));
    $lv = pg_num_rows($st) ? (int)pg_fetch_result($st, 0, 0) : 0;
    $need = ['read'=>1,'create'=>2,'update'=>3,'delete'=>4][$task] ?? 1;
    return $lv >= $need ? $task : 'xxx';
}'''

S2F = r'''function String_to_float($string_obj) {
    $s = trim((string)$string_obj);
    $neg = (strpos($s, '-') === 0);
    $s = str_replace(['-', ','], '', $s);
    $v = (float)$s;
    return $neg ? -$v : $v;
}'''

RAMT = r'''function rAmountReport($number, $round) {
    if ($number > 0) return number_format(floor($number / $round) * $round, 0, '.', ',');
    if ($number < 0) return number_format(floor(($number * -1) / $round) * $round * -1, 0, '.', ',');
    return '-';
}'''

RECALC = r'''function RecalcGrnStatus($grn_hdr_id, $db = null) {
    $grn_hdr_id = (int)$grn_hdr_id;
    $sql = "SELECT
COUNT(*) AS total_items,
SUM(CASE WHEN (gd.qty_received - COALESCE(wd.qty_wh_sum,0)) <= 0 THEN 1 ELSE 0 END) AS fully_received,
SUM(CASE WHEN COALESCE(wd.qty_wh_sum,0) > 0
AND (gd.qty_received - COALESCE(wd.qty_wh_sum,0)) > 0 THEN 1 ELSE 0 END) AS partially_received
FROM grn_dtl gd
LEFT JOIN (
SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
FROM wh_receiving_dtl d
JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
GROUP BY d.grn_dtl_id
) wd ON wd.grn_dtl_id = gd.id
WHERE gd.grn_hdr_id = " . ($db !== null ? ':id' : '$1');
    if ($db !== null) {
        $st = $db->prepare($sql); $st->execute([':id' => $grn_hdr_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        global $conn;
        $res = pg_query_params($conn, $sql, [$grn_hdr_id]);
        if ($res === false) throw new Exception('[RecalcGrnStatus] ' . pg_last_error($conn));
        $row = pg_fetch_assoc($res);
    }
    if ((int)$row['total_items'] === 0) $status = 'open';
    elseif ((int)$row['fully_received'] === (int)$row['total_items']) $status = 'closed';
    elseif ((int)$row['fully_received'] > 0 || (int)$row['partially_received'] > 0) $status = 'partial';
    else $status = 'open';
    if ($db !== null) {
        $st = $db->prepare("UPDATE grn_hdr SET status = :s WHERE id = :id");
        $st->execute([':s' => $status, ':id' => $grn_hdr_id]);
    } else {
        global $conn;
        $res = pg_query_params($conn, "UPDATE grn_hdr SET status = $1 WHERE id = $2", [$status, $grn_hdr_id]);
        if ($res === false) throw new Exception('[RecalcGrnStatus update] ' . pg_last_error($conn));
    }
    return $status;
}'''

REFDB = r'''function CreateRefNoDb($db, $table, $column, $prefix) {
    $allowed = ['wh_receiving_hdr'=>'ref_no','supplier_payments'=>'payment_no',
                'purchase_orders'=>'po_number','grn_hdr'=>'ref_no','purchase_invoice_hdr'=>'inv_no'];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) throw new Exception('CreateRefNoDb: tabel/kolom tidak diizinkan.');
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$prefix));
    if ($prefix === '') throw new Exception('CreateRefNoDb: prefix tidak valid.');
    $db->query("SELECT pg_advisory_xact_lock(" . (int)crc32($table . '.' . $column) . ")");
    $p = $prefix . '-' . date('ym') . '-';
    $st = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT($column, 5) AS int)), 0) + 1 FROM $table WHERE $column ~ :pat");
    $st->execute([':pat' => '^' . $p . '[0-9]{5}$']);
    return $p . str_pad((string)intval($st->fetchColumn()), 5, '0', STR_PAD_LEFT);
}'''

GPT = r'''function mpCallChatGPT($prompt, $timeout = 30) {
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    $payload = ['model'=>'gpt-4o-mini','messages'=>[
        ['role'=>'system','content'=>'Anda asisten AI manajemen proyek konstruksi Indonesia. Jawab dalam BAHASA INDONESIA, format JSON valid tanpa teks di luar JSON.'],
        ['role'=>'user','content'=>$prompt]],
        'response_format'=>['type'=>'json_object'],'temperature'=>0.3];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Authorization: Bearer '.$apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($code !== 200) throw new Exception('OpenAI API gagal (HTTP '.$code.'): '.($err ?: $resp));
    $j = json_decode($resp, true);
    if (isset($j['choices'][0]['message']['content'])) {
        return trim(str_replace(['@BT@json', '@BT@'], '', $j['choices'][0]['message']['content']));
    }
    throw new Exception('OpenAI API: '.($j['error']['message'] ?? 'Format respons tidak dikenali'));
}'''.replace('@BT@', chr(96)*3)

p = 'php-func/function.all.php'; s = rd(p)
n = len(re.findall(r'\?>\s*<\?php', s))
s = re.split(r'\?>\s*<\?php', s)[0].rstrip()
if s.endswith('?>'): s = s[:-2].rstrip()
if n: log.append('function.all.php: buang %d blok duplikat + fragmen SQL yatim' % n)
s,_ = rf(s, 'SetPgAppUser', SETPG)
s,_ = rf(s, 'String_to_float', S2F)
s,_ = rf(s, 'rAmountReport', RAMT)
if 'form_access' in s:
    s,_ = rf(s, 'CheckCRUD', CHECK); log.append('function.all.php: CheckCRUD -> access_level')
s, ok = rf(s, 'RecalcGrnStatus', RECALC)
if not ok: s += '\n\n' + RECALC
log.append('function.all.php: RecalcGrnStatus sadar transaksi PDO')
if 'function CreateRefNoDb(' not in s: s += '\n\n' + REFDB; log.append('function.all.php: + CreateRefNoDb')
if 'function mpCallChatGPT(' not in s: s += '\n\n' + GPT; log.append('function.all.php: + mpCallChatGPT')
wr(p, s.rstrip() + '\n')

p = 'php-crud/purchaseinvoiceCRD.php'
if pathlib.Path(p).exists():
    s = rd(p); o = s
    s = re.sub(r"if\s*\(file_exists\('\.\./php-func/function\.all\.php'\)\)\s*\{\s*require_once\('\.\./php-func/function\.all\.php'\);\s*\}",
               "require_once __DIR__ . '/../php-func/function.all.php';", s)
    s = re.sub(r"if\s*\(file_exists\('\.\./php-class/crudClass\.php'\)\)\s*\{\s*require_once\('\.\./php-class/crudClass\.php'\);\s*\}",
               "require_once __DIR__ . '/../php-class/crudClass.php';", s)
    if s != o: log.append('purchaseinvoiceCRD.php: include pakai __DIR__'); o = s
    s = s.replace("WHERE h.status IN ('open', 'partial')",
                  "WHERE COALESCE(LOWER(h.status), '') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')")
    if s != o: log.append('purchaseinvoiceCRD.php: GRN closed bisa di-invoice')
    if 'pg_advisory_xact_lock(7201)' not in s:
        o = s
        s = s.replace('$stSeq = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(inv_no, 5) AS integer)), 0) + 1',
                      '$db->query("SELECT pg_advisory_xact_lock(7201)");\n$stSeq = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(inv_no, 5) AS integer)), 0) + 1', 1)
        if s != o: log.append('purchaseinvoiceCRD.php: kunci nomor PINV')
    if 'dpp_base' not in s:
        o = s
        s = re.sub(r'(\$stList = \$db->query\("SELECT h\.id, h\.inv_no, h\.supplier_invoice_no, h\.invoice_date, h\.dpp_amount, h\.ppn_amount, h\.total_amount)\s*\n(FROM purchase_invoice_hdr h)',
                   r"\1,\nCOALESCE((SELECT SUM(d.matched_amount * COALESCE(NULLIF(g.exch_rate,0),1))\nFROM purchase_invoice_dtl d JOIN grn_hdr g ON g.id = d.grn_hdr_id\nWHERE d.invoice_hdr_id = h.id), 0) AS dpp_base\n\2", s, count=1)
        s = re.sub(r"PiPostJournal\(\$db, \(int\)\$r\['id'\], \$r\['inv_no'\], \(string\)\$r\['supplier_invoice_no'\], \$r\['invoice_date'\],\s*round\(floatval\(\$r\['dpp_amount'\]\), 2\), round\(floatval\(\$r\['ppn_amount'\]\), 2\)\);",
                   "$dppTxn = round(floatval($r['dpp_amount']), 2);\n$ppnTxn = round(floatval($r['ppn_amount']), 2);\n$dppBase = round(floatval($r['dpp_base']), 2);\n$ppnBase = ($dppTxn > 0) ? round($ppnTxn * ($dppBase / $dppTxn), 2) : 0.0;\nPiPostJournal($db, (int)$r['id'], $r['inv_no'], (string)$r['supplier_invoice_no'], $r['invoice_date'], $dppBase, $ppnBase);",
                   s, count=1)
        if s != o: log.append('purchaseinvoiceCRD.php: backfill jurnal mata uang dasar')
    wr(p, s)

p = 'php-crud/whrcvCRD.php'
if pathlib.Path(p).exists():
    s = rd(p); o = s
    s = s.replace('RecalcGrnStatus($grn_hdr_id);', 'RecalcGrnStatus($grn_hdr_id, $db);')
    s = s.replace("RecalcGrnStatus($hdr['grn_hdr_id']);", "RecalcGrnStatus($hdr['grn_hdr_id'], $db);")
    if s != o: wr(p, s); log.append('whrcvCRD.php: RecalcGrnStatus ikut transaksi PDO')

p = 'php-crud/grnCRD.php'
if pathlib.Path(p).exists():
    s = rd(p); o = s
    if '$qtyMaxAllowed = round($po_qty * 1.10, 4);' in s:
        s = s.replace('$qtyMaxAllowed = round($po_qty * 1.10, 4);',
            '$stTol = pg_query_params("SELECT COALESCE(b.tolerance_pct,0) AS tol FROM po_items pi LEFT JOIN budget_items b ON b.id = pi.budget_item_id WHERE pi.id = $1", [$po_dtl_id]);\n    PgOrFail($stTol, \'Gagal cek toleransi RAB\');\n    $tolPct = (float)(pg_fetch_assoc($stTol)[\'tol\'] ?? 0);\n    $qtyMaxAllowed = round($po_qty * (1 + $tolPct / 100), 4);')
        log.append('grnCRD.php: toleransi ikut RAB')
    if 'WHERE po_item_id = $1 AND grn_hdr_id <> $2' in s:
        s = s.replace('WHERE po_item_id = $1 AND grn_hdr_id <> $2',
            "WHERE po_item_id = $1 AND grn_hdr_id <> $2\n              AND grn_hdr_id IN (SELECT id FROM grn_hdr WHERE COALESCE(LOWER(status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed'))", 1)
        log.append('grnCRD.php: GRN batal tidak memakan kuota')
    if s != o: wr(p, s)

print('\n== Hasil ==')
for x in log: print('  ' + x)
if not log: print('  (tidak ada perubahan)')
PY

echo ""
echo "== Lint =="
FAIL=0
for f in $FILES; do [ -f "$f" ] && { php -l "$f" || FAIL=1; }; done
if [ "$FAIL" -ne 0 ]; then
  echo "LINT GAGAL -> restore backup"
  for f in $FILES; do [ -f "$f.bak-$S" ] && cp -p "$f.bak-$S" "$f"; done
  exit 1
fi
echo ""
echo "SELESAI. Reload: sudo systemctl reload apache2"
