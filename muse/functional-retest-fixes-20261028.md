# Follow-up fixes — functional retest 28 Oct 2026

Two issues stayed open after the browser retest. Both are fixed in the
working tree (uncommitted, on top of the earlier branding + QA-fix sets).
The retest file `rev2square-functional-retest-20261028.md` was not found
in the workspace, /tmp, or the Windows projects folder, so this follows
the brief's exact error text, traces, and URLs.

No live model calls were made. No fixture data altered. Failed runs and
snapshots preserved.

## P1 — architecture retry failed on echoed content hashes

**Root cause.** The validator demanded a ≥12-hex `content_hash` per
evidence ref, but the model input never contains any hash
(`AnalysisPrompt::user()` prints `--- SOURCE {id} :: {path} ---` plus
content only). The prompt even falsely claimed a hash was "printed
beside the source". The model therefore had to fabricate hashes and
could not satisfy the check — the observed
`components[0].evidence[0..2].content_hash must be at least 12 hex
characters` was inevitable, not a model quality issue.

**Fix (server-side binding, checks kept).**
- `app/Services/Revival/ArchitectureSchema.php` — omitted/empty hash
  now binds to the trusted snapshot-manifest hash; a supplied value
  must still be ≥12 hex and a manifest prefix, else rejected
  (malformed → format error, wrong → mismatch error). Normalized
  output always carries the manifest hash. Source-id, commit-scoped
  index, path, and line-range checks unchanged.
- `app/Services/Revival/ArchitecturePrompt.php` — corrected
  instruction: leave `content_hash` empty unless a hash is printed;
  never invent one.

**Tests.**
- `tests/Unit/ArchitectureSchemaTest.php` — omitted binds, prefix
  binds to full hash, malformed triple reproduces the exact observed
  messages.
- `tests/Unit/ArchitecturePromptTest.php` (new) — locks the binding
  rule, forbids the old false sentence.
- `tests/Feature/ArchitectureTest.php::test_empty_hashes_bind_to_manifest_and_render_diagram`
  — empty-hash payload reaches `Ready` with manifest hashes and a
  rendered clickable diagram.

**Retest (owner, paid — NOT done, needs explicit authorization).**
http://localhost:8080/projects/qa-functional-revival-20261028/architecture
→ "Retry analysis" → expect `Ready` + diagram. The two failed snapshots
(provider 400, then hash validation) are preserved. No green
end-to-end architecture claim is made here.

## P2 — shallow README-only investigation within budget

**Assessment.** Trace `s,s,read(S303 README),s,s,arch` on agent/3: the
two-search guard worked as implemented (a read happened at call 3; the
6th call was architecture, not a search). The miss is relevance and
selection: repeated-term README matches outranked single-mention
implementation files, nothing flagged already-read sources, and the
unavailable snapshot (architecture still blocked) burned the last call
without steering back to code.

**Fix (six-call ceiling unchanged).**
- `app/Services/Agent/EvidenceTools.php` — relevance: content-term
  contribution capped at 2 (was 5), +3 for implementation kinds
  (`source`/`script` via `FileClassifier`), import-time
  `relevance_score` tiebreak; results carry `kind`/`language`.
  Honest gaps: `not_imported_matches` names discovered-but-skipped
  paths matching the query (from `missing_relevant`/`skipped`, cap 5)
  so the model reports them as not inspected. Unavailable-snapshot
  reason now steers to `read_evidence`.
- `app/Services/Agent/AgentTurnRunner.php` — search results flag
  `already_read` for sources opened this turn; examined snapshot
  resets the streak (progress), unavailable snapshot advances it
  (no laundering search loops); only successful reads reset (a failed
  read is no progress).
- `app/Services/Agent/AgentPrompt.php` — prefer unread implementation
  sources; name `not_imported_matches` as not inspected.

**Tests** (outcomes, not guard mirrors).
- `tests/Feature/RevivalAgentTest.php` — stuffed README loses to the
  `.go` implementation; not-imported term matches named with reasons;
  missing snapshot steers to reads; full representative turn surfaces
  the `.go` file first (asserted in the wire content), reads and
  verified-cites it.
- `tests/Unit/AgentTurnRunnerTest.php` — unavailable snapshot advances
  the streak (next search blocked), examined snapshot resets it,
  `already_read` flags verified on the wire payload.

**Retest (owner, paid — NOT done).** Fresh cloud-components question,
e.g. http://localhost:8080/projects/qa-functional-revival-20261028/agent
→ expect implementation reads before budget ends, or a precise
"discovered but not imported: <paths>" statement. If the fixture's 50
stored files contain no cloud `.go` implementation, the correct outcome
is that precise statement — not a read that cannot exist.

## Verification

- `vendor/bin/phpunit -c phpunit.tester.xml --do-not-cache-result`
  (non-root `www-data`, isolated `testing` DB, ephemeral grant dropped
  after) → **OK, 529 tests / 2542 assertions** (+12 since the QA-fix
  set; full suite, not a subset).
- `vendor/bin/pint --test` → **PASS, 308 files**.
- Earlier confirmed improvements (feasibility #11/report 5, S-links,
  formatting, history names, draft links, copy, token telemetry) are
  untouched by this set; their tests still pass in the full run.

## Status separation

- Implemented: P1 binding + prompt correction; P2 relevance, selection,
  planning, and gap reporting.
- Automated-tested: all of the above (fake providers, isolated DB).
- Live browser-verified this session: none — no browser here, no
  screenshots found, no live calls made. Architecture end-to-end and
  representative agent depth await the owner's authorized retests above.
