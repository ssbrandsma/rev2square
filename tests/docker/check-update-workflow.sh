#!/bin/sh
# Regression test: production update workflow (sync + lock).
#
# Live fixture tests against scratch git repos (local only: no
# network, no docker, no stack): sync-checkout.sh is driven through
# the normal fast-forward, detached-ancestor adoption,
# detached-foreign refusal, dirty-tree refusal, untracked-ignored,
# diverged/ahead refusal, and --target rollback cases; then
# with-update-lock.sh is proven to serialize (a second concurrent
# holder is refused, the lock releases afterwards). Then the REAL
# updater is driven through its lock re-exec against a fixture copy
# (stub docker/curl, REAL backup + readiness gate, scratch git
# origin): every flag (--target, --env-file including spaces,
# --project, --runners, --drain-timeout, --force) must arrive intact
# on the other side. Spaced-path runs then prove an EXISTING env file
# with spaces reaches docker as one argument end to end (updater,
# backup, gate) and in direct backup/gate/status/restore runs.
# Static pins assert update_prod.sh actually calls both helpers, that
# the re-exec precedes argument parsing, and that the updater and
# helpers contain no destructive git commands.
#
# Requires: POSIX sh + grep + git + gzip/sha256sum/du. Safe: temp
# dirs only (restore runs fully stubbed: nothing real touched).
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
for TOOL in gzip sha256sum du; do
    command -v "$TOOL" >/dev/null 2>&1 || { echo "FAIL: $TOOL is required for this test" >&2; exit 2; }
done
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

argv_single() {
    # argv_single <argv-file> <path>: <path> appears as one whole <arg>
    # line, and its first whitespace-separated word never appears as
    # its own line (any word-split would emit that word standalone).
    grep -qF -- "<$2>" "$1" && ! grep -qF -- "<${2%% *}>" "$1"
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

echo "--- updater re-exec preserves arguments ---"

# Fixture: a copy of the REAL updater + sync/lock helpers rooted in a
# scratch git repo, with stub docker/curl/helpers so full runs need no
# daemon, network, or stack. Every run goes through the actual lock
# re-exec; a dropped flag fails its assertion (a dropped --target
# would silently deploy the tip instead of the pinned release).
F="$TMPD/fixture"
mkdir -p "$F/docker/ops" "$F/docker/backup" "$F/bin"
cp "$ROOT/docker/ops/update_prod.sh" "$ROOT/docker/ops/sync-checkout.sh" "$ROOT/docker/ops/with-update-lock.sh" "$F/docker/ops/"
cp "$ROOT/docker/backup/backup.sh" "$F/docker/backup/"
cp "$ROOT/docker/ops/wait-for-healthy.sh" "$F/docker/ops/"
chmod +x "$F/docker/ops/update_prod.sh" "$F/docker/ops/sync-checkout.sh" "$F/docker/ops/with-update-lock.sh" "$F/docker/backup/backup.sh" "$F/docker/ops/wait-for-healthy.sh"
# Real backup + readiness gate exercise the full deploy path; only the
# realtime probe stays stubbed (it needs a network peer).
for STUB in "$F/docker/ops/check-realtime.sh" "$F/bin/curl"; do
    printf '#!/bin/sh\nexit 0\n' >"$STUB"
    chmod +x "$STUB"
done
cat >"$F/bin/docker" <<'DOCKER_EOF'
#!/bin/sh
# One <arg> per line: a path with spaces is distinguishable from
# split words.
printf '<%s>\n' "$@" >>"${DOCKER_ARGV_FILE:?}"
case " $* " in
    *" ps --format "*)
        for _s in ${DOCKER_HEALTHY:-}; do echo "$_s running healthy"; done
        if [ -n "${DOCKER_BUSY:-}" ]; then echo "analysis-coordinator running"; fi
        ;;
    *" exec "*)
        # Queue depth for drains (0 = idle); dump/query output is
        # ignored by the other callers.
        echo "${DOCKER_BUSY:-0}"
        ;;
esac
exit 0
DOCKER_EOF
chmod +x "$F/bin/docker"
: >"$F/compose.virtualmin.yaml"
printf 'REVERB_APP_KEY=test-public-key\nAPP_URL=http://example.invalid\nDB_DATABASE=testdb\nDB_ROOT_PASSWORD=testpw\n' >"$F/fixture.env"
git init -q "$F"
git -C "$F" checkout -q -b main
git -C "$F" add -A
git -C "$F" commit -qm fixture
git init -q --bare "$TMPD/fixture.git"
git --git-dir="$TMPD/fixture.git" symbolic-ref HEAD refs/heads/main
git -C "$F" remote add origin "$TMPD/fixture.git"
git -C "$F" push -q -u origin main
export UPDATE_LOCK_DIR="$TMPD/fixture.lock"
export DOCKER_ARGV_FILE="$TMPD/docker-argv.log"
export DOCKER_HEALTHY="app nginx db queue scheduler reverb analysis-coordinator"
FIXUPD="$F/docker/ops/update_prod.sh"

