<?php
require_once __DIR__ . '/../php-func/user.valid.php';
if ((int)($_SESSION['FormAccess'] ?? 0) < 2) {
    http_response_code(403);
    exit('Hak buat RAB (level 2) diperlukan.');
}

require_once __DIR__ . '/../php-class/crudClass.php';
if (!isset($_SESSION)) { session_start(); }
$db = new crud();

// Fetch Project & Material Lists
$projects = $db->prepare("SELECT id, project_name FROM m_projects ORDER BY project_name");
$projects->execute();
$materials = $db->prepare("SELECT id, material_name, unit, estimated_price FROM m_materials WHERE active IS TRUE");
$materials->execute();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Input RAB Material</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" type="text/css" href="../css/menu.css" />
</head>
<body class="bg-slate-900 text-slate-100 p-6">
    
    <div id="top" class="mb-6"><?php include 'menudata.php'; ?></div>

    <div class="max-w-2xl mx-auto bg-slate-800 p-8 rounded-xl border border-slate-700 shadow-xl">
        <h1 class="text-xl font-bold text-white mb-6 border-b border-slate-700 pb-3">Entry Budget RAB Material Proyek</h1>
        
        <form action="../php-crud/rabCRD.php" method="POST" class="space-y-4">
            <input type="hidden" name="task" value="create">

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Pilih Proyek</label>
                <select name="project_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:border-sky-500">
                    <option value="">-- Pilih Proyek --</option>
                    <?php while ($p = $projects->fetch(PDO::FETCH_ASSOC)): ?>
                        <option value="<?= $p['id']; ?>"><?= htmlspecialchars($p['project_name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Pilih Item Material</label>
                <select name="material_id" required class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:border-sky-500">
                    <option value="">-- Pilih Material --</option>
                    <?php while ($m = $materials->fetch(PDO::FETCH_ASSOC)): ?>
                        <option value="<?= $m['id']; ?>"><?= htmlspecialchars($m['material_name']); ?> (<?= $m['unit']; ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Volume Budget (RAB)</label>
                    <input type="number" step="0.01" name="budget_qty" required placeholder="0.00" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Harga Satuan (Rp)</label>
                    <input type="number" step="0.01" name="unit_price" required placeholder="0.00" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:border-sky-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Toleransi Overbudget (%)</label>
                <input type="number" step="0.1" name="tolerance_pct" value="5.0" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm focus:border-sky-500">
            </div>

            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-semibold py-3 rounded-lg transition mt-4">
                Simpan RAB Material
            </button>
        </form>
    </div>
</body>
</html>