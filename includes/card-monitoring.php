<?php
/*
Document : includes/card-monitoring.php
Description : Card Monitoring Proyek - Grid 3 Kolom + AI Recommendation Dinamis
              Format: Ringkasan Eksekutif + Rekomendasi Mitigasi
Usage      : <?php include BASE_PATH . "/includes/card-monitoring.php"; ?>
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/..'));
}

if (!isset($db)) {
    require_once __DIR__ . '/../php-class/crudClass.php';
    $db = new crud();
}

// ============================================================
// AMBIL DATA MONITORING PROYEK
// ============================================================
$monitoringData = [];
try {
    $stmt = $db->query("
        SELECT 
            p.id, 
            p.name AS project_name,
            COALESCE(p.progress, 0) AS progress_pct,
            COALESCE(pm.usage_pct, 0) AS usage_pct,
            COALESCE(pm.usage_pct, 0) - COALESCE(p.progress, 0) AS gap,
            CASE 
                WHEN COALESCE(pm.usage_pct, 0) - COALESCE(p.progress, 0) > 20 THEN 'KRITIS'
                WHEN COALESCE(pm.usage_pct, 0) - COALESCE(p.progress, 0) > 10 THEN 'WASPADA'
                ELSE 'NORMAL'
            END AS status_flag,
            COALESCE(p.contract_value, 0) AS contract_value,
            COALESCE(p.budget, 0) AS budget
        FROM projects p
        LEFT JOIN v_project_material_vs_progress pm ON pm.project_id = p.id
        WHERE p.status = 'Berjalan'
        ORDER BY (COALESCE(pm.usage_pct, 0) - COALESCE(p.progress, 0)) DESC
        LIMIT 9
    ");
    $monitoringData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[monitoring-card] Error: ' . $e->getMessage());
    $monitoringData = [];
}

// ============================================================
// HELPER: STATUS STYLE
// ============================================================
if (!function_exists('getMonStatusStyle')) {
    function getMonStatusStyle($status) {
        switch (strtoupper($status)) {
            case 'KRITIS':
                return ['bg' => 'rgba(239, 68, 68, 0.2)', 'border' => '#ef4444', 'text' => '#ef4444', 'dot' => '#ef4444'];
            case 'WASPADA':
                return ['bg' => 'rgba(243, 156, 18, 0.2)', 'border' => '#f39c12', 'text' => '#f39c12', 'dot' => '#f39c12'];
            case 'NORMAL':
            default:
                return ['bg' => 'rgba(16, 185, 129, 0.2)', 'border' => '#10b981', 'text' => '#10b981', 'dot' => '#10b981'];
        }
    }
}

function fmtRpShort($n) {
    $n = (float)$n;
    if ($n >= 1000000000) return 'Rp ' . number_format($n / 1000000000, 1) . 'M';
    if ($n >= 1000000) return 'Rp ' . number_format($n / 1000000, 0) . 'jt';
    return 'Rp ' . number_format($n, 0);
}
?>

<!-- ============================================================ -->
<!-- CSS MONITORING CARD - GRID 3 KOLOM + AI FORMAT BARU -->
<!-- ============================================================ -->


<!-- ============================================================ -->
<!-- HTML: GRID CARD PROYEK -->
<!-- ============================================================ -->
<div class="mon-grid" id="monProjectGrid">
    <?php if (empty($monitoringData)): ?>
        <div class="text-center text-slate-400 py-6 text-sm col-span-full">
            Tidak ada proyek berjalan untuk dimonitor.
        </div>
    <?php else: ?>
        <?php foreach ($monitoringData as $project):
            $style = getMonStatusStyle($project['status_flag']);
            $gapClass = $project['gap'] > 20 ? 'gap-positive' : ($project['gap'] > 10 ? 'gap-warning' : 'gap-normal');
            $progressPct = max(0, min(100, $project['progress_pct']));
        ?>
        <div class="mon-card-grid" 
             style="--status-"
             data-project-id="<?= $project['id'] ?>"
             data-project-name="<?= htmlspecialchars($project['project_name']) ?>"
             onclick="selectMonitoringProject(<?= $project['id'] ?>, '<?= htmlspecialchars($project['project_name']) ?>')">
            
            <!-- Tooltip -->
            <div class="mon-tooltip">
                Budget <?= fmtRpShort($project['contract_value']) ?> | 
                Progres <?= number_format($project['progress_pct'], 0) ?>% | 
                Pemakaian <?= number_format($project['usage_pct'], 0) ?>%
            </div>
            
            <!-- Header -->
            <div class="mon-card-header">
                <div class="mon-card-title">
                    <span>🏗️</span>
                    <span><?= htmlspecialchars($project['project_name']) ?></span>
                </div>
                <div class="mon-badge-grid" >
                    <span class="mon-dot-grid" ></span>
                    <?= htmlspecialchars($project['status_flag']) ?>
                </div>
            </div>
            
            <!-- Metrics -->
            <div class="mon-metrics-grid">
                <div class="mon-metric-grid">
                    <span class="mon-metric-label">Progres:</span>
                    <span class="mon-metric-value progress"><?= number_format($project['progress_pct'], 0) ?>%</span>
                </div>
                <div class="mon-metric-grid">
                    <span class="mon-metric-label">Pemakaian:</span>
                    <span class="mon-metric-value usage"><?= number_format($project['usage_pct'], 0) ?>%</span>
                </div>
                <div class="mon-metric-grid">
                    <span class="mon-metric-label">Gap:</span>
                    <span class="mon-metric-value <?= $gapClass ?>"><?= $project['gap'] >= 0 ? '+' : '' ?><?= number_format($project['gap'], 0) ?></span>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div class="mon-progress-bar">
                <div class="mon-progress-fill" style="width: <?= $progressPct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- AI RECOMMENDATION DYNAMIC (Format Baru) -->
<!-- ============================================================ -->
<div class="mon-ai-dynamic" id="monAiRecommendation">
    <div class="mon-ai-loading">
        🧠 Memuat analisis AI...
    </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT: HANDLE CLICK & FETCH AI -->
<!-- ============================================================ -->
<script>
let selectedProjectId = null;

function selectMonitoringProject(projectId, projectName) {
    // Remove active class from all cards
    document.querySelectorAll('.mon-card-grid').forEach(card => {
        card.classList.remove('active');
    });
    
    // Add active class to selected card
    const selectedCard = document.querySelector(`.mon-card-grid[data-project-id="${projectId}"]`);
    if (selectedCard) {
        selectedCard.classList.add('active');
    }
    
    selectedProjectId = projectId;
    loadAiRecommendation(projectId, projectName);
}

async function loadAiRecommendation(projectId, projectName) {
    const container = document.getElementById('monAiRecommendation');
    container.innerHTML = '<div class="mon-ai-loading">🧠 Memuat analisis AI...</div>';
    
    try {
        const res = await fetch(`../php-crud/dashboardCRD.php?action=read_ai_recommendation&project_id=${projectId}`);
        const json = await res.json();
        
        if (!json.success || !json.data) {
            container.innerHTML = `
                <div class="mon-ai-header">
                    <div class="mon-ai-title">
                        <span>🧠</span>
                        <span>AI Cost & Schedule Variance Analysis</span>
                    </div>
                    <span class="mon-ai-project">${projectName}</span>
                </div>
                <div class="mon-ai-empty">Tidak ada rekomendasi AI untuk proyek ini.</div>
            `;
            return;
        }
        
        const d = json.data;
        const risk = (d.risk_level || 'LOW').toUpperCase();
        const riskClass = 'mon-risk-' + risk.toLowerCase();
        const recs = Array.isArray(d.recommendations) ? d.recommendations : [];
        
        let summaryText = d.ai_summary || 'Tidak ada ringkasan.';
        let recommendationsHtml = '';
        
        if (recs.length > 0) {
            recommendationsHtml = '<ul class="mon-ai-rec-list">' + 
                recs.map(r => `<li>${r}</li>`).join('') + 
                '</ul>';
        } else {
            recommendationsHtml = '<div class="mon-ai-empty">Tidak ada rekomendasi khusus.</div>';
        }
        
        container.innerHTML = `
            <div class="mon-ai-header">
                <div class="mon-ai-title">
                    <span>🧠</span>
                    <span>AI Cost & Schedule Variance Analysis</span>
                </div>
                <span class="mon-ai-risk-badge ${riskClass}">RISK: ${risk}</span>
            </div>
            
            <div class="mon-ai-section">
                <div class="mon-ai-section-title">
                    <span>📊</span>
                    <span>Ringkasan Eksekutif:</span>
                </div>
                <div class="mon-ai-summary">
                    ${summaryText}
                </div>
            </div>
            
            <div class="mon-ai-section">
                <div class="mon-ai-section-title">
                    <span>✅</span>
                    <span>Rekomendasi Mitigasi:</span>
                </div>
                ${recommendationsHtml}
            </div>
            
            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid rgba(34, 211, 238, 0.1)">
                <div style="font-size: 10px">
                    Analisis terakhir: ${new Date(d.created_at).toLocaleString('id-ID')}
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Error loading AI recommendation:', err);
        container.innerHTML = `
            <div class="mon-ai-header">
                <div class="mon-ai-title">
                    <span>🧠</span>
                    <span>AI Cost & Schedule Variance Analysis</span>
                </div>
            </div>
            <div class="mon-ai-empty" >Gagal memuat rekomendasi AI.</div>
        `;
    }
}

// Auto-select first project on load
document.addEventListener('DOMContentLoaded', () => {
    const firstCard = document.querySelector('.mon-card-grid');
    if (firstCard) {
        const projectId = firstCard.getAttribute('data-project-id');
        const projectName = firstCard.getAttribute('data-project-name');
        selectMonitoringProject(projectId, projectName);
    }
});
</script>