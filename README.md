# Goetz & Goetz WordPress Rebuild

This repo is the Docker-managed WordPress rebuild for <https://goetzlegal.com/>. The live site is the content source of truth; this build keeps the approved seven public pages as normal WordPress pages and uses a Tailpress/Vite/Tailwind theme for the rebuild.

## Local Workflow

```bash
cp .env.example .env
./manager.sh start
./manager.sh install
./manager.sh migrate:scan
./manager.sh wp goetz-migration import --source=https://goetzlegal.com
./manager.sh theme:build
```

If Docker is not available inside WSL, enable Docker Desktop WSL integration for this distro and rerun the manager command.

Default local admin login comes from `.env.example`:

- URL: <http://localhost:8080/wp-admin/>
- Username: `admin`
- Password: `admin`

Set `FETCH_PROXY_URL` in `.env` if the migration tool ever needs a Cloudflare Worker-style fetch proxy. Direct source fetches are attempted first.

## Manager Commands

| Command | Purpose |
| --- | --- |
| `./manager.sh start` | Start MariaDB, WordPress, and WP-CLI containers |
| `./manager.sh stop` | Stop local services |
| `./manager.sh restart` | Restart local services |
| `./manager.sh logs [service]` | Tail container logs |
| `./manager.sh shell` | Open a shell in the WordPress container |
| `./manager.sh wp <args>` | Run WP-CLI against local WordPress |
| `./manager.sh db` | Open the local database shell |
| `./manager.sh db:export` | Export a timestamped local database SQL dump to `__dev/` |
| `./manager.sh install` | Install WordPress, activate the theme, and install Yoast/WPForms |
| `./manager.sh theme:dev` | Run the Vite dev server |
| `./manager.sh theme:build` | Install/build theme assets |
| `./manager.sh site:build` | Install/build the required `goetz-site` Gutenberg editor assets |
| `./manager.sh test:integration` | Run the WordPress integration scripts |
| `./manager.sh test:e2e:auth` | Run authenticated Gutenberg browser checks |
| `./manager.sh test:public` | Run public frontend, SEO, accessibility, and visual checks |
| `./manager.sh migrate:scan` | Read-only source discovery and create-only preview |

## Production Release

Production releases are built from the exact clean `main` commit recorded at
`origin/main`. The payload contains only the five allowlisted runtime roots and
two checksum metadata files; ordinary releases never replace the production
database or broad WordPress directories.

Configure the fixed Kinsta endpoint and an independently verified pinned host
key in the ignored `.env`, unlock the SSH identity in an isolated agent, then
use the guarded sequence below:

```bash
release_sha="$(git rev-parse HEAD)"
release_dir="__dev/releases/$release_sha"

./manager.sh release:build "$release_sha"
./manager.sh release:verify "$release_dir"
./manager.sh remote:backup \
  --purpose=pre-deployment \
  --release-dir="$release_dir"
./manager.sh remote:deploy \
  --release-dir="$release_dir" \
  --backup-id=<pre-deployment-backup-id>
./manager.sh verify:remote \
  --release-dir="$release_dir" \
  --origin=https://goetzgoetz.kinsta.cloud
```

After the staging gates pass, create a separate
`--purpose=pre-domain-cutover` packet bound to the same release, run the
cutover dry-run, and apply only in the approved DNS/TLS window. Rollback is
also dry-run by default and always names one locally and remotely verified
coupled packet. See
[`docs/deployment/goetz-production-runbook.md`](docs/deployment/goetz-production-runbook.md)
for the exact cutover, recovery, receipt, and credential-cleanup procedure.

## Content Scope

The importer only creates pages for:

- `/`
- `/james-l-goetz/`
- `/gregory-w-goetz/`
- `/staff/`
- `/questions/`
- `/links/`
- `/contact/`

There are no custom post types in v1. The old cloned-build concepts such as attorneys archives, practice-area CPTs, AI authority files, and broad recommended-plugin notices are intentionally out of scope.

## Theme

The theme lives in `wp-content/themes/goetz-legal` and keeps the Tailpress 5 framework, Vite, Tailwind CSS 4, SCSS, and TypeScript. Custom block metadata lives in `blocks/*/block.json`; block frontend assets are declared with `style`, `viewStyle`, and `viewScript` for conditional loading.

## Site and Migration Plugins

`wp-content/plugins/goetz-site` is the required runtime for Site Settings and the native dynamic Gutenberg blocks. `wp-content/plugins/goetz-migration` owns source discovery and the guarded legacy import path.

The legacy importer discovers the source through `page-sitemap.xml`, fetches public REST API page data first, and falls back to rendered HTML. Its normal path is create-only: missing approved pages may be created, while existing pages are skipped before content, media, or form preparation. It never changes existing templates, menu assignments, the custom logo, front-page settings, default content, or Yoast metadata.

Preview the normalized block plan before any legacy import:

```bash
./manager.sh wp goetz-migration import --source=https://goetzlegal.com --dry-run
```

Create missing pages only:

```bash
./manager.sh wp goetz-migration import --source=https://goetzlegal.com
```

Existing page content can be replaced only through the direct WP-CLI force path. Review the force diff first, then approve interactively or pass `--yes` for an already reviewed non-interactive run:

```bash
./manager.sh wp goetz-migration import --source=https://goetzlegal.com --dry-run --force-existing --yes
./manager.sh wp goetz-migration import --source=https://goetzlegal.com --force-existing
```

There is intentionally no manager import shortcut and no wp-admin force control.

The James attorney profile has a repository-owned, idempotent content migration. Preview it before applying:

```bash
./manager.sh wp goetz-site attorney-profile --slug=james-l-goetz
./manager.sh wp goetz-site attorney-profile --slug=james-l-goetz --apply
```

The apply path checksum-verifies and seeds the exact portrait into the Media Library, saves the original page content once, and refuses to overwrite a page after an editor changes the managed version. A second preview reports `status=noop`.

## Source Details

Authoritative live-site contact information:

- Phone: `(239) 936-2841`
- Email: `info@goetzlegal.com`
- Address: `33 Barkley Cir Ste 100, Fort Myers, Florida 33907`
