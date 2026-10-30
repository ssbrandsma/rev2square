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
.env.virtualmin`. Every command below spells paths out so copy/paste
works without aliases. All server paths use `/srv/rev2square` as the
application root (see section 4).

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
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin build
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
  .env.virtualmin        # chmod 600 root:root, all secrets (section 8)
  compose.virtualmin.yaml
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
[root]# cp .env.virtualmin.example .env.virtualmin
[root]# chmod 600 .env.virtualmin
[root]# nano .env.virtualmin
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
change in the Apache snippet), `DOCKER_PLATFORM=linux/arm64`.

## 9. Initial installation

```sh
[root]# git ls-remote https://github.com/ssbrandsma/rev2square.git HEAD
# expect: a line ending "HEAD" (anonymous read of the public repo; if this
# fails, fix network/GitHub access before continuing)
[root]# git clone https://github.com/ssbrandsma/rev2square.git /srv/rev2square
[root]# cd /srv/rev2square
[root]# git checkout <release-sha-from-release-notes>   # never an unreviewed main
[root]# git rev-parse --short HEAD   # record in the release notes

[root]# cp .env.virtualmin.example .env.virtualmin
[root]# chmod 600 .env.virtualmin
[root]# nano .env.virtualmin   # section 8 (leave APP_KEY empty for now)

[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin build
# native ARM64 build on the VPS, 5-15 min first run

[root]# docker images --digests rev2square/app
[root]# docker images --digests rev2square/nginx
[root]# git rev-parse --short HEAD
# paste all three into the release notes

[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin run --rm app php artisan key:generate --show
# paste the output into .env.virtualmin as APP_KEY

[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan migrate --force
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan db:seed --force
# The seeder creates maintainer@rev2square.test with a PUBLISHED password
# (see README). The stack is still loopback-only, so neutralize it
# immediately, before any cutover ever exposes it:
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan tinker --execute="App\Models\User::where('email','maintainer@rev2square.test')->firstOrFail()->forceFill(['password'=>Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32))])->save(); echo 'seed password randomized'.PHP_EOL;"
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan tinker --execute="echo 'known password still valid: '.(Illuminate\Support\Facades\Hash::check('password',App\Models\User::where('email','maintainer@rev2square.test')->firstOrFail()->password)?'YES-BAD':'no-good').PHP_EOL;"
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
  [root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan migrate --force
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
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin up -d       # start
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin stop        # stop, keep volumes
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin down        # remove containers, keep volumes
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin restart queue scheduler  # workers
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
[root]# ./docker/backup/backup.sh --variant virtualmin   # safety backup first
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin build && \
  docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan migrate --force
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
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin logs -f --tail=100 app queue
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app tail -n 100 storage/logs/laravel.log
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan queue:failed
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
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin build && \
  docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin up -d
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin exec -T app php artisan migrate --force
[root]# ./docker/ops/status.sh --variant virtualmin
```

Full retreat (pre-cutover): plain `down` preserves the database volume
for later inspection — prefer it over destroying anything:

```sh
[root]# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin down
# volumes kept. Destroy them ONLY with explicit authorization:
# docker compose -f compose.virtualmin.yaml --env-file .env.virtualmin down -v
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
`upload_max_filesize 65M` / `post_max_size 80M`
(`docker/php/conf.d-uploads.ini`), nginx `client_max_body_size 80m`
(`docker/nginx/default.prod.conf`), and no `LimitRequestBody` below
83886080 bytes in the Virtualmin Apache templates (default 0 =
unlimited). Aggregate batches are bounded at ~80 MiB: at most 5 files
per request, large files one at a time.

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
