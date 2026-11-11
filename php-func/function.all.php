<?php
error_reporting(E_ALL & ~E_NOTICE);
if (!isset($_SESSION)) { session_start(); }

// Database credentials wajib disediakan melalui environment server, bukan source repository.
$dbHost = getenv('RAB_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = getenv('RAB_DB_PORT') ?: (getenv('DB_PORT') ?: '5432');
$dbUser = getenv('RAB_DB_USER') ?: (getenv('DB_USER') ?: 'postgres');
$dbDatabase = getenv('RAB_DB_NAME') ?: (getenv('DB_NAME') ?: 'proyekGMN');
$dbPass = getenv('RAB_DB_PASSWORD');
if ($dbPass === false) {
    $dbPass = getenv('DB_PASSWORD');
}
if ($dbPass === false) {
    $dbPass = '';
}

// Quote setiap nilai conninfo agar karakter khusus pada password/username tidak mengubah parameter koneksi.
if (!function_exists('app_pg_conninfo_value')) {
    function app_pg_conninfo_value($value): string {
        return "'" . str_replace(array("\\", "'"), array("\\\\", "\\'"), (string)$value) . "'";
    }
}

// Inisialisasi koneksi DB; jangan tampilkan detail koneksi ke browser.
$dbConnInfo = 'host=' . app_pg_conninfo_value($dbHost)
    . ' port=' . app_pg_conninfo_value($dbPort)
    . ' dbname=' . app_pg_conninfo_value($dbDatabase)
    . ' user=' . app_pg_conninfo_value($dbUser)
    . ' password=' . app_pg_conninfo_value($dbPass);
$dbconn = @pg_connect($dbConnInfo);
if (!$dbconn) {
    error_log('[DATABASE] PostgreSQL connection failed: ' . pg_last_error());
    http_response_code(500);
    die('Database connection unavailable.');
}

// Alias $conn dari $dbconn agar kompatibel di seluruh sistem
$conn = $dbconn;

/**
 * Defense-in-depth CSRF check for browser POST requests.
 * Mutating endpoints must be same-origin; GET mutations are separately blocked.
 */
if (!function_exists('app_require_same_origin_post')) {
    function app_require_same_origin_post(): void {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $expectedHostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $source = trim((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
        if ($expectedHostHeader === '' || $source === '') {
            http_response_code(403);
            error_log('[SECURITY] Blocked POST without Origin/Referer header.');
            exit('Permintaan ditolak: sumber request tidak dapat diverifikasi.');
        }

        $expectedScheme = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if (!in_array($expectedScheme, array('http', 'https'), true)) {
            $expectedScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        }
        $expectedParts = parse_url($expectedScheme . '://' . $expectedHostHeader);
        $sourceParts = parse_url($source);
        $expectedHost = strtolower((string)($expectedParts['host'] ?? ''));
        $sourceHost = strtolower((string)($sourceParts['host'] ?? ''));
        $sourceScheme = strtolower((string)($sourceParts['scheme'] ?? ''));
        $expectedPort = $expectedParts['port'] ?? ($expectedScheme === 'https' ? 443 : 80);
        $sourcePort = $sourceParts['port'] ?? ($sourceScheme === 'https' ? 443 : 80);

        if ($expectedHost === '' || $sourceHost === '' || $expectedHost !== $sourceHost
            || !in_array($sourceScheme, array('http', 'https'), true)
            || $sourceScheme !== $expectedScheme
            || $expectedPort !== $sourcePort
            || strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
            http_response_code(403);
            error_log('[SECURITY] Blocked cross-origin POST request.');
            exit('Permintaan ditolak: request harus berasal dari origin aplikasi.');
        }
    }
}

// Set application username
//$sqlSetUsername = "SET app.username='" . pg_escape_string($conn, $_SESSION["username"] ?? '') . "'";
//$outputSetUsername = pg_query($conn, $sqlSetUsername) or die('Connection failed: ' . pg_last_error($conn));

$days = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');

function searchData($findThis, $findInFields, $limit = null) {
  if (isset($findThis) && $findThis <> '' && $findThis <> null) {
    $limit = 99999;
    $searchDtl = explode("|", $findThis);
    $searchField = "";
    for ($c = 0; $c < count($searchDtl); $c++) {
      $inFileds = NULL;
      $searchField .= " and (";
      for ($x = 0; $x < count($findInFields); $x++) {
        if ($x > 0) { $inFileds .= " or "; }
        $inFileds .= " lower(" . $findInFields[$x] . ") ~~ '%" . strtolower($searchDtl[$c]) . "%' ";
      }
      $searchField .= $inFileds . ")";
    }
  } else {
    $limit = ($limit == null) ? 250 : $limit;
    $searchField = NULL;
  }
  return array("limit" => $limit, "searchSQL" => $searchField);
}

function srcDataGrid($fields, $find) {
  $rSreach = "( "; $r = 0;
  $findData = explode("%", $find);
  foreach ($findData as $s) {
    foreach ($fields as $value) {
      if ($r > 0) { $rSreach .= ' or '; }
      $rSreach .= "lower(" . $value . ") like '%" . strtolower($s) . "%'";
      $r++;
    }
  }
  $rSreach .= " )";
  return $rSreach;
}

function insertQ($table, $rows) {
    global $conn;

    $command = "INSERT INTO " . $table;
    $row = "";
    $value = "";

    foreach ($rows as $key => $values) {
        $row .= "," . $key;
        $valStr = $values ?? '';

        if (!empty($values) && is_string($valStr) && substr($valStr, 0, 8) === "currval(") {
            $value .= "," . $valStr;
        }
        else if ($values === '0' || $values === 0) {
            $value .= ",'" . $values . "'";
        }
        else if (!empty($values)) {
            $escapedVal = pg_escape_string($conn, (string)$valStr);
            $value .= ",'" . $escapedVal . "'";
        }
        else {
            $value .= ",NULL";
        }
    }

    $command .= "(" . substr($row, 1) . ")";
    $command .= " VALUES (" . substr($value, 1) . ") RETURNING id";

    return $command;
}

function updateQ($table, $data, $where = null) {
    global $conn;

    $command = "UPDATE " . $table . " SET ";
    $set = "";

    foreach ($data as $key => $values) {
        $valStr = $values ?? '';

        if ($values === '0' || $values === 0) {
            $set .= "," . $key . "='" . $values . "'";
        }
        else if (!empty($values)) {
            $escapedVal = pg_escape_string($conn, (string)$valStr);
            $set .= "," . $key . "='" . $escapedVal . "'";
        }
        else {
            $set .= "," . $key . "=NULL";
        }
    }

    $command .= substr(trim($set), 1);

    if ($where != null) {
        $command .= " WHERE " . $where;
    }

    return $command;
}
function CreateRefNo($tableName, $FieldCount, $RefInit) {
  global $conn;
  $sql = "
    (
    select CASE (count(".$FieldCount.")) WHEN 0
      THEN '".$RefInit."-".date('ym')."-00001'
      ELSE '".$RefInit."-".date('ym')."-'
        ||(select (substring('00000'||cast(CAST(max(substring(".$FieldCount." from '.....$'))AS int)+1 as varchar) from '.....$'))
        from ".$tableName."
        where substring(".$FieldCount.",length(".$FieldCount.")-9,2)='".date('y')."' and substring(".$FieldCount.",length(".$FieldCount.")-7,2)='".date('m')."')
      END  AS ref_no
    from ".$tableName." where substring(".$FieldCount.",length(".$FieldCount.")-9,2)='".date('y')."' and substring(".$FieldCount.",length(".$FieldCount.")-7,2)='".date('m')."'
    )
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}

function CreateWHRefNo($tableName, $FieldCount, $RefInit) {
  global $conn;
  $sql = "
    (
    select CASE (count(".$FieldCount.")) WHEN 0
      THEN '".$RefInit."-".date('ym')."-00001'
      ELSE '".$RefInit."-".date('ym')."-'
        ||(select (substring('00000'||cast(CAST(max(substring(".$FieldCount." from '.....$'))AS int)+1 as varchar) from '.....$'))
        from ".$tableName."
        where substring(".$FieldCount.",0,position('-' in ".$FieldCount."))='".$RefInit."' and substring(".$FieldCount.",length(ref_no)-9,2)='".date('y')."' and substring(".$FieldCount.",length(ref_no)-7,2)='".date('m')."')
      END  AS ref_no
    from ".$tableName." where substring(".$FieldCount.",0,position('-' in ".$FieldCount."))='".$RefInit."' and substring(".$FieldCount.",length(ref_no)-9,2)='".date('y')."' and substring(".$FieldCount.",length(ref_no)-7,2)='".date('m')."'
    )
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}

function CreateNikNo($unit, $status, $fromdate) {
  global $conn;
  $sql = "
    select (nikunitinit((select init from m_location where id in (select location_id from m_department where id=".$unit.")),(select name from m_employee_status where id=".$status."))||'".date('y',strtotime($fromdate))."'||(substring('0000'||cast((coalesce(cast(max(substring(nik::varchar(8) from '....$'))as int),0)+1) as varchar) from '....$')))::varchar(8) AS ref_no
    from m_employee
    where substring(nik::varchar(8),3,2)='".date('y',strtotime($fromdate))."'
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}
/**
 * Menetapkan variabel sesi PostgreSQL (app.username) untuk audit log / trigger
 * Gunakan ini tepat setelah pg_query('BEGIN') pada koneksi pg_query
 */
function SetPgAppUser($conn = null) {
    global $conn; // Menggunakan koneksi $conn dari function.all.php
    $username = $_SESSION['username'] ?? $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'system';
    
    $res = pg_query_params($conn, "SELECT set_config('app.username', $1, true)", [$username]);
    if ($res === false) {
        throw new Exception('[SetPgAppUser] Gagal menetapkan app.username: ' . pg_last_error($conn));
    }
}
function json2Array($jsonData) {
    global $conn;

    $jArray = json_decode(stripslashes((string)($jsonData ?? '')), true);

    if (is_array($jArray) && count($jArray) > 0) {
        $escapedItems = array();

        foreach ($jArray as $item) {
            $itemStr = (string)($item ?? '');
            $escapedItems[] = "'" . pg_escape_string($conn, $itemStr) . "'";
        }

        return implode(",", $escapedItems);
    }

    return "";
}

function String_to_float($string_obj) {
  $string = $string_obj;
  $a = explode(".", $string);
  $a2 = explode(",", $a[0]);
  $int = implode("", $a2);
  $dec = isset($a[1]) ? $a[1] : 0;
  $lengthofnum = strlen($dec);
  $divider = "1";
  $i = 0;
  while ($i < $lengthofnum) {
    $divider .= "0";
    $i++;
  }
  $divider = (int)$divider;
  return $int + ($dec / $divider);
}

function EncodeData($MyData) { return base64_encode($MyData); }
function DecodeData($MyData) { return base64_decode($MyData); }

function rNum($number, $round) { return floor($number / $round) * $round; }

function rAmountReport($number, $round) {
  if ($number > 0) {
    return number_format(floor($number / $round) * $round, 0, '.', ',');
  } elseif ($number < 0) {
    return number_format(floor((($number * -1) / $round) * $round) * -1, 0, '.', ',');
  } else {
    return '-';
  }
}

function GetFormId($formURl) {
  global $conn;
  $formName = explode("/", $formURl);
  $getFormName = end($formName);
  $sql = "select * from m_form_name where php_url='" . pg_escape_string($conn, $getFormName) . "'";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $formId = null;
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $formId = $objid["id"];
    }
  }
  return $formId;
}

function CheckCRUD($task, $userId, $formId) {
  global $conn;
  $newTask = 'xxx';
  $sql = "select * from m_user_access_menu where user_id='" . pg_escape_string($conn, $userId) . "' and form_id=" . intval($formId);
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $formAccess = 0;
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $formAccess = $objid["form_access"];
    }
  }
  switch ($formAccess) {
    case (1):
      if ($task == 'create' || $task == 'update' || $task == 'delete') { $newTask = 'xxx'; } else { $newTask = $task; }
      break;
    case (2):
      if ($task == 'delete') { $newTask = 'xxx'; } else { $newTask = $task; }
      break;
    case (3):
      $newTask = $task;
      break;
  }
  return $newTask;
}

