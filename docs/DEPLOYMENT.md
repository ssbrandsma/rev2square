# Rev2Square — Production Deployment (WP5B)

Target: a single Linux VPS running the hardened `compose.prod.yaml` stack.
Status: **preparation only — no remote server has been touched.** Do not run
the server-side commands below until the VPS hostname, domain, SSH access,
and deployment destination are explicitly confirmed.

Conventions: `pc` = `docker compose -f compose.prod.yaml --env-file
.env.production` (run from the repo root on the server). Every command in
this guide spells it out so copy/paste works without shell aliases.

## 1. Production architecture

```
                    HTTPS :443 / HTTP :80 (redirect)
                               |
                         +-----v------+
                         | proxy      |  Caddy: TLS termination, automatic
                         | (caddy:2)  |  ACME issuance + renewal, HTTP->HTTPS
                         +-----+------+  redirect, security headers.
                               |  nginx:80 (Compose network only)
                         +-----v------+
                         | nginx      |  Serves compiled assets from its own
                         | (built)    |  image, passes PHP to app:9000.
                         +-----+------+
                               |  FastCGI (Compose network only)
              +----------------+----------------+
              | app            | db             |
              | php-fpm        | mariadb:11.8   |
              | (built,        | persistent     |
              |  www-data)     | db-data volume |
              +---+--------+---+                |
                  | queue  | scheduler          |
                  | worker | (exactly one)      |
                  +--------+--------------------+
```

Service notes:

- `app`, `queue`, `scheduler` share one built image
  (`docker/php/Dockerfile.prod`): pinned PHP 8.4 + extensions, dependencies
  from `composer.lock`, frontend from `package-lock.json`, production
  `php.ini` + OPcache, running as `www-data` with `no-new-privileges` and
  no Linux capabilities. Laravel caches are built at container start from
  the runtime environment, so secrets are never baked into the image.
- `nginx` is a separate built image (`docker/nginx/Dockerfile.prod`) with
  the same compiled assets baked in — no shared volumes, no source bind
  mounts anywhere in production.
- `db` exposes no host port; only the Compose network can reach it. The
  app connects as the unprivileged `DB_USERNAME`, never as root.
- `queue --max-time=3600` exits 0 every hour by design; the
  `unless-stopped` restart policy revives it with fresh code. `scheduler`
  must never be scaled past one replica.
- There is no Vite dev server, no `node` service, and no SQLite/Redis in
  production — the same constraint set as development.

## 2. Required VPS specifications

Minimum (demo + judging traffic):

| Resource | Minimum | Recommended |
|---|---|---|
| vCPU | 2 | 2 |
| RAM | 4 GB | 8 GB |
| Disk | 25 GB SSD | 40 GB SSD |
| OS | Ubuntu 24.04 LTS (or Debian 12) | same |
| Docker | Engine 27+ with Compose v2.24+ | latest stable |

Why: MariaDB + php-fpm + queue worker + a 16k-token AI response in flight
fit in 4 GB, but 8 GB gives headroom for image builds on the box. Disk
holds images (~2 GB), the database (<100 MB for the demo), Caddy
certificates, and local backups (each a few MB compressed).

The deploy user needs Docker rights (`docker` group or root-equivalent
sudo). No other inbound ports besides 22 (SSH), 80, and 443.

## 3. Required DNS configuration

1. Create an `A` record: `rev2square.example.com` → the VPS IPv4 address.
   Replace `rev2square.example.com` with the real domain in every step
   below (notably `DOMAIN` and `APP_URL` in `.env.production`).
2. Wait for propagation (`dig +short rev2square.example.com` answers the
   VPS IP) **before** starting the stack: Caddy can only complete the ACME
   HTTP-01 challenge once port 80 on that name reaches the server.
3. No manual certificate steps: Caddy requests the certificate on first
   start and renews it automatically. Certificates persist in the
   `caddy-data` volume across restarts and rebuilds.

## 4. Environment configuration

On the server, in the repo checkout:

```sh
cp .env.production.example .env.production
chmod 600 .env.production
```

Then set, at minimum:

- `APP_KEY` — leave empty until the image is built, then fill it (step 5).
- `APP_URL=https://<real domain>` (no trailing slash).
- `DOMAIN=<real domain>` (Caddy site address; must match `APP_URL` host).
- `DB_PASSWORD`, `DB_ROOT_PASSWORD` — two distinct 32-byte secrets:
  `openssl rand -base64 32`.
- `MODEL_API_KEY` — paste the server-side Meta key; it never appears in
  the repo, the image, logs, or client output.
- `GITHUB_TOKEN` — optional; raises GitHub import quota from 60/hr to
  5,000/hr. Recommended for the demo.

Fixed production values (already in the template, do not change without a
reason): `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`,
`SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`,
`TRUSTED_PROXIES=*` (safe: only the proxy publishes ports).

## 5. Initial deployment

