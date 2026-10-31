# Rev2Square — Virtualmin / ARM64 Deployment (WP5B adaptation)

Target: an existing Hetzner Cloud VPS (AlmaLinux 10.x, ARM64/aarch64)
managed by Virtualmin/Webmin, where Apache 2.4 is already the public
webserver with Virtualmin-managed Let's Encrypt certificates.

Status: **preparation and local validation only — the VPS has not been
accessed.** Do not run the server-side commands below until access,
hostnames, and account details are explicitly confirmed.

This variant coexists with the standalone Caddy deployment in
[DEPLOYMENT.md](DEPLOYMENT.md): `compose.prod.yaml` is untouched in
behavior (it only gained log rotation), and both variants build from the
same Dockerfiles. The Caddy service is NOT used in this variant.

Conventions: commands prefixed `[root]#` run as root (or with sudo);
commands prefixed `[vmin]$` may run as the Virtualmin hosting account.
`vc` = `docker compose -f compose.virtualmin.yaml --env-file
/etc/rev2square/.env.virtualmin`. Every command below spells paths out
so copy/paste works without aliases. All server paths use
`/srv/rev2square` as the application root (see section 4), except the
environment file, which lives at `/etc/rev2square/.env.virtualmin`
(see section 8).

```
Internet
  |  HTTPS :443 (Apache + Virtualmin Let's Encrypt)
  v
Virtualmin Apache 2.4  (ProxyPass, header sanitization)
  |  HTTP 127.0.0.1:8081 (loopback only)
  v
Rev2Square Docker Compose (compose.virtualmin.yaml)
  |-- nginx          127.0.0.1:8081 -> :80, static assets + FastCGI
  |-- app            php-fpm (www-data), Laravel
  |-- queue          1 worker, database queue
  |-- scheduler      exactly 1 instance
  +-- db             mariadb:11.8, persistent db-data volume, no ports
```

## 1. Prerequisites

On the VPS (verify, do not assume):

- AlmaLinux 10.x on aarch64, ~4 GB RAM shared with other sites.
- Virtualmin/Webmin installed; Apache 2.4 serving existing virtual
  servers with working Let's Encrypt renewal.
- Host MariaDB/MySQL running for Virtualmin sites (UNTouched by this
  deployment; Rev2Square uses its own container database).
- Docker Engine 27+ with Compose v2.24+ installed and the daemon
  running. Check: `docker version`, `docker compose version`.
- A dedicated Virtualmin account + virtual server for Rev2Square with
  its domain's DNS already pointing at the VPS and a valid LE
  certificate issued through the Virtualmin UI.
- Outbound HTTPS from the VPS to `api.github.com` (evidence import)
  and `api.meta.ai` (AI analysis), plus registry access to Docker Hub
  (base images) at build time.
- `git`, `curl`, `openssl` on the host.

Decisions required before touching the server: final domain, Virtualmin
account name, loopback port if 8081 collides (section 8), whose
`MODEL_API_KEY`/`GITHUB_TOKEN` fund the demo, off-site backup
destination, maintenance window.

## 2. Host compatibility checks

Run as root on the VPS (read-only, safe on a live Virtualmin host):

```sh
[root]# uname -m && cat /etc/almalinux-release
# expect: aarch64 + AlmaLinux release 10.x

[root]# docker version --format '{{.Server.Version}} {{.Server.Arch}}' && docker compose version
# expect: Engine 27+, arch arm64, Compose v2.24+

[root]# docker info --format '{{.Architecture}} CPUs={{.NCPU}} Memory={{.MemTotal}}'
# expect: aarch64; note free RAM before adding ~0.5 GB idle load

[root]# free -m && df -h /var/lib/docker /srv 2>/dev/null | tail -3
# expect: >=1 GB free RAM, >=8 GB free disk for images + volumes + backups

[root]# httpd -v && httpd -M 2>/dev/null | grep -Ei 'proxy_module|proxy_http_module|headers_module|ssl_module'
# expect: Apache 2.4 + all four modules (section 7 if any are missing)

[root]# ss -tlnp | grep -E ':80|:443|:8081' | head
# expect: Apache on :80/:443; :8081 must be FREE (no listener)

[root]# getenforce 2>/dev/null; firewall-cmd --state 2>/dev/null
# note SELinux/firewall state for troubleshooting only; this guide does
# not change either. If SELinux is Enforcing, loopback proxying still
# works, but verify with section 14 after install.
```

Do NOT proceed if Docker is missing/old, port 8081 is taken (pick
another `APP_LOOPBACK_PORT`, section 8), or free RAM is under ~1 GB.

## 3. ARM64 image validation

All production base images are official multi-architecture builds that
ship `linux/arm64/v8` manifests (verified 27 Oct 2026 with
`docker buildx imagetools inspect`):

| Image | ARM64 |
|---|---|
| `php:8.4-fpm` | yes (v8) |
| `composer:2` | yes (v8) |
| `node:22` | yes (v8) |
| `nginx:1.28` | yes (v8) |
| `mariadb:11.8` | yes (v8) |
| `caddy:2` (prod variant only) | yes (v8) |

