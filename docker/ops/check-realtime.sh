#!/bin/sh
# Rev2Square realtime protocol probe.
#
# Verifies ONE websocket URL the way a browser experiences it: sends a
# WebSocket upgrade carrying a real Origin header, captures the raw
# transcript (HTTP headers plus the server's first frames), and requires
# pusher:connection_established. A bare HTTP 101 is explicitly NOT
# enough: unknown app keys (4001) and rejected origins (4009) also
# complete the handshake and fail only at the protocol level, which a
# status-code check cannot see. Prints no credentials.
#
# Usage:
#   docker/ops/check-realtime.sh --url URL --origin ORIGIN [--timeout SEC]
#   docker/ops/check-realtime.sh --from-file PATH   # classify a capture (test mode)
#
# Exit 0: connection_established seen (fully verified). Exit 1: anything
# else, with a DIAG line naming the cause. Exit 2: usage errors.
# --timeout bounds the capture (default 8s); success usually arrives in
# milliseconds but the capture always runs to server-close or timeout so
# the transcript is complete for diagnosis.

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
umask 077
set -eu

URL=""
ORIGIN=""
TIMEOUT="8"
FROM_FILE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="$2"; shift 2 ;;
        --origin) ORIGIN="$2"; shift 2 ;;
        --timeout) TIMEOUT="$2"; shift 2 ;;
        --from-file) FROM_FILE="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 --url URL --origin ORIGIN [--timeout SEC]"
            echo "       $0 --from-file PATH"
            exit 0
            ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$TIMEOUT" in
    ''|*[!0-9]*) echo "FAIL: --timeout must be a positive integer (got: $TIMEOUT)" >&2; exit 2 ;;
esac
if [ "$TIMEOUT" -le 0 ]; then
    echo "FAIL: --timeout must be positive (got: $TIMEOUT)" >&2
    exit 2
fi

# sanitize: replace non-printable transcript bytes so DIAG lines never
# emit raw binary framing.
sanitize() {
    tr -c '[:print:]\t\n' '?' <"$1" | head -c 300
}

classify() {
    # classify <transcript>: print the verdict for one capture.
    # Returns 0 only for a fully established protocol session.
    CAP="$1"
    if grep -q 'pusher:connection_established' "$CAP" 2>/dev/null; then
        echo "PASS: protocol established (pusher:connection_established seen)"
        return 0
    fi
    if grep -q 'pusher:error' "$CAP" 2>/dev/null; then
        ERR=$(sanitize "$CAP" | grep -o 'pusher:error[^?]*' | head -1)
        echo "FAIL: server rejected the session at the protocol level: ${ERR:-pusher:error}"
        case "$ERR" in
            *4001*) echo "DIAG: 4001 'Application does not exist': the URL key differs from the server key (redeploy to rebuild assets)" ;;
            *4009*) echo "DIAG: 4009 'Origin not allowed': the Origin hostname is not in REVERB_ALLOWED_ORIGINS" ;;
            *) echo "DIAG: unexpected pusher:error; inspect the transcript above" ;;
        esac
        return 1
    fi
    if grep -q ' 101 ' "$CAP" 2>/dev/null; then
        echo "FAIL: handshake returned 101 but no protocol frames arrived (proxy swallowed them? timeout too short?)"
        return 1
    fi
    if grep -q '^HTTP/' "$CAP" 2>/dev/null; then
        STATUS=$(sanitize "$CAP" | grep -m1 '^HTTP/' | head -1)
        echo "FAIL: handshake rejected: ${STATUS:-unknown status}"
        return 1
    fi
    echo "FAIL: no response (connection refused, timeout with zero bytes, or empty reply)"
    return 1
}

if [ -n "$FROM_FILE" ]; then
    if [ ! -f "$FROM_FILE" ]; then
        echo "FAIL: capture file not found: $FROM_FILE" >&2
        exit 2
    fi
    if classify "$FROM_FILE"; then
        exit 0
    else
        exit 1
    fi
fi

if [ -z "$URL" ] || [ -z "$ORIGIN" ]; then
    echo "FAIL: --url and --origin are both required" >&2
    exit 2
fi

TMP=$(mktemp) || { echo "FAIL: cannot create temp file" >&2; exit 2; }
trap 'rm -f "$TMP"' EXIT INT TERM

# -D - merges response headers into the captured stream ahead of the
# frames, so one transcript diagnoses handshake AND protocol failures.
# Server-to-client frames are unmasked: the JSON stays greppable inside
# the binary framing. curl exit 28 (timeout) with an established marker
# is the normal healthy outcome: the socket stays open.
# --http1.1 pins the transport: through Apache, curl's HTTP/2
# negotiation falsely reported HTTP 500 while HTTP/1.1 establishes
# the Pusher protocol fine (verified in production).
CURL_EXIT=0
curl --http1.1 -sS -N -m "$TIMEOUT" -D - \
    -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
    -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
    -H "Origin: $ORIGIN" \
    "$URL" >"$TMP" 2>/dev/null || CURL_EXIT=$?

if classify "$TMP"; then
    exit 0
fi
if [ "$CURL_EXIT" -eq 0 ]; then
    echo "DIAG: server closed the connection promptly (error above arrived pre-close)"
elif [ "$CURL_EXIT" -eq 28 ]; then
    echo "DIAG: capture ran the full ${TIMEOUT}s without the expected frames"
else
    echo "DIAG: curl exited $CURL_EXIT (connection-level failure)"
fi
exit 1
