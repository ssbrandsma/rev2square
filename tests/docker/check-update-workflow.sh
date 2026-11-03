#!/bin/sh
# Regression test: production update workflow (sync + lock).
#
# Live fixture tests against scratch git repos (local only: no
# network, no docker, no stack): sync-checkout.sh is driven through
# the normal fast-forward, detached-ancestor adoption,
# detached-foreign refusal, dirty-tree refusal, untracked-ignored,
# diverged/ahead refusal, and --target rollback cases; then
# with-update-lock.sh is proven to serialize (a second concurrent
# holder is refused, the lock releases afterwards). Static pins assert
# update_prod.sh actually calls both helpers and that the updater and
# helpers contain no destructive git commands.
#
# Requires: POSIX sh + grep + git. Safe: temp dirs only.
#
# Usage:
#   tests/docker/check-update-workflow.sh

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SYNC="$ROOT/docker/ops/sync-checkout.sh"
LOCKWRAP="$ROOT/docker/ops/with-update-lock.sh"
UPDATER="$ROOT/docker/ops/update_prod.sh"

FAIL=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; FAIL=1; }

TMPD=$(mktemp -d) || { echo "FAIL: cannot create temp dir" >&2; exit 2; }
trap 'rm -rf "$TMPD"' EXIT INT TERM

command -v git >/dev/null 2>&1 || { echo "FAIL: git is required for this test" >&2; exit 2; }
# Hermetic commits regardless of the invoking user's gitconfig.
export GIT_AUTHOR_NAME="wf-test" GIT_AUTHOR_EMAIL="wf-test@invalid"
export GIT_COMMITTER_NAME="wf-test" GIT_COMMITTER_EMAIL="wf-test@invalid"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null

# seed_origin <name>: bare origin + dev clone holding commits one,two.
seed_origin() {
    git init -q --bare "$TMPD/$1.git"
    git --git-dir="$TMPD/$1.git" symbolic-ref HEAD refs/heads/main
    git clone -q "$TMPD/$1.git" "$TMPD/$1-dev" 2>/dev/null
    git -C "$TMPD/$1-dev" checkout -q -b main
    echo one >"$TMPD/$1-dev/file.txt"
    git -C "$TMPD/$1-dev" add file.txt
    git -C "$TMPD/$1-dev" commit -qm one
    echo two >>"$TMPD/$1-dev/file.txt"
    git -C "$TMPD/$1-dev" commit -qam two
    git -C "$TMPD/$1-dev" push -q origin main
}

# push_third <name>: dev pushes a third commit (server falls behind).
push_third() {
    echo three >>"$TMPD/$1-dev/file.txt"
    git -C "$TMPD/$1-dev" commit -qam three
    git -C "$TMPD/$1-dev" push -q origin main
}

# clone_srv <name>: fresh server checkout of the origin.
clone_srv() {
    git clone -q "$TMPD/$1.git" "$TMPD/$1-srv" 2>/dev/null
}

branch_of() {
    git -C "$1" symbolic-ref -q --short HEAD 2>/dev/null
}

echo "--- normal fast-forward ---"
seed_origin n1
clone_srv n1
push_third n1
N1_START=$(git -C "$TMPD/n1-srv" rev-parse HEAD)
N1_OUT=$("$SYNC" --root "$TMPD/n1-srv" 2>"$TMPD/n1.log"); N1_CODE=$?
N1_TIP=$(git -C "$TMPD/n1-srv" rev-parse origin/main)
N1_HEAD=$(git -C "$TMPD/n1-srv" rev-parse HEAD)
if [ "$N1_CODE" -eq 0 ]; then pass "behind checkout syncs (exit 0)"; else fail "behind checkout syncs: exit $N1_CODE ($(cat "$TMPD/n1.log"))"; fi
if [ "$N1_HEAD" = "$N1_TIP" ]; then pass "checkout lands on origin/main tip"; else fail "checkout at $N1_HEAD, want tip $N1_TIP"; fi
if [ "$(branch_of "$TMPD/n1-srv")" = "main" ]; then pass "checkout finishes on main"; else fail "checkout finishes on main (on: $(branch_of "$TMPD/n1-srv"))"; fi
if [ "$(git -C "$TMPD/n1-srv" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)" = "origin/main" ]; then pass "main tracks origin/main"; else fail "main tracks origin/main"; fi
if echo "$N1_OUT" | grep -q "^START $N1_START\$"; then pass "stdout records START sha"; else fail "stdout records START sha (got: $N1_OUT)"; fi
if echo "$N1_OUT" | grep -q "^DEPLOYED $N1_TIP\$"; then pass "stdout records DEPLOYED sha"; else fail "stdout records DEPLOYED sha (got: $N1_OUT)"; fi

