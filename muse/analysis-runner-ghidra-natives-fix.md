# Ghidra natives fix (g++ toolchain, linux_arm_64 install, fixture smoke)

## 1. Root causes (three stacked defects)

1. **Missing C++ compiler.** The natives stage installed `gcc`
   but not `g++`; `decompile`/`sleigh` are C++, so Gradle failed
   with `Could not find C++ compiler 'g++' in system path`.
2. **Wrong output paths.** The Dockerfile searched
   `*os/linux_aarch64/decompile` and `*os/linux_arm64/decompile`.
   The real platform name — from `GPL/nativePlatforms.gradle` in
   the pinned tag and confirmed by the Gradle log — is
   **`linux_arm_64`**, and per `GPL/nativeBuildProperties.gradle`
   `buildNatives` links into `<module>/build/os/<platform>/`.
3. **Missing install copy.** `buildNatives` does not install: even
   with correct paths, nothing copied `build/os/…` into the
   `os/linux_arm_64/` directories Ghidra loads at runtime, so the
   final image would have kept only the bundled x86_64 binaries.

## 2. Verified facts (pinned 12.2 tag + release zip, inspected)

- Release zip hash re-verified on download; top dir
  `ghidra_12.2_PUBLIC/`; `support/gradle/gradlew` (Gradle 9.8.0
  wrapper) present — building natives from the distribution is
  the documented flow and is feasible.
- `buildNatives` builds exactly five `linux_arm_64` executables:
  `Decompiler/{decompile,sleigh}`,
  `DemanglerGnu/{demangler_gnu_v2_41,demangler_gnu_v2_24}`,
  `FileFormats/{lzfse}`. All C/C++ sources present in the zip
  (115 decompile `.cc`, 24 demangler `.c`, 8 lzfse `.c`).
- No other module targets `linux_arm_64` (DMG/PDB/decompile-src
  have no such natives). C99/C++17 only — no extra `-dev`
  packages, bison, or flex needed.
- The runtime loads natives from `os/<platform>/` with the same
  platform mapping, so `os/linux_arm_64/` is the install target.
- `Base/ghidra_scripts/` (201 entries, incl. in zip) exists, so
  the `ExportEvidence.java` install path is valid.
- `ldd` is present in the pinned `bookworm-slim` base (digest
  re-confirmed on pull); `file`'s `ARM aarch64` phrasing and a
  no-`file` ELF `e_machine == 183` check were both validated
  against a real ARM64 binary (accept) and x86-64 (reject).

## 3. Changed files

- `docker/analysis/Dockerfile.ghidra`:
  - Builder installs `g++` (+ explicit `gcc`); compilers stay
    out of the final image (regression-guarded).
  - Five labeled `--- natives:` steps: toolchain install,
    `gcc`/`g++ --version` gate *before* the expensive work,
    pinned download + sha + `gradlew` presence check,
    `buildNatives`, and deterministic verify + install: each of
    the five `build/os/linux_arm_64/` outputs must exist,
    execute-bit set, and `file` as `ARM aarch64`; per-dir exact
    counts (2/2/1); explicit `cp` into `os/linux_arm_64/` +
    `chmod 755`; installed ARM64 `decompile` must *differ* from
    the bundled x86_64 copy (mix-up guard); hashes recorded to
    `/provenance-arm64-natives.sha256`.
  - Final stage adds `libstdc++6` (g++ output needs it), copies
    the hash file + a 20-byte `fixtures/arm32-smoke.bin`
    (byte-identical to `BinaryFixtures::codeArm32()`), and
    re-verifies installed hashes with `sha256sum -c` (proves
    COPY carried the verified files).
  - Seven labeled `--- smoke:` www-data gates: stdlib imports,
    JDK executes, AArch64-ELF check (no `file` package),
    `ldd` Completeness (`not found` fails loudly;
    `libstdc++` linkage asserted), headless import-only run,
    full fixture decompile through the **real
    `runner.build_argv`** (no hand-written copy) with evidence
    assertions (schema, `entry`@0x8000, exact `0100a0e3` bytes,
    decompiled C containing a return), and the dry-run argv check.
