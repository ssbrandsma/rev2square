# Global Code Review Report — rev2square

Date: 2026-11-03. Scope: whole repository. Focus: duplicate files, duplicate functions and blocks, iteration debt, refactoring and cleanup, plus convention and risk findings that set cleanup priority.

## Implementation status (2026-11-03)

All P0, P1, and P2 items were applied the same day. P3 items were decided and left (see below). New maintained tests: team invite generic error, LIKE escaping (2 unit), draft assignment rejection, attachment filename/MIME trust, attachment lifecycle denial. Final suite: only the 5 pre-existing environment failures (sandbox/deploy/storage) remain; everything else green. New shared code passes Pint; a few pre-existing Pint findings in untouched lines were left alone.

Deviations from the letter of the suggestions, all deliberate:

- 1.4 gate: applied as an Archived/Paused-only `ProjectActionPolicy::evidenceDenial`, not `contributionDenial`. The latter requires publication, which would break manager uploads on drafts — inconsistent with observation and test-report creation, which are view-gated by design. Filename sanitizing and server-side MIME sniffing applied as suggested.
- 2.8 evidence lists: the variants query now uses the shared one-query helper, but the observation/test-report lists stay unbounded. The show blade computes aggregates over the full collections, and no evidence index route exists to link a capped list to — proper pagination is a feature, not a refactor.
- 2.9 inbox: visibility now resolves through one `visibleTo` scope query instead of per-row policy checks, but filtering still happens after paginate. Scoping JSON-column visibility before paginate would need the unbounded id list the fix removes; rare page shrink on unpublished projects is accepted as before.
- 3.6 `_card`: no change needed. Its only caller (the Livewire Explorer) already eager-loads cover, tasks, and devices, and the view guards with `relationLoaded` — verified zero queries. The `_form-details` query was extracted as suggested.
- 3.8: added a separate `download_host` config (default `github.com`) instead of injecting the configured API host. The configured host is `api.github.com`, whose URL shape does not serve archives — injecting it would have broken downloads.
- 3.12 root database: moved, not deleted, to `storage/app/stray-local-rev2square.sqlite` (real dev data, gitignored there). Everything else pruned as suggested.
- 3.13: one-line clarifying comments added to both migrations instead of a rename, so deployed history is untouched.
- P3: 4.1 left (no test coverage on the probes, so extraction would be unverified churn), 4.2 left (upload actions differ behaviorally — dedup and reuse reporting), 4.3 left (trivial one-liners; a base class would add indirection for nothing).
- 1.2/1.3: implemented as a `BelongsToProject` trait used by the base controller (57 call sites converted, 3 private methods removed), rather than scoped route binding, which would have required renaming relations to match route parameters.
- Bonus fixes while standardizing: `ReviewController::ensureBelongs` folded into the shared assertion, `EvidenceController::showSource` converted from an if-form guard, all 27 `request()` helper uses in controllers converted to injected `Request`, and the LIKE helper also applied to the admin search screens and agent evidence search.

Per-item code pointers: 1.1 [TaskWorkflow.php](/home/sjoerd/projects/rev2square/app/Services/Community/TaskWorkflow.php), 1.2/1.3 [Concerns/BelongsToProject.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/Concerns/BelongsToProject.php), 1.4 [AttachmentController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/AttachmentController.php) + `evidenceDenial`, 1.5 [TeamController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TeamController.php), 2.1/2.8-variants [Concerns/ManagesEvidence.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/Concerns/ManagesEvidence.php), 2.2 [DiscussionController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/DiscussionController.php), 2.3 [SeedsProjects.php](/home/sjoerd/projects/rev2square/tests/Support/SeedsProjects.php), 2.4 [AnalyzeBinaryJob.php](/home/sjoerd/projects/rev2square/app/Jobs/AnalyzeBinaryJob.php), 2.5 [GitHubRepositoryImporter.php](/home/sjoerd/projects/rev2square/app/Services/Revival/GitHubRepositoryImporter.php) + [ImportProgress.php](/home/sjoerd/projects/rev2square/app/Services/Revival/ImportProgress.php), 2.6 [AgentTurnRunner.php](/home/sjoerd/projects/rev2square/app/Services/Agent/AgentTurnRunner.php) + [TurnTracking.php](/home/sjoerd/projects/rev2square/app/Services/Agent/TurnTracking.php), 2.7 [ModerationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ModerationController.php), 2.9 `Project::visibleTo` + [TaskController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TaskController.php), 2.10 [StoreOfferRequest.php](/home/sjoerd/projects/rev2square/app/Http/Requests/StoreOfferRequest.php) + [OfferEligibility.php](/home/sjoerd/projects/rev2square/app/Services/Community/OfferEligibility.php), 2.11 [ArtifactUploadService.php](/home/sjoerd/projects/rev2square/app/Services/Artifacts/ArtifactUploadService.php), 2.12 CLAUDE.md deleted, 2.13 [Like.php](/home/sjoerd/projects/rev2square/app/Support/Like.php), 2.14 `loadMissing`, 3.1 [RateLimit.php](/home/sjoerd/projects/rev2square/app/Support/RateLimit.php), 3.2 [UniqueTitle.php](/home/sjoerd/projects/rev2square/app/Support/UniqueTitle.php), 3.3 [DatabaseNotification.php](/home/sjoerd/projects/rev2square/app/Notifications/DatabaseNotification.php), 3.4 [UnreadCountComposer.php](/home/sjoerd/projects/rev2square/app/View/Composers/UnreadCountComposer.php), 3.5 [AgentTurnService.php](/home/sjoerd/projects/rev2square/app/Services/Agent/AgentTurnService.php) + [HomeController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/HomeController.php), 3.6 edit flow, 3.7 [ai.php](/home/sjoerd/projects/rev2square/config/ai.php), 3.8 `download_host`, 3.9 `$fillable`, 3.10 injected requests, 3.11 left (one-off QA scripts), 3.12/3.13/3.14 done.

