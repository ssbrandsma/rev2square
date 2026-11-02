#!/bin/sh
# Regression test: realtime deployment verification must be protocol-deep.
#
# Section 1 pins the check-realtime.sh classifier: canned transcripts
# (marker substrings, as --from-file fixtures) must yield the exact
# verdicts — connection_established passes, while 4001 (unknown app
# key), 4009 (rejected origin), 101-without-frames, HTTP errors, and
# empty captures all fail with the right diagnosis. Binary framing
# tolerance (server frames are unmasked JSON) is proven by live runs
# against real Reverb, not here: these fixtures assert verdict logic.
#
# Section 2 pins the Apache snippet rule order: the specific
# `ProxyPass /app/` rule must precede the catch-all `ProxyPass /`,
# because Apache uses the FIRST matching rule (no longest-prefix).
# Re-sorting them would proxy sockets as plain HTTP and silently
# break realtime.
#
# Requires: POSIX sh + grep only. No docker, no network, no stack.
# Safe: temp files only; the working tree is untouched.
#
# Usage:
#   tests/docker/check-realtime-verification.sh

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PROBE="$ROOT/docker/ops/check-realtime.sh"
SNIPPET="$ROOT/docker/virtualmin/apache-vhost-snippet.conf"

while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

FAIL=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

TMPD=$(mktemp -d) || { echo "FAIL: cannot create temp dir" >&2; exit 2; }
trap 'rm -rf "$TMPD"' EXIT INT TERM

# expect <name> <want-exit> <want-substring> <fixture-file>
expect() {
    OUT=$("$PROBE" --from-file "$4" 2>&1)
    CODE=$?
    if [ "$CODE" -ne "$2" ]; then
        fail "$1: exit $CODE, want $2 (output: $OUT)"
        return
    fi
    case "$OUT" in
        *"$3"*) pass "$1" ;;
        *) fail "$1: missing '$3' (output: $OUT)" ;;
    esac
}

echo "--- classifier verdicts ---"

printf 'HTTP/1.1 101 Switching Protocols\r\n\r\n\x81{"event":"pusher:connection_established","data":"{}"}' >"$TMPD/established.bin"
expect "established passes" 0 "PASS: protocol established" "$TMPD/established.bin"

printf 'HTTP/1.1 101 Switching Protocols\r\n\r\n\x81{"event":"pusher:error","data":"{\\"code\\":4001}"}' >"$TMPD/key-error.bin"
expect "unknown key fails as 4001" 1 "4001 'Application does not exist'" "$TMPD/key-error.bin"

printf 'HTTP/1.1 101 Switching Protocols\r\n\r\n\x81{"event":"pusher:error","data":"{\\"code\\":4009}"}' >"$TMPD/origin-error.bin"
expect "rejected origin fails as 4009" 1 "4009 'Origin not allowed'" "$TMPD/origin-error.bin"

printf 'HTTP/1.1 101 Switching Protocols\r\n\r\n' >"$TMPD/silent.bin"
expect "101 without frames fails" 1 "no protocol frames arrived" "$TMPD/silent.bin"

printf 'HTTP/1.1 404 Not Found\r\n\r\nnonexistent' >"$TMPD/http-error.bin"
expect "HTTP error fails with status" 1 "handshake rejected: HTTP/1.1 404" "$TMPD/http-error.bin"

printf '' >"$TMPD/empty.bin"
expect "empty capture fails" 1 "no response" "$TMPD/empty.bin"

OUT=$("$PROBE" --from-file "$TMPD/does-not-exist" 2>&1)
CODE=$?
if [ "$CODE" -eq 2 ] && echo "$OUT" | grep -q "not found"; then
    pass "missing capture file exits 2"
else
    fail "missing capture file: exit $CODE (output: $OUT)"
fi

echo "--- snippet rule order ---"

APP_LINE=$(grep -n '^ProxyPass /app/' "$SNIPPET" | cut -d: -f1 | head -1)
CATCHALL_LINE=$(grep -n '^ProxyPass / ' "$SNIPPET" | cut -d: -f1 | head -1)
if [ -n "$APP_LINE" ] && [ -n "$CATCHALL_LINE" ]; then
    if [ "$APP_LINE" -lt "$CATCHALL_LINE" ]; then
        pass "ProxyPass /app/ (line $APP_LINE) precedes catch-all / (line $CATCHALL_LINE)"
    else
        fail "ProxyPass /app/ (line $APP_LINE) must precede catch-all / (line $CATCHALL_LINE): first match wins"
    fi
else
    fail "snippet must contain both an active ProxyPass /app/ and ProxyPass / rule"
fi

if grep '^ProxyPass /app/' "$SNIPPET" | grep -q 'upgrade=websocket'; then
    pass "ProxyPass /app/ carries upgrade=websocket"
else
    fail "ProxyPass /app/ must carry upgrade=websocket"
fi

if [ "$FAIL" -eq 0 ]; then
    echo "== all realtime verification checks passed =="
else
    echo "== realtime verification checks FAILED ==" >&2
fi
exit "$FAIL"
