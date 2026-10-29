# UI simplification pass — 2026-10-29

Focused cleanup, not a redesign. Identity, typography, colors, and all
workflows preserved; no new dependencies, animations, or design system.
All edits are Blade copy/structure; no routes, policies, controllers,
seeders, factories, or user content touched.

## Before / after copy (important changes)

| Location | Before | After |
|---|---|---|
| Top nav (desktop + mobile) | Lounge + Methodology always visible | Lounge only when signed in; Methodology removed (footer keeps it) |
| Hero paragraph | Two long sentences about AI-assisted RE + organizing people | “Give abandoned hardware a second life. Reverse engineer together — and keep useful devices out of e-waste.” |
| Hero CTA | “Explore revival projects” | “Explore projects” (matches nav) |
| Workflow heading | Visible “How a revival works” | Removed; kept as `aria-label` on the `<ol>` |
| Workflow step 02 | Long feasibility/hypotheses paragraph | “Analyze with AI over the evidence; drafts until a maintainer approves.” (`Experimental` + `Analyze with AI` kept — test-pinned) |
| Featured heading | Eyebrow “Featured revival projects” + title “Real devices, real investigations” + selection-rules paragraph | Single “Featured revival projects”; rules paragraph removed (selection behavior unchanged) |
| Lower panels | Two long panels | One line each; “Read our methodology →” and “Find a task →” kept |
| Hero caption | “Every claim traced to its source.” | Removed (repeated the evidence promise) |
| Explore / task directory count | “…found. Filters live in the address bar, so this view can be bookmarked or shared.” | “…found.” (URL filters unchanged) |
| Task directory subtitle | “…newest first. Use the status filter to narrow to open work — …” | “Contributor tasks across published revival projects.” |
| Project header | Extra “Team” pill duplicating project nav | Removed (nav entry kept) |
| Task cards | Full description + “Done means:” + “Offer help →” per task | Description capped at 200 chars (as in directory); DoD only on task detail; per-task link renamed “Help with this task →” to differ from the general “Offer help →” button |
| Milestones empty | “…Maintainers can add them directly, or run an AI analysis…” (shown to everyone) | “No milestones yet.” (maintainers keep the “+ Add milestone” header action) |
| Observations / test reports / history empty | Coaching paragraphs | “No observations yet.” / “No test reports yet.” / “No activity yet.” |
| Observations subtitle | “…Unverified notes are starting points, not facts.” | “What members found on real hardware.” (per-item Unverified badges kept) |
| Assessment empty | Maintainer workflow explained to all visitors | Visitors: “No assessment published.” Maintainers add a “Run the AI analysis →” link |
| Export | Full paragraph + links | Links first; “Exports never include private offers, messages, or invitations.” moved into a “Privacy” `<details>` |
| Discussion rules | Always-open “How discussion works here” panel | Collapsed “Discussion help” `<details>` (all four rules kept) |
| Discussion composer | No nearby reminder | “Posts are unverified conversation — never share credentials or personal data.” beside Post |
| Discussion empty | “…Start the first one above.” for everyone | Guests: “No discussions yet.” only |
| Updates subtitle | “Short official notes… Followers hear about each one…” | “Updates from the maintainers.” |
| Project chat subtitle | “Ephemeral coordination, not decisions: …” | “Quick coordination. Record decisions in discussions or tasks.” |
| Chat empty | “No messages yet. Say hello — and keep it kind.” for everyone | Guests: “No messages yet.” Posters add “Say hello.” (“Be kind” kept once, in the lounge subtitle) |
| Chat presence panel | Large panel for everyone incl. guests | Signed-in only; guest message column goes full-width |
| Team page | “Owner — always retains access” (public) | Public: “Owner”. Retention note moved to the owner-only invite help |
| Architecture empty (no evidence) | “No architecture yet” + import/run instructions for all | Public: “No architecture published yet.” Maintainers add context + “Manage evidence” |
| Architecture empty (evidence ready) | “Map this project's architecture” + long explainer + “Only maintainers can run…” | Public: “No architecture published yet.” Maintainers add context + “Run architecture analysis” |
| Dashboard / inbox empties | “…will surface/appear here…” narration ×6 | “Nothing waiting.” / “No team memberships…” / “Nothing saved yet.” / “No recorded work yet.” / “No activity yet.” / “Nothing here yet.” |
| Profile contributions empty | “…Comments and chat do not count as engineering work.” | “No recorded contributions on published projects yet.” |
| Avatar help | “…Images are resized and re-encoded; …” | Implementation detail dropped; metadata + initials notes kept |
| Evidence human sources | “…Provenance and review status travel with each entry; …” | “…The AI investigator does not read them yet — reviewers do.” (limitation kept, badges show status per entry) |
| Agent intro | “…up to six evidence lookups per turn…” | Dropped (citations/privacy sentences kept) |
| Agent no-evidence notice | Import instructions for everyone | Maintainers keep the import prompt; others see “No evidence to chat about yet.” |
| Agent upload intro | “…describe the device and your question, then start investigating.” | Dropped (numbered form below shows the flow; formats + limits kept) |
| Agent uploads empty | “…Files appear here after upload…” | “No uploads yet.” |
| Maintainer-applications empty | “…Accepting one sends an invitation; …” | “No maintainer applications.” (kept at the actual Accept action in `_card`) |
| Offer thread empty | “…Maintainers can ask for clarification…” | “No messages yet.” |
| Evidence manager empties | “…Import one above…”, “…then run the first AI analysis.” | “No repository linked yet.” / “No analyses yet.” |