## 0. How this review was done

Four parallel review passes covered complementary surfaces: file inventory and duplicate or dead files; duplicate functions and copy-pasted blocks; refactoring and cleanup debt; conventions and risk. A critic pass then checked for grep-only claims and omitted directories. One follow-up pass resolved the tests and migrations gap. A synthesis pass deduplicated everything. The parent session then re-verified the top claims directly against the code before writing this report.

Parent-verified (re-checked, not taken on trust): [AGENTS.md](/home/sjoerd/projects/rev2square/AGENTS.md) vs [CLAUDE.md](/home/sjoerd/projects/rev2square/CLAUDE.md) byte-identical; the 46-site `abort_unless` guard cluster across 14 controllers; `authorizeArtifact` byte-identical pair plus the `authorizeMedia` and `authorizeConversation` family; `rateLimitDelay` byte-identical pair; `variants()` exact pair and `canEdit()` near pair; `LOWER(title)` duplicate-check pair; `via()` identical in 14 of 14 notifications; `seedProject()` in 10 Feature test files; [ModerationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ModerationController.php) lines 52-53 unbounded pluck; [TeamController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TeamController.php) lines 62-63 enumeration message; [HexRecords.php](/home/sjoerd/projects/rev2square/app/Services/Binary/HexRecords.php) lines 18-55 probe scaffolding; blade query sites in [app.blade.php](/home/sjoerd/projects/rev2square/resources/views/layouts/app.blade.php), [inbox/index.blade.php](/home/sjoerd/projects/rev2square/resources/views/inbox/index.blade.php), [_form-details.blade.php](/home/sjoerd/projects/rev2square/resources/views/projects/_form-details.blade.php), [_card.blade.php](/home/sjoerd/projects/rev2square/resources/views/projects/_card.blade.php); the [User.php](/home/sjoerd/projects/rev2square/app/Models/User.php) commented import; the [SandboxedBackend.php](/home/sjoerd/projects/rev2square/app/Services/Binary/Adapters/SandboxedBackend.php) unused import; the zero-byte [favicon.ico](/home/sjoerd/projects/rev2square/public/favicon.ico); the [ExampleTest.php](/home/sjoerd/projects/rev2square/tests/Unit/ExampleTest.php) placeholder.

Repo scale at review time: 120 blades, 125 files under [app/Services](/home/sjoerd/projects/rev2square/app/Services), 40 models, 31 root plus 8 Admin plus 9 Auth controllers, 14 notifications, 18 enums, 8 jobs, about 60 Feature plus 30 Unit tests, 40 migrations.

Known limits of this review: no whole-tree content-similarity hashing, so a copy-paste pair with different basenames could remain. Binary and screenshot bytes were never compared. The [routes](/home/sjoerd/projects/rev2square/routes) bodies were never fully opened. Full bodies beyond heads were not compared for the Revival selector trio, the QA screenshots cluster, notification payload builders, and the JS chat modules. Section 7 lists every gap.

Priority scheme: P0 means security hole or the single largest duplication cluster. P1 means exact or near duplication, real perf cost, or a large method. P2 means smaller duplication or a convention fix. P3 means borderline or trivial. Effort: XS is minutes, S is under an hour, M is a few hours.

## 1. P0 — Do first

### 1.1 Task assignment leaks project info to any user id

[TaskController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TaskController.php) lines 129-140 validates the assignee with only `exists:users,id`. [TaskWorkflow.php](/home/sjoerd/projects/rev2square/app/Services/Community/TaskWorkflow.php) lines 140-152 checks the actor, never the assignee. Any user id can be assigned on a draft project and then gets notified. Fix: restrict the assignee to users with view access to the project, or to the existing candidate set. This is the highest-risk functional finding.

### 1.2 Project-scoping guard repeated at 46 sites in 14 controllers

The line `abort_unless($x->project_id === $project->id, 404)` appears 46 times. This is the largest duplication cluster in the codebase. One fix removes all of it: scoped route binding for nested project resources, or a single shared helper such as `BelongsToProject::assert($project, $model)`.

Full site list, verified by search:

