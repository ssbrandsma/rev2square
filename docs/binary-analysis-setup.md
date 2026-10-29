# Binary analysis: owner-operated setup

The bundled analyzer (pure-PHP detection, metadata, and 6502/Z80/8051
disassembly) works with no extra setup. External backends (Ghidra,
JADX, ILSpy) and production-grade isolation need the owner-operated
steps below. **Do not run untrusted uploads through external backends
until isolation (§3) is in place.** Application-level timeouts alone
are insufficient for hostile input.

## 1. Backend installation (all optional)

Install on the **worker host only** (never on the web container unless
you accept the risk), as root or your provisioning user:

### Ghidra (native disassembly/decompilation)

1. Install a JDK 17+ runtime (Ghidra's requirement — check the release notes).
2. Download a Ghidra release from the official distribution and unpack it to
   `/opt/ghidra` (read-only mount preferred).
3. Set the environment for the queue worker:
   `GHIDRA_HEADLESS=/opt/ghidra/support/analyzeHeadless`
4. Restart the queue worker (see your deployment's worker management).

### JADX (DEX/APK/Java reconstruction)

1. Download a JADX release (needs a JRE) and unpack it to `/opt/jadx`.
2. Set `JADX_BIN=/opt/jadx/bin/jadx` for the queue worker.
3. Restart the queue worker.

### ILSpy (ilspycmd, .NET assemblies)

1. Install the .NET runtime required by the ilspycmd release, then ilspycmd
   itself to `/opt/ilspycmd`.
2. Set `ILSPY_BIN=/opt/ilspycmd/ilspycmd` for the queue worker.
3. Restart the queue worker.

The application **probes** these paths read-only: missing binaries
surface as `dependency_unavailable` in the capability matrix — never
as failures, and the app never downloads or installs anything itself.

## 2. Backend verification protocol (required before use)

Presence is not integration. After installing a backend:

1. Restart the worker and open any matching artifact page (PE for
   Ghidra, APK for JADX, CLR PE for ILSpy).
2. The capability row still reports the backend as unavailable until a
   verified run exists — this is deliberate. Verification currently
   requires developer work: the headless export path
   (`ExportEvidence.java` for Ghidra, output parsers for JADX/ILSpy)
   has **no verified run on any installation** and the adapters refuse
   with `backend_unverified` even when the binary exists.
3. To qualify a backend, a developer must run the documented synthetic
   fixtures through it end-to-end, compare output against the checked-in
   golden expectations, and record the backend version in the release
   notes. Do not mark a backend verified from documentation alone.

Until then, treat every external-backend row as unavailable regardless
of what is installed.

## 3. Analysis-worker isolation (required for untrusted uploads)

Run the queue worker that executes `AnalyzeBinaryJob` (and extraction)
under **all** of the following:

- Dedicated non-root OS user, no sudo.
- No network egress (firewall DROP / network namespace without a route).
- No Docker socket, no production mounts, no secrets in environment
  beyond Laravel's own `APP_KEY` (needed for encryption).
- Per-job temp directories with cleanup (`TMPDIR` on a size-capped
  filesystem); never reuse working directories across projects.
- cgroup limits: CPU shares, memory max, process count, and disk quota.
- Wall-clock timeouts (the job also enforces
  `BINARY_ANALYSIS_TIMEOUT`, default 120s, and PHP queue `--timeout`).
- Tool installations mounted read-only.
- Cancellation must terminate the whole process tree
  (`queue:work --timeout` + SIGKILL escalation on the deployment side).

Suggested shape (systemd unit fragment, adapt paths):

```ini
[Service]
User=rev2-analysis
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
RestrictAddressFamilies=AF_UNIX AF_NETLINK
MemoryMax=2G
TasksMax=128
TimeoutStopSec=150
ExecStart=/usr/bin/php /srv/rev2square/artisan queue:work --timeout=120 --tries=1
```

`RestrictAddressFamilies` without `AF_INET/AF_INET6` blocks egress while
keeping the local database socket working (adjust if your database is
remote — prefer a local socket or an explicit proxy rule).

## 4. Environment reference

| Variable | Default | Meaning |
|---|---|---|
| `GHIDRA_HEADLESS` | _(empty)_ | Fixed path to `analyzeHeadless`; empty = not installed |
| `JADX_BIN` | _(empty)_ | Fixed path to `jadx` |
| `ILSPY_BIN` | _(empty)_ | Fixed path to `ilspycmd` |
| `BINARY_MAX_INSTRUCTIONS` | 20000 | Disassembly cap per run |
| `BINARY_MAX_SWEEP_BYTES` | 1048576 | Sweep cap per run |
| `BINARY_MAX_FUNCTIONS` | 2000 | Function cap per run |
| `BINARY_MAX_XREFS` | 10000 | Cross-reference cap per run |
| `BINARY_MAX_EVIDENCE_BYTES` | 524288 | Evidence JSON cap per run |
| `BINARY_ANALYSIS_TIMEOUT` | 120 | Job wall-clock budget (s) |

Changes apply on the next dispatched job; no code deploy needed, but a
long-running worker picks up new environment only after a restart.

## 5. What stays local-only

- Uploaded binaries and extracted evidence never leave the `artifacts`
  disk except as bounded, redacted, consent-gated excerpts to the
  configured Meta AI provider. No binary-analysis upload services, no
  external AI decompilers, no model downloads.
- AI inference uses the existing Meta integration exclusively; see the
  admin delivery report for provider configuration and usage ledgers.
