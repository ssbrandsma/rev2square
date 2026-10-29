# Binary investigation system — delivery report (2026-10-28)

Extends the Revival Agent + artifact workflow with format detection,
target configuration, deterministic local analysis, and consent-gated
agent tools for executables, firmware, and legacy binaries. All AI
inference uses the existing Meta integration; local work is pure PHP
plus explicitly unavailable external backends.

Working boundaries honored: no production, secrets, credentials,
access changes, execution of uploads, root/Docker administration,
installs, pushes, deploys, or live AI calls. All unrelated
uncommitted work (branding, admin, presentation/media, QA fixes) is
preserved. No existing artifacts or failure evidence were deleted.

## 1. What actually works

- **Upload → detect → inspect.** Content-based detection with recorded
  evidence for ELF, PE, Mach-O (thin + FAT), DEX, APK, Java class,
  Intel HEX, S-record, C64 PRG, ZIP-family archives, and declared-raw
  images. PCAP/PCAPNG behavior is unchanged.
- **Capability reporting.** Every artifact page shows a per-backend,
  per-target matrix with independent levels (supported / partial /
  unsupported / dependency_unavailable / configuration_required) —
  detection passing never implies disassembly.
- **Target resolution.** Structural metadata first, explicit validated
  configuration second, heuristics as labelled suggestions only.
  Contradictory overrides are rejected with explanations; overrides
  record their author.
- **Deterministic analysis (bundled `php-native` 1.0.0).** Linear-sweep
  disassembly for **MOS 6502, Z80, Intel 8051**; function seeding
  (entry/symbol/SYS/call-target); one-level call graphs; candidate
  reset/interrupt vectors; candidate I/O notes; evidence JSON with
  full provenance on the private artifacts disk.
- **Job lifecycle.** uploaded → extracting → needs_configuration →
  queued → analyzing → ready / partial / failed / cancelled, with
  idempotent dispatch (hash + backend/version + config), authorized
  cancel, and retry only for recoverable failures.
- **Agent tools.** `inspect_binary`, `list_binary_targets`,
  `configure_binary_target` (dry run), `request_binary_analysis`,
  `get_analysis_status`, `list_binary_functions`, `read_disassembly`,
  `get_cross_references`, `get_call_graph_slice` — all consent-gated,
  allowlisted, same-project-scoped, bounded, with untrusted-data
  labelling on extracted content.
- **Consent withdrawal.** One click per conversation; future tool calls
  are refused immediately (consent re-checked every call).
- **Viewers.** Detection evidence, metadata panels, strings with file
  offsets, memory maps (offset vs address labelled), function lists,
  disassembly with stable `#addr-…` anchors, vectors, I/O notes, and
  the persistent reconstructed-code disclaimer. All extracted content
  escaped; citations extend the existing `[A{id}]` scheme.

## 2. Support matrix

Backend `php-native/1.0.0` is bundled. `ghidra`, `jadx`, `ilspy` are
**not installed** — rows below marked accordingly are honest
unavailability reports, not support claims. Language IDs listed for
Ghidra are documented values, explicitly unverified here.

| Format | CPU/variant | Detection | Disassembly | Decompilation | Backend/version | Integration-tested | Limitations |
|---|---|---|---|---|---|---|---|
| ELF | x86, x86-64, ARM(→arm32), AArch64 | supported | dependency_unavailable | dependency_unavailable | ghidra (absent) | detection only | Needs Ghidra install + verification protocol |
| ELF | MIPS/PPC/RISC-V machine ids | supported (named, arch unmapped) | dependency_unavailable | dependency_unavailable | ghidra (absent) | detection only | Same; MIPS-unsupported warning preserved |
| ELF | unknown machine + user target 6502/Z80/8051 | supported | supported (exec sections) | unsupported | php-native/1.0.0 | unit-level (segments path) | No full ELF e2e for these targets |
| PE32/PE32+ | x86, x86-64, ARM, AArch64, MIPS, RISC-V | supported | dependency_unavailable | dependency_unavailable | ghidra (absent) | detection only | Imports/exports/CLR parsed; no code lift |
| PE + CLR | clr (managed) | supported | dependency_unavailable | dependency_unavailable | ilspy (absent) | detection + routing | Runtime detection only; no method bodies |
| Mach-O thin | x86, x86-64, ARM, AArch64, PPC | supported | dependency_unavailable | dependency_unavailable | ghidra (absent) | detection only | FAT64 rejected; members described, first parsed |
| Mach-O FAT | per-member | supported | dependency_unavailable | dependency_unavailable | ghidra (absent) | detection only | One member at a time; no recursion |
| DEX | dalvik | supported | dependency_unavailable | dependency_unavailable | jadx (absent) | detection only | Strings/classes/methods listed; no bytecode decode |
| APK | dalvik (+ nested ELF arch) | supported | dependency_unavailable | dependency_unavailable | jadx (absent) | detection only | Manifest string pool; arsc not decoded; split markers flagged |
| Java class | jvm | supported | dependency_unavailable | dependency_unavailable | jadx (absent) | detection only | Metadata + pool census; no bytecode decode |
| JAR-style archive | jvm hint | supported | dependency_unavailable | dependency_unavailable | jadx (absent) | detection only | Member census only |
| Intel HEX / S-record | z80 e2e; 6502/8051 same path | supported | supported (record segments) | unsupported | php-native/1.0.0 | **e2e (z80)** | Checksums validated; overlaps last-wins + reported |
| C64 PRG | mos6502 (+6510 context) | supported | supported (payload only, never BASIC text) | unsupported | php-native/1.0.0 | **e2e** | BASIC detokenized; SYS targets seed functions; ML-only needs filename hint (labelled) |
| Raw BIN/ROM | mos6502, z80, i8051 (user config) | n/a (declared) | supported (flat + base) | unsupported | php-native/1.0.0 | **e2e (8051)** | Explicit opt-in; base/entry validated; single flat region, no banks |
| ZIP (generic) | — | supported | unsupported | unsupported | — | census only | Stored/deflated; encrypted + odd methods reported, never extracted |
| ARM Thumb | arm32-thumb | heuristic candidate | dependency_unavailable | dependency_unavailable | ghidra (absent) | heuristic test | Odd FUNC symbol LSB; tentative label |
| 8080/8085, 6800/6809/68k, AVR, PIC, MSP430, Xtensa, PPC32… | — | unsupported | unsupported | unsupported | — | not claimed | Listed as registry notes where relevant; nosilent family claims |

