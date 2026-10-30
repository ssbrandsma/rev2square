# Binary target/backend completion — delivery notes (2026-10-29)

## Outcome

- The owner's failure — `arm32-thumb` on artifact 16 refused with "No
  available backend can disassemble…" — is root-caused (§1) and fixed
  at every layer: real ARM32 + Thumb disassembly now exists, archives
  analyze per member, the selector disables what cannot route, and
  dispatch is worker-verified.
- 7 of 18 advertised targets now route to verified backends and are
  proven by integration tests; the other 11 stay visible but disabled
  with precise reasons (no relabeling, no placeholders).
- Full suite green: **771 passed (3969 assertions)** — 60 new tests.
- Live end-to-end proven through the real queue daemon (§7).

## 1. Exact root cause (artifact 16 trace)

Requested: artifact 16 (`baby_9.0.2_r17157.bin`) + `arm32-thumb`.

```
artifact format=archive, arch=null
  → TargetResolver: no member concept; archive has no structural
    candidate → "carries no processor identification"… but the owner
    DID pass a target, so resolution "succeeded" with target=arm32-thumb
  → AnalysisRequest::defaultAdapter: needs formats() ∋ archive.
    php-native: elf/pe/prg/raw/ihex/srec — NO
    ghidra: elf/pe/macho/raw — NO (and binary missing anyway)
    jadx/ilspy: no arm32-thumb — NO
  → adapter = null
  → "No available backend can disassemble the 'arm32-thumb' target…"
  → missingBackendHint: candidateTargets() has no arm32-thumb entry
    → "No documented backend covers this pair yet."
```

Four compounding defects, not one: (a) no archive-member analysis
path at all; (b) no ARM backend anywhere (Ghidra absent; the bundled
adapter knew only 6502/Z80/8051); (c) the selector listed all 18
targets as equally queueable for every file; (d) the Ghidra language
table lacked a Thumb entry so even the hint failed. It was never
"Ghidra lacks ARM" — Ghidra was never installed, and nothing else
could take its place.

## 2. Selector inventory (all 18, as exposed)

mos6502, z80, i8051, x86, x86-64, arm32, arm32-thumb, aarch64, mips32,
riscv32, riscv64, ppc32, avr, pic18, msp430, dalvik, jvm, clr.
(The §4 Beratung list — xtensa, 8080, 6800/6809/68000, 6502-relatives,
AVR32, other PICs — is NOT advertised anywhere in the UI; nothing was
added or removed. Scope below is the actual list.)

## 3. Implemented target-to-backend mappings

| Target | Backend (verified version) | Formats | Disasm | Decomp |
|---|---|---|---|---|
| mos6502, z80, i8051 | php-native 1.0.0 (bundled) | raw/prg/ihex/srec/elf?/archive-member | yes | no (assembly only, by design) |
| arm32 | php-native 1.0.0, new A32 decoder | raw/elf/pe/ihex/srec/archive-member | yes | no |
| arm32-thumb | php-native 1.0.0, new T32 decoder | raw/elf/pe/ihex/srec/archive-member | yes | no |
| x86, x86-64 | objdump 2.44 (system binutils) | raw/elf/pe/ihex/srec/archive-member | yes | no (tool cannot) |
| aarch64, mips32, riscv32/64, ppc32, avr, msp430 | none — see §8 | — | no | no |
| pic18 | none — binutils cannot decode PIC; Ghidra+PIC-ext path documented | — | no | no |
| dalvik, jvm, clr | none installed (jadx/ilspy paths documented) | — | no | n/a |

ARM and Thumb are distinct decoders and distinct targets; the only
 sanctioned swap is the explicit arm32↔arm32-thumb override, which the
 resolver still permits within one run's family.

Decoder coverage (hand-verified against ARM encoding tables; every
 uncovered pattern emits `.word`/`.short` data, never a guessed
 mnemonic): A32 integer core incl. DP/MUL/LDR/STR(+B/H)/LDM/STM/
 branches/MRS/MSR/SVC/SWP/CLZ/BKPT. T32: complete 16-bit set plus
 32-bit BL/BLX, B.W, MOVW/MOVT, ADD/SUB.W, ADR.W, MRS/MSR, DMB/DSB/
 ISB. Uncovered: coprocessor/NEON/VFP, signed/dual halfword forms,
 exclusives, hints, TBB/TBH, 32-bit load/store/multiple, media.

