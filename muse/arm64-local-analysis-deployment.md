# ARM64 local-analysis deployment (spool-isolated runners)

Date: 2026-10-29. Status: implemented + tested on x86; ARM64
provisioning and native qualification are owner-operated and pending.

## 1. What was built

Rev2Square's deterministic analysis tools (objdump, Ghidra, JADX,
ILSpyCmd, filesystem extractors) moved from worker-local subprocesses
to **isolated runner containers** driven by a restricted **analysis
coordinator** over a file spool. Meta remains the sole AI provider:
the runners are local, non-AI tools; the Agent reaches them only
through the existing tool interface over validated evidence.

```
Agent / UI → app → analysis-coordinator (queue `analysis` only,
             13-var env allowlist, DB + spool volumes, no secrets)
  → analysis-spool volume (job.json + input; runners mount read-only)
  → runner containers (no network, read-only root, non-root,
     no secrets, private tmpfs workdir, one heavy job server-wide)
  → analysis-results volume (bounded evidence; validated, ingested,
     cleaned up by the coordinator)
```

No container-spawning broker exists on purpose: long-running runners
poll the spool, so nothing in the application stack can launch
privileged containers or pass through arbitrary commands. Full
reference: `docs/analysis-spool-deployment.md` (trust boundary),
`docs/analysis-spool-protocol.md` (schemas), `docs/binary-analysis-setup.md`
(owner runbook).

## 2. Pinned tool versions and provenance

All pins were researched against upstream releases on 2026-10-29
(GitHub release APIs, Adoptium API, NuGet, PyPI, Debian packages).
Dockerfiles verify every download by checksum/signature at build.