# A1: invalid drain timeout rejected by validation.
PATH="$F/bin:$PATH" "$FIXUPD" --drain-timeout NOTANUMBER >"$TMPD/rA1.log" 2>&1; RA1_CODE=$?
if [ "$RA1_CODE" -eq 2 ] && grep -q "must be a positive integer" "$TMPD/rA1.log"; then
    pass "invalid drain timeout rejected"
else
    fail "invalid drain timeout rejected (exit $RA1_CODE: $(cat "$TMPD/rA1.log"))"
fi

# A2: drain timeout VALUE survives the re-exec: with a busy coordinator
# stub (depth 1), the drain expires and the message must quote the
# passed value (1s), not the 900s default. Costs one 15s drain sleep.
DOCKER_BUSY=1 PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --drain-timeout 1 >"$TMPD/rA2.log" 2>&1; RA2_CODE=$?
if [ "$RA2_CODE" -eq 2 ] && grep -q "after 1s; nothing recreated" "$TMPD/rA2.log"; then
    pass "drain timeout value survives re-exec"
else
    fail "drain timeout value survives re-exec (exit $RA2_CODE: $(tail -3 "$TMPD/rA2.log"))"
fi

# B: env path with spaces survives byte-identical (no split, no glob).
SPACED="$F/dir with spaces/missing.env"
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$SPACED" >"$TMPD/rB.log" 2>&1; RB_CODE=$?
if [ "$RB_CODE" -eq 2 ] && grep -qF -- "env file not found: $SPACED" "$TMPD/rB.log"; then
    pass "env path with spaces survives re-exec"
else
    fail "env path with spaces survives re-exec (exit $RB_CODE: $(cat "$TMPD/rB.log"))"
fi

# C: project survives into the compose invocation.
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --project RecProj >"$TMPD/rC.log" 2>&1; RC_CODE=$?
if [ "$RC_CODE" -eq 0 ] && grep -qF -- "<-p>" "$DOCKER_ARGV_FILE" && grep -qF -- "<RecProj>" "$DOCKER_ARGV_FILE"; then
    pass "project survives re-exec"
else
    fail "project survives re-exec (exit $RC_CODE, argv: $(cat "$DOCKER_ARGV_FILE" 2>/dev/null))"
fi

# D: runner selection survives to step 8 validation.
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --runners 'bogus-runner' >"$TMPD/rD.log" 2>&1; RD_CODE=$?
if [ "$RD_CODE" -eq 2 ] && grep -q "unknown runner 'bogus-runner'" "$TMPD/rD.log"; then
    pass "runner selection survives re-exec"
else
    fail "runner selection survives re-exec (exit $RD_CODE: $(tail -2 "$TMPD/rD.log"))"
fi

# E: target survives (a rollback pin must never become a tip deploy).
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --target bogusrev123 >"$TMPD/rE.log" 2>&1; RE_CODE=$?
if [ "$RE_CODE" -eq 2 ] && grep -q "resolves to no commit" "$TMPD/rE.log"; then
    pass "target survives re-exec"
else
    fail "target survives re-exec (exit $RE_CODE: $(tail -2 "$TMPD/rE.log"))"
fi

# F: force survives (drain waits skipped with notice).
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --force >"$TMPD/rF.log" 2>&1; RF_CODE=$?
if [ "$RF_CODE" -eq 0 ] && grep -q -- "--force: skipping pre-build drain" "$TMPD/rF.log"; then
    pass "force survives re-exec"
else
    fail "force survives re-exec (exit $RF_CODE)"
fi

# G: several flags survive TOGETHER through one re-exec.
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$F/fixture.env" --project JointProj --runners 'bogus-runner' --drain-timeout 30 --force >"$TMPD/rG.log" 2>&1; RG_CODE=$?
RG_OK=1
[ "$RG_CODE" -eq 2 ] || RG_OK=0
grep -q "unknown runner 'bogus-runner'" "$TMPD/rG.log" || RG_OK=0
grep -qF -- "<-p>" "$DOCKER_ARGV_FILE" || RG_OK=0
grep -qF -- "<JointProj>" "$DOCKER_ARGV_FILE" || RG_OK=0
grep -q -- "--force: skipping pre-build drain" "$TMPD/rG.log" || RG_OK=0
if [ "$RG_OK" -eq 1 ]; then
    pass "combined flags survive one re-exec"
else
    fail "combined flags survive one re-exec (exit $RG_CODE)"
