#!/bin/sh
# Regression test: the jadx image must qualify its DEX fixture as
# www-data even when the source checkout was created under umask 077
# (files 600, dirs 700).
#
# Builds the REAL docker/analysis/Dockerfile.jadx against a poisoned
# copy of its build context, then verifies as the unprivileged
# www-data runtime user (never root alone): deterministic modes,
# traversal of every fixture ancestor, fixture readability + sha256,
# a real decompile with the FixtureSmoketest class marker, and the
# dry-run argv shape. Covers the "File not found as www-data while
# root passes" incident class (inaccessible path, not absent file).
#
# --no-cache is used ONLY here so the poisoned modes are forced into
# the COPY layers (COPY cache keys on content, so a cached COPY could
# smuggle in good modes and make this test vacuous). The Dockerfile
# fix itself is cache-safe: the normalizing RUN layer applies over
# any cached COPY.
#
# Requires: ARM64 host (the build executes the aarch64 JRE), docker
# CLI + running daemon + network for the pinned releases (a few
# minutes). Safe: temp context + test tag only; the working tree and
# any running stack are untouched.
#
# Usage:
#   tests/docker/check-jadx-fixture-permissions.sh [--keep-image]

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SRC="$ROOT/docker/analysis"
TAG="rev2square/jadx:perms-test"
KEEP_IMAGE=0
FIXTURE_SHA="597807fe5c8d9d999ba7ca4292a8707618281463638b7449384d9907e95f8b80"

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

ARCH=$(uname -m)
if [ "$ARCH" != "aarch64" ] && [ "$ARCH" != "arm64" ]; then
    echo "FAIL: ARM64 host required (this host: $ARCH); the image build executes the aarch64 JRE" >&2
    exit 2
fi
if ! command -v docker >/dev/null 2>&1; then
    echo "FAIL: docker CLI not found; cannot run this test here" >&2
    exit 2
fi
if ! docker info >/dev/null 2>&1; then
    echo "FAIL: docker daemon unreachable; cannot run this test here" >&2
    exit 2
fi

# Fail fast if the Dockerfile's build inputs moved.
for NEED in Dockerfile.jadx runner.py fixtures/dex-smoke.dex; do
    if [ ! -e "$SRC/$NEED" ]; then
        echo "FAIL: missing build input: docker/analysis/$NEED" >&2
        exit 2
    fi
done

CTX=$(mktemp -d) || { echo "FAIL: cannot create temp dir" >&2; exit 2; }
cleanup() {
    rm -rf "$CTX"
}
trap cleanup EXIT INT TERM

# Mirror the Dockerfile's context layout (context root = docker/analysis).
mkdir -p "$CTX/fixtures"
cp "$SRC/Dockerfile.jadx" "$SRC/runner.py" "$CTX/"
cp "$SRC/fixtures/dex-smoke.dex" "$CTX/fixtures/dex-smoke.dex"

# Simulate a umask-077 checkout: every file 600, every directory 700.
find "$CTX" -type d -exec chmod 700 {} +
find "$CTX" -type f -exec chmod 600 {} +
if [ -n "$(find "$CTX" -type f ! -perm 600 -print | head -1)" ] \
    || [ -n "$(find "$CTX" -type d ! -perm 700 -print | head -1)" ]; then
    echo "FAIL: poisoned-context setup broken (modes not 600/700)" >&2
    exit 2
fi
pass "poisoned context staged (files 600, dirs 700)"

if ! docker build --no-cache -f "$CTX/Dockerfile.jadx" -t "$TAG" "$CTX"; then
    echo "FAIL: docker build of Dockerfile.jadx failed" >&2
    exit 1
fi
pass "image built: $TAG"

# 1. Mode audit (root view: necessary but NOT sufficient proof).
BAD_MODES=$(docker run --rm --entrypoint sh "$TAG" -c \
    'find /opt/runner -type d ! -perm 755 -print' 2>&1)
