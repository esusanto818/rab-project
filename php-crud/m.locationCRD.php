<?php
session_start();
include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$searchField = isset($_POST["query"]) ? $_POST["query"] : NULL;
$offset      = (isset($_POST["start"]) && $_POST["start"] !== null) ? intval($_POST["start"]) : 0;
$limit       = (isset($_POST["limit"]) && $_POST["limit"] !== null) ? intval($_POST["limit"]) : 250;
$task        = isset($_POST['task']) ? $_POST['task'] : 'read';

// Inisialisasi objek database secara global
$db = new crud();

switch ($task) {
	case ("create"):
	case ("add"):
		$queryC = '';
		$table = "m_location";
		$data = array(
			"name"   => checksinglequote($_POST["name"] ?? ''),
			"init"   => checksinglequote($_POST["init"] ?? ''),
			"remark" => checksinglequote($_POST["remark"] ?? ''),
		);
		$queryC .= insertQ($table, $data) . ";";

		$days = array("Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday");
		for ($x = 1; $x <= 3; $x++) {
			if (trim($_POST["start_date" . $x] ?? '')) {
				foreach ($days as $value) {
					$day    = strtolower(substr($value, 0, 3));
					$daynum = date("w", strtotime($value));
					$table  = "location_office_hour";
					$data   = array(
						"location_id" => "currval('m_location_id_seq'::regclass)",
						"shift"       => $x,
						"day"         => $daynum,
						"from_time"   => $_POST[$x . $day . "_start"] ?? null,
						"to_time"     => $_POST[$x . $day . "_end"] ?? null,
						"from_date"   => date('Y-m-d', strtotime($_POST["start_date" . $x]))
					);
					$queryC .= insertQ($table, $data) . ";";
				}
			}
		}

		$db->cmdQ($queryC);
		echo $db->getResult();
	break;

	case ('read'):
		$sResult   = searchData($searchField, array("dt.name", "dt.init", "dt.remark"), 250);
		$limit     = $sResult["limit"];
		$searchSQL = $sResult["searchSQL"];

		$fields = "dt.id, dt.name, dt.init, coalesce(dt.remark,'') remark";
		$table  = "m_location dt";
		$where  = "1=1 " . $searchSQL;
		$order  = "name ASC";

		// Memanggil method select modular bawaan crudClass.php
		$db->select($table, $fields, $where, $order, $limit, $offset);
		echo $db->getResult();
	break;

	case ("read_edit"):
		$id    = intval($_POST['id'] ?? 0);
		$table = "m_location";
		$field = "id, name, init, remark";
		$where = "id = " . $id;

		$sql = "SELECT " . $field . " FROM " . $table . " WHERE " . $where;
		$db->select($sql, array());
		echo $db->getResult();
	break;

	case ("read_hour"):
		$locId  = intval($_POST['id'] ?? 0);
		$field  = "o.*";
		$table  = "location_office_hour o";
		$where  = "to_date IS NULL and location_id = " . $locId;
		$order  = "location_id ASC";

		$sql = "SELECT " . $field . " FROM " . $table . " WHERE " . $where . " ORDER BY " . $order;
		$db->select($sql, array());
		echo $db->getResult();
	break;

	case ('HistoryData'):
	case ('read_history'):
		$id    = intval($_POST['id'] ?? 0);
		$table = "view_single_table_log";
		$field = "
			tx_id, date, count,
			case amend_name
				when 'init' then '[EDIT] - Initial'
				when 'name' then '[EDIT] - Name'
				when 'remark' then '[EDIT] - Remark'
				else amend_name
			end ::character varying(200) AS amend_name,
			before_amendment, after_amendment, user_name, user_time
		";
		$where = "tx_id::int = " . $id . " and table_name = 'm_location'";
		$order = "count desc";

		$sql = "SELECT " . $field . " FROM " . $table . " WHERE " . $where . " ORDER BY " . $order;
		$db->select($sql, array());
		echo $db->getResult();
	break;

	case ('update'):
		$queryC = '';
		$locId  = intval($_POST["id"] ?? 0);
		$table  = "m_location";
		$data   = array(
			"name"   => checksinglequote($_POST["name"] ?? ''),
			"init"   => checksinglequote($_POST["init"] ?? ''),
			"remark" => checksinglequote($_POST["remark"] ?? ''),
		);
		$where  = "id = " . $locId;
		$queryC .= updateQ($table, $data, $where) . ";";

		$days = array("Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday");
		for ($x = 1; $x <= 3; $x++) {
			if (trim($_POST["start_date" . $x] ?? '')) {
				$checkFields = "*";
				$checkTable  = "location_office_hour";
				$checkWhere  = "to_date IS NULL and location_id = " . $locId . " and from_date = '" . date('Y-m-d', strtotime($_POST["start_date" . $x])) . "' and shift = " . $x;
				
				$db->select($checkTable, $checkFields, $checkWhere, "id ASC");
				$resCheckJSON = $db->getResult();
				$resCheckArr  = json_decode($resCheckJSON, true);
				$dataCheck    = $resCheckArr["data"] ?? [];

				if (count($dataCheck) > 0) {
					foreach ($days as $value) {
						$day    = strtolower(substr($value, 0, 3));
						$daynum = date("w", strtotime($value));
						$cId    = 0;

						$subFields = "*";
						$subTable  = "location_office_hour";
						$subWhere  = "to_date IS NULL and location_id = " . $locId . " and from_date = '" . date('Y-m-d', strtotime($_POST["start_date" . $x])) . "' and shift = " . $x . " and day = " . $daynum;
						
						$db->select($subTable, $subFields, $subWhere, "id ASC");
						$subJSON = $db->getResult();
						$subArr  = json_decode($subJSON, true);
						$subData = $subArr["data"] ?? [];

						for ($c = 0; $c < count($subData); $c++) {
							$cId = $subData[$c]['id'];
						}

						if ($cId > 0) {
							$table = "location_office_hour";
							$data  = array(
								"from_time" => $_POST[$x . $day . "_start"] ?? null,
								"to_time"   => $_POST[$x . $day . "_end"] ?? null
							);
							$where = "id = " . $cId;
							$queryC .= updateQ($table, $data, $where) . ";";
						} else {
							$table = "location_office_hour";
							$data  = array(
								"location_id" => $locId,
								"shift"       => $x,
								"day"         => $daynum,
								"from_time"   => $_POST[$x . $day . "_start"] ?? null,
								"to_time"     => $_POST[$x . $day . "_end"] ?? null,
								"from_date"   => date('Y-m-d', strtotime($_POST["start_date" . $x]))
							);
							$queryC .= insertQ($table, $data) . ";";
						}
					}
				} else {
					$table = "location_office_hour";
					$data  = array("to_date" => date('Y-m-d', strtotime('-1 day', strtotime($_POST["start_date" . $x]))));
					$where = "to_date IS NULL and location_id = " . $locId . " and shift = " . $x;
					$queryC .= updateQ($table, $data, $where) . ";";

					foreach ($days as $value) {
						$day    = strtolower(substr($value, 0, 3));
						$daynum = date("w", strtotime($value));
						$table  = "location_office_hour";
						$data   = array(
							"location_id" => $locId,
							"shift"       => $x,
							"day"         => $daynum,
							"from_time"   => $_POST[$x . $day . "_start"] ?? null,
							"to_time"     => $_POST[$x . $day . "_end"] ?? null,
							"from_date"   => date('Y-m-d', strtotime($_POST["start_date" . $x]))
						);
						$queryC .= insertQ($table, $data) . ";";
					}
				}
			}
		}

		$db->cmdQ($queryC);
		echo $db->getResult();
	break;

	case ('delete'):
		$deleteId = intval($_POST['id'] ?? 0);
		if ($deleteId > 0) {
			$tabel = "m_location";
			$where = "id = " . $deleteId;
			$db->delete($tabel, $where);
			echo $db->getResult();
		} else {
			echo json_encode(["success" => "false", "status" => "Error", "message" => "ID Location tidak valid."]);
		}
	break;

	default:
		echo json_encode(["success" => "false", "status" => "Error", "message" => "Task tidak dikenali."]);
	break;
}
?>