| Image | Contents | License |
|---|---|---|
| `rev2square/binutils:2.40-2` | Debian bookworm `binutils-multiarch=2.40-2` (APT-signed Release); verified `-m`: `i386`, `i386:x86-64`; full `objdump -i` saved to `/provenance/` for owner qualification | GPL-3.0-or-later |
| `rev2square/ghidra:12.2` | `ghidra_12.2_PUBLIC_20261016.zip` (sha256 `2c2cd776…7be67`) + Temurin JDK 25.0.5+8 aarch64 (sha256 `bb5b2ded…1880`); natives compiled from the distribution via `gradlew buildNatives`; build asserts an ARM aarch64 `decompile` binary; reviewed `ExportEvidence.java` post-script | Apache-2.0 / GPL-2.0 w/ Classpath |
| `rev2square/jadx:1.5.6` | `jadx-1.5.6.zip` (sha256 `545ea2be…e8974`) + Temurin JRE 17.0.21+9 aarch64 (sha256 `1114f09d…d7bb5`); CLI only (GUI launchers removed); resources decoded | Apache-2.0 / GPL-2.0 w/ Classpath |
| `rev2square/ilspy:11.2.0.10050` | NuGet `ilspycmd` 11.2.0.10050 (framework-dependent `any` package — runs on linux-arm64; the release's native AOT packages are x64-only and are NOT used) on .NET 10 runtime copied from `mcr.microsoft.com/dotnet/sdk:10.0@sha256:de4fe90a…` (runtime manifest verified to include linux/arm64); invariant globalization, no ICU | MIT |
| `rev2square/extract:1.0` | 7-Zip ZS `linux-gcc-arm64.zip` v26.02-v1.5.7-R2 (sha256 `64511f6e…03c909`), `squashfs-tools=1:4.5.1-1` (APT-signed), `ubi-reader==0.8.16` sdist (sha256 `d6d81b19…43bd39`, GPL-3.0) | LGPL-2.1+ / GPL-2.0+ / GPL-3.0 |

Base: `debian:bookworm-slim@sha256:99c0b25c…22b6f3` (all except the
ilspy build stage). Downloads happen only at authorized build time,
never during analysis. `docker images --digests` output must be
recorded in the deployment log at owner build time.

Deliberate exclusions: **mtd-utils** (its UBI tooling needs MTD
devices the sandbox never exposes; offline UBI/UBIFS via ubireader),
**sasquatch** (unmaintained, conflicting forks; 7zz + unsquashfs +
ubireader cover the qualified paths), Ghidra debugger/symbolic extras,
JADX GUI, Android SDK. ubi-reader note: PyPI is now the maintained
onekey-sec fork (LICENSE = GPL-3.0; the "Proprietary" classifier is
an upstream metadata error); transitive deps float within their
specifiers and are frozen to `/provenance/python-freeze.txt` per build.

## 3. Processor/format capabilities (honest states)

Source-verified against the Ghidra 12.2 tag (39 built-in processor
modules enumerated; ARM/x86/AARCH64 `.ldefs` read):

- VERIFIED Ghidra languages: `x86:LE:32:default`,
  `x86:LE:64:default`, `ARM:LE:32:v8`, `ARM:LE:32:v8T`
  (Thumb-default — NEW mapping; arm32 and arm32-thumb are distinct,
  never aliases), `AARCH64:LE:64:v8A`. The runner enforces exactly
  this set; documented-but-unverified ids (MIPS/RISC-V/PowerPC) are
  display-only and refused at execution until a qualified run admits
  them.
- **AVR corrected**: Ghidra 12.2 ships no built-in AVR language, so
  the old `AVR:LE:16:default` candidate was removed (it could only
  ever fail). AVR stays an objdump candidate (BFD has a real AVR
  backend) pending qualification; Ghidra AVR needs a vetted extension.
- **PIC/MSP430 corrected**: Ghidra 12.2 ships built-in PIC and
  TI_MSP430 modules (no extension needed); mappings stay unverified
  pending owner qualification. Target notes fixed accordingly.
- objdump: verified `i386`/`i386:x86-64` for ELF/PE/RAW/IHEX/SREC;
  probed architectures surface as candidates only.
- New `filesystem` format (CramFS LE/BE, SquashFS ×4, UBI, UBIFS
  magics) with an `extract` pseudo-target: isolated offline
  extraction, then contained executables (ELF/PE/Mach-O/DEX preferred,
  ≤3) are promoted to child artifacts in the SAME project for CPU
  analysis. CPU targets on the image itself are UNSUPPORTED (decoding
  filesystem structures as instructions would be garbage).
- States kept distinct everywhere: verified/available,
  dependency-unavailable, configuration-required, unverified mapping,
  unsupported. Requestability = any primary output (disassembly,
  decompilation, extraction) SUPPORTED **and** worker-verified.

Changed-file inventory (this task only; prior uncommitted work preserved):
`docker/analysis/` (runner.py, 5 Dockerfiles, ExportEvidence.java,
coordinator.env, tests/), `app/Services/Binary/Sandbox/`
(SpoolClient, EvidenceValidator), `SandboxedBackend` + 5 adapters
(objdump/ghidra/jadx/ilspy converted, extract new),
`AnalyzeBinaryJob` (coordinator routing, per-adapter timeout,
execution re-probe, evidence choke point), `RefreshWorkerCapabilities`
+ `PruneAnalysisSpool` (coordinator routing), config
(artifacts spool keys, `analysis-database` connection),
`compose.dev.yaml` + `compose.virtualmin.yaml` (coordinator +
runners + volumes), admin Analysis page + `analysis:diagnostics`,
`BinaryTools` (`read_decompilation`, `list_reconstruction_files`),
analysis-show view (decompilation/reconstruction/extraction
sections), `FormatDetector` + `ArtifactExtractor` (filesystem),
`Targets` (extract + corrected notes), `Capability::EXTRACTION`,
tests + fixtures listed in §6, this doc + 3 docs/ files.

## 4. Isolation controls and residual limitations

Enforced (see `docs/analysis-spool-deployment.md` §3 for the full
table): non-root runners, dropped caps, no-new-privs, read-only
root + tmpfs workdirs, `network_mode: none`, no Docker socket, no
secrets in runner/coordinator env (15-key coordinator allowlist,
audited by tests), read-only spool mount + staged verified input
copies, per-job dirs, no cross-project access (child promotion stays
in-project), CPU/RAM/pids caps (prod), wall clock + process-group
kill on timeout/cancel, cleanup + hourly prune, symlink/traversal
rejection, bounded validated evidence ingestion.

Residual: same-kernel containers (kernel exploit could escape;
mitigated, not removed — the spool protocol is the seam for a
stronger sandbox later); budgets can be fully burned by hostile
input (contained by wall clock + single-job lock); Ghidra
auto-analysis has no internal progress bound (oversized firmware
dies by wall clock without partial evidence); coordinator
restriction comes from the compose allowlist (verify with
`docker compose exec analysis-coordinator env` after provisioning).

## 5. Resources and concurrency

One concurrent heavy job per server (spool `global.lock`), runner
memory caps (ghidra 2500m, jadx/ilspy 1500m, objdump/extract 1g),
timeouts (wall 480 + grace 30, visibility 900). **No measurements
were taken**: there is no ARM64 hardware in this environment, so the
values are conservative starting points. Measurement protocol before
any increase: qualify fixtures, record wall + peak RSS per tool
(Ghidra firmware is binding), raise one knob at a time, re-qualify
(see `docs/analysis-spool-deployment.md` §5).

## 6. Tests performed and results

All on x86_64 (dev container + host). Nothing here claims ARM64
execution — see §8.

- `php artisan test` (repo gate): **800 passed, 13 skipped, 0 failed**
  (was 771 passed before this task). The 13 skips: 12 objdump-runner
  integration tests (need a live runner; honest skip with setup note)
  + 1 pre-existing.
- New phpunit coverage (41 tests): `tests/Unit/Binary/Sandbox/`
  (EvidenceValidator 7, SpoolClient↔stub-runner protocol 7 incl.
  cancel delivery + give-up, SpooledAdapters 7 incl. byte-identical
  objdump 2.44 fixture parsing with known instructions, promotion +
  dedup, fallback), `FilesystemDetectionTest` 5, `GhidraLanguagesTest`
  4 (pinned IDs, no AVR, Thumb distinct), `AnalysisDeploymentTest`
  12 (compose isolation ×2 files, coordinator allowlist ×2, volume
  sharing ×2, Dockerfile pins, queue routing + timeouts, full
  HTTP→job→spool→ready run, diagnostics, admin page, Agent tools).
- `docker/analysis/tests/test_runner.py` (host python3, stdlib
  unittest): **16/16 pass** — exact argv per profile (+rejections),
  probe/architectures, happy path, sha/tamper, traversal, claim
  exclusivity, timeout + cancel with grandchild reaping, symlink +
  depth caps, evidence cap, stored-file re-hash.
- `docker compose config --quiet`: both compose files validate
  (prod validated with a transient fake env file, removed after).
- Real-tool grounding: objdump fixture verified byte-identical to
  live `objdump -D -M intel -w -b binary -m i386` (Debian 2.44);
  Ghidra language IDs read from the 12.2 tag; .NET arm64 manifest
  + image digests resolved from live registries.

## 7. Owner-operated steps

Provisioning/refresh/qualification/rollback: `docs/binary-analysis-setup.md`
§2/§4/§5 (exact `/srv/rev2square` + `compose.virtualmin.yaml` +
`/etc/rev2square/.env.virtualmin` commands). Affected services:
`analysis-coordinator` (new, shares app image), 5 runners (new
images), `analysis-spool` + `analysis-results` volumes (new). Normal
`update_prod.sh` keeps working; tool images rebuild only on explicit
`build`. After every deploy/backend change: diagnostics → snapshot
refresh → qualification fixtures (§4 of the setup doc).

Browser retest (not performed — no browser access this turn):
artifact page queue flows for raw/ELF/APK/PE/filesystem fixtures,
`/admin/analysis` readiness/queue/failures, analysis-show
decompilation/reconstruction/extraction sections, promoted-child
links. Artifact 16 (`qa-retest-draft-20261028/artifacts/16`): re-run
extraction after provisioning — if its first 4 bytes are CramFS
magic it now classifies as `filesystem` and routes to extraction;
otherwise it keeps its current classification and the ARM32/Thumb
path applies with an explicit base address. No live retest was done
here; report the real outcome from the provisioned server.

## 8. Blockers and checks NOT performed

- No ARM64 hardware: image builds, native execution, Ghidra
  `buildNatives`, 7zz glibc compat, ilspycmd native deps, and
  `ExportEvidence.java` compilation are unverified until the owner
  build + qualification. The Java file is API-verified against 12.2
  sources (analyzeAll, DecompInterface signatures) but never compiled.
- No resource measurements (see §5); concurrency stays 1.
- No browser pass (see §7).
- No production access, deploy, Docker administration, secret reads,
  commits, or pushes — all per task boundaries.
- x86 dev note: runners build but cannot execute on x86 (ARM64
  binaries inside); adapters report unavailable there by design.
