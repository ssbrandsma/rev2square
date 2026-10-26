# Rev2Square — Reverse. Revive. Together.

Hackathon project for the Meta Global AI Developer Hackathon 2026 (Indie Developer track):
turn scattered technical evidence about abandoned hardware into a transparent,
testable, community-executable revival plan.

- Product spec: `docs/Rev2Square_PRD_v0.3_Hackathon_Edition.md`
- Execution plan: `docs/IMPLEMENTATION_PLAN.md`
- Agent contract: `AGENTS.md`
- Hackathon provenance: `HACKATHON.md`
- Case-study inventory: `CASE_STUDIES.md`

## Stack (WP0, pinned)

| Component | Version |
|---|---|
| PHP (FPM) | 8.4.27 (`php:8.4-fpm`) |
| Laravel | 13.38.0 |
| Livewire | 4.4.8 |
| Flux UI | 2.21.0 (installed, not activated; wiring in WP1) |
| Breeze (Blade auth) | 2.4.2 |
| Tailwind CSS | 4.3.3 (+ `@tailwindcss/vite` 4.3.3) |
| Alpine.js | 3.17.5 |
| Vite / laravel-vite-plugin | 8.3.5 / 3.2.0 |
| MariaDB | 11.8.9 (`mariadb:11.8`) |
| Nginx | 1.28.3 (`nginx:1.28`) |
| Node (dev/build only) | v22.23.4 (`node:22`) |
| Composer | 2.10.3 (`composer:2`) |
| PHPUnit | 12.5.40 |

PHP deps are pinned in `composer.lock`, JS deps in `package-lock.json`.
Test framework decision (WP0, per PRD §12.1 "Pest or PHPUnit"): **PHPUnit**.
No SQLite, Redis, PostgreSQL, or Sail in this stack.

## Services (`compose.dev.yaml`)

`app` (PHP-FPM, source bind mount), `nginx` (→ `app:9000`),
`db` (MariaDB + persistent `db-data` volume), `queue` (`queue:work`),
`scheduler` (`schedule:work`, exactly one instance — never scale),
`node` (Vite HMR on :5173).

Laravel reads configuration from the bind-mounted `.env` file; the Compose
file intentionally uses no `env_file` so container process env can never
shadow `phpunit.xml` test settings.

## Start

```sh
cp .env.example .env   # first time only (then php artisan key:generate if APP_KEY empty)
docker compose -f compose.dev.yaml up -d --build
docker compose -f compose.dev.yaml exec app php artisan migrate --force
```

Open [http://localhost:8080](http://localhost:8080) in a browser.
Healthcheck: `curl -f http://localhost:8080/up` (Laravel `/up` route).

## Stop

```sh
docker compose -f compose.dev.yaml stop     # keep containers + DB volume
docker compose -f compose.dev.yaml down     # remove containers, keep DB volume
docker compose -f compose.dev.yaml down -v  # also delete the DB volume (data loss)
```

## Migrations

```sh
docker compose -f compose.dev.yaml exec app php artisan migrate --force
docker compose -f compose.dev.yaml exec app php artisan migrate:fresh --seed --force  # reset dev DB
```

## Testing

Tests run inside the `app` container against the isolated MariaDB `testing`
database (created by `docker/mariadb/init.sql`). Test settings are fixed in
`phpunit.xml` (`force="true"`); they cannot be overridden from the shell.

```sh
docker compose -f compose.dev.yaml exec app php artisan test
docker compose -f compose.dev.yaml exec app php artisan test --filter=BootstrapSmokeTest
```

## Frontend

```sh
docker compose -f compose.dev.yaml run --rm --user $(id -u):$(id -g) -e npm_config_cache=/tmp/npm-cache node npm install
docker compose -f compose.dev.yaml run --rm --user $(id -u):$(id -g) -e npm_config_cache=/tmp/npm-cache node npm run build
```

The `node` service runs `npm run dev` (HMR) automatically; production builds
are compiled into the image in WP5.

## Seed login (dev only)

`migrate:fresh --seed` creates `maintainer@rev2square.test` / `password`
(owner of the Bublcam Revival and Squeezebox Standalone showcase projects).

## WP0 decisions

- Scheduler form: `schedule:work` (single long-running process).
- Dev asset serving: source bind mount shared by `app` and `nginx`;
  production mechanism (no bind mount) is a WP5 deliverable.
- Healthcheck path: stock Laravel `/up` route.
- Flux installed via Composer; no `flux:activate` (no license) and no layout
  wiring yet — WP1 decides if Pro components are needed.
