# Goetz & Goetz Production Release Runbook

This runbook deploys only the approved WordPress runtime roots to the fixed Goetz & Goetz Kinsta site. It deliberately excludes WordPress core, Kinsta must-use plugins, uploads from the code payload, root development dependencies, and every local secret.

## Safety boundary

The fixed deployment target is:

- SSH endpoint: `goetzgoetz@163.192.209.112:43854`
- WordPress root: `/www/goetzgoetz_755/public`
- Private backups: `/www/goetzgoetz_755/private/backups/<backup-id>/`
- Private releases: `/www/goetzgoetz_755/private/releases/<commit>/`
- Staging origin: `https://goetzgoetz.kinsta.cloud`
- Production origin: `https://goetzlegal.com`

Every SSH and rsync operation ignores user SSH configuration with `-F /dev/null`, requires `StrictHostKeyChecking=yes` and an explicit `KINSTA_KNOWN_HOSTS_FILE`, disables agent/port/environment forwarding and proxy commands, and uses bounded connection/keepalive timeouts. Verify the `[163.192.209.112]:43854` fingerprint against authenticated MyKinsta site details before use. Never establish trust from an unauthenticated `ssh-keyscan` result.

Remote scripts also require an already-unlocked, isolated `SSH_AUTH_SOCK`. Manager release dispatch uses a clean environment containing only the fixed Kinsta connection fields, `SSH_AUTH_SOCK`, and a minimal `HOME`/`PATH`; release children immediately unset `SSH_KEY_PW`. They never read `.env`, handle a passphrase, or forward repository environment files. Destroy the isolated agent whenever deployment work pauses and after launch verification completes.

## Deterministic release

Release builds refuse all of the following:

- a dirty worktree, including untracked files;
- a branch other than `main`;
- a requested commit different from `HEAD`;
- a requested commit different from the locally recorded `origin/main`.

Build and verify the exact pushed commit:

```bash
release_sha="$(git rev-parse HEAD)"
./manager.sh release:build "$release_sha"
./manager.sh release:verify "__dev/releases/$release_sha"
```

The builder starts from `git archive <commit>`, installs Composer dependencies with production flags, uses `npm ci`, builds theme and Gutenberg assets, and assembles only:

```text
wp-content/themes/goetz-legal/
wp-content/plugins/goetz-site/
wp-content/plugins/goetz-migration/
wp-content/plugins/wordpress-seo/
wp-content/plugins/wpforms-lite/
release.json
RELEASE-MANIFEST.sha256
```

`release.json` records the source commit, deterministic commit time, runtime compatibility, exact plugin versions, and lockfile hashes. It intentionally has no aggregate self-hash. `RELEASE-MANIFEST.sha256` hashes every other payload file, including `release.json`; the SHA-256 of that completed manifest is the aggregate digest recorded in the private launch receipt.

Run the builder twice before deployment and compare the file list, every file hash, and the completed manifest hash. Any difference blocks deployment.

## Coupled pre-deployment backup

Create a release-coupled backup before any code, database, or URL mutation:

```bash
./manager.sh remote:backup \
  --purpose=pre-deployment \
  --release-dir="__dev/releases/$release_sha"
```

Record the returned backup ID. The mode-0700 remote packet and its mode-0600 files contain:

- a WordPress database export;
- an uploads archive;
- a tarball for each existing allowlisted code root, with absent roots recorded explicitly;
- the active theme, active plugins (an empty file is valid), must-use plugin inventory, home URL, site URL, WordPress version, and prior tracked-release state;
- strict metadata containing the backup purpose, UTC creation time, exact staging origin, intended release commit, and release-manifest digest;
- `SHA256SUMS` covering the complete packet.

The command downloads the packet to ignored `__dev/kinsta-backups/<backup-id>/`, verifies each file locally, compares the local manifest digest with the remote digest, and writes a strict local verification receipt coupled to the same purpose/release metadata. A deployment, cutover, or rollback refuses a packet whose local or remote digest, purpose, release digest, origin, or schema no longer matches.

## Allowlisted staging deployment

Deploy the verified payload with the backup ID from the preceding step:

```bash
./manager.sh remote:deploy \
  --release-dir="__dev/releases/$release_sha" \
  --backup-id=<pre-deployment-backup-id>
```

The release is uploaded to the commit-specific private directory and verified there before application. Synchronization uses `--delete-delay` only inside each named allowlisted theme or plugin directory. The deployment never targets `/wp-content/plugins/`, `/wp-content/mu-plugins/`, `/wp-content/uploads/`, `wp-admin`, `wp-includes`, or the WordPress root.

Private uploads are resumable in one commit/backup-specific incoming directory. The incoming directory and payload destination are physically validated and rejected if any symlink is present before rsync receives the destination. The completed payload is checksum-verified and published with one directory rename; an existing verified release is safely reused. The shared remote mutation lock serializes backup, deployment, cutover, rollback, and read verification.

Before the first runtime write, deployment verifies every source/target physical path, rejects symlinked parents or named roots, rejects multisite, verifies the complete release and backup packets, pre-extracts the recovery packet, records the current debug-log inode/offset, and writes a durable phase receipt. The remote application order is:

