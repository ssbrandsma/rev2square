#!/bin/sh
# Serialize production deployments: run one command under an
# atomic lock directory, refusing when another deployment holds it.
#
# mkdir is the lock primitive (POSIX, no flock needed): exactly one
# mkdir wins. The lock releases on EXIT/INT/TERM; a SIGKILLed holder
# can leave it stale, and the refusal message says how to confirm
# that (no updater running) and clear it with rmdir.
#
# Usage:
#   with-update-lock.sh -- command [args...]
#
# The lock directory is $UPDATE_LOCK_DIR, default
# /tmp/rev2square-update.lock. Sets UPDATE_LOCK_HELD=1 for the child.

if [ "${1:-}" != "--" ]; then
    echo "FAIL: usage: $0 -- command [args...]" >&2
    exit 2
fi
shift
if [ $# -eq 0 ]; then
    echo "FAIL: usage: $0 -- command [args...]" >&2
    exit 2
fi

LOCK_DIR="${UPDATE_LOCK_DIR:-/tmp/rev2square-update.lock}"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    echo "FAIL: another deployment holds the lock $LOCK_DIR; refusing to run concurrently." >&2
    echo "If no update_prod.sh is running (check: ps aux | grep '[u]pdate_prod')," >&2
    echo "the lock is stale from a killed run: clear it with rmdir \"$LOCK_DIR\" and re-run." >&2
    exit 2
fi
trap 'rmdir "$LOCK_DIR" 2>/dev/null' EXIT INT TERM

export UPDATE_LOCK_HELD=1
# Foreground run on purpose: exec would replace this shell and the EXIT
# trap releasing the lock would never run.
"$@"
CODE=$?
rmdir "$LOCK_DIR" 2>/dev/null
exit "$CODE"
