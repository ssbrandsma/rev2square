# Assignments 1–4 — execution report

Working through `./assignments` files 1–4 strictly in order. Isolated fixtures only (`a1-*`, `e2e-*`); community data untouched; no production deploy.

---

## Assignment 1 — Community & roadmap (`1. meta-muse-code-community-and-roadmap-en.md`)

Status: **complete** (all B-fixes + F01–F08 slices implemented, tested, browser-verified).

### B-fixes (concrete issues)

- **B01–B04 (offers/claims/lifecycle/applications):** claim validation (eligible task required), general help discoverable + task-reference without claiming, duplicate-pending guard, atomic claim acceptance (locked assign), lifecycle denials centralized in `ProjectActionPolicy`, dashboard *My applications* with next steps, `OfferStatus::Withdrawn`.
- **B05 (filters lost on refresh):** Explorer already synced `q/phase/activity` to the URL via Livewire `#[Url]`; added validated coercion of all params (invalid → defaults), result count, clear-all, and a validated sort (newest/oldest/title).
- **B06 (homepage features):** new `Project::featurable()` policy — published, not archived, linked device, newest first; honest caption; early-stage/deviceless projects stay in Explore.
- **U01 (counters/identity):** contributor-facing *Offers — N pending* label; header shows signed-in display name (full account menu deferred to A3) and inbox link with unread badge.

### F-slices (human workflow without AI/GitHub)

- **F02 tasks/milestones:** maintainer CRUD + explicit milestone ordering; `ContentOrigin` provenance (AI drafted / maintainer-added); `TaskWorkflow` state machine (Open → Claimed → In progress → Blocked/Ready → Verified, request-changes with mandatory note, reopen, retire/restore) with role checks and audit; task detail page with assignee management, skills, DoD, history; duplicate-title guard; manual rows survive AI re-publish (import_key merge).
- **F01 observations:** any signed-in viewer can share task/variant-linked observations with documentation reference links; corrections workflow (propose → accept-apply/dismiss); maintainer *Verified hardware claim* badge cleared on edit; evidence page lists human sources with provenance/review status; log/document attachments (5 MB, allowlisted types, credential warning, maintainer review).
- **F03 test reports:** outcome (Worked/Partially/Failed) + environment + variant; second-user confirmation with optional note (not self); per-variant compatibility matrix with explicit no-universal-claim note; edit clears confirmation.
- **F04 conversation/inbox:** private per-application thread (applicant ↔ maintainers, clarification flow), optional decision reason, withdraw, deliberate reopen/reconsider with history; in-app inbox (no email) with 8 database notification kinds, deep links, off-site redirect guard, single-delivery.
- **F05 teams:** team page (owner/maintainers/contributors), *Apply to maintain* flow tied to Seeking-maintainer activity, owner-only invitations (14-day expiry, explicit accept before access changes, wrong-account/expiry/reuse guards), revocation; owner row is immutable so projects can't strand; ordinary offer acceptance grants nothing.
- **F06 directory:** `/tasks` cross-project search (text/skill/hardware/status/project/sort, shareable URLs, permission-aware); Home *Find a task* links there. Contributor profiles deferred to A3 (which refines them).
- **F07 export:** Markdown + JSON download (story, devices/variants, repos, roadmap, tasks, observations, reports, public history); offers/messages/invitations always excluded; draft privacy enforced.
- **F08 history/checklist:** public-safe project history (19 allowlisted event kinds); non-blocking publication checklist + preview on the edit page. Content reporting/moderation deferred (needs hiding semantics + abuse policy; spec scopes it "if opening to a broad audience").

### Deferred with reasons

- Doc-URL *fetching* (stored as unfetched reference links; SSRF-safe fetcher + AI selection coupling is new security surface), photo redaction tooling (warning + review instead), task-level comments (project discussion lands in A2), email notifications (spec: optional, inbox only), maintainer *review decision* distinct from second-user confirmation (confirm-with-note covers the slice), contributor profiles (A3), report/moderation queue (F08).
- Single assignee per task (explicit responsibility policy).

### Validation

- Full suite: **346 passed / 1645 assertions** (`php artisan test`), Pint clean.
- New/updated suites: `CommunityWorkflowTest`, `ContributionOfferTest`, `TasksWorkflowTest` (13), `FieldEvidenceTest` (12), `ConversationInboxTest` (9), `TeamTest` (9), `ExplorerTest` (+5), `DiscoveryExportTest` (8).
- Browser (Playwright, two authenticated sessions + mobile): `muse/results/a1-01-project.png` … `a1-09-mobile-project.png` via `muse/results/shots-a1.cjs`; fixtures `a1-owner/contrib@rev2square.test`, project `a1-community-walkman-20261027` (dev DB only).
- Regression found & fixed by screenshots: missing closing quote in header inbox `aria-label` swallowed the badge (byte-level root cause; covered by a markup assertion now).