Disassembly-capable today: **mos6502, z80, i8051 only**, via the
bundled adapter. Deterministic decompilation: **no target**. AI
pseudocode: optional, agent-side, always labelled tentative, never
presented as decompiler output.

## 3. Backend versions and availability

- `php-native/1.0.0` — available (in-process, no subprocess).
- `ghidra` — binary absent (`GHIDRA_HEADLESS` empty). Ships: probe,
  capability rows, validated argv builder, `backend_unverified` refusal.
- `jadx` — binary absent (`JADX_BIN` empty). Same contract.
- `ilspy` — binary absent (`ILSPY_BIN` empty). Same contract.
- No Java, .NET, Ghidra, or toolchain downloads in this environment;
  installs need owner approval and were not performed.

## 4. E2E-verified paths (synthetic fixtures, generated in-code)

- C64 PRG (BASIC + SYS) → 6502 disassembly, `sys_target` + `call_target`
  functions, anchors, viewer disclaimer.
- Raw + user config (i8051, base 0x0) → disassembly, reset-vector
  candidate, refusal without base address.
- Intel HEX + user target (z80) → record-segment disassembly at file
  addresses, `CALL $4008`.
- PE native vs CLR routing; APK (2 DEX + nested `.so` + manifest pool);
  contradictory-override/unknown-target/bad-address refusals;
  x86-64 refusal naming Ghidra with zero runs created.
- Idempotent re-request (one job, truthful "already queued"); stale
  version → new run; cancel-before-start exits clean; environmental
  retry requeues while permanent refuses; worker-crash `failed()`;
  evidence trimming keeps functions, sheds listing lines.
- Agent tools with consent; refusal without consent/scope; withdrawal
  blocks future calls; injection/HTML payload stays escaped data with
  `correlation, not proof` compare semantics; APK-manifest + PCAP-DNS
  cross-artifact match; destroy removes analysis evidence;
  suspended-uploader revocation (`access_revoked`).

## 5. Storage, consent, provenance

- Binaries stay private research artifacts (never public attachments).
  Extraction snapshots + `*.analysis.json` evidence live on the
  `artifacts` disk under `{project_id}/`; destroy deletes all three.
- Consent text now names disassembly/function excerpts, the Meta
  provider, the single conversation scope, and the local-vs-AI split.
  Withdrawal nulls `artifact_consent_at`; tools re-check per call.
- Every run stores adapter id + version, target, resolved config, and
  override authorship; viewers and tool output echo backend/version;
  dedupe keys bind artifact hash + backend/version + config so backend
  upgrades queue fresh runs instead of silently reusing stale ones.
- AI annotations live only in conversations; evidence JSON is never
  overwritten by model output. Usage accounting reuses the existing
  Meta ledger (no new providers).

## 6. Migrations and rollback

- `000036_create_binary_analyses` — new table (dedupe unique,
  FK cascade to artifacts). Rollback drops the table; evidence files
  would orphan (documented; destroy path normally removes them).
- `000037_widen_artifact_state` — `state` varchar 16→32 for
  `needs_configuration`. Rollback to 16 fails if such rows exist
  (none can exist after rolling back the feature).
- No changes to committed migrations; no data migration of existing
  rows (old snapshots keep schema `1` and keep working).