- [OfferController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/OfferController.php): 154, 175, 204, 260, 296, 313.
- [DiscussionController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/DiscussionController.php): 174, 220, 229, 256, 287, 322, 337.
- [ObservationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ObservationController.php): 38, 52, 75, 100, 119.
- [TaskController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TaskController.php): 22, 85, 97, 113, 132.
- [TestReportController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TestReportController.php): 22, 62, 76, 99.
- [MilestoneController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/MilestoneController.php): 47, 58, 77, 90.
- [TeamController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TeamController.php): 98, 128, 140, 167.
- [AttachmentController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/AttachmentController.php): 32, 39.
- [PromotionController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/PromotionController.php): 20, 35.
- [FollowController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/FollowController.php): 33, 43.
- [ModerationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ModerationController.php): 21, 28.
- [ReportController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ReportController.php): 15.
- [ChatController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ChatController.php): 105.
- [ReviewController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ReviewController.php): 84.

### 1.3 `authorizeArtifact` is byte-identical in two controllers

[ArtifactController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ArtifactController.php) lines 259-264 and [BinaryAnalysisController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/BinaryAnalysisController.php) lines 373-378 define the identical private method. The same guard shape repeats in [MediaController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/MediaController.php) lines 331-336 as `authorizeMedia`, and partly in [AgentController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/AgentController.php) lines 271-280 as `authorizeConversation` with an added 403 accessibility check. Fix: extract one shared `BelongsToProject` concern or trait. The 403 check in `authorizeConversation` stays as its own step. This overlaps with 1.2; one trait can serve both.

### 1.4 Attachment uploads trust client-supplied names and use a weak gate

[AttachmentController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/AttachmentController.php) lines 98-99 and 49 store `original_name` and mime from client values (`getClientOriginalName`, `getClientMimeType`) and reuse `original_name` as the download filename. Fix: basename-sanitize, cap the length, and store a server-sniffed mime. Separately, line 86 gates uploads on the `view` ability only, with no discussion-open, activity, or denial check, unlike chat and discussion. Fix: apply the `ProjectActionPolicy` denial check. Risk: uploads on archived or closed projects.

### 1.5 Team invite errors let attackers enumerate registered emails

[TeamController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TeamController.php) lines 62-63 return a distinct 422 message, `No registered account uses that email address`, when the email has no account. An attacker can probe which emails are registered. Fix: return a generic message identical to the other invite-failure messages. Effort XS.

## 2. P1 — High value

### 2.1 Observation and TestReport controllers share three near-identical members

- `variants()` is exact-duplicate: [ObservationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ObservationController.php) lines 196-201 vs [TestReportController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TestReportController.php) lines 170-175. Both return `DeviceVariant::with('device')->whereIn('device_id', $project->devices()->pluck('devices.id'))->get()`.
- `canEdit()` is near-duplicate: lines 203-206 vs 177-180. Same logic (`$x->user_id === $user?->id || $project->managedBy($user)`), differing only in parameter and model names.
- `validatedData()` blocks are near-duplicate: lines 153-186 vs 127-167. They share the task-ownership abort block verbatim and the variant-ownership abort (inlined in one, via a `variantBelongsToProject` helper in the other), plus the same title, body, and rules handling.

Fix: extract a shared concern or form-request pair for evidence controllers. Effort S, impact M.

### 2.2 DiscussionController notify logic is duplicated and runs N+1 queries

In [DiscussionController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/DiscussionController.php), the `notifyNewThread` Update branch at lines 369-378 and the non-Update branch at lines 380-385 are near-identical follower-fetch, reject, and notify blocks. They differ only in the preference key and notification class. The `$project->follows()->with('user')->get()->pluck('user')` chain repeats 4 times in the file (lines 355, 370, 380, 400). Perf cost on top: lines 354-360 run a `DiscussionMute::where()->exists()` per recipient inside the reject loop, lines 370, 380, and 400 reload the follower set up to 3 times per post, and lines 392-400 load the latest 100 posts with authors for mentions.

Fix: merge the two branches with the preference key and notification class as parameters, do one `whereIn(user_id, ids)` mute lookup before the loop, and load the follower and user set once to share across all `notify*()` calls. Effort S, impact M.

### 2.3 Test helpers `seedProject` and `storeBytes` are copy-pasted across Feature tests

`seedProject()` is byte-identical in at least six files: [AnalysisOutputTest.php](/home/sjoerd/projects/rev2square/tests/Feature/AnalysisOutputTest.php) lines 27-33, [ArtifactStorageSharingTest.php](/home/sjoerd/projects/rev2square/tests/Feature/ArtifactStorageSharingTest.php) lines 22-28, [AnalysisPresentationTest.php](/home/sjoerd/projects/rev2square/tests/Feature/AnalysisPresentationTest.php) lines 30-36, [BinaryAnalysisTest.php](/home/sjoerd/projects/rev2square/tests/Feature/BinaryAnalysisTest.php) lines 36-42, [BinaryTargetMatrixTest.php](/home/sjoerd/projects/rev2square/tests/Feature/BinaryTargetMatrixTest.php) lines 30-36, [ArtifactInvestigationTest.php](/home/sjoerd/projects/rev2square/tests/Feature/ArtifactInvestigationTest.php) lines 25-31. Four more files carry signature variants: [ProjectPresentationTest.php](/home/sjoerd/projects/rev2square/tests/Feature/ProjectPresentationTest.php) line 55 (nullable-owner variant), [RevivalAgentTest.php](/home/sjoerd/projects/rev2square/tests/Feature/RevivalAgentTest.php) line 28, [ArchitectureTest.php](/home/sjoerd/projects/rev2square/tests/Feature/ArchitectureTest.php) line 28, and [EvidenceWorkflowTest.php](/home/sjoerd/projects/rev2square/tests/Feature/EvidenceWorkflowTest.php) line 29 (evidence and repository variants).

