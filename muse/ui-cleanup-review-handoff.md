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
| Focused UI suites (8 suites incl. new coverage) | 100 passed / SQLite |
| Full suite on SQLite | 915 passed, 26 failed — byte-identical failure set on the pristine tree (pre-existing MySQL/toolchain environment gaps, unrelated to this change) |
| `php -l` on changed PHP files | clean |
| Pint on all touched files | clean (1 issue fixed in `WarningLabels.php`) |
| `artisan view:cache` (all Blade compiles) | clean |
| `node --check` on changed JS | clean |
| Canonical MySQL gate (`db` host) | NOT RUN — no Docker in this environment; owner command below |
| `vite build` | NOT RUN — sandbox has Node 18, Vite 8 needs Node 22+; owner command below |
| Browser validation | NOT DONE — no browser here; localhost checklist below |

Pre-existing issues left untouched (present at HEAD, out of scope):
14 Pint findings in analysis-runner files, and the 26 SQLite-only test
failures listed above.

## Deliberate deviations from the brief

- Lounge keeps "Community lounge" as the H1 (with "Community" eyebrow)
  instead of "Community" alone: the brief also requires picker labels to
  match room headings, and "Community" alone collides with the global nav.
  Single-H1 + short-intro goal is met; locked in `LoungePresenceTest`.
- The Here-now panel keeps one concise caption line
  ("Only members who opted in… / Presence settings") so opted-out users get
  an explanation instead of a bare empty box; the live roster itself is
  usernames-only and collapses when empty.
- Project chat keeps its one-line purpose subtitle, mirroring the lounge
  intro treatment.

## Localhost validation

Owner steps (Docker required):

```sh
docker compose -f compose.dev.yaml up -d --build
docker compose -f compose.dev.yaml exec app php artisan test
docker compose -f compose.dev.yaml exec app npm run build
```

Then open http://localhost:8080 and check:

1. Lounge: single heading, short intro, Here-now shows usernames only.
2. Project pages + nested pages (team, task, contribute, evidence, edit,
   offers, milestones): shared nav, correct active tab, no duplicated titles.
3. Owner view: no "Offer help" / "Help with this task"; contributor view:
   both present and working.
4. Edit a legacy plain-text project: story loads as paragraphs in the one
   rich editor; save once; no text lost. Edit a rich-story project:
   formatting/images preserved.
5. Inbox: single title, count in H1, "Mark all as read" disabled at zero.
6. Profile → Presence and notifications: in-app-only delivery note lives here.
7. Analysis detail (e.g. Bublcam run): human-readable warnings, no empty
   native-code panels on JADX output, coverage stated honestly.
8. Narrow desktop (~750px) + mobile widths: nav, chat composer, sidebars.

## Deployment

- No migrations, no config/compose/infra changes (app + views + JS + tests).
- Refresh the frontend build during deploy (`public/build` is git-ignored).
- Standard deploy only after the independent review above passes.
