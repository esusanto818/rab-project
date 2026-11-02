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
<style>
    .mon-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }
    @media (max-width: 1024px) {
        .mon-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .mon-grid { grid-template-columns: 1fr; }
    }
    
    .mon-card-grid {
        background: linear-gradient(135deg, rgba(10, 45, 74, 0.6) 0%, rgba(5, 21, 37, 0.8) 100%);
        border: 1px solid rgba(34, 211, 238, 0.15);
        border-radius: 10px;
        padding: 14px 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .mon-card-grid:hover {
        border-color: rgba(34, 211, 238, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    }
    .mon-card-grid.active {
        border-color: #22d3ee;
        box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.3);
    }
    .mon-card-grid::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 3px; height: 100%;
        background: var(--status-color, #10b981);
    }
    
    .mon-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
        gap: 8px;
    }
    .mon-card-title {
        font-family: 'Rajdhani', sans-serif;
        font-size: 15px;
        font-weight: 700;
        color: #ffffff;
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
        line-height: 1.2;
    }
    .mon-badge-grid {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .mon-dot-grid {
        width: 6px; height: 6px;
        border-radius: 50%;
        animation: mon-pulse 2s infinite;
    }
    @keyframes mon-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .mon-metrics-grid {
        display: flex;
        gap: 12px;
        margin-bottom: 10px;
        flex-wrap: wrap;
    }
    .mon-metric-grid {
        display: flex;
        align-items: baseline;
        gap: 4px;
    }
    .mon-metric-label {
        font-size: 10px;
        color: #94a3b8;
    }
    .mon-metric-value {
        font-family: 'Orbitron', sans-serif;
        font-size: 13px;
        font-weight: 700;
    }
    .mon-metric-value.progress { color: #22d3ee; }
    .mon-metric-value.usage { color: #f39c12; }
    .mon-metric-value.gap-positive { color: #ef4444; }
    .mon-metric-value.gap-warning { color: #f39c12; }
    .mon-metric-value.gap-normal { color: #10b981; }
    
    /* Progress Bar */
    .mon-progress-bar {
        width: 100%;
        height: 4px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 2px;
        overflow: hidden;
        margin-top: 8px;
    }
    .mon-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #22d3ee 0%, #0891b2 100%);
        border-radius: 2px;
        transition: width 0.5s ease;
    }
    
    /* Tooltip */
    .mon-tooltip {
        display: none;
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(5, 21, 37, 0.95);
        border: 1px solid rgba(34, 211, 238, 0.3);
        border-radius: 6px;
        padding: 8px 12px;
        font-size: 11px;
        color: #cbd5e1;
        white-space: nowrap;
        z-index: 100;
        margin-bottom: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        pointer-events: none;
    }
    .mon-card-grid:hover .mon-tooltip {
        display: block;
    }
    
    /* AI Recommendation - Format Baru */
    .mon-ai-dynamic {
        background: linear-gradient(135deg, rgba(10, 45, 74, 0.6) 0%, rgba(5, 21, 37, 0.8) 100%);
        border: 1px solid rgba(34, 211, 238, 0.3);
        border-radius: 10px;
        padding: 16px 20px;
        margin-top: 16px;
        min-height: 80px;
    }
    .mon-ai-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(34, 211, 238, 0.2);
    }
    .mon-ai-title {
        font-family: 'Rajdhani', sans-serif;
        font-size: 15px;
        font-weight: 700;
        color: #22d3ee;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .mon-ai-risk-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .mon-ai-risk-badge.mon-risk-medium {
        background: rgba(243, 156, 18, 0.2);
        color: #f39c12;
        border-color: rgba(243, 156, 18, 0.3);
    }
    .mon-ai-risk-badge.mon-risk-high {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.3);
    }
    .mon-ai-risk-badge.mon-risk-critical {
        background: rgba(168, 85, 247, 0.2);
        color: #a855f7;
        border-color: rgba(168, 85, 247, 0.3);
    }
    .mon-ai-project {
        font-size: 12px;
        color: #94a3b8;
        font-weight: 600;
    }
    .mon-ai-section {
        margin-bottom: 14px;
    }
    .mon-ai-section-title {
        font-family: 'Rajdhani', sans-serif;
        font-size: 14px;
        font-weight: 700;
        color: #22d3ee;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .mon-ai-summary {
        font-size: 13px;
        color: #e2e8f0;
        line-height: 1.7;
        text-align: justify;
        margin-bottom: 12px;
    }
    .mon-ai-summary strong {
        color: #22d3ee;
        font-weight: 700;
    }
    .mon-ai-rec-list {
        margin: 0;
        padding: 0 0 0 20px;
        font-size: 13px;
        color: #e2e8f0;
        line-height: 1.8;
    }
    .mon-ai-rec-list li {
        margin-bottom: 6px;
        text-align: justify;
    }
    .mon-ai-loading {
        text-align: center;
        color: #94a3b8;
        font-size: 13px;
        padding: 20px;
    }
    .mon-ai-empty {
        text-align: center;
        color: #10b981;
        font-size: 13px;
        font-style: italic;
        padding: 10px;
    }
</style>

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
             style="--status-color: <?= $style['border'] ?>;"
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
                <div class="mon-badge-grid" style="background: <?= $style['bg'] ?>; color: <?= $style['text'] ?>; border: 1px solid <?= $style['border'] ?>;">
                    <span class="mon-dot-grid" style="background: <?= $style['dot'] ?>;"></span>
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
                <div class="mon-progress-fill" style="width: <?= $progressPct ?>%;"></div>
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
            
            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid rgba(34, 211, 238, 0.1);">
                <div style="font-size: 10px; color: #64748b;">
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
            <div class="mon-ai-empty" style="color: #ef4444;">Gagal memuat rekomendasi AI.</div>
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