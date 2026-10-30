# Ghidra evidence-script permissions fix (umask-077 COPY hardening)

## 1. Root cause

`COPY` preserves host file modes and `cp` preserves source modes.
The production checkout is created under umask 077, so
`ExportEvidence.java` arrives as mode 600. The Dockerfile chmod'd
`runner.py` (755) and the fixture (644) but never the evidence
script, so both the reviewed copy and the `ghidra_scripts`
install were `600 root` → `Permission denied` for www-data at
post-script load. Local checkouts (umask 022, git 644) masked
this; only the production checkout exposed it.

Mechanism proven both directions with a micro-Dockerfile under a
real umask-077 source tree: plain COPY → `-rw------- root` →
`DENIED_AS_WWW_DATA` (server failure reproduced); `COPY --chmod`
+ explicit `chmod` → build green with `test -r` passing as
www-data.

## 2. Fixes (`Dockerfile.ghidra` only, plus runner validation)

- Every repo-file COPY declares its mode (`--chmod=755` runner,
  `--chmod=644` script + fixture); the install step re-asserts
  `644` on both script copies and `755` on the `ghidra_scripts`
  dir after `cp`, so neither host umask nor `cp` semantics can
  regress them. Files stay root-owned and non-writable to
  www-data; no 777, no root execution, no writable install.
- New first www-data gate, before any Ghidra launch:
  `test -r` on both script copies, runner, fixture, and the CA
  bundle, plus `test -x` on `analyzeHeadless`. Ten labeled
  runtime gates now.
- `ca-certificates` added to the final stage: Ghidra logs an
  ERROR when no OS bundle is readable. Assessment: the bundle
  completes the trust store and silences per-run ERROR noise
  that could mask real failures; it enables no network use
  (there is none) and changes no verification behavior (the
  Java default store, which already loaded 118 CAs and let the
  run continue, stays as fallback).
- Fixture assertions strengthened (never relaxed): the MOV bytes
  must sit **at** `0x8000` and the memory map must show the
  20-byte executable block starting at `0x8000` — the loader
  takes no explicit base option, so the script's rebase is now
  proven by address+bytes together. All strengthened values
  match the observed x86-rig evidence exactly
  (`0x8000`/`0100a0e3`, `start 0x8000 size 20 execute`).
- `runner.py` `_collect` now also rejects a wrong evidence
  envelope (`schema != 1` or `tool != "ghidra"`) with an
  actionable error naming both values. Missing-file (headless
  exits 0 despite post-script failure), oversize, and non-object
  rejections pre-existed and are now characterization-tested.

## 3. Audit of other copied inputs

All five Dockerfiles were inspected for host-mode dependence:
`runner.py` is explicitly `chmod 755`'d everywhere (now
regression-pinned for all five); `machines.txt` is created
in-image and chmod'd; no other Dockerfile COPYs repo files with
un-normalized modes. The evidence script was the sole gap.

## 4. Verification performed

- umask-077 micro-Dockerfile: control denied, fixed readable
  (above); throwaway images removed.
- `sh -n` over all ghidra RUN bodies: clean.
- Python suite 19/19 (new: missing-evidence + wrong-envelope
  rejections); PHP `AnalysisDeploymentTest` + `AdaptersTest` 29
  passed / 590 assertions (new: perms tokens, gate count 10,
  strengthened-assertion markers, all-images runner-mode test).
- Negative round-trip: new assertions fail on pre-fix sources
  (1 PHP + 1 python; the missing-evidence test passes pre-fix
  as intended — it characterizes pre-existing fail-closed
  behavior).

## 5. Per-image report and owner rebuild

Only the ghidra image changed. In `/srv/rev2square`:

```sh
cd /srv/rev2square
git pull
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build ghidra-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d --force-recreate ghidra-runner
```

Expect the `runtime inputs readable` gate first, then the
remaining nine gates ending with `FIXTURE EVIDENCE OK`. The
server's own umask-077 checkout is the real permission test —
its success proves the fix where it previously failed.

Pending native-ARM64 verification: the full ghidra rebuild with
these gates, first successful post-script load + evidence
creation on ARM64, and ARM32/Thumb qualification. Other images:
unchanged, prior states stand.

No production changes, commits, pushes, or deployments were made
from here.