1. verify the release and coupled backup manifests;
2. deploy and activate `goetz-site`;
3. deploy the theme, migration plugin, Yoast SEO 28.0, and WPForms Lite 1.10.0.4 as separate roots;
4. validate generated files and exact runtime versions;
5. activate the required theme/plugins;
6. run homepage dry-run, apply, and second no-op;
7. run strict SEO configuration twice and reindex Yoast;
8. flush rewrites, object cache, and Kinsta page cache;
9. reject only PHP fatal/parse errors written after the captured offset, while ignoring historical log entries;
10. scan the full public tree for SQL/dump/release/secret artifacts and smoke all seven routes;
11. atomically publish the current-release and completed-operation receipts.

If a command fails after runtime mutation begins, the same remote process restores the coupled code, uploads, database, activation/URL state, and caches while it still owns the mutation lock. It records either `auto_rollback_succeeded` or `auto_rollback_failed_manual_intervention_required`. A local transport error never starts a second racing rollback; inspect the durable receipt, then use the printed manager rollback command if manual recovery is required.

Complete the full local/remote route, editor, SEO, accessibility, and visual gates against the Kinsta staging origin before taking a cutover backup.

```bash
./manager.sh verify:remote \
  --release-dir="__dev/releases/$release_sha" \
  --origin=https://goetzgoetz.kinsta.cloud
```

Remote verification holds a shared lock, requires the exact current-release digest, uses explicit `wp --path`, rejects multisite, and compares the complete file tree and SHA-256 hashes of all five deployed runtime roots with the private payload. Missing, unexpected, or changed runtime files all fail verification. It also verifies activation, checks only new debug-log bytes, scans the complete public tree, and smokes all seven routes. Every smoke may follow HTTPS redirects only when the effective URL remains the exact requested origin and route.

## Cutover

After staging passes, create a distinct coupled packet immediately before URL or DNS mutation:

```bash
./manager.sh remote:backup \
  --purpose=pre-domain-cutover \
  --release-dir="__dev/releases/$release_sha"
```

Review the required dry-run:

```bash
./manager.sh remote:cutover \
  --from=https://goetzgoetz.kinsta.cloud \
  --to=https://goetzlegal.com \
  --backup-id=<pre-domain-cutover-backup-id>
```

The command is read-only unless the exact `--apply` flag is present. It refuses duplicate flags, alternate source/destination origins, an unverified or wrongly purposed backup, a release digest different from the current deployed receipt, and any home/site URL other than the exact staging origin. The dry-run prints the exact manager apply and rollback commands. Keep the rollback command ready, then apply only inside the approved DNS/TLS cutover window:

```bash
./manager.sh remote:cutover \
  --from=https://goetzgoetz.kinsta.cloud \
  --to=https://goetzlegal.com \
  --backup-id=<pre-domain-cutover-backup-id> \
  --apply
```

DNS and MyKinsta domain changes remain explicit operator actions. Do not infer authority or modify unrelated mail records. If authoritative DNS/MyKinsta access is unavailable, stop with staging verified and document the exact remaining web-record/domain action.

If serialized URL replacement or either option write fails, cutover imports the pre-domain-cutover database, verifies the staging URLs, flushes rewrite rules and object cache, purges Kinsta cache, and scans the public tree before releasing the lock. It records `auto_rollback_succeeded` only when every recovery step passes; otherwise it records `auto_rollback_failed_manual_intervention_required`. Inspect the cutover phase receipt before retrying.

## Rollback

Inspect a rollback without changing the remote site:

```bash
./manager.sh remote:rollback --backup-id=<backup-id> --dry-run
```

Apply only the reviewed packet:

```bash
./manager.sh remote:rollback --backup-id=<backup-id> --apply
```

Rollback dry-run executes the complete non-mutating preflight: checksum/schema verification, archive traversal checks, exact code-state validation, physical source/target checks, multisite rejection, release-state validation, and a full source/target/route action report. It prints the exact manager apply command. Rollback apply then restores in this order:

1. each of the five allowlisted code roots, including removing an exact root that was previously absent;
2. uploads;
3. the database;
4. the database-restored active theme/plugin state and Kinsta must-use plugin inventory;
5. rewrite rules, WordPress object cache, and Kinsta page cache;
6. all seven public routes.

The apply path records every durable phase while holding the shared lock. A rollback failure records `rollback_failed_manual_intervention_required` and stops; it never recursively starts another restore.

If a public cutover also changed DNS, reverse only the reviewed web records through the authoritative provider when necessary. Record the backup ID, packet and manifest hashes, restored targets, URL state, DNS action, and seven-route result in the launch receipt.

## Completion and credential cleanup

Launch is complete only after the production origin passes the full public route, asset, editor, SEO/schema/sitemap, accessibility, visual, TLS, redirect, and cache checks. Preserve the verified backup packet and release receipt outside the public root.

Finally, terminate the isolated SSH agent and remove `SSH_KEY_PW` from the ignored local `.env` as requested by the site owner. Never copy the value into a receipt, command, environment dump, or repository file.
