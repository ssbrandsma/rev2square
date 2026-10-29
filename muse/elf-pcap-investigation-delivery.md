# ELF + PCAP investigation delivery — project-scoped upload investigation in the Revival Agent

Implemented: upload → describe → “Start investigating” → cited findings + follow-ups,
for ELF firmware and PCAP/PCAPNG captures, inside the existing Revival Agent.
No live model calls were made; no fixture data altered; no commit/push/deploy.

## 1. Implemented features and limitations

**Workflow (all wired):** the project Agent page has an upload section
(multi-file, contents-detected formats, limits explained up front) and an
investigation form (select ready files, research question, optional device
context, explicit consent checkbox, prominent “Start investigating”).
States are explicit: `uploaded → queued → extracting → ready / partial /
failed`. Extraction progress (artifact page) is separate from Agent progress
(conversation trace). A failed selection keeps the question (`withInput`) and
names the failure; retry reuses stored bytes (no re-upload); follow-ups reuse
the same snapshot (no re-parse).

**ELF (`elf-php/1.0.0`, pure PHP, no shell/deps):** magic/class/endianness/
machine/object-type detection; arch from the machine field (ARM32, AArch64,
x86, x86-64 — never inferred from 32/64-bit class alone; others reported
explicitly, e.g. MIPS); segments, sections (bounds-checked, out-of-bounds
flagged, never trusted), symbols + dynamic (NEEDED/SONAME/unresolved
imports), bounded printable strings with file offsets + encoding label,
endpoint candidates (URL/hostname/IPv4/protocol) labeled candidates-only.
Stripped/static binaries report `no_dynamic_segment` / `no_symbols` as valid
states. File offsets vs virtual addresses are labeled and never interchanged;
no function names, call graphs, source lines, or decompilation are fabricated.

**Captures (`pcap-php/1.0.0`, pure PHP):** PCAP (µs/ns, either endianness) +
PCAPNG (multiple interfaces, per-interface precision); supported link types
Ethernet + raw-IP, others kept with explicit `unsupported_linktype` notes;
stable packet numbering from file order; bounded summary (packet count, time
range, interfaces, endpoints, protocols, flows with packet/byte counts, DNS
questions + A/AAAA/CNAME resolutions, TCP attempt/completed/refused/
incomplete/reset/retransmit counters, redacted HTTP method/host/path/status,
TLS SNI/ALPN when present, mDNS/SSDP discovery). Encrypted payloads are
reported unreadable, never “inspected”. Direction/timestamps/packet refs
preserved; no assumption about which endpoint is the device.

**Agent tools (7, read-only, consent-gated):** `list_artifacts`,
`search_elf_strings`, `read_elf_metadata`, `search_capture`,
`list_capture_flows`, `read_packet`, `compare_evidence`. Every call re-checks
the project `view` policy, the conversation consent flag, and a server-side
artifact allowlist; model-supplied ids never widen access. Results are
bounded, redacted, carry `[A{id}]` citations verified against artifacts the
model actually saw this turn, and expose partial/unsupported states. The
six-call ceiling is unchanged; artifact searches consume the same
search-before-read planning budget as evidence searches, reads/list/compare
reset it. `compare_evidence` correlates a term across firmware strings,
endpoints, DNS, and flows, and labels matches “correlation, not proof”;
repository tools remain available for the GitHub side of the comparison.

**Evidence identity/navigation:** `[A{id}]` chips link to an authorized
artifact viewer (`?from=agent:{id}` return path, forged ids ignored) showing
SHA-256, format/arch, extractor + schema version, limits, warnings, and a
“what the AI provider receives” disclosure. Snapshots are immutable JSON
beside the raw bytes; reprocessing overwrites nothing historical (retry only
from failed state, new extraction timestamp). Citations to deleted artifacts
keep recorded metadata but no longer resolve (stated on delete).

**Limitations:** no I²C/SPI/CAN (out of scope); no decompilation, emulation,
replay, endpoint contact, scanning, or flashing (refused by design); OS-level
extraction isolation (non-root worker, netns, cgroups) is an owner-operated
deployment step — the code enforces byte/output/time budgets and failure
categories, and parsing runs in the queue worker, never the web process;
HTTP/TLS parsing covers common visible metadata, not every extension.

## 2. Architecture and parser choices, with versions