## 4. Required import configuration per family

- Raw images (any target): explicit target + hex base address
  (required, validated against the address space); optional entry.
- Raw archive members: member + target required; base required once
  the job confirms the member is raw (clear `member_base_required`
  failure otherwise — bytes decide, the name never does).
- Structured members/files (ELF/PE): target may be omitted (machine
  type applies); contradicting targets fail (`member_target_mismatch`
  / `contradicts`), except arm32↔arm32-thumb.
- ihex/srec: target required; addresses come from records.
- Nothing is ever guessed: no silent base address, no inferred CPU,
  no Thumb-bit masking of vector candidates (shown unmasked, labeled
  candidate, profile left to device context).

## 5. Worker capability verification (actual)

- New `worker_capabilities` table + hourly `RefreshWorkerCapabilities`
  job (runs ON the queue worker) + `binary:worker-capabilities`
  command (show / `--refresh` / `--sync`) + 24h TTL + admin
  dashboard warning when an installed backend has no fresh snapshot.
- Dispatch rule: subprocess backends consult the snapshot, never the
  web container's binaries; the bundled adapter is exempt (identical
  code both sides). Missing/stale snapshot → clear refusal naming
  the exact remedy. Job re-probes at execution:
  `worker_unavailable` / `worker_mismatch` (version drift) failures.
- Live dev capture (queue container, 2026-10-29): php-native 1.0.0
  yes; objdump 2.44 yes; ghidra/jadx/ilspy correctly unavailable.
- Isolation finding (unchanged, still owner work): the dev queue
  worker runs as **root** inside its container; container boundary is
  the only isolation. Non-root + no-egress + cgroups remain required
  before untrusted uploads go near subprocess backends
  (`docs/binary-analysis-setup.md` §4). No live analysis of hostile
  input was run here beyond bounded local inspection.

## 6. Complete tested capability matrix

New suites: `tests/Unit/Binary/ArmDisasmTest.php` (9 tests, exact
instruction/text/target pins incl. `.word` fallbacks),
`tests/Feature/BinaryTargetMatrixTest.php` (50 tests). Real tools
only; no mocked adapter stands in for processor integration.

| Target | Variant | Input | Backend/version | Disasm | Decomp | Test result |
|---|---|---|---|---|---|---|
| arm32 | A32 LE | raw+base | php-native 1.0.0 | yes | no (honest) | pass, exact text+xref |
| arm32 | A32 LE | ELF32 EM_ARM | php-native | yes | no | pass, structural |
| arm32 | A32 LE | PE 0x1C0 | php-native | yes | no | pass, structural |
| arm32 | A32 LE | zip member | php-native | yes | no | pass, member provenance |
| arm32-thumb | T32 LE | raw+base | php-native | yes | no | pass, incl. 32-bit BL |
| arm32 vs thumb | mismatch demo | raw | php-native | n/a | n/a | pass (distinct listings) |
| x86 | 32-bit | raw+base | objdump 2.44 | yes | no (honest) | pass, exact text+xref |
| x86 | 32-bit | ihex records | objdump 2.44 | yes | no | pass |
| x86 | 32-bit | zip member (stored) | objdump 2.44 | yes | no | pass |
| x86-64 | 64-bit | raw+base | objdump 2.44 | yes | no | pass |
| x86-64 | 64-bit | ELF64 EM_X86_64 | objdump 2.44 | yes | no | pass, structural |
| x86-64 | 64-bit | PE+ 0x8664 | objdump 2.44 | yes | no | pass, structural |
| mos6502/z80/i8051 | — | raw+base | php-native | yes | no | pass (routing; decode pre-existed) |
| aarch64/mips32/riscv*/ppc32/avr/msp430/pic18/dalvik/jvm/clr | — | raw | none | — | — | pass (disabled + reason; refusal w/o run) |
| (all pairs) | aliases | ELF arch map | registry | — | — | pass (8 alias pins) |
| (dispatch) | stale/missing snapshot | raw+x86 | objdump | — | — | pass (refused, actionable) |
| (job) | version drift | raw+x86 | objdump | — | — | pass (`worker_mismatch`, non-retryable) |
| (job) | encrypted/oversized/empty/unknown member | zip | — | — | — | pass (dispatch + job codes) |
| (job) | contradicting member target | zip+ELF | — | — | — | pass (`member_target_mismatch`) |
| (auth) | guest/stranger/cross-project | — | — | — | — | pass (login/403/404) |

