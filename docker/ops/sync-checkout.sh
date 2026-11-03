#!/bin/sh
# Synchronize a production checkout to one reviewed release.
#
# Default: fast-forward the local main branch to the tip of
# origin/main and leave the checkout ON main tracking origin/main.
# --target REV (rollback): detach HEAD at one pinned release, which
# must already be merged in origin/main; local main is untouched and
# the next default run returns the checkout to main.
#
# Non-destructive by design: exactly one fetch per run, fast-forward
# moves only, tracked local modifications are refused (never merged
# over, never discarded), untracked files are ignored, and history is
# never rewritten. A detached HEAD is adopted only when it is an
# ancestor of origin/main; anything else is refused with the manual
# recovery printed.
#
# Machine contract: human progress goes to stderr; stdout carries
# exactly two lines, "START <full-sha>" (checkout before the sync)
# and "DEPLOYED <full-sha>" (checkout after; always equals the
# resolved target). Exit 0 on success, exit 2 on any refusal.
#
# Usage:
#   sync-checkout.sh --root DIR [--target REV]

ROOT=""
TARGET_ARG=""
while [ $# -gt 0 ]; do
    case "$1" in
        --root) ROOT="$2"; shift 2 ;;
        --target) TARGET_ARG="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,/^$/p' "$0"
            echo "Usage: $0 --root DIR [--target REV]"
            exit 0
            ;;
        *) echo "FAIL: unknown argument: $1" >&2; exit 2 ;;
    esac
done

if [ -z "$ROOT" ]; then
    echo "FAIL: --root DIR is required" >&2
    exit 2
fi
if ! git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; then
    echo "FAIL: $ROOT is not a git checkout" >&2
    exit 2
fi
if [ -n "$TARGET_ARG" ]; then
    case "$TARGET_ARG" in
        ''|*[\ \	]*)
            echo "FAIL: --target must be a single revision (got: $TARGET_ARG)" >&2
            exit 2
            ;;
    esac
fi

START_SHA=$(git -C "$ROOT" rev-parse HEAD 2>/dev/null) || {
    echo "FAIL: cannot resolve HEAD in $ROOT" >&2
    exit 2
}
echo "start checkout: $START_SHA" >&2

FETCH_OUT=$(git -C "$ROOT" fetch origin 2>&1) || {
    echo "$FETCH_OUT" | sed 's/^/fetch: /' >&2
    echo "FAIL: 'git fetch origin' failed; refusing to deploy from stale history" >&2
    exit 2
}
echo "$FETCH_OUT" | sed 's/^/fetch: /' >&2

if [ -n "$TARGET_ARG" ]; then
    TARGET_SHA=$(git -C "$ROOT" rev-parse --verify "$TARGET_ARG^{commit}" 2>/dev/null) || {
        echo "FAIL: --target '$TARGET_ARG' resolves to no commit in the fetched history" >&2
        exit 2
    }
    if ! git -C "$ROOT" merge-base --is-ancestor "$TARGET_SHA" origin/main 2>/dev/null; then
        echo "FAIL: --target '$TARGET_ARG' ($TARGET_SHA) is not merged in origin/main;" >&2
        echo "only reviewed releases already on origin/main may be deployed." >&2
        exit 2
    fi
else
    TARGET_SHA=$(git -C "$ROOT" rev-parse origin/main 2>/dev/null) || {
        echo "FAIL: cannot resolve origin/main after fetching" >&2
        exit 2
    }
fi
echo "target release: $TARGET_SHA" >&2

DIRTY=$(git -C "$ROOT" status --porcelain --untracked-files=no) || {
    echo "FAIL: cannot read the working-tree status in $ROOT" >&2
    exit 2
}
if [ -n "$DIRTY" ]; then
    echo "FAIL: tracked local modifications are present; commit, revert, or move" >&2
    echo "them aside and re-run. This tool never touches local changes:" >&2
    git -C "$ROOT" status --short --untracked-files=no >&2
    exit 2
fi

if [ -z "$TARGET_ARG" ]; then
    if ! git -C "$ROOT" symbolic-ref -q HEAD >/dev/null; then
        if ! git -C "$ROOT" merge-base --is-ancestor "$START_SHA" origin/main 2>/dev/null; then
            echo "FAIL: checkout is detached at $START_SHA, which is not in the" >&2
            echo "origin/main history; check out main or a known release manually" >&2
            echo "(this tool only adopts detached checkouts it can fast-forward)." >&2
            exit 2
        fi
        echo "note: detached checkout is an ancestor of origin/main; moving to main" >&2
    fi
    if ! git -C "$ROOT" show-ref --verify --quiet refs/heads/main; then
        echo "note: no local main branch; creating it from origin/main" >&2
        git -C "$ROOT" checkout -q -b main origin/main || {
            echo "FAIL: cannot create local main from origin/main" >&2
            exit 2
        }
    elif [ "$(git -C "$ROOT" symbolic-ref -q HEAD)" != "refs/heads/main" ]; then
        git -C "$ROOT" checkout -q main || {
            echo "FAIL: cannot check out local main" >&2
            exit 2
        }
    fi
    UPSTREAM=$(git -C "$ROOT" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null) || UPSTREAM=""
    if [ -z "$UPSTREAM" ]; then
        git -C "$ROOT" branch -q --set-upstream-to=origin/main main || {
            echo "FAIL: cannot set local main to track origin/main" >&2
            exit 2
        }
        echo "note: local main now tracks origin/main" >&2
    elif [ "$UPSTREAM" != "origin/main" ]; then
        echo "FAIL: local main tracks '$UPSTREAM', not origin/main; point it at" >&2
        echo "origin/main manually (or reconcile the checkout) and re-run." >&2
        exit 2
    fi
    if ! git -C "$ROOT" merge --ff-only -q "$TARGET_SHA" 2>/dev/null; then
        echo "FAIL: local main cannot fast-forward to $TARGET_SHA: it holds" >&2
        echo "commits that diverge from origin/main. Reconcile manually (this" >&2
        echo "tool never rewrites history) and re-run." >&2
        exit 2
    fi
else
    git -C "$ROOT" checkout -q --detach "$TARGET_SHA" || {
        echo "FAIL: cannot detach at $TARGET_SHA" >&2
        exit 2
    }
    echo "note: checkout left detached at the pinned release; local main untouched;" >&2
    echo "the next default run returns the checkout to main." >&2
fi

DEPLOYED_SHA=$(git -C "$ROOT" rev-parse HEAD 2>/dev/null) || {
    echo "FAIL: cannot resolve the checkout after syncing" >&2
    exit 2
}
if [ "$DEPLOYED_SHA" != "$TARGET_SHA" ]; then
    echo "FAIL: checkout ($DEPLOYED_SHA) does not equal the target ($TARGET_SHA):" >&2
    echo "local main holds commits outside origin/main. Reconcile manually and re-run." >&2
    exit 2
fi
echo "deployed checkout: $DEPLOYED_SHA" >&2

echo "START $START_SHA"
echo "DEPLOYED $DEPLOYED_SHA"
