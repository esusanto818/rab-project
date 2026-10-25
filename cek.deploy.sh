#!/bin/bash
# ============================================================
# cek_deploy.sh -- memeriksa apakah SEMUA file hasil update sudah terpasang di server (versi terbaru).
# Cara: bash cek_deploy.sh [folder-aplikasi]
#   folder-aplikasi = folder yang berisi php-crud/, js/, views/, pdf/, php-func/ (contoh: ~/rab-project/dev)
#   Tanpa argumen: dicoba folder saat ini, lalu ~/rab-project/dev, ~/rab-project, ~/proyekgmn.
# Hasil:  OK = file ada dan berisi penanda versi baru | LAMA = file ada tetapi masih versi lama | HILANG = file tidak ada / kosong
# Hanya membaca; tidak mengubah apa pun.
# ============================================================
ROOT="$1"
if [ -z "$ROOT" ]; then
  for d in "$PWD" "$HOME/rab-project/dev" "$HOME/rab-project" "$HOME/proyekgmn"; do
    if [ -d "$d/php-crud" ] && [ -d "$d/js" ]; then ROOT="$d"; break; fi
  done
fi
[ -d "$ROOT/php-crud" ] || { echo "Folder aplikasi tidak ditemukan. Jalankan: bash cek_deploy.sh /path/ke/aplikasi"; exit 1; }
echo "Folder aplikasi: $ROOT"
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
js/profitloss.js|Tahun Berjalan (YTD)
php-crud/financialreportCRD.php|amount_ytd
js/financialreport.js|amount_ytd
js/journal_entry.js|btnPrintJournalDtl
pdf/balancesheet.php|PRINT-BS
pdf/trialbalance.php|hide_zero
pdf/profitloss.php|tpl v3
pdf/financialreport.php|fr_is_section
pdf/generalledger.php|LANDSCAPE
pdf/journal.php|period_id
pdf/inventoryrecon.php|ir_reconcile
pdf/spk.php|SURAT PERINTAH KERJA
php-crud/project.paymentCRD.php|backfill_termin_journal
php-crud/warehouse.issuingCRD.php|lookup_spk
js/warehouse.issuing.js|fSpk
php-crud/project.wipreleaseCRD.php|wr_calc
js/project.wiprelease.js|previewOk
views/project.wiprelease.php|wiprelease
php-crud/year.closingCRD.php|yc_filter
js/year.closing.js|groupsOf
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
LIST
echo "----------------------------------------"
echo "OK: $ok   LAMA: $lama   HILANG: $hilang"
[ "$lama" -eq 0 ] && [ "$hilang" -eq 0 ] && echo "Semua file terpasang." || echo "Salin ulang file berstatus LAMA / HILANG (lihat CHECKLIST_DEPLOY.md untuk urutannya)."
