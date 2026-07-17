# Goetz & Goetz Kinsta Go-Live Design

**Date:** 2026-07-16

**Goal:** Publish the approved local Goetz & Goetz WordPress rebuild to the existing Kinsta production environment tonight, with a verified rollback point and no credential leakage.

## Confirmed Starting State

- The local project is a full WordPress site with a custom `goetz-legal` theme and the `goetz-migration` plugin.
- The local production asset build completes and contains the expected Vite manifest, CSS, and JavaScript bundles.
- The local database contains the seven approved public pages, uses the custom theme, and has `goetz-migration`, WPForms Lite, and Yoast SEO active.
- The Kinsta SSH endpoint is `goetzgoetz@163.192.209.112:43854`.
- The Kinsta WordPress root is `/www/goetzgoetz_755/public`.
- Kinsta currently serves a stock one-page WordPress install at `https://goetzgoetz.kinsta.cloud` with Twenty Twenty-Five, Yoast SEO, and the Kinsta must-use plugin.
- The public `https://goetzlegal.com` hostname still resolves to the existing host, not the discovered Kinsta web environment.

## Credential Handling

`SSH_KEY_PW` is read from the ignored local `.env` only long enough to unlock `~/.ssh/id_rsa` into a temporary `ssh-agent`. The passphrase must never appear in command arguments, terminal output, logs, plans, or helper files. The `.env` file is restricted to mode `0600`. The agent and its socket are removed after deployment.

## Considered Approaches

### 1. Stage on the Kinsta temporary domain, then cut over (selected)

Import the rebuilt database using `https://goetzgoetz.kinsta.cloud`, verify the complete site there, then replace that temporary URL with `https://goetzlegal.com` immediately before the domain/DNS cutover. This produces a testable Kinsta deployment before public traffic moves and preserves the current public site throughout staging.

### 2. Import directly with the final public URL

This is the shortest path supported by `manager.sh`, but the rebuilt Kinsta site would redirect preview requests to the still-live legacy host. That makes visual and functional verification unreliable before DNS changes, so it is rejected.

### 3. Rebuild content manually in Kinsta

This avoids a database replacement but introduces configuration drift, takes materially longer, and discards the approved local database package. It is rejected.

## Deployment Sequence

1. Rebuild local theme assets and re-check the seven-page database package.
2. Export the current Kinsta database to `/www/goetzgoetz_755/private` and download a copy into ignored local `__dev/kinsta-backups/` storage.
3. Record the pre-launch Kinsta site URL, active theme, active plugins, published-page count, and HTTP response.
4. Rsync the custom theme, migration plugin, and uploads using the existing `manager.sh deploy:code` command with the discovered Kinsta settings supplied in process memory.
5. Export the local database with serialized URLs rewritten to `https://goetzgoetz.kinsta.cloud`, upload it, and import it into Kinsta.
6. Confirm the Kinsta must-use plugin remains available, install/activate WPForms Lite if absent, activate the custom theme and required plugins, flush rewrite rules, and clear Kinsta/WordPress caches.
7. Verify all seven URLs on the Kinsta temporary domain, asset loading, navigation, the contact form rendering, WordPress health indicators, and absence of PHP fatal errors.
8. Replace the temporary Kinsta URL with `https://goetzlegal.com` using WP-CLI's serialization-safe search/replace, update `home` and `siteurl`, and flush caches.
9. Complete or confirm the Kinsta domain/DNS cutover. If DNS cannot be changed from the available environment, stop with the fully verified Kinsta site ready and provide the exact remaining MyKinsta/DNS action.
10. Verify public HTTP status, TLS, redirects, all seven pages, assets, navigation, robots visibility, and the contact form after DNS resolves to Kinsta.

## Failure Handling and Rollback

- No destructive import occurs until the remote database backup exists and can be read back.
- A code-upload failure leaves the database unchanged; rerun only the failed rsync after correcting the error.
- A database/import or temporary-domain smoke-test failure triggers restoration of the pre-launch database export. Existing remote theme/plugin directories are absent, so they can be left inactive or removed only after explicit validation.
- A post-cutover failure first triggers a Kinsta database restore and, when necessary, DNS reversal to the prior provider. DNS changes are not inferred or made without confirmed access to the authoritative DNS/Kinsta domain controls.
- The uploaded SQL import file is removed immediately after a successful or failed import attempt.

## Success Criteria

- Kinsta contains the custom theme, migration plugin, uploads, and all required third-party plugins.
- The database contains exactly the seven approved published pages and uses the intended static homepage and permalink structure.
- The Kinsta temporary domain passes smoke tests before public cutover.
- `https://goetzlegal.com` resolves to and serves the Kinsta deployment with valid TLS and no redirect loop.
- Home, James L. Goetz, Gregory W. Goetz, Staff, Questions, Links, and Contact return successful responses and render the rebuilt theme.
- The contact form renders without submitting a test message to the firm.
- The pre-launch database backup, deployment receipt, and remaining rollback instructions are retained outside the public web root.

## Scope Boundaries

- No WordPress core or third-party plugin mass updates are part of tonight's launch.
- No test contact-form submission is sent without separate approval because it would create an external message.
- No unrelated refactoring or cleanup is included.
- The user should remove `SSH_KEY_PW` from `.env` after the deployment is complete.
