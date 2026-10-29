#!/bin/sh
# Rev2Square production static-asset check.
#
# Verifies the hand-authored public SVGs are served correctly by the
# deployed stack. Each asset must pass three gates:
#   1. exact HTTP 200 (redirects are NOT followed, so a login redirect
#      or Laravel fallback route fails the check);
#   2. an image/svg+xml content type (so an HTML error page or Laravel
#      404 page served with 200 fails the check);
#   3. expected markers in the body (so a wrong file or generic 200
#      fails the check).
# Prints status lines only — no response bodies, no credentials.
#
# Usage:
#   docker/ops/check-static-assets.sh [--variant prod|virtualmin] [--env-file FILE] [--url URL] [--insecure]
#
# Variants mirror status.sh: the base URL defaults to APP_URL from the
# variant env file. --url overrides it (e.g. loopback
# http://127.0.0.1:8081 to prove the containers without Apache, or a
# scratch-container port in tests) and then no env file is needed.
# --insecure passes -k to curl; smoke-test only, never for production.
# Exit 0 only if every asset passes.

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VARIANT="prod"
ENV_FILE=""
ENV_SET=0
URL_OVERRIDE=""
CURL_INSECURE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --variant) VARIANT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; ENV_SET=1; shift 2 ;;
        --url) URL_OVERRIDE="$2"; shift 2 ;;
        --insecure) CURL_INSECURE="-k"; shift ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 [--variant prod|virtualmin] [--env-file FILE] [--url URL] [--insecure]"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$VARIANT" in
    prod)
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="$ROOT/.env.production"
        ;;
    virtualmin)
        [ "$ENV_SET" -eq 0 ] && ENV_FILE="$ROOT/.env.virtualmin"
        ;;
    *) echo "Unknown variant: $VARIANT (use prod or virtualmin)" >&2; exit 2 ;;
esac

if [ -n "$URL_OVERRIDE" ]; then
    BASE="$URL_OVERRIDE"
else
    if [ ! -f "$ENV_FILE" ]; then
        echo "FAIL: env file not found: $ENV_FILE" >&2
        exit 2
    fi
    # shellcheck disable=SC1090
    set -a; . "$ENV_FILE"; set +a
    BASE="${APP_URL:?APP_URL missing from $ENV_FILE}"
fi

TMPD=$(mktemp -d) || { echo "FAIL: cannot create temp dir" >&2; exit 2; }
trap 'rm -rf "$TMPD"' EXIT INT TERM

FAIL=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

# check_asset <path> <marker>: fetch one URL and enforce the three gates.
check_asset() {
    APATH="$1"
    MARKER="$2"
    BODY="$TMPD/body"
    HDRS="$TMPD/hdrs"
    rm -f "$BODY" "$HDRS"

    # No -L (a redirect must fail) and no -f (non-2xx must be reported,
    # not masked). Replies are bounded: SVGs are a few KB.
    # shellcheck disable=SC2086
    CODE=$(curl -sS -m 15 $CURL_INSECURE --max-filesize 100000 \
        -o "$BODY" -D "$HDRS" -w '%{http_code}' "$BASE$APATH" 2>"$TMPD/err") \
        || { fail "$APATH unreachable: $(head -c 200 "$TMPD/err" | tr '\n' ' ')"; return; }
    if [ "$CODE" != "200" ]; then
        fail "$APATH: HTTP $CODE, want 200"
        return
    fi

    CTYPE=$(grep -i '^content-type:' "$HDRS" 2>/dev/null | tail -1 \
        | tr -d '\r' | sed 's/^[^:]*:[[:space:]]*//; s/[[:space:]]*$//')
    CTYPE_NORM=$(printf '%s' "$CTYPE" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')
    case "$CTYPE_NORM" in
        image/svg+xml|image/svg+xml\;*)
            ;;
        *)
            fail "$APATH: content-type '$CTYPE', want image/svg+xml"
            return
            ;;
    esac

    if ! grep -q '<svg' "$BODY" 2>/dev/null || ! grep -q "$MARKER" "$BODY" 2>/dev/null; then
        fail "$APATH: body lacks SVG marker '$MARKER'"
        return
    fi

    SIZE=$(wc -c < "$BODY" | tr -d '[:space:]')
    pass "$APATH: HTTP 200, $CTYPE, ${SIZE} bytes, marker '$MARKER' present"
}

check_asset "/images/hero-board.svg" "heroBoardTitle"
check_asset "/favicon.svg" "faviconTitle"

exit $FAIL
