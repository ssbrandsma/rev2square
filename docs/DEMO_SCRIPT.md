# Rev²Square — 3-minute demo script

Target: a reviewer who has never seen the app. Total: ~3:00.
Base URL: `http://localhost:8080`. All data below is real local state unless marked optional.

## Setup (before recording, ~5 min)

1. Start the stack and confirm health:
   - `docker compose -f compose.dev.yaml up -d`
   - `curl -f http://localhost:8080/up`
2. Sign-in readiness (two browser profiles or normal + private window):
   - Maintainer: seed login from `README.md` ("Seed login (dev only)").
   - Contributor: pre-create an account via Register (any name/email); verify it can sign in.
3. Confirm the Bublcam project is published with its approved assessment:
   - Guest window: `http://localhost:8080/projects/bublcam-revival` shows the
     "Revival assessment · v1 · maintainer-approved" panel.
4. Decide the analysis beat (see 1:10): live re-run needs `MODEL_API_KEY` in `.env`
   plus queue/scheduler containers running (`docker compose -f compose.dev.yaml ps`).
   Without them, use fallback B — never fake a run.

## Beat 1 — The problem (0:00–0:25)

Action: landing page `http://localhost:8080/`, scroll hero → how-it-works → featured.

Narration: "Good hardware dies when its company does. The Bublcam — a 360°
camera from a 2013 Kickstarter — lost its cloud, its app, and its docs. What
survives is scattered across a GitHub repo. Rev²Square turns that scattered
evidence into a revival plan a community can actually execute."

Fallback: if the landing page fails, start at `/projects` and narrate the same
line over the project cards.

## Beat 2 — Discover the project (0:25–0:50)

Action: click Bublcam Revival → project page. Point at phase/activity badges,
device panel, assessment summary, roadmap, "Help wanted" tasks.

Narration: "This is a real revival project. Everything technical on this page —
the feasibility verdicts, the roadmap, the open tasks — traces back to imported
source evidence and one human-approved AI assessment."

Fallback: search "bubl" in the explorer if the featured card is not visible.

## Beat 3 — Real evidence import (0:50–1:10)

Action: as maintainer, Dashboard → Bublcam → Evidence. Show the tracked repo
(`ssbrandsma/bublcam-revive`, pinned commit), the source manifest table
(S1…S36 with paths, sizes, hashes), and the skipped-files list with reasons.

Narration: "The maintainer imports the public repo. Thirty-six files, pinned to
one commit, each with a stable source ID. Nothing is executed; oversized and
binary files are skipped with a recorded reason. This manifest is what the AI
is allowed to cite — nothing else."

Fallback (rate-limited API): do NOT re-import live; the existing manifest is
already real. Narrate: "GitHub limits anonymous callers, so we keep the
verified import from earlier — same commit, same manifest."

## Beat 4 — Real Meta AI analysis (1:10–1:50)

Action (live path): click "Analyze with AI". Show the queued run row, then —
after the worker finishes (~1 min) — the completed run with its report link.

Narration: "One click sends the evidence package to Meta's model. The prompt
treats every repo file as untrusted data, demands structured JSON with a
citation for every claim, and the backend rejects anything that fails schema
or citation validation."

Fallback B (no key / slow API / recording time): open the existing run history
and the validated report v1. Narrate honestly: "This is the previously
completed real analysis — model `muse-spark-1.3`, fourteen findings, five
revival paths, six experiments, every citation resolved. A fresh run takes
about a minute; the review workflow is identical either way."

Never claim a historical run was generated during the recording.

## Beat 5 — Human review (1:50–2:15)

Action: open the report review page. Show a finding with its SUPPORTED label
and source link, an UNKNOWN, one revival path with its verdict, and the
approve/reject/publish actions with review notes.

Narration: "Nothing publishes itself. The maintainer checks each claim against
its linked source, sees what is still unknown, and only then approves. The
original AI JSON stays immutable; the approval and notes are recorded
separately in an audit trail."

## Beat 6 — Published roadmap (2:15–2:40)

Action: guest window, full public assessment
`/projects/bublcam-revival/reports/1`: summary, maintainer notes, findings,
unknowns, revival approaches, proposed experiments labeled "plans, not
results", provenance footer. Then back to the project roadmap and tasks.

Narration: "After approval, the assessment goes public with progressive
disclosure — the summary up front, engineering depth one click down. Approved
milestones and tasks become the project roadmap, and re-analysis later will
update them without duplicating anything."

## Beat 7 — Community contribution (2:40–3:00)

Action: as the contributor, open a task → "Offer help" → submit a short offer.
As maintainer, open Offers, show the pending offer, accept it.

Narration: "Anyone can offer to help on a concrete task — no contact details
exposed publicly. The maintainer reviews each offer. Reverse. Revive.
Together."

Fallback: if short on time, submit the offer and show it pending; acceptance
can be narrated over the offers list.

## After recording

- Leave the Bublcam report v1 published (it is the demo anchor).
- Optionally decline/withdraw the demo offer to reset the offers list.
- Do not run `migrate:fresh` — it would destroy the validated live analysis.
