# HACKATHON.md — New Work vs Pre-existing Material

Competition: Meta Global AI Developer Hackathon 2026, Indie Developer track.
Build period: 27 Oct – 4 Nov 2026. This file tracks which parts of the
submission were built during the event. Presented features, code, and
functionality must have been built during the hackathon; anything reused must
be disclosed here.

## Built during the hackathon (Rev2Square implementation)

- WP0 (27 Oct 2026): Laravel 13 + Docker Compose dev stack (`app`, `nginx`,
  `db`, `queue`, `scheduler`, `node`), Breeze Blade auth, Livewire + Flux +
  Tailwind v4 + Alpine toolchain, MariaDB-backed PHPUnit suite
  (`BootstrapSmokeTest`), `README.md`, `AGENTS.md`,
  `docs/IMPLEMENTATION_PLAN.md`, this file, `CASE_STUDIES.md`.
- WP1 (27 Oct 2026): project data layer (devices, projects, members, milestones, tasks), public landing/explore/detail/about pages, maintainer dashboard + project CRUD with ownership policies, Bublcam/Squeezebox showcase seeds (PRD facts only), ProjectCrud/Policy/Explorer tests.
- WP2 (27 Oct 2026): GitHub evidence importer (allowlisted REST client, bounded file selection, queued imports, evidence manifest UI on project evidence page), repositories + evidence_sources tables, EvidencePackage handoff for WP3, GitHubUrl/EvidenceManifest/GitHubImport tests. Real Bublcam import observed end-to-end (35 files, ~117KB at commit c5d4b9f); refined re-import (+LICENSE-DOCS rule) auto-retries after the GitHub rate-limit reset.
- Later WPs will be appended here with date + scope as they land.

## Pre-existing / third-party (NOT hackathon work)

- Bublcam and Squeezebox device research, repositories, notes, and media
  created before the event (see `CASE_STUDIES.md` for the per-case inventory).
- Upstream open-source packages (Laravel, Livewire, Breeze, Flux, Tailwind,
  Vite, MariaDB, Nginx, Node) and their stock scaffolds (auth views,
  default tests) — used as tooling, not presented as product features.
- `docs/Rev2Square_PRD_v0.3_Hackathon_Edition.md` (planning document,
  pre-event baseline v0.3 dated 26 Oct 2026).

## License

Repository license: MIT (`LICENSE`, copyright 2026 ssbrandsma).
