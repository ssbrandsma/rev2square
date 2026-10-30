# Research-artifact uploads: 64 MiB limit — delivery notes (2026-10-29)

## Outcome

- Default research-artifact per-file cap is now **64 MiB = 67,108,864 bytes**,
  enforced server-side and shown consistently as "64 MiB" in UI, messages,
  admin settings and docs.
- Owner firmware `baby_9.0.2_r17157.bin` (**16,032,273 bytes**, ~15.3 MiB)
  fits, was stored intact through the real service path, detected as a ZIP
  archive and extracted to a bounded listing. Stored SHA-256 matches the
  file on disk and the owner's `.sha` sidecar.
- Analysis stays bounded: extraction is asynchronous, local-only, with
  unchanged archive-expansion/decompression limits; no AI calls, no external
  transmission, no token-budget changes. Deep AI investigation was NOT run
  (worker isolation unverified in dev; no consent for external transmission).
- Full suite green: **711 passed (3626 assertions)**. Targeted suites:
  `ArtifactUploadLimitsTest` 16 passed, `Artifact|Agent` filter 105 passed.

## 1. Rejection-layer diagnosis

The owner's "Entity too large" could come from several stacked layers.
Every layer was inspected; the effective application ceiling was the
lowest Laravel-side control, and any one outer layer can still 413 first:

| Layer | Before | After | Notes |
|---|---|---|---|
| Browser guard (inline JS) | old limit text | 64 MiB / 80 MiB batch attrs | advisory only, never trusted |
| Laravel per-file rule + service | ~8 MiB default | **67,108,864** default | server-side, authoritative |
| `artifacts.max_upload_bytes` setting | 8388608 default | 67108864 default | env > DB > config |
| PHP `upload_max_filesize` | inherited default (2M) | **65M** | 1 MiB headroom so Laravel reports app-limit breaches precisely |
| PHP `post_max_size` | inherited default (8M) | **80M** | covers 64 MiB file + multipart overhead |
| nginx `client_max_body_size` | inherited default (1M) | **80m** | dev + prod configs; static 413 page |
| Virtualmin/Apache host proxy | unknown | **owner must verify** | see §8; never touched from here |
| Public presentation-media limits | separate workflow | **unchanged** | deliberately not raised |

Which layer rejects first for a given request depends on size and shape:
a >80 MiB body is rejected by nginx/PHP before Laravel ever runs (hence the
static 413 page + frontend guard); a file between the app limit and 64 MiB
with a lowered operator setting is rejected by Laravel with a per-file
message naming the effective limit.

## 2. Changed files

Application code:

- `config/artifacts.php` — default `max_upload_bytes` 67108864.
- `app/Services/Admin/AppSettings.php` — default/help text "64 MiB",
  bounds kept (min 1024, max 1 GiB); env precedence preserved.
- `app/Services/Artifacts/ArtifactUploadService.php` — per-file cap from
  effective setting; MiB-exact messages; streaming store (`fopen` +
  `put(stream)`), no full-file buffering for hashing/storage.
- `app/Services/Artifacts/ArtifactExtractor.php` — `streamSha256()` so
  integrity checks stream instead of loading whole files.
- `app/Http/Controllers/ArtifactController.php` — Content-Length pre-check
  against `post_max_size` (distinct "request too large" error, never
  mislabeled CSRF/missing-file); per-file loop with per-file outcomes
  (`isValid`, PHP upload error, size vs app limit); `phpBytes()` parser.
- `resources/views/projects/agent/index.blade.php` — limits panel
  (formats, per-file 64 MiB, batch ≤ 5 files / ≤ 80 MiB), inline
  pre-submit guard via `data-per-file-bytes`/`data-batch-bytes`.
- `resources/views/projects/artifacts/index.blade.php` — same limit
  summary on the listing page.

Infrastructure:

- `docker/nginx/default.conf`, `docker/nginx/default.prod.conf` —
  `client_max_body_size 80m` + `error_page 413 /413.html`.
- `public/413.html` (new) — static, plain-language 413 page (works when
  Laravel is unreachable).
- `docker/php/conf.d-uploads.ini` (new) — `upload_max_filesize=65M`,
  `post_max_size=80M`, `max_input_time=300`, `memory_limit=256M` (PHP
  `max_file_uploads` left at default; the 5-file batch cap is enforced by
  Laravel validation `'files' => 'required|array|min:1|max:5'`).
- `compose.dev.yaml` — mounts the ini into the dev PHP container.
- `docker/php/Dockerfile.prod` — COPYs the ini into the prod image.
- `docs/DEPLOYMENT_VIRTUALMIN_ARM64.md` — limit table + Virtualmin
  `LimitRequestBody` owner step.