Dependency audit:

- PHP extensions (`pdo_mysql`, `mbstring`, `zip`, `exif`, `pcntl`,
  `bcmath`, `opcache`, `curl`, `dom`) compile from source inside the
  build — architecture-neutral, no binary blobs.
- Composer packages are pure PHP (Laravel, Livewire, Breeze, Flux);
  `composer.json` pins no platform-specific binaries.
- npm packages resolve per platform at install time. Tailwind CSS v4
  pulls a native Oxide binary, but `npm ci` runs INSIDE the target-arch
  build container, so the correct `linux/arm64` binary is selected
  automatically — on native and cross builds alike.
- No QEMU/emulation is used at runtime: images are built for (or on)
  ARM64 and execute natively.

Re-verify on the VPS (native, authoritative):

```sh
[root]# cd /srv/rev2square
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build
[root]# docker inspect --format '{{.RepoTags}} {{.Architecture}}' rev2square/app:latest rev2square/nginx:latest
# expect: both report arm64
[root]# docker run --rm --entrypoint sh rev2square/app:latest -c 'ls /var/www/.env* 2>&1; ls -d /var/www/vendor /var/www/public/build'
# expect: no .env files; vendor + build present
```

Cross-build procedure (amd64 workstation, validated locally):

```sh
docker buildx create --name rev2arm --driver docker-container
docker buildx --builder rev2arm build --platform linux/arm64 \
  -f docker/php/Dockerfile.prod -t rev2square/app:arm64-check --load .
docker buildx --builder rev2arm build --platform linux/arm64 \
  -f docker/nginx/Dockerfile.prod -t rev2square/nginx:arm64-check --load .
docker inspect --format '{{.RepoTags}} {{.Architecture}}' \
  rev2square/app:arm64-check rev2square/nginx:arm64-check
```

Cross-building proves the Dockerfiles are ARM64-clean; it does NOT prove
ARM64 runtime behavior. Only execution on ARM64 (natively on the VPS, or
explicitly labeled QEMU-emulated execution) counts as runtime validation.

## 4. Recommended directory structure

```sh
[root]# mkdir -p /srv/rev2square && cd /srv/rev2square
```

Everything lives in ONE root-owned directory (NOT inside the Virtualmin
account's `/home/<user>` tree — the vhost account never needs shell
access to the application):

```
/srv/rev2square/
  compose.virtualmin.yaml
  # (environment file lives outside the checkout:
  #  /etc/rev2square/.env.virtualmin, chmod 600 root:root — section 8)
  docker/, app/, ...    # git checkout (root:root, read-only for vmin user)
  backups/              # created by backup.sh, chmod 700 root:root
```

Rationale: Docker volumes (`db-data`) live under `/var/lib/docker`
(root-owned) regardless; keeping the checkout root-owned too means one
consistent permission story and zero interaction with Virtualmin home
quotas, `public_html` (backups can never be web-served from here), or
per-user PHP-FPM pools.

## 5. Docker ownership and security model

Model: **root-managed Docker, zero Docker rights for the hosting account.**

- All `docker compose` / backup / upgrade commands run as root (or via
  root's cron). The Virtualmin account is NEVER added to the `docker`
  group: docker-group membership is root-equivalent (arbitrary
  `--privileged` containers, host filesystem mounts), which is
  unacceptable for an ordinary hosting account on a shared VPS.
- The Virtualmin account needs no application access at all: Apache
  proxies by TCP to loopback, so file ownership never crosses the
  Apache/container boundary. Give the account NO shell access to
  `/srv/rev2square` (default `755` dirs / `644` files from root are
  fine; secrets stay `600`).
- This guide does not change the host Docker daemon configuration.
- Optional read-only status for the site owner without Docker rights:
  add ONE exact-command sudoers rule (visudo), e.g. for account `rev2`:

  ```
  rev2 ALL=(root) NOPASSWD: /srv/rev2square/docker/ops/status.sh --variant virtualmin
  ```

  Exact-command match only — no wildcards, no `--url` (which takes a
  free-form argument). The script is read-only; root owns the file so
  the account cannot modify what sudo executes.

Persistent volumes are Docker-managed (`db-data` under
`/var/lib/docker`), owned by root/container users automatically — no
manual chown, no UID mapping with the Virtualmin account.

## 6. Virtualmin virtual server setup

In the Virtualmin UI (no CLI needed, no existing servers affected):

1. Create the hosting account + virtual server for the Rev2Square
   domain (e.g. `rev2square.example.com`). Any PHP execution mode is
   fine — the vhost serves only as a proxy; its `public_html` stays
   empty (leave Virtualmin's default holding page until cutover, then
   proxying takes over `/`).
2. Issue the Let's Encrypt certificate: Server Configuration -> SSL
   Certificate -> Let's Encrypt. Verify auto-renewal is enabled
   (Virtualmin default monthly cron).
3. Confirm `https://<domain>/` serves the holding page over valid TLS
   BEFORE adding the proxy rules (proves DNS + cert + Apache are
   healthy independent of Rev2Square).
4. Keep Virtualmin's port-80 vhost as generated (ACME challenges must
   keep working for renewals).

