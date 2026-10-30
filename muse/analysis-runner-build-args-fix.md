# Runner build-input fix (ARG scope + JADX layout)

## 1. Exact cause of the extract failure

`Dockerfile.extract` declared `SEVENZZ_URL`, `SEVENZZ_SHA256`,
`UBIREADER_URL`, `UBIREADER_SHA256` as global ARGs (before the
first `FROM`). Global ARGs are visible in `FROM` lines only — a
`RUN` step sees them as empty unless the stage re-declares them
with a bare `ARG <name>`. No re-declaration existed, so line 59
expanded to `curl -o /tmp/7zz.zip ""` → `curl: (3) URL using
bad/illegal format or missing URL`.

Ruled out by inspection: the defaults themselves are correct
(verified live below), and neither compose file sets `build.args`,
so no Compose override erased them. The cause is purely Dockerfile
ARG scope. `DEBIAN_IMAGE` worked because it is used in the `FROM`
line, where global ARGs are legal.

The audit found the same scope bug plus a second, stacked bug in
`Dockerfile.jadx` (details in §3).

## 2. Pinned-input verification (all against real sources, 2026-10-30)

| Artifact | URL live | SHA-256 | Format / internal paths |
|---|---|---|---|
| 7-Zip ZS `linux-gcc-arm64.zip` | 200 | `64511f6e…` matches (downloaded, 6.9 MB) | real ZIP; `7zz` at root; `file` → ARM aarch64 ELF ✓ |
| ubi-reader 0.8.16 sdist | 200 | `d6d81b19…` matches (downloaded) | real tar.gz; `ubireader_extract_files` console script confirmed in `[tool.poetry.scripts]` ✓ |
| Ghidra `ghidra_12.2_PUBLIC_20261016.zip` | 200 | `2c2cd776…` matches the hash published in the `Ghidra_12.2_build` release notes; asset name confirmed via API | top dir `ghidra_12.2_PUBLIC/` per Ghidra release convention; enforced fail-closed by the build's `mv` + `buildNatives` + decompile-path assertion |
| Temurin JDK 25.0.5+8 aarch64 | 200 | `bb5b2ded…` matches the upstream `.sha256.txt` sidecar | tarball, single top dir (`--strip-components=1`) |
| Temurin JRE 17.0.21+9 aarch64 | 200 | `1114f09d…` matches the upstream `.sha256.txt` sidecar | same as JDK |
| JADX `jadx-1.5.6.zip` | 200 | `545ea2be…` matches (downloaded, 72 MB); asset confirmed via API | real ZIP, **flat layout** (`bin/`, `lib/` at root — no `jadx-1.5.6/` dir). The old `cp` path was wrong; fixed. |
| ilspycmd 11.2.0.10050 | NuGet 200 (nupkg endpoint) | n/a (exact `--version` pin enforced by `dotnet tool install`) | framework-dependent tool package |
| dotnet SDK digest, Debian base digest, APT pins | unchanged pins | enforced by digest/`=version` at build | — |

No URLs or hashes were invented or changed: every recorded value
verified, and every download stays `curl -f` + `sha256sum -c`
fail-closed. Checksum verification was not weakened anywhere.

## 3. Changed files and rationale

- `docker/analysis/Dockerfile.extract`: re-declares the four ARGs
  after `FROM`; adds an early preflight RUN (`: "${VAR:?missing
  build input …}"` per input + `https://` scheme checks) that runs
  before any expensive step.
- `docker/analysis/Dockerfile.jadx`: same ARG + preflight fix,
  **and** corrects the install copy to `cp -r /tmp/jadxdist/.
  /opt/jadx/` (verified flat layout) with a `test -x
  /opt/jadx/bin/jadx` gate; header comment records the layout so
  the `jadx-${VERSION}/` assumption cannot return.
- `docker/analysis/Dockerfile.ghidra`: scope was already correct
  (per-stage re-declarations present); added preflights to the JDK
  and natives stages, the latter guarding the 20–40 minute
  `buildNatives` before it starts.
- `docker/analysis/Dockerfile.ilspy`: scope was already correct;
  added an `ILSPYCMD_VERSION` preflight to the tooling stage.
- `docker/analysis/Dockerfile.binutils`: audited, no changes
  needed (only `DEBIAN_IMAGE` in `FROM`, no downloads).
- `tests/Feature/AnalysisDeploymentTest.php`:
  `test_runner_stages_redeclare_download_args_with_preflight`
  (every stage referencing a global ARG must re-declare it;
  exact preflight/scheme-check counts per Dockerfile),
  `test_jadx_uses_verified_flat_archive_layout`, and a no-`args:`
  assertion for runner services in both compose files.