- `elf-php/1.0.0` (`app/Services/Artifacts/ElfParser.php`, ~600 lines):
  hand-written bounds-checked reader. Chosen over `readelf`/`pyelftools`
  because the queue container has no ELF toolchain and shelling out from
  filenames/bytes is forbidden; every read is length-guarded.
- `pcap-php/1.0.0` (`app/Services/Artifacts/PcapParser.php`, ~900 lines):
  hand-written PCAP/PCAPNG reader + IPv4/IPv6/TCP/UDP/DNS/HTTP/TLS-hello/
  mDNS/SSDP dissector. Chosen over `tshark`/`tcpdump` for the same reason;
  checksums are not validated (documented in the fixture README).
- `EndpointFinder` (regex URL/hostname/IPv4/protocol scan over kept
  strings), `Redactor` (auth/cookie/bearer/query-key/private-key patterns +
  header allowlist + URL userinfo/query redaction).
- `ArtifactExtractor::extract()` — detect → integrity check (size + SHA-256)
  → parse with limits → fit snapshot → store immutable JSON → ready/partial
  (partial on truncation/cap/out-of-bounds/unsupported markers) or failed
  with a friendly actionable message. `ExtractArtifactJob` (tries=1,
  timeout from config, `failed()` marks the row honestly).
- `ArtifactUploadService` — validation, per-project count cap, SHA-256
  dedupe (identical bytes reuse the existing row), generated storage keys,
  job dispatch. `ArtifactEvidence` — redacted coverage/context/disclosure
  layer; the only thing the model ever sees.

## 3. Changed files and separation from pre-existing work

New (this assignment): `app/Models/ProjectArtifact.php`,
`app/Services/Artifacts/{ArtifactExtractor,ElfParser,EndpointFinder,
PcapParser,Redactor,ArtifactUploadService,ArtifactEvidence}.php`,
`app/Services/Agent/ArtifactTools.php`, `app/Jobs/ExtractArtifactJob.php`,
`app/Http/Controllers/ArtifactController.php`, `config/artifacts.php`,
`database/migrations/2026_10_28_000030_create_project_artifacts_table.php`,
`database/migrations/2026_10_28_000031_add_artifact_columns_to_agent_conversations.php`,
`resources/views/projects/artifacts/{index,show}.blade.php`,
`tests/Fixtures/Artifacts/{README.md,generate.php,files/*}` (13 files),
`tests/Unit/Artifacts/{ElfParserTest,PcapParserTest,RedactorTest}.php`,
`tests/Feature/ArtifactInvestigationTest.php`, this report.

Modified (surgical, existing behavior preserved):
`app/Models/Project.php` (+`artifacts`, +`agentConversations` relations),
`app/Models/AgentConversation.php` (+artifact fillable/casts),
`app/Services/Agent/ToolContext.php` (nullable repository for
artifact-only turns + artifact allowlist/consent),
`app/Services/Agent/ToolResult.php` (+`seenArtifactIds`),
`app/Services/Agent/EvidenceTools.php` (null-repository guard; repo turns
unchanged), `app/Services/Agent/AgentTurnRunner.php` (`[A{id}]` citation
verification + artifact search/read planning; `[S{id}]` path untouched),
`app/Services/Agent/AgentPrompt.php` (+`artifactInvestigation()` block;
base system prompt unchanged), `app/Http/Controllers/AgentController.php`
(+`investigate()`, artifact-aware `runTurn`/`message`/`show`/rendering;
plain repo chat unchanged), `config/filesystems.php` (+private `artifacts`
disk only), `routes/web.php` (+6 routes), `resources/views/projects/agent/
{index,show}.blade.php` (upload/investigate section + artifact citations).
Exports use an allowlist and needed no change (verified by test).

Pre-existing uncommitted work (branding set, browser-QA set,
functional-retest set) is preserved as-is; this feature overlaps those files
only where the Agent surface required extension, and every overlapped
behavior is still covered by its original tests (full suite green).

## 4. Storage, redaction, model-transmission behavior

Raw bytes live on the private `artifacts` disk
(`storage/app/artifacts/{project_id}/{random40}.bin`, `serve: false`),
never under public paths; snapshots beside them (`*.extraction.json`).
Recorded per artifact: project, uploader, original name (escaped display
metadata only), byte size, SHA-256, format/arch, state + failure
code/message, extractor + schema version, warnings, limits applied,
technical attributes, timestamps. No archive unpacking, no recursion.

