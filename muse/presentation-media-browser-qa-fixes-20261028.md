# Presentation/media browser QA fixes — 2026-10-28

Follow-up to the independent browser QA of the project presentation/media
system (QA project `/projects/qa-retest-draft-20261028`, kept Draft).
All prior uncommitted work (branding, ELF/PCAP, admin, presentation/media)
is preserved. No production, secrets, access, Docker, install, deploy,
commit, push, or live-AI actions were taken.

## 1. P1 root cause — exact, evidenced

**Verdict: operational (stale queue-worker image), with contributing
application defects that made the failure permanent and unactionable.**

Evidence, read-only (`failed_jobs` + container inspection):

- `failed_jobs` rows for media 1 (`device.png`) and media 3 (`device.jpg`)
  both carry:
  `Error: Call to undefined function App\Services\Media\imagecreatefromstring()`
  at `app/Services/Media/ImageProcessor.php:39`, via `ProcessMediaJob`.
- `php -m` / `function_exists('imagecreatefromstring')`:
  **app container: GD present; queue container (`rev2square-queue-1`): GD absent.**
- Timeline explains it: GD entered `docker/php/Dockerfile` in commit
  `d3d37f1` (2026-10-28); the running queue image was built 2026-10-26
  (pre-GD), while the app image was rebuilt 2026-10-27 from a tree that
  already contained GD.
- Consistent with the QA observation that upload-time validation worked
  (MIME allowlist, SVG rejection, 1200×600 dimensions stored — all run
  in the web container with GD/core functions) while async processing
  failed on the worker.

Application defects fixed alongside (all demonstrated by the QA):

1. `ProcessMediaJob` caught **every** throwable, marked the row
   permanently `failed`, and called `$this->fail()` inside `handle()` —
   producing confusing FAIL-then-DONE worker log lines and a
   `failed_jobs` row for every outage, with no retry path and no safe
   reason stored.
2. Re-uploading a failed image reported `0 new, 1 reused. Processing
   runs in the background` although **no job was dispatched** (P2).
3. The story image picker labelled failed items `Processing…` (P3).

## 2. Owner-operated step (approval-ready, NOT executed)

Rebuilding/restarting containers is Docker administration, so this is
left for the owner. Exact step:

```sh
docker compose -f compose.dev.yaml up -d --build queue
docker compose -f compose.dev.yaml exec -T queue php -r "var_dump(function_exists('imagecreatefromstring'));"
# expect: bool(true)
```

Effect: the queue worker image is rebuilt from the current Dockerfile
(with GD) and the worker restarts. No code, data, or secret changes.
The two existing `failed_jobs` rows are operator history and were left
in place. After the rebuild, open the edit page below and click
**Retry processing** on each failed image — no re-upload needed.

## 3. Implemented application changes

- **Failure classification.** `ImageProcessor` now throws
  `UnprocessableImageException` (permanent: unreadable/decode/pixel
  budget, with `invalid_image` / `missing_original` codes) and
  `ProcessingUnavailableException` (environmental), with an explicit
  GD preflight so a GD-less worker never condemns valid bytes.
- **Job behavior.** `ProcessMediaJob::handle()` records permanent
  failures with their code and drops the job quietly (expected
  validation failures no longer pollute `failed_jobs`); any other
  throwable propagates for normal worker retry/backoff, and the new
  `failed()` hook records `processing_unavailable` (retryable) only
  after attempts are exhausted — and never overwrites a terminal
  classification.
- **Retry endpoint.** `POST /projects/{project}/media/{media}/retry`
  (`projects.media.retry`): update-gated, same-project-scoped,
  image-only. Atomic failed→processing claim makes concurrent clicks
  schedule exactly one job. Ready/processing rows get truthful no-op
  messages; permanent failures are refused with a re-upload hint.
- **Truthful dedupe messaging.** Re-uploads now report the reused row's
  actual state (`still processing` / `failed — use Retry …`) and the
  "Processing runs in the background" line appears only when a job was
  actually dispatched.
- **Status labels.** Picker, library, and cover preview distinguish
  Ready / Processing… / Failed; failed items show a safe explanation
  (no paths, traces, or exception internals) and a Retry button for
  retryable rows. Legacy failed rows with no code (the two QA rows)
  are treated as retryable — one retry re-classifies them.
