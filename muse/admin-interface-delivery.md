# Administrator interface + application role system — delivery

Complete admin interface and app-wide roles for Rev2Square: backend, UI,
authorization, revocation, secrets, usage accounting, budgets, audit log,
tests, and docs. Working tree holds earlier uncommitted sets (branding,
browser-QA, functional-retest, ELF/PCAP delivery + QA fixes) plus this
feature — all preserved, nothing committed, pushed, merged, or deployed.

No production changes. No credentials created. No real secrets read or
used (tests neutralize the dev `MODEL_API_KEY` env and use fakes). No
accounts promoted. No live provider calls. No installs.

## 1. Role/permission matrix (implemented)

Global roles (`App\Enums\GlobalRole`): `user` (default), `moderator`,
`admin`. Project roles (Owner/Maintainer/Contributor via `ProjectPolicy`)
are untouched and grant zero global powers.

| Ability | Moderator | Admin |
|---|---|---|
| `admin.access` — open /admin + dashboard | no | yes |
| `admin.users.view` — user directory + metadata | no | yes |
| `admin.users.manage` — roles, suspend/reactivate, AI limits | no | yes |
| `admin.settings.view` — settings + key status metadata | no | yes |
| `admin.settings.manage` — change ordinary settings | no | yes |
| `admin.secrets.manage` — replace/remove Meta key (write-only) | no | yes |
| `admin.usage.view` — aggregate + per-user AI usage | no | yes |
| `admin.audit.view` — admin audit log | no | yes |
| `moderate.reports` — review community reports | yes | yes |

Enforced in `App\Services\Admin\Permissions` + `admin`/`moderate` gates;
every admin route carries `can:` middleware AND re-authorizes in its
controller. Suspended users fail all gates. Explicit non-powers:
moderators reach no keys/settings/usage/users, no private Agent
conversations, artifacts, or messages by role alone, and cannot grant
roles or suspend admins; admins see aggregate metadata only — there is
intentionally no conversation/artifact-content viewer in /admin and no
user impersonation anywhere.

## 2. Changed files and migrations

Migration `2026_10_28_000032_create_admin_foundation` (applied to the
dev DB 28 Oct): users += `global_role`, `account_status`,
`suspended_at`, `ai_enabled`, `ai_daily_request_limit`,
`ai_daily_token_limit`, `ai_unlimited`; new `app_settings`,
`ai_usage_entries` (unique `idempotency_key`), `ai_budget_counters`
(unique user/day), `admin_audit_events`.

New: `app/Enums/{GlobalRole,AccountStatus}.php`,
`app/Models/{AppSetting,AiUsageEntry,AiBudgetCounter,AdminAuditEvent}.php`,
`app/Services/Admin/{Permissions,AppSettings,UsageLedger,CostEstimates,
AiBudgets,AdminUserService}.php` (+`LastAdminException`),
`app/Services/AI/{AiDeniedException,MeteredAIProvider}.php`,
`app/Http/Middleware/CheckAccountStatus.php`,
`app/Http/Controllers/Admin/{Dashboard,User,Setting,Usage,Audit,
Moderation}Controller.php`, `app/Http/Controllers/MyUsageController.php`,
`app/Console/Commands/{PromoteAdminCommand,ReconcileBudgetsCommand}.php`,
views `admin/{dashboard,users/*,settings/*,usage/*,audit/*,
moderation/*}`, `usage/mine`, `suspended`, `admin/_nav`,
tests `tests/Feature/{AdminInterfaceTest,AdminUsageBudgetsTest}.php`,
this report.

Modified: `User` (casts + `isAdmin/isModerator/isSuspended/aiDisabled`;
privileged columns deliberately NOT fillable), `AppServiceProvider`
(gates, metered provider binding, per-boot settings application),
`bootstrap/app.php` (suspension middleware on `web`), `AIRequest`
(+`usage` attribution), `AgentTurnRunner` (+usage context),
`AgentController` (usage context, friendly prechecks, uploads gate),
`EvidenceManager::analyze` + `ArchitectureController::store` (prechecks),
`RevivalAnalysisService` + `ArchitectureService` (usage context),
`AnalyzeProjectJob` + `AnalyzeArchitectureJob` + `ExtractArtifactJob`
(initiator recheck before work), `ArchitectureService::markFailed`
(private→public for the job guard), `ModerationController::review`
(managers OR global moderators), `channels.php` (suspension denies all
presence/chat channels), login (suspended refused), registration
(open/closed gate), `ArtifactUploadService` (uploads lock),
`account-menu` (My AI usage + admin/moderation links), `routes/web.php`
(+19 admin, +1 my-usage, +1 suspended).

## 3. Secret storage and configuration precedence