The model receives only `ArtifactEvidence` output: coverage lists, bounded
context blocks (default 12 KB/conversation), and per-tool slices — all
passed through `Redactor`, all citing `[A{id}]`. Transmission requires the
per-conversation consent checkbox; upload alone never consents
(`test_investigate_requires_explicit_consent`). Disclosure text is shown on
the Agent page, the artifact viewer, and beside the checkbox, and states
redaction cannot catch every secret. Model/parser logs carry no raw
content (tools log name/args/ms only). Private addresses stay in tool
output for correlation (explained in the prompt rules) but raw payloads are
never extracted.

## 5. Processing limits and isolation guarantees actually enforced

Config (`config/artifacts.php`, all env-overridable): 8 MB/upload, 25
artifacts/project, 512 KB snapshot, ELF 2000 strings/256 B/2000 symbols/256
sections, PCAP 5000 packets/500 flows/500 DNS/2048 payload bytes, 12 KB
model context, 60 s job timeout, 90-day retention guideline (explicit
maintainer delete only — no auto-deletion implemented or run).

Enforced in code: upload size/count caps, parse-time truncation with
warnings, snapshot fit-or-fail, job timeout + single try, read-only snapshot
serving (raw bytes opened only by the extractor), structured parsing with no
shell (no command construction from any input), per-call authorization.
NOT enforced in code (owner-operated, see §7): worker UID, network
namespace, cgroups, tmpfs. The queue worker currently runs parsing in-process;
that is the documented setup gap, not a silent claim.

## 6. Required dependencies, migrations, configuration

No new dependencies (pure-PHP parsers; no installs performed). Migrations
`000030` (`project_artifacts`) and `000031` (`artifact_ids`,
`device_context`, `artifact_consent_at` on `agent_conversations`) —
applied to the dev DB 2026-10-28 (see §7). Config: `config/artifacts.php`
(defaults work out of the box); optional env `ARTIFACTS_ENABLED`,
`ARTIFACTS_MAX_UPLOAD_BYTES`, `ARTIFACTS_MAX_PER_PROJECT`,
`ARTIFACTS_MAX_SNAPSHOT_BYTES`, `ARTIFACTS_ELF_*`, `ARTIFACTS_PCAP_*`,
`ARTIFACTS_CONTEXT_MAX_BYTES`, `ARTIFACTS_EXTRACTION_TIMEOUT`,
`ARTIFACTS_RETENTION_DAYS`. Queue worker must be running (already is in
dev). No restarts needed (no route/config cache in dev).

## 7. Setup steps performed vs left for the owner

Performed: dev migration 000030/000031; full suite + Pint via the existing
dev stack as non-root `www-data` against the isolated `testing` DB with an
ephemeral grant (dropped afterward); read-only HTTP smoke (`/` 200, agent +
artifacts routes 302 for guests). Not performed (explicitly out of bounds):
production isolation hardening for the extraction worker (dedicated UID,
no-net namespace, memory/CPU cgroup, tmpfs scratch); any production
migration/deploy; any live model call. Owner steps for production: run the
two migrations, set `ARTIFACTS_*` env as desired, confine the queue worker
as above, and confirm `MODEL_API_KEY` is configured before live turns.

## 8. Test commands, actual results, blockers

Via `docker compose -f compose.dev.yaml exec -T -u www-data app` with an
ephemeral `/tmp/phpunit.tester.xml` (absolute paths, `tester_run` grant on
`testing`, deleted + grant dropped after; committed `phpunit.xml`
untouched):

- `vendor/bin/phpunit -c /tmp/phpunit.tester.xml --do-not-cache-result`
  → **OK, 565 tests / 2720 assertions** (was 529/2542 before this
  assignment: +12 `ArtifactInvestigationTest` +24 parser/redactor unit
  tests; zero regressions).
- `./vendor/bin/pint --test` → **PASS, 327 files** (5 style findings in
  new/edited files fixed manually — the container user cannot write the
  bind mount, so `pint` write mode was not used).
- `php -l` clean on every new/edited file; `migrate` applied cleanly.

