#!/bin/sh
# Rev2Square production updater (Virtualmin variant).
#
# Reviewed replacement for the owner-managed untracked update_prod.sh.
# Deploys the current checkout: backup -> drain analyses -> build ->
# migrate -> recreate -> readiness -> HTTP checks -> realtime checks.
# Prints no credentials.
#
# What it reconciles: app, nginx, queue, scheduler, reverb, and
# analysis-coordinator are rebuilt/recreated from the current checkout.
# Explicitly untouched: db (data), and the analysis runners (rebuild
# only via --runners; see below).
#
# Analysis safety: recreating the coordinator while a job runs kills the
# job (SIGKILL after the grace period; the job then fails visibly). So
# the updater waits, boundedly, for the analysis queue to drain BEFORE
# touching anything, checks again right before recreating (a job may
# have started during the build), and gives the coordinator a long
# SIGTERM grace period as a backstop. If the bound expires it aborts
# WITHOUT recreating anything: safe to re-run once analyses finish.
# --force skips the drain waits (a running analysis will be killed).
#
# Realtime: the public-key build-arg bakes VITE_REVERB_APP_KEY into the
# frontend assets (the key is public by Pusher-protocol design: every
# browser receives it as the /app/<key> URL path). The app SECRET never
# enters images. After the HTTP checks pass, the updater probes the realtime
# PROTOCOL separately through nginx (loopback) and through Apache
# (public URL) via check-realtime.sh: each sends the real browser Origin
# and requires pusher:connection_established, so a failure pinpoints
# its layer AND its cause (a bare 101 would prove nothing).
#
# Usage (on the server, as root, from /srv/rev2square):
#   git fetch origin && git checkout <new-release-sha>
#   ./docker/ops/update_prod.sh [--env-file FILE] [--project NAME]
#       [--runners svc,...] [--drain-timeout SEC] [--force]
#
# --runners rebuilds and recreates named analysis runners AFTER the main
# stack verifies (comma-separated subset of: objdump-runner,
# ghidra-runner, jadx-runner, ilspy-runner, extract-runner). Default:
# runners are left alone (their images change rarely and rebuilds are
# slow, especially ghidra).
# --drain-timeout bounds each analysis-drain wait (default 900s: covers
# one max-length 800s job plus margin).
# --force skips both drain waits AND the graceful coordinator stop.
# --env-file/--project override the variant defaults (same convention
# as status.sh / wait-for-healthy.sh / backup.sh).
#
# Exit 0: stack updated, HTTP healthy. A WARN about the PUBLIC realtime
# probe still exits 0 (Apache WSS is an owner step that may follow the
# container deploy; see docs/DEPLOYMENT_VIRTUALMIN_ARM64.md section 7),
# but the final status line then reads NOT fully verified: only a public
# PASS yields FULLY VERIFIED. Exit 1: deploy failed or the INTERNAL
# realtime path is broken.
# Exit 2: usage/env/preflight errors, or the drain bound expired (nothing
# recreated; re-run later, or --force to override).

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
umask 077
set -eu

ENV_FILE="/etc/rev2square/.env.virtualmin"
ENV_SET=0
PROJECT=""
RUNNERS=""
DRAIN_TIMEOUT="900"
FORCE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --runners) RUNNERS="$2"; shift 2 ;;
        --drain-timeout) DRAIN_TIMEOUT="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--env-file FILE] [--project NAME] [--runners svc,...] [--drain-timeout SEC] [--force]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$DRAIN_TIMEOUT" in
    ''|*[!0-9]*) echo "FAIL: --drain-timeout must be a positive integer (got: $DRAIN_TIMEOUT)" >&2; exit 2 ;;
esac
if [ "$DRAIN_TIMEOUT" -le 0 ]; then
    echo "FAIL: --drain-timeout must be positive (got: $DRAIN_TIMEOUT)" >&2
    exit 2
fi

COMPOSE_FILE="$ROOT/compose.virtualmin.yaml"
BACKUP_SH="$ROOT/docker/backup/backup.sh"
WAIT_SH="$ROOT/docker/ops/wait-for-healthy.sh"
CHECK_SH="$ROOT/docker/ops/check-realtime.sh"