echo "--- detached ancestor adopted ---"
seed_origin n2
clone_srv n2
push_third n2
git -C "$TMPD/n2-srv" checkout -q --detach HEAD 2>/dev/null
N2_OUT=$("$SYNC" --root "$TMPD/n2-srv" 2>"$TMPD/n2.log"); N2_CODE=$?
N2_TIP=$(git -C "$TMPD/n2-srv" rev-parse origin/main)
if [ "$N2_CODE" -eq 0 ]; then pass "detached ancestor syncs (exit 0)"; else fail "detached ancestor syncs: exit $N2_CODE ($(cat "$TMPD/n2.log"))"; fi
if [ "$(git -C "$TMPD/n2-srv" rev-parse HEAD)" = "$N2_TIP" ]; then pass "adopted checkout lands on tip"; else fail "adopted checkout lands on tip"; fi
if [ "$(branch_of "$TMPD/n2-srv")" = "main" ]; then pass "adopted checkout finishes on main"; else fail "adopted checkout finishes on main"; fi

echo "--- detached foreign refused ---"
seed_origin n3
clone_srv n3
git -C "$TMPD/n3-srv" checkout -q -b experiment
echo rogue >>"$TMPD/n3-srv/file.txt"
git -C "$TMPD/n3-srv" commit -qam rogue
git -C "$TMPD/n3-srv" checkout -q --detach HEAD 2>/dev/null
N3_BEFORE=$(git -C "$TMPD/n3-srv" rev-parse HEAD)
N3_OUT=$("$SYNC" --root "$TMPD/n3-srv" 2>"$TMPD/n3.log"); N3_CODE=$?
if [ "$N3_CODE" -eq 2 ]; then pass "detached foreign commit refused (exit 2)"; else fail "detached foreign commit refused: exit $N3_CODE"; fi
if [ "$(git -C "$TMPD/n3-srv" rev-parse HEAD)" = "$N3_BEFORE" ]; then pass "refused checkout left untouched"; else fail "refused checkout left untouched"; fi

echo "--- dirty tracked tree refused ---"
seed_origin n4
clone_srv n4
push_third n4
echo local-change >>"$TMPD/n4-srv/file.txt"
N4_BEFORE=$(git -C "$TMPD/n4-srv" rev-parse HEAD)
N4_OUT=$("$SYNC" --root "$TMPD/n4-srv" 2>"$TMPD/n4.log"); N4_CODE=$?
if [ "$N4_CODE" -eq 2 ]; then pass "dirty tree refused (exit 2)"; else fail "dirty tree refused: exit $N4_CODE"; fi
if [ "$(git -C "$TMPD/n4-srv" rev-parse HEAD)" = "$N4_BEFORE" ]; then pass "dirty checkout HEAD untouched"; else fail "dirty checkout HEAD untouched"; fi
if grep -q local-change "$TMPD/n4-srv/file.txt"; then pass "local modification preserved"; else fail "local modification preserved"; fi