## 7. Apache reverse proxy configuration

Full snippet: [docker/virtualmin/apache-vhost-snippet.conf](../docker/virtualmin/apache-vhost-snippet.conf).
Paste it via **Services -> Configure Website -> Edit Directives** into
the `:443` VirtualHost only. Virtualmin preserves Edit-Directives
content across regenerations; never hand-edit the generated vhost files
under `/etc/httpd/conf.d/` directly.

Required modules: `proxy`, `proxy_http`, `headers` (plus `ssl`, already
present). Check: `httpd -M | grep -Ei 'proxy|headers'`; on AlmaLinux
these ship with `httpd` and usually only need their `LoadModule` lines
enabled, then `systemctl reload httpd`.

Behavior contract (what each line is for):

- `ProxyPreserveHost On` + `ProxyPass / http://127.0.0.1:8081/`:
  forward everything to the Compose nginx on loopback.
- `RequestHeader unset X-Forwarded-*` / `Forwarded`: strip
  client-supplied forwarding headers FIRST, so a hostile client cannot
  inject a fake IP/host/proto that Laravel would believe.
- `RequestHeader set X-Forwarded-Proto "https"` (+ port 443):
  mod_proxy never sets these itself; the `:443` vhost guarantees them.
  `X-Forwarded-For`/`X-Forwarded-Host` are re-added by mod_proxy with
  the real observed values.
- `ProxyTimeout 120`: generous for slow clients; AI work runs async in
  the queue worker, never in the web request.
- No WebSocket/`RewriteCond %{HTTP:Upgrade}` rules: Rev2Square has no
  websocket server (verified: no Reverb/Pusher/Echo/broadcast usage;
  `BROADCAST_CONNECTION=log`).

Before pasting (Gate 2 pre-cutover inspection, read-only): dump the live
`:443` vhost and confirm no conflicting proxy/rewrite rules. Expected on
this host (verified Oct 2026): `ProxyPass /.well-known !` (keep),
webmail/admin `RewriteRule` redirects (keep — they redirect before
proxying), and a `FilesMatch \.php$` host-FPM handler (harmless: app
URLs never end in `.php`; literal `*.php` probes keep 404ing on the
host). Our snippet adds no `RewriteRule` and no second `.well-known`
exclusion, so it composes with all three. Do NOT touch other vhosts.

```sh
[root]# httpd -S 2>&1 | grep -A3 'port 443 namevhost <domain>'
[root]# sed -n '<first-line>,/<\/VirtualHost>/p' /etc/httpd/conf/httpd.conf | grep -nE 'ProxyPass|RewriteRule|SetHandler|ServerName|ServerAlias'
```

Verify (as root, after `apachectl configtest && systemctl reload httpd`):

```sh
[root]# curl -fsS https://<domain>/up
[root]# curl -s -o /dev/null -D - https://<domain>/ | grep -i '^set-cookie:'
# expect a session cookie WITH the "secure" attribute (proves Laravel
# sees HTTPS through both proxy layers)
[root]# curl -s -H 'Host: evil.example.com' https://<domain>/ -o /dev/null -w '%{http_code}\n'
# expect: 400 (untrusted Host rejected; see section 19 if Apache itself
# answers 404 first, which is equally safe)
```

## 8. Environment variables and secrets

```sh
[root]# cd /srv/rev2square
[root]# mkdir -p /etc/rev2square
[root]# cp .env.virtualmin.example /etc/rev2square/.env.virtualmin
[root]# chmod 600 /etc/rev2square/.env.virtualmin
[root]# nano /etc/rev2square/.env.virtualmin
```

Set at minimum: `APP_KEY` (after first build, section 9 step 5),
`APP_URL=https://<real domain>`, `DB_PASSWORD` + `DB_ROOT_PASSWORD`
(two distinct `openssl rand -base64 32` values), `MODEL_API_KEY`,
optional `GITHUB_TOKEN`.

Fixed values (already in the template): `APP_ENV=production`,
`APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true`,
`SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES=PRIVATE_SUBNETS`
(Laravel's direct peer is always the in-Compose nginx on a private
subnet), `TRUSTED_HOSTS` empty (= APP_URL host + loopback for
healthchecks; pin explicitly only for extra names),
`APP_LOOPBACK_PORT=8081` (change ONLY if 8081 collides — mirror the
change in the Apache snippet), `DOCKER_PLATFORM=linux/arm64`,
`DEPLOY_VARIANT=virtualmin` (drives deployment-specific owner
commands in admin diagnostics; the standalone template uses `prod`).