## 7. Owner steps required

See `docs/binary-analysis-setup.md` (reviewable, no privileged step
executed): backend installs (Ghidra/JADX/ILSpy paths + env), the
backend verification protocol (currently: no external backend may be
marked usable — the headless export path has no verified run
anywhere), and the isolated analysis-worker design (non-root,
no-egress, cgroups, per-job temp dirs, suggested systemd fragment).
Until isolation is deployed, treat external-backend analysis of
untrusted uploads as not permitted; the UI fails closed regardless.

## 8. Tests run

- New: `tests/Unit/Binary/{DisassemblerTest` (7 golden vectors),
  `FormatDetectionTest` (10), `BinaryParsersTest` (12),
  `AdaptersTest` (5 incl. argv-injection refusals)`}`,
  `tests/Feature/BinaryAnalysisTest` (21 e2e). All synthetic;
  provenance in `tests/Fixtures/Binary/README.md`.
- Full suite: **OK 674 tests / 3385 assertions** (was 619; +55, zero
  regressions — ELF/PCAP/agent/admin/media suites all pass).
- `pint --test`: PASS, 410 files. `vite build`: ✓ 684ms.
- Skips/failures: none. No test was weakened; two pre-existing
  messages updated for the wider format list (message text only; the
  unknown-bytes rejection contract is preserved and still tested).
- Not performed: real-browser validation (no browser in this
  environment — walkthroughs below are for the owner), live AI calls,
  external-backend runs (binaries absent).

## 9. Localhost browser walkthroughs (owner)

Base `http://localhost:8080`. Use a project you own; fixtures below
are synthetic — generate bytes via the corps in `BinaryFixtures` or
craft equivalents by hand (exact bytes in the test file):

1. **PE detection + capabilities + refusal.** Agent page → upload the
   minimal PE as `firmware.bin` → open the file page
   (`/projects/{slug}/artifacts/{id}`): detection evidence (MZ + PE
   signature), imports panel, capability matrix with Ghidra rows
   `dependency_unavailable`. Request target `x86-64` → refusal naming
   Ghidra, zero runs.
2. **PRG end-to-end.** Upload `game.prg` (BASIC `10 SYS 2064` + 6502
   payload) → file page shows BASIC listing + SYS target →
   `Queue analysis` with no target (structural 6502) → run page shows
   provenance, `Reconstructed code…` banner, function list
   (`sys_target`, `call_target`), disassembly with `#addr-…` anchors.
3. **Raw 8051.** Upload unknown bytes with `Treat as raw binary image`
   → `Needs configuration` → request `i8051` without base → guided
   error → with `0x0` → ready run with reset-vector candidate.
4. **APK.** Upload synthetic APK → DEX counts, manifest strings,
   nested `.so` arch, `resources_arsc_not_decoded` partial state.
5. **Consent.** Agent conversation with consent → withdraw via the new
   button → further evidence tools refuse; banner confirms.
6. **Dedupe honesty.** Request the same analysis twice → second
   reports "already queued/running/exists", one job total.

## 10. Changed files (selected)

New: `app/Models/BinaryAnalysis.php`,
`app/Services/Binary/*` (detectors, 9 parsers, 3 decoders + sweep,
4 adapters, targets/resolver/matrix/request),
`app/Jobs/AnalyzeBinaryJob.php`,
`app/Http/Controllers/BinaryAnalysisController.php`,
`app/Services/Agent/BinaryTools.php`, 2 migrations, 5 test files +
`tests/Support/BinaryFixtures.php`, `docs/binary-analysis-setup.md`,
views `artifacts/analysis-show.blade.php` + show/index/agent edits.
Extended: `ArtifactExtractor` (registry), `ArtifactUploadService`
(`as_raw`), `ArtifactEvidence` (context + viewers + compare),
`ArtifactTools::compare_evidence` (binary surfaces), `AgentPrompt`,
`AgentTurnRunner` (progress tracking), `AgentController` (tools +
withdrawal), routes, `config/artifacts.php`.

## 11. Limitations and next steps

- No deterministic decompiler for any target; no AVR/PIC/68k/8080/
  MSP430 disassembly (registry notes say so; no silent claims).
- External adapters are probe + argv-safety + refusal only; the
  headless export/verification work is future developer work, then
  owner install + isolation.
- No banked-memory or Harvard-segment configs; no register-map upload;
  8051 SFR/C64 I/O notes are pattern candidates only.
- `function` sizes tentative; callers attributed to enclosing linear
  function starts; no recursive CFG or type recovery.
- Suggested next slices: (a) Ghidra export-script + verification rig
  on an owner-provided host; (b) JADX/ILSpy output parsers; (c) AVR
  word-oriented decoder (pure PHP, like the three shipped ones);
  (d) register-map/linker-map attachments for embedded runs;
  (e) banked-image configuration.