```sh
# 1. Check out the release on the server (example destination /srv/rev2square).
git clone git@github.com:ssbrandsma/rev2square.git /srv/rev2square
cd /srv/rev2square
git checkout main

# 2. Configure the environment (see section 4).
cp .env.production.example .env.production
chmod 600 .env.production
nano .env.production   # fill DOMAIN, APP_URL, DB_* secrets, API keys

# 3. Build the production images (5-10 min on first run: composer + npm).
docker compose -f compose.prod.yaml --env-file .env.production build

# 4. Record what you shipped (paste into the release notes).
docker images --digests rev2square/app
docker images --digests rev2square/nginx
git rev-parse --short HEAD

# 5. Generate the application key and paste it into .env.production as APP_KEY.
docker compose -f compose.prod.yaml --env-file .env.production run --rm app php artisan key:generate --show

# 6. Start the stack.
docker compose -f compose.prod.yaml --env-file .env.production up -d

# 7. Run migrations (one-off; the entrypoint deliberately does not migrate).
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan migrate --force

# 8. Seed the public demo content (safe: PRD-derived showcase only, no
#    private data — see section 6), then verify.
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan db:seed --force
./docker/ops/status.sh
curl -fsS https://<real domain>/up
```

Expected result: `status.sh` reports all six services running, `/up`
returns 2xx, queue depth 0, no failed jobs, and the landing page loads at
`https://<real domain>/`.

## 6. Demo data (public environment)

The production demo is seeded from `database/seeders/ShowcaseSeeder.php`,
which contains only PRD-derived Bublcam/Squeezebox descriptions plus
planned milestones/tasks. It carries no credentials, no user data, no AI
output, and no experiment claims. `db:seed` is idempotent
(`firstOrCreate`/`updateOrCreate`).

**Never copy the development database to production.** The dev database
holds the real Bublcam AI report, maintainer accounts, and test users; it
is excluded from deployment by design (no dump/restore path ships dev
data, and `restore.sh` only accepts files you hand it explicitly).

The seeder creates one well-known account,
`maintainer@rev2square.test` / `password` (also printed in README for
developers). Immediately after seeding production:

1. Register the real maintainer account through the site UI.
2. Reassign the showcase projects to it and delete the seed account:

```sh
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan tinker --execute="App\Models\Project::query()->update(['owner_id' => App\Models\User::where('email', '<real maintainer email>')->firstOrFail()->id]); App\Models\User::where('email', 'maintainer@rev2square.test')->delete();"
```

3. Verify: `projects/bublcam-revival` and `projects/squeezebox-standalone`
   load for anonymous visitors; `/dashboard` requires login.

Authentication and authorization are unchanged from development: guests
browse published projects, only owners import/analyze/review/publish, and
contribution offers never expose contact details. Nothing is weakened for
the demo.

## 7. Upgrades

```sh
cd /srv/rev2square
git fetch origin
git checkout <new-release-sha>     # never deploy an unreviewed main blindly

# Safety backup before touching anything.
./docker/backup/backup.sh

docker compose -f compose.prod.yaml --env-file .env.production build
docker compose -f compose.prod.yaml --env-file .env.production up -d
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan migrate --force
./docker/ops/status.sh
```

`up -d` recreates only containers whose image or configuration changed.
The `db-data` and `caddy-data` volumes are untouched by rebuilds, so the
database and TLS certificates survive every upgrade. The same holds for
the `app-storage` volume (private user data in `storage/app`, shared by
app and queue): never `down -v`.

First upgrade carrying `app-storage`: each container currently keeps its
own `storage/app`, and the new empty volume would hide those files.
Before recreating, inventory and back up per-container files read-only,
then merge, restore, and verify per the storage migration runbook in
DEPLOYMENT_VIRTUALMIN_ARM64.md section 18 (same steps with
`-f compose.prod.yaml --env-file .env.production`; this variant has no
analysis-coordinator, so inventory covers app and queue only).

## 8. Backups and restore

Dumps live in `./backups/` (chmod 700, git-ignored, timestamped
`rev2square-YYYYMMDD-HHMMSS.sql.gz` with sha256 printed per run).

```sh
# Manual backup (keeps the 14 newest by default).
./docker/backup/backup.sh

# Restore a dump (takes a safety dump first, asks for confirmation,
# then migrates + restarts workers). DESTRUCTIVE to current content.
./docker/backup/restore.sh backups/rev2square-20261101-030000.sql.gz
```

Schedule the daily backup as the deploy user (`crontab -e`):

```
0 3 * * * cd /srv/rev2square && ./docker/backup/backup.sh >> /var/log/rev2square-backup.log 2>&1
```

Off-site copies are an operator step: sync `./backups/` to object storage
or a second host (outstanding decision D4 below). Test every restore on a
scratch project first (`--project` flag), never against live blindly.

## 9. Rollback

Application rollback is a redeploy of the previous release (images are
rebuilt from git, so the previous state is always reproducible):

