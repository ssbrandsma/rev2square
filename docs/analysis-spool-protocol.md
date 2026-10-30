# Analysis spool protocol (schema 1)

Normative job/evidence shapes for the coordinator ↔ runner boundary.
The runner (`docker/analysis/runner.py`) and the coordinator
(`App\Services\Binary\Sandbox\SpoolClient` + `SandboxedBackend`
adapters) implement exactly this; unknown `kind` values are ignored
by older runners, and unknown top-level evidence keys are stripped
with a warning. Conformance is covered by
`docker/analysis/tests/test_runner.py` (runner side) and
`tests/Unit/Binary/Sandbox/*` (coordinator side).

## 1. Capability probe

Spool: `<job>/job.json`:

```json
{"schema": 1, "tool": "objdump", "kind": "probe"}
```

Results: `<job>/status.json` (this doubles as the probe answer):

```json
{"schema": 1, "tool": "objdump", "tool_version": "GNU objdump ... 2.40",
 "runner_arch": "aarch64", "image_digest": "sha256:...|build-local",
 "tool_image": "rev2square/binutils:2.40-2", "answered_at": 0,
 "architectures": ["i386", "arm", "aarch64"]}
```

`architectures` is objdump-only (parsed `objdump -i` rows, same
grammar as `ObjdumpAdapter::parseArchitectures`). The coordinator
rejects runners whose normalized arch (`aarch64/arm64`,
`x86_64/amd64`) differs from its own — emulated execution is never
silently qualified.

`fixture` is jadx-only (smoke qualification): the runner decompiles
its baked `dex-smoke.dex` and reports `{"name": "dex-smoke.dex",
"ok": true}` only when the known class marker appears in the emitted
`.java`. The coordinator declares jadx ready only with `ok: true`;
runners predating the fixture (no `fixture` key) stay
not-operational until rebuilt. Probe answers keep version/arch even
when the fixture fails, so diagnostics can tell "runner down" apart
from "runner up, decompile broken".

## 2. Analysis job

Spool: `<job>/job.json` + `<job>/<input>` (confined basename,
≤ 128 chars, no slashes):

```json
{"schema": 1, "tool": "ghidra", "kind": "analyze",
 "profile": "headless",
 "params": {"language": "ARM:LE:32:v8", "base": 134217728, "entry": null},
 "input": "input.bin", "input_size": 1024,
 "input_sha256": "<64 hex>",
 "limits": {"wall_seconds": 480}}
```

The runner re-verifies size + sha256 after staging into its private
workdir, enforces `wall_seconds` (1–3600) on the process group, and
honors `<job>/cancel` (written by the coordinator) between polls.

### Profiles (runner-enforced argv)

| Tool | Profile | Params | Executed shape |
|---|---|---|---|
| objdump | `auto` | — | `objdump -d -M intel -w <in>` |
| objdump | `binary` | `machine` (baked allowlist), `vma` (u64) | `objdump -D -M intel -w -b binary -m M --adjust-vma=V <in>` |
| objdump | `ihex` / `srec` | `machine` (baked allowlist) | `objdump -D -M intel -w -b FMT -m M <in>` |
| ghidra | `headless` | `language` (verified set), `base` (u64), `entry` (u64?) | `analyzeHeadless <proj> -import <in> -overwrite -processor L -cspec default -postScript ExportEvidence.java <out> <base> <entry\|none> -deleteProject` |
| jadx | `apk` | `threads` (1–4) | `jadx --no-src-save-cache -j T -d <out> <in>` |
| ilspy | `assembly` | — | `ilspycmd -p -o <out> <in>` |
| extract | `7zz-list` | — | `7zz l -slt -ba <in>` |
| extract | `7zz-extract` | — | `7zz x -y -o<out> <in>` |
| extract | `unsquashfs-list` | — | `unsquashfs -ll -d <out> <in>` |
| extract | `unsquashfs-extract` | — | `unsquashfs -d <out> -f <in>` |
| extract | `ubireader-extract` | — | `ubireader_extract_files -o <out> <in>` |

