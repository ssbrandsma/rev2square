# Browser QA fixes — 28 October 2026

Follow-up to the independent functional QA round through http://localhost:8080
(fixture project `qa-functional-revival-20261028`, Bose-SoundTouch import).
All fixes below are implemented in the working tree, **uncommitted and
unpushed**, on top of the earlier uncommitted UI-branding work (preserved
as-is; the two change sets are separate concerns in one tree).

Fixture data was not altered. No live model calls were made for repair
validation: all verification used fake providers and the isolated `testing`
database via an ephemeral non-root MySQL user (created for the runs, dropped
after). No costs were verified; nothing here claims spend under any budget.

Screenshots referenced by the QA brief (`qa-*.jpg`) were not found in the
workspace, /tmp, /mnt, or /media, so fixes were validated against the written
observations plus the cited IDs/URLs.

## P1 — Architecture Investigator blocked by provider schema rejection

**Root cause.** `ArchitectureSchema::jsonSchema()` omitted
`additionalProperties: false` on all five object nodes (root, component,
relationship, unknown, evidence-ref). Meta's strict `json_schema` mode
rejects such schemas with 400 before the model runs — the exact observed
error (`'additionalProperties' is required…`, param `schema`). The
feasibility schema (`ReportSchema`) already set the flag everywhere, which
is why only the architecture call failed. The architecture schema also used
`const`, `minimum`, `maxItems`, and `description`, which are outside the
strict subset the provider accepts.

**Fix.**
- `app/Services/Revival/ArchitectureSchema.php` — every object now sets
  `additionalProperties: false` and requires all its properties;
  `const` → single-value `enum`; bounds/descriptions moved out of the
  wire schema (server-side `validate()` still enforces all bounds;
  `ArchitecturePrompt` already documents locator/hash formats).
- `app/Services/AI/MetaAIProvider.php` — new pre-send guard
  `assertStrictSchema()`: any object missing `additionalProperties:
  false` or not requiring every property throws `AIProviderException`
  before any HTTP is attempted (deterministic, free, recorded as
  `PROVIDER_ERROR` without retries — the job already only retries rate
  limits/timeouts).
- Recovery path already existed and is unchanged: the failed run keeps
  its `PROVIDER_ERROR` status/message, and "Retry analysis" creates a
  new snapshot run.

**Tests.**
- `tests/Unit/ArchitectureSchemaTest.php` — schema is non-recursive,
  closed, and requires-all (mirrors `ReportSchemaTest`).
- `tests/Feature/MetaAIProviderTest.php` — regression fixture replaying
  the exact live 400 (`additionalProperties…`, param `schema`);
  open/partial schemas refused with `Http::assertNothingSent()`.
- `tests/Feature/ArchitectureTest.php::test_real_provider_sends_strict_compatible_schema`
  — real `MetaAIProvider` + faked HTTP reaches `Ready` and renders the
  diagram; the wire schema is asserted strict.

**Retest (owner, requires explicit live authorization — NOT done).**
http://localhost:8080/projects/qa-functional-revival-20261028/architecture
→ "Retry analysis" → expect a `Ready` snapshot with clickable diagram.
The original failed run is preserved for comparison.

## P1 — Revival Investigator fails validation on UNKNOWN findings