### Migrations (A1)

`000020` assignment/review columns · `000021` origin + retired · `000022` observations/corrections/test_reports · `000023` notifications/offer_messages · `000024` source_url/environment/attachments · `000025` team_invitations.

---

## Assignment 2 — Community layer (`2. meta-muse-code-comments-chat-presence-en.md`)

Status: **complete** (C01–C04 implemented, tested, two-session browser-verified).

### Realtime infrastructure

- Self-hosted Laravel Reverb (`reverb` service in `compose.dev.yaml`), nginx `/app/` WebSocket proxy so host and container browsers share one config; `BROADCAST_CONNECTION=reverb` in dev `.env` only (`.env.example` documents log defaults).
- `routes/channels.php` private + presence auth, `MemberInfo` identity-only payloads, `resources/js/echo.js` + page modules (`chat-room`, `presence-roster`, `chat-launcher`, `discussion-composer`) bundled in `app.js`.
- Fixed during validation: Vite dev server served ES modules without CORS headers, so `app.js` never loaded in any browser (CSS + Livewire worked, masking it). Added `cors: true` to `vite.config.js`; port 5173 was already published.

### C01 project discussion + moderation

- Threads + one-level replies, activity sort, task linking, maintainer announcements; tombstone removals; close/reopen; rate limits (20 threads / 30 replies / min); submission-key double-submit guard; localStorage drafts.
- Safe `Markup` (links, `code`, paragraphs; XSS-tested) + `Mentions` (@-match against managers/participants/followers).
- Moderation: member reports → maintainer queue (dismiss / remove-with-logged-reason), audit-logged; `ReportStatus` enum.

### C02 live chat + launcher

- Community lounge + per-project rooms; HTTP-persisted sends with `client_key` idempotency, `NewChatMessage` broadcast (`ShouldBroadcastNow`); sockets only deliver others' messages, typing whispers, and catch-up; history cursors both directions; author retract + maintainer remove (soft deletes, tombstones); chat never sends inbox notifications.
- Floating launcher with unread dot + room panel; `chat_reads` per-room tracking.

### C03 presence + opt-in

- Opt-in presence (`show_presence`, default off) enforced in channel auth; `/online` page + per-room rosters; identity-only payloads (no email); Reverb timing (60s ping / 30s timeout) bounds stale members. Preferences UI on the profile page covers presence + four notification groups.

### C04 follows / updates / promotion / profiles

- Project + thread follows (idempotent, view-gated); maintainer-only official updates feed with follower notifications; ordinary posts notify only opted-in followers; thread mutes; minimal public profiles (published work, no private details).
- Promotion: maintainer turns any post into a task or (unverified) observation with attribution snapshot; source removal never rewrites the record.
- Export extended with official-updates + discussion sections (JSON + Markdown); inbox empty-state copy updated (inbox was already generic + visibility-filtered).

### Regressions found & fixed by the new tests

- `ChatController` had two `history()` methods (PHP case-insensitive fatal).
- Presence registrations used client-side names (`presence-*`); the broadcaster strips prefixes before matching, so **no presence join ever matched** — renamed to normalized `online` / `roster.*`, distinct from private `chat.*` (different auth rules).
- Project rooms used `project:{id}` but channel patterns require dots — renamed to `project.{id}`.
- `Markup` autolink used PHP `+` on strings (numeric addition, dropped `)` trails) — now `.`.
- Test-harness notes: `Broadcast::channel()` registers on the boot-time default driver, so channel-auth tests switch to the Reverb driver and re-require `routes/channels.php`; Pusher signs with local HMAC (no socket server contacted); `Broadcast::fake()` doesn't exist on this version — chat broadcast assertions use `Event::fake()` (the `broadcast()` helper dispatches through the event dispatcher).
- Mobile (390px): launcher FAB covered the chat Send button — responsive `bottom-24 sm:bottom-4` offset; verified via `elementFromPoint`.

### Validation

