# Goetz & Goetz WordPress Rebuild

This repo is the Docker-managed WordPress rebuild for <https://goetzlegal.com/>. The live site is the content source of truth; this build keeps the approved seven public pages as normal WordPress pages and uses a Tailpress/Vite/Tailwind theme for the rebuild.

## Local Workflow

```bash
cp .env.example .env
./manager.sh start
./manager.sh install
./manager.sh migrate:scan
./manager.sh migrate:import
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
| `./manager.sh migrate:scan` | Dry-run source discovery |
| `./manager.sh migrate:import` | Import missing approved pages and media |

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

It discovers the source through `page-sitemap.xml`, fetches public REST API page data first, falls back to rendered HTML when needed, imports media into the local media library, rewrites image URLs, configures the homepage, and rebuilds the primary/footer menus.

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
