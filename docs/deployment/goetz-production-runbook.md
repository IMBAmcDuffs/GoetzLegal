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

Establish an operator-enforced WordPress write freeze **before** creating the backup. During this window, allow no wp-admin/editor saves, form submissions, imports, scheduled content jobs, integration writes, or other database/upload mutations. Keep the freeze in place through deployment completion and remote verification. If the freeze was interrupted after the backup, discard that packet and create a new one after re-establishing the freeze.

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
  --backup-id=<pre-deployment-backup-id> \
  --write-freeze-confirmed
```

`--write-freeze-confirmed` is a required operator attestation that the uninterrupted freeze began before this exact backup and is still active. The deploy command rejects a missing or duplicate confirmation before contacting Kinsta. Never pass it based only on an earlier maintenance window or an unverified assumption that no one is editing.

The release is uploaded to the commit-specific private directory and verified there before application. Synchronization uses `--delete-delay` only inside each named allowlisted theme or plugin directory. The deployment never targets `/wp-content/plugins/`, `/wp-content/mu-plugins/`, `/wp-content/uploads/`, `wp-admin`, `wp-includes`, or the WordPress root.

Private uploads are resumable in one commit/backup-specific incoming directory. The incoming directory and payload destination are physically validated and rejected if any symlink is present before rsync receives the destination. The completed payload is checksum-verified and published with one directory rename; an existing verified release is safely reused. The shared remote mutation lock serializes backup, deployment, cutover, rollback, and read verification.

Before the first runtime write, deployment verifies every source/target physical path, rejects symlinked parents or named roots, rejects multisite, requires the existing `goetz-site` runtime to be active, verifies the complete release and backup packets, pre-extracts the recovery packet, and writes a durable phase receipt. It also establishes `wp-content/debug.log` as a normal regular-file checkpoint, safely creating an empty mode-`0600` file when it is initially absent, and records its inode, byte offset, and prefix SHA-256. The remote application order is:

1. verify the release and coupled backup manifests;
2. sync only the already-active `goetz-site` code;
3. use that newly synced code to preview James and Gregory without changing the database or uploads, failing closed on conflicts, missing pages, version conflicts, or migration-evidence mismatches;
4. migrate the two attorney profiles, applying only a recognized legacy or missing-seed state, then require a `noop` or protected `managed_modified` post-preview and verified migration evidence;
5. deploy the theme, migration plugin, Yoast SEO 28.0, and WPForms Lite 1.10.0.4 as separate roots;
6. validate generated files and exact runtime versions, then activate the required theme/plugins;
7. run homepage dry-run, apply, and second no-op;
8. run strict SEO configuration twice and reindex Yoast;
9. flush rewrites, object cache, and Kinsta page cache;
10. reject any missing, symlinked, replaced, truncated, or prefix-rewritten debug checkpoint and reject PHP fatal/parse errors written after the captured offset;
11. scan the full public tree, smoke all seven routes, then scan the same debug checkpoint again so request-generated fatal/parse errors cannot escape the gate;
12. atomically publish the current-release and completed-operation receipts.

The attorney-profile gate is deliberately non-destructive for already-managed content. An exact current profile must remain a no-op, and a profile that the client edited after its guarded migration must remain `managed_modified`; deployment verifies that state without applying over the editor's changes. Any unknown profile, missing page, unrecognized legacy content, absent migration evidence, or disagreement between preview and verification fails the deployment and invokes the coupled recovery path.

If attorney preflight or another command fails after code sync but before any database/upload mutation, the same remote process restores only the coupled code and records `auto_code_rollback_succeeded`; it deliberately does not import the older database or uploads packet. After a profile apply or activation/database mutation begins, failure or HUP/INT/TERM uses full packet recovery exactly once while the process still owns the mutation lock, restoring code, uploads, database, activation/URL state, and caches. It records either `auto_rollback_succeeded` or `auto_rollback_failed_manual_intervention_required`. The uninterrupted write freeze is still mandatory because no deployment script can prevent an external editor or integration from writing between the read-only preflight and the first guarded mutation. A local transport error never starts a second racing rollback and does not prove whether the remote handler ran; treat the outcome as unknown, inspect the durable receipt, then use the printed manager rollback command if manual recovery is required.

Complete the full local/remote route, editor, SEO, accessibility, and visual gates against the Kinsta staging origin before taking a cutover backup.

```bash
./manager.sh verify:remote \
  --release-dir="__dev/releases/$release_sha" \
  --origin=https://goetzgoetz.kinsta.cloud
```

Remote verification holds a shared lock, requires the exact current-release digest, uses explicit `wp --path`, rejects multisite, and compares the complete file tree and SHA-256 hashes of all five deployed runtime roots with the private payload. Missing, unexpected, or changed runtime files all fail verification. It also requires verified versioned migration evidence for both James and Gregory while accepting protected post-migration editor changes, verifies activation, requires the debug-log inode/size/prefix checkpoint to remain continuous and its size to stay stable through each scan, rejects new fatal/parse bytes, scans the complete public tree, smokes all seven routes, and repeats the debug scan afterward. Every smoke may follow HTTPS redirects only when the effective URL remains the exact requested origin and route.

Run the authenticated, non-mutating editor/settings acceptance gate separately. Use the staging origin before cutover and the production origin after cutover:

```bash
GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
GOETZ_E2E_ALLOW_REMOTE=1 \
./manager.sh test:e2e:auth \
  production-read-only.spec.ts
