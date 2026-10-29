#!/bin/sh
# Regression test: the nginx image must serve public assets even when the
# source checkout was created under umask 077 (files 600, dirs 700).
#
# Builds the REAL docker/nginx/Dockerfile.prod against a poisoned copy of
# its build context, then verifies as the unprivileged nginx worker user
# (never root alone): deterministic 755/644 modes, readability/traversal,
# SVG markers, and HTTP serving. Covers hand-authored SVGs and generated
# Vite assets (build/manifest.json plus hashed output).
#
# --no-cache is used ONLY here so the poisoned modes are forced into the
# COPY layers (COPY cache keys on content, so a cached COPY could smuggle
# in good modes and make this test vacuous). The Dockerfile fix itself is
# cache-safe: the normalizing RUN layer applies over any cached COPY.
#
# Requires: docker CLI + running daemon + network for npm ci (several
# minutes). Safe: temp context + test tag only; the working tree and any
# running stack are untouched.
#
# Usage:
#   tests/docker/check-nginx-public-permissions.sh [--port PORT] [--keep-image]

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
DOCKERFILE="$ROOT/docker/nginx/Dockerfile.prod"
TAG="rev2square/nginx:perms-test"
PORT="18081"
KEEP_IMAGE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --port) PORT="$2"; shift 2 ;;
        --keep-image) KEEP_IMAGE=1; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--port PORT] [--keep-image]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

FAIL=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

if ! command -v docker >/dev/null 2>&1; then
    echo "FAIL: docker CLI not found; cannot run this test here" >&2
    exit 2
fi
if ! docker info >/dev/null 2>&1; then
    echo "FAIL: docker daemon unreachable; cannot run this test here" >&2
    exit 2
fi

# Fail fast if the Dockerfile's build inputs moved.
for NEED in package.json package-lock.json vite.config.js resources public \
    docker/nginx/default.prod.conf docker/nginx/Dockerfile.prod \
    docker/ops/check-static-assets.sh; do
    if [ ! -e "$ROOT/$NEED" ]; then
        echo "FAIL: missing build input: $NEED" >&2
        exit 2
    fi
done

CTX=$(mktemp -d) || { echo "FAIL: cannot create temp dir" >&2; exit 2; }
CID=""
cleanup() {
    if [ -n "$CID" ]; then
        docker rm -f "$CID" >/dev/null 2>&1 || true
    fi
    rm -rf "$CTX"
}
trap cleanup EXIT INT TERM

# Mirror the Dockerfile's context layout, including .dockerignore so the
# test build excludes exactly what production builds exclude.
mkdir -p "$CTX/docker/nginx"
cp "$ROOT/package.json" "$ROOT/package-lock.json" "$ROOT/vite.config.js" "$ROOT/.dockerignore" "$CTX/"
cp -r "$ROOT/resources" "$CTX/resources"
cp -r "$ROOT/public" "$CTX/public"
cp "$ROOT/docker/nginx/default.prod.conf" "$CTX/docker/nginx/default.prod.conf"

# Simulate a umask-077 checkout: every file 600, every directory 700.
find "$CTX" -type d -exec chmod 700 {} +
find "$CTX" -type f -exec chmod 600 {} +
if [ -n "$(find "$CTX/public" -type f ! -perm 600 -print | head -1)" ] \
    || [ -n "$(find "$CTX/public" -type d ! -perm 700 -print | head -1)" ]; then
    echo "FAIL: poisoned-context setup broken (modes not 600/700)" >&2
    exit 2
fi
pass "poisoned context staged (public files 600, dirs 700)"

if ! docker build --no-cache -f "$DOCKERFILE" -t "$TAG" "$CTX"; then
    echo "FAIL: docker build of $DOCKERFILE failed" >&2
    exit 1
fi
pass "image built: $TAG"

# 1. Mode audit (root view: necessary but NOT sufficient proof).
BAD_MODES=$(docker run --rm --entrypoint sh "$TAG" -c \
    'find /var/www/public -type d ! -perm 755 -print; find /var/www/public -type f ! -perm 644 -print' 2>&1)
if [ -n "$BAD_MODES" ]; then
    fail "non-normalized modes in image:"
    echo "$BAD_MODES" | head -20
else
    pass "image modes deterministic (dirs 755, files 644)"
fi

# 2. Read/traverse proof AS the nginx worker user.
WHO=$(docker run --rm --user nginx --entrypoint sh "$TAG" -c 'id -u -n' 2>&1)
if [ "$WHO" != "nginx" ]; then
    fail "unprivileged probe did not run as nginx (got: $WHO)"
else
    pass "readability probe runs as user 'nginx'"
    UNREADABLE=$(docker run --rm --user nginx --entrypoint sh "$TAG" -c \
        'find /var/www/public -type d ! -executable -print; find /var/www/public -type f ! -readable -print' 2>&1)
    if [ -n "$UNREADABLE" ]; then
        fail "paths unreadable/untraversable by nginx:"
        echo "$UNREADABLE" | head -20
    else
        pass "every public path readable/traversable by nginx"
    fi
    if docker run --rm --user nginx --entrypoint sh "$TAG" -c \
        'grep -q "heroBoardTitle" /var/www/public/images/hero-board.svg \
        && grep -q "faviconTitle" /var/www/public/favicon.svg \
        && grep -q "resources/js/app.js" /var/www/public/build/manifest.json' 2>&1; then
        pass "nginx user reads SVG markers + generated manifest.json"
    else
        fail "nginx user cannot read expected asset content"
    fi
fi

# 3. Static serving over HTTP (master root + worker nginx: production shape).
if ! CID=$(docker run -d --rm -p "127.0.0.1:$PORT:80" "$TAG" 2>&1); then
    fail "cannot start test container on 127.0.0.1:$PORT: $CID"
    CID=""
else
    i=0
    while [ "$i" -lt 30 ]; do
        if curl -s -o /dev/null "http://127.0.0.1:$PORT/favicon.svg" 2>/dev/null; then
            break
        fi
        i=$((i + 1))
        sleep 1
    done
    if sh "$ROOT/docker/ops/check-static-assets.sh" --url "http://127.0.0.1:$PORT"; then
        pass "HTTP serving check passed"
    else
        fail "HTTP serving check failed"
    fi
fi

# Stop the scratch container before the final image cleanup below, so
# `docker rmi` on success is not blocked by a running container (the EXIT
# trap stays as the backstop for failures).
if [ -n "$CID" ]; then
    docker rm -f "$CID" >/dev/null 2>&1 || true
    CID=""
fi

if [ "$FAIL" -eq 0 ]; then
    pass "nginx public-permission regression test passed"
    if [ "$KEEP_IMAGE" -eq 0 ]; then
        docker rmi "$TAG" >/dev/null 2>&1 || true
    else
        echo "kept image $TAG (--keep-image)"
    fi
else
    echo "kept image $TAG for inspection (docker rmi $TAG to remove)" >&2
fi

exit $FAIL