`storeBytes()` is near-identical in [AnalysisOutputTest.php](/home/sjoerd/projects/rev2square/tests/Feature/AnalysisOutputTest.php) lines 35-51, [BinaryAnalysisTest.php](/home/sjoerd/projects/rev2square/tests/Feature/BinaryAnalysisTest.php) lines 44-62, [BinaryTargetMatrixTest.php](/home/sjoerd/projects/rev2square/tests/Feature/BinaryTargetMatrixTest.php) lines 38-57, and [AnalysisPresentationTest.php](/home/sjoerd/projects/rev2square/tests/Feature/AnalysisPresentationTest.php) line 38, differing only in comments.

Fix: extract one flexible helper into a shared `Tests\Support` trait or onto [TestCase.php](/home/sjoerd/projects/rev2square/tests/TestCase.php), with optional parameters covering the owner, evidence, repository, and format variants. Effort S, impact M. Every new Feature test currently copies this block again, so this pays off immediately.

### 2.4 `AnalyzeBinaryJob::handle` is 180 lines with a duplicated validation block

[AnalyzeBinaryJob.php](/home/sjoerd/projects/rev2square/app/Jobs/AnalyzeBinaryJob.php) lines 64-242 span about 180 lines across 6 or more stages. Inside, lines 146-159 (SandboxedBackend) and 160-173 (ExternalBackend) are near-identical probe-plus-version re-validation blocks. Fix: extract a shared `validateWorker($adapter, $analysis)` helper and split the stages into private methods. Effort M, impact H.

### 2.5 `GitHubRepositoryImporter::import` is 280 lines mixing four stages

[GitHubRepositoryImporter.php](/home/sjoerd/projects/rev2square/app/Services/Revival/GitHubRepositoryImporter.php) lines 54-333 span about 280 lines. One method mixes fetching, the up-to-date check, inventory building, and persistence. Fix: extract private stages such as `syncMeta`, `upToDateResponse`, `buildInventory`, and `persistEvidence`. This is the largest method found. Effort M, impact H.

### 2.6 `AgentTurnRunner::run` is 190 lines with four early-return branches

[AgentTurnRunner.php](/home/sjoerd/projects/rev2square/app/Services/Agent/AgentTurnRunner.php) lines 43-233 span about 190 lines. One method runs the provider and tool-call loop with four early-return `partial()` branches. Fix: extract loop-step and guard helpers such as `checkBudgets`, `callProvider`, and `handleToolCalls`. Effort M, impact H.

### 2.7 Moderation index loads every id into PHP

[ModerationController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ModerationController.php) lines 52-53 pluck all discussion-post and chat-message ids into PHP, then feed them to `whereIn`. Unbounded memory plus a giant `IN` list on large projects. Fix: scope reports with a `whereHas` or morph query, or a join, instead of id lists. Effort S, impact M.

### 2.8 Project page loads unbounded evidence lists with heavy eager loads

[ProjectController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ProjectController.php) lines 57-58 call `->get()` unbounded on observations and test reports with heavy eager loads on every project view. Line 62 adds an extra query with `DeviceVariant::whereIn(pluck())`. Fix: paginate or cap the evidence lists, and use `whereHas` instead of pluck. Effort S, impact M.

### 2.9 Candidate and visibility checks hydrate full collections in loops

[TaskController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TaskController.php) line 36 hydrates every offer row with `$project->offers()->with('user')->get()->pluck('user')` just to build a candidate list. Fix: a distinct-user query such as `User::whereHas` on offers for the project. Separately, [Project.php](/home/sjoerd/projects/rev2square/app/Models/Project.php) lines 278-292 run an `exists()` query per `can('view')` or `managedBy()` call, invoked in loops from [DashboardController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/DashboardController.php) line 40 and [InboxController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/InboxController.php) lines 24-25. Inbox and dashboard also filter after `paginate` or `get`, so pages shrink. Fix: preload one membership map per request and scope visibility before paginating. Effort S, impact M.

### 2.10 `OfferController::store` is 93 lines of inline eligibility logic

[OfferController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/OfferController.php) lines 49-141 mix claim eligibility, maintainer eligibility, and duplicate checks inline. Fix: move rules to FormRequest classes and checks to an `OfferEligibility` service. Effort S, impact M.

### 2.11 `ArtifactController::store` is 82 lines of batch-upload plumbing

[ArtifactController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ArtifactController.php) lines 44-125 mix `post_max_size` detection, a per-file validation loop, and message assembly. Fix: extract batch-upload handling to an `ArtifactUploadService`. Effort S, impact M.

### 2.12 AGENTS.md and CLAUDE.md are byte-identical

