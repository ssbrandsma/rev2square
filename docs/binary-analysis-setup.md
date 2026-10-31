# Binary analysis: owner-operated setup (isolated runners)

Working with no extra setup: the bundled PHP analyzer (detection,
metadata, and 6502/Z80/8051/ARM-A32/Thumb-T32 disassembly). Everything
else — x86/x86-64 disassembly, Ghidra disassembly + deterministic
decompilation, JADX/ILSpy reconstruction, filesystem extraction —
runs in **isolated runner containers** fed by a restricted
**analysis coordinator** through a file spool. No tool binaries are
installed on the application host or in the PHP image, and the old
`GHIDRA_HEADLESS` / `JADX_BIN` / `ILSPY_BIN` / `OBJDUMP_BIN` worker
paths are retired (kept in config only as documented, non-functional
legacy keys).

Current coverage (see `muse/binary-target-backend-completion.md` and
`muse/arm64-local-analysis-deployment.md` for the tested matrix):

| Backend | Targets (verified) | Output |
|---|---|---|
| Bundled PHP (`php-native`) | mos6502, z80, i8051, arm32, arm32-thumb | Assembly only, by design |
| GNU objdump (`objdump`) | x86, x86-64 | Assembly only (objdump cannot decompile) |
| Ghidra (`ghidra`) | x86, x86-64, arm32, arm32-thumb, aarch64 → runner-qualified | Assembly + tool-generated decompilation |
| JADX (`jadx`) | dalvik, jvm → runner-qualified | Java-like reconstruction |
| ILSpy (`ilspy`) | clr → runner-qualified | C#-like reconstruction |
| Extraction (`extract`) | extract (filesystem images) → runner-qualified | File manifest + promoted child artifacts |

“Runner-qualified” means: the mapping additionally needs a passing
owner qualification run on the isolated runner (§4). Until qualified,
mappings stay visible-but-unrouted — this is deliberate.

## 1. Architecture (what runs where)

```
Browser / Agent
  → app (Laravel) + general queue workers (full env, all secrets)
  → analysis-coordinator (queue `analysis` only, 16-key env allowlist,
     DB + spool volumes only — NO app key, NO mail, NO AI keys)
       → spool volume (coordinator writes jobs; runners mount read-only)
       → objdump/ghidra/jadx/ilspy/extract runners
         (no network, read-only root, non-root, no secrets,
          private tmpfs workdir, one heavy job server-wide)
       → results volume (runners write bounded evidence; coordinator
          validates, ingests, and cleans up)
```

Trust boundary details, the job/evidence schemas, and every isolation
control are specified in `docs/analysis-spool-protocol.md`.

## 2. First-time provisioning (production, ARM64 server)

Run in `/srv/rev2square` with `--env-file /etc/rev2square/.env.virtualmin`
on every compose invocation. All steps are owner-operated.

```sh
cd /srv/rev2square

# 1. Build the five pinned tool images (needs build-time internet
#    for the pinned releases; Ghidra's native build takes 20-40 min)
#    and (re)create the coordinator + runners — as ONE `&&` chain, so
#    a failed build can never fall through into `up`. App/nginx/db/
#    queue keep running; only these six services recycle. If ANY build
#    fails: fix it and re-run this whole step; do NOT start services
#    with missing local images. (Runner services use
#    `pull_policy: never`, so a missing image fails fast with a local
#    error instead of a misleading Docker Hub "pull access denied".)
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d analysis-coordinator objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner

# 2. Record what you built (paste into the deployment log). Expect all
#    five runner tags; a missing row means step 1 did not complete.
docker images --digests 'rev2square/*'

# 3. Check the runners answer (versions + ARM64 arch, no secrets shown).
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan analysis:diagnostics

# 4. Capture worker capability snapshots FROM the runners.
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan binary:worker-capabilities --refresh
# …wait a minute, then inspect:
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan binary:worker-capabilities
```

Then qualify the mappings you intend to offer (§4) before announcing
them. Admins can watch readiness at `/admin/analysis`.

Normal `update_prod.sh` runs keep working: the coordinator shares the
app image (rebuilt with it), while the tool images only rebuild when
you run `build` for them explicitly. After ANY app deploy or tool
rebuild, repeat steps 3–4: snapshots older than
`BINARY_WORKER_SNAPSHOT_TTL_HOURS` (default 24) refuse dispatch until
refreshed, and version changes fail queued runs as `worker_mismatch`
rather than running them against a new backend silently.

## 3. Development

```sh
# Coordinator + runners (runners need ARM64 hardware to EXECUTE;
# on x86 they build but stay unavailable — documented, never silent).
# The `&&` chain stops `up` from running after a failed build.
docker compose -f compose.dev.yaml \
  build analysis-coordinator objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner && \
docker compose -f compose.dev.yaml up -d analysis-coordinator \
  objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner
docker compose -f compose.dev.yaml exec analysis-coordinator php artisan analysis:diagnostics
```

On x86 dev machines the sandboxed adapters report unavailable and the
bundled PHP backends keep working. The spool protocol itself is covered
by `tests/Unit/Binary/Sandbox/*` (phpunit, stub runner) and
`docker/analysis/tests/test_runner.py` (host python3, stub tools).

## 4. Qualification protocol (required before use)

Presence is not integration. After provisioning or any backend change:

1. Refresh the snapshot (§2 step 5) and open any matching artifact
   page. Unverified mappings stay visible-but-unrouted.