step() { echo "== $1 =="; }
pass() { echo "PASS: $1"; }
warn() { echo "WARN: $1"; }
fail() { echo "FAIL: $1"; }

# --- preflight -----------------------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    echo "FAIL: env file not found: $ENV_FILE" >&2
    exit 2
fi
if [ ! -f "$COMPOSE_FILE" ]; then
    echo "FAIL: compose file not found: $COMPOSE_FILE" >&2
    exit 2
fi
for HELPER in "$BACKUP_SH" "$WAIT_SH" "$CHECK_SH"; do
    if [ ! -x "$HELPER" ]; then
        echo "FAIL: helper missing or not executable: $HELPER" >&2
        exit 2
    fi
done

# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

if [ -n "$PROJECT" ]; then
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE -p $PROJECT"
else
    COMPOSE="docker compose -f $COMPOSE_FILE --env-file $ENV_FILE"
fi

# The public key is baked into frontend assets at build time and probed
# after deploy; an empty/placeholder key would ship broken realtime.
case "${REVERB_APP_KEY:-}" in
    ""|*"CHANGE-ME"*)
        echo "FAIL: REVERB_APP_KEY is empty or a placeholder in $ENV_FILE (set a real public key first)" >&2
        exit 2
        ;;
esac
: "${APP_URL:?APP_URL missing from $ENV_FILE}"
APP_LOOPBACK_PORT="${APP_LOOPBACK_PORT:-8081}"

SHA="(unknown)"
if git -C "$ROOT" rev-parse --short HEAD >/dev/null 2>&1; then
    SHA=$(git -C "$ROOT" rev-parse --short HEAD)
fi
echo "== Rev2Square update ($SHA, $(date -u +%Y-%m-%dT%H:%M:%SZ)) =="

# shellcheck disable=SC2086
if ! $COMPOSE ps >/dev/null 2>&1; then
    echo "FAIL: 'compose ps' failed (daemon down or bad compose config)" >&2
    exit 2
fi

service_running() {
    # service_running <service>: true when a container is in State=running.
    # shellcheck disable=SC2086
    $COMPOSE ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -q "^$1 running"
}

