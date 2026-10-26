# Rev2Square — Implementation Plan (Hackathon Edition)

## Goal

Deliver the smallest working hackathon vertical slice of Rev2Square (Meta Global AI Developer Hackathon 2026, Indie Developer track) before 4 November 2026, 21:00 ET: GitHub import → evidence manifest → real Meta AI analysis → feasibility assessment → verifiable experiments → human approval → published project roadmap → contributor action. Persistence is deliberately simplified: the complete validated AI report is stored as versioned JSON in `analysis_reports`; only collaboration entities (projects, evidence sources, runs, milestones, tasks, offers) are normalized. A project supports many analysis runs over time; each run creates one new immutable report version and publishing a new version never duplicates milestones/tasks or overwrites history.

Source baseline: `docs/Rev2Square_PRD_v0.3_Hackathon_Edition.md` (PRD v0.3). Approved refinements (26 Oct 2026): (1) simplified H0 persistence, (2) re-analysis/report versioning, (3) deadline-first scoping with WP0 as local dev environment only. This plan revision implements all three; §PRD Corrections lists every resulting PRD inconsistency as a proposal — the PRD itself is untouched. No application code, dependencies, or containers exist yet; no WP starts without approval.

## Success Criteria

- One excellent end-to-end case works live: public repo import → source manifest → real Meta analysis → validated structured report → maintainer approval → published project with milestone(s) and task(s) → visitor contribution offer persisted (PRD J1, FR-01–FR-16, §16.1).
- Every public sourced claim links to an ingested source locator; unsupported questions yield explicit insufficient-evidence outcomes, never fabricated fixes (PRD §7.5, FR-12). Citation IDs in report JSON resolve to ingested evidence; unresolved citations fail validation.
- AI Revival Investigator, source provenance, feasibility assessment, and roadmap generation are demonstrably model-driven (multi-hypothesis feasibility, experiments with pass/fail criteria), not a README summary or generic chatbot (PRD §3.4, §7.3).
- Re-analysis works: a maintainer can run a new analysis on new evidence; each run is a new immutable report version; prior published versions stay readable; re-publish never duplicates milestones/tasks (PRD J2.5, §7.7).
- Slow AI work runs in async jobs with visible queued/ingesting/analyzing/review/published/failed states; publish is transactional, idempotent, and policy-checked (PRD §12.6, FR-09, FR-13).
- Live deployment is reproducible from Compose with persisted MariaDB, migrations, healthcheck, and secure defaults; public repo, disclosures, README reproduction steps, and 3-minute video are complete (PRD §16.1, §19.2).
- Long-term community features (catalog, discussions, hardware exchange, wishlist, funding, matching, governance, localization) and normalized findings/experiment-results tables remain feasible extensions but are not implemented (PRD §15, H-17–H-24).

## Approach

### Refinement decisions (approved, implemented by this revision)

1. Simplified persistence: H0 normalized tables are `users`, `devices`, `device_variants` (minimal), `projects`, `project_devices`, `project_members`, `repositories`, `evidence_sources`, `analysis_runs`, `analysis_reports` (versioned `payload_json` + `schema_version`), `milestones`, `contributor_tasks`, `contribution_offers`, `audit_events`. No `findings`, `finding_evidence`, `revival_paths`, or `experiments` tables in H0; those concepts live in validated report JSON, and publish derives `milestones`/`contributor_tasks` only.
2. Versioning: per-project sequential report versions with immutable payloads; `analysis_runs.status` owns the pipeline state machine, `analysis_reports.review_status` owns human review; report history view keeps prior published versions readable. Cross-version task identity is independent of the report version: the idempotency key is the per-project stable task identity from JSON (never the report version), so publishing v2 matches v1 tasks and preserves IDs, assignments, and contribution history; report-version provenance is stored separately on the row.
3. Deadline-first: WP0 = reliable local Laravel/Docker dev environment (boot, migrate, green tests). No prod-grade platform work, complex CI/CD, or speculative abstraction in WP0; production hardening is WP5. Linear WP order for a solo sprint; H0 gate, cut ladder, 1 Nov feature freeze.

### PRD review findings (inconsistencies, gaps, risks)

Resolvable without a PRD rewrite; items marked (C1–C5) have formal correction proposals in §PRD Corrections:

