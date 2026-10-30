# ILSpy smoke fix (unmatchable arch grep → structured RID gate + decompile test)

## 1. Failing command, established from evidence

The failing command is:

```
grep -q 'linux-arm64' /provenance/dotnet-runtimes.txt   → exit 1
```

Established three ways, without assuming:

1. The server log shows every earlier chain command succeeding
   (stdlib imports OK, `ilspycmd --version` printing both version
   lines). The remaining commands are this grep, an infallible
   `mkdir -p` on writable `/tmp`, and the pure-python dry-run
   whose `ilspycmd` argv match is proven by construction.
2. Real `dotnet --list-runtimes` output, captured from
   `mcr.microsoft.com/dotnet/runtime:10.0`, is:
   `Microsoft.NETCore.App 10.0.14 [/usr/share/dotnet/shared/...]` —
   it contains **no runtime-identifier token on any
   architecture**, so the grep can never match anywhere.
3. The old grep was replayed against that real output here and
   reproduces exit 1 exactly.

Root cause of the bad check: `--list-runtimes` is an inventory
listing; the RID lives in the documented `Host:` fields of
`dotnet --info` (`RID:`, `Architecture:`) — verified in the same
capture.

## 2. Changed files and rationale

`docker/analysis/Dockerfile.ilspy` only (plus tests):

- Root provenance RUN now also captures `dotnet --info` to
  `/provenance/dotnet-info.txt`, so the recorded output the arch
  gate inspects is preserved in the image. The (now assertion-free)
  `--list-runtimes` capture stays as inventory.
- The single six-command `&&` chain is split into five labeled
  `--- smoke: … (www-data)` layers: stdlib imports, `ilspycmd
  --version`, RID gate, decompile gate, runner dry-run. A future
  failure names its gate in the build log.
- The arch gate matches the documented fields, anchored:
  `RID: linux-arm64` and `Architecture: arm64` from a live
  `dotnet --info`. No substring assumption, no `|| true` — a
  wrong-arch runtime still fails the build loudly.
- New integration gate: decompile the framework's own
  `System.Console.dll` (`-o` to tmpfs, cleaned up after) and
  assert exit 0 plus `class Console` and `ReadLine` in the
  output. `--version` alone only proves startup.
- Header comment records the verified tool-resolution chain:
  the `ilspycmd` NuGet id is a metapackage mapping
  win-x64/linux-x64/any; linux-arm64 falls back to the
  `ilspycmd.any` binaries (confirmed by nupkg inspection and by
  the server's `--version` output).

Non-root execution, read-only root expectations, checksums, and
the runner dry-run assertion are preserved; nothing else in the
image changed.

## 3. Audit of the other four runners' smokes

Each `grep` in the other final images was inspected for the same
brittleness (assumed substrings in third-party output). None found:

| Image | Output-dependent matches | Verdict |
|---|---|---|
| binutils | `objdump -i \| grep -q '^  i386$'` — anchored full-line match on objdump's decades-stable `-i` listing; proven live on the x86 build. Dry-run grep matches our own argv JSON. | sound, unchanged |
| ghidra | exit-code checks only (`java -version`); dry-run grep matches our own argv (`ExportEvidence.java`) | sound, unchanged |
| jadx | exit-code checks only (`jadx --version`); dry-run grep matches our own argv | sound, unchanged |
| extract | `unsquashfs … \| grep -qi 'squashfs'` — matches the tool's own name in its usage banner (case-insensitive); plus structural `test -x` / `test -s` gates | acceptable, unchanged |

## 4. Verification performed (x86_64 dev)

- Old grep replayed against real `--list-runtimes`: exit 1
  reproduced → bug mechanism confirmed.
- New RID/Architecture matchers run against real `--info`:
  field shape matches; the `linux-arm64` variant correctly
  rejects x64 (no vacuous pass — it only passes where the
  runtime truly reports ARM64).
- Decompile gate validated end to end with the exact
  `ilspycmd.any` 11.2.0.10050 binaries the ARM64 server
  resolves, against the runtime image's own
  `System.Console.dll`: exit 0, `class Console` ✓, `ReadLine` ✓,
  single 152 KB output file.
- `sh -n` over all ilspy RUN bodies: clean.
- `AnalysisDeploymentTest`: 19 passed, 476 assertions. The new
  `test_ilspy_smoke_uses_structured_arch_signal_and_decompiles`
  fails on the pre-fix Dockerfile (stash round-trip) and passes
  after.
- Full suites not re-run: no app or runner code changed.

## 5. Per-image build report

| Image | Status | Evidence |
|---|---|---|
| ilspy 11.2.0.10050 | **failed** (server, this smoke) → fixed, **untested** natively | fix validated by format replay + real-binary decompile on x86; native ARM64 build pending |
| binutils 2.40-2 | passed (x86 build + www-data runtime probe, previous turn) | unchanged since; ARM64 rebuild still pending |
| extract 1.0 | partial (x86: all steps through the ARM64 execution gate) | unchanged; full ARM64 pass pending |
| jadx 1.5.6 | partial (x86: all steps through the ARM64 execution gate) | unchanged; full ARM64 pass pending |
| ghidra 12.2 | cancelled/untested | unchanged; full ARM64 build + qualification pending |

No native-ARM64 qualification is claimed from x86 evidence.

## 6. Owner-operated rebuild (production ARM64)

Only the ilspy image changed. In `/srv/rev2square`:

```sh
cd /srv/rev2square
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build ilspy-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d ilspy-runner
docker images --digests 'rev2square/ilspy'
```

In the build log, expect all five `--- smoke: … (www-data)
---` gates to pass, including `linux-arm64 runtime RID` and
`ilspycmd decompiles a known assembly`. If any gate fails, its
label names the exact check — report the label and the lines
above it.

Post-build validation:

```sh
docker inspect --format '{{.Repository}}:{{.Tag}} {{.Architecture}}' rev2square/ilspy:11.2.0.10050
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/ilspy:11.2.0.10050 \
  python3 /opt/runner/runner.py --dry-run --tool ilspy --profile assembly --params-json '{}' --workdir /tmp/dry
```

Expect `arm64`, and a dry-run argv containing `/opt/ilspy/ilspycmd`.
After the remaining runners are also rebuilt, refresh worker
capability snapshots and qualify the runner-backed mappings per
`docs/binary-analysis-setup.md` §2 steps 3–4 before routing
traffic.

No production changes, commits, pushes, or deployments were made
from here.
