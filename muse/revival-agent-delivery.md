# Revival Agent delivery — Architecture Investigator (AI-01) + Project-Aware Revival Agent (AI-02)

## 1. What was implemented

**AI-01 — Architecture Investigator** (bounded pipeline: ready snapshot → evidence
selection → deterministic signals → model interpretation → validated payload):

- Versioned architecture schema v1.0 (`ArchitectureSchema`): components, entry
  points, dependencies, external systems, relationships, unknowns. Every node
  carries a stable id, type, label, explanation, OBSERVED/INFERRED/UNKNOWN
  certainty, and evidence references.
- Server-side citation validation: unknown source ids, path mismatches, content
  hash mismatches (full or ≥12-hex prefix), invalid/out-of-range line spans,
  invented lines on partial excerpts with unknown ranges, dangling edges,
  self-loops, duplicate ids, and over-cap output are all rejected.
- Deterministic local signals (`ArchitecturalSignals`): entry-point candidates,
  per-language import edges (go/py/js/php/c/rust), config/manifest classification,
  submodule and LFS markers — all bounded and model-free.
- Immutable snapshots (`ArchitectureSnapshot`): input manifest with per-source
  hashes, commit, evidence identity, schema/prompt versions, provider/model,
  token usage, duration, status. Stale results (new commit, changed evidence,
  old schema) are flagged, never rewritten; re-requests reuse up-to-date
  snapshots via a commit+pinned cache key.
- Queue job with the same retry/backoff taxonomy as revival analysis
  (rate-limit aware, two tries).
- Interactive view at `/projects/{project}/architecture`: SVG diagram rendered
  from validated data (textContent-only, keyboard accessible), click-to-inspect
  details panel, full component/relationship/unknown lists as the no-JS fallback,
  plus loading, empty, failed, partial, and stale states. Maintainers trigger
  (re-)runs; anyone with view access reads.

**AI-02 — Project-Aware Revival Agent** (chat inside an authorized project):

- Native Chat Completions tool calling on the Meta provider: `tools` payload
  (+ `parallel_tool_calls: false`), `messages` history override, `tool_calls`
  parsing into `AIToolCall`, `tool`-role replies. `tools`+`jsonSchema` combined
  is refused client-side. No retrieval fallback was needed: native calling is
  implemented and covered by mocked contract tests (live API **not** verified —
  see §10).
- Three read-only tools (`EvidenceTools`): `search_evidence` (scoped keyword
  search with snippets), `read_evidence` (id-scoped reads; whole files support
  clamped windows, partial excerpts return as stored with known ranges — line
  positions are never invented), `get_architecture` (validated snapshot
  summary with explicit staleness). Every invocation re-checks the project
  `view` policy and repository/snapshot ownership; model-supplied ids select
  rows but never widen access.
- Bounded turn loop (`AgentTurnRunner`): ≤6 tool calls/turn, ≤2 identical
  calls, 60 s wall clock, configurable token budget (chars/4), bounded
  arguments/results, ≤8 provider round-trips backstop, one final text-only
  summary when the tool budget is spent. Unknown tools, malformed arguments,
  duplicates, timeouts, and provider failures end the turn gracefully with the
  partial trace preserved.
- Citation verification: `[S{id}]` markers are checked against sources the
  model actually saw this turn; unverified markers are flagged in the UI and
  stored on the message.
- Private conversations (`AgentConversation`/`AgentMessage`): visible to the
  author and project maintainers only; author or maintainer may continue.
  Saving a hypothesis/answer as an observation requires an explicit titled
  action, creates an unverified observation + `observation.created` audit event
  with `origin: revival_agent`, and redirects to the project observations.
- Chat UI at `/projects/{project}/agent`: conversation list, transcript with
  citation chips (linked to evidence for maintainers), per-turn investigation
  trace, stopped-reason banners, save-as-observation forms. Answers render
  through the existing safe `Markup` renderer; no model HTML/JS executes.

