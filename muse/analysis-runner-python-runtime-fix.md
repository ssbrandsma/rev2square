# Analysis-runner Python runtime fix (python3-minimal → python3)

## 1. Confirmed root cause

On the ARM64 production server, `Dockerfile.binutils` installed
`python3-minimal`. Its build-time runner self-check failed at
`docker/analysis/runner.py:33` (`import json`) with
`ModuleNotFoundError: No module named 'json'`, aborting the
multi-service build. A subsequent `compose up` with missing local
`rev2square/*` images then attempted a registry pull and reported
`pull access denied` — a misleading secondary error hiding the real
build failure.

Why `json` is missing: on Debian bookworm, `python3-minimal`
contains only the interpreter plus the `py3compile`/`py3clean`
helpers — no standard-library modules (verified against the Debian
package filelists for `python3-minimal/bookworm/arm64`). The full
`python3` package pulls in `libpython3-stdlib`, which ships
`json/`, `shutil`, and the rest of what the runner imports. The fix
is therefore the full `python3` package — never `pip install json`
(it is stdlib, not a PyPI package).

`runner.py` import inventory (all stdlib, audited 2026-10-30):
top-level `argparse, fcntl, hashlib, json, os, platform, shutil,
signal, subprocess, sys, time`, plus inline `re` (line 320) and
`select` (line 515). The server traceback failing exactly at line
33 is consistent: lines 30–32 import fine (frozen into the minimal
interpreter), `json` is the first real stdlib module.

The same `python3-minimal` gap existed in four of the five final
runner images:

| Dockerfile | Pre-fix final stage | Fixed |
|---|---|---|
| `Dockerfile.binutils` | `python3-minimal` | full `python3` |
| `Dockerfile.ghidra` (runtime stage; builder too) | `python3-minimal` | full `python3` |
| `Dockerfile.jadx` | `python3-minimal` | full `python3` |
| `Dockerfile.ilspy` | `python3-minimal` | full `python3` |
| `Dockerfile.extract` | already full `python3` | smoke restructure only |

## 2. Changed files and rationale

**Runner Dockerfiles** (`docker/analysis/Dockerfile.{binutils,ghidra,jadx,ilspy,extract}`):
- Final stages install full `python3` instead of `python3-minimal`
  (Ghidra builder stage too, for one consistent interpreter).
- Each final stage now runs a runtime self-check **as `USER
  www-data`** (previously the dry-run ran as root): a `python3 -c`
  smoke importing all 13 runner modules, a tool-executability
  probe, and the pre-existing `--dry-run` argv assertion with
  `HOME=/tmp` (matching the tmpfs production runtime).
- `Dockerfile.extract` additionally fixes shell logic where `||
  true` version-capture fallbacks masked earlier assertion
  failures (`A && B || true` always continues); 7zz execution is
  now a hard gate, uncertain `--version`/`--help` flags stay
  best-effort with explicit braces.

**Compose** (`compose.virtualmin.yaml`, `compose.dev.yaml`):
- `pull_policy: never` on all five runner services in both files,
  plus prod `app` and `nginx` (also locally built `rev2square/*`
  tags covered by the `build && up` upgrade flow). A missing local
  image now fails fast with a local error instead of attempting a
  Docker Hub pull. Registry images (`db`, dev `nginx`/`node`) keep
  default pull behavior so base-image retrieval is unaffected.

**Provisioning docs** (`docs/binary-analysis-setup.md`,
`docs/DEPLOYMENT_VIRTUALMIN_ARM64.md`):
- Runner provisioning §2 step 1 is now one `build … && up -d …`
  chain so a failed build can never fall through into `up`; steps
  renumbered 1–4 with the `steps 3–4` cross-references updated.
- Upgrade and rollback flows chain `build && up -d` likewise.

**Regression tests** (`tests/Feature/AnalysisDeploymentTest.php`):
- `test_runner_final_images_ship_full_python3_with_stdlib_smoke`:
  derives the expected import set from `runner.py` itself, then
  per Dockerfile asserts no `python3-minimal` in the final stage,
  full-`python3` install, stdlib smoke covering every import,
  smoke positioned after `USER www-data`, and the `--dry-run`
  self-check retained.
- `test_locally_built_images_are_never_pulled` (dev+prod): every
  `rev2square/*` service has `pull_policy: never`; `db` keeps
  default pull behavior.
- `test_setup_docs_gate_startup_on_successful_build`: pins the
  `build … &&` chains in both provisioning docs.

## 3. Tests performed and results