[AGENTS.md](/home/sjoerd/projects/rev2square/AGENTS.md) and [CLAUDE.md](/home/sjoerd/projects/rev2square/CLAUDE.md) are both 47 lines including the `<laravel-boost-guidelines>` bootstrap, and `cmp` reports them identical. Fix: delete one or replace one with a pointer. Effort XS.

### 2.13 LIKE search interpolates user input with unescaped wildcards

[Explorer.php](/home/sjoerd/projects/rev2square/app/Livewire/Projects/Explorer.php) lines 94-97 and [TaskDirectory.php](/home/sjoerd/projects/rev2square/app/Livewire/Projects/TaskDirectory.php) lines 122-124 wrap user input in `%..%` without escaping, so `%` and `_` in input act as wildcards. Parameters are bound, so there is no injection. [TaskDirectory.php](/home/sjoerd/projects/rev2square/app/Livewire/Projects/TaskDirectory.php) line 127 already escapes the skill filter, so behavior is inconsistent. Fix: extract one shared escape helper and apply it everywhere. Effort XS.

### 2.14 Offer accept lazy-loads the user inside a locked transaction

[OfferController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/OfferController.php) line 341 lazy-loads `$offer->user` inside the `lockForUpdate` transaction, holding the row lock during an extra query. Fix: call `$offer->loadMissing('user')` before opening the transaction. Effort XS.

## 3. P2 — Medium

### 3.1 `rateLimitDelay` is byte-identical in two jobs

[ImportRepositoryJob.php](/home/sjoerd/projects/rev2square/app/Jobs/ImportRepositoryJob.php) lines 81-88 and [AnalyzeProjectJob.php](/home/sjoerd/projects/rev2square/app/Jobs/AnalyzeProjectJob.php) lines 79-86 define the identical static method: null returns 300, else `min(max($resetsAt - time() + 5, 60), 3600)`. Fix: move it to one shared helper, for example on a base job class or a rate-limit support class. Effort XS.

### 3.2 Task and Milestone duplicate-title checks share one block

[TaskController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/TaskController.php) lines 168-175 and [MilestoneController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/MilestoneController.php) lines 116-123 share the case-insensitive `LOWER(title)` check plus the `when($ignore)` block. They differ only in the relation and the message. Fix: extract a shared rule or helper taking the relation and message. Effort XS.

### 3.3 All 14 notifications repeat the same `via()` body

Every class under [app/Notifications](/home/sjoerd/projects/rev2square/app/Notifications) defines the identical 3-line `via()` returning `['database']`. Verified in all 14 files. Fix: add a small base notification class or trait. Note: the `toDatabase` and `toArray` payload builders were not compared, so a larger notification duplication cluster may still hide there. See gap 7.4. Effort XS.

### 3.4 Two blades run the same unread-count query

[app.blade.php](/home/sjoerd/projects/rev2square/resources/views/layouts/app.blade.php) line 44 and [inbox/index.blade.php](/home/sjoerd/projects/rev2square/resources/views/inbox/index.blade.php) line 4 both run `auth()->user()->unreadNotifications()->count()` in the view. Fix: compute it once in a shared view composer or layout data. Effort XS.

### 3.5 Agent runner wiring sits in the controller, home query sits in a route closure

[AgentController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/AgentController.php) line 297 `runTurn()` spans about 73 lines wiring `ToolContext`, tool definitions, and `AgentTurnRunner` inside the controller. [web.php](/home/sjoerd/projects/rev2square/routes/web.php) lines 46-50 run a `Project::featurable()` query in a route closure. Fix: move the runner wiring to a service and the home query to a controller or invokable. Effort S, impact M.

### 3.6 Two project blades query the database during render

[_form-details.blade.php](/home/sjoerd/projects/rev2square/resources/views/projects/_form-details.blade.php) line 33 runs `DeviceVariant::find()` in the view. Fix: pass `$linkedVariant` from the create and edit controller actions. [_card.blade.php](/home/sjoerd/projects/rev2square/resources/views/projects/_card.blade.php) line 4 falls back to `$project->cover()->first()` when the relation is not loaded, which is N+1 per card on listing pages, and line 33 filters tasks inline. The `relationLoaded` guard is already there, so the view is defensive. Fix: eager-load cover and tasks in the listing controllers. Effort S, impact M.

### 3.7 Admin setting allowlist is hardcoded and the audit reason is unvalidated

[SettingController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/Admin/SettingController.php) line 72 hardcodes the supported-model allowlist (`['muse-spark-1.3']`) in the controller. Line 79 passes `$request->string('reason')` to the audit log without validation. Fix: move the list to [ai.php](/home/sjoerd/projects/rev2square/config/ai.php) and validate the reason as `nullable|max:2000`. Effort XS.

### 3.8 Archive downloader hardcodes the GitHub host

[ArchiveDownloader.php](/home/sjoerd/projects/rev2square/app/Services/GitHub/ArchiveDownloader.php) lines 68 and 141 hardcode `https://github.com` for archive and download URLs, while `GitHubClient` supports a configured host. Fix: inject the configured host for consistency and GitHub Enterprise support. Effort XS.

### 3.9 `Project::$fillable` includes slug and owner_id