1. PRD path mismatch: §12.4 layout lists `docs/PRD.md`; workspace has only `docs/Rev2Square_PRD_v0.3_Hackathon_Edition.md`. No `README.md`, `HACKATHON.md`, `CASE_STUDIES.md`, or Laravel/Compose files exist yet (`AGENTS.md` and this plan exist); workspace is not a git repo.
2. Dual status fields: pipeline state machine (§12.6) and `analysis_reports.review_status` (§11.2) overlap. Resolution: runs own pipeline state, reports own review state; WP3 records the transition map in `README.md`.
3. (C1–C3) Normalized AI tables: §11.2 lists `findings`, `finding_evidence`, `revival_paths`, `experiments` as "Core H0". Superseded by refinement 1: deferred to post-hackathon; H0 reads them from report JSON.
4. Single-call vs staged invocation: §7.3 stages A–E without call count. Resolution: one bounded structured call for the hackathon, per-stage prompt sections + telemetry; stage boundaries kept for later.
5. Follows vs offers: H-09 lists "offer help / claim task / follow" but `follows` is H1/LT (§11.2). Resolution: H0 implements `contribution_offers` only.
6. Experiment results are H1 (J2, FR-20, §11.2): H0 shows experiment definitions from JSON; no `experiment_results` persistence.
7. Nginx + PHP-FPM asset serving (§12.3) under-specified for production. WP0 fixes the dev mechanism; WP5 fixes production (no bind mount).
8. Scheduler form undecided ("`schedule:work` or minute runner", §12.3): WP0 picks exactly one for dev; WP5 enforces a single scheduler in production.
9. (C4) WP0 scope: §17.1 WP0 "CI/test setup" vs deadline-first scoping. Resolution: WP0 = local test setup + green suite; hosted CI deferred to minimal/optional in WP5.

Missing dependencies (per PRD §22.2, no versions assumed): compatible PHP/Laravel/Livewire/Flux/Node/MariaDB versions + lockfiles; auth starter; test framework (Pest or PHPUnit) + MariaDB integration strategy; Meta model identifier, structured-output mechanism, credentials, billing; GitHub allowlisted doc paths + token strategy; license, public repo URL, domain/VPS, TLS, demo-account policy; Bublcam/Squeezebox image rights. The Bublcam URL (PRD §9.1) is verified at build time; this plan assumes nothing about its contents.

Top risks (PRD §21): late Meta credits (provider abstraction + test fakes; live gate in WP3); solo-sprint scope (H0 gate, cut ladder, 1 Nov freeze); hallucination (manifest, citation validation, classification, mandatory review); basic-RAG perception (verdicts, hypotheses, experiments); provider outage at judging (async jobs, persisted runs, labeled fallback per §17.2.6).

### Proposed architecture (Laravel + Livewire + MariaDB + Docker Compose)

Modular monolith per PRD §12 (no microservices, Redis, vector DB, SPA backend):

- App: pinned Laravel + PHP-FPM; Blade + Livewire + Alpine.js + Tailwind CSS 4 + Flux UI; Vite build (dev HMR via `node`, compiled assets in prod image); layout per PRD §12.4.
- Data: MariaDB for everything incl. database queue; Eloquent + migrations; versioned AI JSON in `analysis_reports`, normalized collaboration tables only (§Refinement 1); UTC storage; unique slugs, canonical repo URLs, idempotent task keys.
- Web/jobs: Nginx + `app`, `queue` worker, single `scheduler` from one pinned image; `db` on persistent volume; `compose.dev.yaml` in WP0, `compose.prod.yaml` hardened in WP5; healthcheck path fixed in WP0.
- Services (PRD §12.5, implemented thinly): `GitHubRepositoryImporter` → `EvidenceManifestBuilder` → queued `RevivalAnalysisService` + `MetaAIProvider` (`AIProviderInterface`) → `FeasibilityEvaluator`/`ExperimentPlanner` (JSON validation, citation resolution) → `ProjectPublisher` (transactional, idempotent, version-aware) → `ContributionService`; `AnalysisRunRepository` owns the §12.6 state machine.
- AI contract: `schema_version` per §7.4; verdicts `PROMISING`/`UNCERTAIN`/`HIGH_RISK`/`INSUFFICIENT_EVIDENCE`; claim statuses `SUPPORTED`/`INFERRED`/`UNVERIFIED`/`CONTRADICTED`/`UNKNOWN`; malformed output quarantined, never published; repo content untrusted (injection defenses); mock provider for tests only; demo uses real inference (§7.6).

### Smallest working vertical slice (H0 only)

J1 on one device case (Bublcam §9.1; Squeezebox seeding is H1): login → Analyze wizard → bounded import + manifest → queued Meta analysis → structured JSON report (findings, unknowns, paths + verdicts, experiments, risks, skills) → edit/approve/reject → publish (milestones + tasks, idempotent) → public project + report history → visitor offer → maintainer review → optional re-analysis producing v2 without duplicating tasks. Supporting H0: home/explore/project/about pages, dashboard, auth + policies, jobs + telemetry, Docker deploy, tests/docs. Excluded: Q&A assistant, photo input, discussions, updates, second AI analysis, experiment-result persistence, catalog, hardware exchange, wishlist, funding, matching, governance, localization, normalized findings tables.

