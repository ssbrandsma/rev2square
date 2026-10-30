#!/bin/sh
# Rev2Square shared-storage integration verification (disposable QA stack).
#
# Proves, on throwaway infrastructure, what Laravel fake-storage tests
# cannot: private user data written in one container is visible in the
# others and survives container recreation.
#
# Phases: (A) cross-container read/write as www-data with private modes,
# (B) real HTTP upload -> extraction by the separate queue worker ->
# viewer page + reload with SHA-256 intact, (C) retention through
# `up -d --force-recreate` of app+queue, (D) mount audit of the three
# authorized services.
#
# Safety: this script ONLY manages the disposable project `rev2storageqa`
# (own prefixed volumes, generated throwaway secrets, loopback port
# 18081). It refuses production project names and env files, never
# touches `rev2square-virtualmin` / `rev2square-prod`, and its trap
# removes ONLY the QA project's own containers/volumes (unless --keep).
# The committed compose file points service env_file at the server path
# /etc/rev2square/.env.virtualmin, so the script runs a sed-rewritten
# COPY with that path swapped to its generated env (byte-identical
# otherwise, asserted below) plus --project-directory for build
# contexts; phase 0 then proves the QA env (not /etc) is loaded.
# Analysis-runner images are ARM64-only, so full analysis E2E is out of
# scope on amd64; the coordinator mount is proven at container level.
#
# Usage: docker/ops/verify-shared-storage.sh [--keep]
# Requires: docker (compose v2), openssl, curl. Minute-scale runtime.

set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
QA_PROJECT="rev2storageqa"
QA_PORT="18081"
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --keep) KEEP=1; shift ;;
        -h|--help) sed -n '2,/^$/p' "$0"; exit 0 ;;
        *) echo "Unknown argument: $1 (only --keep)" >&2; exit 2 ;;
    esac
done

case "$QA_PROJECT" in
    rev2square-virtualmin|rev2square-prod|"") echo "Refusing unsafe project name" >&2; exit 2 ;;
esac

for cmd in docker openssl curl; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 2; }
done

if curl -fsS -o /dev/null --max-time 3 "http://127.0.0.1:${QA_PORT}/up" 2>/dev/null; then
    echo "Port ${QA_PORT} already answers; refusing to clobber it." >&2; exit 2
fi

TMPDIR=$(mktemp -d -t rev2storageqa.XXXXXX)
ENV_FILE="$TMPDIR/qa.env"
COOKIES="$TMPDIR/cookies.txt"
FIXTURE="$TMPDIR/qa-share.elf"
ARCH=$(uname -m | sed -e 's/x86_64/amd64/' -e 's/aarch64/arm64/')

cleanup() {
    if [ "$KEEP" -eq 1 ]; then
        echo "Kept QA stack (project ${QA_PROJECT}) and $TMPDIR for inspection."
        return 0
    fi
    # Disposable project only: generated data, never production volumes.
    docker compose -f "$ROOT/compose.virtualmin.yaml" --env-file "$ENV_FILE" \
        -p "$QA_PROJECT" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMPDIR"
}
trap cleanup EXIT INT TERM

QA_COMPOSE="$TMPDIR/qa-compose.virtualmin.yaml"
sed "s|/etc/rev2square/.env.virtualmin|$ENV_FILE|g" "$ROOT/compose.virtualmin.yaml" > "$QA_COMPOSE"
# (delta assertions run below, after fail() is defined)

dc() {
    docker compose --project-directory "$ROOT" -f "$QA_COMPOSE" --env-file "$ENV_FILE" -p "$QA_PROJECT" "$@"
}

fail() { echo "FAIL: $1" >&2; exit 1; }
pass() { echo "PASS: $1"; }

[ "$(grep -cF "path: $ENV_FILE" "$QA_COMPOSE")" -eq 3 ] || fail "compose copy env rewrite"
if grep -qF "/etc/rev2square" "$QA_COMPOSE"; then fail "compose copy still references /etc"; fi

# Synthetic 4 KiB ELF fixture (deterministic; no firmware needed here).
printf '\177ELF\002\001\001\000' > "$FIXTURE"
dd if=/dev/zero of="$FIXTURE" bs=1 count=$((4096 - 8)) seek=8 conv=notrunc status=none
FIXTURE_SHA=$(sha256sum "$FIXTURE" | cut -d' ' -f1)

