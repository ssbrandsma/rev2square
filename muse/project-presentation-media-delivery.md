# Project presentation & media system — delivery

Polished project storytelling for Rev2Square: optional cover photo with
focal point, a block-based rich story editor with inline project images,
a photo gallery with lightbox, and a supporting-files area — one coherent
presentation across Explore, homepage, project pages, profiles, editing,
and exports. No crowdfunding/funding features, no Kickstarter branding.

Nothing committed, pushed, merged, deployed, or changed in production.
Earlier uncommitted sets (branding, QA fixes, ELF/PCAP, admin) preserved.
No installs performed; no system packages, Docker, or root used.

## 1. Implemented editor and media behavior

**Cover.** Optional per project: upload/replace, click-or-arrow-key
focal point (stored 0–100 x/y, rendered as `object-position` so every
card size crops identically), alt/caption/credit/license metadata, and
a deliberate remove action that unlinks without deleting the library
row. Shown on Explore cards, homepage featured cards, the project
header, and public-profile summaries via shared partials
(`_card`, `_cover`, `_thumb`); a restrained dark placeholder keeps
cards aligned when no cover exists, and failed images remove
themselves without collapsing layout. Dense dashboard tables stay
text-only by design.

**Story editor.** Custom block editor (vanilla JS, no framework —
see §2): paragraph, heading (2 levels), ordered/unordered list,
quote, code, and image blocks; inline `**bold**`, `*italic*`,
`` `code` ``, `[text](https://…)`; per-block move up/down/delete;
image picker restricted to the project's own ready images with
normal/wide layouts; undo/redo (history + Ctrl+Z/Y); dirty/saving/
saved states; unsaved-changes warning; client-side preview mirroring
server rules; draft JSON round-trips through `old()` on validation
or concurrency failures. No autosave is claimed. Nothing is sent to
AI providers.

**Gallery & library.** One `project_media` library serves cover,
story, and gallery; metadata editing, usage badges (Cover/In
story/Gallery), DOM-order reorder form (works without JS), and a
gallery with an accessible lightbox (Esc/arrows/focus restore,
mobile buttons). Deleting a story-referenced image is refused with
an explanation; cover-only deletes unlink cleanly. Same-project
SHA-256 dedupe avoids duplicate storage; cross-project dedupe is
never consulted, so reuse cannot probe others' files. No existing
user media was touched or deleted.

**Files.** PDF/TXT/LOG/Markdown/CSV/JSON with content+extension
validation, categories (Documentation/Schematics/Other), download
as `attachment` with `nosniff` — never rendered as active content.
ELF/PCAP research uploads are untouched: they stay in the private
artifact flow with its consent rules, and attaching a file here
never parses it as evidence or contacts any AI provider.

**Flow.** Create stays basics-only (no temp-upload orphans possible);
the edit page gains anchored Cover/Story/Library/Gallery/Files
sections plus the preserved publication checklist, preview link, and
delete action. Story saves are optimistically concurrent
(`presentation_version`): stale writers keep their draft and see an
explicit conflict message.

## 2. Dependency choices and versions

No new dependencies installed. Decided after inspecting the stack:

- **Editor: custom block editor, zero deps.** TipTap/ProseMirror
  would need npm installs (network + approval) and a sanitizer
  bridge for their HTML/JSON; the requirements (6 block types,
  constrained inline syntax, image placement, reorder, undo/redo)
  are fully met by ~600 lines of vanilla JS against a strict
  server-validated JSON schema. No approval needed, no supply
  chain added.
- **Image processing: ext-gd (already in the PHP container) +
  ext-exif + fileinfo.** Verified present; no Intervention/Glide
  needed for resize/re-encode/orientation. If a future deployment
  lacks GD, uploads fail closed with “unsupported” messages (no
  silent validation weakening); owner setup is just the standard
  PHP GD extension.
- **Lightbox/gallery JS: vanilla** in `resources/js/pages/
  story-editor.js`, wired into the existing Vite entry. Frontend
  build verified (`npm run build` in the node container, ✓ in
  797ms); `public/build` is git-ignored build output.

## 3. Storage and image-processing design

`project_media` rows (project, kind, category, random 40-char
storage key, original name as display metadata only, MIME, size,
dimensions, SHA-256 + original-SHA-256, title/caption/alt/credit/
license, focal point, gallery order/flag, processing status).
Originals on the `local` disk (`media/<key>.bin`); JPEG derivatives
`thumb 400 / card 800 / wide 1200` (`.<variant>.jpg`).
`ProcessMediaJob` (queued, 120s timeout, 2 tries): pixel-budget
recheck, EXIF-orientation application, full re-encode (strips
EXIF/GPS/location — verified: only a GD COMMENT segment remains),
derivative generation; failures mark the row `failed` with a UI
state instead of blocking the web process. Limits: 12 MB images /
25 MB files, 8000px sides, 40M pixels (header-checked before GD
allocates — decompression bombs rejected from a 55-byte probe),
200 items/project. Reuse never duplicates bytes within a project.

## 4. Role/visibility rules

Management (upload/edit/reorder/cover/story/delete) requires
project `update` (owner/maintainer); global admin/moderator status
grants nothing here — the explicit permission model is respected,
not bypassed. Delivery (`media.show`, `media.download`) requires
project `view`: published ⇒ public (long-lived public cache),
draft ⇒ managers only (`private, no-store, max-age=0`), so
unpublishing revokes ordinary access on the next request (no CDN
involved; no permanent public URLs exist). Story-embedded IDs are
validated against the owning project on save (foreign IDs rejected);
every media route 404s on project mismatch; anonymous draft access
is 403. Publishing exposes presentation media/files metadata as
documented on the edit page; research artifacts, Agent
conversations, and private messages are never included.

