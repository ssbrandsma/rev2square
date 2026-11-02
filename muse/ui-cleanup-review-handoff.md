# UI cleanup — review handoff

Finish of the application-wide UI consistency pass plus the two mandatory
corrections (owner help-hiding, single rich story editor). Branch: `main`
(commit hash reported with the push confirmation).

## Scope

- One shared project shell (`_nav` + `_back-link`) across project pages and
  nested workflows, with permission-dependent tabs and parent-section active
  states. Roadmap/Tasks keep their overview-anchor destinations.
- Lounge/chat: single H1, short intro, usernames-only presence roster that
  collapses when empty, no permanent "Signing you in" state, chat help moved
  behind a "Chat help" disclosure, launcher toggle named for both states.
- Artifacts/analysis: results-first layout, technical provenance behind
  details, raw warning codes translated via `WarningLabels` (code kept in
  expandable diagnostics), backend-specific empty panels, honest Partial
  coverage, source-browser/download work preserved.
- Agent page/conversations: compact upload block, scoped empty states, answer
  first with token/timing/consent history in one details area.
- Overview/tasks, editor/creation, workspace/inbox/profile, usage labels,
  admin settings/analysis/moderation/audit readability, global titles and
  sentence case — per the brief.
- Mandatory correction 1: owners AND maintainers cannot file offers
  (`ProjectActionPolicy::offerDenial`, server-side in `OfferController` and
  gated in views). Management actions unchanged.
- Mandatory correction 2: exactly one rich story editor on project edit,
  directly after Title/Pitch, inside the single "Save project" form.
  Legacy plain text loads as paragraph blocks automatically; the plain
  textarea now exists only on project creation; hidden legacy fields can
  never overwrite the rich document (tested).

Important files: `app/Services/Community/ProjectActionPolicy.php`,
`app/Http/Controllers/OfferController.php`,
`app/Http/Controllers/ProjectController.php`, `app/Support/WarningLabels.php`,
`app/Models/AiUsageEntry.php`, `resources/views/projects/_nav.blade.php`,
`resources/views/projects/edit.blade.php`,
`resources/views/projects/_story-editor.blade.php`,
`resources/views/projects/_form-identity.blade.php`,
`resources/views/projects/_form-details.blade.php`,
`resources/js/pages/presence-roster.js`,
`resources/js/pages/chat-launcher.js`, and the new suites
`ProjectShellTest`, `LoungePresenceTest`, `AdminPresentationTest`,
`AnalysisPresentationTest` plus extended `ProjectPresentationTest`,
`CommunityWorkflowTest`, `ConversationInboxTest`, `ProfileTest`.

## Verification

| Check | Result |
|---|---|
| UI suites on MySQL (11 suites: shell, workflow, presentation, lounge, chat, inbox, profile, admin, analysis) | 131 passed |
| Full suite on MySQL (`testing` database, disposable fixtures) | 953 passed, 4 failed — the same 4 fail on the pristine tree (pre-existing binary-runner environment gaps: EvidenceValidator HTML, runner images, artifact sharing × 2) |
| `tests/js` (zero-dependency Node asserts) | all 5 suites pass |
| `vite build` in `node:22` container | success, ~0.5 s; bundle contains the new realtime modules |
| `php -l` on changed PHP files | clean |
| Pint on all touched files | clean |
| `artisan view:cache` (all Blade compiles) | clean |
| HTTP smoke via nginx | `/lounge`, `/online`, `/login` all 200 with the corrected markup |
| Browser validation | NOT DONE — no browser here; localhost checklist below |

Pre-existing issues left untouched (present at HEAD, out of scope):
14 Pint findings in analysis-runner files, and the 4 MySQL test failures
listed above. The dev `rev2square` database was not modified (reads only);
all test writes went to the isolated `testing` database.

## Corrections applied after independent review

Both previously deferred owner requirements are now implemented:

1. Lounge heading: the H1 is "Community" (eyebrow removed, short intro
   kept), the tab title is "Community", and the launcher picker label is
   "Community" to match. No "Community lounge" text remains on the page.
2. Online-user panel: the opted-in caption and "Presence settings" link
   are removed everywhere; panels render collapsed and only appear with
   usernames when members are present. Auth, opt-in/opt-out, and the
   profile presence settings are unchanged.

Kept deliberately (flagged for owner overrule):

- Project chat keeps its one-line purpose subtitle, mirroring the lounge
  intro treatment.
- The admin moderation queue still labels lounge rows "Community lounge
  message (no project)" — backend precision, not user-facing naming.
- `/online` keeps its guest sign-in call-to-action (outside the panel;
  without it guests see a blank page) and its one-line page description.
- The denied-access roster notice ("no longer have access here") still
  displays; every other empty/error state collapses silently.

## Localhost validation

Environment is already running (app, nginx, db, node; queue, scheduler,
reverb were already up). If it was stopped, restart without rebuilding:

```sh
docker compose -f compose.dev.yaml up -d app nginx db node
```

Note: after editing Blade files locally, run
`docker compose -f compose.dev.yaml exec app php artisan view:cache` —
the web runtime cannot refresh compiled views itself (volume ownership),
so pages 500 until the cache is rebuilt. No data impact.

Then open http://localhost:8080 (Vite dev on :5173; production assets
also built fresh in `public/build`) and check with disposable fixtures:

1. Lounge: H1 "Community", short intro, no "Community lounge" text;
   Here-now hidden when empty, usernames only when members present.
2. Online now: roster hidden when empty; guest sign-in prompt intact.
3. Project pages + nested pages (team, task, contribute, evidence, edit,
   offers, milestones): shared nav, correct active tab, no duplicated titles.
4. Owner view: no "Offer help" / "Help with this task"; contributor view:
   both present and working.
5. Edit a legacy plain-text project: story loads as paragraphs in the one
   rich editor directly after Title/Pitch; save once; no text lost. Edit
   a rich-story project: formatting/images preserved. Failed validation
   preserves all edits.
6. Inbox: single title, count in H1, "Mark all as read" disabled at zero.
7. Profile → Presence and notifications: in-app-only delivery note and
   presence controls live here.
8. Analysis detail (e.g. Bublcam run): human-readable warnings, no empty
   native-code panels on JADX output, coverage stated honestly.
9. Live chat + presence across two sessions (needs Reverb, running):
   messages/typing/rosters update; unpublish/revoke/suspend strands
   stale subscribers (realtime fixes `a0ffc37`/`d0c38ac`).
10. Narrow desktop (~750px) + mobile widths: nav, chat composer, sidebars.

## Deployment

- One migration: `2026_11_02_000001_add_channel_epoch_to_projects`
  (from the realtime fixes; run `php artisan migrate`).
- Refresh the frontend build during deploy (`public/build` is git-ignored).
- Reverb must be running for chat/presence (already the case).
- Standard deploy only after the independent review above passes.
- Not done here: no deploy, no `update_prod.sh`, no production contact.