# Phase-0 marker: a random value that exists ONLY in the generated QA env;
# reading it back from a running container proves the sed-rewritten compose
# copy loaded the QA env rather than the server path /etc/rev2square/.
QA_MARKER="qa-marker-$(openssl rand -hex 8)"

DB_PASSWORD=$(openssl rand -base64 32)
DB_ROOT_PASSWORD=$(openssl rand -base64 32)
cat > "$ENV_FILE" <<EOF
APP_NAME=Rev2SquareQA
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://127.0.0.1:${QA_PORT}
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=rev2square
DB_USERNAME=rev2square
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
AI_PROVIDER=mock
TRUSTED_PROXIES=PRIVATE_SUBNETS
APP_LOOPBACK_PORT=${QA_PORT}
DOCKER_PLATFORM=linux/${ARCH}
QA_ENV_MARKER=${QA_MARKER}
EOF
chmod 600 "$ENV_FILE"

echo "Building app+nginx images (runners excluded)..."
dc build app nginx >/dev/null || fail "image build"
APP_KEY=$(dc run --rm app php artisan key:generate --show | tr -d '\r\n') || fail "key generation"
printf 'APP_KEY=%s\n' "$APP_KEY" >> "$ENV_FILE"

echo "Starting disposable stack (db, app, nginx, queue, coordinator)..."
dc up -d db app nginx queue analysis-coordinator >/dev/null || fail "stack up"
i=0
until dc exec -T db healthcheck.sh --connect >/dev/null 2>&1; do
    i=$((i + 1))
    [ "$i" -ge 40 ] && fail "database never became ready"
    sleep 3
done
dc exec -T app php artisan migrate --force >/dev/null || fail "migrate"

BASE="http://127.0.0.1:${QA_PORT}"
i=0
until curl -fsS -o /dev/null --max-time 5 "$BASE/up" 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -ge 60 ] && fail "stack never became healthy"
    sleep 3
done

# --- Phase 0: env isolation (generated QA env loaded, not /etc) ----------
got_marker=$(dc exec -T app sh -c 'echo "$QA_ENV_MARKER"' | tr -d '\r\n') || fail "marker read via app"
[ "$got_marker" = "$QA_MARKER" ] || fail "QA env marker mismatch (wrong env file?)"
got_appurl=$(dc exec -T app sh -c 'echo "$APP_URL"' | tr -d '\r\n') || fail "APP_URL read via app"
[ "$got_appurl" = "http://127.0.0.1:${QA_PORT}" ] || fail "APP_URL mismatch ($got_appurl)"
pass "phase 0: QA env loaded (marker + APP_URL :${QA_PORT})"

# --- Phase A: cross-container sharing as the runtime user -----------------
PROBE="artifacts/_qa_probe_$$.bin"
dc exec -T app sh -c "echo qa-probe-$$ > /var/www/storage/app/$PROBE" || fail "probe write via app"
for svc in queue analysis-coordinator; do
    got=$(dc exec -T "$svc" cat "/var/www/storage/app/$PROBE" | tr -d '\r\n') || fail "probe read via $svc"
    [ "$got" = "qa-probe-$$" ] || fail "probe content mismatch via $svc"
done
mode=$(dc exec -T app stat -c '%a %u' "/var/www/storage/app/$PROBE" | tr -d '\r') || fail "probe stat"
set -- $mode
[ $((0$1 & 002)) -eq 0 ] || fail "probe is world-writable ($1)"
[ "$2" = "33" ] || fail "probe not www-data-owned ($2)"
pass "A: app write visible to queue+coordinator as www-data, mode $1 uid $2"
dc exec -T app rm "/var/www/storage/app/$PROBE"

# --- Phase B: HTTP upload -> separate-worker extraction -> viewer --------
csrf() { # $1 = page path -> prints session CSRF token
    curl -fsS -c "$COOKIES" -b "$COOKIES" "$BASE$1" | grep -o 'name="_token"[^>]*value="[^"]*"' | head -n 1 | sed 's/.*value="//;s/"$//'
}

TOKEN=$(csrf "/register") || fail "register page"
[ -n "$TOKEN" ] || fail "CSRF token missing"
code=$(curl -sS -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "name=Storage QA" \
    --data-urlencode "email=qa-storage@example.com" --data-urlencode "password=qa-Storage-12345" \
    --data-urlencode "password_confirmation=qa-Storage-12345" "$BASE/register") || fail "register POST"