- `phpunit.xml` — `memory_limit=512M` so the exact-64 MiB synthetic test
  can run under PHPUnit.

Tests (synthetic files only; firmware never committed or used as fixture):

- `tests/Feature/ArtifactUploadLimitsTest.php` (new, 14 tests) — below /
  exactly 67108864 / one byte above; misleading extension; malformed
  input; multipart overhead + bounded aggregate; multi-file behavior;
  lowered setting respected; env override respected; admin validation;
  oversized-body handling; no orphaned state on failure; bounded
  large-file hashing; small ELF/PCAP still accepted; form guard attrs;
  infra config declarations.

## 3. Per-file vs aggregate limits

- Per file: **67,108,864 bytes (64 MiB)**, configurable down by operator.
- Per request batch: **at most 5 files, at most 80 MiB total**
  (enforced by controller pre-check + nginx/PHP body caps + frontend
  guard; documented on the upload form).
- Rationale: a per-file-only cap would allow N×64 MiB in one request.
  The 5-file/80 MiB batch bound keeps the worst case near one large file
  plus overhead. Single-file uploads show a single clear result; batches
  show per-file results.

## 4. Existing-setting migration behavior

Precedence is unchanged: **environment variable > database setting >
config default**. The new 64 MiB value is the *default*; it does not
overwrite anything.

- Fresh installs / never-configured: effective limit becomes 64 MiB.
- DB holds an explicit operator value: respected as-is, even if lower.
- Ambiguous case: a DB row holding the *old default* `8388608` cannot be
  distinguished automatically from a deliberate 8 MiB choice, so it is
  treated as an intentional override and kept. Owner action: in
  Admin → Settings, either delete/reset that row to inherit 64 MiB or
  set an explicit value. This is a one-field change, documented in-app
  next to the setting.

## 5. Supplied firmware — identity and outcome

Windows path: `C:\Temp\fs\baby_9.0.2_r17157.bin`
(WSL path drifted between `/mnt/C/...` and `/mnt/c/...` across remounts;
both resolve to the same file.)

- Bytes: **16,032,273** (~15.29 MiB) — fits within 64 MiB.
- SHA-256: `dc783d525e0ea17736f36ff51d1424e6d8909cc5a9ba51f0cfdcacef56d65d6d`
- SHA-1: `9ab354c46ae98027d5a3164c2ae13166875d2c87`
  — matches the owner's sidecar `baby_9.0.2_r17157.bin.sha`.
- `file(1)`: `Zip archive data, at least v2.0 to extract, compression
  method=deflate`. No processor/format inferred from the name.

Practical test (dev stack, disposable Draft QA project
`firmware-qa-64mib-20261029`, id 17; nothing attached to a real project):

- `ArtifactUploadService::store()` accepted the file: artifact id 13,
  `format=archive`, `byte_size=16032273`.
- Queued extraction ran to `state=ready`, `failure=none`: bounded member
  listing (**5 entries**), `warnings=0`, `detection_warnings=0`.
- Stored SHA-256 **matches** the on-disk file byte-for-byte.
- `AiUsageEntry` count **0** before and after: no AI calls, no usage
  ledger writes, no external transmission from the upload/extract path.
- Member names/contents were not dumped; only counts were recorded.
- Deep AI investigation was deliberately NOT started: `php artisan
  agent:isolation-status` reports isolation unverified in dev, and no
  consent was given to send bytes/strings to Meta or any third party.
- File was never executed, emulated, flashed, scanned externally,
  committed to Git, or published as a fixture. Container-side temp copy
  removed after the test.

Honest boundary: upload + content-based initial inspection are proven.
Anything beyond extraction (disassembly, decompilation, AI-assisted
analysis) is pending verified worker isolation + explicit owner consent.

## 6. HTTP end-to-end verification (dev, real nginx→PHP→Laravel)

- Upload form now shows a live progress bar (native `<progress>` + "N% ·
  X MiB of Y MiB", button disabled while sending, distinct messages for
  HTTP 413 vs network interruption). Plain-JS XHR; degrades to a normal
  form POST without JS. Markup covered by
  `test_upload_form_shows_progress_indicator`.
- Wiring regression guard: `test_php_upload_ini_is_wired_into_dev_and_prod_images`
  asserts the ini is mounted in `compose.dev.yaml` (app + queue) and
  COPY'd in `Dockerfile.prod`.
