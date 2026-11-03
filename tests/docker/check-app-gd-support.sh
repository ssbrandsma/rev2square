#!/bin/sh
# Regression test: the production PHP image must ship GD with JPEG/PNG
# support. The queue worker refuses image jobs without
# imagecreatefromstring() (every cover upload then fails with "Image
# processing is temporarily unavailable"), and avatar uploads 500.
# Prod once lost GD while dev kept it, so this pins BOTH Dockerfiles
# and then proves the REAL built image decodes/encodes as the
# www-data runtime user (never root alone).
#
# Requires: docker CLI + running daemon + network for the image build
# (several minutes). Safe: temp tag only; the working tree and any
# running stack are untouched.
#
# Usage:
#   tests/docker/check-app-gd-support.sh [--keep-image]

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PROD_DOCKERFILE="$ROOT/docker/php/Dockerfile.prod"
DEV_DOCKERFILE="$ROOT/docker/php/Dockerfile"
TAG="rev2square/app:gd-test"
KEEP_IMAGE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --keep-image) KEEP_IMAGE=1; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--keep-image]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

FAIL=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

echo "--- Dockerfile pins ---"

if grep -q -- 'docker-php-ext-configure gd --with-jpeg' "$PROD_DOCKERFILE" \
    && grep -q -- 'libpng-dev' "$PROD_DOCKERFILE" \
    && grep -q -- 'libjpeg-dev' "$PROD_DOCKERFILE" \
    && grep -q -- 'dom gd' "$PROD_DOCKERFILE"; then
    pass "Dockerfile.prod installs gd with jpeg support"
else
    fail "Dockerfile.prod must install gd with jpeg support (configure + libpng/libjpeg + gd)"
fi

if grep -q -- 'docker-php-ext-configure gd --with-jpeg' "$DEV_DOCKERFILE" \
    && grep -q -- ' dom gd' "$DEV_DOCKERFILE"; then
    pass "dev Dockerfile keeps gd (parity pin)"
else
    fail "dev Dockerfile must keep gd (parity pin)"
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "FAIL: docker CLI not found; cannot build-verify here" >&2
    exit 2
fi
if ! docker info >/dev/null 2>&1; then
    echo "FAIL: docker daemon unreachable; cannot build-verify here" >&2
    exit 2
fi

cleanup() {
    if [ "$KEEP_IMAGE" -eq 0 ]; then
        docker rmi -f "$TAG" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT INT TERM

echo "--- image build ---"
echo "(building $TAG; several minutes)"
if docker build -f "$PROD_DOCKERFILE" -t "$TAG" "$ROOT" >/tmp/app-gd-build.log 2>&1; then
    pass "production app image builds"
else
    fail "production app image builds (see /tmp/app-gd-build.log)"
fi

if [ "$FAIL" -ne 0 ]; then
    echo "== app GD checks FAILED ==" >&2
    exit "$FAIL"
fi

echo "--- runtime GD probes (www-data) ---"

if docker run --rm --entrypoint php "$TAG" -r 'exit(function_exists("imagecreatefromstring") ? 0 : 1);' >/dev/null 2>&1; then
    pass "imagecreatefromstring exists"
else
    fail "imagecreatefromstring exists"
fi

if docker run --rm --entrypoint php "$TAG" -r '$i = gd_info(); exit(!empty($i["JPEG Support"]) && !empty($i["PNG Support"]) ? 0 : 1);' >/dev/null 2>&1; then
    pass "gd_info reports JPEG+PNG support"
else
    fail "gd_info reports JPEG+PNG support"
fi

if docker run --rm --entrypoint php "$TAG" -r '$c = imagecreatetruecolor(8, 8); ob_start(); $ok = imagejpeg($c, null, 75); $b = (string) ob_get_clean(); $d = ($ok && $b !== "") ? @imagecreatefromstring($b) : false; exit($d instanceof GdImage ? 0 : 1);' >/dev/null 2>&1; then
    pass "JPEG encode/decode roundtrip works"
else
    fail "JPEG encode/decode roundtrip works"
fi

if docker run --rm --entrypoint php "$TAG" -r '$c = imagecreatetruecolor(8, 8); ob_start(); $ok = imagepng($c); $b = (string) ob_get_clean(); $d = ($ok && $b !== "") ? @imagecreatefromstring($b) : false; exit($d instanceof GdImage ? 0 : 1);' >/dev/null 2>&1; then
    pass "PNG encode/decode roundtrip works"
else
    fail "PNG encode/decode roundtrip works"
fi

WEBP=$(docker run --rm --entrypoint php "$TAG" -r '$i = gd_info(); echo !empty($i["WebP Support"]) ? "yes" : "no";' 2>/dev/null)
echo "INFO: image WebP Support=$WEBP (informational; image/webp is advertised but unsupported)"

if [ "$FAIL" -eq 0 ]; then
    echo "== all app GD checks passed =="
else
    echo "== app GD checks FAILED ==" >&2
fi
exit "$FAIL"