```sh
cd /srv/rev2square
./docker/backup/backup.sh   # back up the current (bad) state first
git checkout <previous-release-sha>
docker compose -f compose.prod.yaml --env-file .env.production build
docker compose -f compose.prod.yaml --env-file .env.production up -d
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan migrate --force
./docker/ops/status.sh
```

Database rollback is a `restore.sh` of a pre-upgrade dump (section 8).
Migrations in this project are additive; no `migrate:rollback` path is
documented on production — restore the dump instead.

## 10. Monitoring and operations

```sh
# Full read-only status: containers, /up, queue depth, failed jobs,
# recent errors, disk, database size. Exit 1 on any FAIL.
./docker/ops/status.sh

# Live logs.
docker compose -f compose.prod.yaml --env-file .env.production logs -f --tail=100 app queue

# Failed jobs in detail, and retry-all after a fix.
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan queue:failed
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan queue:retry all

# Queue backlog probe (exit != 0 when over threshold).
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan queue:monitor database:default --max=1000

# Graceful worker restart (workers exit after their current job and the
# restart policy revives them).
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan queue:restart

# Maintenance mode around risky operations.
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan down --render="errors::503" --retry=60
docker compose -f compose.prod.yaml --env-file .env.production exec -T app php artisan up
```

What to watch: `/up` non-2xx (app/nginx/db path broken), queue depth
growth (worker down or Meta API slow), failed-job rows (inspect payload
before retry), `storage/logs/laravel.log` errors (also surfaced by
`status.sh`), disk/Caddy-data growth, and MariaDB size trends.

## 11. Troubleshooting

- `proxy` unhealthy / no certificate: DNS must resolve to this host and
  port 80 must be reachable from the internet for the ACME challenge.
  Check `docker compose ... logs proxy` for `acme` errors and
  `curl -v http://<domain>/.well-known/acme-challenge/x` reachability.
- `app` unhealthy after deploy: usually a bad `.env.production`
  (empty `APP_KEY`, wrong `DB_PASSWORD`). Check
  `docker compose ... logs app` — Laravel prints the cause; fix the file
  and `up -d` again (containers are recreated with the new env).
- `db` unhealthy / restart loop: out of disk or corrupted volume. Check
  `df -h`, `docker compose ... logs db`. Last resort: `down`, restore a
  dump into a fresh volume (section 8) — never delete `db-data` without a
  verified backup.
- Queue jobs stuck: `queue:failed` shows poison jobs; `queue:monitor`
  shows backlog. Workers exit hourly by design and restart automatically;
  if `queue` shows `Exited` for more than a few minutes, the restart
  policy may be fighting a crash loop — read `logs queue`.
- Mixed-content / session loss behind the proxy: `TRUSTED_PROXIES` must
  include the proxy path (default `*`), `APP_URL` must be the https
  domain, and `SESSION_SECURE_COOKIE=true`. Misconfiguration shows as
  login loops or http asset URLs.
- Brief 502s right after `up -d` recreates `app`: nginx re-resolves the
  php-fpm upstream through Docker DNS within ~10s by design
  (`resolver 127.0.0.11` in `default.prod.conf`). If 502s persist past a
  minute, force it:
  `docker compose -f compose.prod.yaml --env-file .env.production exec -T nginx nginx -s reload`.
- Out of disk during build: `docker system prune -a --volumes` is
  DESTRUCTIVE (deletes volumes). Prefer `docker image prune -a` (keeps
  volumes) and only after a verified backup.
- `docker images` shows `<none>` tags: a previous `build` was
  interrupted; rebuild and re-check before calling the release done.

## 12. Security checklist

- [ ] `.env.production` is chmod 600, owned by the deploy user, and
      contains no placeholder `CHANGE-ME` values.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`.
- [ ] `APP_KEY` is a generated 32-byte key, unique to this server.
- [ ] `DB_PASSWORD` / `DB_ROOT_PASSWORD` are distinct 32-byte secrets.
- [ ] `MODEL_API_KEY` / `GITHUB_TOKEN` were pasted server-side only and
      appear nowhere in git, images (`docker history`), or logs.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- [ ] Only ports 22/80/443 are reachable from the internet (host
      firewall); MariaDB has no published port.
- [ ] Seed account `maintainer@rev2square.test` deleted (section 6).
- [ ] `status.sh` is green; `/up` answers over public HTTPS.
- [ ] Backups run on cron and at least one restore drill succeeded.
- [ ] Release notes record the git SHA + image digests.

## 13. Outstanding deployment decisions

- D1 VPS host/account: provider, region, and who owns the account.
- D2 Domain: final public hostname (replaces `rev2square.example.com`).
- D3 SSH access: key holders + deploy-user provisioning on the VPS.
- D4 Off-site backup destination for `./backups/` (object storage/host).
- D5 `GITHUB_TOKEN` + `MODEL_API_KEY` ownership: whose tokens fund the
  public demo quota, and rotation responsibility.
- D6 Maintenance window + judging-time support expectations.