- Full suite: **395 passed / 1887 assertions** (`php artisan test`), Pint clean (was 346/1645 before A2: +49 tests, +242 assertions).
- New suites: `MarkupTest` (5), `DiscussionTest` (20), `ChatTest` (9), `PresenceAuthTest` (6), `FollowUpdatesTest` (9).
- Browser (Playwright, two authenticated sessions + mobile, `muse/results/shots-a2.cjs`): discussion index/thread, cross-session reply, **realtime chat delivery without reload**, typing indicator, presence roster in both sessions, UI presence opt-out (roster drops member), launcher unread dot + panel, updates feed, report → moderation queue, `/online`, mobile chat. No page errors. Screenshots `muse/results/a2-01-discussion-index.png` … `a2-09-mobile-chat.png`; fixtures `a2-owner/member@rev2square.test`, project `a2-discuss-o-tron-20261027` (dev DB only).

### Migrations / services / config (A2)

- Migration `000026` (discussion_posts, discussion_mutes, reports, chat_messages, chat_reads, follows, `projects.discussion_open`, `users.show_presence` + `notification_preferences`).
- Services: `reverb` container; nginx `/app/` proxy; Reverb `REVERB_APP_*` in dev `.env` (not committed; must be set wherever realtime runs); `laravel-echo` + `pusher-js` npm deps; `laravel/reverb` composer dep.

### Deferred with reasons

- Discussion realtime (page shows posts on load; only chat/presence are live sockets — spec centers realtime on chat/presence).
- Email/push notifications (inbox only, per A1 policy); chat message search; edit history beyond the Edited marker.

---

## Assignment 3 — Profiles, participation, account menu (`3. meta-muse-code-user-profiles-en.md`)

Status: **complete** (P01–P06 implemented, tested, three-session browser-verified).

### P01 account identity and menu

- Header account menu (`x-account-menu` class component + `AccountMenu` view component): avatar/initials button with display name, identity block (`@handle` or own email), bounded recent-project list (3 owned + 3 memberships, 5 max, role/status labels), links to public profile / workspace / saved / inbox with real unread count / applications / edit profile & settings, logout. Alpine open/close with outside-click, guarded Escape + focus return; anonymous header unchanged (Log in/Join).
- Mobile menu gained the same account block and links (plus the previously missing Lounge link).
- Logout closes the Reverb socket immediately (`data-logout` + echo.js listener) so presence drops at once instead of lingering to the activity timeout.

### P02 public profile vs private workspace

- Public profile (`/users/{id}` + new `/u/{handle}`): avatar/initials, name, handle, member-since, bio, skills, availability/hardware interests labeled self-described, safe links (`rel=noopener nofollow`, http(s) only), and Started/Maintaining/Working on/Contributions sections with agreeing counts and honest empty states. Always public-only — even for self (with links to edit/workspace for private records).
- Workspace = extended dashboard (`My workspace`): Needs attention (pending invitations with accept/decline, joinable accepted offers, blocked assigned tasks, unread inbox), Started by me (created, incl. drafts, gated actions), Working on, applications (existing + Join buttons), saved, contributions, recent activity (same inbox rows, not a second feed).

### P03 participation semantics (documented in code + enforced)

- Started = `projects.created_by_user_id` (new column, backfilled from owner, model hook for new rows); Maintaining = owner or active owner/maintainer membership; Working on = active membership or current non-retired task assignment; Contributions = authored observations/test reports + assigned tasks + audit-credited earlier work on finished tasks (labeled `Earlier task work`, counted once); Applications/Following/Saved stay private.
- Explicit Join step: accepted offer → applicant-only `Join project` (workspace + offer thread) creates an **active contributor** membership (`team.joined` audit). Acceptance alone still grants nothing; join grants no manage rights; revoked members cannot self-rejoin on a stale acceptance; maintainers still come only from owner invitations.
- Ownership transfer does not exist; `created_by_user_id` is the retention mechanism for when it lands (deferred, see below).

### P04 cards and navigation

- Cards show title, pitch, phase/activity/status, personal role, assigned task; archived projects keep history with an `Archived` label; unpublish removes projects AND contributions from public profiles immediately (tested); draft titles/counts never leak (counts computed on visible sets).
- Author names link to `profileUrl()` (handle-aware) from discussion, chat (incl. realtime JS), presence rosters, team lists, offer threads, observations/corrections, and test reports.

### P05 edit profile, identity, presence

- Profile page: public-profile form (handle with lowercase/reserved/duplicate/retired-handle rules, bio, skills, 5 links, availability, interests) with preview link; avatar upload/delete; existing account (name/email), password, preferences (presence + notifications), and delete sections untouched. Email never in public responses; bio/links/skills escaped; avatar JPEG/PNG/WebP/GIF ≤2 MB, ≤4000px, resized to 256px and re-encoded as JPEG (strips EXIF, first frame only, transparency flattened), served via controller with cache headers, delete resets to initials.
- Handle changes retire the old handle to `user_handles`: old links keep resolving, names are never recycled; reclaiming removes the redundant row.
- Presence preference already existed; it is now also reachable from rosters (`Presence settings` link, pre-existing) and honored everywhere.

