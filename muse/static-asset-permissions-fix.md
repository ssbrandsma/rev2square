# Static-asset permissions fix — production nginx image

## 1. Confirmed root cause and relevant build paths

**Root cause.** `docker/nginx/Dockerfile.prod` copied the public document
root into the image with no permission normalization. `COPY` preserves
host mode bits, and the production checkout under `update_prod.sh`
(`umask 077`) creates newly introduced files as `600` and directories as
`700`, owned by root. The nginx master process runs as root but its
workers run as the unprivileged `nginx` user, so they could neither
traverse `/var/www/public/images` (`700`) nor read the SVGs (`600`).
`docker/nginx/default.prod.conf` serves statics via
`location / { try_files $uri $uri/ /index.php?$query_string; }`, so the
failed stat fell through to Laravel, which answered **404** — matching
the observed symptom exactly (a 404 page, not a 403).

**Why host-side repair + rebuild did not help.** Docker `COPY` layers key
on file content, not mode bits, so rebuilding after a host `chmod`
reused the cached layers still carrying `600`/`700`. This matches the
observed "container retained the inaccessible directory".

**Build paths (verified in the repo).**

- Both production variants build the same images: `compose.prod.yaml`
  and `compose.virtualmin.yaml` both use
  `docker/nginx/Dockerfile.prod` (nginx) and
  `docker/php/Dockerfile.prod` (app/queue/scheduler). One Dockerfile
  fix covers both variants. Dev (`compose.dev.yaml`,
  `docker/php/Dockerfile`) uses bind mounts and is unaffected.
- Nginx document root: `/var/www/public` (Dockerfile `COPY` target and
  `default.prod.conf` `root` directive agree).
- COPYs populating the document root: `COPY public /var/www/public`
  (line 28) and `COPY --from=assets /src/public/build
  /var/www/public/build` (line 29, generated Vite output). The fix is
  placed after both.
- The PHP application image needs **no change**:
  `docker/php/Dockerfile.prod:83` already runs
  `chmod -R a+rX /var/www` after all three COPYs (lines 70–72), then
  `chown`s only the writable dirs (`storage`, `bootstrap/cache`,
  `.config`) to `www-data`. `a+rX` only adds read/traverse, so existing
  executable bits (e.g. `artisan`) are preserved.
- `update_prod.sh` is **absent from the repository**: not tracked, no
  file, no references (`git status` was clean before this work). It is
  owner-local on the production host. No contents were invented; §5
  gives a reviewed wiring snippet instead of a patch.

## 2. Changed files and rationale

- `docker/nginx/Dockerfile.prod` (+11 lines): after the last `COPY`
  into the document root,
  `RUN find /var/www/public -type d -exec chmod 755 {} + &&
  find /var/www/public -type f -exec chmod 644 {} +`.
  Rationale: deterministic `755`/`644` regardless of checkout umask;
  the new layer applies over any cached `COPY`, so normal
  cache-enabled builds are fixed with no `--no-cache`, host `chmod`,
  or container-startup hooks. Plain `find` (no `-L`/`-follow`, and
  `-type d`/`-type f` never match symlinks) neither follows nor alters
  symlinks, so nothing outside the document root is reachable. No
  `chmod 777`; scope is limited to `/var/www/public`.
- `docker/ops/check-static-assets.sh` (new, mode 755, POSIX `sh`,
  `status.sh` argument conventions): deploy-time gate for
  `/images/hero-board.svg` (marker `heroBoardTitle`) and
  `/favicon.svg` (marker `faviconTitle`). Each asset must return
  exact HTTP 200 with redirects NOT followed (login redirects fail),
  an `image/svg+xml` content type (Laravel HTML fallbacks fail), and
  `<svg` plus its expected marker in a size-bounded body (wrong-file
  or generic 200s fail). Markers verified unique to their files.
  Prints status lines only — no bodies, no secrets. `--url` mode
  needs no env file, so loopback and scratch-container checks take
  no credentials.