| Check | Where | Result |
|---|---|---|
| `AnalysisDeploymentTest` (16 tests, 425 assertions, incl. 4 new cases) | dev app container | PASS |
| New tests against pre-fix sources (stash round-trip) | dev app container | 4 FAIL as expected, then PASS after restore |
| `compose.dev.yaml` / `compose.virtualmin.yaml` `config --quiet` | dev host | both OK |
| `binutils` image build (x86_64) | dev host | **BUILT** — incl. the www-data stdlib smoke stage |
| `binutils` runtime probe: `--user www-data --read-only --network none`, imports + dry-run argv | x86_64 image | `www-data`, `IMPORTS_OK`, exact `["/usr/bin/objdump", "-d", "-M", "intel", "-w", …]` argv |
| `sh -n` syntax check of every RUN body, all 5 Dockerfiles | dev host | all OK |

Not re-run (no app code changed; focused gate is green):
full PHP suite, `docker/analysis` Python suite (`runner.py`
untouched).

## 4. Build matrix by architecture (honest labeling)

| Image | x86_64 dev | ARM64 prod |
|---|---|---|
| binutils 2.40-2 | built + runtime-verified here | owner rebuild pending (same Dockerfile; multiarch apt) |
| ghidra 12.2 | not buildable (ARM64 JDK + natives by design) | owner rebuild + `ExportEvidence` qualification pending |
| jadx 1.5.6 | not buildable (ARM64 JRE by design) | owner rebuild pending |
| ilspy 11.2.0.10050 | not buildable (`linux-arm64` RID assertion by design) | owner rebuild pending |
| extract 1.0 | not buildable (ARM64 7zz by design) | owner rebuild + UBI functional qualification pending |

No native-ARM64 claim is made from x86 results. The four
ARM64-only images are covered here by static regression tests and
shell-syntax checks only; their real verification is the owner
rebuild below.

## 5. Exact owner-operated rebuild + validation (production ARM64)

Run on the server in `/srv/rev2square`. `update_prod.sh` does
**not** rebuild tool images (by design — coordinator shares the app
image; runners rebuild only when built explicitly), so after
pulling this fix run the runner provisioning once:

```sh
cd /srv/rev2square
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  build objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner && \
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  up -d analysis-coordinator objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner
docker images --digests 'rev2square/*'
```

If any build fails: fix it and re-run the chain — do not start
services with missing local images. With `pull_policy: never`, a
missing image now fails fast locally instead of the misleading
Hub `pull access denied`.

Validate each rebuilt image as the non-root runtime user on a
read-only filesystem (imports for all five, then the dry-run argv
path per tool):

```sh
for t in binutils:2.40-2 ghidra:12.2 jadx:1.5.6 ilspy:11.2.0.10050 extract:1.0; do
  docker run --rm --read-only --tmpfs /tmp --user www-data --network none \
    rev2square/$t python3 -c "import argparse, fcntl, hashlib, json, os, platform, re, select, shutil, signal, subprocess, sys, time" \
    && echo "$t IMPORTS_OK"
done
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/binutils:2.40-2 \
  python3 /opt/runner/runner.py --dry-run --tool objdump --profile auto --params-json '{}' --workdir /tmp/dry
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/ghidra:12.2 \
  python3 /opt/runner/runner.py --dry-run --tool ghidra --profile headless --params-json '{"language":"ARM:LE:32:v8","base":134217728}' --workdir /tmp/dry
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/jadx:1.5.6 \
  python3 /opt/runner/runner.py --dry-run --tool jadx --profile apk --params-json '{"threads":2}' --workdir /tmp/dry
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/ilspy:11.2.0.10050 \
  python3 /opt/runner/runner.py --dry-run --tool ilspy --profile assembly --params-json '{}' --workdir /tmp/dry
docker run --rm --read-only --tmpfs /tmp --user www-data --network none rev2square/extract:1.0 \
  python3 /opt/runner/runner.py --dry-run --tool extract --profile 7zz-list --params-json '{}' --workdir /tmp/dry
docker images --format '{{.Repository}}:{{.Tag}} {{.Architecture}}' 'rev2square/*'
```

Then refresh worker capability snapshots from the runners and
re-qualify (§2 steps 3–4 of `docs/binary-analysis-setup.md`):

```sh
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan analysis:diagnostics
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec analysis-coordinator php artisan binary:worker-capabilities --refresh
```

## 6. Unverified / remaining owner steps

- Native ARM64 builds of all five images (binutils verified on
  x86 only; same Dockerfile, multiarch apt — expected to pass).
- Ghidra `buildNatives`, `ExportEvidence.java` compatibility with
  the pinned Ghidra API, and the ARM32/Thumb qualification run.
- 7zz glibc compatibility gate and functional UBI extraction
  qualification on the extract runner.
- `ilspycmd` native-dependency behavior on the prod host.
- No production changes were made from here; no commits, pushes,
  or deployments performed.
