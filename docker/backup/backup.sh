#!/bin/sh
# Rev2Square production MariaDB backup (WP5B).
#
# Dumps the production database through the running db container and writes a
# timestamped gzip file. Safe to run while the app is live
# (--single-transaction; InnoDB).
#
# Usage:
#   docker/backup/backup.sh [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--dir DIR] [--keep N]
#
# Variants: prod (compose.prod.yaml + .env.production, rev2square-prod) or
# virtualmin (compose.virtualmin.yaml + /etc/rev2square/.env.virtualmin,
# rev2square-virtualmin). An explicit --env-file overrides the variant
# default. Output ./backups, keep 14 newest dumps.
#
# Cron example (daily 03:00 as the deploy user):
#   0 3 * * * cd /srv/rev2square && ./docker/backup/backup.sh >> /var/log/rev2square-backup.log 2>&1
#
# The backup directory holds sensitive data: keep it chmod 700 and never
# commit it (it is git-ignored). This script never prints credentials.
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VARIANT="prod"
ENV_FILE=""
ENV_SET=0
PROJECT=""
BACKUP_DIR="$ROOT/backups"
KEEP=14

while [ $# -gt 0 ]; do
    case "$1" in
        --variant) VARIANT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --dir) BACKUP_DIR="$2"; shift 2 ;;
        --keep) KEEP="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--dir DIR] [--keep N]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$VARIANT" in
    prod)
        COMPOSE_FILE="$ROOT/compose.prod.yaml"
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="$ROOT/.env.production"
        ;;
    virtualmin)
        COMPOSE_FILE="$ROOT/compose.virtualmin.yaml"
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="/etc/rev2square/.env.virtualmin"
        ;;
    *) echo "Unknown variant: $VARIANT (use prod or virtualmin)" >&2; exit 2 ;;
esac

if [ ! -f "$ENV_FILE" ]; then
    echo "FAIL: env file not found: $ENV_FILE" >&2
    exit 2
fi

# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

: "${DB_DATABASE:?DB_DATABASE missing from $ENV_FILE}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD missing from $ENV_FILE}"

if [ -n "$PROJECT" ]; then
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE -p $PROJECT"
else
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE"
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

TS=$(date -u +%Y%m%d-%H%M%S)
TMP="$BACKUP_DIR/.rev2square-$TS.sql.gz.tmp"
FINAL="$BACKUP_DIR/rev2square-$TS.sql.gz"

echo "Dumping database '$DB_DATABASE' ..."
# shellcheck disable=SC2086
$COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
    mariadb-dump -uroot \
        --single-transaction --routines --events \
        --databases "$DB_DATABASE" \
    | gzip -c > "$TMP"

mv "$TMP" "$FINAL"
gzip -t "$FINAL"

SIZE=$(du -h "$FINAL" | cut -f1)
SHA=$(sha256sum "$FINAL" | cut -d' ' -f1)
echo "Wrote $FINAL ($SIZE, sha256 $SHA)"

# Retention: keep the $KEEP newest dumps.
# shellcheck disable=SC2012
OLD=$(ls -t "$BACKUP_DIR"/rev2square-*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) || true)
if [ -n "$OLD" ]; then
    echo "$OLD" | xargs rm -f
    echo "Pruned $(echo "$OLD" | wc -l) old dump(s), keeping $KEEP."
fi

echo "Backup OK."