- `tests/docker/check-nginx-public-permissions.sh` (new, mode 755):
  regression test. Builds the real `Dockerfile.prod` against a
  poisoned context copy (public files `600`, dirs `700`,
  `.dockerignore` honored), then asserts deterministic `755`/`644`
  modes, readability/traversal **as the `nginx` user**
  (uid asserted, never root alone), SVG markers plus generated
  `build/manifest.json` content, and HTTP serving through
  `check-static-assets.sh` against a scratch container.
  `--no-cache` is used only inside this test so the poisoned modes
  are forced into the `COPY` layers (content-keyed cache could
  otherwise make the test vacuous); the Dockerfile fix itself is
  cache-safe in production.
- Deliberately unchanged: PHP Dockerfile (already normalized),
  `default.prod.conf` (routing correct; `.svg` content type comes
  from stock `mime.types`), compose files, `status.sh` (exact-command
  sudo-pinned; behavior untouched), deployment guides (commands
  unchanged), and the absent `update_prod.sh` (see §5 snippet).

**Security preserved.** `umask 077` in the deploy flow is untouched;
`.dockerignore` still excludes `.env*`, `storage/*`, `backups`, and
`tests`, so no credentials, backups, logs, or private artifacts enter
images or gain broader modes. No new public exposure: only modes
inside the already-public document root are normalized.

## 3. Tests performed and results

- `sh -n` syntax check on both new scripts: pass.
- Executable bits match repo convention (`100755`, like
  `status.sh`/`backup.sh`).
- Marker uniqueness: `heroBoardTitle` only in
  `public/images/hero-board.svg`; `faviconTitle` only in
  `public/favicon.svg`.
- `check-static-assets.sh` scenario suite (throwaway fake-`curl`
  harness in `/tmp/assettest`, kept out of the repo): **9/9 pass** —
  positive case with the real repo SVG files exits 0 with two PASS
  lines; Laravel-404, HTML-with-200, wrong-SVG-without-marker,
  302-redirect, and connection-refused cases each exit 1 with the
  correct FAIL reason; `--help`/bad-variant/missing-env exits
  correct. Trap cleanup verified (no temp-dir residue).
- Regression-test precondition paths: `--help` exits 0; without a
  docker CLI it exits 2 with a clear message.
- **Full Docker run of `tests/docker/check-nginx-public-permissions.sh`
  (29 Oct 2026, local Docker): PASS, exit 0, all 10 checks green.**
  The real `Dockerfile.prod` built cleanly against the poisoned
  `600`/`700` context (build log shows the normalizing RUN as final
  layer `stage-1 6/6`); image modes deterministic (`755`/`644`);
  readability probe asserted to run as user `nginx` (uid checked,
  not assumed); every public path readable/traversable by that
  user; SVG markers plus generated `build/manifest.json` readable
  unprivileged; scratch container served `/images/hero-board.svg`
  (HTTP 200, `image/svg+xml`, 3812 bytes) and `/favicon.svg`
  (HTTP 200, `image/svg+xml`, 429 bytes) via
  `check-static-assets.sh`. The first run exposed a test-script
  cleanup flaw (scratch container still running blocked `docker rmi`
  on success); fixed by stopping the container before image removal
  and re-run green with zero residue (image removed, no stray
  container).
- No PHP changed, so no PHPUnit run was required; no repo-wide gate
  covers shell scripts (no CI in the repo). Nothing was committed,
  pushed, or deployed; production was not touched and no secrets
  were read.

## 4. Unverified checks

- `shellcheck` on the new scripts (not installed here; both follow
  the POSIX patterns of the existing `status.sh`, and both pass
  `sh -n` plus their functional runs above). Optional on a host
  that has it: `shellcheck docker/ops/check-static-assets.sh
  tests/docker/check-nginx-public-permissions.sh`.
- Production deployment and post-deploy validation (§5):
  owner-operated only. Re-running the regression test on demand
  from the repo root: `tests/docker/check-nginx-public-permissions.sh`
  (options: `--port 18081`, `--keep-image` for forensics).

## 5. Owner-operated production deployment and validation

