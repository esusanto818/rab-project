<?php
if (!isset($_SESSION)) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$wwwdoc = $_SERVER["DOCUMENT_ROOT"];

if (file_exists("../php-func/function.all.php")) {
    include_once "../php-func/function.all.php";
}

if (!class_exists('crud')) {

  class crud extends PDO {
    private $engine;
    private $host;
    private $database;
    private $user;
    private $pass;
    private $result;

    public $status;
    public $success;
    public $message;

    public function __construct() {
      $this->engine   = 'pgsql';
      $this->host     = "127.0.0.1";
      $this->database = "proyekGMN";
      $this->user     = "postgres";
      $this->pass     = ""; // Sesuaikan password database Anda

      $dns = $this->engine . ':dbname=' . $this->database . ";port=5432;host=" . $this->host;

      try {
          parent::__construct($dns, $this->user, $this->pass);
          $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

          // PERBAIKAN WARNING: Gunakan SET (Session level) alih-alih SET LOCAL (Transaction level)
          $appUser = $_SESSION['login_name'] ?? $_SESSION['username'] ?? 'system';
          $this->exec("SET app.username = " . $this->quote($appUser));

// AUDIT O-B: Identitas koneksi database aplikasi
$stmt = $this->query("
    SELECT
        current_database() AS database_name,
        session_user AS login_role,
        current_user AS effective_role,
        current_setting('application_name') AS application_name
");

$auditIdentity = $stmt->fetch(PDO::FETCH_ASSOC);

error_log(
    'AUDIT DATABASE IDENTITY: ' .
    json_encode($auditIdentity)
);

      } catch (PDOException $e) {
          echo "Koneksi Gagal: " . $e->getMessage();
          exit;
      }
    }

    /**
     * Menjalankan kueri SELECT
     *
     * PERBAIKAN: sebelumnya method ini menangkap PDOException sendiri dan
     * hanya `return false`, sehingga kegagalan query (mis. kolom tidak ada,
     * tabel salah nama, dsb) jadi SENYAP kalau pemanggil tidak mengecek
     * return value-nya. Padahal koneksi PDO ini sudah di-set
     * PDO::ERRMODE_EXCEPTION di constructor -- niatnya memang supaya error
     * langsung ketahuan. Sekarang exception dilempar ulang (rethrow) setelah
     * detailnya tetap disimpan ke $this->result/getResult() untuk kompatibilitas
     * kode lama yang membaca itu.
     */
    public function setAppUser($username = null) {
        if (!$username) {
            $username = $_SESSION['login_name'] ?? $_SESSION['username'] ?? 'system';
        }
        // Menggunakan PDO exec / prepare
        $stmt = $this->prepare("SELECT set_config('app.username', :user, true)");
        $stmt->execute(['user' => $username]);
    }
    public function select($table, $rows = "*", $where = null, $order = null, $limit = null, $offset = null, $group = null) {
      try {
        $q = "SELECT " . $rows . " FROM " . $table;

        if ($where != null) { $q .= " WHERE " . $where; }
        if ($group != null) { $q .= " GROUP BY " . $group; }
        if ($order != null) { $q .= " ORDER BY " . $order; }
        if ($limit != null) { $q .= " LIMIT " . intval($limit); }
        if ($offset != null) { $q .= " OFFSET " . intval($offset); }

        $query = $this->prepare($q);
        $query->execute();

        $data = $query->fetchAll(PDO::FETCH_ASSOC);

        $output = array(
            "success"    => "true",
            "status"     => "Success",
            "totalCount" => count($data),
            "data"       => $data
        );

        $this->result = json_encode($output);
        return true;

      } catch (PDOException $e) {
        $errorResult = array(
            "success" => "false",
            "status"  => "Error",
            "message" => "Query Select Gagal: " . $e->getMessage()
        );
        $this->result = json_encode($errorResult);
        throw $e; // <-- rethrow, jangan ditelan
      }
    }

    /**
     * Menjalankan kueri INSERT
     * (lihat catatan PERBAIKAN di select() -- sama: rethrow, bukan return false)
     */
    public function insert($table, $data) {
      try {
        $keys = array_keys($data);
        $fields = implode(", ", $keys);
        $placeholders = ":" . implode(", :", $keys);

        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";

        $stmt = $this->prepare($sql);
        $stmt->execute($data);

        $result["success"] = "true";
        $result["status"]  = "Success";
        $result["message"] = "Data berhasil ditambahkan.";
        $this->result      = json_encode($result);
        return true;
      } catch (PDOException $e) {
        $errorResult = array(
            "success" => "false",
            "status"  => "Error",
            "message" => "Query Insert Gagal (" . $table . "): " . $e->getMessage()
        );
        $this->result = json_encode($errorResult);
        throw $e; // <-- rethrow, jangan ditelan
      }
    }

    /**
     * Menjalankan kueri UPDATE secara presisi
     * (lihat catatan PERBAIKAN di select() -- sama: rethrow, bukan return false)
     */
    public function update($table, $data, $where) {
      try {
        $fields = "";
        $params = [];

        foreach ($data as $key => $value) {
            $fields .= "{$key} = :{$key}, ";

            // PERBAIKAN BOOLEAN & NULL: Penanganan eksplisit tipe data PDO
            if (is_bool($value)) {
                $params[":{$key}"] = $value ? 'true' : 'false';
            } else {
                $params[":{$key}"] = $value;
            }
        }
        $fields = rtrim($fields, ', ');

        $sql = "UPDATE {$table} SET {$fields} WHERE {$where}";

        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        $result["success"] = "true";
        $result["status"]  = "Success";
        $result["message"] = "Data berhasil diperbarui.";
        $this->result      = json_encode($result);
        return true;
      } catch (PDOException $e) {
        $errorResult = array(
            "success" => "false",
            "status"  => "Error",
            "message" => "Query Update Gagal (" . $table . "): " . $e->getMessage()
        );
        $this->result = json_encode($errorResult);
        throw $e; // <-- rethrow, jangan ditelan
      }
    }

    /**
     * Menjalankan kueri DELETE
     * (lihat catatan PERBAIKAN di select() -- sama: rethrow, bukan return false)
     */
    public function delete($table, $where) {
      try {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        $stmt = $this->prepare($sql);
        $stmt->execute();

        $result["success"] = "true";
        $result["status"]  = "Success";
        $result["message"] = "Data berhasil dihapus.";
        $this->result      = json_encode($result);
        return true;
      } catch (PDOException $e) {
        $errorResult = array(
            "success" => "false",
            "status"  => "Error",
            "message" => "Query Delete Gagal (" . $table . "): " . $e->getMessage()
        );
        $this->result = json_encode($errorResult);
        throw $e; // <-- rethrow, jangan ditelan
      }
    }

    /**
     * Menjalankan perintah SQL bebas (DDL/DML tanpa binding)
     *
     * cmdQ() TIDAK di-rethrow seperti method di atas, karena beberapa
     * pemanggil lama sengaja memakainya untuk perintah yang boleh gagal
     * tanpa menghentikan alur (mis. DROP TABLE IF EXISTS). Kalau Anda mau
     * cmdQ() juga melempar exception saat gagal, ganti baris
     * `return $this->result;` di blok catch supaya jadi `throw $e;`.
     */
    public function cmdQ($sqlString) {
      try {
          $queryResource = $this->exec($sqlString);
          if ($queryResource !== false) {
              $this->status = "Success";
              $this->success = "true";
              $this->message = "Perintah SQL berhasil dieksekusi.";
          } else {
              $this->status = "Error";
              $this->success = "false";
              $this->message = "Query Gagal dieksekusi.";
          }
      } catch (PDOException $e) {
          $this->status = "Error";
          $this->success = "false";
          $this->message = "Exception: " . $e->getMessage();
      }

      $this->result = json_encode(array(
          "success" => $this->success ?? "false",
          "status"  => $this->status ?? "Error",
          "message" => $this->message ?? "Unknown error"
      ));
      return $this->result;
    }

    public function getResult() {
        return $this->result;
    }
  }
}
?>