- `docker/analysis/fixtures/arm32-smoke.bin`: new 20-byte A32
  fixture (`mov r0,#1; bl; bx lr; …`), provenance-tied to the
  PHP suite by test.
- `tests/Feature/AnalysisDeploymentTest.php`: toolchain/install/
  gate assertions + fixture-bytes sync test.

## 4. Audit notes (req 6)

- `mv …/ghidra_${VERSION}_PUBLIC` target, `gradlew` path,
  `ghidra_scripts` dir, Temurin `--strip-components=1` layout:
  all verified against the real archives; each remains
  fail-closed in the build regardless.
- `make`/`zip` stay installed in the builder as cheap insurance
  (no Make tasks found in the native modules, but removal buys
  nothing in a builder-only layer).
- Raw-binary import uses the runner's exact argv (no `-loader`
  flag, mirroring production). If headless ever requires an
  explicit loader for raw input, the import gate fails loudly
  with Ghidra's message — that outcome would be a real finding
  about production jobs, not a smoke artifact, and is the
  correct follow-up trigger.

## 5. Verification performed (x86_64 dev)

- `sh -n` over all ghidra RUN bodies: clean.
- Embedded python snippets: parse-checked; the evidence
  assertions executed green against representative
  `ExportEvidence`-shaped JSON (field names taken from the
  actual script).
- `AnalysisDeploymentTest`: 21 passed, 501 assertions. The new
  toolchain test fails on the pre-fix Dockerfile (stash
  round-trip) and passes after; the fixture test pins the bytes.
- No native build or headless run executed here (ARM64-only by
  construction).

## 6. Per-image build report

| Image | Status |
|---|---|
| ghidra 12.2 | **failed** (server: missing g++) → fixed, **untested** natively; first build exercises toolchain gate → 20–40 min buildNatives → install gates → 7 runtime gates |
| binutils 2.40-2 | passed (x86); unchanged; ARM64 rebuild pending |
| extract 1.0 | partial x86 (through arch gate); unchanged; ARM64 pass pending |
| jadx 1.5.6 | partial x86 (through arch gate); unchanged; ARM64 pass pending |
| ilspy 11.2.0.10050 | smoke fixed last turn, validated by replay; unchanged; ARM64 pass pending |

No native-ARM64 qualification is claimed from x86 evidence.

## 7. Owner-operated rebuild (production ARM64)

Only the ghidra image changed. In `/srv/rev2square` (allow 40+ minutes):

```sh
cd /srv/rev2square
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build ghidra-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d ghidra-runner
docker images --digests 'rev2square/ghidra'
```

In the build log expect all five `--- natives: …` steps and all
seven `--- smoke: … (www-data)` gates to pass, ending with
`FIXTURE EVIDENCE OK`. Each label names its check; on failure,
report the label plus the lines above it.

Post-build validation:

```sh
docker inspect --format '{{.Repository}}:{{.Tag}} {{.Architecture}}' rev2square/ghidra:12.2
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/ghidra:12.2 \
  python3 /opt/runner/runner.py --dry-run --tool ghidra --profile headless --params-json '{"language":"ARM:LE:32:v8","base":134217728}' --workdir /tmp/dry
```

Expect `arm64` and an argv containing `ExportEvidence.java`. After
the remaining runners are rebuilt, refresh capability snapshots
and qualify the runner-backed mappings per
`docs/binary-analysis-setup.md` §2 steps 3–4.

Pending ARM64 checks: the ghidra native build itself, all install
+ runtime gates, `ExportEvidence.java` compilation inside
headless (first real execution), and the ARM32/Thumb
qualification run.

No production changes, commits, pushes, or deployments were made
from here.