Production reality (authoritative here, differs from the guides):
checkout `/srv/rev2square`, compose file `compose.virtualmin.yaml`,
env file `/etc/rev2square/.env.virtualmin` (the guides show a
checkout-local `.env.virtualmin`; use the `/etc` path on this host).
All commands as root from `/srv/rev2square`.

**Deploy.**

```sh
cd /srv/rev2square
git fetch origin
git checkout <sha-containing-this-fix>
git status --short   # expect clean except this change set
grep -n 'find /var/www/public' docker/nginx/Dockerfile.prod  # expect the RUN lines
```

Then run `update_prod.sh` normally. **Yes, a normal execution picks
up the fix**: its existing build step bakes the new normalizing
layer, which applies over any cached `COPY` layers — no `--no-cache`
is required. (Optional belt-and-braces, not required:
`docker compose -f compose.virtualmin.yaml --env-file
/etc/rev2square/.env.virtualmin build --no-cache nginx`.)

**Wire the new gate into `update_prod.sh`.** Append after the
existing migration/health/login/Vite checks (reviewed addition to
the owner-local script — adjust nothing else):

```sh
# Static-asset gate: loopback proves the containers; APP_URL proves the full chain.
./docker/ops/check-static-assets.sh --url "http://127.0.0.1:8081" || exit 1
./docker/ops/check-static-assets.sh --variant virtualmin --env-file /etc/rev2square/.env.virtualmin || exit 1
```

(If `APP_LOOPBACK_PORT` is customized, use that port in the first
line. Existing checks stay as they are; no destructive rollback
was added.)

**Post-deploy verification (runtime permissions + both URLs).**

```sh
C="docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin"

# 1. No non-normalized modes anywhere under the served document root (expect no output).
$C exec -T nginx sh -c 'find /var/www/public -type d ! -perm 755 -print; find /var/www/public -type f ! -perm 644 -print'

# 2. Unprivileged worker user reads the previously broken asset (expect UNPRIVILEGED-READ-OK).
$C exec -T -u nginx nginx sh -c 'grep -q heroBoardTitle /var/www/public/images/hero-board.svg && grep -q faviconTitle /var/www/public/favicon.svg && echo UNPRIVILEGED-READ-OK'

# 3. Both URLs through the containers and through the public chain (expect all PASS, exit 0).
./docker/ops/check-static-assets.sh --url "http://127.0.0.1:8081"
./docker/ops/check-static-assets.sh --variant virtualmin --env-file /etc/rev2square/.env.virtualmin

# 4. Usual status overview (expect exit 0).
./docker/ops/status.sh --variant virtualmin --env-file /etc/rev2square/.env.virtualmin
```

**When app images also require rebuilding.** This fix touches the
nginx image only, but `update_prod.sh` already rebuilds app+nginx
together — keep that. Rebuild the app image whenever PHP code,
config, or Composer dependencies change, as before; no app-image
permission work is needed (already normalized, §1).

**Acceptance.** A normal, cache-enabled production build now serves
`/images/hero-board.svg` and `/favicon.svg` correctly even when its
source checkout was created under `umask 077`.

## 6. Remaining questions (separate; no security weakened)

- **SELinux.** Unchanged by this fix. If Enforcing +
  `httpd_can_network_connect` off blocks Apache proxying, the
  signature is *all* public URLs failing while loopback `:8081`
  works (docs §17). Diagnose with `ausearch -m avc -ts recent`;
  any boolean change is a host-owner decision. Do not disable
  SELinux for this fix — it is unrelated to container file modes.
- **Apache routing.** The vhost snippet (`ProxyPass /
  http://127.0.0.1:8081/`) already forwards `/images/*` and
  `/favicon.svg`; no vhost change is needed. If loopback serves the
  SVGs but the public URL does not, the fault is in the
  Apache/Virtualmin layer (port match, `proxy`/`proxy_http`/
  `headers` modules, Edit-Directives placement), not the
  containers — verify per the deployment guide §§7/14.
- **Rollback** remains the standard previous-SHA redeploy from the
  guide (§16); no automatic destructive rollback was introduced.