Precedence (per key): environment variable when set (locked) →
database row → allowlist default. `AppSettings::allowlist()` is the only
writable surface: typed bool/int/string/secret, min/max/options
validated; unknown keys throw. The Meta key (`meta.api_key`) is
write-only: never prepopulated, never returned, no reveal button; UI
shows configured/not-configured, source, updated-at/by only. Stored via
`Crypt::encryptString` in `app_settings`; blank submit retains;
removal is a separate explicit action. The key reaches only provider
construction (runtime config override in `applyToConfig`, env-first);
it is excluded from views, audits (fact-of-change + non-secret
metadata only), ledgers, exports, validation errors, and logs
(`AppSetting::retrieved` hides secret values from serialization).
`MODEL_API_KEY` env set ⇒ page shows locked and refuses changes; the
UI never edits environment files. Rotation applies to web immediately
and to queue workers on their next job (settings re-applied every
boot; no restart/root/Docker needed — verified by test). Connection
check offers format-only (free, proves nothing) and one minimal
5-token inference (explicitly billable, confirmed); both are
explicit, audited, and the live call is ledger-metered as
unattributed system usage. No non-billable provider validation
endpoint is claimed because none is known.

## 4. Suspension/revocation behavior and limitations

`AdminUserService::suspend` (transactional): marks suspended, deletes
`user_id` session rows for database-backed sessions, rotates
`remember_token`, audits with reason. Enforcement: `CheckAccountStatus`
middleware on every web request (logout + redirect to /suspended),
login refusal, `AiBudgets::precheck` at all AI entry points,
`MeteredAIProvider` rechecks fresh user state before every attempt,
analysis/extraction jobs recheck the initiator before work, realtime
channel auth denies suspended users, artifact upload honors the
uploads lock. No Sanctum/API tokens exist in this app, so there is no
token surface to revoke. Suspension preserves everything: projects,
contributions, history, audit rows — ownership is never transferred;
the user page surfaces owned projects of suspended accounts for
manual attention. Reactivation restores access only (role, AI limits,
AI restriction unchanged). Limitations: already-running provider
HTTP requests cannot be cancelled or refunded (documented honestly
— the decorator stops the NEXT attempt, not the in-flight one);
`ImportRepositoryJob` has no recorded initiator so it cannot be
attributed or revoked (noted as a gap); non-database session drivers
rely on the middleware rather than row deletion.

## 5. Usage attribution and budget semantics

`MeteredAIProvider` wraps real and mock providers: every `complete()`
reserves allowance, executes, records exactly one `ai_usage_entries`
row (queued/attempted/succeeded/failed/cancelled/unknown,
in/out/cached/reasoning tokens as reported, duration, correlation,
model, error code — never prompts/completions/secrets/payloads),
reconciles the reservation. Features: `revival_investigator`,
`architecture_investigator`, `repo_agent`, `upload_agent` (follow-ups
included), `connection_test`; retries and multi-call turns record per
attempt with ascending `attempt_seq` under one correlation id — turn
totals on conversations are display aggregates, the ledger is truth.
Idempotency keys (`correlation:provider:seq`, unique) make job
retries safe. Background usage attributes to the initiating user;
calls without a user are labeled `unattributed` (connection tests)
and still metered. Budgets (UTC days): global kill switch, per-user
AI on/off, default + per-user daily request/token limits, optional
spend cap, `ai_unlimited` explicit exemption (metered, UI-visible,
audited). Reservations lock the user/day counter row
(`SELECT … FOR UPDATE`): request count + estimated tokens
(input-chars/4 + max output) increment atomically; completion
reconciles to actuals; failures release the token estimate but keep
the request counted. `ai:reconcile-budgets` rebuilds today from the
ledger after crashes. Denials raise `AiDeniedException` with plain
reasons (disabled/suspended/restricted/exhausted/pricing-unknown);
moderators get no bypass. Per-request input/output bounds are the
existing budget settings, enforced before calls.

## 6. Pricing assumptions and unknown-cost handling

No verified Meta price list is bundled. Costs are computed ONLY from
operator-entered `ai.price_{input,output}_per_1k_micros` + `price_version`
label; each estimate snapshots the version, so price changes never
rewrite history. Missing prices or missing token counts ⇒ `unknown`
(null), displayed as “unknown”, never zero; totals mixing known and
unknown rows say so (“partial”). The optional daily spend cap
(`ai.daily_cost_cap_micros`) enforces worst-case costing against
reconciled spend — but when set WITHOUT pricing, requests are
REFUSED with an explicit message rather than pretending a cap holds.
Currency is micro-USD, formatted `$x.xxxx (estimate)`; timezone is
UTC everywhere with reset at 00:00 labeled.

## 7. Audit behavior

`admin_audit_events`: actor, action, outcome, polymorphic target,
timestamp, reason, safe before/after JSON, correlation id. Covers
account updates, suspend/reactivate, settings updates, secret
replace/remove/test, and console promotion (actor null). Append-only:
no update/delete route, controller, or UI exists; secrets record
fact-of-change only. Tamper-resistance limit (documented): tables are
protected by application convention + database grants, not by
append-only storage or cryptographic chaining — a database superuser
could alter rows; mitigate with restricted DB credentials and
external backups.

## 8. Actual tests and results

