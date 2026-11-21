#!/usr/bin/env bash
set -euo pipefail
umask 077

: "${MYSQL_BACKUP_CONFIG:?Defina MYSQL_BACKUP_CONFIG com um arquivo privado de opções do cliente MySQL.}"
: "${DB_NAME:?Defina DB_NAME com o banco a copiar.}"
[[ -f "$MYSQL_BACKUP_CONFIG" ]] || { echo 'Arquivo MYSQL_BACKUP_CONFIG não encontrado.' >&2; exit 1; }
[[ "$DB_NAME" =~ ^[a-zA-Z0-9_-]+$ ]] || { echo 'DB_NAME inválido.' >&2; exit 1; }
output_directory="${1:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)/backups}"
mkdir -p -- "$output_directory"
output_path="$output_directory/$DB_NAME-$(date -u +%Y%m%d-%H%M%S)-$$.sql"
if ! mysqldump "--defaults-extra-file=$MYSQL_BACKUP_CONFIG" --single-transaction --quick --routines --triggers --events \
    --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "--result-file=$output_path" "$DB_NAME"; then
    rm -f -- "$output_path"
    echo 'O backup falhou. Nenhum arquivo parcial foi mantido.' >&2
    exit 1
fi
printf '%s\n' "$output_path"