Compose needs no change: no `build.args` exist to erase
defaults (now regression-guarded), and `pull_policy: never` plus
the `build && up` provisioning chains from the previous fix
remain in place and tested.

## 4. Per-image audit

Server build status reflects the incident reports; local status is
what this turn verified on x86_64 (an ARM64 binary cannot execute
here, so ARM64-only images stop at their execution gate by design).

| Image | Server build | Local verification |
|---|---|---|
| binutils 2.40-2 | failed (python, previous fix) → rebuild pending | **passed** last turn: x86 build + www-data read-only runtime probe; unchanged since |
| extract 1.0 | **failed** (empty `SEVENZZ_URL`) | ARG fix + preflight proven: x86 build passes preflight, both downloads, both sha256, unzip, `cp`, ubi-reader pip install, then stops exactly at the ARM64 `7zz` execution gate (exit 255, exec-format — expected). Emptied `--build-arg SEVENZZ_URL=` fails in 0.2 s with `missing build input SEVENZZ_URL`. Full pass pending ARM64. |
| jadx 1.5.6 | **cancelled** (stack abort; would have failed: same ARG bug + wrong `cp` path) | Both fixes proven: x86 build passes preflight, both downloads/shas, unzip, corrected `cp`, `test -x`, then stops at ARM64 JRE execution (expected). Full pass pending ARM64. |
| ghidra 12.2 | **cancelled** (stack abort) | Static audit passed (scope correct, preflights added, `sh -n` clean). Build untested here (ARM64 JDK + 20–40 min natives). Full build + `ExportEvidence` qualification pending ARM64. |
| ilspy 11.2.0.10050 | **cancelled** (stack abort) | Static audit passed (scope correct, preflight added, `sh -n` clean). Build untested here (`linux-arm64` RID by design). Full pass pending ARM64. |

No whole-stack success is claimed: each image is reported on its
own evidence, and nothing above relies on cached layers standing
in for a real step (the x86 partial builds executed every step
through the arch gate).

## 5. Tests performed and results

- `AnalysisDeploymentTest`: 18 passed, 466 assertions (dev app
  container), including the 2 new tests + the new `args:`
  assertions.
- New tests against pre-fix Dockerfiles (stash round-trip): 2
  fail as expected, then pass after restore.
- `sh -n` over every RUN body in all 5 Dockerfiles: clean.
- x86 partial builds: extract + jadx (above); preflight negative
  test with emptied `SEVENZZ_URL` (above).
- Full PHP suite / runner Python suite not re-run: no app or
  runner code changed; the focused contract file is green.

## 6. Owner-operated rebuild (production ARM64)

Only four images changed (`binutils` and the coordinator are
untouched). In `/srv/rev2square`:

```sh
cd /srv/rev2square
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build ghidra-runner jadx-runner ilspy-runner extract-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d ghidra-runner jadx-runner ilspy-runner extract-runner
docker images --digests 'rev2square/*'
```

Expect Ghidra to take 20–40 minutes (`buildNatives`). If any
build fails, fix it and re-run the chain — the `&&` prevents `up`
from running against missing images, and `pull_policy: never`
keeps any residual failure local instead of a Hub pull error.
(The full five-image chain from `docs/binary-analysis-setup.md`
§2 remains valid as an alternative.)

Then re-run the per-image www-data validation probes and the
capability-snapshot refresh from
`muse/analysis-runner-python-runtime-fix.md` §5 (imports,
dry-run argv paths, `analysis:diagnostics`,
`binary:worker-capabilities --refresh`), and qualify the
runner-backed mappings before routing traffic.

## 7. Remaining pending ARM64 checks

- Native builds of extract, jadx, ghidra, ilspy completing past
  their execution gates (binutils: ARM64 rebuild still pending
  from the previous fix).
- 7zz glibc-compatibility gate + functional UBI extraction
  qualification on the extract runner.
- Ghidra `buildNatives` output, `linux_aarch64` decompile
  assertion, `ExportEvidence.java` compatibility, ARM32/Thumb
  qualification run.
- `ilspycmd` native-dependency behavior + `linux-arm64` runtime
  assertion on the prod host.

No production changes, commits, pushes, or deployments were made
from here. A local `rev2square/binutils:2.40-2` x86 image from the
previous turn's verification remains in the dev daemon (harmless;
dev `up` builds/uses local images only).