if [ -n "$BAD_MODES" ]; then
    fail "non-normalized dirs under /opt/runner:"
    echo "$BAD_MODES" | head -20
else
    pass "runner-tree dirs deterministic (755)"
fi
MODES=$(docker run --rm --entrypoint sh "$TAG" -c \
    'stat -c "%a %n" /opt/runner/runner.py /opt/runner/fixtures/dex-smoke.dex' 2>&1)
if [ "$MODES" != "755 /opt/runner/runner.py
644 /opt/runner/fixtures/dex-smoke.dex" ]; then
    fail "unexpected file modes:"
    echo "$MODES" | head -5
else
    pass "file modes deterministic (runner.py 755, fixture 644)"
fi

# 2. Traversal + readability proof AS the www-data runtime user.
WHO=$(docker run --rm --user www-data --entrypoint sh "$TAG" -c 'id -u -n; id -u' 2>&1)
if [ "$WHO" != "www-data
33" ]; then
    fail "unprivileged probe did not run as www-data/33 (got: $WHO)"
else
    pass "readability probe runs as user 'www-data' (uid 33)"
    if docker run --rm --user www-data --entrypoint sh "$TAG" -c \
        'test -x /opt && test -x /opt/runner && test -x /opt/runner/fixtures \
        && test -r /opt/runner/fixtures/dex-smoke.dex \
        && test -r /opt/runner/runner.py' 2>&1; then
        pass "www-data traverses every ancestor and reads runner + fixture"
    else
        fail "fixture path not traversable/readable by www-data"
    fi
    if docker run --rm --user www-data --entrypoint sh "$TAG" -c \
        "echo '$FIXTURE_SHA  /opt/runner/fixtures/dex-smoke.dex' | sha256sum -c -" 2>&1 \
        | grep -q ': OK$'; then
        pass "www-data verifies the fixture sha256"
    else
        fail "www-data cannot verify the fixture sha256"
    fi
fi

# 3. Real decompile AS www-data through the real runner argv + env,
# with the known class marker (same qualification the build runs).
if docker run --rm --user www-data --entrypoint sh "$TAG" -c \
    'mkdir -p /tmp/fxcheck \
    && python3 -c "import subprocess,sys; sys.path.insert(0,'\''/opt/runner'\''); import runner; argv=runner.build_argv('\''jadx'\'','\''apk'\'',{'\''threads'\'':1},'\''/tmp/fxcheck'\'','\''/opt/runner/fixtures/dex-smoke.dex'\''); subprocess.run(argv,check=True,timeout=300,env=runner.tool_env('\''jadx'\'','\''/tmp/fxcheck'\''))" \
    && grep -rq FixtureSmoketest /tmp/fxcheck/jadx-out' 2>&1; then
    pass "www-data decompiles the fixture with the FixtureSmoketest marker"
else
    fail "www-data decompile qualification failed"
fi

# 4. Dry-run argv shape (verified CLI, no removed flags).
ARGV=$(docker run --rm --user www-data --entrypoint sh "$TAG" -c \
    'python3 /opt/runner/runner.py --dry-run --tool jadx --profile apk --params-json '\''{"threads":2}'\'' --workdir /tmp/drycheck' 2>&1)
if echo "$ARGV" | grep -q -- '"-j"'; then
    pass "dry-run argv carries -j"
else
    fail "dry-run argv lacks -j: $ARGV"
fi
if echo "$ARGV" | grep -q 'no-src-save-cache'; then
    fail "dry-run argv reintroduces a removed flag: $ARGV"
else
    pass "dry-run argv carries no removed flags"
fi

if [ "$FAIL" -eq 0 ]; then
    pass "jadx fixture-permission regression test passed"
    if [ "$KEEP_IMAGE" -eq 0 ]; then
        docker rmi "$TAG" >/dev/null 2>&1 || true
    else
        echo "kept image $TAG (--keep-image)"
    fi
else
    echo "kept image $TAG for inspection (docker rmi $TAG to remove)" >&2
fi

exit $FAIL
