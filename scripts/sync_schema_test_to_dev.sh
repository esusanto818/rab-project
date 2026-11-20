#!/usr/bin/env bash
set -euo pipefail

PG_HOST="${PG_HOST:-127.0.0.1}"
PG_PORT="${PG_PORT:-5432}"
PG_USER="${PG_USER:-postgres}"

SRC_DB="${SRC_DB:-proyekGMN_test}"
DST_DB="${DST_DB:-proyekGMN}"

PROJECT_DIR="$HOME/proyekgmn-dev"
SQL_DIR="$PROJECT_DIR/sql"
BACKUP_DIR="$PROJECT_DIR/backups/schema_sync"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

mkdir -p "$BACKUP_DIR"

echo "============================================================"
echo " Sinkronisasi Skema PostgreSQL"
echo " Sumber : $SRC_DB ($PG_HOST:$PG_PORT)"
echo " Target : $DST_DB ($PG_HOST:$PG_PORT)"
echo "============================================================"

echo "[1/5] Memeriksa koneksi ke $SRC_DB dan $DST_DB..."
psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$SRC_DB" -c "SELECT 1;" >/dev/null
psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$DST_DB" -c "SELECT 1;" >/dev/null
echo "      Koneksi OK."

BACKUP_FILE="$BACKUP_DIR/${DST_DB}_before_sync_${TIMESTAMP}.dump"
echo "[2/5] Membuat backup penuh database target ke:"
echo "      $BACKUP_FILE"
pg_dump -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -Fc -f "$BACKUP_FILE" "$DST_DB"
echo "      Backup selesai."

SRC_SCHEMA="$BACKUP_DIR/${SRC_DB}_schema_${TIMESTAMP}.sql"
DST_SCHEMA="$BACKUP_DIR/${DST_DB}_schema_${TIMESTAMP}.sql"
DIFF_FILE="$BACKUP_DIR/schema_diff_${TIMESTAMP}.patch"

echo "[3/5] Mengekspor perbandingan skema..."
pg_dump -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" --schema-only --no-owner --no-privileges "$SRC_DB" > "$SRC_SCHEMA"
pg_dump -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" --schema-only --no-owner --no-privileges "$DST_DB" > "$DST_SCHEMA"
diff -u "$DST_SCHEMA" "$SRC_SCHEMA" > "$DIFF_FILE" || true
echo "      Selisih skema tersimpan di: $DIFF_FILE"

echo "[4/5] Daftar file migrasi di $SQL_DIR:"
if [ -d "$SQL_DIR" ] && [ -n "$(ls -A "$SQL_DIR"/*.sql 2>/dev/null)" ]; then
    ls -1 "$SQL_DIR"/*.sql | nl
else
    echo "      (Tidak ditemukan file *.sql di $SQL_DIR)"
    exit 0
fi

echo ""
read -p "Terapkan seluruh file migrasi di atas ke '$DST_DB'? (ketik 'ya' untuk lanjut): " CONFIRM
if [ "$CONFIRM" != "ya" ]; then
    echo "Dibatalkan. Backup tetap aman di $BACKUP_FILE."
    exit 0
fi

echo "[5/5] Menjalankan migrasi SQL ke $DST_DB..."
for sql_file in "$SQL_DIR"/*.sql; do
    echo "  -> Menjalankan: $(basename "$sql_file")"
    psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$DST_DB" -v ON_ERROR_STOP=1 -f "$sql_file"
done

echo ""
echo "============================================================"
echo " Selesai! Skema '$DST_DB' berhasil diperbarui."
echo "============================================================"
