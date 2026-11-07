<?php
session_start();
include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$searchField = isset($_POST["query"]) ? $_POST["query"] : NULL;
$offset      = (isset($_POST["start"]) && $_POST["start"] !== null) ? intval($_POST["start"]) : 0;
$limit       = (isset($_POST["limit"]) && $_POST["limit"] !== null) ? intval($_POST["limit"]) : 250;
$task        = isset($_POST['task']) ? $_POST['task'] : 'read';

$db = new crud();

switch ($task) {
	case ("create"):
	case ("add"):
		$tabel = "m_currency";
		$data = array(
			"init" => checksinglequote($_POST["init"] ?? ''),
			"name" => checksinglequote($_POST["name"] ?? '')
		);
		$db->insert($tabel, $data);
		echo $db->getResult();
	break;

	case ('read'):
		$sResult   = searchData($searchField, array("name", "init"), 250);
		$limit     = $sResult["limit"];
		$searchSQL = $sResult["searchSQL"];

		$fields = "id, init, name";
		$table  = "m_currency";
		$where  = "1=1 " . $searchSQL;
		$order  = "name ASC";

		$db->select($table, $fields, $where, $order, $limit, $offset);
		echo $db->getResult();
	break;

	case ("read_edit"):
		$id     = intval($_POST['id'] ?? 0);
		$fields = "id, init, name";
		$table  = "m_currency";
		$where  = "id = " . $id;

		$db->select($table, $fields, $where);
		echo $db->getResult();
	break;

	case ('HistoryData'):
	case ('read_history'):
		$id      = intval($_POST['id'] ?? 0);
		$table   = "view_single_table_log";
		$fields  = "
			tx_id, date, count,
			case amend_name
				when 'init' then '[EDIT] - Init'
				when 'name' then '[EDIT] - Name'
				else amend_name
			end ::character varying(200) AS amend_name,
			before_amendment, after_amendment, user_name, user_time
		";
		$where   = "tx_id::int = " . $id . " and table_name = 'm_currency'";
		$orderBy = "count desc";

		$db->select($table, $fields, $where, $orderBy);
		echo $db->getResult();
	break;

	case ('update'):
		$id = intval($_POST['id'] ?? 0);
		if ($id > 0) {
			$tabel = "m_currency";
			$data = array(
				"init"   => checksinglequote($_POST["init"] ?? ''),
				"name"   => checksinglequote($_POST["name"] ?? ''),
			);
			$where = "id = " . $id;
			$db->update($tabel, $data, $where);
			echo $db->getResult();
		} else {
			echo json_encode(["success" => "false", "status" => "Error", "message" => "ID tidak valid untuk proses update."]);
		}
	break;

	case ('delete'):
		$id = intval($_POST['id'] ?? 0);
		if ($id > 0) {
			$tabel = "m_currency";
			$where = "id = " . $id;
			$db->delete($tabel, $where);
			echo $db->getResult();
		} else {
			echo json_encode(["success" => "false", "status" => "Error", "message" => "ID tidak valid untuk proses penghapusan."]);
		}
	break;

	default:
		echo json_encode(["success" => "false", "status" => "Error", "message" => "Task tidak dikenali."]);
	break;
}
?>