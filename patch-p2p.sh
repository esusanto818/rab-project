#!/usr/bin/env bash
# patch-p2p.sh — jalankan dari root repo: cd ~/proyekgmn-dev && bash patch-p2p.sh
# Opsi: bash patch-p2p.sh --no-git   (terapkan patch saja, tanpa branch/commit)
set -euo pipefail
cd "$(dirname "$0")"
[ -d php-func ] && [ -d php-crud ] || { echo "ERROR: jalankan dari root repo (~/proyekgmn-dev)."; exit 1; }
command -v php >/dev/null || { echo "ERROR: php CLI tidak tersedia."; exit 1; }
command -v python3 >/dev/null || { echo "ERROR: python3 tidak tersedia."; exit 1; }

STAMP=$(date +%Y%m%d-%H%M%S)
DO_GIT=1; [ "${1:-}" = "--no-git" ] && DO_GIT=0
FILES="php-func/function.all.php php-crud/poCRD.php php-crud/purchaseinvoiceCRD.php php-crud/whrcvCRD.php php-crud/grnCRD.php"

echo "== Backup =="
for f in $FILES; do [ -f "$f" ] && cp -p "$f" "$f.bak-$STAMP" && echo "  $f.bak-$STAMP"; done

python3 - <<'PY'
import re, pathlib
rep = []
def rd(p): return pathlib.Path(p).read_text()
def wr(p,s): pathlib.Path(p).write_text(s)
def has(p): return pathlib.Path(p).exists()

def replace_func(s, name, new):
    m = re.search(r'function\s+'+name+r'\s*\(', s)
    if not m: return s, False
    i = s.index('{', m.end()); depth=0; j=i
    while j < len(s):
        if s[j]=='{': depth+=1
        elif s[j]=='}':
            depth-=1
            if depth==0: break
        j+=1
    return s[:m.start()] + new + s[j+1:], True

# ================= 1. function.all.php =================
NEW_SETPG = '''function SetPgAppUser($connOverride = null) {
    global $conn;
    $c = $connOverride ?: $conn;
    $username = $_SESSION['username'] ?? $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'system';
    $res = pg_query_params($c, "SELECT set_config('app.username', $1, true)", [$username]);
    if ($res === false) throw new Exception('[SetPgAppUser] ' . pg_last_error($c));
}'''

NEW_CHECK = '''function CheckCRUD($task, $userId, $formId) {
    global $conn;
    $st = pg_query_params($conn, "SELECT access_level FROM m_user_access_menu WHERE user_id = $1 AND form_id = $2", [$userId, $formId]);
    if ($st === false) throw new Exception('[CheckCRUD] ' . pg_last_error($conn));
    $lv = pg_num_rows($st) ? (int)pg_fetch_result($st, 0, 0) : 0;
    $need = ['read'=>1,'create'=>2,'update'=>3,'delete'=>4][$task] ?? 1;
    return $lv >= $need ? $task : 'xxx';
}'''

NEW_S2F = '''function String_to_float($string_obj) {
    $s = trim((string)$string_obj);
    $neg = (strpos($s, '-') === 0);
    $s = str_replace(['-', ','], '', $s);
    $v = (float)$s;
    return $neg ? -$v : $v;
}'''

NEW_RAMT = '''function rAmountReport($number, $round) {
    if ($number > 0) return number_format(floor($number / $round) * $round, 0, '.', ',');
    if ($number < 0) return number_format(floor(($number * -1) / $round) * $round * -1, 0, '.', ',');
    return '-';
}'''

