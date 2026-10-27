#!/bin/sh
# Rev2Square production status check (WP5B).
#
# Read-only operational overview: container states, /up health, queue
# depth, failed jobs, recent application errors, disk usage, database
# size. Every check prints PASS/WARN/FAIL; the script exits 1 if any
# check FAILs. Prints no credentials.
#
# Usage:
#   docker/ops/status.sh [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--url URL] [--insecure]
#
# Variants: prod (compose.prod.yaml + .env.production, expects the Caddy
# proxy service) or virtualmin (compose.virtualmin.yaml + .env.virtualmin,
# no proxy service). An explicit --env-file overrides the variant default.
# --url overrides the APP_URL from the env file (e.g. a local smoke test).
# --insecure passes -k to curl; smoke-test only, never for production.

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VARIANT="prod"
ENV_FILE=""
ENV_SET=0
PROJECT=""
URL_OVERRIDE=""
CURL_INSECURE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --variant) VARIANT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --url) URL_OVERRIDE="$2"; shift 2 ;;
        --insecure) CURL_INSECURE="-k"; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--url URL] [--insecure]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$VARIANT" in
    prod)
        COMPOSE_FILE="$ROOT/compose.prod.yaml"
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="$ROOT/.env.production"
        EXPECTED_SERVICES="app nginx proxy db queue scheduler"
        ;;
    virtualmin)
        COMPOSE_FILE="$ROOT/compose.virtualmin.yaml"
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="$ROOT/.env.virtualmin"
        EXPECTED_SERVICES="app nginx db queue scheduler"
        ;;
    *) echo "Unknown variant: $VARIANT (use prod or virtualmin)" >&2; exit 2 ;;
esac

if [ ! -f "$ENV_FILE" ]; then
    echo "FAIL: env file not found: $ENV_FILE" >&2
    exit 2
fi

# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

if [ -n "$PROJECT" ]; then
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE -p $PROJECT"
else
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE"
fi

FAIL=0
pass() { echo "PASS: $1"; }
warn() { echo "WARN: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

echo "== Rev2Square status ($(date -u +%Y-%m-%dT%H:%M:%SZ)) =="

# 1. Container states.
echo "--- containers ---"
# shellcheck disable=SC2086
PS_OUT=$($COMPOSE ps --format '{{.Service}} {{.State}}' 2>&1) || PS_OUT=""
if [ -z "$PS_OUT" ]; then
    fail "no containers reported (is the stack up?)"
else
    echo "$PS_OUT"
    # shellcheck disable=SC2086
    for SVC in $EXPECTED_SERVICES; do
        if echo "$PS_OUT" | grep -q "^$SVC running"; then
            pass "$SVC running"
        else
            fail "$SVC not running"
        fi
    done
fi

# 2. Application health endpoint.
echo "--- health endpoint ---"
if [ -n "$URL_OVERRIDE" ]; then
    BASE="$URL_OVERRIDE"
else
    BASE="${APP_URL:?APP_URL missing from $ENV_FILE}"
fi
# shellcheck disable=SC2086
if curl -fsS -m 15 $CURL_INSECURE "$BASE/up" -o /dev/null 2>&1; then
    pass "GET $BASE/up returned 2xx"
else
    fail "GET $BASE/up failed"
fi

# 3. Queue depth (fails if the default queue holds more than 1000 jobs).
echo "--- queue ---"
# shellcheck disable=SC2086
if QMON_OUT=$($COMPOSE exec -T app php artisan queue:monitor database:default --max=1000 2>&1); then
    pass "queue depth within limits"
    echo "$QMON_OUT" | head -10
else
    fail "queue monitor reported a problem (backlog or worker down)"
    echo "$QMON_OUT" | head -10
fi

# 4. Failed jobs (informational: any entry is a WARN, not a FAIL).
echo "--- failed jobs ---"
# shellcheck disable=SC2086
QFAIL_OUT=$($COMPOSE exec -T app php artisan queue:failed 2>&1) || QFAIL_OUT=""
if echo "$QFAIL_OUT" | grep -qi "no failed jobs\|no failed\|0 failed"; then
    pass "no failed jobs"
else
    warn "failed jobs present (run 'queue:failed' for detail)"
    echo "$QFAIL_OUT" | head -15
fi

# 5. Recent application errors (last 300 log lines).
echo "--- application errors ---"
# shellcheck disable=SC2086
LOG_OUT=$($COMPOSE exec -T app sh -c 'tail -n 300 storage/logs/laravel.log 2>/dev/null' 2>&1) || LOG_OUT=""
if [ -z "$LOG_OUT" ]; then
    warn "no application log yet (fresh deploy?)"
else
    ERR_COUNT=$(echo "$LOG_OUT" | grep -ciE '\.(ERROR|CRITICAL|ALERT|EMERGENCY)' || true)
    if [ "${ERR_COUNT:-0}" -eq 0 ]; then
        pass "no errors in last 300 log lines"
    else
        warn "$ERR_COUNT error-level line(s) in last 300 log lines"
        echo "$LOG_OUT" | grep -iE '\.(ERROR|CRITICAL|ALERT|EMERGENCY)' | tail -5
    fi
fi

# 6. Disk usage.
echo "--- disk ---"
df -h "$ROOT" | tail -1
docker system df 2>/dev/null | head -8 || warn "docker system df unavailable"

# 7. Database size (proves the database answers and shows growth).
echo "--- database ---"
: "${DB_DATABASE:?DB_DATABASE missing from $ENV_FILE}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD missing from $ENV_FILE}"
# shellcheck disable=SC2086
if DB_OUT=$($COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
        mariadb -uroot -N -e "SELECT CONCAT(ROUND(SUM(data_length+index_length)/1024/1024,2), ' MB in ', COUNT(*), ' tables') FROM information_schema.tables WHERE table_schema='$DB_DATABASE';" 2>&1); then
    pass "database '$DB_DATABASE' reachable: $DB_OUT"
else
    fail "database query failed"
    echo "$DB_OUT" | head -5
fi

echo "== status check complete =="
exit $FAIL
