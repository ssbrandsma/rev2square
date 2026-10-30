#!/bin/sh
# Rev2Square production MariaDB restore (WP5B).
#
# Restores a gzip dump created by backup.sh into the production database.
# DESTRUCTIVE: the current database content is replaced. A safety dump of
# the current state is taken first (unless --skip-safety-dump).
#
# Usage:
#   docker/backup/restore.sh [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--yes] [--skip-safety-dump] FILE.sql.gz
#
# Variants: prod (compose.prod.yaml + .env.production) or virtualmin
# (compose.virtualmin.yaml + /etc/rev2square/.env.virtualmin). An
# explicit --env-file overrides the variant default.
#
# After restore the script runs migrations (schema alignment no-op on a
# healthy dump) and signals workers to restart gracefully. This script
# never prints credentials.
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VARIANT="prod"
ENV_FILE=""
ENV_SET=0
PROJECT=""
ASSUME_YES=0
SAFETY_DUMP=1
FILE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --variant) VARIANT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --yes) ASSUME_YES=1; shift ;;
        --skip-safety-dump) SAFETY_DUMP=0; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--yes] [--skip-safety-dump] FILE.sql.gz"
            exit 0
            ;;
        -*) echo "Unknown argument: $1" >&2; exit 2 ;;
        *) FILE="$1"; shift ;;
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

if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
    echo "FAIL: dump file not found: ${FILE:-<none>}" >&2
    exit 2
fi

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

gzip -t "$FILE" || { echo "FAIL: $FILE is not a valid gzip file" >&2; exit 2; }

if [ "$ASSUME_YES" -ne 1 ]; then
    echo "About to REPLACE database '$DB_DATABASE' with: $FILE"
    printf "Type the database name to confirm: "
    read -r ANSWER
    if [ "$ANSWER" != "$DB_DATABASE" ]; then
        echo "Aborted." >&2
        exit 2
    fi
fi

if [ "$SAFETY_DUMP" -eq 1 ]; then
    echo "Taking a safety dump of the current database first ..."
    if [ -n "$PROJECT" ]; then
        "$ROOT/docker/backup/backup.sh" --variant "$VARIANT" --env-file "$ENV_FILE" --project "$PROJECT"
    else
        "$ROOT/docker/backup/backup.sh" --variant "$VARIANT" --env-file "$ENV_FILE"
    fi
fi

echo "Restoring $FILE into '$DB_DATABASE' ..."
# shellcheck disable=SC2086
gunzip -c "$FILE" | $COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot

echo "Aligning schema (migrations) ..."
# shellcheck disable=SC2086
$COMPOSE exec -T app php artisan migrate --force

echo "Signalling workers to restart gracefully ..."
# shellcheck disable=SC2086
$COMPOSE exec -T app php artisan queue:restart || true

echo "Verifying table count ..."
# shellcheck disable=SC2086
$COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
    mariadb -uroot -N -e "SELECT CONCAT(COUNT(*), ' tables in $DB_DATABASE') FROM information_schema.tables WHERE table_schema='$DB_DATABASE';"

echo "Restore OK."