Skips: objdump-backed tests skip only when the binary is absent
(present here — all ran). No failures. Existing ELF/PCAP, retry,
dedupe and agent-context behavior still green in the full suite.
One pre-existing test was updated for the new reality
(`BinaryAnalysisTest::test_unsupported_target_refuses…` now pins
pic18 instead of x86-64) and the 4→5 backend registry pin in
`AdaptersTest`; both expectation changes are documented at the edit.

## 7. Live verification (real queue daemon, dev)

- `POST …/artifacts/17/analyses` (valid x86-64 ELF, structural) →
  run 2 `ready`, objdump 2.44: 8 insns, entry + call-target
  functions, 1 xref, `push rbp / mov rbp,rsp / call 0x40000b …`.
- `POST …/artifacts/18/analyses` (zip + `member=fw/image.bin`,
  arm32, base 0x8000) → run 3 `ready`, php-native: 5 A32 insns
  (`mov r0,#1 / bl 0x800c / bx lr …`), member provenance in evidence.
- Bare 64-byte ELF header → honest `no_executable_segments` (no
  placeholder success).
- Selector on the live page: x86-64 enabled ("Ready via GNU objdump"),
  pic18 disabled ("No backend covers this target for this file.");
  archive page shows the member picker with sizes/methods.

## 8. Artifact 16 practical outcome (honest — no forced import)

- Members: `jive.version` (115 B: "9.0.2 r17157…"), `board.version`
  (102 B: `baby:0001…0009, baby:25000`), `upgrade.md5` (87 B),
  `zImage` (3,191,832 B, ARM-LE kernel stub + payload — clean A32
  decode of sampled words, gzip blob at offset 2395584),
  `root.cramfs` (14,598,144 B, magic `28CD3D45` = CramFS-LE
  filesystem, **not executable code**).
- Integrity: recomputed md5 of both large members matches
  `upgrade.md5` exactly. No execution, no emulation, no external
  transmission, no AI calls; only bounded local reads.
- **No analysis was queued on artifact 16**: `zImage` has no known
  load address (guessing one would print fictional addresses) and
  `root.cramfs` needs filesystem extraction tooling that is not
  installed. Forcing a raw ARM import would violate the "never guess
  configuration" rule to manufacture a green screenshot. What the
  owner needs: device/u-boot docs for the zImage load address
  (then: member + arm32/arm32-thumb + base), and a cramfs extraction
  step for the rootfs (owner-approved tooling, then contained ELFs
  analyze normally). Failure history preserved (analyses=0; the
  original refusal was dispatch-time, no row was ever written).

## 9. Owner-operated setup steps

```sh
cd /srv/rev2square   # or the dev checkout
php artisan migrate --force                      # worker_capabilities table
php artisan binary:worker-capabilities --refresh # capture on the worker
php artisan binary:worker-capabilities           # confirm objdump yes/2.44
# scheduler must run (hourly refresh already registered)
```

- No deploy needed for config: new settings are code defaults +
  documented env vars (`OBJDUMP_BIN`, `BINARY_*`, snapshot TTL).
- To light up aarch64/mips/riscv/ppc/avr/msp430: install
  multi-arch binutils on the worker, set `OBJDUMP_BIN`, refresh,
  then qualify each machine mapping with a new integration test
  (protocol in `docs/binary-analysis-setup.md` §3 — probed arches
  stay unrouted until then). PIC needs Ghidra + a vetted PIC
  processor extension instead. Decompilation needs Ghidra +
  `ExportEvidence.java` qualification.