[Project.php](/home/sjoerd/projects/rev2square/app/Models/Project.php) lines 25-35 list `slug` and `owner_id` as fillable. This is safe today because the FormRequests exclude them and [ProjectController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ProjectController.php) lines 81-82 set them explicitly. But any future `fill($validated)` reuse becomes a mass-assignment risk. Fix: drop both from `$fillable` and keep the explicit assignment. Effort XS.

### 3.10 Request shapes are inconsistent across controllers

[DiscussionController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/DiscussionController.php) lines 327 and 340 (`mute`, `unmute`) use the `request()` helper, while the sibling `toggle()` at line 304 injects `Request $request`. [ProjectController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/ProjectController.php) line 78 uses a `StoreProjectRequest` FormRequest, while about 63 other sites use inline `$request->validate`. Fix: standardize on injected `Request` or FormRequest parameters. The 63 count is grep-based; per-site bodies were not opened. Effort S.

### 3.11 QA screenshot scripts repeat the same login boilerplate

Eight one-off scripts repeat the login sequence (`goto /login`, fill email and password, click submit, `waitForURL` dashboard): [shots-a1](/home/sjoerd/projects/rev2square/muse/results/shots-a1.cjs), [shots-a2](/home/sjoerd/projects/rev2square/muse/results/shots-a2.cjs), [shots-a3](/home/sjoerd/projects/rev2square/muse/results/shots-a3.cjs), [shots-a4](/home/sjoerd/projects/rev2square/muse/results/shots-a4.cjs), [shots-final](/home/sjoerd/projects/rev2square/muse/results/shots-final.cjs), [shots-evidence-ready](/home/sjoerd/projects/rev2square/muse/shots-evidence-ready.cjs), [shots-home-before](/home/sjoerd/projects/rev2square/muse/shots-home-before.cjs), and [shots.cjs](/home/sjoerd/projects/rev2square/docs/e2e-20261027/shots.cjs). This is the largest copy-paste family by file count, but the scripts are one-off QA, so it is benign. Fix if touched again: extract one shared login helper. Only heads were read; duplication past the login block is unverified. Effort S.

### 3.12 Dead, stray, and leftover files to remove or relocate

- [favicon.ico](/home/sjoerd/projects/rev2square/public/favicon.ico) is 0 bytes, tracked, and referenced nowhere. Both layouts link only `favicon.svg`. Remove or regenerate it.
- [ExampleTest.php](/home/sjoerd/projects/rev2square/tests/Unit/ExampleTest.php) lines 12-15 assert only `assertTrue(true)`. Laravel scaffold leftover and dead weight. The Feature sibling at least smoke-tests `GET /`. Delete it.
- [rev2square](/home/sjoerd/projects/rev2square/rev2square) at the repo root is a 581632-byte SQLite database, untracked, with no `.gitignore` entry. Wrong place: [database.php](/home/sjoerd/projects/rev2square/config/database.php) line 38 defaults sqlite to `database/database.sqlite`, and [.env.example](/home/sjoerd/projects/rev2square/.env.example) lines 23-28 use MySQL with `rev2square` as the database name. Move it into `database/`, or delete it and ignore the name. Contents beyond the file header were not inspected.
- [public/hot](/home/sjoerd/projects/rev2square/public/hot) is a single-line `http://localhost:5173` Vite dev marker. Gitignored, so harmless, but it forces dev-server asset URLs if a server happens to run. Remove when not doing frontend dev.
- Local runtime leftovers, all gitignored and safe to prune: [.phpunit.result.cache](/home/sjoerd/projects/rev2square/.phpunit.result.cache) (113KB), `storage/app/a3-avatar.png` (177B QA leftover), `storage/app/artifacts` and `storage/app/private` runtime dirs.

Effort XS to S, risk low.

### 3.13 Two migration names imply tables that do not exist

[2026_10_27_000015_add_publish_provenance_table.php](/home/sjoerd/projects/rev2square/database/migrations/2026_10_27_000015_add_publish_provenance_table.php) lines 11-29 run only `Schema::table` alters on `contributor_tasks`, `milestones`, and `analysis_reports`. No `publish_provenance` table is created, so the trailing `_table` suffix misleads readers. [2026_10_27_000012_link_evidence_to_runs_table.php](/home/sjoerd/projects/rev2square/database/migrations/2026_10_27_000012_link_evidence_to_runs_table.php) lines 11-13 follow the same sloppy suffix pattern. Harmless, since Laravel never re-runs migrations, but confusing. Fix: leave the files alone and add a doc note, or rename if the project has no deployed history to preserve. The other 38 migrations were not opened. Effort XS.

### 3.14 Two one-line cleanups

- [SandboxedBackend.php](/home/sjoerd/projects/rev2square/app/Services/Binary/Adapters/SandboxedBackend.php) line 7 imports `EvidenceValidator`, and the name never occurs again in the file body. Validation happens in `AnalyzeBinaryJob`. Delete the import.
- [User.php](/home/sjoerd/projects/rev2square/app/Models/User.php) line 5 keeps a commented-out `use Illuminate\Contracts\Auth\MustVerifyEmail;` from scaffolding. It is the only commented-out code found in the whole tree. Delete it.

## 4. P3 — Borderline and trivial