Verified Ghidra languages (runner allowlist = adapter table):
`x86:LE:32:default`, `x86:LE:64:default`, `ARM:LE:32:v8`,
`ARM:LE:32:v8T` (Thumb-default), `AARCH64:LE:64:v8A`.
Documented-but-unverified ids are refused here even though the UI
may list them as candidates.

### Terminal statuses (`results/<job>/status.json`)

`done` (`exit_code`, `elapsed_ms`, `evidence_bytes`, `files`,
`truncated`), `timeout` (`wall_seconds`), `cancelled`, or `failed`
(`error`, ≤ 500 chars). The coordinator maps these to
`analysis_timeout` / `cancelled_race` / `runner_failed`; silence past
wall + grace becomes `environmental`.

## 3. Evidence

### objdump (`tool.out` + envelope)

`evidence.json`: `{schema: 1, tool, exit_code, output_file:
"tool.out", output_truncated}`. The bounded text goes through the
existing PHP parser (pure string code — it executes nothing).

### Ghidra (`evidence.json` from ExportEvidence.java)

```json
{"schema": 1, "tool": "ghidra", "tool_version": "12.2",
 "language": "ARM:LE:32:v8", "compiler_spec": "default",
 "image_base": "0x8000", "entry": "0x8000",
 "memory": [{"name": "ram", "start": "0x8000", "size": 0,
             "execute": true, "initialized": true}],
 "functions": [{"name": "entry", "entry": "0x8000", "size": 0}],
 "xrefs": [{"from": "0x8000", "to": "0x800c", "kind": "call"}],
 "disassembly": {"lines": [{"address": "0x8000", "bytes": "0100a0e3",
                             "text": "MOV r0,#1"}],
                 "truncated": false},
 "decompilation": {"functions": [{"name": "entry", "entry": "0x8000",
                                  "c": "..."}],
                   "truncated": false},
 "warnings": []}
```

Addresses are hex strings; the adapter converts to integers and
fails the run on any malformed value (never silent zero). Bounds:
500 functions, 50 calls each, 20000 instructions, 50 decompiled
functions / 200 KiB pseudocode, per-line and per-warning truncations
inside the script.

### Tree tools (jadx / ilspy / extract)

```json
{"schema": 1, "tool": "jadx", "exit_code": 0, "profile": "apk",
 "files": [{"path": "sources/com/Foo.java", "size": 0,
            "sha256": "<64 hex>"}],
 "inline": {"sources/com/Foo.java": "..."},
 "truncated": false,
 "stored_files": [{"path": "bin/app", "size": 0, "sha256": "<64 hex>"}]}
```

`stored_files` is extract-only; the bytes live at
`results/<job>/files/<sha>.bin` (re-hashed at copy). Collection
bounds: 2000 files, depth 12, 32 KiB per inline file / 256 KiB
total, 5 × 8 MiB stored. Symlinks are skipped, never followed.

### Coordinator envelope (`binary-analysis/1`)

Adapters normalize runner evidence into the established envelope
(validated by `EvidenceValidator`): `functions` (id/address/source),
`xrefs`, `calls`, `disassembly.windows`, plus `decompilation`,
`reconstruction` (`files`, `inline`, `classes`), or `extraction`
(`profile`, `files`, `inline`, `promoted`). Unknown keys are
stripped; over-bound, mistyped, HTML-bearing, or path-unsafe content
fails the run closed.

## 4. Limits summary

| Layer | Control | Default |
|---|---|---|
| Runner | wall clock per run | 480 s (`BINARY_SPOOL_WALL_SECONDS`) |
| Runner | tool text / evidence / inline / stored | 8 MiB / 512 KiB / 256 KiB / 5 × 8 MiB |
| Runner | manifest files / depth | 2000 / 12 |
| Coordinator | grace after wall clock | 30 s (`BINARY_SPOOL_GRACE_SECONDS`) |
| Queue | visibility timeout | 900 s (`ANALYSIS_QUEUE_RETRY_AFTER`) |
| Job | evidence JSON fit cap | 512 KiB (`BINARY_MAX_EVIDENCE_BYTES`) |
| Concurrency | global spool lock | 1 heavy job per server |