- **Cover guard.** `setCover` now refuses non-ready images with a
  message pointing at retry (display surfaces already degraded to the
  placeholder via `isReady()` guards).

Changed files: `app/Jobs/ProcessMediaJob.php`,
`app/Models/ProjectMedia.php` (error codes, `isRetryable()`,
`processingErrorMessage()`), `app/Services/Media/ImageProcessor.php`,
`app/Services/Media/UnprocessableImageException.php` (new),
`app/Services/Media/ProcessingUnavailableException.php` (new),
`app/Http/Controllers/MediaController.php` (`retry`, messaging, cover
guard), `routes/web.php`, `resources/views/projects/edit.blade.php`,
migration `2026_10_28_000034_add_processing_error_to_project_media.php`
(nullable code column; QA rows untouched), and
`tests/Feature/MediaProcessingRecoveryTest.php` (new, 8 tests).

## 4. Actual tests and results

- New `MediaProcessingRecoveryTest` (8 tests): `failed()` mapping of
  the exact observed worker `Error`, permanent-corrupt-bytes path with
  empty `failed_jobs`/`jobs`, idempotent retry endpoint, permanent
  refusal + cross-project 404, truthful failed/ready re-upload
  messaging, **real async path** (database queue dispatch +
  `queue:work --once` producing all three derivatives), and
  picker/library failed-vs-processing rendering with leak assertions.
  Written failing first; all pass.
- Full suite: **OK 619 tests / 3067 assertions** (was 611 before;
  +8 new). Note: two earlier full runs each showed a *different*
  single-test flake (`TeamTest`, then `JoinProjectTest` — team/join
  profile assertions untouched by this change); both pass in
  isolation, in combination with the new tests, and in the final full
  run. Pre-existing flake, not caused by this fix.
- `pint --test`: PASS, 368 files. `vite build`: ✓ 625ms.
- Dev DB migrated (`..._000034`); QA project still `DRAFT`; media
  rows 1–3 (failed PNG, ready TXT, failed JPEG) preserved with
  `processing_error = NULL` → both failed rows are retryable in the
  browser after the worker rebuild.

## 5. Exact browser retest URLs (localhost:8080)

1. `http://localhost:8080/projects/qa-retest-draft-20261028/edit`
   — media library: failed rows now show the safe reason + **Retry
   processing**; click it per image after the owner rebuild (§2),
   refresh, expect Ready. Picker (`+ Image` in Story) labels Failed
   items correctly. Cover section offers the cover selector once an
   image is Ready.
2. `http://localhost:8080/projects/qa-retest-draft-20261028`
   — story, gallery/lightbox, attachments after recovery.
3. Explore / homepage / profile project cards — cover consistency
   once a cover is set (specific routes unchanged from the delivery
   report).
4. Negative checks: re-upload the same PNG while failed (truthful
   retry message, no false "background" claim); retry a ready image
   (truthful no-op); upload a corrupt `.png` (permanent failure
   message, retry refused).

## 6. Verification status (honest split)

- **Implemented + automated-tested:** classification, retry endpoint +
  idempotency, dedupe messaging, picker/library labels, cover guard,
  async worker-path integration.
- **Observed in this session (read-only diagnostics):** exact P1
  exception, GD present in app / absent in queue worker, image build
  dates, FAIL-then-DONE log pattern explained by old
  catch-and-`fail()` code, QA rows preserved, project still Draft.
- **NOT verified:** real-browser recovery after the worker rebuild
  (requires the owner step in §2); EXIF strip, orientation,
  derivatives, lightbox, mobile layout, publish/unpublish media
  transitions, and download headers remain as reported in the QA
  (not validated). No live AI calls were made or needed.

## 7. Remaining limitations and risks

- Until §2 is executed, every image upload still fails on the stale
  worker — now with actionable messaging and retry instead of a dead
  end. New failures during the outage window land on the retryable
  path automatically.
- `failed_jobs` rows retain exception text with server paths; they
  are operator-visible only (never rendered to users) — unchanged
  from framework behavior.
- Permanent-failure detection still depends on GD decode failing
  loudly; pathological files that decode to garbage pixels are
  accepted as images (pre-existing scope, unchanged).
- OS-level worker isolation for untrusted uploads remains unverified
  (standing prerequisite, unchanged).
