# Ghidra headless home fix (passwd user.home, tmpfs ownership, CLI + script corrections)

## 1. Root cause (one cause, two errors)

Java on Linux derives `user.home` from `/etc/passwd`, **not** from
`$HOME` (proven: with `HOME=/tmp` exported, `user.home` still
resolved to `/var/www`). The `www-data` passwd entry points at
`/var/www`, which does not exist, so:

1. `LaunchSupport -save` (which `launch.sh` runs before every
   launch to resolve/persist the JDK) dies in
   `AppConfig.getSaveFile` with `FileNotFoundException: User home
   directory does not exist: /var/www`;
2. `launch.sh` falls through to its interactive JDK-prompt branch,
   which fails with `Unable to prompt user for JDK path, no TTY
   detected`.

Both server errors were reproduced byte-identically in a
`www-data` container before fixing.

## 2. Fixes

**Home (image + compose).** `Dockerfile.ghidra` repoints the
passwd entry to `/home/www-data` (sed anchored on the exact
current line + `pwd` assertion, so a base-image change fails
loudly), creates the www-data-owned dir, and sets `ENV
HOME=/home/www-data`. That path is a layer dir at build time and
tmpfs at runtime. Separately fixed: a plain tmpfs mount is
**root-owned** and would hide the image's owned dir (reproduced:
`Failed to create directory` even after the passwd fix), so both
compose files now mount
`/home/www-data:uid=33,gid=33,mode=755` (daemon acceptance +
compose parse verified). All `export HOME=/tmp` masking was
removed from the ghidra smokes so they test the real config.

**JDK (supported mechanism).** `support/launch.properties` gets
`JAVA_HOME_OVERRIDE=/opt/jdk` ("Force Ghidra's Java home instead
of trying to figure it out automatically") via deterministic sed +
assert. Together with the writable home, `LaunchSupport -save`
succeeds non-interactively (verified); `JAVA_HOME`/`PATH` pass
through unchanged.

**Invocation (three real defects found by running headless).**
`analyzeHeadless` requires **two** positionals (location + name);
the runner passed one, so `-import` was eaten as the project name
(`Bad argument: …/input.bin` — established from the 12.2
`AnalyzeHeadless.java` source and the live error). The project
**location directory must pre-exist** (`Directory not found`
otherwise). `runner.py` now passes a fixed `analysis` project
name and creates the location (same precedent as the extract
outdir); `GhidraAdapter::buildCommand` mirrors the shape. Raw
binaries import with no `-loader` flag (BinaryLoader fallback
confirmed live), and `-processor/-cspec default` are accepted.

**Per-job JVM scoping.** New `runner.tool_env()`: minimal env as
before, plus for ghidra `GHIDRA_HEADLESS_JAVA_OPTIONS` (the
launcher's documented env hook) scoping `-Duser.home`,
`-Djava.io.tmpdir`, and `-Dapplication.cachedir` under the job
workdir (auto-cleaned). The last one matters: Ghidra's default
cache is `/var/tmp/<user>-<application>`, which is read-only at
runtime. The JDK probe keeps using the shared home (the `-save`
JVM never receives these flags). Whitespace in workdir fails
fast (the launcher word-splits the options string). Both build
smokes use `tool_env()` too, so build and runtime run the
identical environment.

**`ExportEvidence.java` (three defects, all proven by execution —
headless exits 0 even when the post-script fails to compile, so
only the evidence assertions catch these; the runner separately
fails closed on missing evidence.json).**
`Listing.getCodeUnits(AddressRange, boolean)` does not exist in
12.2 → `getInstructions(new AddressSet(range), true)`;
`Instruction.getBytes()` throws checked `MemoryAccessException` →
per-instruction catch with warning; entry creation raced
auto-analysis (`FUN_00008000` already owned the address) → rename
in place when present.

## 3. Labeled gates (Dockerfile)

Two `--- install:` steps (home, JDK override) and nine
`--- smoke:` gates: stdlib imports, JDK executes, **home
alignment** (`$HOME` + writability + `user.home` from
showSettings), AArch64 ELF, ldd completeness, headless
import-only, fixture decompile via real `build_argv` +
`tool_env` (schema, `entry`@0x8000, exact `0100a0e3` bytes,
decompiled `return`), **scoping** (no `.ghidra` in shared home,
no `/var/tmp` cache escape), dry-run argv.

## 4. Validation performed

- x86 rig (pinned Ghidra + Temurin 25 x64, www-data,
  read-only-capable, tmpfs, no network — production parity
  except machine code): reproduced the exact server failure;
  then import-only (exit 0, `Import succeeded`) and the full
  fixture run (`FIXTURE EVIDENCE OK`) with per-job env, on both
  rw and **read-only** installs. Real output observed:
  `mov r0,#0x1`, `bl 0x0000800c`, and decompiled
  `void entry(void) { FUN_0000800c(1); return; }`.
- `analyzeHeadless -version` exits 1 (no such option) — the
  runner's ghidra version probe therefore reports null by
  design; left as is, noted as a possible future improvement
  (read `application.version` instead).
- `sh -n` clean; python suite 17/17 (new `tool_env` test);
  `AnalysisDeploymentTest` + `AdaptersTest` 28 passed / 572
  assertions (home/override/scoping/tmpfs/project-name
  assertions, several new); `BinaryTargetMatrixTest` 38 passed /
  12 skipped; both compose files validate. New/changed
  assertions fail on pre-fix sources (stash round-trip: 4 PHP +
  2 python failures) and pass after.

## 5. Per-image build report

| Image | Status |
|---|---|
| ghidra 12.2 | failed (server: user.home) → fixed; invocation, script, env, and evidence flow proven on x86 rig; **native ARM64 build + gates pending** |
| binutils / extract / jadx / ilspy | unchanged this turn; prior states stand (x86 verified; ARM64 rebuilds pending) |

Static tests and x86 execution are reported separately above;
no native-ARM64 claim is made from either.

## 6. Owner-operated rebuild (production ARM64)

Only the ghidra image changed. In `/srv/rev2square`:

```sh
cd /srv/rev2square
git pull
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build ghidra-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d --force-recreate ghidra-runner
docker images --digests 'rev2square/ghidra'
```

`--force-recreate` matters here: the tmpfs mount options only
apply to a fresh container. Expect both `--- install:` steps and
all nine `--- smoke:` gates to pass, ending with
`FIXTURE EVIDENCE OK`. Then:

```sh
docker inspect --format '{{.Repository}}:{{.Tag}} {{.Architecture}}' rev2square/ghidra:12.2
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan binary:worker-capabilities --refresh
```

Pending ARM64 checks: the full native build (toolchain gate →
buildNatives → install gates → 9 runtime gates),
`ExportEvidence.java` first execution on ARM64, and ARM32/Thumb
qualification before routing traffic.

Follow-up observations (not changed, out of scope): jadx/ilspy
runners also run JVM/CLR processes without `HOME` at runtime —
worth a startup smoke if they ever show home-dependent behavior;
Ghidra headless heap defaults to 2G against the 2500m container
limit (plus out-of-heap `decompile` memory) — revisit only if
OOMs appear.

No production changes, commits, pushes, or deployments were made
from here. The x86 rig (throwaway containers + downloads) was
removed after validation.