### P06 saved, activity, notifications

- `saved_projects`: private bookmarks, idempotent save/unsave, separate from follows and membership; unviewable saves collapse into an honest hidden-count note.
- Workspace activity reuses the inbox table (verified: same row in both views); no second event/read-state table; chat still never notifies; deep links recheck access via existing inbox guards. No recommender added (none exists).

### Regressions found & fixed during A3

- Blade `@php @endphp` block in the layout broke the earlier inline `@php(...)` compile (directive parser quirk) → extracted the menu into a class component.
- Inline directives after word characters (`Inbox@if`, `member@endif`) silently stop compiling (same trap as A1) → spaces added; component + view coverage now renders every new template.
- `@{{ handle }}` renders literally (the `@@` escape eats the echo) → `{{ '@'.$handle }}`; caught by the profile screenshot, now asserted.
- Dashboard `Started by me` listed memberships (pre-existing query) → `createdProjects()` with policy-gated actions.
- Workspace contributions linked to 403 pages after unpublish (policy: drafts are managers-only) → view-filtered with a hidden-count note.
- Profile page had no status banner for avatar/preference saves → generic banner added (existing specific banners preserved).

### Validation

- Full suite: **418 passed / 2082 assertions** (`php artisan test`), Pint clean (was 395/1887 before A3: +23 tests... recount: 6 new suites, 28 tests total incl. the hiding test).
- New suites: `ProfileTest` (10), `JoinProjectTest` (3), `SavedProjectTest` (2), `AvatarTest` (2), `ContributionsTest` (6), `WorkspaceMenuTest` (5).
- Browser (Playwright, three authenticated sessions + mobile, `muse/results/shots-a3.cjs`): menu keyboard open/Escape/focus-return, populated public profile with no email leak, live join flow, workspace, profile edit round-trip, avatar upload rendering in header, unrelated-viewer privacy (no email/applications/saves), live unpublish propagation (project + contributions vanish), logout + account switching identity, mobile workspace. No page errors. Screenshots `muse/results/a3-01-menu.png` … `a3-05-mobile-workspace.png`; fixtures `a3-owner/member/viewer@rev2square.test`, projects `a3-profile-walkman-20261027` / `a3-side-radio-20261027` (dev DB only; walkman ends each run as Draft by design of the unpublish check, seed re-publishes).

### Migrations / services / config (A3)

- Migration `000027` (users: handle/bio/skills/links/availability/hardware_interests/avatar_path; `user_handles`; `saved_projects`; `projects.created_by_user_id` with backfill).
- PHP GD added to `docker/php/Dockerfile` (libpng/libjpeg + `docker-php-ext-configure`); app/queue/scheduler/reverb rebuilt and restarted. (Build note: the helper is `docker-php-ext-configure`, not `docker-php-configure`.)
- Avatar fixture source `storage/app/a3-avatar.png` (GD-generated; needed by the browser script).

### Deferred with reasons

- Ownership transfer (no transfer flow exists; `created_by_user_id` preserves attribution for it; transfer needs confirmation + security review beyond this assignment).
- Full profile pagination past 12-per-section caps (bounded lists with totals instead; no fabrication).
- Contributor reassignment of in-progress task credit beyond finished work (only completed/verified tasks credit earlier hands, honestly labeled).
- `Recommended for you` (no recommender exists; spec says omit rather than fake).

---

## Assignment 4 — Calmer visual identity (`4. meta-muse-code-calmer-visual-design-en.md`)

Status: **complete** (typography/layout/color/language refresh, before/after verified).

### What made it look templated

1. Figtree + `font-extrabold` headings on nearly every title.
2. Monospace, all-caps, wide-tracked eyebrow labels on every screen (99 occurrences).
3. A bordered card around every paragraph, post, and list item.
4. Heavy pills and shadowed cards (profile settings, badges).
5. External font dependency (Bunny CDN) with Figtree as the only voice.

### Typography (§1)

