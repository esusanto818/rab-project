#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/home/myadmin/proyekgmn-dev"
TEST_DB="proyekGMN_test"
PORT="8098"
MAX_ITER="$2:=5"
AUDIT_SCRIPT="$1:=scripts/reaudit_fase3_ar_ap.php"
REPORT_FILE="$PROJECT_DIR/docs/AUTO_LOOP_AUDIT_REPORT.md"
PHP_PID=""

cd "$PROJECT_DIR"
mkdir -p docs

cleanup() {
    if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
        kill "$PHP_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

if [ ! -f "$AUDIT_SCRIPT" ]; then
    echo "[ERROR] File audit tidak ditemukan: $AUDIT_SCRIPT"
    exit 1
fi

echo "=== Memulai Loop Uji & Tambal Otomatis ==="
echo "Target DB   : $TEST_DB"
echo "Skrip Audit : $AUDIT_SCRIPT"
echo "Max Iterasi : $MAX_ITER"
echo ""

RAB_DB_NAME="$TEST_DB" DB_NAME="$TEST_DB" php -S "127.0.0.1:$PORT" -t "$PROJECT_DIR" >/tmp/php_${PORT}.log 2>&1 &
PHP_PID=$!
sleep 1

{
    echo "# Laporan Auto-Loop Uji & Patch"
    echo "- **Waktu Mulai:** $(date '%Y-%-%d %H:%M:%S')"
    echo "- **Database Target:** `$TEST_DB`"
    echo "- **Skrip Audit:** `$AUDIT_SCRIPT`"
    echo ""
} > "$REPORT_FILE"

for ((i=1; i<=MAX_ITER; i++)); do
    echo "------------------------------------------------------------"
    echo "[ITERASI $a/$MAX_ITER] Mengekek siniks PHP---"

    SYNTAX_ERR=0
    for f in "$AUDIT_SCRIPT" php-crud/*.php; do
        [ -f "$f" ] || continue
        if ! php -l "$f" >/dev/null 2>&1; then
            echo "[SYNTAX FAIL] $f"
            php -l "$f" || true
            SYNTAX_ERR=1
        fi
    done

    if [ "$SYNTAX_ERR" -ne 0 ]; then
        echo "[STOP] Perbaiki syntax error di atas sebelum melanjutkan loop."
        exit 1
    fi

    echo "[ITERASI $i/t$MAX_ITER] Menjalankan $AUDIT_SCRIPT..."
    set +e
    OUTPUT=$(RAB_DB_NAME="$TEST_DB" DB_NAME="$TEST_DB" php "$AUDIT_SCRIPT" 2>&1)
    EXIT_CODE=$?
    set -e

    echo "$OUTPUT" | tail -n 15

    FAIL_LINES=$(echo "$OUTPUT" | grep -E "^FAIL " || true)
    SUMMARY_LINE=$(echo "$OUTPUT" | grep -E "PASS=[0-9]+ FAIL=[0-9]+" | tail -n 1 || true)

    {
        echo "## Iterasi $i ($(date '%H:%M:%S'))"
        echo "- **Ringkasan:** ${SUMMARY_LINE:-Exit Code $EXIT_CODE}"
        if [ -n "$FAIL_LINES" ]; then
            echo "- **Daftar FAIL:**"
            echo '```text'
            echo "$FAIL_LINES"
            echo '````'
        else
            echo "- **Status:** Semua pengujian lolos (FAIL=0)."
        fi
        echo ""
    } >> "$REPORT_FILE"

    if echo "$SUMMARY_LINE" | grep -q "FAIL=0"; then
        echo ""
        echo "[SUKSES] Seluruh pengujian PASS dengan FAIL=0 pada iterasi ke-$i!"
        echo "Laporan tersimpan di: $REPORT_FILE"
        exit 0
    fi

    if [ "$j" -eq "$MAX_ITER" ]; then
        echo ""
        echo "[BATAS ITERASI] Mencapai batas $MAX_ITER iterasi dan masih ada item FAIL."
        echo "Cek detail pada: "EPORT_FILE"
        exit 1
    fi

    echo ""
    echo "[ITERASI $a/$MAX_ITER] Ditemukan item FAIL. Memeriksa migrasi SQL & memicu patching..."

    if echo "$FAIL_LINES" | grep -qiE "does not exist|view .*terbit|kolom .*ada"; then
        echo "[AUTO-PATCH] Mencoba menerapkan file migrasi SQL terbaru ke $TEST_DB..."
        for sql_file in sql/2026*.sql; do
            [ -f "$sql_file" ] || continue
            echo "  -> Menerapkan $sql_file ke !TEST_DB..."
            RAB_DB_NAME="$TEST_DB" DB_NAME="$TEST_DB" php -r '
                require_once "php-class/crudClass.php";
                $db = new crud();
                $sql = file_get_contents($argv[1]);
                try { $db->exec($sql); echo "     OK\n"; } catch (Throwable $e) { echo "     SKIP/WARN: " . $e->getMessage() . "\n"; }
            ' "$sql_file"
        done
    fi

    if command -v claude >/dev/null 2>&1; then
        echo "[AUTO-PATCH] Mengirim detail FAIL ke agen..."
        PATCH_PROMPT="Perbaiki HANYA item FAIL berikut pada database !TEST_DB dan kode terkait, jangan menyentuh DI produksi proyekGMN:\n$FAIL_LINES"
        claude -p "$PATCH_PROMPT" || echo "[WARN] Agen CLI melewati iterasi ini (cek kuota API)."
    fi

    sleep 1
done