Isolated, non-root (`docker compose -f compose.dev.yaml exec -T -u
www-data app`), mock provider, ephemeral `tester_run` grant on the
`testing` DB via `/tmp/phpunit.tester.xml` (grant dropped, file
removed after; committed `phpunit.xml` untouched). Real env keys
neutralized per-process; no live calls.

- 16 new tests (`AdminInterfaceTest` 6, `AdminUsageBudgetsTest` 10)
  covering acceptance 1–15: crafted role-change/registration/mass-
  assignment refusal; moderator review vs admin-area denial;
  UI-driven user management + audit; suspension revoking sessions,
  logins, and queued AI with attribution intact; reactivation
  keeping AI restriction; last-admin self-demote/suspend refusal
  then rotation; secret write-only/encrypted/blank-retains/remove;
  env-lock precedence + worker propagation; console promote
  confirm/abort/audit; revival+architecture attribution; 3-attempt
  turn with seqs 1–3 under one correlation; budget over-allowance
  denial; spend-cap-without-pricing refusal; private my-usage
  isolation; injection-safe CSV; admin page markers.
- Full suite: **OK, 589 tests / 2905 assertions** (573 before + 16),
  zero regressions (acceptance 16).
- `pint --test`: **PASS, 354 files**.
- Read-only smoke: `/admin` and `/my/ai-usage` 302 for guests,
  `/suspended` 200.
- Concurrency note: reservation/last-admin paths use row locks and
  are tested sequentially; true parallel-race tests were not run
  (single-connection test harness) — documented, not claimed.

## 9. Exact localhost browser walkthroughs (owner, after bootstrap)

Base `http://localhost:8080`, signed in as the promoted admin:

1. `/admin` — overview: account counts, today's UTC usage/errors/
   cost honesty labels, key status, warnings (key missing, uploads
   without isolation, suspended owners).
2. `/admin/users` — filter by role/status/date; open a user: metadata,
   last session activity, project counts, today/all-time usage,
   suspend/reactivate with reason (password confirm), owned-project
   attention box when suspended.
3. `/admin/users/{id}/edit` — role/AI limits/unlimited exemption +
   required reason (password confirm); last-admin demote shows the
   protection error.
4. `/admin/settings` — typed allowlist with effective value, source
   badge (environment/database/default), locked flags, help text.
5. `/admin/settings/secrets` — status metadata only; replace with a
   key (blank keeps); format check (free) vs live test (billable,
   confirmed); separate removal. Env-managed keys show locked.
6. `/admin/usage` — filter user/feature/status/project/date; totals
   with estimate/unknown/partial labels; CSV download; per-user
   drill-down with attempts, tokens, duration, correlation.
7. `/admin/audit` — filterable append-only trail with before/after.
8. `/admin/moderation` — report queue; project reports link out,
   lounge reports review inline (moderator accounts reach only this).
9. `/my/ai-usage` (any user) — own consumption, limits, remaining,
   unknown-cost honesty.
10. Suspended user — next click lands on `/suspended` with
    explanation; login refused; queued analysis fails ACCESS_REVOKED.

## 10. First-admin bootstrap (owner-operated)

No public endpoint, no default admin, no reusable token exists:

1. Register a normal account through the UI.
2. From a project shell: `php artisan admin:promote you@example.com`
   (or `admin:promote some-handle --by-handle`).
3. Read the warning, confirm explicitly. The promotion is audit-logged
   (`role.promoted`, console actor). Promote a second admin before
   changing the first — the last active admin cannot be demoted,
   suspended, or removed.

## 11. Required owner-operated setup and worker restart

- Run `php artisan migrate` wherever this deploys (000032 adds all
  tables; dev already migrated).
- No worker restart required: settings re-apply every boot; rotation
  propagates on next job. (If a future deployment uses Octane or
  `config:cache` with DB-backed overrides, re-verify propagation.)
- OS-level extraction-worker isolation is STILL unverified: keep
  `artifacts.uploads_locked` (or `ARTIFACTS_UPLOADS_LOCKED`) ON for
  untrusted uploads until the dedicated-UID/no-net/cgroup/tmpfs
  setup from the earlier report is in place. Toggling
  `artifacts.enabled` does not create a sandbox.
- Enter model pricing before trusting cost figures or setting a
  spend cap; without pricing all costs read “unknown” by design.
- No MFA facility exists in this application — consequential admin
  actions use Laravel password confirmation instead (documented, not
  invented).
- No Docker/root/admin-UI deployment buttons were added, per spec.

## 12. Remaining gaps and risks

- Import jobs lack initiator attribution (cannot revoke/attribute).
- In-flight provider requests cannot be cancelled/refunded.
- No per-account concurrency cap (rate + budgets only).
- True parallel race coverage for locks is code-review-level, not
  test-proven.
- Audit trail is DB-conventional, not tamper-evident (§7).
- Live-model behavior of budgets/denials unverified (mock only).
- No real-browser pass this session — backend tests + HTTP smoke
  only; ChatGPT browser validation pending per instructions.
