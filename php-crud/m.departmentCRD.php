<?php
// add w/h id for direct update univ from production
// alter table department_section add column warehouse_id bigint

session_start();
include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$searchField       = isset($_POST["query"]) ? $_POST["query"] : NULL;
$offset            = (isset($_POST["start"]) && $_POST["start"] !== null) ? intval($_POST["start"]) : 0;
$limit             = (isset($_POST["limit"]) && $_POST["limit"] !== null) ? intval($_POST["limit"]) : 250;
$departmentIdParam = isset($_POST["department_id"]) ? intval($_POST["department_id"]) : 0;
$task              = isset($_POST['task']) ? $_POST['task'] : 'read';

// Inisialisasi objek database secara global
$db = new crud();

switch ($task) {
	case ("create"):
	case ("add"):
		$queryC = '';
		$rawSection = $_POST["arrSection"] ?? '[]';
		$dataSec = !empty($rawSection) ? json_decode($rawSection) : [];

		$table = "m_department";
		$data = array(
			"name"        => checksinglequote($_POST["name"] ?? ''),
			"seq_no"      => intval($_POST["seq_no"] ?? 0),
			"init"        => checksinglequote($_POST["init"] ?? ''),
			"remark"      => checksinglequote($_POST["remark"] ?? ''),
			"location_id" => !empty($_POST["location_id"]) ? intval($_POST["location_id"]) : null,
			"active"      => true
		);
		$queryC .= insertQ($table, $data) . ";";

		if (is_array($dataSec)) {
			foreach ($dataSec as $sec) {
				$table = "department_section";
				$data = array(
					"department_id" => "currval('m_department_id_seq')",
					"seq_no"        => intval($sec->seq_no ?? 0),
					"line_no"       => intval($sec->line_no ?? 0),
					"section_name"  => checksinglequote($sec->section_name ?? ''),
					"data_output"   => !empty($sec->data_output) ? intval($sec->data_output) : null,
					"warehouse_id"  => !empty($sec->warehouse_id) ? intval($sec->warehouse_id) : null,
					"piece_rate"    => floatval(str_replace(",", "", $sec->piece_rate ?? 0)),
					"share_amt"     => floatval(str_replace(",", "", $sec->share_amt ?? 0)),
					"operator"      => !empty($sec->operator) ? intval($sec->operator) : null,
					"remark"        => checksinglequote($sec->remark ?? '')
				);
				$queryC .= insertQ($table, $data) . ";";
			}
		}

		$db->cmdQ($queryC);
		echo $db->getResult();
	break;

	case ('read'):
        $sResult   = searchData($searchField, array("dt.name", "dt.init"), 250);
        $limit     = $sResult["limit"];
        $searchSQL = $sResult["searchSQL"];

        // PASTIKAN $fields berupa string murni, BUKAN array [...]
        $fields = "dt.id, dt.seq_no, dt.name, dt.init, coalesce(dt.remark,'') remark, l.id location_id, l.name location_name, l.init location_init, dt.active";
        
        $table  = "m_department dt left join m_location l on coalesce(dt.location_id,0)=coalesce(l.id,0)";
        $where  = "dt.active = true " . $searchSQL;
        $order  = "l.name ASC, coalesce(dt.seq_no,0) ASC, dt.name ASC";

        // Pastikan pemanggilan parameter berurutan dengan benar: ($table, $fields, $where, $order, $limit, $offset)
        // Catatan: Sesuaikan urutan parameter ini dengan struktur asli method select() di crudClass.php Anda
        $db->select($table, $fields, $where, $order, $limit, $offset);
        echo $db->getResult();
	break;
	case ("read_edit"):
		$id     = intval($_POST['id'] ?? 0);
		$fields = "id, name, seq_no, init, remark, location_id, active";
		$table  = "m_department";
		$where  = "id = " . $id;

		$db->select($table, $fields, $where);
		echo $db->getResult();
	break;

	case ('read_detail'):
		$fields = "
			s.id, s.department_id, s.seq_no, s.section_name, s.line_no, s.data_output, s.warehouse_id, s.piece_rate,
			s.operator, s.share_amt, s.remark, w.warehouse_name warehouse
		";
		$table  = "
			department_section s
			left join view_warehouse_name_init w on s.warehouse_id = w.warehouse_id
		";
		$where  = "s.department_id = " . $departmentIdParam;
		$order  = "s.seq_no ASC";

		$db->select($table, $fields, $where, $order);
		echo $db->getResult();
	break;

	case ('HistoryData'):
	case ('read_history'):
		$id     = intval($_POST['id'] ?? 0);
		$table  = "view_single_table_log";
		$fields = "
			tx_id, date, count,
			case amend_name
				when 'init' then '[EDIT] - Initial'
				when 'seq_no' then '[EDIT] - Seq No.'
				when 'name' then '[EDIT] - Name'
				when 'remark' then '[EDIT] - Remark'
				when 'location_id' then '[EDIT] - Location'
				else amend_name
			end ::character varying(200) AS amend_name,
			before_amendment, after_amendment, user_name, user_time
		";
		$where  = "tx_id::int = " . $id . " and table_name = 'm_department'";
		$order  = "count desc";

		$db->select($table, $fields, $where, $order);
		echo $db->getResult();
	break;

	case ('update'):
		$queryC = '';
		$deptId = intval($_POST['id'] ?? 0);
		$rawSection = $_POST["arrSection"] ?? '[]';
		$dataSec = !empty($rawSection) ? json_decode($rawSection) : [];

		// Cek section lama menggunakan method select modular kelas crud
		$checkFields = "id, department_id";
		$checkTable  = "department_section";
		$checkWhere  = "department_id = " . $deptId;
		$db->select($checkTable, $checkFields, $checkWhere, "id ASC");
		$resCheckJSON = $db->getResult();
		$resCheckArr  = json_decode($resCheckJSON, true);
		$dataCheck    = $resCheckArr["data"] ?? [];

		for ($c = 0; $c < count($dataCheck); $c++) {
			$dataFound = 0;
			$cId = $dataCheck[$c]['id'];
			for ($a = 0; $a < count($dataSec); $a++) {
				if (isset($dataSec[$a]->id) && $dataSec[$a]->id == $cId) {
					$dataFound = 1;
					break;
				}
			}
			if ($dataFound == 0) {
				$queryC .= deleteQ("department_section", "id = " . intval($cId)) . ";";
			}
		}

		$table = "m_department";
		$data = array(
			"name"        => checksinglequote($_POST["name"] ?? ''),
			"seq_no"      => intval($_POST["seq_no"] ?? 0),
			"init"        => checksinglequote($_POST["init"] ?? ''),
			"remark"      => checksinglequote($_POST["remark"] ?? ''),
			"location_id" => !empty($_POST["location_id"]) ? intval($_POST["location_id"]) : null,
			"active"      => $_POST['i_active'] ?? $_POST["active"] ?? 'true'
		);
		$where = "id = " . $deptId;
		$queryC .= updateQ($table, $data, $where) . ";";

		if (is_array($dataSec)) {
			foreach ($dataSec as $sec) {
				$secId = intval($sec->id ?? 0);
				$secData = array(
					"seq_no"       => intval($sec->seq_no ?? 0),
					"line_no"      => intval($sec->line_no ?? 0),
					"section_name" => checksinglequote($sec->section_name ?? ''),
					"data_output"  => !empty($sec->data_output) ? intval($sec->data_output) : null,
					"warehouse_id" => !empty($sec->warehouse_id) ? intval($sec->warehouse_id) : null,
					"piece_rate"   => floatval(str_replace(",", "", $sec->piece_rate ?? 0)),
					"share_amt"    => floatval(str_replace(",", "", $sec->share_amt ?? 0)),
					"operator"     => !empty($sec->operator) ? intval($sec->operator) : null,
					"remark"       => checksinglequote($sec->remark ?? '')
				);

				if ($secId > 0) {
					$queryC .= updateQ("department_section", $secData, "id = " . $secId) . ";";
				} else {
					$secData["department_id"] = $deptId;
					$queryC .= insertQ("department_section", $secData) . ";";
				}
			}
		}

		$db->cmdQ($queryC);
		echo $db->getResult();
	break;

	case ('delete'):
		$deleteId = intval($_POST["id"] ?? 0);
		if ($deleteId > 0) {
			$table = "m_department";
			$data  = array("active" => false);
			$where = "id = " . $deleteId;

			$queryC = updateQ($table, $data, $where) . ";";
			$db->cmdQ($queryC);
			echo $db->getResult();
		} else {
			echo json_encode(["success" => "false", "status" => "Error", "message" => "ID Department tidak valid."]);
		}
	break;

	default:
		echo json_encode(["success" => "false", "status" => "Error", "message" => "Task tidak dikenali."]);
	break;
}
?>