fi

echo "--- spaced paths stay single arguments ---"

mkdir -p "$F/dir with spaces"
cp "$F/fixture.env" "$F/dir with spaces/real.env"
SPACED_EXISTING="$F/dir with spaces/real.env"

# H: existing spaced env through the full updater run (real backup +
# real readiness gate). Exit 0 proves intact passthru (a split path
# fails the helpers' existence checks); >= 3 whole-arg sightings
# proves updater, backup and gate each emitted it unsplit.
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$FIXUPD" --env-file "$SPACED_EXISTING" >"$TMPD/rH.log" 2>&1; RH_CODE=$?
RH_COUNT=$(grep -cF -- "<$SPACED_EXISTING>" "$DOCKER_ARGV_FILE")
if [ "$RH_CODE" -eq 0 ] && [ "$RH_COUNT" -ge 3 ] && argv_single "$DOCKER_ARGV_FILE" "$SPACED_EXISTING"; then
    pass "spaced env preserved end to end ($RH_COUNT docker sightings)"
else
    fail "spaced env preserved end to end (exit $RH_CODE, sightings $RH_COUNT)"
fi

# BK/WT: real backup and readiness gate invoked directly.
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$ROOT/docker/backup/backup.sh" --variant virtualmin --env-file "$SPACED_EXISTING" --dir "$TMPD/bak" >"$TMPD/rBK.log" 2>&1; RBK_CODE=$?
if [ "$RBK_CODE" -eq 0 ] && argv_single "$DOCKER_ARGV_FILE" "$SPACED_EXISTING"; then
    pass "backup keeps spaced env whole"
else
    fail "backup keeps spaced env whole (exit $RBK_CODE: $(tail -2 "$TMPD/rBK.log"))"
fi
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$ROOT/docker/ops/wait-for-healthy.sh" --variant virtualmin --env-file "$SPACED_EXISTING" >"$TMPD/rWT.log" 2>&1; RWT_CODE=$?
if [ "$RWT_CODE" -eq 0 ] && argv_single "$DOCKER_ARGV_FILE" "$SPACED_EXISTING"; then
    pass "readiness gate keeps spaced env whole"
else
    fail "readiness gate keeps spaced env whole (exit $RWT_CODE: $(tail -2 "$TMPD/rWT.log"))"
fi

# ST: status check invoked directly (read-only overview).
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$ROOT/docker/ops/status.sh" --variant virtualmin --env-file "$SPACED_EXISTING" >"$TMPD/rST.log" 2>&1; RST_CODE=$?
if [ "$RST_CODE" -eq 0 ] && argv_single "$DOCKER_ARGV_FILE" "$SPACED_EXISTING"; then
    pass "status keeps spaced env whole"
else
    fail "status keeps spaced env whole (exit $RST_CODE: $(tail -3 "$TMPD/rST.log"))"
fi

# RS: restore invoked directly (all docker stubbed: nothing real
# touched; safety dump skipped, backup covered above).
printf 'dump' | gzip >"$TMPD/dump.sql.gz"
: >"$DOCKER_ARGV_FILE"
PATH="$F/bin:$PATH" "$ROOT/docker/backup/restore.sh" --variant virtualmin --env-file "$SPACED_EXISTING" --yes --skip-safety-dump "$TMPD/dump.sql.gz" >"$TMPD/rRS.log" 2>&1; RRS_CODE=$?
if [ "$RRS_CODE" -eq 0 ] && argv_single "$DOCKER_ARGV_FILE" "$SPACED_EXISTING"; then
    pass "restore keeps spaced env whole"
else
    fail "restore keeps spaced env whole (exit $RRS_CODE: $(tail -2 "$TMPD/rRS.log"))"
fi

# Structural pin for the review finding: the lock re-exec must precede
# the consuming parse loop, so "$@" is still intact at exec time.
REEXEC_LINE=$(grep -n 'exec "$LOCK_SH"' "$UPDATER" | cut -d: -f1 | head -1)
PARSE_LINE=$(grep -n 'while \[ \$# -gt 0 \]' "$UPDATER" | cut -d: -f1 | head -1)
if [ -n "$REEXEC_LINE" ] && [ -n "$PARSE_LINE" ] && [ "$REEXEC_LINE" -lt "$PARSE_LINE" ]; then
    pass "lock re-exec precedes argument parsing (lines $REEXEC_LINE < $PARSE_LINE)"
else
    fail "lock re-exec must precede argument parsing (re-exec: $REEXEC_LINE, parse: $PARSE_LINE)"
fi

if [ "$FAIL" -eq 0 ]; then
    echo "== all update workflow checks passed =="
else
    echo "== update workflow checks FAILED ==" >&2
fi
exit "$FAIL"
