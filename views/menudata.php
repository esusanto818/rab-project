<?php
if (!isset($_SESSION)) { session_start(); }
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    exit('Sesi berakhir. Silakan login kembali.');
}
require_once __DIR__ . '/../php-class/crudClass.php';
if (function_exists('app_require_active_user')) { app_require_active_user(); }

// Instantiate the PDO crud class
$db = new crud();

$menu='';
$user_id=$_SESSION["user_id"];
$href_logout='../php/logout.php';


function toRoman($num) {
    $map = [
        'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
        'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
        'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1
    ];
    $romans = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV'];
    $num = intval($num);
    return isset($romans[$num]) ? $romans[$num] : $num;

    $res = '';
    foreach ($map as $roman => $value) {
        while ($num >= $value) {
            $res .= $roman;
            $num -= $value;
        }
    }
    return $res;
}

// 1. Fetch main menu groups using PDO
$hsql = "
  select name,seq
  from m_form_group
  where active = true and substring(seq,3,2)='00'
    and substring(seq,1,2) in (
      select distinct substring(g.seq,1,2) seqn
      from m_form_group g
      left join m_form_name f on g.id=f.group_id
      left join m_user_access_menu u on u.form_id=f.id
      where g.active =true and u.user_id = :user_id
      group by substring(g.seq,1,2)
      order by substring(g.seq,1,2)
  )
  order by seq::int
";
$stmt = $db->prepare($hsql);
$stmt->execute(['user_id' => $user_id]);
$hrows_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hrows = count($hrows_data);
$menuWidth = ($hrows + 1);
$i=0;
if ($hrows > 0) {
  foreach ($hrows_data as $hobjid) {
    $i++;
    $romanNum = toRoman($i);
    $displayName = $romanNum . '. ' . $hobjid['name'];
    $menu.='<li><a href="#">'.$displayName.'</a>';
    $menu.='    <div>';

    // 2. Fetch child menu groups
    $csql = "
        select id,name,seq
        from m_form_group
        where active = true and substring(seq,3,2)!='00' and substring(seq,1,2) = :sub_seq
          and id in (
            select distinct g.id
            from m_form_group g
            left join m_form_name f on g.id=f.group_id
            left join m_user_access_menu u on u.form_id=f.id
            where g.active = true and f.active = true and u.user_id = :user_id
        )
        order by seq::int
    ";
    $cStmt = $db->prepare($csql);
    $cStmt->execute([
        'sub_seq' => substr($hobjid["seq"], 0, 2),
        'user_id' => $user_id
    ]);
    $crows_data = $cStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($crows_data) > 0) {
      foreach ($crows_data as $cobjid) {
        $menu.='        <div class="nav-column">';
        if (strtolower($cobjid["name"])=='absence') {
            $menu.='<h3 class="orange">'.$cobjid["name"].'</h3>';
        } else {
            $menu.='<h3>'.$cobjid["name"].'</h3>';
        }

        // Get max row count for alignment padding
        $cRowsql = "
            select count(f.*) as rowcount
            from m_form_name f
            left join m_user_access_menu u on u.form_id=f.id
            where f.active= true and u.user_id = :user_id and f.group_id = :group_id
            group by group_id
            order by count(f.*) DESC
            limit 1
        ";
        $cRowStmt = $db->prepare($cRowsql);
        $cRowStmt->execute(['user_id' => $user_id, 'group_id' => $cobjid["id"]]);
        $rowCount = 0;
        if ($rowObj = $cRowStmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount = $rowObj["rowcount"];
        }

        // 3. Fetch submenu items
        $fsql = "
            select f.id,f.group_id,f.seq,f.title,f.php_url
            from m_form_name f
            left join m_user_access_menu u on u.form_id=f.id
            where f.active= true and group_id = :group_id and u.id is not null and u.user_id = :user_id
            order by group_id,seq::int
        ";
        $fStmt = $db->prepare($fsql);
        $fStmt->execute(['group_id' => $cobjid["id"], 'user_id' => $user_id]);
        $frows_data = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        $frows = count($frows_data);

        if ($frows > 0) {
          $menu.='<ul class="submenu">';
          foreach ($frows_data as $fobjid) {
            $dynamicUrl = "router?id=" . $fobjid["id"];
            $menu .= '<li><a href="' . $dynamicUrl . '" target="_blank">' . $fobjid["title"] . '</a></li>';
            //$targetUrl = !empty($fobjid["php_url"]) ? $fobjid["php_url"] : "#";
          //$menu .= '<li><a href="' . $targetUrl . '" target="_blank">' . $fobjid["title"] . '</a></li>';
          }
          if ($frows < $rowCount) {
              for ($x = $frows; $x < $rowCount; $x++) {
                  $menu.='<li><a class="noHover" target="_blank">&nbsp</a></li>';
              }
          }
          $menu.='</ul>';
        }
        $menu.='</div>';
      }
    }
    $menu.='</div>';
    $menu.='</li>';
  }
}
$menu.='<li><a id="LogoutButton" href="../php/logout.php"><i class="material-icons md-30 white vertical-align-middle padding-bottom-12">exit_to_app</i><div class="logoutText">Logout</div></a></li>';
echo $menu;
?>