[ "$code" = "302" ] || fail "register returned $code"
pass "B1: registered QA user over HTTP"

TOKEN=$(csrf "/projects/create") || fail "project form"
[ -n "$TOKEN" ] || fail "CSRF token missing"
location=$(curl -sS -o /dev/null -D - -c "$COOKIES" -b "$COOKIES" \
    --data-urlencode "_token=$TOKEN" --data-urlencode "title=Storage QA" \
    --data-urlencode "phase=RESEARCH_FEASIBILITY" --data-urlencode "activity=ACTIVE" \
    --data-urlencode "publication_status=DRAFT" "$BASE/projects" | grep -i '^location:' | tail -n 1 | tr -d '\r') || fail "project POST"
SLUG=$(printf '%s' "$location" | sed 's#.*/projects/##;s#/$##')
[ -n "$SLUG" ] || fail "project slug missing ($location)"
pass "B2: created project $SLUG over HTTP"

TOKEN=$(csrf "/projects/$SLUG/agent") || fail "agent page"
[ -n "$TOKEN" ] || fail "CSRF token missing"
code=$(curl -sS -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" \
    -F "_token=$TOKEN" -F "files[]=@$FIXTURE;type=application/octet-stream" \
    "$BASE/projects/$SLUG/artifacts") || fail "upload POST"
[ "$code" = "302" ] || fail "upload returned $code"
pass "B3: uploaded fixture over HTTP (302)"

ART_ID=$(dc exec -T app php artisan tinker --execute="echo \App\Models\ProjectArtifact::latest(\"id\")->firstOrFail()->id;" | tr -d '\r\n ') || fail "artifact id lookup"
[ -n "$ART_ID" ] || fail "artifact row missing after upload"
state=""
i=0
until [ "$state" = "ready" ] || [ "$state" = "partial" ]; do
    state=$(dc exec -T app php artisan tinker --execute="echo \App\Models\ProjectArtifact::findOrFail($ART_ID)->state;" | tr -d '\r\n ') || fail "state poll"
    if [ "$state" = "failed" ]; then fail "extraction failed (see laravel.log)"; fi
    i=$((i + 1))
    [ "$i" -ge 40 ] && fail "extraction stuck in $state"
    sleep 3
done
pass "B4: separate queue worker extracted to $state"

SHOW_HTML=$(curl -fsS -b "$COOKIES" "$BASE/projects/$SLUG/artifacts/$ART_ID") || fail "viewer GET"
echo "$SHOW_HTML" | grep -q "$FIXTURE_SHA" || fail "viewer lacks fixture SHA-256"
curl -fsS -b "$COOKIES" "$BASE/projects/$SLUG/artifacts/$ART_ID" | grep -q "$FIXTURE_SHA" || fail "viewer reload lost SHA-256"
pass "B5: viewer + reload show intact SHA-256"

dc exec -T app php artisan artifacts:verify-storage || fail "verify-storage before recreate"
pass "B6: artifacts:verify-storage clean"

# --- Phase C: retention through container recreation ----------------------
dc up -d --force-recreate --no-deps app queue >/dev/null || fail "recreate"
i=0
until curl -fsS -o /dev/null --max-time 5 "$BASE/up" 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -ge 60 ] && fail "stack unhealthy after recreate"
    sleep 3
done
dc exec -T app php artisan artifacts:verify-storage || fail "verify-storage after recreate"
curl -fsS -b "$COOKIES" "$BASE/projects/$SLUG/artifacts/$ART_ID" | grep -q "$FIXTURE_SHA" || fail "SHA-256 lost across recreate"
pass "C: bytes + viewer intact across app+queue recreation"

# --- Phase D: mount audit --------------------------------------------------
for svc in app queue analysis-coordinator; do
    dc exec -T "$svc" sh -c "test -d /var/www/storage/app/artifacts && test -w /var/www/storage/app" \
        || fail "$svc lacks writable shared storage/app"
done
running=$(dc ps --services --status running)
if echo "$running" | grep -q scheduler; then fail "scheduler unexpectedly running"; fi
if echo "$running" | grep -q 'runner'; then fail "a runner unexpectedly started"; fi
pass "D: app/queue/coordinator share writable storage/app; scheduler+runners uninvolved"

echo "ALL SHARED-STORAGE CHECKS PASSED"