New coverage: upload→queue→ready→viewer; unsupported-file failure;
consent-required (no conversation without the checkbox); consented turn
with verified `[A{id}]` citation + viewer link + device context on the
page; raw-marker absence from all mock wire requests; follow-up reuse
(no new rows/re-parse); cross-project denial (investigate exclusion +
viewer 404); malicious-filename escaping; DNS/flow/packet tool reads with
packet refs; cross-file `compare_evidence` with correlation wording;
unconsented/foreign tool refusal; failed-selection question retention;
md+json export exclusion. ELF/PCAP/Redactor unit suites cover 4 arches +
endianness + MIPS-unsupported + stripped/static + bad-section/truncated/
malformed inputs, PCAP+PCAPNG + multi-interface + unsupported linktype +
DNS/TCP/HTTP/TLS/discovery + truncation, and secret-pattern redaction.
Blockers: none for automated validation. First run caught 1 real bug
(`investigate()` compared a Collection to `[]`, creating empty consented
conversations — fixed, covered by the cross-project + failed tests).

## 9. Exact localhost walkthroughs and synthetic fixture locations

Fixtures: `tests/Fixtures/Artifacts/files/` (regenerate with
`php tests/Fixtures/Artifacts/generate.php`; provenance in its README —
all synthetic, `example.com`/`.local`, documentation/private addresses,
`SYNTHETIC-` secrets, zeroed checksums).

Walkthrough (base `http://localhost:8080`, signed in, any project):

1. ELF: Agent page → upload `elf64-x86_64.elf` (lands as
   `firmware.elf`-style name) → wait for Ready → select `[A{id}]` →
   question “Which external services does this firmware reference?” →
   context “Test device, v1” → check consent → Start investigating →
   expect `updates.example.com` + `broker.local` candidates with
   candidate-only wording and a clickable `[A{id}]` → open it for
   header/sections/symbols/SHA-256.
2. Capture: upload `capture-mixed.pcap` → question “What DNS activity
   and connection outcomes are visible?” → expect `example.com` query/
   response, completed + refused + incomplete TCP outcomes, redacted
   HTTP (`token=[REDACTED]`), TLS SNI `tls.example.com`, each with
   packet numbers → open the viewer for the TCP counters.
3. Combined: select both + ask “Which external services does this
   device attempt to contact? Match firmware and capture evidence.” →
   expect `compare_evidence`-style correlation with OBSERVED/INFERRED/
   HYPOTHESIS/UNKNOWN separation and next-measurement proposals.
4. Failure: upload `not-elf.bin` → actionable “not a supported …”
   error; upload `capture-truncated.pcap` → Partial with warnings;
   try investigating a failed file → question kept, retry offered.
5. Reuse: follow up in the same conversation — no re-upload, same
   `[A{id}]` ids.
6. Denial: sign in as another user, open the first project’s artifact
   URL directly → 404; try its id in your own project → excluded.
7. Limits: upload `elf64-static-stripped.elf` → honest no-symbols
   report; ask about encrypted payload bytes → “not readable”.
8. Disclosure: read the consent label + viewer’s “What the AI provider
   receives” before confirming; raw bytes are never shown to the model.

## 10. Which acceptance scenarios were browser-verified

None in a real browser this session (no browser here). All 8 scenarios
are implemented and automated-test-verified (fake provider, isolated DB)
per §8; live browser verification (upload → investigate → cited answer →
viewer navigation, desktop + mobile, plus one paid live-model turn) awaits
the owner’s explicit authorization in the in-app browser.

## 11. Known gaps, risks, follow-up work

- OS-level worker isolation is documented but not implemented (§5/§7).
- Live-model behavior unverified: tool-shape compliance, `[A{id}]`
  discipline, and correlation quality need one authorized live turn.
- No per-artifact line/byte viewer beyond the summary + tool slices
  (strings carry offsets; raw bytes are intentionally not served).
- Checksums unvalidated; exotic link types/TCP extensions report as
  unsupported rather than parsed; redaction is pattern-based and
  explicitly incomplete — the consent copy says so.
- Agent turns remain synchronous (~60 s + model latency); very large
  selections may need narrower questions (context cap truncates loudly).
- Working tree also holds the earlier uncommitted branding + QA-fix
  sets; nothing was committed, pushed, merged, or deployed.
