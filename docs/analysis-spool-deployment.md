# Isolated analysis: trust boundary and deployment reference

Companion to `docs/binary-analysis-setup.md` (runbook) and
`docs/analysis-spool-protocol.md` (job/evidence schemas). This file is
the stable reference the application points at: it defines what runs
where, what crosses the boundary, and what the isolation does and
does not promise.

## 1. Zones

| Zone | Components | Secrets | Network | Untrusted bytes |
|---|---|---|---|---|
| Application | `app`, `nginx`, `queue`, `scheduler`, `db` | Full env (APP_KEY, mail, Meta keys) | App net + proxied egress | Stored, never parsed by tools |
| Coordinator | `analysis-coordinator` | 16-key allowlist: DB + framework basics only (no APP_KEY, no mail, no AI keys) | App net (DB/queue only) | Staged into the spool, parsed only as validated JSON/text |
| Spool | `analysis-spool` vol (job specs + inputs), `analysis-results` vol (status + evidence) | None | None (volumes) | Job inputs (opaque blobs) |
| Runners | `objdump/ghidra/jadx/ilspy/extract-runner` | None (no env_file, no secrets in compose) | `network_mode: none` | Parsed by tools inside private tmpfs workdirs |

The coordinator is trusted to see authorized artifact bytes and the
database; it never executes tool binaries. Runners execute tools but
see only one job's input plus the pinned tool installation. The Agent
interface (`BinaryTools`) only ever reads validated evidence — it
cannot submit jobs except through the same consent-gated request path
as the UI.

No execution broker with container-spawning privileges exists by
design: long-running runner containers poll the spool, so nothing in
the application stack can launch privileged containers, mount new
filesystems, or pass through arbitrary commands. If a stronger
sandbox (gVisor, Kata, a second host) is ever adopted, only the
runner side changes — the spool protocol and the Agent interface stay
identical.

## 2. What crosses the boundary

Coordinator → spool (`analysis-spool`, runners mount read-only):

- `<job>/job.json`: `{schema: 1, tool, kind, profile, params,
  input, input_size, input_sha256, limits: {wall_seconds}}`.
  `params` carries scalars only (language id, base/entry ints,
  machine name, VMA, thread count). No argv, paths, mounts, or env.
- `<job>/<input>`: exact input bytes under a confined basename.

Runner → results (`analysis-results`, coordinator reads + cleans up):

- `<job>/status.json`: claimed/running/done/timeout/failed/cancelled
  plus timing, exit code, and bounded diagnostics.
- `<job>/evidence.json`: schema-1 tool evidence (bounded, see the
  protocol doc). Ghidra writes this directly; tree tools get a
  manifest + bounded inline files; objdump gets `tool.out` text.
- `<job>/files/<sha>.bin`: up to 5 re-hashed promotion candidates
  (extract tool only).
- `<job>/cancel`: written by the COORDINATOR to request termination.

Global concurrency: `results/global.lock` (flock, non-blocking) —
exactly one heavy job server-wide across all runners.

Everything the runner writes is validated twice: structurally at
collection time (sizes, counts, depths, symlink rejection) and
semantically by `EvidenceValidator` before anything is stored or
rendered. Validation failures discard the output and fail the run —
never partial ingestion.

## 3. Isolation controls (enforced, not documented)

Per runner service (compose, asserted by
`AnalysisDeploymentTest::test_runners_are_isolated_in_compose`):

- `network_mode: none` — no host networking, no egress, no DNS.
- `read_only: true` + tmpfs `/tmp`, `/work` (extract: size-capped)
  — the tool installation cannot be modified at runtime.
- `user: www-data` — non-root; shared UID with the coordinator only
  so spool hygiene (cleanup/prune) works across the volume.
- `no-new-privileges:true` + `cap_drop: ALL`.
- Spool mounted `:ro`; inputs copied into the private workdir and
  re-verified (size + sha256) before the tool starts, so the tool
  never sees spool paths or other jobs' files.
- `mem_limit` + `pids_limit` (prod): ghidra 2500m/128, jadx/ilspy
  1500m/64, objdump/extract 1g/64 — initial values, see §5.
- No `env_file`, no published ports, no secrets anywhere in the
  runner definitions; tool versions arrive via pinned image builds.

Per run (runner.py, covered by `docker/analysis/tests/test_runner.py`
and `tests/Unit/Binary/Sandbox/SpoolClientTest.php`):

- Profile allowlist per tool; scalar params validated (language ids
  against the verified set, machines against the baked
  `machines.txt`, ints range-checked, names confined to basenames).
- Tool runs in its own session; wall-clock timeout and cancel kill
  the whole process group (SIGTERM, then SIGKILL) and reap.
- Output caps: 8 MiB tool text, 512 KiB evidence, 2000 manifest
  files, depth 12, 256 KiB inline total, 5 × 8 MiB stored files.
- Symlinks never followed or recorded; manifest paths re-checked
  coordinator-side; traversal/absolute/empty segments rejected.
- Atomic claim (`mkdir`), idempotent coordinator cleanup, hourly
  prune of strays older than wall + 1h.

Coordinator (compose, asserted by
`AnalysisDeploymentTest::test_coordinator_environment_is_an_allowlist`):

- Serves only queue `analysis` on connection `analysis-database`
  (`retry_after` 900 > wall 480 + grace 30 — long runs never
  double-execute after a visibility timeout).
- 16-key environment allowlist (DB + framework + spool paths +
  `DEPLOY_VARIANT` for deployment-specific owner hints); in
  dev the bind-mounted repo `.env` is additionally masked by
  `docker/analysis/coordinator.env` (non-secret framework keys only).
- `--tries=1`: malformed input and over-limit runs fail once with a
  categorized error; the user retries deliberately, never in a loop.

## 4. Residual limitations (honest)

- Same-kernel container isolation: a kernel exploit from a runner
  could escape to the host. Mitigations in place (non-root,
  no-new-privs, dropped caps, read-only root, no network, seccomp
  default) raise the bar but do not remove the shared kernel. The
  spool protocol is the seam for a stronger sandbox later.
- CPU/RAM/pids are capped per runner, but a malicious input can
  still burn its full budget — that is what the wall clock, the
  global single-job lock, and categorized failures contain.
- Ghidra auto-analysis has no internal progress bound: oversized
  firmware dies by wall clock with no partial evidence (documented;
  narrow the target range and retry).
- Image digests are recorded at owner build time (`docker images
  --digests`); the Dockerfiles pin every upstream artifact by
  version + checksum so rebuilds are reproducible.
- The coordinator shares the app image with the general workers;
  its restriction comes from the compose environment allowlist
  (audited in review + tests), not from a separate image. Verify
  with `docker compose exec analysis-coordinator env` after
  provisioning (expect exactly the allowlist).

## 5. Sizing and measurement

Initial values are conservative for a small shared server: one heavy
job at a time, runner memory caps above. Before raising concurrency:

1. Run the qualification fixtures (§4 of the setup doc) and record
   wall time + peak RSS per tool (`docker stats` during a Ghidra
   firmware run is the binding constraint).
2. Raise one knob at a time (runner `mem_limit`, then the spool lock
   to N slots), re-qualify, and record. The lock is a single file
   today; an N-slot lock is a small, reviewed runner change.
3. Never raise concurrency by adding coordinator replicas: the
   coordinator is a singleton (`deploy.replicas: 1`) so queue
   polling and spool cleanup stay single-writer.