## 5. Story compatibility/migration strategy

Migration `2026_10_28_000033_create_project_media` (applied to dev
DB 28 Oct) adds `project_media` + `projects.story_document` (JSON,
nullable), `story_format` (`plain` default), `cover_media_id`,
`presentation_version`. **No data rewrite**: existing plain-text
stories stay byte-identical and render through the unchanged
paragraph path. Authoritative rule: `story_format=rich` + document
⇒ rich renderer; otherwise the legacy body. On rich save, `body`
is refreshed as a derived plain-text fallback (search/compat) and
the basics form ignores `body` thereafter — stale legacy fields
can never overwrite rich content. Rollback limitation: `down()`
drops `project_media` rows (files would orphan on disk) and the
new project columns (rich documents lost; legacy `body` keeps the
last derived text, original plain bodies were never altered).

## 6. Changed files and migrations

Migration above. New: `app/Models/ProjectMedia.php`,
`app/Services/Media/{MediaUploadService,ImageProcessor,
StoryDocument,StoryRenderer}.php`, `app/Jobs/ProcessMediaJob.php`,
`app/Http/Controllers/MediaController.php`,
`resources/views/projects/{_cover,_thumb,_story,_gallery,
_media-files}.blade.php`, `resources/js/pages/story-editor.js`,
`tests/Feature/ProjectPresentationTest.php`,
`tests/Unit/Media/StoryRenderingTest.php`, this report. Modified:
`Project` (casts, `media/gallery/cover` relations,
`hasRichStory`), `ProjectController` (`updateStory`, edit data,
rich-body guard, eager loads), `ExportController` + `md` view
(story markdown, media metadata), `Explorer` + home route (cover
eager load), `_card` (cover band), `show` (header cover, story/
gallery/files sections), `edit` (all sections; checklist + delete
preserved), `_form` (`hideBody` prop), `users/show` (thumbs),
`app.css` (story typography), `app.js` (module import).

## 7. Required owner-operated setup

- Run `php artisan migrate` on deploy (000033).
- Queue worker must run `ProcessMediaJob` (already does in dev;
  sync driver in tests processes inline).
- `npm run build` for production assets (dev uses Vite HMR).
- PHP extensions `gd`, `exif`, `fileinfo` (present in dev).
- No other setup; no restarts, no secrets, no storage provisioning
  beyond the existing `local` disk.

## 8. Actual test results and blockers

Isolated, non-root (`exec -T -u www-data`), ephemeral `tester_run`
grant on `testing` (dropped after), GD-generated synthetic fixtures
only (known provenance; no personal/copyrighted material):

- 22 new tests (18 feature + 4 unit) mapping to acceptance 1–19:
  plain-story preservation; cover on homepage/header/cards;
  focal-point consistency; inline-image ordering; block survival;
  draft retention on failure; gallery reorder; safe downloads
  (attachment + nosniff); same-project dedupe vs cross-project
  isolation; story-delete refusal vs cover unlink; stranger 403s;
  cross-project ID rejection; draft-private/published-public/
  unpublished-revoked; markup/filename inertness; malformed/bomb/
  SVG rejection; EXIF strip + orientation fix; concurrency
  conflict; md/json exports (versioned document, captioned
  markdown, metadata-only media, no storage keys/secrets).
- Full suite: **OK, 611 tests / 3031 assertions** (589 before),
  zero regressions (acceptance 20).
- `pint --test`: **PASS, 364 files**. `vite build`: ✓.
- Blockers: none. No real-browser run — automated + build only.

## 9. Exact localhost walkthroughs and synthetic fixtures

Fixtures: generated in-test by GD (no fixture files needed). Manual
walkthrough at `http://localhost:8080` (sign in, open any owned
project → Edit):

1. Cover: upload a JPEG/PNG/WebP → set as cover → click the
   preview to move the focal point → save → check Explore card,
   homepage, header, and profile thumbnail.
2. Story: add heading/list/quote/code/image blocks, `**bold**`,
   `[text](https://…)` → Preview → save → reload edit (content
   intact) → view project page (typography, captioned image).
3. Validation: open edit in two tabs, save tab A, save tab B →
   conflict message with draft kept.
4. Gallery: tick images into the gallery → reorder with ↑/↓ →
   save → project page grid → open lightbox (arrows, Esc).
5. Files: attach a PDF + a TXT → download (attachment headers) →
   delete one.
6. Safety: try an SVG, a renamed text file, and a story link
   `[x](javascript:…)` → clean rejections / inert literals.
7. Visibility: unpublish → anonymous media URL 403s; publish →
   200; check md/json exports for captioned story + media list.
8. Regression: a pre-existing plain-story project renders
   unchanged; evidence/Agent/tasks/discussions unaffected.

## 10. Screenshots

None — no browser was available in this session.

## 11. Remaining limitations and risks

- Editor is block-based by design (no freeform HTML/CSS, no
  absolute positioning, no remote images, no autosave); nested
  inline markup stays literal.
- Originals are re-encoded to JPEG (transparency becomes white;
  documented on save path behavior).
- Gallery/lightbox and editor JS are unit-uncovered (backend +
  build verified; browser validation pending per instructions).
- Export image URLs require view rights; JSON carries metadata,
  not bytes; no ZIP export (out of scope).
- Rollback drops media rows (see §5); `down()` is for
  emergencies, not routine use.