NEW_RECALC = '''function RecalcGrnStatus($grn_hdr_id, $db = null) {
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
    if ((int)$row['total_items'] === 0)                               $status = 'open';
    elseif ((int)$row['fully_received'] === (int)$row['total_items']) $status = 'closed';
    elseif ((int)$row['fully_received'] > 0 || (int)$row['partially_received'] > 0) $status = 'partial';
    else                                                              $status = 'open';
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

NEW_REFDB = '''function CreateRefNoDb($db, $table, $column, $prefix) {
    $allowed = ['wh_receiving_hdr'=>'ref_no', 'supplier_payments'=>'payment_no',
                'purchase_orders'=>'po_number', 'grn_hdr'=>'ref_no', 'purchase_invoice_hdr'=>'inv_no'];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) throw new Exception('CreateRefNoDb: tabel/kolom tidak diizinkan.');
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$prefix));
    if ($prefix === '') throw new Exception('CreateRefNoDb: prefix tidak valid.');
    $db->query("SELECT pg_advisory_xact_lock(" . (int)crc32($table . '.' . $column) . ")");
    $p = $prefix . '-' . date('ym') . '-';
    $st = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT($column, 5) AS int)), 0) + 1 FROM $table WHERE $column ~ :pat");
    $st->execute([':pat' => '^' . $p . '[0-9]{5}$']);
    return $p . str_pad((string)intval($st->fetchColumn()), 5, '0', STR_PAD_LEFT);
}'''

NEW_GPT = '''function mpCallChatGPT($prompt, $timeout = 30) {
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    $apiUrl = "https://api.openai.com/v1/chat/completions";
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'Anda asisten AI manajemen proyek konstruksi Indonesia. Seluruh respons BAHASA INDONESIA, format JSON valid tanpa teks di luar JSON.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.3
    ];
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200) {
        $errorMsg = $curlError ? $curlError : $response;
        throw new Exception("Gagal terhubung ke OpenAI API (HTTP {$httpCode}): {$errorMsg}");
    }
    $apiResult = json_decode($response, true);
    if (isset($apiResult['choices'][0]['message']['content'])) {
        $raw = $apiResult['choices'][0]['message']['content'];
        return trim(str_replace(['```json', '```'], '', $raw));
    }
    $errMsg = $apiResult['error']['message'] ?? 'Format respons tidak dikenali';
    throw new Exception("Error dari OpenAI API: {$errMsg}");
}'''

p = 'php-func/function.all.php'; s = rd(p)
n0 = len(re.findall(r'\?>\s*<\?php', s))
s = re.split(r'\?>\s*<\?php', s)[0].rstrip()
if s.endswith('?>'): s = s[:-2].rstrip()
if n0: rep.append(('function.all.php', f'buang {n0} blok duplikat + fragmen SQL yatim'))

s,_ = replace_func(s, 'SetPgAppUser', NEW_SETPG)
s,_ = replace_func(s, 'String_to_float', NEW_S2F)
s,_ = replace_func(s, 'rAmountReport', NEW_RAMT)
if 'form_access' in s:
    s,_ = replace_func(s, 'CheckCRUD', NEW_CHECK); rep.append(('function.all.php','CheckCRUD -> access_level'))
s,ok = replace_func(s, 'RecalcGrnStatus', NEW_RECALC)
if not ok: s += '\n\n' + NEW_RECALC
rep.append(('function.all.php','RecalcGrnStatus sadar transaksi PDO'))
if 'function CreateRefNoDb(' not in s: s += '\n\n' + NEW_REFDB; rep.append(('function.all.php','+ CreateRefNoDb'))
if 'function mpCallChatGPT(' not in s: s += '\n\n' + NEW_GPT; rep.append(('function.all.php','+ mpCallChatGPT'))
wr(p, s.rstrip() + '\n')

# ================= 2. poCRD.php =================
NEW_PO = r'''<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/* poCRD.php - sinkron po.js: kontrol nilai RAB + mata uang anggaran + PPN. */
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
$db = new crud();
const PO_EPS = 0.0001;
const PO_VEPS = 0.01;
const PPN_DEFAULT_RATE = 11.0;
const PPN_MODES = ['NONE', 'EXCL', 'INCL'];
const PO_TRANSITIONS = [
    'Draft'      => ['Diajukan', 'Dibatalkan'],
    'Diajukan'   => ['Disetujui', 'Draft', 'Dibatalkan'],
    'Disetujui'  => ['Selesai', 'Dibatalkan'],
    'Selesai'    => [],
    'Dibatalkan' => [],
];
class PoBusinessError extends Exception {}
function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else { $response['data'] = $data; }
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function po_fmt($n): string { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }
function po_ppn_mode($m): string { $m = strtoupper(trim((string)$m)); return in_array($m, PPN_MODES, true) ? $m : 'NONE'; }
function po_ppn_calc(float $subtotal, string $mode, float $rate): array {
    $subtotal = round($subtotal, 2); $mode = po_ppn_mode($mode);
    if ($mode === 'NONE' || $rate <= 0) return ['subtotal'=>$subtotal,'dpp'=>$subtotal,'ppn'=>0.0,'total'=>$subtotal];
    if ($mode === 'INCL') { $dpp = round($subtotal/(1+$rate/100),2); return ['subtotal'=>$subtotal,'dpp'=>$dpp,'ppn'=>round($subtotal-$dpp,2),'total'=>$subtotal]; }
    $ppn = round($subtotal*$rate/100,2); return ['subtotal'=>$subtotal,'dpp'=>$subtotal,'ppn'=>$ppn,'total'=>round($subtotal+$ppn,2)];
}
function po_line_dpp(float $qty, float $price, string $mode, float $rate): float {
    $sub = round($qty*$price,2);
    if ($mode === 'INCL' && $rate > 0) return round($sub/(1+$rate/100),2);
    return $sub;
}
function po_base_currency($db): array {
    $r = $db->query("SELECT fs.base_currency_id AS id, c.init FROM public.finance_settings fs JOIN public.m_currency c ON c.id = fs.base_currency_id ORDER BY fs.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) $r = $db->query("SELECT id, init FROM public.m_currency WHERE UPPER(TRIM(init))='IDR' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Mata uang dasar belum dikonfigurasi (finance_settings.base_currency_id).');
    return ['id'=>(int)$r['id'], 'code'=>strtoupper(trim((string)$r['init']))];
}
function po_currency_code($db, int $id): string {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $st = $db->prepare("SELECT UPPER(TRIM(init)) FROM public.m_currency WHERE id = :id"); $st->execute([':id'=>$id]);
    $c = (string)$st->fetchColumn(); $cache[$id] = $c !== '' ? $c : 'IDR'; return $cache[$id];
}
function po_sql_line_dpp(string $i, string $po): string {
    return "ROUND(CASE WHEN {$po}.ppn_mode='INCL' AND {$po}.ppn_rate>0 THEN ROUND({$i}.qty*{$i}.unit_price,2)/(1+{$po}.ppn_rate
