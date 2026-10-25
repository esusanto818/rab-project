<?php
/**
 * function.spk.php  [NEW 2026-10-21]
 * ------------------------------------------------------------------
 * Logika kuota SPK (Surat Perintah Kerja). Dipakai oleh php-crud/spkCRD.php dan php-crud/warehouse.issuingCRD.php.
 * Bergantung pada helper yang sama dengan CRD stok: WhRow(), WhRows(), WhException.
 *
 * Aturan kuota material per proyek:
 *   % dibayar   = SUM(project_invoices.paid_amount) / projects.contract_value x 100        (maks 100)
 *   % diizinkan = % dibayar x SPK_PAID_MULTIPLIER (2), maksimal 100
 *   kuota kumulatif per material = qty RAB x % diizinkan / 100
 *   terpakai kuota = qty semua SPK Diterbitkan (dipesan) + issue proyek posted yang BUKAN dari SPK aktif
 *                    (issue tanpa SPK / dari SPK yang sudah Selesai = pemakaian nyata)
 *   tersedia untuk SPK baru = kuota - terpakai kuota
 * Sisa SPK per material = qty di SPK - issue posted yang memakai SPK itu.
 */

if (!defined('SPK_PAID_MULTIPLIER')) { define('SPK_PAID_MULTIPLIER', 2.0); }

// Pembayaran proyek: nilai kontrak, total diterima, persen, dan persen yang diizinkan untuk material
function SpkProjectPaid($projectId) {
    $r = WhRow("SELECT COALESCE(p.contract_value,0) AS cv,
                       COALESCE((SELECT SUM(paid_amount) FROM project_invoices WHERE project_id=p.id),0) AS paid
                FROM projects p WHERE p.id=$1", array((int)$projectId));
    if (!$r) { throw new WhException('Proyek tidak ditemukan.'); }
    $cv = (float)$r['cv']; $paid = (float)$r['paid'];
    $pct = ($cv > 0) ? min(100.0, $paid / $cv * 100.0) : 0.0;
    $allowed = min(100.0, $pct * SPK_PAID_MULTIPLIER);
    return array('contract_value' => $cv, 'paid_amount' => $paid,
                 'paid_pct' => round($pct, 4), 'allowed_pct' => round($allowed, 4));
}

// Kuota per material untuk proyek. $excludeSpkId = SPK yang sedang diedit (tidak dihitung sebagai pemakai kuota).
function SpkMaterialQuota($projectId, $excludeSpkId = 0) {
    $projectId = (int)$projectId; $excludeSpkId = (int)$excludeSpkId;
    $pay = SpkProjectPaid($projectId);
    $rab = WhRows("SELECT bi.material_id, m.material_code, m.material_name,
                          MAX(COALESCE(NULLIF(bi.unit,''), m.unit, '')) AS unit, SUM(bi.qty_plan) AS rab_qty
                   FROM budget_items bi JOIN materials m ON m.id=bi.material_id
                   WHERE bi.project_id=$1 AND bi.material_id IS NOT NULL
                   GROUP BY bi.material_id, m.material_code, m.material_name
                   ORDER BY m.material_name", array($projectId));

    $act = array();
    foreach (WhRows("SELECT si.material_id, SUM(si.qty) AS q FROM spk_items si JOIN spk s ON s.id=si.spk_id
                     WHERE s.project_id=$1 AND s.status='Diterbitkan' AND s.id<>$2 GROUP BY si.material_id",
                    array($projectId, $excludeSpkId)) as $r) { $act[(int)$r['material_id']] = (float)$r['q']; }

    $oth = array();
    foreach (WhRows("SELECT i.material_id, SUM(i.qty_out) AS q
                     FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                     LEFT JOIN spk s ON s.id=h.spk_id
                     WHERE h.project_id=$1 AND h.status='posted' AND h.issue_type='PROJECT'
                       AND (h.spk_id IS NULL OR s.status<>'Diterbitkan') AND (h.spk_id IS NULL OR h.spk_id<>$2)
                     GROUP BY i.material_id", array($projectId, $excludeSpkId)) as $r) { $oth[(int)$r['material_id']] = (float)$r['q']; }

    // Stok fisik inventory: saldo IN - OUT pada seluruh gudang aktif.
    // Batas qty SPK tidak boleh melampaui kuota pembayaran/RAB maupun stok fisik yang tersedia.
    $stock = array();
    foreach (WhRows("SELECT bal.item_id AS material_id, SUM(GREATEST(bal.onhand,0)) AS onhand
                     FROM (SELECT sm.item_id, sm.warehouse_id,
                                  SUM(CASE WHEN sm.movement_type='IN' THEN sm.qty ELSE -sm.qty END) AS onhand
                           FROM stock_movements sm
                           JOIN warehouses w ON w.id=sm.warehouse_id
                           WHERE w.active=true
                           GROUP BY sm.item_id, sm.warehouse_id) bal
                     GROUP BY bal.item_id") as $sr) {
        $stock[(int)$sr['material_id']] = max(0, (float)$sr['onhand']);
    }

    $out = array();
    foreach ($rab as $r) {
        $mid = (int)$r['material_id']; $rq = (float)$r['rab_qty'];
        $cap = round($rq * $pay['allowed_pct'] / 100.0, 4);
        $a = isset($act[$mid]) ? $act[$mid] : 0.0; $o = isset($oth[$mid]) ? $oth[$mid] : 0.0;
        $quotaAvailable = max(0, round($cap - $a - $o, 4));
        $stockAvailable = isset($stock[$mid]) ? round($stock[$mid], 4) : 0.0;
        $out[$mid] = array('material_id' => $mid, 'material_code' => $r['material_code'], 'material_name' => $r['material_name'],
                           'unit' => $r['unit'], 'rab_qty' => round($rq, 4), 'cap_qty' => $cap,
                           'spk_active' => round($a, 4), 'issued_other' => round($o, 4),
                           'stock_available' => $stockAvailable,
                           'quota_available' => $quotaAvailable,
                           'available' => min($quotaAvailable, $stockAvailable));
    }
    return array('pay' => $pay, 'materials' => $out);
}

// Sisa jatah satu material pada satu SPK = qty SPK - issue posted yang memakai SPK itu
function SpkRemaining($spkId, $materialId) {
    $q = WhRow("SELECT COALESCE(SUM(qty),0) AS q FROM spk_items WHERE spk_id=$1 AND material_id=$2", array((int)$spkId, (int)$materialId));
    $u = WhRow("SELECT COALESCE(SUM(i.qty_out),0) AS q FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                WHERE h.spk_id=$1 AND i.material_id=$2 AND h.status='posted'", array((int)$spkId, (int)$materialId));
    return array('qty' => round((float)$q['q'], 4), 'used' => round((float)$u['q'], 4),
                 'remaining' => max(0, round((float)$q['q'] - (float)$u['q'], 4)));
}