Extensibility: normalized findings/`experiment_results`/community tables attach later by `project_id`/`report_id`; `schema_version` allows JSON evolution; `AIProviderInterface` keeps extra Meta capabilities optional.

### PRD Corrections (proposed; PRD untouched)

- C1: Move `findings`, `finding_evidence`, `revival_paths` from §11.2 "Core H0" to H1/LT (post-hackathon read-model tables keyed by `report_id`); H0 reads these concepts from `analysis_reports.payload_json`.
- C2: Move `experiments` from §11.2 "Core H0" to LT; H0 experiment definitions live in report JSON and publish derives `milestones`/`contributor_tasks`.
- C3: Extend §11.2 `analysis_reports` with explicit versioning (`version_number` per-project sequential, `supersedes_report_id` nullable self-FK or documented equivalent) and state payload immutability vs `review_status` transitions.
- C4: Narrow §17.1 WP0 "CI/test setup" to local test setup + green suite; hosted CI becomes minimal/optional in WP5.
- C5 (standing, reported previously): fix §12.4 `docs/PRD.md` reference to the versioned filename or vice versa; clarify H-09 follow vs H1/LT `follows`; pick scheduler form; fix prod asset-serving mechanism. For C5 the plan records decisions in `README.md` instead of requiring PRD edits.

## Steps

Linear order WP0 → WP5 (solo sprint; each WP depends on all previous WPs unless noted). Each WP ends with plan → implementation → tests → diff review → concise summary with pasted command output. Conventions: `dc` = `docker compose -f compose.dev.yaml` (service `app` per PRD §12.3; WP0 confirms exact flags in `README.md`); `artisan` = `dc exec app php artisan`; suite names below are deliverables the WP creates (proposed, not yet executed).

- WP0 — Bootstrap (local dev environment). Dependencies: approval only. Deliverables: pinned PHP/Laravel/Livewire/Flux/Node/MariaDB versions + lockfiles + README version table; Laravel app; `docker/php`, `docker/nginx`, `compose.dev.yaml`; MariaDB volume; queue worker + single scheduler (dev); Vite/Tailwind/Flux shell; auth + base policies; healthcheck endpoint (path fixed here); `.env.example`; `README.md`, `HACKATHON.md`, `CASE_STUDIES.md`; test framework + MariaDB integration setup; `tests/Feature/BootstrapSmokeTest.php`. Acceptance: clean `up --build` from scratch; `migrate:fresh --seed` succeeds; full suite green; healthcheck 200; no secrets in repo; license decision recorded. Test commands: `dc up -d --build`; `artisan migrate:fresh --seed`; `artisan test`; `artisan test --filter=BootstrapSmokeTest`; `curl -f http://localhost:8080/<healthcheck-path>`.
- WP1 — Project UI (deliberately lean: minimal styling, no secondary-UI polish; priority is reaching WP3 live analysis fast). Dependencies: WP0. Deliverables: migrations/models/factories/policies for `devices`, `device_variants`, `projects`, `project_devices`, `project_members`, `milestones`, `contributor_tasks`; landing, explore (simple DB search/filter), project detail (incl. roadmap/tasks sections fed by seed data), dashboard, auth pages, about/methodology; responsive + keyboard-accessible; `tests/Feature/ProjectCrudTest.php`, `tests/Feature/ProjectPolicyTest.php`. Acceptance: anonymous browsing of seeded showcase; owner CRUD + publish gating enforced, unauthorized edits denied (FR-01/02/11/16). Test commands: `artisan migrate:fresh --seed`; `artisan test`; `artisan test --filter=ProjectCrudTest`; `artisan test --filter=ProjectPolicyTest`; manual desktop/mobile + keyboard pass recorded.
- WP2 — GitHub ingestion (deliberately lean: functional manifest UI only, no polish; priority is reaching WP3 live analysis fast). Dependencies: WP1. Deliverables: canonicalization/validation, allowlisted REST endpoints/paths (recorded in config + README), bounded fetch, `repositories` + `evidence_sources` tables, commit SHA/hashes/locators, ingestion-error records, source manifest UI, idempotent re-import; `tests/Unit/GitHubUrlTest.php`, `tests/Unit/EvidenceManifestTest.php`, `tests/Feature/GitHubImportTest.php` (HTTP-faked matrix). Acceptance: real Bublcam URL imports a small license-compliant set with manifest + timestamps + errors; SSRF/private/oversized/rate-limited cases rejected with clean diagnostics; re-import idempotent (FR-03/04). Test commands: `artisan test`; `artisan test --filter=GitHubImportTest`; `artisan test --filter=EvidenceManifestTest`; manual real-URL import once with manifest output pasted into the WP summary (not asserted in CI).
- WP3 — Meta AI analysis. Dependencies: WP2. Deliverables: `AIProviderInterface` + real `MetaAIProvider` (config/timeout/retry/budget/telemetry) + test-only mock; single structured call for stages A–E; `analysis_runs` + versioned `analysis_reports` tables; JSON schema + citation-ID validation, claim classification, verdict mapping, quarantine/retry; queued jobs with §12.6 states; run→report versioning (`version_number`, `supersedes_report_id`); per-user rate limits; transition map in README; `tests/Unit/ReportSchemaTest.php`, `tests/Feature/RevivalAnalysisTest.php` (mock matrix). Acceptance: mock matrix green (valid/invalid JSON, timeout, rate limit, injection README, contradictions, unknown source ID, empty repo → `INSUFFICIENT_EVIDENCE`); gated live Meta run persists a validated report (findings + unknowns + ≥1 verdict + experiments or explicit gaps) with telemetry, or records a clean failure state — WP4 does not start until one live run succeeds (FR-05/06/07/13/14, §7.7). Test commands: `artisan test`; `artisan test --filter=RevivalAnalysisTest`; `artisan test --filter=ReportSchemaTest`; `artisan queue:work --once` (failure/visibility check); live run via documented manual procedure with telemetry pasted into the WP summary.
- WP4 — Publish, versioning, contribution. Dependencies: WP3. Deliverables: report review UI (edit-metadata/reject/approve — never payload rewrite), report history view, transactional version-aware `ProjectPublisher` (upsert-by-key, re-run safe), experiment display from JSON with pass/fail + safety notes, `contribution_offers` + `audit_events` tables, offer submit/review UI; `tests/Feature/PublishWorkflowTest.php`, `tests/Feature/ContributionOfferTest.php`. Acceptance: draft→approval→publish→public→offer end to end (FR-08/09/10/12); unpublished drafts/sources invisible to visitors; non-owner publish denied; second published version keeps v1 readable and creates no duplicate milestones/tasks. Test commands: `artisan test`; `artisan test --filter=PublishWorkflowTest`; `artisan test --filter=ContributionOfferTest`.
- WP5 — Demo readiness. Dependencies: WP4. Deliverables: `compose.prod.yaml` + production hardening (TLS, secure env, single scheduler, rate limits, sanitization checks), persistent DB + tested backup/restore, Bublcam seed + provenance ledger (Squeezebox only if time), observability (structured logs, request IDs, failed-job + AI metadata), full test pass, visual polish, README/`HACKATHON.md`/`CASE_STUDIES.md`/demo docs, ≤3-minute video + backup recording, submission rehearsal. Acceptance: public site + repo + reproduction steps + video ready ≥48h before deadline; production smoke green; fallback is an accurately labeled real run (FR-15, §16.1, §19.2). Test commands: `artisan test` (full suite, dev); production smoke `curl -f` checks for home/project/health endpoints (URLs recorded); backup/restore drill with pasted output; PRD §20.2 adversarial checklist recorded.