- Hardening before hostile input: non-root worker, no egress,
  cgroups, `TMPDIR` caps (setup doc §4); current dev worker is root.

## 10. Browser retest URLs (localhost)

- `http://localhost:8080/projects/qa-http-upload-20261029/artifacts/17`
  — x86-64 ELF: ready selector state, capability table, queue → run.
- `http://localhost:8080/projects/qa-http-upload-20261029/artifacts/18`
  — zip: member picker, ARM member queue.
- `http://localhost:8080/projects/qa-retest-draft-20261028/artifacts/16`
  — owner firmware: member list, disabled-with-reason targets,
  worker-state lines (no valid queue without device context).
- `http://localhost:8080/admin` — dashboard worker-snapshot warning
  appears only when an installed backend is unverified.
- No real-browser pass was performed in this environment (curl +
  assertion-level page checks only); desktop/mobile rendering of the
  picker/options is unverified.

## 11. Changed files (this task; other uncommitted work preserved)

New: `Disasm/Arm32Decoder.php`, `Disasm/ThumbDecoder.php`,
`Adapters/ObjdumpAdapter.php`, `Binary/ArchiveMember.php`,
`Models/WorkerCapability.php`, `Jobs/RefreshWorkerCapabilities.php`,
`Console/Commands/WorkerCapabilitiesCommand.php`,
`migrations/…_create_worker_capabilities_table.php`,
`tests/Unit/Binary/ArmDisasmTest.php`,
`tests/Feature/BinaryTargetMatrixTest.php`.
Edited: `Targets.php` (native flags, honest notes), `TargetResolver.php`
(member validation, public override check), `AnalysisRequest.php`
(worker gate, execution-aware hint), `CapabilityMatrix.php`
(verified-target rows, worker fields, `targetOptions()`),
`PhpNativeAdapter.php` (ARM decoders, archive formats, shared static
segments/functions/callGraph, ARM vector candidates),
`LinearSweep.php` (kind=data counts as data),
`AnalyzeBinaryJob.php` (member staging, execution re-checks),
`BinaryAnalysis.php` (`backend_timeout` retryable),
`ArtifactExtractor.php` (public limit helpers),
`ArtifactController.php` + `show.blade.php` (options, member picker,
worker lines), `BinaryAnalysisController.php` (member input),
`DashboardController.php` (+view: snapshot warning),
`AdapterRegistry.php`, `config/artifacts.php`, `routes/console.php`,
`docs/binary-analysis-setup.md`, `BinaryFixtures.php` (code + ELF +
zip builders), `BinaryAnalysisTest.php`, `AdaptersTest.php`,
`AdminUsageBudgetsTest.php`.

Incidental finding: this view mixes inline `@php(…)` with a
`@php…@endphp` block fatally under the app's Blade pipeline (500 on
compile). Member-option computation was moved to the controller
instead; the three inline directives were left untouched.

## 12. Remaining unsupported cases (no overstatement)

- AArch64/MIPS32/RISC-V 32+64/PowerPC32/AVR/MSP430: disassembly only
  via owner-installed multi-arch objdump (+ per-mapping tests) or
  Ghidra; decompilation only via Ghidra. Endianness (notably MIPS
  either-endian, PPC big-endian) must be qualified per mapping —
  the adapter currently assumes nothing beyond the verified i386 pair.
- PIC18 (+ all other PICs): no binutils coverage; Ghidra + vetted
  processor extension required. Not started.
- Dalvik/JVM/CLR: JADX/ILSpy not installed; adapters refuse honestly.
- Decompilation/pseudocode: unavailable for every target; the UI says
  so per backend. No AI output is presented as tool output anywhere.
- Filesystem images (cramfs/squashfs/ubifs): identification only;
  extraction tooling + qualification are separate owner steps.
- Mixed ARM/Thumb interworking inside one run: not attempted — the
  states are separate targets by design; per-function state remains
  user-selected.
