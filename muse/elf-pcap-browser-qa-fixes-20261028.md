# ELF/PCAP browser QA fixes — 28 October 2026

Follow-up to the real-browser QA pass over the upload/investigation feature
(fixture project `qa-functional-revival-20261028`, artifacts A4/A5,
conversations /agent/5 and /agent/6). The findings file
`elf-pcap-functional-qa-20261028.md` was not found in the workspace, /tmp,
or the home tree, so this follows the brief's exact observations, IDs, and
URLs. All fixes are in the working tree, uncommitted, on top of the earlier
uncommitted sets (branding, browser-QA, functional-retest, ELF/PCAP
delivery) — all preserved as-is.

No live model calls were made. No fixture data altered. No production,
Docker/root, credential, install, push, or deploy actions.

## 1. Missing HTTP metadata end to end — FIXED (tool serialization loss)

**Root cause (verified against actual parser output, not assumed).** The
parser was innocent: `HttpInspector` extracts the GET (`method`, redacted
`target` `/api/data?token=[REDACTED]`, `headers.host`, packet 6) and the
200 (`status`, packet 7) correctly, and the snapshot stores them. The data
died one layer up, in `ArtifactTools::searchCapture`, which read
`$http['host']` and `$http['path']` — keys the parser never emits (host
lives in `headers.host`, path in `target`). Consequences, each matching one
observed symptom: `/api/data` searches matched nothing (empty haystack);
`read_packet` returned only the packet meta (`http: GET` marker) without
joining the `http[]` detail; the turn context block omitted HTTP/TLS
entirely — so a broad question burned six calls and answered HTTP UNKNOWN.

**Fix (redaction kept, observation preserved).**
- `app/Services/Agent/ArtifactTools.php` — `search_capture` now matches
  method + `target` + `headers.host` + status and returns all four plus
  packet/ts; `read_packet` joins the matching `http` (via the shared
  presenter), `tls`, and `dns` entries by packet number; `compare_evidence`
  also scans HTTP/TLS entries.
- `app/Services/Artifacts/ArtifactEvidence.php` — new shared presenters
  `presentHttp()` (method/host/redacted-target/status/safe-headers only)
  and `presentFlow()`; the turn context block now lists flows as unordered
  pairs plus HTTP request/response and TLS SNI lines with packet numbers.
- Token VALUES stay redacted (`token=[REDACTED]`); the useful observation
  (method/host/path/status/packet) is no longer dropped. No parser or
  snapshot-shape change was needed, so the existing A5 snapshot works
  immediately — no re-extraction required for this fix.

**Tests** (`tests/Feature/ArtifactInvestigationTest.php`): path search
returns GET/host/redacted-target/packet-6 with the raw token absent;
`read_packet` 6/7/15 joins request/response/TLS detail; a full fake-provider
turn (search → read → cited answer) carries `/api/data` on the wire with
`token=[REDACTED]` and a verified `[A{id}]` citation.

**Retest (owner).** http://localhost:8080/projects/qa-functional-revival-20261028/agent
→ new investigation on A5, ask the broad DNS/TCP/HTTP/TLS question →
expect named GET/host/path/status, not HTTP UNKNOWN. Ask for `/api/data`
directly → expect packet-6 evidence. (Paid turns need fresh explicit
permission; the EUR 10 covered the QA session, not new Muse calls.)

## 2. Independently inspectable citations — DONE (bounded detail views)

**Gap.** The viewer showed aggregate counts; quoted symbols, offsets, and
packet detail were unverifiable without asking the model again.

**Fix.**
- `ArtifactEvidence::viewerDetails()` — bounded, pre-redacted structures:
  ELF sections/symbols (values labeled virtual addresses)/strings (file
  offsets)/endpoints; capture packets/flows (unordered presentation)/DNS/
  resolutions/HTTP/TLS/discovery. Caps with explicit totals (100 packets,
  50 flows/sections/symbols/DNS, 100 strings); secrets redacted before
  render (`SYNTHETIC-TEST-KEY-12345`-class values never reach the page).
- `resources/views/projects/artifacts/show.blade.php` — new anchored
  sections: `#packet-{n}` rows, HTTP entries with method/host/target/
  status + jump links, `#offset-0x…` endpoint rows, offset/file-offset vs
  virtual-address labels everywhere. Hash, extractor/schema, limits, and
  warnings stay visible; no raw bytes served; return path
  `?from=agent:{id}` unchanged.
- Tool results now carry `viewer_anchor` (`#packet-6`, `#offset-0x128`)
  on string/endpoint/DNS/HTTP matches and packet reads, so answers can
  point at the exact inspectable row.

**Acceptance retest (owner, no AI cost).**
http://localhost:8080/projects/qa-functional-revival-20261028/artifacts/5
→ HTTP section shows `GET /api/data?token=[REDACTED]`, host example.com,
packet 6, and status 200 at packet 7; packet list anchors `#packet-6/7`.
http://localhost:8080/projects/qa-functional-revival-20261028/artifacts/4
→ endpoint `https://updates.example.com/fw` at file offset `0x128`
(`#offset-0x128`).

## 3. Direction and timestamp ambiguity — FIXED (presentation + parser)