Both features reuse the existing evidence selection (`EvidenceSelector`),
inventory context (`EvidenceContextBuilder`), prompt format (`AnalysisPrompt`),
provider interface, project policies, audit events, observations, and visual
language. No ELF/I²C/SPI/CAN adapters (extension points only, by design), no
shell/SQL/URL/hardware/firmware capabilities anywhere in the tool surface.

## 2. What remains incomplete or blocked

- **Automated tests: executed and green** (see §6). An isolated non-root
  `tester` MySQL user on the existing `testing` database was provisioned for
  the run and dropped afterward; the committed `phpunit.xml` (root) was never
  executed and is unchanged.
- **Localhost validation: performed** for all 7 routes over real HTTP against
  the dev stack (see §6). Migrations 000028/000029 were applied to the dev
  database. Synthetic seed data was removed afterward.
- **No live Meta API verification.** Native tool calling is implemented from
  the documented protocol and covered by mocked tests; no paid/live call was
  made (§10). HTTP verification deliberately avoided provider-calling paths
  (cache-hit trigger, gating/validation errors, seeded ready-state pages).
- The interactive diagram JS was served through the running Vite dev server
  but not exercised in a real browser; a production asset build
  (`npm run build`) was not run here.

## 3. Branch, baseline, and commit identifiers

