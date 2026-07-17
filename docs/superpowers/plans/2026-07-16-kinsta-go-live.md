# Goetz & Goetz Kinsta Go-Live Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy and verify the approved seven-page Goetz & Goetz WordPress rebuild on Kinsta, then cut `goetzlegal.com` over when authoritative domain access is available.

**Architecture:** Use the existing `manager.sh` release commands for the theme, custom plugin, uploads, and database, but stage the imported database on Kinsta's temporary hostname first. Wrap the destructive import in remote and local backups, fill the discovered WPForms dependency gap, and use serialization-safe WP-CLI URL replacement only after temporary-domain verification succeeds.

**Tech Stack:** Bash, Docker Compose, WordPress/WP-CLI, SSH agent, rsync/scp, Kinsta WordPress hosting, curl/DNS/TLS checks.

## Global Constraints

- Never print, log, pass as a command argument, or copy `SSH_KEY_PW`; use only the already-unlocked temporary SSH agent.
- Target only `goetzgoetz@163.192.209.112:43854` and `/www/goetzgoetz_755/public`.
- Preserve the Kinsta must-use plugin and do not run WordPress core or bulk plugin updates.
- Do not import a database until both the remote backup and its downloaded local copy are verified non-empty.
- Verify the Kinsta temporary domain before changing URLs to `https://goetzlegal.com`.
- Do not submit the public contact form; rendering and client-side structure are sufficient for launch verification.
- Do not infer DNS authority or mutate DNS without confirmed access to the domain controls.

---

### Task 1: Freeze and verify the local release candidate

**Files:**
- Verify: `manager.sh`
- Verify: `wp-content/themes/goetz-legal/dist/.vite/manifest.json`
- Verify: local WordPress database through `manager.sh wp`

**Interfaces:**
- Consumes: the current working tree and running local Docker site.
- Produces: a freshly built theme and a release receipt showing seven pages, the intended homepage, permalink structure, theme, and plugins.

- [ ] **Step 1: Validate the deployment script and rebuild production assets**

Run:

```bash
bash -n manager.sh
./manager.sh theme:build
test -s wp-content/themes/goetz-legal/dist/.vite/manifest.json
```

Expected: all commands exit `0`; Vite reports a successful production build.

- [ ] **Step 2: Verify the local release database**

Run:

```bash
./manager.sh wp post list --post_type=page --post_status=publish --format=count
./manager.sh wp option get show_on_front
./manager.sh wp option get page_on_front
./manager.sh wp option get permalink_structure
./manager.sh wp theme list --status=active --field=name
./manager.sh wp plugin list --status=active --field=name
```

Expected: `7`, `page`, homepage ID `4`, `/%postname%/`, theme `goetz-legal`, and active plugins `goetz-migration`, `wpforms-lite`, and `wordpress-seo`.

### Task 2: Create and verify the Kinsta rollback packet

**Files:**
- Create remotely: `/www/goetzgoetz_755/private/goetz-prelaunch-<timestamp>.sql`
- Create remotely: `/www/goetzgoetz_755/private/goetz-prelaunch-uploads-<timestamp>.tgz`
- Create locally: `__dev/kinsta-backups/goetz-prelaunch-<timestamp>.sql`
- Create locally: `__dev/kinsta-backups/goetz-prelaunch-uploads-<timestamp>.tgz`

**Interfaces:**
- Consumes: the authenticated temporary SSH agent and current Kinsta baseline.
- Produces: recoverable pre-import database and uploads snapshots outside the public web root.

- [ ] **Step 1: Export the remote database and uploads**

Run with a single retained timestamp:

```bash
export SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock
GOETZ_LAUNCH_TS="$(date -u +%Y%m%dT%H%M%SZ)"
ssh -p 43854 goetzgoetz@163.192.209.112 "cd /www/goetzgoetz_755/public && wp db export /www/goetzgoetz_755/private/goetz-prelaunch-${GOETZ_LAUNCH_TS}.sql --add-drop-table && tar -czf /www/goetzgoetz_755/private/goetz-prelaunch-uploads-${GOETZ_LAUNCH_TS}.tgz wp-content/uploads"
```

Expected: WP-CLI reports a successful export and `tar` exits `0`.

- [ ] **Step 2: Download and validate the rollback packet**

Run:

```bash
mkdir -p __dev/kinsta-backups
scp -P 43854 "goetzgoetz@163.192.209.112:/www/goetzgoetz_755/private/goetz-prelaunch-${GOETZ_LAUNCH_TS}.sql" __dev/kinsta-backups/
scp -P 43854 "goetzgoetz@163.192.209.112:/www/goetzgoetz_755/private/goetz-prelaunch-uploads-${GOETZ_LAUNCH_TS}.tgz" __dev/kinsta-backups/
test -s "__dev/kinsta-backups/goetz-prelaunch-${GOETZ_LAUNCH_TS}.sql"
test -s "__dev/kinsta-backups/goetz-prelaunch-uploads-${GOETZ_LAUNCH_TS}.tgz"
gzip -t "__dev/kinsta-backups/goetz-prelaunch-uploads-${GOETZ_LAUNCH_TS}.tgz"
```