function Str2N($StrData) {
  return floatval(preg_replace("/[^-0-9\.]/", "", $StrData));
}

function checksinglequote($txt) {
    global $conn;
    if ($txt === null || is_bool($txt)) {
        return '';
    }
    $quotes = array("'", '"');
    return str_replace($quotes, '', pg_escape_string($conn, (string)$txt));
}

function deleteQ($table, $where = null) {
    global $conn;

    $command = "DELETE FROM " . $table;

    if ($where != null && $where !== '') {
        $command .= " WHERE " . $where;
    }

    return $command;
};

/*
  Fungsi   : DbOrFail()
  Kenapa   : crud::insert() / crud::update() / crud::delete() (di
             crudClass.php) MENANGKAP PDOException sendiri dan hanya
             `return false` -- pesan errornya tersimpan di $db->getResult(),
             TIDAK pernah dilempar ulang sebagai exception. Akibatnya kalau
             kode pemanggil tidak mengecek return value-nya secara eksplisit,
             kegagalan insert/update jadi senyap: transaksi Postgres sudah
             aborted, tapi PHP tetap lanjut ke statement berikutnya sampai
             akhirnya meledak sebagai "25P02: current transaction is aborted"
             di query lain yang sebenarnya tidak bersalah.

  Cara pakai: bungkus SETIAP panggilan $db->insert()/update()/delete() yang
  ada di dalam try/beginTransaction() dengan fungsi ini, supaya kegagalan
  langsung ketahuan & transaksi langsung rollback dengan pesan asli dari
  Postgres -- bukan pesan 25P02 yang membingungkan.

      DbOrFail($db, $db->insert('journal_entries', [...]), 'insert journal_entries');

  Throws Exception dengan pesan asli dari $db->getResult() jika $ok === false.
*/
function DbOrFail($db, $ok, $context = '') {
    if ($ok === false) {
        $raw = $db->getResult();
        $decoded = json_decode($raw, true);
        $msg = $decoded['message'] ?? $raw ?? 'Operasi database gagal (tanpa pesan detail).';
        throw new Exception(($context ? "[{$context}] " : '') . $msg);
    }
    return $ok;
}
/*
  Fungsi      : AutoPostJournal()
  Lokasi      : Tambahkan ke function.all.php (shared, dipakai lintas modul)
  Tujuan      : Generate jurnal otomatis dari modul transaksi (Sales Invoice,
                Purchase GRN, Customer Payment, dll) berdasarkan rule yang
                sudah didefinisikan di tabel fiscal_config.

  Cara pakai dari modul lain (contoh setelah simpan Sales Invoice baru):

      require_once __DIR__ . '/../php-func/function.all.php';
      $result = AutoPostJournal($db, [
          'rule_code'      => 'SLSCASH',           // cocokkan ke fiscal_config.rule_code
          'reference_type' => 'sales_univoice',      // harus salah satu nilai enum reference_type
          'reference_id'   => $newInvoiceId,        // id record di tabel sumber (sales_univoices dkk)
          'journal_date'   => $univoiceDate,         // tanggal transaksi
          'amount'         => $totalAmount,         // nominal transaksi
          'description'    => 'Invoice ' . $univoiceNo
      ]);

      if (!$result['success']) {
          // tangani error -- misal rule tidak ditemukan, atau periode closed.
          // Modul pemanggil TETAP bisa lanjut simpan transaksinya (jurnal
          // gagal dibuat bukan berarti transaksi sumber harus dibatalkan --
          // itu keputusan bisnis, sesuaikan sesuai kebutuhan Anda).
      }

  --------------------------------------------------------------------------
  UPGRADE: Override akun per transaksi (mis. Payment Entry dengan combo
  pilihan Kas/Bank dinamis dari user, bukan akun tetap dari fiscal_config).

  rule_code TETAP WAJIB diisi dan tetap divalidasi ke fiscal_config -- rule
  dipakai untuk kontrol/audit bahwa hanya kombinasi posting yang sudah
  didaftarkan yang boleh jalan, dan sebagai fallback account bila override
  tidak dikirim. Tambahkan parameter opsional:

      'debit_account_id'  => int   // override akun debit rule utk transaksi ini
      'credit_account_id' => int   // override akun kredit rule utk transaksi ini

  Contoh Payment Entry (Kas/Bank dipilih user, kredit ikut rule AR/Uang Muka):

      AutoPostJournal($db, [
          'rule_code'         => 'PAY_AR',
          'reference_type'    => 'payment_entry',
          'reference_id'      => $paymentId,
          'journal_date'      => $payDate,
          'amount'            => $allocatedTotal,
          'description'       => 'Pelunasan Invoice ' . $paymentNo,
          'debit_account_id'  => $accountId,   // akun Kas/Bank pilihan user
      ]);

  Return: ['success' => bool, 'message' => string, 'journal_id' => int|null]
*/