- 2026-10-29 incident retest: after `up -d` recreated the containers, a
  real 16,777,336-byte ZIP posted through nginx → PHP-FPM → Laravel
  returned 302 → artifacts index and stored byte-identical
  (`archive`/`ready`, SHA matches). This is the exact 16 MB shape that
  previously died with the PHP 8M `Content-Length` warning.
- Registered a throwaway QA account and created Draft project
  `qa-http-upload-20261029` over HTTP (302 → project page).
- Agent page form carries live `data-per-file-bytes="67108864"` and
  `data-batch-bytes="83886080"`.
- Invalid 24-byte pseudo-ELF: HTTP 302 back with precise per-file
  message "not recognized as a supported executable, firmware, or
  capture" — no row created, no mislabeling.
- Valid 64-byte ELF header: HTTP 302 → artifacts index; row stored as
  `elf`/`x86-64`/`ready`, 64 bytes, correct SHA-256.
- Not performed: a real >80 MiB HTTP POST (would only re-prove nginx's
  `client_max_body_size`, and wastes bandwidth); covered by config
  assertion tests instead. Browser-rendered (JS guard UX) checks were
  not run — no real-browser pass in this environment.

## 7. Resource-safety bounds (unchanged by this task)

- Upload + SHA-256 stream; no whole-file in-memory copies on the hot path.
- Extraction stays on the queue (async), with the pre-existing CPU,
  memory, disk, output and wall-time limits untouched.
- Archive expansion/decompression ceilings are independent of the
  compressed upload size — a 64 MiB ZIP cannot expand unboundedly.
- AI token budgets and spending limits unchanged; nothing in this task
  triggers AI work.

## 8. Owner-operated setup and retest

Development (this repo). NOTE: a plain `restart` is NOT enough after
pulling these changes — the PHP ini arrives as a *new volume mount*,
which only applies when containers are recreated. On 2026-10-29 the
running dev stack still had PHP's 8M default for exactly this reason,
producing `POST Content-Length ... exceeds the limit of 8388608 bytes`
on a 16 MB upload. Fix: `up -d` (recreates), then restart nginx so it
re-resolves the fresh `app` container IP (stale IP otherwise gives 502s):

```sh
docker compose -f compose.dev.yaml up -d
docker compose -f compose.dev.yaml restart nginx
docker compose -f compose.dev.yaml exec -T app php -r 'echo ini_get("upload_max_filesize")," ",ini_get("post_max_size"),"\n";'
# expect: 65M 80M
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" http://localhost:8080/413.html
# expect: 200 text/html
docker compose -f compose.dev.yaml exec -T app php artisan test --filter=ArtifactUploadLimitsTest
```

Localhost retest steps:

1. Sign in (any dev user), open a Draft project → Agent tab.
2. Confirm the panel reads "64 MiB per file" and "at most 5 files / 80 MiB
   per upload".
3. Upload a small ELF/PCAP → per-file success; artifact row appears.
4. Upload an unsupported file → precise per-file rejection, no row.
5. (Optional, honest 413) POST a >80 MiB body → static 413 page, no
   Laravel error, no session/CSRF mislabel.

Production (`compose.virtualmin.yaml`, `/etc/rev2square/.env.virtualmin`;
owner-operated only, nothing done from here):

```sh
cd /srv/rev2square
./update_prod.sh   # normal run picks up the nginx + php image changes
docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin \
  exec -T app php -r 'echo ini_get("upload_max_filesize")," ",ini_get("post_max_size"),"\n";'
curl -s -o /dev/null -w "%{http_code}\n" https://rev2square.com/413.html
```

Virtualmin host step (requires owner approval, NOT applied here): the
Apache/host reverse proxy in front of Docker must allow ≥80 MiB bodies
(`LimitRequestBody 83886080` or the Virtualmin UI equivalent); otherwise
it 413s before traffic reaches nginx. SELinux/proxy behavior unverified.

QA cleanup (optional; dev only): delete Draft projects
`firmware-qa-64mib-20261029` and `qa-http-upload-20261029`, artifact
rows 13+ and the `qa-upload-20261029@example.com` account, plus
`storage/app/artifacts/*` blobs for those rows.

## 9. Not verified / explicitly out of scope

- No real-browser pass (desktop/mobile rendering of the limits panel,
  guard behavior, 413 page styling).
- Production rebuild/deploy and Virtualmin host proxy settings —
  owner-operated, pending.
- Deep firmware analysis — blocked on verified worker isolation +
  explicit transmission consent (by design, not an omission).
- PHP-FPM `request_terminate_timeout` and queue-worker memory ceilings
  were reviewed, not load-tested at 64 MiB.