Cut rule (PRD §4.2): if late, cut H1 items, discussions, extra onboarding, animation before H-04–H-08 or the demo; no large new feature after 1 Nov unless H0 is stable.

## Validation Plan

- Per-WP: full `artisan test` stays green plus the WP suite filters above; each WP summary pastes exact commands and output; failing/skipped tests block the WP.
- WP3 live gate is the highest-risk validation: structured-output round-trip (schema + citation resolution + quarantine) must succeed live before WP4.
- Pre-submission: PRD §20.2 adversarial checklist, jury self-review (§16.3), video/repo/site accessibility without special permissions, no secrets in public artifacts, `HACKATHON.md`/`CASE_STUDIES.md` complete.

## Risks / Open Questions

Q1 (WP0): canonical PRD path — versioned file vs `docs/PRD.md`. Non-blocking.
Q2 (WP0, resolved BY WP0): pinned versions + auth starter + test framework. Non-blocking for WP0 start.
Q3 (WP3): Meta model identifier + structured-output mechanism + credentials/billing. Blocks live WP3 gate only; mocked WP3 proceeds regardless. Confirm billing early.
Q4 (WP2): GitHub allowlisted doc paths + optional token strategy. Non-blocking (WP2 decides).
Q5 (WP0/WP5): license, public repo URL, domain/VPS, TLS, demo-account policy, image rights. License + repo URL needed before publication (blocks WP5); decide early, no code impact.
Q6 (WP0/WP5): scheduler form + asset-serving mechanism. Non-blocking (WP0 dev choice, WP5 prod choice).
Q7 (default no): real experiment-result submission in scope? Only if H0 stable before 1 Nov.

Blocking decisions: none block starting WP0 — only your approval. Downstream gates are Q3 (live WP3 validation) and Q5 license/repo/domain (WP5 publication).