Expected: both files are non-empty and the archive integrity check exits `0`.

### Task 3: Deploy code and media with the existing release command

**Files:**
- Deploy: `wp-content/themes/goetz-legal/`
- Deploy: `wp-content/plugins/goetz-migration/`
- Deploy: `wp-content/uploads/`
- Modify remotely: matching paths below `/www/goetzgoetz_755/public/wp-content/`

**Interfaces:**
- Consumes: the built local release candidate and discovered Kinsta connection settings.
- Produces: code/media required by the imported database, without changing the database.

- [ ] **Step 1: Execute `manager.sh deploy:code`**

Run:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock \
KINSTA_SSH_USER=goetzgoetz \
KINSTA_SSH_HOST=163.192.209.112 \
KINSTA_SSH_PORT=43854 \
KINSTA_SITE_PATH=/www/goetzgoetz_755/public \
./manager.sh deploy:code
```

Expected: all three rsync operations exit `0` and `manager.sh` prints `Code deploy complete.`

- [ ] **Step 2: Verify deployed artifacts before database import**

Run:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh -p 43854 goetzgoetz@163.192.209.112 'cd /www/goetzgoetz_755/public && test -s wp-content/themes/goetz-legal/dist/.vite/manifest.json && test -s wp-content/plugins/goetz-migration/goetz-migration.php && find wp-content/uploads -type f | wc -l'
```

Expected: file checks pass and the upload count is at least `61`.

### Task 4: Import the database on the Kinsta temporary domain

**Files:**
- Create locally: `__dev/goetzlegal-db-<timestamp>-for-goetzgoetz.kinsta.cloud.sql`
- Create then remove remotely: `/www/goetzgoetz_755/public/goetzlegal-import.sql`
- Replace remotely: Kinsta WordPress database.

**Interfaces:**
- Consumes: the verified rollback packet, local database, and deployed code/media.
- Produces: the complete rebuilt site addressable at `https://goetzgoetz.kinsta.cloud`.

- [ ] **Step 1: Run the guarded database deployment command**

Run:

```bash
printf 'deploy\n' | SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock \
KINSTA_SSH_USER=goetzgoetz \
KINSTA_SSH_HOST=163.192.209.112 \
KINSTA_SSH_PORT=43854 \
KINSTA_SITE_PATH=/www/goetzgoetz_755/public \
PROD_URL=https://goetzgoetz.kinsta.cloud \
./manager.sh deploy:db
```

Expected: local export, upload, remote import, cache flush, and rewrite flush all exit `0`; the temporary remote SQL file is removed.

- [ ] **Step 2: Restore exact runtime dependencies and configuration**

Run:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh -p 43854 goetzgoetz@163.192.209.112 'set -eu; cd /www/goetzgoetz_755/public; wp plugin is-installed wpforms-lite || wp plugin install wpforms-lite --version=1.10.0.4; wp plugin activate wpforms-lite wordpress-seo goetz-migration; wp theme activate goetz-legal; wp rewrite structure "/%postname%/" --hard; wp rewrite flush --hard; wp cache flush; wp kinsta cache purge --all 2>/dev/null || true; test ! -e goetzlegal-import.sql'
```

Expected: required plugins and theme are active, rewrites/caches flush, and no import dump remains in the public directory.

### Task 5: Verify the Kinsta temporary deployment

**Files:**
- Read remotely: WordPress options, pages, plugins, theme, and debug log.
- Read over HTTPS: the seven public routes and their same-origin assets.

**Interfaces:**
- Consumes: the staged Kinsta rebuild.
- Produces: a pass/fail gate for final-domain URL changes.

- [ ] **Step 1: Verify WordPress state over SSH**

Run:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh -p 43854 goetzgoetz@163.192.209.112 'set -eu; cd /www/goetzgoetz_755/public; test "$(wp option get home)" = "https://goetzgoetz.kinsta.cloud"; test "$(wp option get siteurl)" = "https://goetzgoetz.kinsta.cloud"; test "$(wp post list --post_type=page --post_status=publish --format=count)" = 7; test "$(wp theme list --status=active --field=name)" = goetz-legal; wp plugin is-active goetz-migration; wp plugin is-active wpforms-lite; wp plugin is-active wordpress-seo; wp plugin list --status=must-use --field=name | grep -qx kinsta-mu-plugins; test "$(wp option get permalink_structure)" = "/%postname%/"; test ! -s wp-content/debug.log || ! grep -E "PHP (Fatal|Parse) error" wp-content/debug.log'
```

Expected: every assertion exits `0` and no PHP fatal/parse error is present.