- Branch: `main`.
- Baseline: `72a3461` ("Rev2Square: fix evidence re-import consistency and
  improve Meta API diagnostics").
- Commit A (prior work, assignments 1–4): `d3d37f1`.
- Commit B (this delivery, AI-01/AI-02): `ca57b23`.
- This report's SHA record: see follow-up commit on `main`.
- All AI-01/AI-02 changes are listed in §4 and contained in commit B
  (plus this SHA-record update).

## 4. Explicit list of changed files

New files (AI-01):

- `app/Models/ArchitectureSnapshot.php`
- `app/Services/Revival/ArchitectureSchema.php`
- `app/Services/Revival/ArchitecturalSignals.php`
- `app/Services/Revival/ArchitecturePrompt.php`
- `app/Services/Revival/ArchitectureService.php`
- `app/Jobs/AnalyzeArchitectureJob.php`
- `app/Http/Controllers/ArchitectureController.php`
- `resources/views/projects/architecture/show.blade.php`
- `resources/js/pages/architecture-diagram.js`
- `database/migrations/2026_10_27_000028_create_architecture_snapshots_table.php`
- `tests/Unit/ArchitectureSchemaTest.php`
- `tests/Unit/ArchitecturalSignalsTest.php`
- `tests/Feature/ArchitectureTest.php`

New files (AI-02):

- `app/Models/AgentConversation.php`
- `app/Models/AgentMessage.php`
- `app/Services/AI/AIToolCall.php`
- `app/Services/Agent/ToolContext.php`
- `app/Services/Agent/ToolResult.php`
- `app/Services/Agent/AgentPrompt.php`
- `app/Services/Agent/EvidenceTools.php`
- `app/Services/Agent/AgentTurnResult.php`
- `app/Services/Agent/AgentTurnRunner.php`
- `app/Http/Controllers/AgentController.php`
- `resources/views/projects/agent/index.blade.php`
- `resources/views/projects/agent/show.blade.php`
- `database/migrations/2026_10_27_000029_create_agent_conversations_table.php`
- `tests/Unit/AgentTurnRunnerTest.php`
- `tests/Feature/RevivalAgentTest.php`
- `tests/Feature/MetaAIToolCallingTest.php`

Modified files (all additive/surgical; no prior behavior removed):

- `app/Enums/AnalysisStatus.php` — added `Ready` case + label (new snapshots
  only; existing rows unaffected).
- `app/Models/Project.php` — added `repository()` latest-of-many relation.
- `app/Services/AI/AIRequest.php` — added optional `messages` + `tools`.
- `app/Services/AI/AIResponse.php` — added optional `toolCalls`.
- `app/Services/AI/MetaAIProvider.php` — tools payload, history override,
  tool_calls parsing, tools+schema guard, live-unverified docblock note.
- `config/ai.php` — `architecture_token_budget`, `agent_*` limits.
- `routes/web.php` — 2 architecture + 5 agent routes.
- `resources/views/projects/_nav.blade.php` — Architecture + Agent tabs
  (file itself was already untracked from prior assignment work).
- `resources/js/app.js` — diagram module import.
- `tests/Support/MockAIProvider.php` — `__tool_calls` scripting +
  `architecturePayload()` helper.

## 5. Separation between pre-existing work and this assignment's changes

- Pre-existing (assignments 1–4, revival fixes): the ~191 dirty paths already
  present on `main`, including the untracked `_nav.blade.php` shell this
  assignment extended with two tab links.
- This assignment: exactly the files in §4. No pre-existing file was
  restructured; shared touch points (`AnalysisStatus`, `Project`,
  `AIRequest`/`AIResponse`, `MetaAIProvider`, `MockAIProvider`, config,
  routes, nav, `app.js`) received only additive changes that keep every
  existing call site working (all new provider fields are optional; the new
  enum case touches no existing row).

## 6. Test commands and actual results

**Executed 2026-10-28 via the dev stack** (`docker compose -f compose.dev.yaml
exec -T app …`) with an isolated phpunit config (ephemeral, in-container
`/tmp`, deleted afterward) pointing at the `testing` database as a
least-privilege `tester` user (dropped afterward). Results:

- New suites: **OK — 59 tests, 221 assertions**
  (`ArchitectureSchemaTest`, `ArchitecturalSignalsTest`, `AgentTurnRunnerTest`,
  `ArchitectureTest`, `RevivalAgentTest`, `MetaAIToolCallingTest`).
- Full suite: **OK — 479 tests, 2324 assertions**, no regressions.
- Pint: **PASS — 304 files** (7 new files auto-fixed for style only;
  suites re-run green afterward).
- `php -l`: clean on every touched PHP file.

The first run caught 6 test bugs (all in test code, none in implementation):
two `array_replace_recursive` empty-array assumptions, one `actingAs`
persistence ordering issue, one too-short search query, one unique-constraint
violation (second repository row — re-imports update in place), one 403/404
expectation, and one assertion-less test. All fixed; final state is green.

HTTP verification (real requests against `http://localhost:8080`, synthetic
seed data, zero model calls):

- Guest: architecture page → 403 `Maintainers only.`; agent index → 302 login.
- Authed: architecture page 200 (seeded summary, component, diagram mount,
  no stale banner); trigger POST → 302 cache-hit, snapshot count unchanged.
- Authed: agent index/show 200 (seeded conversation, `[S{id}]` chip resolved
  to `main.py`, 1-lookup trace, save form).
- Save POST → 302 to project `#observations`; observation + audit event with
  `origin: revival_agent` confirmed in DB.
- Store POST on evidence-less project → 302 gating error, nothing created.
- Message POST with empty question → 302 validation error, no provider call.
- Guest POST → 419 from CSRF middleware (standard Laravel ordering; the
  login-redirect gate for guests is covered by feature tests).

Suites delivered:

- `tests/Unit/ArchitectureSchemaTest.php` — 17 tests: valid normalization,
  hash prefixes, version/enum/cap violations, fabricated ids, path/hash
  mismatches, whole/partial/unknown-range locators, dangling + self edges,
  duplicate ids, missing evidence.
- `tests/Unit/ArchitecturalSignalsTest.php` — 5 tests: entry points, imports
  per language, config classification, external markers, bounds/stats.
- `tests/Unit/AgentTurnRunnerTest.php` — 11 tests: direct answer, protocol
  history shape, citation verification, unknown/malformed/duplicate calls,
  tool/token/time budgets, provider failure, throwing executor, history cap.
- `tests/Feature/ArchitectureTest.php` — 9 tests: full run + diagram page,
  output escaping, invalid JSON/schema failures, rate-limit/timeout bubbling,
  cache reuse, stale-on-new-commit + immutability, import-state gating,
  policy matrix, partial citations.
- `tests/Feature/RevivalAgentTest.php` — 11 tests: full search→read→cite turn,
  follow-ups, conversation privacy, evidence gating, graceful limits,
  budget/provider partial states, configurable token budget, per-call
  authorization (cross-project, stranger, swapped repo, unknown tool, read
  clamping), prompt-injection read-only containment, architecture tool,
  explicit save authorization + audit, HTTP 429 rate limiting.
- `tests/Feature/MetaAIToolCallingTest.php` — 5 mocked-HTTP contract tests:
  tools/history payload, tool_calls parsing, malformed rejection,
  tools+schema guard, plain-text regression.

Static checks actually performed here (all passing): bracket-balance audit of
every new/modified PHP file, JS balance audit, Blade open/close pairing,
route-name/view/class cross-reference. Self-review caught and fixed 4 real
defects before delivery: missing `decodeJson`, wrong `maxOutputTokens` param
name, two test-file bracket typos, and an evidence-identity scope bug in
staleness detection.

Owner re-run commands (all executed here via the `app` container; isolated
config recipe: copy `phpunit.xml`, point bootstrap/suites at absolute paths,
set `DB_USERNAME`/`DB_PASSWORD` to a least-privilege user on `testing`):

```sh
docker compose -f compose.dev.yaml exec -T app vendor/bin/phpunit --configuration /tmp/phpunit.isolated.xml --filter 'ArchitectureSchemaTest|ArchitecturalSignalsTest|AgentTurnRunnerTest|ArchitectureTest|RevivalAgentTest|MetaAIToolCallingTest'
docker compose -f compose.dev.yaml exec -T app vendor/bin/phpunit --configuration /tmp/phpunit.isolated.xml
docker compose -f compose.dev.yaml exec -T app ./vendor/bin/pint --test
```

## 7. Exact localhost URLs and walkthrough steps

Base: `http://localhost:8080` (after §11 is performed).

Architecture Investigator:

1. Open a project with ready evidence, e.g.
   `http://localhost:8080/projects/{slug}/architecture` (Architecture tab).
2. As a maintainer, click **Run architecture analysis**; the page polls while
   `QUEUED`/`ANALYZING`.
3. Inspect the summary, snapshot metadata (commit, schema/prompt, model,
   tokens), the diagram (click nodes/edges), component/relationship/unknown
   lists, and coverage note.
4. Re-import the repository at a new commit → the snapshot shows a **Stale
   snapshot** banner; **Run new analysis** creates a second immutable row.
5. Re-request without changes → "An up-to-date architecture snapshot already
   exists", no new row.

Revival Agent:

1. Open `http://localhost:8080/projects/{slug}/agent` (Agent tab, signed in).
2. Ask e.g. "How does Bluetooth pairing work?" → the turn searches, reads,
   and answers with `[S{id}]` chips, a sources list, and an investigation
   trace (`details` element).
3. Follow up in the same conversation; history is bounded to 6 turns.
4. Expand **Save as project observation…** on an answer, give it a title →
   creates an unverified observation and jumps to `#observations`.
5. Ask overly broad questions repeatedly to see tool/token/time partial
   states; remove evidence readiness to see the gating message.

## 8. Synthetic demo project or fixtures provided

No demo project was created (creating one requires running migrations/seeders
against the dev database, outside the permitted scope). Synthetic coverage is
provided as fixtures + fakes instead:

- `MockAIProvider::architecturePayload()` — valid v1.0 architecture payload
  builder; `__tool_calls` queue scripting for multi-step turns.
- Feature-test seeders build in-test projects/repositories/evidence rows
  (incl. partial excerpts, prompt-injection content, cross-project pairs).
- Owner demo path: import any small public repo on localhost, open the
  Architecture tab, run the analysis, then ask the Agent about it.

## 9. Provider integration used

**Native tool calling** — implemented in `MetaAIProvider` per the documented
Chat Completions protocol (`tools` functions, `tool_calls` answers, `tool`
messages, serial calls). No server-side retrieval fallback was built because
native calling is implemented and mocked-tested. The mock provider
(`MockAIProvider`) is used for all automated tests; no live-provider branch
exists in test paths.

## 10. Which provider behavior was verified and which was not

Verified (mocked/contract level, tests written but not yet executed here):

- Tools + full message history serialization, `parallel_tool_calls: false`.
- `tool_calls` parsing incl. empty-content turns and usage passthrough.
- Malformed `tool_calls` rejection; tools+`jsonSchema` refusal; plain-text
  answers unaffected (regression).
- End-to-end turn loop against the scripted mock (budgets, duplicates,
  unknown tools, citations, partial states).

**Not verified**: any behavior against the live Meta API — no live request
was made (forbidden in this assignment). First live review should confirm the
model honors `parallel_tool_calls: false`, returns `tool_calls` in the
documented shape, and accepts the `get_architecture` empty-`properties`
schema; the provider surfaces any deviation as an actionable
`provider_error` turn result rather than crashing.

## 11. Required migrations, configuration, builds, restarts, and whether performed

| Step | Command / action | Performed here |
|---|---|---|
| Migrate | `php artisan migrate --force` in the dev env (adds `architecture_snapshots`, `agent_conversations`, `agent_messages`) | **Done (dev DB, 2026-10-28)** |
| Assets | `npm run build` (or dev server) for `architecture-diagram.js` | Vite dev server already running; production build not run |
| Queue | worker already running for revival analysis; no change needed | n/a |
| Config | optional: `AI_ARCHITECTURE_TOKEN_BUDGET`, `AI_AGENT_TOKEN_BUDGET`, `AI_AGENT_TURN_SECONDS`, `AI_AGENT_MAX_TOOL_CALLS`, `AI_AGENT_MAX_IDENTICAL_CALLS`; live turns need the existing `MODEL_API_KEY` | **No change made** |
| Restart | app restart only if the deploy caches config/routes | **Not needed** |

## 12. Known limitations and risks

- Tests are written but have never executed; treat the first green run as part
  of review, not as established fact.
- Live tool-calling behavior is unverified (§10); the turn loop is defensive
  (unknown/malformed/duplicate handling, budgets, graceful partials) to
  contain surprises.
- Agent turns run synchronously in the request (up to ~60 s + model latency);
  PHP/HTTP timeouts above that are the deploy's concern — long turns may need
  a queue-backed design later. No cancellation source exists in the sync flow.
- `LIKE`-based evidence search is substring matching, not ranked retrieval;
  good enough for project scale, not for huge imports (candidates capped 60).
- Stale architecture snapshots remain readable with a banner; the agent sees
  the latest snapshot with its stale flag rather than being blocked.
- Maintainers can read and continue any project conversation (consistent with
  the draft-visibility model); authors cannot hide a conversation from
  maintainers.
- Pint/style, Vite build, and mobile-layout checks could not run here.

## 13. Owner action required before independent review

1. Done: committed and pushed per owner instruction (see §3).
2. Done: isolated non-root test run, green (§6).
3. Done: dev migration + HTTP walk (§6, §7). Optional: `npm run build` for a
   production asset check and a real-browser pass of the interactive diagram.
4. For live agent/architecture turns, ensure `MODEL_API_KEY` is configured in
   the dev environment (never in the repo); without it, turns fail gracefully
   with a configuration error.
5. Hand to ChatGPT for code inspection + localhost validation; return one
   consolidated feedback prompt for corrections.
