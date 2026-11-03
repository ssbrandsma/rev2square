#!/bin/sh
# Rev2Square deploy readiness gate.
#
# Waits, boundedly, for the regular services to reach running+healthy
# before any HTTP verification runs. Without this gate a deploy check
# that curls immediately after `up -d` races container startup and
# fails with HTTP 502 while containers are still `health: starting`
# (nginx answers but php-fpm is not up yet) — a false failure that
# looks exactly like a broken deploy.
#
# Every expected service must report State=running AND Health=healthy
# (all of them define healthchecks in both compose files). An
# exited/dead service fails fast with its bounded logs (waiting
# cannot fix a crash); anything else waits until --timeout, then
# fails with the full `ps` table plus bounded logs of the services
# that never became ready. Prints no credentials.
#
# Usage:
#   docker/ops/wait-for-healthy.sh [--variant prod|virtualmin] [--env-file FILE]
#       [--project NAME] [--timeout SEC] [--interval SEC]
#
# Variants mirror status.sh. Defaults: --timeout 300, --interval 5.
# Exit 0 when every expected service is ready, 1 when the deadline
# passes or a service died, 2 on usage/env/compose-config errors.

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VARIANT="prod"
ENV_FILE=""
ENV_SET=0
PROJECT=""
TIMEOUT="300"
INTERVAL="5"

while [ $# -gt 0 ]; do
    case "$1" in
        --variant) VARIANT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --timeout) TIMEOUT="$2"; shift 2 ;;
        --interval) INTERVAL="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--variant prod|virtualmin] [--env-file FILE] [--project NAME] [--timeout SEC] [--interval SEC]"
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
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="/etc/rev2square/.env.virtualmin"
        EXPECTED_SERVICES="app nginx db queue scheduler reverb analysis-coordinator"
        ;;
    *) echo "Unknown variant: $VARIANT (use prod or virtualmin)" >&2; exit 2 ;;
esac

case "$TIMEOUT" in
    ''|*[!0-9]*) echo "FAIL: --timeout must be a positive integer (got: $TIMEOUT)" >&2; exit 2 ;;
esac
case "$INTERVAL" in
    ''|*[!0-9]*) echo "FAIL: --interval must be a positive integer (got: $INTERVAL)" >&2; exit 2 ;;
esac
if [ "$TIMEOUT" -le 0 ] || [ "$INTERVAL" -le 0 ]; then
    echo "FAIL: --timeout and --interval must be positive (got: $TIMEOUT/$INTERVAL)" >&2
    exit 2
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "FAIL: env file not found: $ENV_FILE" >&2
    exit 2
fi
if [ ! -f "$COMPOSE_FILE" ]; then
    echo "FAIL: compose file not found: $COMPOSE_FILE" >&2
    exit 2
fi

# compose runs docker compose with the variant's fixed flags. A function,
# not a string: quoted expansions keep paths containing spaces as
# single arguments. No eval.
compose() {
    if [ -n "$PROJECT" ]; then
        docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" -p "$PROJECT" "$@"
    else
        docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
    fi
}

# Preflight: a broken compose setup must fail now, not burn the timeout.
if ! PS_OUT=$(compose ps --format '{{.Service}} {{.State}} {{.Health}}' 2>&1); then
    echo "FAIL: 'compose ps' failed (daemon down or bad compose config):" >&2
    echo "$PS_OUT" | head -10 >&2
    exit 2
fi

# service_field <ps-output> <service> <field>: $2=State, $3=Health.
service_field() {
    printf '%s\n' "$1" | awk -v s="$2" -v f="$3" '$1 == s { print $f }' | head -1
}

report_logs() {
    # report_logs <service>: bounded logs for one failed service.
    echo "--- logs: $1 (last 50 lines) ---"
    compose logs --tail=50 "$1" 2>&1 | tail -50
}

deadline=$(( $(date +%s) + TIMEOUT ))
while :; do
    PS_OUT=$(compose ps --format '{{.Service}} {{.State}} {{.Health}}' 2>&1) || PS_OUT=""
    READY=0
    TOTAL=0
    UNREADY=""
    FAILED_SVC=""
    for SVC in $EXPECTED_SERVICES; do
        TOTAL=$((TOTAL + 1))
        STATE=$(service_field "$PS_OUT" "$SVC" 2)
        HEALTH=$(service_field "$PS_OUT" "$SVC" 3)
        if [ "$STATE" = "running" ] && [ "$HEALTH" = "healthy" ]; then
            READY=$((READY + 1))
        else
            UNREADY="$UNREADY $SVC($STATE/$HEALTH)"
            if [ "$STATE" = "exited" ] || [ "$STATE" = "dead" ]; then
                FAILED_SVC="$SVC"
            fi
        fi
    done

    if [ "$READY" -eq "$TOTAL" ]; then
        echo "PASS: all $TOTAL services running+healthy ($VARIANT)"
        exit 0
    fi

    # A dead service cannot become ready by waiting: fail fast with logs.
    if [ -n "$FAILED_SVC" ]; then
        echo "FAIL: service '$FAILED_SVC' is dead (not starting):" >&2
        echo "$PS_OUT" >&2
        report_logs "$FAILED_SVC" >&2
        exit 1
    fi

    NOW=$(date +%s)
    if [ "$NOW" -ge "$deadline" ]; then
        echo "FAIL: $READY/$TOTAL services ready after ${TIMEOUT}s; unready:$UNREADY" >&2
        echo "--- compose ps ---" >&2
        echo "$PS_OUT" >&2
        for SVC in $EXPECTED_SERVICES; do
            STATE=$(service_field "$PS_OUT" "$SVC" 2)
            HEALTH=$(service_field "$PS_OUT" "$SVC" 3)
            if [ "$STATE" != "running" ] || [ "$HEALTH" != "healthy" ]; then
                report_logs "$SVC" >&2
            fi
        done
        exit 1
    fi

    echo "WAIT: $READY/$TOTAL ready; unready:$UNREADY (deadline in $((deadline - NOW))s)"
    sleep "$INTERVAL"
done
