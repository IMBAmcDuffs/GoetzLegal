# Goetz & Goetz Kinsta Go-Live Receipt

**Started:** 2026-07-16 (America/Los_Angeles)

**Last updated:** 2026-07-17T05:42:48Z

**Status:** Kinsta deployment verified on the temporary domain; public-domain cutover pending MyKinsta and Midphase DNS access.

## Release Identity

- Repository HEAD during deployment: `2ab1710`
- Design commit: `f2d5d5d` (`docs: define Kinsta go-live design`)
- Runbook commit: `2ab1710` (`docs: add Kinsta launch runbook`)
- The release candidate also includes the pre-existing uncommitted go-live-package changes in `.env.example`, `.gitignore`, `manager.sh`, `wp-content/themes/goetz-legal/functions.php`, and `wp-content/themes/goetz-legal/header.php`.
- A credential-containment change was added to `manager.sh` during launch: `SSH_KEY_PW` is unset immediately after `.env` is loaded so it is not inherited by Docker, rsync, SSH, or other child processes.

## Target

- SSH endpoint: `goetzgoetz@163.192.209.112:43854`
- WordPress root: `/www/goetzgoetz_755/public`
- Verified staging URL: `https://goetzgoetz.kinsta.cloud`
- Intended public URL: `https://goetzlegal.com`

## Rollback Packet

Both remote and local copies were verified with matching SHA-256 hashes and restricted to mode `0600`.

| Artifact | Location | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| Pre-launch Kinsta database | `/www/goetzgoetz_755/private/goetz-prelaunch-20260717T053355Z.sql` and `__dev/kinsta-backups/goetz-prelaunch-20260717T053355Z.sql` | 135119 | `0216a11faa478dcc30cfd87401d0eb72fd91d72863032d47561cd0f85de05a34` |
| Pre-launch Kinsta uploads | `/www/goetzgoetz_755/private/goetz-prelaunch-uploads-20260717T053355Z.tgz` and `__dev/kinsta-backups/goetz-prelaunch-uploads-20260717T053355Z.tgz` | 169 | `5df0b039dd3ef920e5c2a31eb69f843bf87f32b03f0ae18d62134f144ca38dc3` |

Database rollback command:

```bash
ssh -p 43854 goetzgoetz@163.192.209.112 'cd /www/goetzgoetz_755/public && wp db import /www/goetzgoetz_755/private/goetz-prelaunch-20260717T053355Z.sql && wp cache flush && wp rewrite flush --hard'
```

The pre-launch target did not contain the custom theme or migration plugin. If a full rollback is required before DNS cutover, restore the database first; the newly uploaded custom directories can remain inactive until reviewed.

## Verification Evidence

- `bash -n manager.sh`: pass.
- Vite production build: pass; output included `app-CUEgfdj6.css`, `app-CXKrCJeu.js`, and the Vite manifest.
- Production npm dependency audit: zero vulnerabilities (`npm audit --omit=dev`).
- Composer audit: no security advisories.
- Local release database: seven published pages, static homepage ID `4`, `/%postname%/`, custom theme, and three required active plugins.
- Kinsta code/media sync: pass; 61 upload files present.
- Local/remote Vite manifest checksum: `97ec3091d30e45e2b576f916c4f71244245231342fdb263cb35d12c8aa01f827` on both sides.
- Kinsta database import using `https://goetzgoetz.kinsta.cloud`: pass; 106 URL replacements during export.
- Required runtime: `goetz-legal` 1.0.0, `goetz-migration` 1.0.0, WPForms Lite 1.10.0.4, Yoast SEO 28.0, and Kinsta MU Plugins 3.6.1.
- No import SQL file remains under the public WordPress root.
- No PHP fatal or parse error was found in the available debug log gate.
- All seven routes returned HTTP `200`: `/`, `/james-l-goetz/`, `/gregory-w-goetz/`, `/staff/`, `/questions/`, `/links/`, and `/contact/`.
- Contact page contains WPForms markup; home page references the custom theme; no `http://localhost:8080` references were found.
- Asset crawl: 29 same-origin stylesheets, scripts, and images checked; zero failures.
- The rendered-browser CLI was unavailable, and managed execution rejected downloading an arbitrary npm binary. Verification used HTTPS route, markup, runtime, and asset-crawl gates instead.

## Public-Domain Gate

Current authoritative state at the last update:

- `goetzlegal.com A 185.151.30.214` with TTL `3600`.
- `www.goetzlegal.com CNAME goetzlegal.com` with TTL `14400`.
- Authoritative nameservers: `ns14.midphase.com`, `ns15.midphase.com`, `ns16.midphase.com`.
- Existing mail routing is separate and must be preserved: `MX 1 smtp.google.com`.
- The current public host identifies as Apache/PHP 7.4 via StackCDN.
- Kinsta edge TLS probes for `goetzlegal.com` and `www.goetzlegal.com` fail the handshake, confirming that the custom domains are not yet configured/verified in MyKinsta.

Required external sequence:

1. In MyKinsta, open **Sites > goetzgoetz > Domains > Add domain**.
2. Add `goetzlegal.com` with the `www` subdomain and choose **Avoid downtime**.
3. Add the unique TXT/CNAME verification records displayed by MyKinsta at the Midphase authoritative DNS panel.
4. After MyKinsta verifies the domains and provides the pointing records, replace only the conflicting root/www web records. Preserve MX, SPF, DKIM, DMARC, and unrelated DNS records.
5. Make the intended non-www domain primary and confirm Kinsta SSL issuance.
6. Only then run the temporary-to-public WP-CLI search/replace from the runbook and execute the final seven-route/TLS verification.

Do not claim the public launch complete until MyKinsta accepts both hostnames, DNS points to Kinsta, TLS succeeds, and all final-hostname smoke tests pass.

## Credential Cleanup

The temporary SSH agent must be destroyed whenever execution pauses. After the public cutover and final checks, remove `SSH_KEY_PW` from `.env` as requested by the site owner.