2. Queue the synthetic fixtures end-to-end and compare against the
   checked-in expectations — real instructions/functions, never
   “non-empty output”:
   - `tests/Unit/Binary/Sandbox/SpooledAdaptersTest.php` — adapter
     normalization incl. the byte-identical objdump 2.44 fixture;
   - `tests/Feature/BinaryTargetMatrixTest.php` — routing plus
     dispatch→worker→evidence runs per target/backend pair;
   - `docker/analysis/tests/test_runner.py` — runner claim, timeout,
     cancel, tree-kill, caps, argv conformance;
   - for new objdump `-m` / Ghidra language mappings: extend the
     adapter's verified table **only together with** a passing
     integration test on the isolated runner (see the existing
     x86/x86-64/ARM cases).
3. For Ghidra specifically, confirm one run shows tool-generated
   pseudocode for a known function, and that an `arm32-thumb`
   fixture decodes Thumb (not ARM) instructions.
4. For JADX specifically, no manual run is needed: every probe
   decompiles the baked `dex-smoke.dex` fixture and the backend
   reports ready only when the known class marker appears
   (`docker/analysis/fixtures/gen_dex_smoke.py` regenerates the
   fixture deterministically; the image build fails otherwise).
5. For extraction, confirm one SquashFS fixture promotes its
   contained executable to a child artifact you can then analyze.
6. Record backend name + version + image digest in the release notes.
   Do not mark a backend verified from documentation alone.

Until qualified, treat the mapping as unavailable regardless of what
is installed.

## 5. Operating notes

- One concurrent heavy analysis job per server (spool lock). The
  queue may hold more; they run in order. Raise only after measuring
  (§6 of the delivery doc).
- Timeouts: runner wall clock `BINARY_SPOOL_WALL_SECONDS` (default
  480) kills the whole tool process tree; the coordinator waits
  `BINARY_SPOOL_GRACE_SECONDS` longer, and the queue connection's
  `retry_after` (900) exceeds both so long runs never double-execute.
- Cancellation terminates the runner process group, not just the
  queue record; orphaned spool entries are pruned hourly by
  `PruneAnalysisSpool` on the coordinator.
- Resource-limit failures (`analysis_timeout`, `evidence_rejected`,
  `runner_failed`) are categorized per run and summarized at
  `/admin/analysis` and via `analysis:diagnostics`.
- In-flight jobs during a deploy: stop the coordinator, wait one wall
  budget + grace, then redeploy; queued (not started) runs survive in
  the database queue. Never delete the spool volumes mid-run.
- Rollback: previous tool images stay tagged locally; `up -d` with
  the previous compose revision restores them. Re-run §2 steps 3–4.

## 6. Environment reference

Coordinator allowlist (the only vars the coordinator ever sees):

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` | mysql/db | Coordinator database access |
| `QUEUE_CONNECTION` | `database` | Always database on the coordinator |
| `CACHE_STORE` | `database` | |
| `FILESYSTEM_DISK` | `local` | Artifact disk access |
| `BINARY_SPOOL_DIR` / `BINARY_RESULTS_DIR` | `/spool` / `/results` | Spool mount points |
| `DEPLOY_VARIANT` | `virtualmin` / `prod` | Deployment name so diagnostics render exact owner commands |

Analysis tuning (coordinator + job behavior):

| Variable | Default | Meaning |
|---|---|---|
| `BINARY_SPOOL_WALL_SECONDS` | 480 | Runner wall clock per run (s) |
| `BINARY_SPOOL_GRACE_SECONDS` | 30 | Coordinator grace after the wall clock |
| `BINARY_SPOOL_PROBE_SECONDS` | 45 | Capability-probe round-trip budget (shared version-ping default; objdump 75, jadx 150) |
| `BINARY_ANALYSIS_TIMEOUT` | 120 | Job budget for in-process adapters (s) |
| `ANALYSIS_QUEUE_RETRY_AFTER` | 900 | Queue visibility timeout (must exceed longest job: wall+grace+max probe+margin) |
| `BINARY_WORKER_SNAPSHOT_TTL_HOURS` | 24 | Snapshot freshness for dispatch |
| `BINARY_MAX_INSTRUCTIONS` | 20000 | Disassembly cap per run |
| `BINARY_MAX_FUNCTIONS` | 2000 | Function cap per run |
| `BINARY_MAX_XREFS` | 10000 | Cross-reference cap per run |
| `BINARY_MAX_EVIDENCE_BYTES` | 524288 | Evidence JSON cap per run |
| `BINARY_OBJDUMP_TIMEOUT` | 60 | Legacy local-subprocess budget (unused by the runner path) |
| `BINARY_OBJDUMP_MAX_OUTPUT_BYTES` | 8388608 | objdump text cap (enforced runner-side too) |

Retired (documented, non-functional): `OBJDUMP_BIN`,
`GHIDRA_HEADLESS`, `JADX_BIN`, `ILSPY_BIN`. Tool binaries live only
inside the runner images.

## 7. What stays local-only

- Uploaded binaries and extracted evidence never leave the `artifacts`
  disk except as bounded, redacted, consent-gated excerpts to the
  configured Meta AI provider. No binary-analysis upload services, no
  external AI decompilers, no model downloads.
- AI inference uses the existing Meta integration exclusively.
- Local deterministic analysis never requires a paid AI call, and no
  Meta response is ever presented as disassembler output.