function AutoPostJournal($db, $params) {
    $ruleCode      = $params['rule_code'] ?? '';
    $referenceType = $params['reference_type'] ?? '';
    $referenceId   = intval($params['reference_id'] ?? 0);
    $journalDate   = $params['journal_date'] ?? date('Y-m-d');
    $amount        = Str2N($params['amount'] ?? 0);
    $description   = $params['description'] ?? '';

    // Override opsional -- kalau diisi, dipakai menggantikan akun dari rule.
    $debitAccountIdOverride  = intval($params['debit_account_id']  ?? 0);
    $creditAccountIdOverride = intval($params['credit_account_id'] ?? 0);

    if (empty($ruleCode) || empty($referenceType) || $amount <= 0) {
        return ['success' => false, 'message' => 'Parameter AutoPostJournal tidak lengkap (rule_code/reference_type/amount).', 'journal_id' => null];
    }

    // 1. Cari rule di fiscal_config (tetap wajib ada & aktif, sebagai kontrol
    //    posting resmi -- walau akunnya nanti bisa di-override per transaksi)
    $stmtRule = $db->prepare("SELECT debit_account_id, credit_account_id, rule_name FROM fiscal_config WHERE rule_code = :code AND is_active = true");
    $stmtRule->execute(['code' => $ruleCode]);
    $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);
    if (!$rule) {
        return ['success' => false, 'message' => "Rule posting '{$ruleCode}' tidak ditemukan atau tidak aktif di fiscal_config.", 'journal_id' => null];
    }

    $debitAccountId  = $debitAccountIdOverride  > 0 ? $debitAccountIdOverride  : intval($rule['debit_account_id']);
    $creditAccountId = $creditAccountIdOverride > 0 ? $creditAccountIdOverride : intval($rule['credit_account_id']);

    if ($debitAccountId <= 0 || $creditAccountId <= 0) {
        return ['success' => false, 'message' => "Akun debit/kredit untuk rule '{$ruleCode}' tidak valid (cek fiscal_config atau override yang dikirim).", 'journal_id' => null];
    }

    // 2. Cari periode akuntansi berdasarkan tanggal transaksi
    $stmtPeriod = $db->prepare("
        SELECT id, is_closed FROM accounting_periods
        WHERE :jdate BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmtPeriod->execute(['jdate' => $journalDate]);
    $period = $stmtPeriod->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        return ['success' => false, 'message' => "Tidak ada periode akuntansi yang mencakup tanggal {$journalDate}. Buat periode-nya dulu di menu Fiscal Period.", 'journal_id' => null];
    }
    if ($period['is_closed']) {
        return ['success' => false, 'message' => "Periode akuntansi untuk tanggal {$journalDate} sudah ditutup. Jurnal otomatis tidak bisa dibuat.", 'journal_id' => null];
    }

    // 3. Buat header jurnal -- langsung status 'posted' (bukan draft), karena
    //    ini auto-generate dari transaksi yang sudah tervalidasi di modul asal.
    // [FIX-REFNO] CreateRefNo() memakai koneksi pg terpisah ($conn) sehingga TIDAK melihat jurnal yang baru di-insert
    //             lewat PDO ($db) dan belum di-commit -> posting >1 jurnal dalam satu transaksi menghasilkan ref_no kembar
    //             (unique violation journal_entries_journal_no_key). Nomor jurnal sekarang dibuat lewat $db (koneksi/transaksi yang sama).
    $refNo = JournalNextRefNo($db); // JV = Journal Voucher (auto)

    try {
        DbOrFail($db, $db->insert('journal_entries', [
            'ref_no'         => $refNo,
            'journal_date'   => $journalDate,
            'period_id'      => $period['id'],
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'description'    => $description ?: $rule['rule_name'],
            'status'         => 'posted',
            'posted_by'      => $_SESSION['user_id'] ?? null,
            'posted_at'      => date('Y-m-d H:i:s')
        ]), 'insert journal_entries');

        $stmtNewId = $db->prepare("SELECT id FROM journal_entries WHERE ref_no = :no");
        $stmtNewId->execute(['no' => $refNo]);
        $journalId = $stmtNewId->fetch(PDO::FETCH_ASSOC)['id'] ?? null;

        if (!$journalId) {
            return ['success' => false, 'message' => 'Gagal membuat header jurnal.', 'journal_id' => null];
        }

        // 4. Insert 2 baris (debit & kredit) -- pakai akun hasil override jika ada
        DbOrFail($db, $db->insert('journal_lines', [
            'journal_id'  => $journalId,
            'account_id'  => $debitAccountId,
            'debit'       => $amount,
            'credit'      => 0,
            'description' => $description
        ]), 'insert journal_lines (debit)');

        DbOrFail($db, $db->insert('journal_lines', [
            'journal_id'  => $journalId,
            'account_id'  => $creditAccountId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => $description
        ]), 'insert journal_lines (credit)');

    } catch (Exception $e) {
        // Pesan asli dari Postgres (mis. constraint reference_type/status,
        // FK period_id/account_id tidak valid, dsb) dikembalikan ke pemanggil
        // -- bukan dibiarkan jadi 25P02 senyap di query lain.
        return ['success' => false, 'message' => $e->getMessage(), 'journal_id' => null];
    }

    return ['success' => true, 'message' => "Jurnal otomatis {$refNo} berhasil dibuat.", 'journal_id' => $journalId];
}