echo "--- untracked files ignored ---"
seed_origin n5
clone_srv n5
push_third n5
echo owner-notes >"$TMPD/n5-srv/owner-notes.txt"
N5_OUT=$("$SYNC" --root "$TMPD/n5-srv" 2>"$TMPD/n5.log"); N5_CODE=$?
N5_TIP=$(git -C "$TMPD/n5-srv" rev-parse origin/main)
if [ "$N5_CODE" -eq 0 ]; then pass "untracked files do not block sync"; else fail "untracked files do not block sync: exit $N5_CODE"; fi
if [ "$(git -C "$TMPD/n5-srv" rev-parse HEAD)" = "$N5_TIP" ]; then pass "checkout with untracked files lands on tip"; else fail "checkout with untracked files lands on tip"; fi
if [ -f "$TMPD/n5-srv/owner-notes.txt" ]; then pass "untracked file survives sync"; else fail "untracked file survives sync"; fi

echo "--- diverged and ahead refused ---"
seed_origin n6
clone_srv n6
echo local >>"$TMPD/n6-srv/file.txt"
git -C "$TMPD/n6-srv" commit -qam local
push_third n6
N6_BEFORE=$(git -C "$TMPD/n6-srv" rev-parse HEAD)
N6_OUT=$("$SYNC" --root "$TMPD/n6-srv" 2>"$TMPD/n6.log"); N6_CODE=$?
if [ "$N6_CODE" -eq 2 ]; then pass "diverged main refused (exit 2)"; else fail "diverged main refused: exit $N6_CODE"; fi
if [ "$(git -C "$TMPD/n6-srv" rev-parse HEAD)" = "$N6_BEFORE" ]; then pass "diverged history untouched"; else fail "diverged history untouched"; fi
seed_origin n7
clone_srv n7
echo local >>"$TMPD/n7-srv/file.txt"
git -C "$TMPD/n7-srv" commit -qam local
N7_BEFORE=$(git -C "$TMPD/n7-srv" rev-parse HEAD)
N7_OUT=$("$SYNC" --root "$TMPD/n7-srv" 2>"$TMPD/n7.log"); N7_CODE=$?
if [ "$N7_CODE" -eq 2 ]; then pass "ahead-of-tip main refused (exit 2)"; else fail "ahead-of-tip main refused: exit $N7_CODE"; fi
if [ "$(git -C "$TMPD/n7-srv" rev-parse HEAD)" = "$N7_BEFORE" ]; then pass "ahead history untouched"; else fail "ahead history untouched"; fi

echo "--- target rollback ---"
seed_origin n8
clone_srv n8
push_third n8
"$SYNC" --root "$TMPD/n8-srv" >/dev/null 2>&1
N8_TIP=$(git -C "$TMPD/n8-srv" rev-parse HEAD)
N8_OLD=$(git -C "$TMPD/n8-srv" rev-parse 'HEAD~1')
N8_OUT=$("$SYNC" --root "$TMPD/n8-srv" --target "$N8_OLD" 2>"$TMPD/n8.log"); N8_CODE=$?
if [ "$N8_CODE" -eq 0 ]; then pass "target rollback syncs (exit 0)"; else fail "target rollback syncs: exit $N8_CODE ($(cat "$TMPD/n8.log"))"; fi
if [ "$(git -C "$TMPD/n8-srv" rev-parse HEAD)" = "$N8_OLD" ]; then pass "target checkout lands on pinned release"; else fail "target checkout lands on pinned release"; fi
if [ -z "$(branch_of "$TMPD/n8-srv")" ]; then pass "target checkout left detached"; else fail "target checkout left detached"; fi
if [ "$(git -C "$TMPD/n8-srv" rev-parse main)" = "$N8_TIP" ]; then pass "target rollback leaves main at tip"; else fail "target rollback leaves main at tip"; fi
N8B_OUT=$("$SYNC" --root "$TMPD/n8-srv" 2>"$TMPD/n8b.log"); N8B_CODE=$?
if [ "$N8B_CODE" -eq 0 ] && [ "$(branch_of "$TMPD/n8-srv")" = "main" ] && [ "$(git -C "$TMPD/n8-srv" rev-parse HEAD)" = "$N8_TIP" ]; then
    pass "next default run returns to main tip"
else
    fail "next default run returns to main tip (exit $N8B_CODE)"
fi