```

The documented no-caller Tasks 18/20 path requires no operator-entered WordPress credential. It accepts exactly the dedicated `production-read-only.spec.ts` selector and rejects a missing, alternate, or additional Playwright argument before creating an account. With the already-unlocked isolated SSH agent, manager creates one `goetz_verify_<random>` administrator through remote WP-CLI, supplies its 256-bit password only on `--prompt=user_pass` standard input, sends the two credentials to the Playwright process on standard input, and keeps them out of arguments, files, artifacts, and output. The dedicated spec contains exactly two matching tests: they inspect the locked homepage tree and editable controls, leave the editor clean, render Site Settings without submitting its form, and compare the original read-only state after navigation. Explicit caller-provided credential pairs retain the focused authenticated-test compatibility path for controlled diagnostics; Tasks 18/20 use only the ephemeral dedicated selector.

Before any Playwright dependency, state, or artifact path is created or permissioned, manager rejects a named directory or existing parent that is a symlink or non-directory. Treat that diagnostic as a local workspace-integrity failure; inspect and replace the redirected path rather than overriding the check.

An EXIT/HUP/INT/TERM cleanup trap deletes the temporary account and requires a successful `wp user list --login=<exact-login> --format=count` result of `0`. Cleanup failure takes precedence over a browser failure, exits with status `70`, and prints only this credential-free warning:

```text
CRITICAL: temporary remote verification administrator cleanup failed; follow the emergency cleanup runbook immediately.
```

If that warning appears, do not rerun the gate. Using the same pinned Kinsta transport and explicit `/www/goetzgoetz_755/public` path, list administrators with `wp user list --role=administrator --fields=ID,user_login,user_email,user_registered --format=table`. Review only a login matching `^goetz_verify_[a-f0-9]{16}$` and the failed gate's time window. Delete that exact login with `wp user delete <exact-login> --yes`, then run `wp user list --login=<exact-login> --format=count` and require the exact output `0`. Never place the generated login or any credential in a ticket, receipt, shell history, or repository file.

## Performance and CDN image delivery

Run desktop and mobile Lighthouse evidence against the built local site and the warmed staging origin. Record FCP, LCP, CLS, TBT, accessibility, best-practices, and SEO, but do not treat a local simulated score as a production guarantee. A material regression, new blocking asset, missing image dimensions, or non-zero layout shift blocks release until investigated.

The homepage hero remains a native WordPress Media Library attachment rendered through `wp_get_attachment_image()`. Keep its responsive `srcset`, explicit dimensions, `loading="eager"`, and `fetchpriority="high"`; desktop treats that image as the LCP element. Do not replace it with a plugin URL or unmanaged duplicate merely to improve an isolated audit.

Before final staging performance verification, use authenticated MyKinsta to open **Sites > goetzgoetz > CDN > Image optimization Settings** and select **Lossless**. Kinsta's lossless mode creates CDN WebP variants for PNG images without modifying stored WordPress files or page HTML. This is an explicit MyKinsta operator action and must not be inferred from the presence of the CDN alone.

After saving the setting, allow it to settle, warm the hero image with a browser that advertises WebP, and inspect the response. Require `ki-cache-type: CDN` and `ki-cf-cache-status: HIT`; `cf-polished` must show that image optimization processed the asset, and `content-type` must report the format actually delivered. If `cf-polished` is absent or the cache remains in an optimizing state, wait and retry before recording staging Lighthouse evidence. Recheck the same headers after domain cutover.

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

If serialized URL replacement, either option write, strict SEO configuration, Yoast reindexing, or a later cutover step fails, cutover imports the pre-domain-cutover database, verifies the staging URLs, flushes rewrite rules and object cache, purges Kinsta cache, and scans the public tree before releasing the lock. HUP/INT/TERM uses that same recovery path exactly once. It records `auto_rollback_succeeded` only when every recovery step passes; otherwise it records `auto_rollback_failed_manual_intervention_required`. A transport failure leaves the remote result unknown, so inspect the cutover phase receipt before retrying.

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

The apply path records every durable phase while holding the shared lock. A rollback failure or HUP/INT/TERM records `rollback_failed_manual_intervention_required`, removes its private extraction and preflight work, and stops; it never recursively starts another restore.

If a public cutover also changed DNS, reverse only the reviewed web records through the authoritative provider when necessary. Record the backup ID, packet and manifest hashes, restored targets, URL state, DNS action, and seven-route result in the launch receipt.

## Completion and credential cleanup

Launch is complete only after the production origin passes the full public route, asset, editor, SEO/schema/sitemap, accessibility, visual, TLS, redirect, and cache checks. Preserve the verified backup packet and release receipt outside the public root.

Finally, terminate the isolated SSH agent and remove `SSH_KEY_PW` from the ignored local `.env` as requested by the site owner. Never copy the value into a receipt, command, environment dump, or repository file.
