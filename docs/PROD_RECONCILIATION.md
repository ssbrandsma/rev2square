# One-time production reconciliation: HTTP/1.1 probe hotfix

Production (`/srv/rev2square`) carries one verified, uncommitted
hotfix in `docker/ops/check-realtime.sh`: the live probe's curl
negotiated HTTP/2 through Apache and falsely reported HTTP 500, while
`--http1.1` establishes the Pusher protocol fine. That exact fix is
now committed on `origin/main` together with the self-syncing updater,
so the local change is redundant and the checkout can return to a
clean `main` tracking `origin/main`.

This procedure synchronizes the CHECKOUT ONLY. It touches no
container, volume, credential, or environment file: zero downtime, and
it is deliberately separate from rebuilding/deploying Docker images
(step 6 points at the normal deploy command for afterwards).

Run as root, from `/srv/rev2square`. Stop at any step whose output
does not match: do not improvise past a mismatch.

## 1. Inspect: status and diff

```sh
[root]# cd /srv/rev2square
[root]# git rev-parse HEAD; git symbolic-ref -q HEAD || echo DETACHED
[root]# git status --short --untracked-files=no
[root]# git diff --stat --untracked-files=no
[root]# git diff --numstat -- docker/ops/check-realtime.sh
[root]# git diff
```

Expected: the status shows EXACTLY one entry:

```text
 M docker/ops/check-realtime.sh
```

the numstat shows EXACTLY one added and one deleted line:

```text
1       1       docker/ops/check-realtime.sh
```

and the diff shows EXACTLY the one-line hotfix:

```diff
-curl -sS -N -m "$TIMEOUT" -D - \
+curl --http1.1 -sS -N -m "$TIMEOUT" -D - \
```

If ANY other tracked file is modified, or the hunk differs: STOP.
Reconcile those changes deliberately first (commit or revert them on
purpose) — the steps below assume the hotfix is the only tracked
change. Untracked files (shown by bare `git status --short` without
`--untracked-files=no`) are informational only: nothing below touches
them, and you must NOT delete them.

## 2. Fetch, and confirm the fix is in the pushed tip

```sh
[root]# git fetch origin
[root]# TARGET=$(git rev-parse origin/main); echo "$TARGET"
[root]# git show "$TARGET:docker/ops/check-realtime.sh" | grep -n '^curl '
[root]# git diff HEAD "$TARGET" -- docker/ops/check-realtime.sh | grep -F '+curl --http1.1'
```

Expected: the `$TARGET` value equals the reviewed release SHA from the
release handoff — character for character. If it differs, `origin/main`
has advanced past the reviewed release: STOP for review instead of
silently deploying additional changes. Do not proceed until a new
review names the new tip.

The `git show` must print the live probe line carrying `--http1.1`,
and the release diff (base `HEAD` to `$TARGET`) must contain the
identical `+curl --http1.1` line. Together with step 1, this proves
the local change is EXACTLY the known hotfix and is present
identically in the reviewed release — the local change is redundant,
not lost.

## 3. Preserve a patch backup

```sh
[root]# git diff HEAD >/root/rev2square-hotfix-backup-$(date +%Y%m%dT%H%M%S).patch
[root]# ls -l /root/rev2square-hotfix-backup-*.patch
```

The backup lives in `/root`, OUTSIDE the repo, so no git operation
below can touch it. Keep it until the first successful deploy from the
reconciled checkout; then delete it.

## 4. Drop the redundant hotfix (preferred, non-destructive)

The file reverts to `HEAD`, which is safe ONLY because step 2 proved
the tip already contains the fix (and step 3 kept a backup):

```sh
[root]# git checkout -- docker/ops/check-realtime.sh
[root]# git status --short --untracked-files=no
```

Expected: empty output (tracked tree clean). Untracked files are
untouched — `git checkout -- <path>` cannot affect them.

## 5. Move to main and fast-forward to the tip

```sh
[root]# git rev-parse --abbrev-ref HEAD
```

If that prints `HEAD` (detached checkout), move onto main first:

```sh
[root]# git checkout main || git checkout -b main origin/main
```

Then fast-forward (never rewrite):

```sh
[root]# git merge --ff-only origin/main
[root]# git rev-parse HEAD; git rev-parse origin/main
[root]# git status --short --untracked-files=no
[root]# grep -n '^curl ' docker/ops/check-realtime.sh
```

Expected: both `rev-parse` values are identical (the `$TARGET` from
step 2), status is empty, and the checked-out probe line carries
`--http1.1`. If `merge --ff-only` refuses, local `main` holds commits
outside `origin/main`: STOP and reconcile deliberately — do not force
it.

## 5b. Optional alternative: reset --hard (destructive, read first)

Prefer step 4 + 5. `git reset --hard origin/main` is ONLY for the case
where step 1 showed tracked changes you have individually reviewed and
decided to abandon. What it discards, permanently and
unrecoverably (past the step-3 backup): EVERY tracked working-tree
modification AND every staged change, resetting both to `origin/main`.
What it does NOT touch: untracked files (still present), branches,
stashes, and history on the remote.

Requirements before using it: the step-3 backup exists; you have read
EVERY line of `git status --short --untracked-files=no`, `git diff`,
AND `git diff --cached`, and confirm each one is safe to drop. Then:

```sh
[root]# git checkout main || git checkout -b main origin/main
[root]# git reset --hard origin/main
[root]# git rev-parse HEAD; git rev-parse origin/main   # must match
```

Never follow this with `git clean` in any form: `clean` deletes
untracked files (owner notes, staged uploads, tool state) that no
backup above covers.

## 6. Afterwards: the normal deploy command (separate step)

Reconciliation changed only git files. To actually rebuild and deploy
Docker images from the reconciled checkout, run the normal workflow
(section 12 of `docs/DEPLOYMENT_VIRTUALMIN_ARM64.md`):

```sh
[root]# cd /srv/rev2square
[root]# ./docker/ops/update_prod.sh
[root]# ./docker/ops/status.sh --variant virtualmin
```

From now on the updater fetches and fast-forwards itself: no manual
`git fetch`/`git checkout` before normal runs. Rollback of a release
is `./docker/ops/update_prod.sh --target <previous-release-sha>`
(section 16).