analysis_depth() {
    # analysis_depth: prints waiting+running analysis-job count, or nothing
    # on query failure. Counts every row on the 'analysis' queue (same
    # queue the coordinator serves with --queue=analysis): waiting rows
    # are safe across recreation (they are just DB rows), but a nonzero
    # count also covers a RUNNING job, which must finish first.
    : "${DB_DATABASE:?DB_DATABASE missing from $ENV_FILE}"
    : "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD missing from $ENV_FILE}"
    TABLE="${DB_QUEUE_TABLE:-jobs}"
    # shellcheck disable=SC2086
    $COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
        mariadb -uroot -N -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.\`$TABLE\` WHERE queue='analysis';" 2>/dev/null | tr -d '[:space:]'
}

drain_analyses() {
    # drain_analyses <label>: bounded wait for zero analysis jobs.
    # Returns 0 when drained (or when no coordinator runs), 1 on timeout
    # or probe failure. Never recreates anything: safe to abort on.
    if ! service_running analysis-coordinator; then
        echo "drain ($1): no running analysis-coordinator, nothing to wait for"
        return 0
    fi
    deadline=$(( $(date +%s) + DRAIN_TIMEOUT ))
    while :; do
        DEPTH=$(analysis_depth) || DEPTH=""
        case "$DEPTH" in
            ""|*[!0-9]*)
                fail "drain ($1): cannot read analysis queue depth (db down?)"
                echo "  Check the db container, then re-run. Override with --force" >&2
                echo "  (a running analysis would be killed by the recreate)." >&2
                return 1
                ;;
        esac
        if [ "$DEPTH" -eq 0 ]; then
            pass "drain ($1): analysis queue empty, no job running"
            return 0
        fi
        NOW=$(date +%s)
        if [ "$NOW" -ge "$deadline" ]; then
            fail "drain ($1): still $DEPTH analysis job(s) after ${DRAIN_TIMEOUT}s; nothing recreated"
            echo "  Wait for analyses to finish and re-run, raise --drain-timeout, or" >&2
            echo "  --force to deploy now (kills the running analysis; it fails visibly)." >&2
            return 1
        fi
        echo "WAIT: drain ($1): $DEPTH analysis job(s) outstanding (deadline in $((deadline - NOW))s)"
        sleep 15
    done
}

PASSTHRU=""
if [ "$ENV_SET" -eq 1 ]; then
    PASSTHRU="$PASSTHRU --env-file $ENV_FILE"
fi
if [ -n "$PROJECT" ]; then
    PASSTHRU="$PASSTHRU --project $PROJECT"
fi

# --- 1. safety backup (database only) ------------------------------------
step "1/8 safety backup"
# shellcheck disable=SC2086
if ! $BACKUP_SH --variant virtualmin $PASSTHRU; then
    echo "FAIL: safety backup failed; refusing to deploy without one" >&2
    exit 1
fi
echo "NOTE: this backup is DATABASE-ONLY. Private uploads (app-storage"
echo "volume) are NOT included; see section 13 of"
echo "docs/DEPLOYMENT_VIRTUALMIN_ARM64.md for uploads backup/restore."

# --- 2. pre-build drain ---------------------------------------------------
step "2/8 drain analyses (pre-build)"
if [ "$FORCE" -eq 1 ]; then
    warn "--force: skipping pre-build drain (a running analysis will be killed)"
elif ! drain_analyses "pre-build"; then
    exit 2
fi

# --- 3. build ------------------------------------------------------------
step "3/8 build app+nginx"
# shellcheck disable=SC2086
if ! $COMPOSE build --build-arg "VITE_REVERB_APP_KEY=$REVERB_APP_KEY" app nginx; then
    echo "FAIL: image build failed; nothing recreated" >&2
    exit 1
fi
pass "app+nginx images built (frontend key baked from REVERB_APP_KEY)"

# --- 4. migrate ----------------------------------------------------------
step "4/8 migrate"
# shellcheck disable=SC2086
if ! $COMPOSE run --rm app php artisan migrate --force; then
    echo "FAIL: migrations failed; old containers still running on the" >&2
    echo "previous schema state (re-run after fixing; migrate is idempotent)." >&2
    exit 1
fi
pass "migrations applied"

# --- 5. pre-recreate drain ------------------------------------------------
step "5/8 drain analyses (pre-recreate)"
if [ "$FORCE" -eq 1 ]; then
    warn "--force: skipping pre-recreate drain"
elif ! drain_analyses "pre-recreate"; then
    echo "  Images are built and migrations applied; re-running resumes" >&2
    echo "  cleanly (build cached, migrate no-op). Old containers untouched." >&2
    exit 2
fi

# --- 6. recreate ----------------------------------------------------------
step "6/8 recreate app nginx queue scheduler reverb analysis-coordinator"
if [ "$FORCE" -eq 0 ] && service_running analysis-coordinator; then
    # Backstop for a job that started in the seconds between the drain
    # check and this stop: SIGTERM lets queue:work finish the current
    # job; idle (drained) workers exit at once, so this is instant in
    # the common case and bounded (900s) in the rare race.
    echo "graceful coordinator stop first (up to 900s only if a job runs)..."
    # shellcheck disable=SC2086
    if ! $COMPOSE stop -t 900 analysis-coordinator; then
        echo "FAIL: graceful coordinator stop failed; aborting before recreate" >&2
        exit 1
    fi
fi
# Explicit service list: db keeps its data; runners are rebuilt only
# via --runners (step 8). Never bare `up -d` here.
# shellcheck disable=SC2086
if ! $COMPOSE up -d app nginx queue scheduler reverb analysis-coordinator; then
    echo "FAIL: recreate failed; run wait-for-healthy.sh + status.sh to assess" >&2
    exit 1
fi
pass "services recreated (db + runners untouched)"

# --- 7. readiness + HTTP + realtime ---------------------------------------
step "7/8 readiness"
# shellcheck disable=SC2086
if ! $WAIT_SH --variant virtualmin $PASSTHRU; then
    echo "FAIL: services never became healthy; investigate before use" >&2
    exit 1
fi

step "7/8 HTTP checks"
if curl -fsS -m 15 "http://127.0.0.1:$APP_LOOPBACK_PORT/up" -o /dev/null 2>&1; then
    pass "loopback GET 127.0.0.1:$APP_LOOPBACK_PORT/up returned 2xx"
else
    echo "FAIL: loopback /up failed though containers report healthy" >&2
    exit 1
fi
if curl -fsS -m 20 "$APP_URL/up" -o /dev/null 2>&1; then
    pass "public GET $APP_URL/up returned 2xx"
else
    echo "FAIL: public $APP_URL/up unreachable while loopback works:" >&2
    echo "containers updated fine; the fault is in the Apache/Virtualmin" >&2
    echo "layer (docs section 17), not this deploy." >&2
    exit 1
fi

step "7/8 realtime checks (protocol-level; public non-fatal)"
# Both probes send the real browser Origin ($APP_URL) and require
# pusher:connection_established -- a bare 101 proves nothing (rejected
# keys/origins also complete the handshake).
if "$CHECK_SH" --url "http://127.0.0.1:$APP_LOOPBACK_PORT/app/$REVERB_APP_KEY" --origin "$APP_URL"; then
    pass "internal realtime fully verified (handshake + protocol via nginx)"
else
    echo "FAIL: internal realtime path is broken (see probe output above):" >&2
    echo "reverb is healthy but the nginx /app/ path does not deliver a" >&2
    echo "working protocol session (stale nginx image? key mismatch?)." >&2
    echo "HTTP works; realtime does not." >&2
    exit 1
fi
REALTIME_PUBLIC_OK=0
if "$CHECK_SH" --url "$APP_URL/app/$REVERB_APP_KEY" --origin "$APP_URL"; then
    pass "public realtime fully verified (handshake + protocol via Apache)"
    REALTIME_PUBLIC_OK=1
else
    warn "public realtime NOT verified (internal path fully OK)."
    echo "  If Apache has no /app/ upgrade rule yet, add it per docs section 7" >&2
    echo "  (proxy_wstunnel + ProxyPass upgrade=websocket BEFORE ProxyPass /)," >&2
    echo "  reload httpd, then re-run just this probe:" >&2
    echo "    ./docker/ops/check-realtime.sh --url \"\$APP_URL/app/\$REVERB_APP_KEY\" --origin \"\$APP_URL\"" >&2
    echo "  (with the env file sourced for \$APP_URL/\$REVERB_APP_KEY). If Apache" >&2
    echo "  already has the rule, realtime REGRESSED: investigate before announcing." >&2
fi

# --- 8. optional runners ---------------------------------------------------
if [ -n "$RUNNERS" ]; then
    step "8/8 selective runners ($RUNNERS)"
    VALID="objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner"
    # shellcheck disable=SC2086
    for R in $(echo "$RUNNERS" | tr ',' ' '); do
        HIT=0
        for V in $VALID; do
            if [ "$R" = "$V" ]; then HIT=1; break; fi
        done
        if [ "$HIT" -eq 0 ]; then
            echo "FAIL: unknown runner '$R' (valid: $VALID)" >&2
            exit 2
        fi
        # shellcheck disable=SC2086
        if ! $COMPOSE build "$R"; then
            echo "FAIL: runner build failed: $R (main stack already updated)" >&2
            exit 1
        fi
        # shellcheck disable=SC2086
        if ! $COMPOSE up -d "$R"; then
            echo "FAIL: runner recreate failed: $R (main stack already updated)" >&2
            exit 1
        fi
        pass "runner updated: $R"
    done
else
    echo "== 8/8 runners untouched (pass --runners to rebuild selectively) =="
fi

# --- done ------------------------------------------------------------------
echo "== update complete ($SHA) =="
if [ "${REALTIME_PUBLIC_OK:-0}" -eq 1 ]; then
    echo "Realtime: FULLY VERIFIED (internal + public protocol checks passed)"
else
    echo "Realtime: NOT fully verified (public probe failed): finish the Apache"
    echo "/app/ step, then re-run check-realtime.sh before announcing realtime."
fi
# Audit trail of what is now live (best-effort; :latest tags move).
docker images --digests rev2square/app 2>/dev/null | head -4 || true
docker images --digests rev2square/nginx 2>/dev/null | head -4 || true
# shellcheck disable=SC2086
echo "Next: ./docker/ops/status.sh --variant virtualmin $PASSTHRU"
echo "Deploys drop realtime sockets for seconds; browsers reconnect"
echo "automatically (presence may flap once on the Online page)."
