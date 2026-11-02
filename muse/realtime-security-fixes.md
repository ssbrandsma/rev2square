# Realtime security fixes (review of assignments 1–4)

Fixes for the six review findings, kept as a new commit on top of the
original delivery. No history was rewritten.

## P1-1 — Attribute-safe author rendering

`resources/js/chat-render.js` (new) builds live messages with
`createElement`/`textContent`/`setAttribute` only. The single `innerHTML`
assignment is the server-sanitized body. `chat-room.js` renders socket,
catch-up, and own-message payloads through it.

Covered by `tests/js/chat-render.test.mjs` (adversarial names/URLs stay
inert; verified the test fails against the old concatenation style) plus
a wiring assertion in `RealtimeSecurityTest`.

## P1-3 — Per-account composer drafts

`resources/js/drafts.js` (new) scopes keys as `composer:{userId}:{key}`.
Legacy unscoped keys are removed on sight for signed-in sessions (they
cannot be attributed); logout clears every composer key. Chat and
discussion composers use the store; guests never read or write.

Covered by `tests/js/drafts.test.mjs` (sequential-login isolation,
legacy handling, logout cleanup, guest no-op) plus wiring assertions.

## P1-2 — Subscription revocation via channel rotation

Channel names embed a per-project epoch and a global epoch
(`App\Services\Realtime\ChannelNames`). Rotation bumps the epoch and
broadcasts `channel.rotated` (new name only, no private content) on each
old name; clients rejoin, which re-runs channel auth, so viewers that
lost access are stranded on a dead channel. No client cooperation is
needed for the security property.

- Triggers: publication change, member revoke, ownership transfer
  (project epoch); suspension (global epoch, fans out to all channels).
- Broadcasts always follow their epoch commits in program order.
- Clients: chat rooms and rosters rejoin on rotation with catch-up;
  failed re-auth shows a no-access state instead of a dead view.
- Same-browser cooperation only where the server cannot reach:
  cross-tab logout disconnect and presence opt-out leave, both scoped
  by user id (`rt:logout`, `rt:presence-optout`).

Covered by `tests/Feature/RealtimeSecurityTest.php`: stale names return
403 for everyone, current names enforce access, each trigger bumps the
epoch and announces on the old names, and the opt-out signal renders.
A live two-socket test was not possible here (no Reverb server); the
manual check is: subscribe as B, unpublish/revoke/suspend, send as A,
confirm B's tab shows no-access and B receives nothing.

## P2-4 — Catch-up pagination

`resources/js/chat-sync.js` (new) pages `after_id` to exhaustion with a
contiguous cursor and buffers live arrivals mid-run, so every id renders
exactly once in server order (bounded at 20 pages with an honest
truncation notice past that).

Covered by `tests/js/chat-sync.test.mjs` (150 backlog + live arrival,
exact-page boundary, empty probe, duplicates, failure, cap, overlap).

## P2-5 — Sitewide presence

Opted-in members join `online.{epoch}` from every app page via a hidden
layout element (`registerGlobalPresence` in `resources/js/presence.js`,
new); pages with their own roster skip the duplicate join. Registration
follows rotation and cross-tab opt-out leave.

Covered by `tests/js/presence.test.mjs` and a layout-render test in
`LoungePresenceTest`.

## P2-6 — Live message controls

`ChatMessage::actionUrls()` feeds Retract/Report/Remove endpoints into
both the HTTP serializer and the socket broadcast (which also gains the
missing `avatar_url`); the DOM builder renders the same controls as the
server rows using per-viewer page context. All enforcement stays on the
POST routes.

Covered by `ChatTest` payload/context assertions and the control matrix
in `tests/js/chat-render.test.mjs`.

## Verification

- `npm run test:js` (zero-dependency Node asserts): all pass.
- PHP suite on SQLite: 926 passed; the 26 failures are byte-identical on
  the pristine tree (pre-existing MySQL/toolchain environment gaps).
- Canonical MySQL gate, `vite build` (sandbox Node 18 vs Vite 8's Node
  22+ need), and browser validation were unavailable here; run them in
  the dev container before deploying.
- Pint clean on all touched files (14 pre-existing findings elsewhere
  left alone); `artisan view:cache` compiles.
