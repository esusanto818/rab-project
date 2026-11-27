#!/bin/bash
# ============================================================
# cek.deploy.sh -- Verifikasi file terpasang + Automated Risk & CSS Audit Gate.
# Cara: bash cek.deploy.sh [folder-aplikasi] [--strict-css]
#   folder-aplikasi = folder yang berisi php-crud/, js/, views/, pdf/, php-func/
#   Tanpa argumen: dicoba folder saat ini, lalu ~/proyekgmn-dev, ~/rab-project/dev, ~/rab-project, ~/proyekgmn.
# ============================================================
ROOT=""
STRICT_CSS=0

for arg in "$@"; do
  if [ "$arg" = "--strict-css" ]; then
    STRICT_CSS=1
  elif [ -z "$ROOT" ]; then
    ROOT="$arg"
  fi
done

if [ -z "$ROOT" ]; then
  for d in "$PWD" "$HOME/proyekgmn-dev" "$HOME/rab-project/dev" "$HOME/rab-project" "$HOME/proyekgmn"; do
    if [ -d "$d/php-crud" ] && [ -d "$d/js" ]; then ROOT="$d"; break; fi
  done
fi
[ -d "$ROOT/php-crud" ] || { echo "Folder aplikasi tidak ditemukan. Jalankan: bash cek.deploy.sh /path/ke/aplikasi"; exit 1; }

echo "============================================================"
echo " [TAHAP 1/3] CEK KELENGKAPAN FILE DEPLOY: $ROOT"
echo "============================================================"
printf "%-8s %-46s %s\n" "STATUS" "FILE" "PENANDA"
ok=0; lama=0; hilang=0
while IFS='|' read -r path mark; do
  [ -z "$path" ] && continue
  f="$ROOT/$path"
  if [ ! -s "$f" ]; then st="HILANG"; hilang=$((hilang+1))
  elif grep -qF -- "$mark" "$f"; then st="OK"; ok=$((ok+1))
  else st="LAMA"; lama=$((lama+1)); fi
  printf "%-8s %-46s %s\n" "$st" "$path" "$mark"
done <<'LIST'
php-func/function.all.php|JournalNextRefNo
php-func/function.spk.php|SpkMaterialQuota
php-func/function.inventory.recon.php|ir_fix_missing
pdf/print.common.php|extraCss
php-crud/balancesheetCRD.php|FIX-LABA
js/balancesheet.js|FONT18
php-crud/trialbalanceCRD.php|FIX-BANKCASH
js/trialbalance.js|FIT-GRID
php-crud/generalledgerCRD.php|FIX-BANKCASH
php-crud/profitlossCRD.php|closing_entry
js/profitloss.js|Ext.
php-crud/financialreportCRD.php|amount_ytd
js/financialreport.js|amount_ytd
js/journal.entry.js|btnPrintJournalDtl
pdf/balancesheet.php|PRINT-BS
pdf/trialbalance.php|hide_zero
pdf/profitloss.php|tpl v3
pdf/financialreport.php|fr_is_section
pdf/generalledger.php|LANDSCAPE
pdf/journal.php|period_id
pdf/inventory.recon.php|<?php
pdf/spk.php|SURAT PERINTAH KERJA
php-crud/project.paymentCRD.php|backfill_termin_journal
php-crud/warehouse.issuingCRD.php|<?php
js/warehouse.issuing.js|fSpk
php-crud/project.wipreleaseCRD.php|wr_calc
js/project.wiprelease.js|previewOk
views/project.wiprelease.php|wiprelease
php-crud/year.closingCRD.php|yc_filter
js/year.closing.js|Ext.
views/year.closing.php|year.closing
php-crud/inventory.reconCRD.php|fix_missing_journals
js/inventory.recon.js|btnFix
views/inventory.recon.php|inventory.recon
php-crud/spkCRD.php|SpkIssue
js/spk.js|addAllMaterials
views/spk.php|spk.js
php-crud/project.usageCRD.php|v_project_material_vs_progress
js/project.usage.js|loadSummary
views/project.usage.php|project.usage
php-crud/m.proyekCRD.php|ppn_rate
js/m.proyek.js|fldPpn
css/app-theme.css|UNIFIED BUTTON SYSTEM
tests/auto_risk_test.php|RISIKO-01
tests/auto_css_test.php|CSS-01
LIST

echo "------------------------------------------------------------"
echo "File Status -> OK: $ok | LAMA: $lama | HILANG: $hilang"
FILE_FAIL=0
if [ "$lama" -ne 0 ] || [ "$hilang" -ne 0 ]; then
  echo "[WARN] Ada file berstatus LAMA / HILANG (lihat CHECKLIST_DEPLOY.md)."
  FILE_FAIL=1
fi

# ============================================================
# [TAHAP 2/3] AUTOMATED RISK REGRESSION TEST (MANDATORY GATE)
# ============================================================
echo ""
echo "============================================================"
echo " [TAHAP 2/3] AUTOMATED RISK REGRESSION SUITE"
echo "============================================================"
RISK_FAIL=0
if [ -f "$ROOT/tests/auto_risk_test.php" ]; then
  php "$ROOT/tests/auto_risk_test.php"
  [ $? -ne 0 ] && RISK_FAIL=1
else
  echo "[SKIP] $ROOT/tests/auto_risk_test.php tidak ditemukan."
fi

# ============================================================
# [TAHAP 3/3] AUTOMATED CSS UNIFICATION & BUTTON AUDIT
# ============================================================
echo ""
echo "============================================================"
echo " [TAHAP 3/3] AUTOMATED CSS UNIFICATION & BUTTON AUDIT"
echo "============================================================"
CSS_FAIL=0
if [ -f "$ROOT/tests/auto_css_test.php" ]; then
  php "$ROOT/tests/auto_css_test.php"
  [ $? -ne 0 ] && CSS_FAIL=1
else
  echo "[SKIP] $ROOT/tests/auto_css_test.php tidak ditemukan."
fi

# ============================================================
# RINGKASAN & KEPUTUSAN DEPLOY GATE
# ============================================================
echo ""
echo "============================================================"
echo " RINGKASAN DEPLOY GATE"
echo "============================================================"
echo " - Kelengkapan File : $([ $FILE_FAIL -eq 0 ] && echo 'PASS' || echo 'FAIL')"
echo " - Risk Regression  : $([ $RISK_FAIL -eq 0 ] && echo 'PASS' || echo 'FAIL (BLOCKER)')"
echo " - CSS Unification  : $([ $CSS_FAIL -eq 0 ] && echo 'PASS' || echo 'WARN (lihat docs/CSS_UNIFICATION_TEST_REPORT.md)')"
echo "============================================================"

if [ $FILE_FAIL -ne 0 ] || [ $RISK_FAIL -ne 0 ]; then
  echo "[BLOCKED] Deploy ditahan karena ada kegagalan pada kelengkapan file atau Risk Regression."
  exit 1
fi

if [ $STRICT_CSS -eq 1 ] && [ $CSS_FAIL -ne 0 ]; then
  echo "[BLOCKED] Deploy ditahan (--strict-css aktif dan audit CSS belum 100% PASS)."
  exit 1
fi

echo "[READY] Aplikasi lolos verifikasi deploy!"
exit 0