echo "--- target validation ---"
seed_origin n9
clone_srv n9
git -C "$TMPD/n9-srv" checkout -q -b experiment
echo rogue >>"$TMPD/n9-srv/file.txt"
git -C "$TMPD/n9-srv" commit -qam rogue
N9_ROGUE=$(git -C "$TMPD/n9-srv" rev-parse HEAD)
git -C "$TMPD/n9-srv" checkout -q main
N9_BEFORE=$(git -C "$TMPD/n9-srv" rev-parse HEAD)
N9_OUT=$("$SYNC" --root "$TMPD/n9-srv" --target "$N9_ROGUE" 2>"$TMPD/n9.log"); N9_CODE=$?
if [ "$N9_CODE" -eq 2 ]; then pass "unmerged target refused (exit 2)"; else fail "unmerged target refused: exit $N9_CODE"; fi
if [ "$(git -C "$TMPD/n9-srv" rev-parse HEAD)" = "$N9_BEFORE" ]; then pass "refused target leaves checkout untouched"; else fail "refused target leaves checkout untouched"; fi
N9B_OUT=$("$SYNC" --root "$TMPD/n9-srv" --target deadbeefdeadbeefdeadbeefdeadbeefdeadbeef 2>"$TMPD/n9b.log"); N9B_CODE=$?
if [ "$N9B_CODE" -eq 2 ]; then pass "unresolvable target refused (exit 2)"; else fail "unresolvable target refused: exit $N9B_CODE"; fi

echo "--- lock serialization ---"
export UPDATE_LOCK_DIR="$TMPD/upd.lock"
"$LOCKWRAP" -- sleep 10 &
HOLDER=$!
i=0
while [ ! -d "$UPDATE_LOCK_DIR" ] && [ "$i" -lt 10 ]; do sleep 1; i=$((i + 1)); done
if [ ! -d "$UPDATE_LOCK_DIR" ]; then
    fail "lock holder started (lock dir missing)"
    wait "$HOLDER" 2>/dev/null
else
    L2_OUT=$("$LOCKWRAP" -- true 2>&1); L2_CODE=$?
    if [ "$L2_CODE" -eq 2 ]; then pass "second concurrent holder refused (exit 2)"; else fail "second concurrent holder refused: exit $L2_CODE"; fi
    if echo "$L2_OUT" | grep -q "holds the lock"; then pass "refusal names the lock"; else fail "refusal names the lock (got: $L2_OUT)"; fi
    wait "$HOLDER"
fi
L3_OUT=$("$LOCKWRAP" -- true 2>&1); L3_CODE=$?
if [ "$L3_CODE" -eq 0 ]; then pass "lock acquired after release"; else fail "lock acquired after release: exit $L3_CODE ($L3_OUT)"; fi
if [ ! -e "$UPDATE_LOCK_DIR" ]; then pass "lock released after run"; else fail "lock released after run"; fi

echo "--- updater wiring ---"
if grep -q 'sync-checkout\.sh' "$UPDATER"; then pass "update_prod.sh calls sync-checkout.sh"; else fail "update_prod.sh calls sync-checkout.sh"; fi
if grep -q 'with-update-lock\.sh' "$UPDATER"; then pass "update_prod.sh runs under with-update-lock.sh"; else fail "update_prod.sh runs under with-update-lock.sh"; fi
if grep -q 'UPDATE_LOCK_HELD' "$UPDATER"; then pass "update_prod.sh guards the lock re-exec"; else fail "update_prod.sh guards the lock re-exec"; fi
if grep -q -- '--target' "$UPDATER"; then pass "update_prod.sh offers --target"; else fail "update_prod.sh offers --target"; fi
for S in "$SYNC" "$LOCKWRAP" "$UPDATER"; do
    if grep -Eq 'reset --hard|git clean|git stash' "$S"; then
        fail "no destructive git commands in $(basename "$S")"
    else
        pass "no destructive git commands in $(basename "$S")"
    fi
done

if [ "$FAIL" -eq 0 ]; then
    echo "== all update workflow checks passed =="
else
    echo "== update workflow checks FAILED ==" >&2
fi
exit "$FAIL"
