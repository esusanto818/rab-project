<?php
session_start();
include_once "../php-class/crudClass.php";

$offset=($_POST["start"]==null)? 0 : $_POST["start"];

$task = $_POST['task'] ?? 'read';
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak baca status persediaan.']);
    exit;
}

switch ($task){
	case ('read'):
    $sResult=searchData($_POST["query"],array("item_name","size","type_group","grade","wood"),250);
    $limit=$sResult["limit"];
    $searchField=$sResult["searchSQL"];
		$db = new crud();
		$field  = "*";
		$table = "
      (
        select type_group,item_id,
          item_name||case when length(btrim(coalesce(size,'')))>0 then ' - '||coalesce(size,'') else '' end as item_name,
          size,um_unit,um_packing,grade,coalesce(grade_id,0) grade_id,wood,coalesce(wood_type_id,0) wood_type_id,
          sum(coalesce(balance_qty,0)) balance_qty,sum(coalesce(balance_packing,0)) balance_packing,
          sum(coalesce(balance_volume,0)) balance_volume
        from
          (
            select i.type_group,b.item_id,i.item_name,i.size,i.um_unit,i.um_packing,
              b.balance_qty,b.balance_packing,
              case when lower(btrim(i.location_name)) like 'unit%5' and lower(btrim(i.type_group)) like '%finish%' then 0 else b.balance_volume end as balance_volume,
              g.name grade,g.id grade_id, w.name wood, w.id wood_type_id
            from wh_item_proyek_balance b
            left join mat_view_items i on coalesce(b.item_id,0)=coalesce(i.item_id,0)
            left join item_grade g on coalesce(b.grade_id,0)=coalesce(g.id,0)
            left join m_wood_type w on coalesce(b.wood_type_id,0)=coalesce(w.id,0)
            where coalesce(b.item_id,0)>0
            order by i.type_group,i.item_name,i.size,g.name
          ) d
        group by type_group,item_id,item_name,size,um_unit,um_packing,grade,coalesce(grade_id,0),wood,coalesce(wood_type_id,0)
     )dt
    ";
    //(balance_qty<>0 or balance_packing<>0 or balance_volume<>0) and 
    $where = "coalesce(item_id,0)>0".$searchField;
    $order = "(case when balance_qty >0 or balance_packing >0 or balance_volume>0 then 1 else 0 end) DESC, 
      type_group,item_name,size,grade,balance_qty DESC, balance_packing DESC, balance_volume DESC";
		$db->select('data',$table,$field,$where,$order,$limit, $offset);
		$result = $db->getResult();
		echo $result;
	break;
  case ('readDetail'):
    $db = new crud();
    $field  = "*";
    $table = "
      (
        select type_group,item_id,
          item_name||case when length(btrim(coalesce(size,'')))>0 then ' - '||coalesce(size,'') else '' end as item_name,
          size,um_unit,um_packing,grade,id_no,warehouse,grade_id,wood,coalesce(wood_type_id,0) wood_type_id,
          sum(coalesce(balance_qty,0)) balance_qty,sum(coalesce(balance_packing,0)) balance_packing,
          sum(coalesce(balance_volume,0)) balance_volume
        from 
          (
            select i.type_group,b.item_id,i.item_name,i.size,i.um_unit,i.um_packing,
              b.id_no,b.balance_qty,b.balance_packing,b.balance_volume,g.name grade,g.id grade_id,wo.name wood,wo.id wood_type_id,
              w.location_name||' ~ '||w.warehouse_name warehouse
            from wh_item_proyek_balance b
            left join mat_view_items i on coalesce(b.item_id,0)=coalesce(i.item_id,0)
            left join item_grade g on coalesce(b.grade_id,0)=coalesce(g.id,0)
            left join m_wood_type wo on coalesce(b.wood_type_id,0)=coalesce(wo.id,0)
            left join view_warehouse_name_init w on w.warehouse_id=b.warehouse_id
            where coalesce(b.item_id,0)=".$_POST['item_id']." 
              and coalesce(b.grade_id,0)=".$_POST['grade_id']." 
              and coalesce(b.wood_type_id,0)=".$_POST['wood_id']."
            order by i.type_group,i.item_name,i.size,g.name,w.location_name||' ~ '||w.warehouse_name
          ) d
        group by type_group,item_id,item_name,size,um_unit,um_packing,grade,grade_id,id_no,warehouse,wood,coalesce(wood_type_id,0)
     )dt
    ";
    $where = "(balance_qty<>0 or balance_packing<>0 or balance_volume<>0) 
      and item_id=".$_POST['item_id']." and coalesce(grade_id,0)=coalesce(".$_POST['grade_id'].",0) 
      and coalesce(wood_type_id,0)=coalesce(".$_POST['wood_id'].",0)";
    $order = "warehouse, coalesce(id_no,'')";
    $db->select('data',$table,$field,$where,$order,$limit, $offset);
    $result = $db->getResult();
    echo $result;
  break;
}
?>