/*
  Fungsi   : ReverseJournalByReference()
  Tujuan   : Hapus semua jurnal (header + lines) yang tercatat untuk sebuah
             reference_type + reference_id tertentu. Dipakai saat modul
             sumber (Payment Entry, Sales Invoice, dll) di-update atau
             dihapus, sebelum AutoPostJournal() dipanggil ulang.

  Catatan  : Satu reference_type+reference_id bisa punya LEBIH DARI SATU
             journal_entries (mis. Payment Entry dengan alokasi Piutang +
             Uang Muka = 2 kali panggil AutoPostJournal = 2 journal_entries).
             Fungsi ini menghapus semuanya sekaligus.

  Return: int  -> jumlah journal_entries yang dihapus
*/
function ReverseJournalByReference($db, $referenceType, $referenceId) {
    $referenceId = intval($referenceId);
    if (empty($referenceType) || $referenceId <= 0) {
        return 0;
    }

    $stmtFind = $db->prepare("SELECT id FROM journal_entries WHERE reference_type = :rtype AND reference_id = :rid");
    $stmtFind->execute(['rtype' => $referenceType, 'rid' => $referenceId]);
    $journalIds = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

    if (!$journalIds) {
        return 0;
    }

    $stmtDelLines  = $db->prepare("DELETE FROM journal_lines WHERE journal_id = :jid");
    $stmtDelHeader = $db->prepare("DELETE FROM journal_entries WHERE id = :jid");

    foreach ($journalIds as $jid) {
        $stmtDelLines->execute(['jid' => $jid]);
        $stmtDelHeader->execute(['jid' => $jid]);
    }

    return count($journalIds);
}