- [ ] **Step 2: Verify all public routes and contact form markup**

Run:

```bash
for path in / /james-l-goetz/ /gregory-w-goetz/ /staff/ /questions/ /links/ /contact/; do curl -fsS -o /tmp/goetz-smoke.html -w "${path} %{http_code}\n" "https://goetzgoetz.kinsta.cloud${path}"; done
curl -fsS https://goetzgoetz.kinsta.cloud/contact/ | grep -q 'wpforms-form'
curl -fsS https://goetzgoetz.kinsta.cloud/ | grep -q 'goetz-legal'
```

Expected: every route returns `200`, the contact page contains WPForms markup, and the home page references the custom theme.

### Task 6: Prepare and execute the public-domain cutover

**Files:**
- Modify remotely: serialized WordPress database URLs, `home`, and `siteurl`.
- External system: Kinsta domain configuration and authoritative DNS for `goetzlegal.com`.

**Interfaces:**
- Consumes: a fully passing temporary-domain deployment and confirmed domain-control access.
- Produces: `https://goetzlegal.com` served from Kinsta.

- [ ] **Step 1: Inspect DNS and Kinsta domain readiness**

Run:

```bash
dig +short A goetzlegal.com
dig +short CNAME www.goetzlegal.com
curl -fsSI https://goetzlegal.com/
```

Expected before cutover: evidence identifies the current provider. Confirm the exact Kinsta DNS target in MyKinsta before changing records.

- [ ] **Step 2: Change WordPress to the final domain immediately before DNS cutover**

Run only when domain control is confirmed:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh -p 43854 goetzgoetz@163.192.209.112 'set -eu; cd /www/goetzgoetz_755/public; wp search-replace "https://goetzgoetz.kinsta.cloud" "https://goetzlegal.com" --all-tables-with-prefix --precise --dry-run; wp search-replace "https://goetzgoetz.kinsta.cloud" "https://goetzlegal.com" --all-tables-with-prefix --precise; wp option update home "https://goetzlegal.com"; wp option update siteurl "https://goetzlegal.com"; wp rewrite flush --hard; wp cache flush; wp kinsta cache purge --all 2>/dev/null || true'
```

Expected: WP-CLI reports replacements and successful option/cache updates.

- [ ] **Step 3: Apply the MyKinsta/DNS changes**

In MyKinsta, add/verify `goetzlegal.com` and `www.goetzlegal.com`, designate the intended primary domain, and apply the exact A/CNAME records Kinsta displays at the authoritative DNS provider.

Expected: MyKinsta shows both domains as verified and SSL issuance begins/completes. If credentials are unavailable, stop here and hand off this exact external action without claiming the site is publicly live.

### Task 7: Verify public launch and close the credential session

**Files:**
- Create: `docs/deployment/2026-07-16-kinsta-go-live-receipt.md`
- Remove from process memory: temporary SSH agent key and socket.

**Interfaces:**
- Consumes: the cut-over public domain.
- Produces: fresh launch evidence and a durable rollback/handoff receipt.

- [ ] **Step 1: Verify DNS, TLS, redirects, pages, assets, and WordPress state**

Run:

```bash
dig +short A goetzlegal.com
curl -fsSIL --max-redirs 5 https://goetzlegal.com/
for path in / /james-l-goetz/ /gregory-w-goetz/ /staff/ /questions/ /links/ /contact/; do curl -fsS -o /tmp/goetz-public-smoke.html -w "${path} %{http_code} %{url_effective}\n" "https://goetzlegal.com${path}"; done
curl -fsS https://goetzlegal.com/contact/ | grep -q 'wpforms-form'
curl -fsS https://goetzlegal.com/robots.txt
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh -p 43854 goetzgoetz@163.192.209.112 'cd /www/goetzgoetz_755/public && wp option get home && wp option get siteurl && wp post list --post_type=page --post_status=publish --format=count && wp theme list --status=active --field=name'
```

Expected: valid HTTPS, no redirect loop, seven `200` route responses on the final hostname, WPForms markup, public indexing allowed, final URL options, seven published pages, and active theme `goetz-legal`.

- [ ] **Step 2: Write the deployment receipt**

Record the UTC deployment time, release commit plus working-tree status, remote target, backup filenames and sizes, build result, temporary and public smoke-test results, DNS/TLS evidence, and rollback commands in `docs/deployment/2026-07-16-kinsta-go-live-receipt.md`. Do not include credentials, database contents, or private values.

Expected: the receipt contains evidence for every success criterion and identifies any external gate that remains.

- [ ] **Step 3: Destroy the temporary credential session**

Run:

```bash
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh-add -D
SSH_AUTH_SOCK=/tmp/goetz-ssh-agent.sock ssh-agent -k
```

Expected: the identity is removed and the SSH agent terminates. Remind the user to delete `SSH_KEY_PW` from `.env`.
