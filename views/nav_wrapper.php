<?php
if (!isset($_SESSION)) { session_start(); }
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    exit('Sesi berakhir. Silakan login kembali.');
}
require_once __DIR__ . '/../php-class/crudClass.php';

$db = new crud();
$user_id = $_SESSION["user_id"] ?? 0;

function toRoman($num) {
    $romans = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV'];
    $num = intval($num);
    return isset($romans[$num]) ? $romans[$num] : $num;
}

// Fetch Main Menu Groups
$hsql = "
  select id, name, seq
  from m_form_group
  where active = true and substring(seq,3,2)='00'
    and substring(seq,1,2) in (
      select distinct substring(g.seq,1,2) seqn
      from m_form_group g
      left join m_form_name f on g.id=f.group_id
      left join m_user_access_menu u on u.form_id=f.id
      where g.active = true and u.user_id = :user_id
      group by substring(g.seq,1,2)
  )
  order by seq::int
";
$stmt = $db->prepare($hsql);
$stmt->execute(['user_id' => $user_id]);
$mainMenus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation Menu</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        tech: ['Rajdhani', 'sans-serif'],
                        header: ['Orbitron', 'sans-serif'],
                        body: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        /* 1. MATIKAN SEMUA SCROLLBAR SECARA MUTLAK */
        html, body {
            background-color: transparent !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        ::-webkit-scrollbar {
            display: none !important;
            width: 0px !important;
            height: 0px !important;
        }

        * {
            -ms-overflow-style: none !important;  /* IE & Edge */
            scrollbar-width: none !important;  /* Firefox */
        }

        /* 2. HOVER DROPDOWN ANIMATION */
        .nav-item:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-menu {
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: all 0.2s ease-in-out;
        }
    </style>
</head>
<body class="font-body text-slate-200 antialiased select-none">

    <nav class="relative z-50 bg-[#060a12] border border-slate-800 rounded-xl shadow-2xl">
        <div class="flex items-center justify-between h-12 px-4">
            
            <!-- MAIN MENU LIST -->
            <ul class="flex items-center space-x-1 sm:space-x-3 lg:space-x-6 font-header text-xs lg:text-sm tracking-wider font-semibold">
                
                <?php 
                $i = 0;
                foreach ($mainMenus as $main): 
                    $i++;
                    $romanNum = toRoman($i);
                    $mainSeq = substr($main["seq"], 0, 2);

                    // Fetch Child Groups
                    $csql = "
                        select id, name, seq
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
                    $cStmt->execute(['sub_seq' => $mainSeq, 'user_id' => $user_id]);
                    $childGroups = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $hasChildren = count($childGroups) > 0;
                ?>

                <li class="nav-item group relative">
                    <a href="#" class="py-3 px-2 text-slate-300 hover:text-amber-400 group-hover:text-amber-400 group-hover:border-b-2 group-hover:border-amber-400 flex items-center gap-1 transition-all">
                        <?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
                    </a>

                    <?php if ($hasChildren): ?>
                    <!-- DROPDOWN CONTAINER OVERLAY -->
                    <div class="dropdown-menu absolute left-0 top-full mt-2 w-[85vw] max-w-6xl bg-[#0c1322]/95 backdrop-blur-md border border-slate-700/80 rounded-xl shadow-2xl p-5 text-slate-300">
                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-6">
                            
                            <?php foreach ($childGroups as $child): 
                                $fsql = "
                                    select f.id, f.title, f.php_url
                                    from m_form_name f
                                    left join m_user_access_menu u on u.form_id=f.id
                                    where f.active = true and group_id = :group_id and u.id is not null and u.user_id = :user_id
                                    order by seq::int
                                ";
                                $fStmt = $db->prepare($fsql);
                                $fStmt->execute(['group_id' => $child["id"], 'user_id' => $user_id]);
                                $formItems = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            ?>
                            
                            <div class="space-y-2">
                                <h3 class="font-tech text-amber-400 font-bold text-sm tracking-widest uppercase border-b border-slate-700/60 pb-1">
                                    <?= htmlspecialchars($child['name']); ?>
                                </h3>
                                <ul class="space-y-1 font-tech text-sm text-slate-300">
                                    <?php foreach ($formItems as $item): ?>
                                        <li>
                                            <a href="router?id=<?= $item['id']; ?>" target="_blank" class="hover:text-amber-300 hover:translate-x-1 inline-block transition-all py-0.5">
                                                <?= htmlspecialchars($item['title']); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <?php endforeach; ?>

                        </div>
                    </div>
                    <?php endif; ?>
                </li>

                <?php endforeach; ?>

            </ul>

            <!-- LOGOUT BUTTON -->
            <a href="../php/logout.php" target="_top" class="h-9 bg-rose-600 hover:bg-rose-700 text-white font-header text-xs tracking-widest px-4 rounded-lg flex items-center justify-center gap-1.5 transition-colors shadow-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Logout</span>
            </a>

        </div>
    </nav>

</body>
</html>