Decide each one, then act or leave it. None of these is urgent.

### 4.1 HexRecords probes share scaffolding

[HexRecords.php](/home/sjoerd/projects/rev2square/app/Services/Binary/HexRecords.php) `probeIntelHex` at lines 18-35 and `probeSrec` at lines 38-55 share the identical loop and early-return scaffolding. They differ only in the regex (line 23 vs 43) and the detail string (line 34 vs 54). A parametric `probe($regex, $detail)` would remove it. But the two probes read clearly as-is, so leaving them is defensible. No extraction decision yet.

### 4.2 MediaController upload actions share scaffolding

[MediaController.php](/home/sjoerd/projects/rev2square/app/Http/Controllers/MediaController.php) `storeImage` at lines 26-83 and `storeFile` at lines 129-163 share the multi-file validate, loop, result-ok, error-aggregate, and `bumpVersion` scaffolding. `storeImage` adds dedup and reuse reporting. A parametric upload handler is possible but genuinely borderline. Decide when either action is next touched.

### 4.3 Adapter and job one-liners

`targets()` one-liners plus empty `candidateTargets()` repeat in [JadxAdapter.php](/home/sjoerd/projects/rev2square/app/Services/Binary/Adapters/JadxAdapter.php) lines 69-77, [IlspyAdapter.php](/home/sjoerd/projects/rev2square/app/Services/Binary/Adapters/IlspyAdapter.php) lines 36-44, and [ExtractAdapter.php](/home/sjoerd/projects/rev2square/app/Services/Binary/Adapters/ExtractAdapter.php) lines 59-67, with per-adapter values. Identical `backoff()` returning `[120, 600]` repeats in [AnalyzeArchitectureJob.php](/home/sjoerd/projects/rev2square/app/Jobs/AnalyzeArchitectureJob.php) lines 24-28 and [AnalyzeProjectJob.php](/home/sjoerd/projects/rev2square/app/Jobs/AnalyzeProjectJob.php) lines 25-29. Leave these, or fold them into a base class opportunistically. Trivial either way.

## 5. Checked and cleared — no action needed

These looked like duplication but are not, verified by opening bodies:

- Same-basename blades (`index` x16, `show` x12, `edit` x8, `create` x5, `_form` x4) are per-resource views with distinct bodies. Spot-checked project story form vs task form.
- `Admin/{ProjectController,ModerationController,DashboardController}` vs the top-level same names are distinct namespaces. All 47 controllers are referenced from routes by grep audit.
- The Auth trio ([VerifyEmailController](/home/sjoerd/projects/rev2square/app/Http/Controllers/Auth/VerifyEmailController.php), [EmailVerificationPromptController](/home/sjoerd/projects/rev2square/app/Http/Controllers/Auth/EmailVerificationPromptController.php), [EmailVerificationNotificationController](/home/sjoerd/projects/rev2square/app/Http/Controllers/Auth/EmailVerificationNotificationController.php)) is the standard Breeze split: verify link, show prompt, resend link.
- [AdminAuditEvent](/home/sjoerd/projects/rev2square/app/Models/AdminAuditEvent.php) (91 lines, admin-global) vs [AuditEvent](/home/sjoerd/projects/rev2square/app/Models/AuditEvent.php) (85 lines, project-scoped) have distinct scopes.
- [AppSetting](/home/sjoerd/projects/rev2square/app/Models/AppSetting.php) (37-line Eloquent row) vs [AppSettings](/home/sjoerd/projects/rev2square/app/Services/Admin/AppSettings.php) (289-line typed service) are a model plus service pair.
- [413.html](/home/sjoerd/projects/rev2square/public/413.html) (static nginx error page, wired in both nginx confs and asserted in `ArtifactUploadLimitsTest`) vs `errors/{403,404}.blade.php` (Laravel exception views) are complementary layers.
- Env and compose variants ([.env.example](/home/sjoerd/projects/rev2square/.env.example) 94 lines vs production 100 vs virtualmin 151; [compose.dev.yaml](/home/sjoerd/projects/rev2square/compose.dev.yaml) 250 vs prod 214 vs virtualmin 483) overlap by design as per-target config. Only headers were diffed, so this rests on partial reads.
- Docker pairs (nginx `default.conf` vs `default.prod.conf`, `entrypoint.sh` vs `entrypoint-prod.sh`, `Dockerfile` vs `Dockerfile.prod`) are dev and prod variants by design.
- `PrgParser` vs `PrgProbe`, `gen_dex_many.py` vs `gen_dex_smoke.py`, and the Artifacts vs Binary fixture READMEs are distinct-purpose lookalikes.
- Enum `label()`, `casts()`, parser `parse()`, decoder `decode()` share names by interface convention with distinct bodies. Spot-checked only.
- Repeated `ProjectController`, `ModerationController`, `DashboardController` class names are namespaced pairs, not redefinitions.

## 6. Verified clean