## Pages / components changed

`layouts/app.blade.php`, `welcome.blade.php`,
`livewire/projects/explorer.blade.php`,
`livewire/projects/task-directory.blade.php`,
`livewire/projects/evidence-manager.blade.php`,
`projects/show.blade.php`, `projects/discussion/index.blade.php`,
`projects/discussion/updates.blade.php`, `chat/_room.blade.php`,
`chat/room.blade.php`, `projects/team/index.blade.php`,
`projects/architecture/show.blade.php`, `tasks/index.blade.php`,
`dashboard.blade.php`, `inbox/index.blade.php`, `users/show.blade.php`,
`profile/edit.blade.php`, `projects/evidence.blade.php`,
`projects/agent/index.blade.php`, `projects/offers/index.blade.php`,
`projects/offers/show.blade.php`.
New: `tests/Feature/NavigationVisibilityTest.php`.

## Functionality explicitly preserved

- Lounge route authorization (still guest-readable; only nav links gated).
- Explore/task filters, search, sorting, URL-backed bookmarking.
- Featured-project selection, project count, All-projects link.
- Task statuses, skills, hardware badges, provenance, assignment, DoD
  (on detail), milestones, retired show/hide.
- Discussion/composer/updates publishing, following, notifications.
- Presence opt-in behavior, settings links, roster for members.
- Owner protection, invitation expiry/accept flow, offer lifecycle.
- Architecture draft/published, failure + retry, staleness, citations.
- Export privacy behavior; AI consent wording untouched; usage/budget,
  visibility, destructive-confirmation, upload-restriction, and
  admin-security copy untouched.
- No user-authored stories, discussions, reports, or AI answers edited;
  no Bublcam data edits (templates only).

## What was deliberately NOT changed after audit (§12)

Already tight or carrying must-keep information: task detail, project
create/edit form, observation/test-report forms, agent conversation,
evidence viewer + import form, artifacts/binary views, analysis reports
(provenance), milestones forms, admin settings/users/usage/audit/
moderation, inbox header note (“in-app only, no emails”), profile
privacy/presence explanations, attachment upload warning, `_post`
partials. Methodology page kept as the detail home (eyebrow + title
retained — they are not redundant there); footer kept (one-line brand +
required hackathon/license line).

## Findings provenance

- Task §§3–11 (homepage, overview, discussion, updates, chat/lounge,
  team, architecture, tasks, methodology): from the independent browser
  pass; implemented as specified, verified against the actual templates
  (two described banners no longer exist in code: no duplicate sign-in
  on discussion index, no sign-in banner on empty updates — only the
  single contextual actions, which were kept).
- §12 (dashboard, inbox, profiles, agent, evidence, offers empty
  states; avatar/evidence/agent microcopy): additional improvements
  found in this turn's code audit, not browser-confirmed findings.

## Tests

- Updated 3 expectations whose contract the task explicitly changed:
  `ArchitectureTest` (“No architecture published yet” + maintainer
  action), `CommunityWorkflowTest` (roadmap empty state split into
  visitor/maintainer tests), `DiscoveryExportTest` (directory subtitle,
  all-status intent kept).
- New `NavigationVisibilityTest`: guest/auth top-nav (desktop + mobile
  markup), footer Methodology, public lounge route.
- New role tests appended: `DiscussionTest` (empty state),
  `ChatTest` (empty state + presence panel).
- Full suite: **680 passed** (`php artisan test` in dev container).
- Frontend build: `npm run build` in node container — success.
- Live guest HTTP checks (dev stack :8080, curl): home, explore,
  bublcam-revival, lounge, discussion, updates, team, architecture —
  all 200; Lounge absent for guests, footer Methodology present, env
  message present, removed sentences absent, role copy correct. The
  only “How a revival works” match is the intentional `aria-label`.

## Not verified

- No real-browser pass (no browser tooling here): visual desktop/mobile
  rendering, focus order, `<details>` behavior on touch devices, and
  menu overflow were not eyeballed — markup reuses existing patterns.
- Signed-in live pages were verified via tests (`actingAs`), not via
  an authenticated browser/curl session.
- Earlier uncommitted work (`docker/nginx/Dockerfile.prod`,
  `docker/ops/check-static-assets.sh`,
  `muse/static-asset-permissions-fix.md`, `tests/docker/`) was left
  untouched and uncommitted.