Two distinct mechanisms read this one file — do not confuse them.
`--env-file` on the compose command line feeds Compose-file
*interpolation* (`${DB_PASSWORD:?}`, image tags, ports, the
coordinator's explicit allowlist). The services' `env_file:` entries
in compose.virtualmin.yaml feed the *container runtime* of app, queue
and scheduler (absolute `/etc` path, `required: true`). On this
server both point at `/etc/rev2square/.env.virtualmin`; the
coordinator has no `env_file` entry and receives only its interpolated
16-key allowlist.

## 9. Initial installation

```sh
[root]# git ls-remote https://github.com/ssbrandsma/rev2square.git HEAD
# expect: a line ending "HEAD" (anonymous read of the public repo; if this
# fails, fix network/GitHub access before continuing)
[root]# git clone https://github.com/ssbrandsma/rev2square.git /srv/rev2square
[root]# cd /srv/rev2square
[root]# git checkout <release-sha-from-release-notes>   # never an unreviewed main
[root]# git rev-parse --short HEAD   # record in the release notes

[root]# cp .env.virtualmin.example /etc/rev2square/.env.virtualmin
[root]# chmod 600 /etc/rev2square/.env.virtualmin
[root]# nano /etc/rev2square/.env.virtualmin   # section 8 (leave APP_KEY empty for now)

[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build
# native ARM64 build on the VPS, 5-15 min first run

[root]# docker images --digests rev2square/app
[root]# docker images --digests rev2square/nginx
[root]# git rev-parse --short HEAD
# paste all three into the release notes

[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin run --rm app php artisan key:generate --show
# paste the output into /etc/rev2square/.env.virtualmin as APP_KEY

[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan migrate --force
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan db:seed --force
# The seeder creates maintainer@rev2square.test with a PUBLISHED password
# (see README). The stack is still loopback-only, so neutralize it
# immediately, before any cutover ever exposes it:
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan tinker --execute="App\Models\User::where('email','maintainer@rev2square.test')->firstOrFail()->forceFill(['password'=>Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32))])->save(); echo 'seed password randomized'.PHP_EOL;"
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan tinker --execute="echo 'known password still valid: '.(Illuminate\Support\Facades\Hash::check('password',App\Models\User::where('email','maintainer@rev2square.test')->firstOrFail()->password)?'YES-BAD':'no-good').PHP_EOL;"
# expect: "seed password randomized" then "known password still valid: no-good"
# (Re-running db:seed is safe: firstOrCreate never resets the password.)
[root]# ./docker/ops/status.sh --variant virtualmin --url http://127.0.0.1:8081
```

Then add the Apache snippet (section 7) and run its verification. The
Virtualmin host MariaDB is never contacted: `DB_HOST=db` resolves
inside the Compose network only.

## 10. Database migrations and seeding

- Migrate explicitly after every build (the entrypoint deliberately
  does not auto-migrate — concurrent app/queue/scheduler starts would
  race):

  ```sh
  [root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan migrate --force
  ```

- Seed ONLY via `db:seed --force` (idempotent ShowcaseSeeder:
  PRD-derived Bublcam/Squeezebox descriptions, no credentials, no user
  data, no AI output). Then immediately replace the well-known
  `maintainer@rev2square.test` / `password` account: register the real
  maintainer through the site UI and run the reassignment from
  DEPLOYMENT.md section 6 with the virtualmin compose file/env.
- NEVER import the development database and NEVER touch Virtualmin's
  host databases: there is no documented path for either, by design.
  `restore.sh` only restores files you hand it explicitly.

## 11. Starting/stopping services

```sh
[root]# cd /srv/rev2square
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d       # start
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin stop        # stop, keep volumes
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin down        # remove containers, keep volumes
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin restart queue scheduler  # workers
```

Routine stop/start/retreat ALWAYS preserves volumes (`stop` or plain
`down`). `down -v` DESTROYS the database volume: never run it unless
explicitly authorized for that specific occasion, and never without a
verified backup (section 13). Container logs are rotation-capped
(10 MB x 3 per container, both variants) so `up`/`restart` cycles
cannot fill the disk.

## 12. Application upgrades

```sh
[root]# cd /srv/rev2square
[root]# git fetch origin
[root]# git checkout <new-release-sha>
# First upgrade carrying app-storage: run the section 18 storage
# migration runbook BEFORE the build/up below (inventory + back up
# container-layer files first; a new empty volume would hide them).
[root]# ./docker/backup/backup.sh --variant virtualmin   # safety backup first
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build && \
  docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan migrate --force
[root]# ./docker/ops/status.sh --variant virtualmin
```

Volumes survive rebuilds (database + nothing else to persist in this
variant — TLS lives in Apache/Virtualmin). Brief 502s while `app` is
recreated self-heal within ~10s (nginx re-resolves via Docker DNS).

## 13. Backup and restore

```sh
[root]# ./docker/backup/backup.sh --variant virtualmin
# timestamped gzip in ./backups/ (700, root-only), sha256 printed, keeps 14

[root]# ./docker/backup/restore.sh --variant virtualmin backups/rev2square-<ts>.sql.gz
# safety dump first, explicit typed confirmation, then migrate + worker
# restart. DESTRUCTIVE to current content.
```

Cron (root's crontab, `crontab -e` as root):

```
0 3 * * * cd /srv/rev2square && ./docker/backup/backup.sh --variant virtualmin >> /var/log/rev2square-backup.log 2>&1
```

`./backups/` is outside any `public_html`, chmod 700, git-ignored.
Off-site sync (object storage/second host) stays an operator step using
any host tooling — the files are plain gzip dumps. Test restores on a
scratch project (`--project`) before trusting them.

## 14. Health checks

```sh
[root]# ./docker/ops/status.sh --variant virtualmin
# containers (5, no proxy) + /up + queue depth + failed jobs + recent
# errors + disk + DB size. Exit 1 on any FAIL.

[root]# curl -fsS https://<domain>/up          # through Apache (proves the full chain)
[root]# curl -fsS http://127.0.0.1:8081/up     # loopback direct (proves Compose without Apache)
```

If the loopback check passes but the public one fails, the fault is in
the Apache/Virtualmin layer (section 17), not the containers.

## 15. Log inspection

```sh
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin logs -f --tail=100 app queue
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app tail -n 100 storage/logs/laravel.log
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan queue:failed
```

Apache-side access/error logs stay in Virtualmin's usual per-domain
location and are the right place to diagnose TLS/ACME/proxy errors
(`logs proxy` does not exist in this variant).

## 16. Rollback

Application rollback = redeploy the previous release (images rebuild
from git, always reproducible):

```sh
[root]# cd /srv/rev2square
[root]# ./docker/backup/backup.sh --variant virtualmin
[root]# git checkout <previous-release-sha>
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build && \
  docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan migrate --force
[root]# ./docker/ops/status.sh --variant virtualmin
```

Full retreat (pre-cutover): plain `down` preserves the database volume
for later inspection — prefer it over destroying anything:

```sh
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin down
# volumes kept. Destroy them ONLY with explicit authorization:
# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin down -v
```

Database rollback = `restore.sh --variant virtualmin` of a pre-upgrade
dump. Migrations are additive; production never runs `migrate:rollback`.

## 17. Troubleshooting

- Public URL fails but loopback `:8081` works: Apache layer. Check
  `apachectl configtest`, the Edit-Directives snippet (port match!),
  `httpd -M` module list, and the domain's error log. SELinux
  Enforcing + `httpd_can_network_connect` off blocks ProxyPass:
  diagnose with `ausearch -m avc -ts recent`; changing the boolean is
  a host-owner decision, not covered here.
- `/up` ok but login loops / `http` asset URLs: forwarded-proto chain
  broken. Verify `X-Forwarded-Proto "https"` in the vhost,
  `TRUSTED_PROXIES` includes the Docker subnet, `APP_URL` is https,
  `SESSION_SECURE_COOKIE=true`.
- 400 on every page: `TRUSTED_HOSTS`/APP_URL mismatch (the Host
  allowlist rejects the domain). `APP_URL` must be exactly the public
  https host; add aliases via `TRUSTED_HOSTS`.
- `evil.example.com` Host probe returns Apache's 404 instead of the
  app's 400: equally safe (Apache rejected the unknown ServerName
  before proxying). Either outcome proves Host spoofing cannot reach
  Laravel URL generation.
- `db` unhealthy / restart loop: disk pressure or corrupt volume.
  `df -h`, `logs db`; restore to a fresh volume — never delete
  `db-data` without a verified backup.
- Queue backlog: `queue:monitor` / `queue:failed`; workers exit hourly
  by design and auto-restart. `Exited` for minutes = crash loop, read
  `logs queue`.
- Out of disk: `docker system df`; safe reclaims are `docker image
  prune -a` (keeps volumes) and old `./backups/` rotation. Never
  `prune --volumes` without a verified backup.
- High RAM: section 18. Do NOT set hard container memory limits: an
  OOM-killed 16k-token AI job fails silently mid-analysis; prefer
  monitoring + the hourly worker recycle.

## 18. Resource monitoring

Conservative production defaults (verified in the images, not tuned):

| Consumer | Setting | Idle RSS (reference) |
|---|---|---|
| php-fpm pool | dynamic, max 5 children, 256 MB PHP limit | ~45 MB |
| queue worker | 1 process, `--sleep=3`, hourly recycle | ~35 MB |
| scheduler | 1 `schedule:work` process | ~35 MB |
| nginx | stock + static assets | ~15 MB |
| MariaDB | stock 128 MB buffer pool, no custom tuning | ~220 MB |
| OPcache | 128 MB cap, timestamps off | inside php-fpm |

ESTIMATE (not a benchmark): ~400–600 MB idle for the Compose stack
(dev-measured reference: ~350 MB across the same five services; the
`node` dev container's ~240 MB does not exist in production). Under AI
load (16k-token response + queue job + fpm children), allow headroom to
~1.5 GB. On the 4 GB shared VPS this leaves room for Apache, host
MariaDB, and other tenants — but confirm with section 2 checks and keep
an eye on `docker stats` plus host `free -m` during the demo.

No Redis or extra services. No per-container memory limits (an
OOM-killed analysis job is worse than a slow one). Log rotation is
configured (10 MB x 3 per container); application logs stay in the
default single file — rotate host-side if the demo generates volume.

Watch: `docker stats --no-stream`, `./docker/ops/status.sh --variant
virtualmin` (disk + DB size), host `free -m`, `./backups/` growth.

Research-artifact upload path (all four must agree): 64 MiB per file in
Laravel (`artifacts.max_upload_bytes`, admin-tunable), PHP
`upload_max_filesize 64M` / `post_max_size 80M`
(`docker/php/conf.d-uploads.ini`, loaded by php-fpm AND CLI from the
shared `/usr/local/etc/php/conf.d/uploads.ini`; no FPM pool overrides),
nginx `client_max_body_size 80m`
(`docker/nginx/default.prod.conf`), and no `LimitRequestBody` below
83886080 bytes in the Virtualmin Apache templates (default 0 =
unlimited). Aggregate batches are bounded at ~80 MiB: at most 5 files
per request, large files one at a time.

Production PHP-limit fix (observed 2M/8M on a stale image): the Docker
nginx layer is already configured at 80m (see above); only the app image
needs a rebuild to pick up `conf.d-uploads.ini`. A plain `restart` is
NOT enough (new image content requires recreation). Owner-operated, as
root from `/srv/rev2square`:

```sh
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build app && \
  docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d app queue scheduler analysis-coordinator
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app cat /usr/local/etc/php/conf.d/uploads.ini
# expect: upload_max_filesize = 64M, post_max_size = 80M
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php -r 'echo ini_get("upload_max_filesize")," ",ini_get("post_max_size"),"\n";'
# expect: 64M 80M (CLI shares conf.d with php-fpm in this image)
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php-fpm -i | grep -Ei '^(upload_max_filesize|post_max_size) '
# expect: both lines show the same 64M/80M under the FPM SAPI
```

Host outer-proxy caveat: a host `nginx -T | grep client_max_body_size`
(or equivalent) producing NO matching output does NOT prove there is no
outer body limit. The front door on this host is Virtualmin-managed
Apache (`ProxyPass` to 127.0.0.1:8081), not host nginx; Apache's
`LimitRequestBody` (default 0 = unlimited) lives in Virtualmin server
templates and per-vhost directives, which a host-nginx check cannot see.
Before retesting uploads, the owner must confirm in the Virtualmin UI /
generated vhost that no `LimitRequestBody` below 83886080 applies to the
Rev2Square `:443` vhost.

Post-deploy HTTP proof (owner-operated): the PHP setting alone does NOT
prove the entire upload path works. Upload the supplied firmware
(`baby_9.0.2_r17157.bin`, 16,032,273 bytes) through the real public URL
(Draft project -> Agent tab -> Upload), then check it stored byte-intact
(artifact row `ready`, SHA-256 matches the sidecar). If HTTP 413
persists, identify the rejecting layer from scoped logs (narrowest
window covering one retry):

- Docker nginx 413: `docker compose ... logs --since 5m nginx | grep -i 413`
  plus `client_max_body_size` in the baked `/etc/nginx/conf.d/default.conf`.
- Apache outer proxy: the domain's Virtualmin error log around the same
  timestamp (`LimitRequestBody` / proxy errors); loopback-direct POST to
  `http://127.0.0.1:8081/` succeeding while public HTTPS 413s points here.
- PHP-FPM/CLI limits: `php-fpm -i` values above; Laravel log
  `storage/logs/laravel.log` showing the controller's "exceeds the server
  request limit" (post_max_size) vs per-file "the limit is 64.0 MiB"
  (app cap) vs `UPLOAD_ERR_INI_SIZE` mapping.

Do not edit production files or running containers by hand; all changes
arrive via the next `build && up -d` from a reviewed git SHA.

File-mode follow-up (Permission denied on uploads.ini inside the
container, `php --ini` omitting uploads.ini, effective 2M/8M despite a
correct host file): `COPY` preserves host checkout bits, so a umask-077
build host baked root-only 600 ini files that the www-data runtime user
cannot read. Fixed by `COPY --chmod=0644` on both conf.d ini files
(uploads + opcache; root-owned, non-writable by the runtime user, no
broadening of secrets or storage) plus a build-time gate that runs as
www-data and fails the image unless uploads.ini is readable, scanned,
and effective (64M/80M) under both CLI and php-fpm. Same owner rebuild
as above; after it, `cat .../uploads.ini` must succeed (no Permission
denied), `php --ini` must list uploads.ini, and `php-fpm -i` must show
64M/80M. A `restart` without `build` cannot fix baked-in modes.

Shared private storage (`app-storage` volume): `storage/app` (research
artifacts, presentation media, avatars, attachments, imports) is a
named volume mounted read-write on exactly the services that read or
write it — `app` (uploads, viewers), `queue` (extraction snapshots,
media derivatives), `analysis-coordinator` (analysis evidence,
promoted children). Sessions/cache/queue are database-backed, so
`storage/framework` and logs intentionally stay per-container.
`scheduler` only touches the database and dispatches jobs, so it stays
off the mount; isolated runners keep only their per-job spool inputs
(no artifact or app-secret mounts). Every mounted service runs as
www-data and the image pre-chowns `/var/www/storage` to www-data, so
the volume preserves uid 33 with private modes and no chmod. Without
this mount each container kept its own `storage/app`: web uploads were
invisible to workers (`missing_input`/`missing_original` within
seconds) and any recreation lost them. Integration proof on disposable
infrastructure: `docker/ops/verify-shared-storage.sh` (HTTP upload →
separate-worker extraction → viewer/reload → recreation retention).

Storage migration runbook — REQUIRED before the first recreation with
`app-storage` (a new empty volume hides container-layer files, so
inventory and back up FIRST; never `down -v`, prune, delete
containers/volumes, or auto-delete failed rows):

```sh
[root]# cd /srv/rev2square
# 0. Read-only inventory: what lives where right now.
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin ps
[root]# for s in app queue analysis-coordinator; do echo "== $s"; docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T $s sh -c 'find /var/www/storage/app -type f | head -50; echo; du -sh /var/www/storage/app'; done
[root]# docker inspect $(docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin ps -q app) --format '{{json .Mounts}}'
# expect: no app-storage yet (first migration); note any anonymous volumes
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan tinker --execute="foreach (\App\Models\ProjectArtifact::orderBy(\"id\")->get() as \$a) echo \$a->id.' '.\$a->state.' '.\$a->byte_size.' '.\$a->sha256.' '.\$a->rawPath().PHP_EOL;" > /root/artifact-rows-$(date +%Y%m%d).txt
[root]# ./docker/backup/backup.sh --variant virtualmin   # database safety backup
# 1. Back up per-container files (each container holds DIFFERENT pieces:
# app = raw uploads, queue = snapshots, coordinator = evidence/children).
[root]# ts=$(date +%Y%m%d-%H%M%S); mkdir -p backups/storage-app-$ts
[root]# for s in app queue analysis-coordinator; do cid=$(docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin ps -q $s); docker cp $cid:/var/www/storage/app backups/storage-app-$ts/$s; done
[root]# find backups/storage-app-$ts -type f | sort | xargs sha256sum > backups/storage-app-$ts/SHA256SUMS; wc -l backups/storage-app-$ts/SHA256SUMS
# 2. Deploy (recreates app/queue/coordinator with the new empty volume).
[root]# git fetch origin && git checkout <sha-with-app-storage>
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build app && docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d
# 3. Merge the three trees (disjoint names expected: raws vs snapshots
# vs evidence) and restore into the volume as www-data (no root chown
# needed: exec runs as the compose user).
[root]# cd /srv/rev2square/backups/storage-app-$ts && rm -rf merged && mkdir merged
[root]# for s in app queue analysis-coordinator; do (cd $s && tar cf - .) | (cd merged && tar xkf -); done
[root]# echo "merged: $(find merged -type f | wc -l) source: $(find app queue analysis-coordinator -type f | wc -l)"
# expect equal counts (no collisions); stop and inspect if they differ
[root]# cd /srv/rev2square && (cd backups/storage-app-$ts/merged && tar cf - .) | docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app tar xf - -C /var/www/storage/app
# 4. Verify SHA-256 per row, then retry failed rows from the UI.
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan artifacts:verify-storage
# Rows whose file is back report "retry from the artifact page": open
# each failed artifact/analysis and press Retry (extraction re-runs;
# analyses failed missing_original/storage_unreadable are retryable).
# Re-run verify-storage until clean. Re-upload is the last resort,
# only for rows whose bytes exist nowhere.
```

Recovery/retry behavior after storage is restored: extraction rows
failed `missing_input`/`storage_unreadable` show Retry extraction
(which re-queues once the file is back); analyses failed
`missing_original`/`storage_unreadable` are retryable via Retry run;
viewers show an amber "temporarily unavailable" notice (never a 500)
while snapshots are missing and go full on reload. Missing vs
permission-denied is distinguished in operator logs and failure codes;
public messages stay generic and retry-first. `artifacts:verify-storage`
is the pre/post check; it never modifies rows or files.

Spool/results volume ownership (coordinate with the storage migration
above — same window, uploads first): the `analysis-spool` /
`analysis-results` named volumes were first created by images that did
not contain `/spool` or `/results`, so Docker created them root-owned
and the www-data coordinator/runners cannot stage jobs (probes fail,
snapshots stay missing, artifact pages warn). All six images now
pre-create both dirs www-data-owned, which fixes FRESH volumes; the
two EXISTING volumes need a one-time re-own. They hold only transient
job traffic (no uploads), but do this strictly AFTER the upload
inventory/backup above and BEFORE any recreation:

```sh
[root]# cd /srv/rev2square
# 0. Uploads inventoried + backed up per the storage runbook above FIRST.
# 1. Drain analysis: queue nothing new; wait for in-flight runs (they
#    are retryable if lost).
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T analysis-coordinator php artisan analysis:diagnostics
# expect: Pending spool submissions 0 (re-run until drained)
# 2. Confirm the exact volume names (project-scoped).
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin config --volumes
# expect: analysis-spool analysis-results app-storage db-data (+ live names
# rev2square-virtualmin_analysis-spool / rev2square-virtualmin_analysis-results)
# 3. One-shot re-own of the two TRANSIENT volumes only (contents kept).
[root]# docker run --rm --user root --entrypoint chown -v rev2square-virtualmin_analysis-spool:/spool -v rev2square-virtualmin_analysis-results:/results rev2square/app:latest 33:33 /spool /results
[root]# docker run --rm --user www-data --entrypoint sh -v rev2square-virtualmin_analysis-spool:/spool -v rev2square-virtualmin_analysis-results:/results rev2square/app:latest -c 'touch /spool/.w && rm /spool/.w && touch /results/.w && rm /results/.w && echo SPOOL-WRITABLE'
# expect: SPOOL-WRITABLE
# Fallback (only if the re-own cannot run): after the drain, remove and
# recreate JUST these two empty volumes — never app-storage/db-data:
#   docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin stop analysis-coordinator objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner
#   docker volume rm rev2square-virtualmin_analysis-spool rev2square-virtualmin_analysis-results
# 4. Deploy (rebuilds app + runners with the ownership fix and the jadx
#    fixture), recreate, restore app-storage, then verify.
[root]# git fetch origin && git checkout <sha-with-spool-fix>
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin build app objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner && docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin up -d
# (analysis-coordinator shares the app image, so `build app` covers it)
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T app php artisan artifacts:verify-storage
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T analysis-coordinator php artisan analysis:diagnostics
# 5. Refresh snapshots from the coordinator and confirm runners ready.
[root]# docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin exec -T analysis-coordinator php artisan binary:worker-capabilities --refresh
# wait up to ~7 minutes (worst case: dead runners burn full probe
# budgets totalling ~6 minutes; live runners answer in seconds
# except first-start JADX qualification, which runs the full
# bounded fixture check), then re-run analysis:diagnostics: every
# installed backend ready (jadx ready requires its DEX fixture
# pass). One refresh completes cold start;
# repeated manual refreshes are not part of the protocol (hourly
# scheduled refreshes keep snapshots fresh afterwards). Poisoned
# snapshots captured pre-fix from spool-less containers heal on this
# refresh automatically; no manual row cleanup.
```

Producer/consumer flow (intended): the coordinator stages job specs
into `/spool` (write) and collects `/results` (write for claims
cleanup); runners read the spool read-only and write results; the web
tier NEVER mounts either volume and reads coordinator status purely
via the `worker_capabilities` DB snapshots (hourly refresh on the
coordinator queue, or `--refresh` on demand). Do not mount the spool
into app/queue/scheduler merely to silence warnings.

## 19. Known limitations

1. ARM64 runtime was validated by cross-build + manifest inspection and
   (if available) QEMU-emulated execution — see the validation report
   for exactly which level was reached. First native ARM64 execution
   happens on the VPS; section 3's on-server checks are the gate.
2. The loopback port (default 8081) is reachable by any host-local
   process, including other Virtualmin tenants' scripts. They cannot
   read secrets (all server-side), but a hostile local process COULD
   send requests with spoofed `X-Forwarded-*` headers directly to
   nginx, bypassing Apache's sanitization — polluting IP-based log data
   and weakening IP components of throttles (login throttle keeps its
   per-email component). No Host spoofing impact (allowlist rejects
   unknown hosts). Mitigation options if tenants are untrusted: host
   firewall loopback-egress review, per-tenant proxy tokens (not
   implemented — see report), or moving the port behind an
   Apache-only unix socket (future work).
3. Single-node, single-queue-worker deployment: no horizontal scaling;
   adequate for demo/judging traffic, not for production growth.
4. `mail=log`: no outbound email (password resets print to the log).
   Wire Virtualmin-managed SMTP later if accounts need email.
5. No APM/metrics endpoint beyond `/up`, `queue:monitor`, and
   `status.sh`. No log shipping; logs live in containers/host files.
6. Virtualmin UI actions that rewrite the vhost (domain rename, cert
   reissue flows) preserve Edit-Directives content but should be
   followed by re-running the section 7 verification.
7. The `:80` vhost is intentionally NOT proxied: ACME HTTP-01 must keep
   working for renewals. Plain-HTTP app URLs redirect per Virtualmin's
   own "redirect to SSL" website option — enable it in the UI.