/*
  Fungsi   : JournalNextRefNo()   [FIX-REFNO][NEW]
  Tujuan   : Nomor jurnal berikutnya format JV-YYMM-NNNNN (sama dengan CreateRefNo) tetapi dibaca lewat PDO $db,
             jadi ikut melihat baris yang belum di-commit pada transaksi yang sama.
             Advisory lock transaksi mencegah dua proses mengambil nomor yang sama bersamaan.
*/
function JournalNextRefNo($db) {
    $prefix = 'JV-' . date('ym') . '-';
    $db->query("SELECT pg_advisory_xact_lock(7001)");   // lock dilepas otomatis saat COMMIT/ROLLBACK
    $st = $db->prepare("SELECT COALESCE(MAX(CAST(RIGHT(ref_no, 5) AS int)), 0) + 1
                          FROM journal_entries
                         WHERE ref_no ~ :pat");
    $st->execute(['pat' => '^' . $prefix . '[0-9]{5}$']);
    return $prefix . str_pad((string)intval($st->fetchColumn()), 5, '0', STR_PAD_LEFT);
}

/*
  Fungsi   : PostStockMovement()
  Tujuan   : Catat 1 baris pergerakan stok (IN atau OUT) ke ledger
             stock_movements. Dipakai oleh GRN (IN), Sales Delivery (OUT),
             Stock Transfer (IN+OUT sepasang), Purchase/Sales Return, dll.

  $params = [
      'movement_date'  => 'YYYY-MM-DD',  (required)
      'item_id'        => int,            (required)
      'grade_id'       => int|null,
      'warehouse_id'   => int,            (required)
      'movement_type'  => 'IN'|'OUT',     (required)
      'qty'            => float,          (required, > 0)
      'unit_cost'      => float,          (optional, default 0)
      'reference_type' => string,         (required)
      'reference_id'   => int,            (required)
      'reference_no'   => string,         (optional, buat tampilan cepat)
      'remark'         => string,
  ]

  Return: int  -> id baris stock_movements yang baru diinsert
  Throws Exception kalau parameter wajib kosong / movement_type tidak valid.
*/
function PostStockMovement($db, $params) {
    $movementDate  = $params['movement_date'] ?? null;
    $itemId        = intval($params['item_id'] ?? 0);
    $gradeId       = isset($params['grade_id']) && $params['grade_id'] !== '' ? intval($params['grade_id']) : null;
    $warehouseId   = intval($params['warehouse_id'] ?? 0);
    $movementType  = strtoupper($params['movement_type'] ?? '');
    $qty           = round(floatval($params['qty'] ?? 0), 4);
    $unitCost      = round(floatval($params['unit_cost'] ?? 0), 4);
    $referenceType = $params['reference_type'] ?? '';
    $referenceId   = intval($params['reference_id'] ?? 0);
    $referenceNo   = $params['reference_no'] ?? null;
    $remark        = $params['remark'] ?? null;

    if (empty($movementDate) || $itemId <= 0 || $warehouseId <= 0 || $qty <= 0) {
        throw new Exception("Parameter PostStockMovement tidak lengkap (movement_date/item_id/warehouse_id/qty wajib diisi & valid).");
    }
    if (!in_array($movementType, ['IN', 'OUT'], true)) {
        throw new Exception("movement_type harus 'IN' atau 'OUT', diterima: '{$movementType}'.");
    }
    if (empty($referenceType) || $referenceId <= 0) {
        throw new Exception("Parameter PostStockMovement tidak lengkap (reference_type/reference_id wajib diisi).");
    }

    $ok = $db->insert('stock_movements', [
        'movement_date'  => $movementDate,
        'item_id'        => $itemId,
        'grade_id'       => $gradeId,
        'warehouse_id'   => $warehouseId,
        'movement_type'  => $movementType,
        'qty'            => $qty,
        'unit_cost'      => $unitCost,
        'reference_type' => $referenceType,
        'reference_id'   => $referenceId,
        'reference_no'   => $referenceNo,
        'remark'         => $remark,
        'created_by'     => $_SESSION['user_id'] ?? null
    ]);

    if ($ok === false) {
        throw new Exception("Gagal insert stock_movements: " . $db->getResult());
    }

    return $db->lastInsertId();
}

/*
  Fungsi   : ReverseStockMovementByReference()
  Tujuan   : Hapus semua baris stock_movements untuk reference_type +
             reference_id tertentu. Dipakai saat modul sumber (GRN, Sales
             Delivery, dll) di-update atau dihapus, sebelum
             PostStockMovement() dipanggil ulang.

  Return: int -> jumlah baris yang dihapus
*/
function ReverseStockMovementByReference($db, $referenceType, $referenceId) {
    $referenceId = intval($referenceId);
    if (empty($referenceType) || $referenceId <= 0) {
        return 0;
    }

    $stmtCount = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE reference_type = :rtype AND reference_id = :rid");
    $stmtCount->execute(['rtype' => $referenceType, 'rid' => $referenceId]);
    $count = intval($stmtCount->fetchColumn());

    if ($count > 0) {
        $stmtDel = $db->prepare("DELETE FROM stock_movements WHERE reference_type = :rtype AND reference_id = :rid");
        $stmtDel->execute(['rtype' => $referenceType, 'rid' => $referenceId]);
    }

    return $count;
}

/*
  Fungsi   : GetStockBalance()
  Tujuan   : Ambil saldo stok saat ini untuk 1 item (+grade opsional) di
             1 gudang tertentu, langsung dari view_stock_balance.
*/
function GetStockBalance($db, $itemId, $gradeId, $warehouseId) {
    if ($gradeId) {
        $stmt = $db->prepare("SELECT balance_qty FROM view_stock_balance WHERE item_id = :item_id AND grade_id = :grade_id AND warehouse_id = :warehouse_id");
        $stmt->execute(['item_id' => $itemId, 'grade_id' => $gradeId, 'warehouse_id' => $warehouseId]);
    } else {
        $stmt = $db->prepare("SELECT balance_qty FROM view_stock_balance WHERE item_id = :item_id AND grade_id IS NULL AND warehouse_id = :warehouse_id");
        $stmt->execute(['item_id' => $itemId, 'warehouse_id' => $warehouseId]);
    }
    $balance = $stmt->fetchColumn();
    return $balance !== false ? round(floatval($balance), 4) : 0.0;
}



?><?php
/**
 * GANTI RecalcGrnStatus() yang sudah ada di function.all.php dengan ini.
 * Perbaikan: DbOrFail($result,'msg') dihapus -- itu salah pakai (DbOrFail
 * asli butuh $db + boolean, bukan pg_query_params() resource). Native
 * pg_query_params() dicek manual di sini.
 */
function RecalcGrnStatus($grn_hdr_id) {
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
            WHERE gd.grn_hdr_id = $1";
    $result = pg_query_params($sql, [$grn_hdr_id]);
    if ($result === false) {
        throw new Exception('[RecalcGrnStatus] ' . pg_last_error());
    }
    $row = pg_fetch_assoc($result);

    if ((int)$row['total_items'] === 0) {
        $status = 'open';
    } elseif ((int)$row['fully_received'] === (int)$row['total_items']) {
        $status = 'closed';
    } elseif ((int)$row['fully_received'] > 0 || (int)$row['partially_received'] > 0) {
        $status = 'partial';
    } else {
        $status = 'open';
    }

    $upd = pg_query_params("UPDATE grn_hdr SET status = $1 WHERE id = $2", [$status, $grn_hdr_id]);
    if ($upd === false) {
        throw new Exception('[RecalcGrnStatus update] ' . pg_last_error());
    }

    return $status;
}
function mpCallChatGPT($prompt, $timeout = 30) {
    // 1. Masukkan API Key OpenAI Anda di sini (atau via Environment Variable)
    $apiKey = getenv('OPENAI_API_KEY') ?: 'sk-proj-Lorem ipsum dolor sit amet, consectetur adipiscing elit. Morbi quis orci molestie elit scelerisque cursus.';
    
    // 2. Pilih model ChatGPT (gpt-4o-mini sangat cepat, murah, dan pintar)
    $model = 'gpt-4o-mini'; 
    
    // 3. Endpoint Resmi OpenAI
    $apiUrl = "https://api.openai.com/v1/chat/completions";

    // 4. Payload dengan System Prompt yang MEMAKSA Bahasa Indonesia
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Anda adalah asisten AI ahli manajemen proyek konstruksi di Indonesia. ATURAN WAJIB: 1) Berikan SELURUH respons dalam BAHASA INDONESIA yang baik dan benar. 2) Jangan gunakan bahasa Mandarin atau Inggris. 3) Selalu berikan respons dalam format JSON yang valid tanpa teks pembuka/penutup di luar JSON.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        // Memaksa model untuk mengeluarkan output JSON murni
        'response_format' => [
            'type' => 'json_object'
        ],
        'temperature' => 0.3 // Rendah agar hasil analisis lebih konsisten dan faktual
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // 5. Handle Error
    if ($httpCode !== 200) {
        $errorMsg = $curlError ? $curlError : $response;
        throw new Exception("Gagal terhubung ke OpenAI API (HTTP {$httpCode}): {$errorMsg}");
    }

    // 6. Parse Respons
    $apiResult = json_decode($response, true);
    
    if (isset($apiResult['choices'][0]['message']['content'])) {
        $raw = $apiResult['choices'][0]['message']['content'];
        // Bersihkan tag markdown ```json ... ``` jika model tetap mengeluarkannya
        return trim(str_replace(['```json', '```'], '', $raw));
    }

    // Fallback jika struktur respons berubah atau ada error dari sisi OpenAI
    $errMsg = $apiResult['error']['message'] ?? 'Format respons tidak dikenali';
    throw new Exception("Error dari OpenAI API: {$errMsg}");
}