**Findings from actual tool output.** Direction: YES, canonical unordered
keys were misrepresentable — the refused flow stores `a=10.0.0.5:1883`,
`b=192.168.1.10:40001` (lexicographic) with a `>` separator in `key`, which
reads as service→client. The parser is now the only place direction is
asserted, and only from SYN evidence. Timestamps: the parser was CORRECT —
the completed HTTP flow is packets 3–7, ts 1720000002→1720000006, exactly
as stored; 1720000014 belongs to the separate TLS flow (packets 12–15).
The contradiction was cross-flow mixing in the answer, enabled by output
that never labeled bounds per flow.

**Fix.**
- `app/Services/Artifacts/PcapParser.php` (additive; old snapshots stay
  valid): flows record `initiator`/`responder` from the first lone-SYN
  sender only (UDP flows: no initiator key at all); first/last packet/ts
  are min/max, deterministic under reordered input.
- `ArtifactTools::list_capture_flows` + viewer + context block present
  `endpoint_1`/`endpoint_2` with `endpoints: unordered`, initiator only
  when observed, explicit per-flow packet/time bounds, and a
  direction_note; the misleading `a>b` key no longer reaches the model.
- `app/Services/Agent/AgentPrompt.php` — artifact rules now forbid
  reading endpoint_1 as source and require quoting each flow's own
  bounds without mixing across flows.

**Tests.** `tests/Unit/Artifacts/PcapParserTest.php`: HTTP-flow
initiator/responder + 3–7/…002–…006 bounds; refused flow keeps client
initiator despite service-first ordering; UDP asserts no initiator.
Feature test locks the unordered wire shape and the HTTP flow's bounds.

**Retest (owner).**
/projects/qa-functional-revival-20261028/agent/5 → fresh follow-up on the
refused/incomplete flows → expect unordered endpoints with initiator only
where SYN-observed, and per-flow bounds (HTTP flow 3–7, …002–…006) used
consistently. Note: A5's stored snapshot predates initiator tracking, so
old flows honestly render “no lone SYN observed” until re-extracted; new
uploads carry initiator data. No device-identity guessing anywhere.

## 4. Upload/validation feedback — FIXED

- `app/Http/Controllers/ArtifactController.php` — upload now reports
  accurate counts: `Upload result: N new (… — extraction queued);
  M duplicate(s) reused without re-uploading; K rejected: …` (the
  `reused` flag from `ArtifactUploadService` was previously ignored).
- `app/Http/Controllers/AgentController.php` + `resources/views/projects/
  agent/index.blade.php` — empty selection now says `Select at least one
  ready or partial file.`; the investigation question field is renamed to
  `investigation_question` so a failed submit repopulates only its own
  form, never the repository-question field. Existing investigate tests
  updated to the new field name.

**Retest (owner, no AI cost).**
/projects/qa-functional-revival-20261028/agent → upload 4 files incl. a
duplicate hash → expect `3 new …; 1 duplicate reused…`; submit with no
selection → friendly message, no new conversation, question text in one
field only.

## Preserved confirmed behavior

Untouched paths, all still covered by their original tests in the full
green run: 4 ELF architectures + ARM big-endian recognition; stripped
`no_symbols`/`no_dynamic_segment` honesty; invalid-format rejection,
truncated-header Failed, truncated-capture/unsupported-linktype Partial;
dedupe reuse; consent gating; artifact links + device-context persistence;
cross-project 404 / second-account denial; /agent/6-style non-causal
correlation wording (compare rule unchanged).

## Safety prerequisite (unchanged — NOT production-ready)

OS-level worker isolation is still not verified or configured. Synthetic
tests are not parser fuzzing or isolation proof. Required owner-operated
setup before untrusted uploads: run the queue worker as a dedicated
non-root user with no network namespace access, no model/production
credentials in its environment, read-only input + tmpfs scratch, and
memory/CPU/time cgroups around extraction. Enablement stays gated on
`ARTIFACTS_ENABLED` (upload service, investigate endpoint, and Agent UI
section all honor it; set false where isolation is absent). No security
infrastructure was changed here.

## Verification

Run as container user `www-data` (never root) against the isolated
`testing` DB via an ephemeral grant (dropped afterward); committed
`phpunit.xml` untouched:

- New tests failed first (6 failures: empty HTTP matches, missing packet
  detail, directional flow keys, aggregate-only viewer, wrong upload
  counts, raw validation message), then passed after the fix.
- Full suite: `OK, 573 tests / 2789 assertions` — 565 pre-existing plus
  7 new feature tests + 1 new parser unit test, zero regressions.
- `pint --test`: PASS, 327 files.
- Read-only HTTP: `/` 200; agent/artifacts routes 302 for guests.

## Implemented vs verified

- Implemented + automated-tested (fake providers, isolated DB): all four
  fix areas above.
- Browser-verified this session: none — no browser here; QA screenshots
  unavailable (findings file itself absent, brief text used instead).
- Requires owner action at http://localhost:8080: free retests (§2 viewer
  acceptance, §4 feedback) any time; paid retests (§1 broad question,
  §3 fresh follow-up) only with fresh explicit permission.