- Source Sans 3, self-hosted via `@fontsource/source-sans-3` 5.3.1 (SIL OFL 1.1), weights 400/500/600/700 only, bundled by Vite into `public/build` (verified woff2 output); Bunny links removed from both layouts — zero external font requests.
- 17px root, body line-height 1.6 (`resources/css/app.css`); sentence case everywhere by converting the shouting classes (content was already sentence case); labels now `text-sm font-semibold` in zinc/emerald.
- Headings semibold throughout (51 extrabold + 14 bold-heading conversions); page titles rescaled to 1.75rem mobile / 3xl desktop (~30/32px at the 17px root); hero keeps its larger scale.
- Monospace now only for code, hashes, paths, slugs, and machine-state markers; two `Experimental` AI badges intentionally keep a compact uppercase treatment as meaningful state markers.
- Long-form copy (project story, observations, corrections, discussion bodies/excerpts, bio) capped at 70ch.

### Layout and color (§2–§3)

- Discussion threads/replies/updates converted from nested cards to divider-separated conversation rows; real avatars (`x-avatar`, incl. realtime chat + presence rosters via new `avatar_url` payload fields) replace initials-only chips there and in chat.
- Profile settings cards lost their shadows for thin bordered panels; floating controls (launcher, dropdowns, auth card) keep functional shadows.
- Status pills kept small and informational (always text-labeled, never color-only); AI provenance (`Drafted by AI`, `Added by maintainer`) untouched; dark header kept as brand recognition with medium-weight nav (the project pages themselves were already light — no heavy banner remained to remove).

### Language and navigation (§4–§5)

- No inflated claims found or added: Home shows real featured projects with an honest empty state, one primary + one secondary action per section, methodology links instead of promises. No new slogans; the header/footer tagline kept sentence case.
- Unsaved-changes warnings: forms are plain server posts (no SPA state to lose); chat/discussion composers already autosave drafts to localStorage (A2) — nothing to add.

### Accessibility (§6) and preservation (§7)

- Global `:focus-visible` emerald outline; account-menu keyboard behavior (A3) re-verified visually; touch targets unchanged (44px+); status never color-only.
- All A1–A3 controls, labels, counts, and empty states preserved; full suite green with no test edits required by A4 (no test depended on the old classes); dark header/footer, emerald actions, avatars, and badges retained as brand anchors.

### Validation

- Full suite: **420 passed** (`php artisan test`), Pint clean; new `VisualIdentityTest` (2 tests): five key screens render with no external font URL or Figtree reference, and the stylesheet carries the Source Sans / weight / 17px / focus tokens.
- Browser (`muse/results/shots-a4.cjs`, asserting computed `font-family` per page, no page errors): after-shots `muse/results/a4-after-home.png`, `a4-after-project.png`, `a4-after-discussion.png`, `a4-after-profile.png`, `a4-after-dashboard.png`, `a4-after-home-mobile.png`, `a4-after-project-mobile.png`.
- Before/after mapping: Home `a4-before-home[-mobile].png` → `a4-after-home[-mobile].png`; project `a1-01-project.png` → `a4-after-project[-mobile].png`; discussion `a2-01-discussion-index.png` / `a2-02-thread.png` → `a4-after-discussion.png`; profile `a3-02-public-profile.png` → `a4-after-profile.png`; dashboard `a3-03-workspace.png` → `a4-after-dashboard.png`.

### Migrations / services / config (A4)

- None. New npm dep `@fontsource/source-sans-3` (OFL-1.1, bundled — no runtime CDN); `npm run build` re-run to verify font bundling.

### Deferred with reasons

- Light header variant (spec said "consider"; the compact dark header is the clearest brand anchor and pages are otherwise light).
- Full screen-reader/keyboard audit beyond the menu + focus work (spot-checked only, as in A1–A3).

---

## Final regression + combined journey

- Full suite: **420 passed / 2103 assertions** (`php artisan test`), Pint PASS (279 files). Suite run 4 consecutive times at the end: 419+1 once (single transient failure, test name not captured, did not reproduce in 3 subsequent runs), then 420/420/420 green.
- Combined journey (`muse/results/shots-final.cjs`, fixture `fj-owner@rev2square.test`, project `final-journey-rig`, dev DB only): created a project in the browser, started a **live** Bose SoundTouch import, verified ready state, posted a discussion thread, sent a project-room chat message, and confirmed workspace reflection — no page errors.
- Live import record: `https://github.com/gesellix/bose-soundtouch`, branch main, SHA `613b40e2262b7aa40cb120062e76a82e5e215886` (fresh head, fresh 10.85 MB archive download), **50 evidence files / 1137 inventory**, status ready; AI Investigator offers analysis of the 50 files at that commit. Screenshots `final-01-evidence-importing.png` … `final-05-evidence-ready.png`.
- Working tree: 191 changed/untracked paths on `main` (all assignment + revival work), intentionally left uncommitted for owner review — no commit/push performed.