- No `TODO`, `FIXME`, `HACK`, `XXX`, `dd`, `dump`, or `var_dump` debt anywhere. Word-boundary grep over tests and migrations found zero real hits. Only benign matches: `dump.bin` and `dump.z80` fixture filenames, a `json.dump` comment, and a `decompilation:` assertion.
- No commented-out code except the [User.php](/home/sjoerd/projects/rev2square/app/Models/User.php) line 5 import.
- No backup files: find for `*.bak`, `*~`, `*.orig`, `*.rej`, `*-old*`, `*.old`, `*.copy`, `*.swp` returned zero hits outside `node_modules` and `vendor`.
- [TestCase.php](/home/sjoerd/projects/rev2square/tests/TestCase.php) is a clean 16-line base class. [BinaryTargetMatrixTest.php](/home/sjoerd/projects/rev2square/tests/Feature/BinaryTargetMatrixTest.php) is clean across 120 opened lines and 39 test methods.
- No dead JS by import audit: `app.js` imports 6 page modules by design, and the root modules (`echo`, `drafts`, `socket-config`, `channel-refresh`, `chat-render`, `chat-sync`, `presence`) are all imported by page scripts and covered by `tests/js` suites. Bodies were not opened, so overlap between `chat-sync`, `chat-render`, and `channel-refresh` is unchecked.
- Public SVG images are all referenced from blades. `robots.txt` is a 2-line allow-all.

## 7. Gaps — what this review did not cover

1. Revival selector trio: only the first 40 lines each of `FileSelector::select`, `EvidenceSelector::select`, and `EvidencePlanner::plan` were read. Distinct input stages so far, but full bodies were never compared for shared ranking or budget logic.
2. Routes audit: [web.php](/home/sjoerd/projects/rev2square/routes/web.php) (323 lines), [auth.php](/home/sjoerd/projects/rev2square/routes/auth.php) (59), [channels.php](/home/sjoerd/projects/rev2square/routes/channels.php) (73), [console.php](/home/sjoerd/projects/rev2square/routes/console.php) (13) were never fully opened. Duplicate route definitions, middleware coverage for attachment, chat, and realtime endpoints, and closure logic are unverified. The all-47-controllers-referenced claim is grep-only.
3. Quality-risk omitted dirs: only Controllers, Models, GitHub, and Livewire were covered. Jobs x8, Services under Binary, Revival, AI, and Admin, Notifications x14, Policies, Events, `database/`, `config/ai.php`, `config/artifacts.php`, `config/revival.php`, `bootstrap/` trust-proxy, and `storage/` were not reviewed. `MemberProfile::publicSections` and `workspaceSections` shapes were never opened. JS plus blade inline-script XSS surface was never reviewed.
4. Duplicates omitted dirs: blade bodies (120 files), Livewire, Services internals for AI, Revival, GitHub, and Community (name-frequency pass only), Models x40, Enums x18, Jobs x8 (only `rateLimitDelay` and `backoff` checked), Policies, Events, Support, Providers, factories x18, config x15, and `bootstrap/` bodies were never compared. Tests were covered only by the `seedProject` and `storeBytes` spot-check.
5. Grep-only counts: the 63 inline `$request->validate` sites and the 46 guard sites were counted by search. Per-site contexts were verified for the guard list by the parent, but extraction shape per site still needs a look during the fix.
6. Unresolved candidates: `ProjectController` presentation-version handling vs `MediaController::bumpVersion` (lines 338-341) was never verified. `storeImage` vs `storeFile` and the HexRecords probes are parked as borderline without a decision.
7. Binary bytes excluded: fixture files, `assignments/*.jpg`, `muse/results/*.png`, and `docs/e2e-20261027/*.png` were never byte-compared. The stray root `rev2square` SQLite contents were never inspected beyond the file header.
8. Migrations: only `000015` and `000012` were opened. The other 38 were not.

## 8. Suggested execution order

Phase A, XS wins first (under an hour total): 1.5 enumeration message, 2.12 doc pointer, 2.13 LIKE escaping, 2.14 `loadMissing`, 3.1 `rateLimitDelay`, 3.2 title check, 3.3 notification base, 3.4 view composer, 3.7 settings, 3.8 host injection, 3.9 `$fillable`, 3.12 dead files, 3.13 migration note, 3.14 one-liners.

Phase B, P0 security and the big guard (half a day): 1.1 assignee scoping, 1.4 attachment trust, then 1.2 plus 1.3 together as one `BelongsToProject` extraction across the 14 controllers.

Phase C, P1 duplication (a day): 2.1 evidence concern, 2.2 DiscussionController notify merge, 2.3 test helpers, 2.7 moderation query, 2.8 project page caps, 2.9 candidate and visibility queries, 2.10 and 2.11 store extractions, 3.5 runner wiring, 3.6 blade queries, 3.10 request shapes.

Phase D, large refactors (one each): 2.4 `AnalyzeBinaryJob`, 2.5 `GitHubRepositoryImporter`, 2.6 `AgentTurnRunner`.

Phase E, decisions and gaps: rule on section 4, then work through section 7, starting with the routes audit and the Revival trio.

Total: 5 P0 items, 14 P1 items, 14 P2 items, 3 P3 items, 8 named gaps. The codebase is in decent shape. No test suite rot, no debug leftovers, no backup clutter. The debt is concentrated: one 46-site guard cluster, a handful of copy-pasted helpers, three oversized methods, and a few unbounded queries.