**Root cause.** Prompt/validator misalignment, not a broken check. The
prompt defined `UNKNOWN` as "(cannot tell)" with no citation rule, while
`ReportSchema::validate()` requires every non-`UNVERIFIED` finding to cite
≥1 evidence id. The model therefore emitted a semantically reasonable but
uncitable `UNKNOWN` finding (Run #10, `findings[3]`), which was correctly
rejected. Grounding checks were working as designed.

**Fix (alignment, checks intact).**
- `app/Services/Revival/AnalysisPrompt.php` — explicit rule: `UNKNOWN`
  means examined evidence cannot answer → must cite it and explain in
  `limitations`; questions with no evidence at all go to `unknowns[]`,
  never to `findings[]`. Validator unchanged (still rejects uncited
  `UNKNOWN`, fabricated ids, path/hash mismatches).
- `resources/views/livewire/projects/evidence-manager.blade.php` —
  `SCHEMA_VIOLATION` runs now show a next-action hint ("No draft was
  created and your evidence is unchanged… running the analysis again
  often succeeds"). No silent paid re-runs: failures stay failed until
  the maintainer re-runs.

**Tests.**
- `tests/Unit/ReportSchemaTest.php` — cited `UNKNOWN` passes; uncited
  `UNKNOWN` fails with the observed message; empty-findings +
  populated-`unknowns` passes (the missing-evidence representation).
- `tests/Unit/AnalysisPromptTest.php` (new) — locks the prompt/validator
  alignment sentence.
- `tests/Feature/RevivalAnalysisTest.php::test_validation_failure_shows_next_action_hint`.

**Retest (owner, paid call — NOT done).**
http://localhost:8080/projects/qa-functional-revival-20261028/evidence
→ "Analyze with AI" → expect a draft report reaching "Review report".
Run #10 stays failed as the regression reference. Repeated-run
reliability is still unmeasured (one observed failure only).

## P2 — Agent citation links opened the generic manifest

**Root cause.** `AgentController::renderAnswer()` linked every verified
`[S{id}]` chip to the same generic evidence URL; no per-source target
existed.

**Fix.**
- New route `GET /projects/{project}/evidence/{source}` →
  `EvidenceController@showSource` (`projects.evidence.show`), maintainers
  only (same gate as the manifest), 404 for foreign sources.
- New view `resources/views/projects/evidence-show.blade.php`: path,
  source id, stored commit SHA, content hash, coverage (complete /
  partial with original `Lx–Ly` ranges, or an explicit "original line
  numbers unavailable" note — never manufactured), fetched time,
  upstream GitHub link, and the exact stored content. Earlier-import
  sources get a staleness banner but remain viewable (historical pinning:
  old source rows persist per commit). `?from=agent:{id}` adds a
  validated "Back to conversation" link (foreign/forged ids ignored).
- Citation chips and the "Sources cited" list now link per source;
  non-maintainers keep unlinked chips; unverified markers stay red and
  unlinked. Manifest `S{id}` cells link to the viewer too.

**Tests** (`tests/Feature/EvidenceWorkflowTest.php`,
`tests/Feature/RevivalAgentTest.php::test_citation_chips_link_to_the_cited_source`):
viewer content/commit/hash, earlier-import banner + return link, forged
`from` ignored, partial ranges honest, stranger 403, cross-project 404,
each citation opening its own source.

**Retest (owner, no AI cost).**
http://localhost:8080/projects/qa-functional-revival-20261028/agent/2
→ click S303 → expect `cmd/soundtouch-backup/README.md` at the cited
commit with a "Back to conversation" link; S305/S342 open their own
sources.

## P2 — Agent answers leaked raw Markdown

**Root cause.** `AgentController` rendered answers with the discussion
`Markup` renderer (paragraphs/code/links only) while the model writes
full Markdown (headings, bold, lists). Partial rendering exposed the rest.

**Fix.**
- New `app/Services/Agent/AgentAnswer.php` — safe renderer for the
  answer subset: `##`/`###` headings (→ h3/h4), bullet/numbered lists,
  bold/italic, fenced + inline code, quotes, rules, bare-URL autolinks.
  Everything escaped first; no raw HTML passthrough; only http(s)
  linked; `[S{id}]` markers pass through for citation linking. Tables
  are out of scope (left literal, documented here).
- `AgentController` uses it; discussions/chat rendering untouched.
- `AgentPrompt` now names the supported format explicitly.

**Tests.** `tests/Unit/AgentAnswerTest.php` (new, 7 tests incl. a
fixture replicating the observed answer shape and malicious-markup
inertness) plus
`RevivalAgentTest::test_markdown_answer_renders_with_working_citations`
(full-turn wiring: semantic tags, no raw markers, citations still link).

**Retest (owner, no AI cost).** Same conversation URL as above: the saved
answer should show semantic headings/lists with no `**`, `###`, or `*`
markers.

## P2 improvement — tool budget spent on overlapping searches

**Root cause.** Only byte-identical calls were capped; paraphrased
searches each consumed budget. Five searches + one read exhausted the
six-call ceiling before implementation code was read.

**Fix (ceiling and honesty unchanged).**
- `app/Services/Agent/AgentTurnRunner.php` — after two consecutive
  searches that returned unreads, the next search is blocked with a
  `read_before_searching` error naming the unread `[S{id}]` sources and
  remaining budget. The blocked call still counts toward the ceiling.
  Any `read_evidence` resets the streak; searches that found nothing
  never trigger it (missing-evidence turns keep reformulating and
  answering honestly). New ctor param `$maxSearchesBeforeRead = 2`.
- `app/Services/Agent/EvidenceTools.php` — search results now carry
  `imported_files`, and empty results carry a hint distinguishing "no
  imported file matched" from "only N of M discovered files were
  imported".
- `AgentPrompt` — "search at most twice before reading" planning line.

**Tests.** `tests/Unit/AgentTurnRunnerTest.php` (block + named ids,
empty-search exemption, streak reset) and
`RevivalAgentTest::test_repeated_searches_yield_to_a_read_before_budget_runs_out`
(real tools: 3rd search blocked, read succeeds, verified citation) plus
the empty-search hint test.

**Retest (owner, paid call — NOT done).** Ask a fresh evidence question
on the fixture project and confirm reads happen before the budget ends.
Single-observation caveat from QA still applies: this improves planning,
it does not prove every investigation converges.

## P3 — history lost the assignee identity

**Root cause.** Two recorders disagreed: `TaskWorkflow::assign()` stores
`assignee` (name), but the offer-accept path in `OfferController`
stored only `assignee_id` — so claim-accepted assignments (the QA flow)
rendered the `?? 'someone'` fallback on both history lists.

**Fix.**
- `app/Http/Controllers/OfferController.php` — claim acceptance now
  records the assignee name too.
- `app/Models/AuditEvent.php::assigneeMap()` — resolves assignees for a
  history list in one query; both history controllers pass it.
- New partial `resources/views/projects/_history-assignee.blade.php` —
  recorded name first, live-name fallback for pre-fix rows (this repairs
  the fixture's display without touching its data), profile link when
  the user resolves, `'someone'` only when truly unavailable.
  Wired into `projects/tasks/show.blade.php` and `projects/show.blade.php`.

**Tests** (`tests/Feature/ContributionOfferTest.php`): accepted claim
records the name and both histories show it with a profile link; old
id-only rows resolve; deleted users still fall back safely.

**Retest (owner, no AI cost).** Task page and project history for the
fixture (task 22 / offer 5) should name QA Contributor 20261028 with a
profile link:
http://localhost:8080/projects/qa-functional-revival-20261028/tasks/22
and http://localhost:8080/projects/qa-functional-revival-20261028#history

## P3 — draft task cards advertised Offer help; roadmap message wrong

**Root cause.** The per-task "Offer help →" link checked only
`status === Open`, ignoring `ProjectActionPolicy::contributionDenial()`
(which the section-level CTA already respected). The roadmap empty state
described only the AI path although manual creation works.

**Fix** (`resources/views/projects/show.blade.php`).
- Per-task offer links hidden whenever `$contributionDenial` is set
  (draft/archived/paused). Server-side denial in `OfferController`
  unchanged. Owners keep the Details page (assign form + edit) instead
  of an apply-to-own-draft invitation.
- Roadmap empty message now names both paths: direct creation and
  approve-the-roadmap generation.

**Tests** (`tests/Feature/CommunityWorkflowTest.php`): draft hides
task offer links but keeps Details; published keeps them; roadmap
message asserted.

**Retest (owner, no AI cost).** Fixture project page (currently Draft):
no "Offer help" anywhere, denial notice shown, roadmap message updated.

## P3 — /tasks scope wording

**Fix.** Header reworded to describe the all-status directory
("Contributor tasks across published revival projects, newest first.
Use the status filter to narrow to open work…"). Behavior, defaults,
and shareable URLs untouched — the deliberate choice over changing the
default filter. Test: `DiscoveryExportTest::test_directory_describes_all_status_scope`.
Live-verified: http://localhost:8080/tasks returns 200 with the new
copy. Retest: http://localhost:8080/tasks (no AI cost).

## Follow-up investigation — AI spend visibility

**Assessment (no prices invented; QA costs NOT verified).**
- Already recorded per call: model, input/output tokens, duration
  (`token_usage` on runs, snapshots, agent messages; structured logs).
  Already shown: report review page (in/out/total) and architecture
  page (model + total tokens).
- Added (this change, tokens only): per-run totals in the evidence
  runs list; per-answer in/out (or honest "≈N estimated") + duration on
  agent conversations. Tests assert tokens display and no currency text.
- Still absent by design: per-run cost, cumulative spend, monetary
  caps. Provider pricing for `muse-spark-1.3` was not available to this
  session, so no EUR conversion was implemented — any "cap" today is a
  call-count/rate/token-budget limit (see `config/ai.php`: 10
  analyses/hour/user, 6 agent tool calls/turn, token budgets), which
  bounds usage but is not a spend cap and is not presented as one.
- Proportional next step (not implemented): a maintainer spend page
  summing recorded tokens per project, plus optional configured
  price-per-1k (operator-entered, clearly labeled estimate) — only if
  the owner supplies real pricing.

## Verification

Commands (run as container user `www-data`, never root; `testing` DB
via an ephemeral `tester` grant, dropped afterwards; `phpunit.xml`
untouched):

- Full suite: `vendor/bin/phpunit -c phpunit.tester.xml
  --do-not-cache-result` → **OK, 517 tests / 2495 assertions**
  (was 479/2324; +38 tests, all new coverage listed above).
- `vendor/bin/pint --test` → **PASS, 307 files**.
- Read-only HTTP: `/` 200, `/tasks` 200 with new copy; all new-viewer
  and authz paths covered by tests (authenticated fixture pages were
  not fetched: no bypass login per instructions).

## Implemented vs independently verified

- Implemented + test-verified (fake providers, isolated DB): all nine
  fixes above.
- Live-verified in a browser this session: none — this environment has
  no browser, and the QA screenshots were unavailable.
- Requires owner action in the in-app browser at http://localhost:8080:
  1. Architecture retry (paid — needs explicit authorization).
  2. Feasibility re-analysis (paid — needs explicit authorization).
  3. Fresh agent question for planning behavior (paid).
  4. Free retests: agent conversation 2 rendering + S-links, task 22 /
     project history names, draft offer-link hiding, roadmap + /tasks
     copy, token lines on runs/agent pages.

## Remaining blockers / not validated

- Live Meta API behavior for the corrected architecture schema
  (strict-subset reasoning + docs, but the wire proof needs one
  authorized call) and feasibility-report reliability across runs.
- Everything the QA brief already listed as not validated end to end
  (diagram interaction, report publish/merge, follow-up/save flows,
  uploads, password reset/email, invitations, deletion, realtime
  delivery) remains unvalidated.
- Privacy behaviors the brief marked working (private-conversation 403,
  export exclusions) were preserved; the touched suites covering them
  pass. No exhaustive security audit performed.
- Working tree also still holds the earlier uncommitted UI-branding
  change set; nothing was committed, pushed, or merged.
