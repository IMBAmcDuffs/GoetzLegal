# Goetz & Goetz Gutenberg Homepage Production Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Use `superpowers:test-driven-development` for every behavior change and `superpowers:verification-before-completion` before every release claim.

**Goal:** Replace the opaque homepage rebuild with a handoff-friendly native Gutenberg implementation, match the public reference homepage and animation, make shared site data and Yoast configuration reproducible, deploy a clean committed release to Kinsta, cut over `goetzlegal.com`, and prove the public launch.

**Architecture:** A required tracked `goetz-site` plugin owns the stable `goetz/*` block APIs, Site Settings, seed media, versioned content migrations, Yoast configuration/schema, and WP-CLI commands. The existing `goetz-legal` hybrid theme owns presentation, templates, menus, header/footer, and responsive behavior. Root Composer and committed Node lockfiles reproduce third-party/runtime dependencies. Releases are built from a clean pushed commit into a checksum manifest, then deployed only through explicit directory allowlists; content changes are idempotent WP-CLI migrations, never an ordinary full database replacement.

**Tech stack:** WordPress 6.9+/PHP 8.3, Gutenberg Block API v3, `@wordpress/scripts`, TailPress 5/Tailwind 4/Vite, Composer/WPackagist, WP-CLI, Yoast SEO 28.0, WPForms Lite 1.10.0.4, PHPUnit/Brain Monkey, Playwright, axe-core, Docker Compose, Bash, SSH/rsync, Kinsta.

## Non-negotiable constraints

- Keep `.env` ignored and mode-restricted. Never print, interpolate into a logged command, copy, persist, or deploy `SSH_KEY_PW`; use it only to unlock a temporary SSH agent, immediately unset it, and destroy the agent at the end.
- Preserve all user-owned worktree changes. Before each commit, inspect `git diff`, stage explicit paths, run `git diff --cached --check`, and verify the staged file list.
- Do not add ACF. All editing uses native Gutenberg, WordPress Menus, the Settings API, and the Media Library.
- Do not rename existing `goetz/hero`, `goetz/attorney-card`, `goetz/cta`, `goetz/faq-list`, or `goetz/resource-links` block names.
- Activate and verify `goetz-site` before removing theme-side block registration.
- Do not concatenate block comments manually. Use `parse_blocks()`, `serialize_block()`, and `serialize_blocks()`.
- Do not overwrite existing editor content during ordinary deployment. Every write has dry-run output, a schema version, idempotency tests, and a backup.
- Do not replace complete Yoast option arrays. Write only the approved allowlisted keys and preserve verification/integration data byte-for-byte.
- Do not deploy from a dirty working tree. Build the payload from the exact pushed commit.
- Never `rsync --delete` the WordPress plugin root, Kinsta MU plugins, WordPress core, or arbitrary uploads.
- Do not submit the production contact form.
- A passing staging site is progress, not completion. Completion requires the public domain, TLS/redirects, all seven pages, editor/settings behavior, visuals, accessibility, SEO, sitemap/schema, and production receipt to pass.

## Required command conventions

All commands run from `/home/mcduffion/projects/GOETZ` unless the step says otherwise.

```bash
./manager.sh start
./manager.sh wp <wp-cli arguments>
./manager.sh test:unit
./manager.sh test:integration
./manager.sh test:e2e
./manager.sh test:all
./manager.sh release:build "$(git rev-parse HEAD)"
./manager.sh release:verify "__dev/releases/$(git rev-parse HEAD)"
```

Expected test behavior is always explicit below: the RED invocation must fail for the named missing behavior, then the identical focused invocation must pass after the smallest implementation.

---

### Task 1: Lock the repository and release-safety contract

**Files:**

- Create: `tests/contracts/repository-release.sh`
- Modify: `.gitignore`
- Modify: `.env.example`
- Modify: `manager.sh`
- Modify: `wp-content/themes/goetz-legal/functions.php`
- Modify: `wp-content/themes/goetz-legal/header.php`

**Interfaces and invariants:**

- `.env` and secret-like variants remain ignored; `.env.example` remains explicitly trackable.
- `SSH_KEY_PW` is unset immediately after `.env` is sourced.
- Docker Compose never reads `.env` itself. `manager.sh` supplies only the approved Compose substitutions, sets `COMPOSE_DISABLE_ENV_FILE=1`, and points Compose at `/dev/null`; therefore Compose and containers cannot re-read `SSH_KEY_PW` after the shell unsets it.
- Repository/release tests reject floating `npm install`, floating `wp plugin install`, destructive ordinary `deploy:db`, secret files, SQL dumps, Git metadata, arbitrary uploads, and plugin-root deletion.
- Preserve the already-reviewed Kinsta variables, Roboto-only font enqueue, and pingback removal currently present in the dirty worktree.

- [ ] **Step 1: Write the failing repository contract**

Add an executable Bash test whose essential assertions are:

```bash
#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

git check-ignore -q --no-index .env
! git check-ignore -q --no-index .env.example
grep -Fqx '!.env.example' .gitignore
grep -q '^unset SSH_KEY_PW$' manager.sh
grep -q 'COMPOSE_DISABLE_ENV_FILE=1' manager.sh
grep -q -- '--env-file /dev/null' manager.sh
! grep -q -- '--env-file .*\.env' manager.sh
! grep -Eq 'npm install([[:space:]]|$)' manager.sh
! grep -Eq 'wp plugin install (wordpress-seo|wpforms-lite)([[:space:]]|$)' manager.sh
! grep -Eq 'deploy:db|wp db import' manager.sh
```

Also make it inspect a supplied release path, when `GOETZ_RELEASE_DIR` is set, and fail if it finds `.env*`, `*.sql`, `.git`, `node_modules`, tests, or files below `wp-content/uploads` other than the curated seed originals. In a disposable fixture copy, give `manager.sh` a synthetic `.env` containing `SSH_KEY_PW=never-forward-this-test-value` and run it against a fake `docker` executable that records its arguments/environment. Fail if the recording contains the variable name, synthetic value, `.env` path, or any non-allowlisted exported variable. Never read or compare the real workspace passphrase in this contract test.

- [ ] **Step 2: Run RED**

Run:

```bash
debug_before=0
if [[ -f wp-content/debug.log ]]; then debug_before="$(wc -l < wp-content/debug.log)"; fi
bash tests/contracts/repository-release.sh
```

Expected: non-zero because `.env.example` has no exception and floating/destructive commands are still present.

- [ ] **Step 3: Make the minimum safety changes**

Add `!.env.example`; keep `/wp-content/uploads/`, `/.env`, `/.env*`, `/__dev/`, `/artifacts/`, generated dependency/build directories, and third-party plugin source ignored. Remove `deploy:db` from ordinary command routing and replace floating install/build calls with named functions that will be implemented under locked dependencies in Task 2. Keep `unset SSH_KEY_PW` directly after sourcing `.env`.

Replace `COMPOSE=(docker compose --env-file "${ROOT_DIR}/.env")` and the `set -a` source block with a wrapper that sources `.env` as non-exported shell variables, unsets `SSH_KEY_PW`, and invokes `COMPOSE_DISABLE_ENV_FILE=1 docker compose --env-file /dev/null` with only these explicit Compose substitutions: `COMPOSE_PROJECT_NAME`, `WP_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `FETCH_PROXY_URL`, `WORDPRESS_IMAGE`, and `WPCLI_IMAGE`. `WP_URL`/admin and Kinsta values remain unexported shell values consumed only by the manager functions that need them. Expose this same wrapper as `./manager.sh compose <arguments>` with `shift` and quoted forwarding so later setup never invokes raw Compose. No child process inherits `SSH_KEY_PW`.

Do not discard the current `.env.example`, `functions.php`, or `header.php` edits.

- [ ] **Step 4: Run GREEN and static checks**

Run:

```bash
bash tests/contracts/repository-release.sh
bash -n manager.sh
git diff --check
git diff -- .env.example .gitignore manager.sh wp-content/themes/goetz-legal/functions.php wp-content/themes/goetz-legal/header.php
```

Expected: all checks pass; the diff contains no secret value.

- [ ] **Step 5: Commit the safety baseline**

```bash
git add .env.example .gitignore manager.sh tests/contracts/repository-release.sh \
  wp-content/themes/goetz-legal/functions.php wp-content/themes/goetz-legal/header.php
git diff --cached --check
git diff --cached --name-only
git commit -m "chore: harden the production release baseline"
```

Expected staged paths: exactly the six paths listed for this task.

---

### Task 2: Make PHP, WordPress plugin, theme, and browser dependencies reproducible

**Files:**

- Create: `composer.json`
- Create: `composer.lock`
- Create: `phpunit.xml.dist`
- Create: `tests/phpunit/bootstrap.php`
- Modify: `.gitignore`
- Modify: `docker-compose.yml`
- Modify: `manager.sh`
- Create/commit: `wp-content/themes/goetz-legal/composer.lock`
- Create/commit: `wp-content/themes/goetz-legal/package-lock.json`
- Create: `wp-content/plugins/goetz-site/package.json`
- Create/commit: `wp-content/plugins/goetz-site/package-lock.json`
- Create: `tests/e2e/package.json`
- Create/commit: `tests/e2e/package-lock.json`
- Create: `tests/e2e/playwright.config.ts`
- Create: `tests/e2e/playwright.public.config.ts`
- Create: `tests/e2e/playwright.capture.config.ts`
- Create: `tests/e2e/global-setup.ts`
- Create: `tests/e2e/helpers/wordpress.ts`
- Create: `tests/e2e/smoke.spec.ts`
- Create: `tests/integration/wp-version-matrix.sh`
- Create: `tests/fixtures/compat-site.php`

**Dependency contract:**

Root `composer.json` contains the WPackagist repository and these runtime packages:

```json
{
  "$schema": "https://getcomposer.org/schema.json",
  "name": "goetz-legal/site",
  "description": "Locked runtime dependencies for the Goetz & Goetz WordPress site.",
  "type": "project",
  "license": "proprietary",
  "repositories": [
    {"type": "composer", "url": "https://wpackagist.org"}
  ],
  "require": {
    "php": ">=8.0.2",
    "composer/installers": "^2.3",
    "wpackagist-plugin/wordpress-seo": "28.0",
    "wpackagist-plugin/wpforms-lite": "1.10.0.4"
  },
  "require-dev": {
    "brain/monkey": "^2.6",
    "phpunit/phpunit": "^9.6"
  },
  "extra": {
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
    }
  },
  "config": {
    "allow-plugins": {
      "composer/installers": true
    },
    "sort-packages": true
  }
}
```

The plugin package builds one editor bundle with `@wordpress/scripts`; the E2E package locks `@playwright/test`, `@axe-core/playwright`, `pixelmatch`, and `pngjs`. Use `--save-exact` when generating new package manifests; the committed lockfiles are authoritative.

The plugin manifest exposes exactly these script interfaces (the lockfile pins resolved transitive versions):

```json
{
  "name": "goetz-site-blocks",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "build": "wp-scripts build src/index.js --output-path=build",
    "test:unit": "wp-scripts test-unit-js"
  },
  "devDependencies": {
    "@wordpress/scripts": "33.0.0"
  }
}
```

The complete E2E manifest is:

```json
{
  "name": "goetz-e2e",
  "private": true,
  "type": "module",
  "scripts": {
    "test:auth": "playwright test --config=playwright.config.ts",
    "test:public": "playwright test --config=playwright.public.config.ts",
    "test:capture": "playwright test --config=playwright.capture.config.ts"
  },
  "devDependencies": {
    "@axe-core/playwright": "4.12.1",
    "@playwright/test": "1.61.1",
    "pixelmatch": "7.2.0",
    "pngjs": "7.0.0"
  }
}
```

- [ ] **Step 1: Extend the failing repository contract**

Assert that root/theme/plugin/E2E lockfiles are trackable and exist, `manager.sh` contains `composer install --no-dev --prefer-dist --no-interaction --no-progress` and `npm ci`, and it contains no floating install. Assert `composer.json` resolves the two exact WordPress plugin versions.

- [ ] **Step 2: Run RED**

```bash
bash tests/contracts/repository-release.sh
test -f composer.lock
test -f wp-content/themes/goetz-legal/package-lock.json
test -f wp-content/plugins/goetz-site/package-lock.json
test -f tests/e2e/package-lock.json
```

Expected: failure because the manifests/locks do not exist.

- [ ] **Step 3: Add manifests and generate locks**

Make the Composer service able to run at `/app` and `/app/wp-content/themes/goetz-legal`; make the Node service accept an explicit working directory. Pin Docker image tags to the exact tested WordPress/PHP, WP-CLI/PHP, MariaDB, Node, Composer, and official Playwright versions; record digests when the registry exposes them.

Run:

```bash
./manager.sh compose run --rm -w /app composer composer validate --strict
./manager.sh compose run --rm -w /app composer composer update --with-all-dependencies --no-interaction --no-progress
./manager.sh compose run --rm -w /app/wp-content/themes/goetz-legal composer composer update --with-all-dependencies --no-interaction --no-progress
./manager.sh compose run --rm -w /app/wp-content/themes/goetz-legal node npm install --package-lock-only
./manager.sh compose run --rm -w /app/wp-content/plugins/goetz-site node npm install --package-lock-only
./manager.sh compose run --rm -w /app/tests/e2e node npm install --package-lock-only
```

Expected: all four lockfiles are produced without modifying ignored third-party source beyond the Composer install paths.

- [ ] **Step 4: Implement locked manager commands**

Extend the sanitized `compose` passthrough from Task 1 and add `deps:install`, `theme:build`, `site:build`, `phpunit:test`, `site:test`, `test:unit`, `test:integration`, `test:compat`, `e2e:install`, `test:e2e:auth`, `test:public`, `test:capture`, `test:e2e`, and `test:all` functions. Every dispatch branch begins with `shift` and forwards quoted `"$@"` to its focused runner. `phpunit:test` owns PHPUnit `--filter`; `site:test` owns Jest `--runInBand` and test-path arguments; `test:unit` runs both without accepting focused runner arguments. `test:integration` runs the explicit WordPress scripts against the Docker site. `test:compat --bootstrap-only` initially proves disposable WordPress 6.9.4 and 7.0.1/PHP 8.3 environments can install; its full mode uses `tests/fixtures/compat-site.php` to create the seven approved page slugs/front-page setting, then (after Tasks 3-12 exist) proves activation, blocks, migration/no-op, and SEO/no-op on both. `test:e2e:auth` uses authenticated config, `test:public` is unauthenticated, `test:capture` is the unauthenticated legacy-only config, and `test:e2e` runs auth plus public locally. Final `test:all` runs static contracts, unit, WordPress integration, full compatibility, authenticated local E2E, and public local E2E; it never runs capture or remote tests. The production commands must use:

```bash
composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
npm ci
npm run build
npx playwright install --with-deps chromium
```

Local test dependency installation may omit `--no-dev`, but release payloads may not contain the root dev vendor tree.

All configs use `baseURL: process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080'`, Chromium, and failure-only trace/screenshot/video. Before invoking a browser child, manager explicitly sets `GOETZ_BASE_URL` from a caller override or its non-exported local `WP_URL`; it forwards `GOETZ_EXPECT_ORIGIN`, `GOETZ_EXPECT_PRODUCTION`, and remote opt-in only when the caller supplied them. Authenticated `playwright.config.ts` alone uses `global-setup.ts` and ignored storage state below `__dev/playwright/`; manager supplies local `GOETZ_E2E_USER`/`GOETZ_E2E_PASSWORD` from the unexported local admin values or the caller's ephemeral remote overrides, without logging either value. `playwright.public.config.ts` has no global setup/storage state and runs frontend/SEO/accessibility/visual tests only. `playwright.capture.config.ts` has no authentication and runs only the read-only legacy capture. Remote authenticated tests refuse to start unless `GOETZ_E2E_ALLOW_REMOTE=1`; public/capture tests never log in. Helpers create uniquely prefixed temporary drafts and always trash them in teardown.

Run `./manager.sh install` before the initial local smoke so a clean volume has WordPress, the locked required plugins, and the theme. Do not require seven content pages until the explicit migration/content fixture exists; Task 3 proves block ownership independently of database content.

- [ ] **Step 5: Run GREEN and audit locked dependencies**

```bash
bash tests/contracts/repository-release.sh
./manager.sh compose run --rm -w /app composer composer validate --strict
./manager.sh compose run --rm -w /app composer composer install --no-interaction --no-progress
./manager.sh compose run --rm -w /app composer composer audit --locked
./manager.sh compose run --rm -w /app/wp-content/themes/goetz-legal composer composer validate --strict
./manager.sh compose run --rm -w /app/wp-content/themes/goetz-legal node npm ci
./manager.sh compose run --rm -w /app/wp-content/themes/goetz-legal node npm run build
test -s wp-content/themes/goetz-legal/dist/.vite/manifest.json
./manager.sh start
./manager.sh install
./manager.sh test:unit
./manager.sh test:e2e:auth --grep "local smoke"
./manager.sh test:public --grep "public local smoke"
./manager.sh test:compat --bootstrap-only
```

Expected: manifests validate, locked installs/build pass, and no unassessed high/critical production advisory remains.

- [ ] **Step 6: Commit dependency reproducibility**

```bash
git add .gitignore composer.json composer.lock phpunit.xml.dist tests/phpunit/bootstrap.php \
  docker-compose.yml manager.sh \
  wp-content/themes/goetz-legal/composer.lock wp-content/themes/goetz-legal/package-lock.json \
  wp-content/plugins/goetz-site/package.json wp-content/plugins/goetz-site/package-lock.json \
  tests/e2e/package.json tests/e2e/package-lock.json tests/e2e/playwright.config.ts \
  tests/e2e/playwright.public.config.ts tests/e2e/playwright.capture.config.ts \
  tests/e2e/global-setup.ts tests/e2e/helpers tests/e2e/smoke.spec.ts \
  tests/integration/wp-version-matrix.sh tests/fixtures/compat-site.php
git diff --cached --check
git commit -m "build: lock WordPress and frontend dependencies"
```

---

### Task 2A: Freeze the public legacy homepage before that reference disappears

**Files:**

- Create: `tests/visual/fixtures/legacy/home-1440x900.png`
- Create: `tests/visual/fixtures/legacy/home-390x844.png`
- Create: `tests/visual/fixtures/legacy/home-989x844.png`
- Create: `tests/visual/fixtures/legacy/home-990x844.png`
- Create: `tests/visual/fixtures/legacy/geometry.json`
- Create: `tests/e2e/helpers/settle-page.ts`
- Create: `tests/e2e/capture-reference.spec.ts`
- Modify: `manager.sh`

This read-only task runs immediately after the browser harness exists and before visual CSS tuning or DNS cutover. The fixture preserves the source of truth after `goetzlegal.com` starts serving Kinsta.

- [ ] **Step 1: Write RED settle/capture tests**

Test delayed fonts, incomplete/lazy images, scroll-to-bottom section activation, return-to-top, viewport metadata, and rejection of any final origin other than `https://goetzlegal.com` unless an explicit read-only reference override is supplied.

```bash
./manager.sh test:capture --grep "reference capture contract"
```

Expected: failure because the helper/capture command does not exist.

- [ ] **Step 2: Implement deterministic read-only capture**

Wait for `document.fonts.ready`; require every image to be complete with non-zero natural dimensions; scroll through every major section to settle lazy content/animation; wait for layout stability; return to top. Capture full-page PNGs and record DOMRects/computed font size/line height/weight/colors for header, five homepage sections, major images/buttons, and footer. Store HTTP status, final URL, viewport, UTC capture time, and SHA-256 hashes in `geometry.json`.

- [ ] **Step 3: Capture and validate the live baseline**

```bash
GOETZ_REFERENCE_URL=https://goetzlegal.com ./manager.sh visual:capture-reference
test -s tests/visual/fixtures/legacy/home-1440x900.png
test -s tests/visual/fixtures/legacy/home-390x844.png
test -s tests/visual/fixtures/legacy/home-989x844.png
test -s tests/visual/fixtures/legacy/home-990x844.png
jq -e '.reference_url == "https://goetzlegal.com/" and (.components | length > 0)' \
  tests/visual/fixtures/legacy/geometry.json
sha256sum tests/visual/fixtures/legacy/*
```

Expected: all files are non-empty, final reference origin is correct, fonts/images settled, and hashes are recorded. The test does not click links, submit forms, log in, or mutate the legacy site.

- [ ] **Step 4: Commit the immutable reference**

```bash
git add tests/visual/fixtures/legacy tests/e2e/helpers/settle-page.ts \
  tests/e2e/capture-reference.spec.ts manager.sh
git diff --cached --check
git commit -m "test: preserve the legacy homepage visual baseline"
```

---

### Task 3: Bootstrap the required site plugin and transfer the stable block APIs safely

**Files:**

- Create: `wp-content/plugins/goetz-site/goetz-site.php`
- Create: `wp-content/plugins/goetz-site/includes/class-plugin.php`
- Create: `wp-content/plugins/goetz-site/includes/class-blocks.php`
- Create: `wp-content/plugins/goetz-site/includes/functions.php`
- Create: `wp-content/plugins/goetz-site/src/index.js`
- Create: `wp-content/plugins/goetz-site/tests/js/block-registration.test.js`
- Move: `wp-content/themes/goetz-legal/blocks/{hero,attorney-card,cta,faq-list,resource-links}/` to `wp-content/plugins/goetz-site/blocks/`
- Modify: `wp-content/themes/goetz-legal/functions.php`
- Create: `wp-content/plugins/goetz-site/tests/php/block-registration.php`

**Public interfaces:**

```php
namespace Goetz\Site;

final class Plugin {
    public static function boot(): void;
}

final class Blocks {
    public const EDITOR_HANDLE = 'goetz-site-block-editor';
    public static function register(): void;
    public static function names(): array;
}
```

The plugin header declares `Requires at least: 6.9`, `Requires PHP: 8.0`, and version `1.0.0`. `goetz-site.php` loads explicit internal files and boots once. `Blocks::register()` reads `build/index.asset.php`, registers the shared editor bundle, scans only first-party directories below `blocks/`, and registers each `block.json` through `register_block_type()`.

Each migrated `block.json` keeps `apiVersion: 3`, its current name and current attributes, changes `textdomain` to `goetz-site`, sets `editorScript` to `goetz-site-block-editor`, and keeps file-relative render/styles. Saved frontend output must remain compatible before editor enhancements.

- [ ] **Step 1: Write failing JavaScript and integration tests**

The JS test imports the block registry and expects exactly:

```js
[
  'goetz/attorney-card',
  'goetz/cta',
  'goetz/faq-list',
  'goetz/hero',
  'goetz/resource-links',
]
```

The WordPress integration script asserts each name appears in `WP_Block_Type_Registry`, renders representative legacy attributes, and asserts the generated wrapper classes/content. It deactivates the theme registration callback during the assertion so the plugin is proven independent.

- [ ] **Step 2: Run RED**

```bash
./manager.sh compose run --rm -w /app/wp-content/plugins/goetz-site node npm ci
./manager.sh site:test --runInBand --testPathPattern=block-registration
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/block-registration.php
```

Expected: failure because the plugin/bootstrap/bundle do not exist.

- [ ] **Step 3: Add bootstrap and shared editor registration**

The essential editor registration shape is:

```js
import { registerBlockType } from '@wordpress/blocks';
import hero from '../blocks/hero/block.json';
import HeroEdit from './blocks/hero/edit';

registerBlockType(hero.name, { edit: HeroEdit, save: () => null });
```

Implement a registry module that exports the metadata/edit pairs for testing. Move the five existing block directories with `git mv`; do not rename their APIs or remove URL fallback attributes.

- [ ] **Step 4: Preserve activation order**

Activate `goetz-site`, assert all five names, then remove `goetz_legal_register_blocks()` and its `init` hook from the theme. Make theme rendering fail visibly only in admin health checks if the required plugin is absent; frontend shared chrome must keep safe business-data fallbacks.

- [ ] **Step 5: Run GREEN**

```bash
./manager.sh site:build
./manager.sh wp plugin activate goetz-site
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/block-registration.php
```

Expected: the bundle exists; the integration script exits non-zero unless all five types register from the plugin, representative saved attributes render, the theme callback is absent, and no unsupported-block fallback appears. Content-page counts are verified only after the versioned page migration in Task 9.

- [ ] **Step 6: Commit the ownership transfer**

```bash
git add wp-content/plugins/goetz-site wp-content/themes/goetz-legal/functions.php
git diff --cached --check
git commit -m "feat: move stable Gutenberg blocks into the site plugin"
```

---

### Task 4: Add sanitized global Site Settings with theme fallbacks

**Files:**

- Create: `wp-content/plugins/goetz-site/includes/settings/class-site-settings.php`
- Create: `wp-content/plugins/goetz-site/includes/settings/class-settings-page.php`
- Create: `wp-content/plugins/goetz-site/assets/js/settings-media.js`
- Modify: `wp-content/plugins/goetz-site/includes/functions.php`
- Modify: `wp-content/plugins/goetz-site/includes/class-plugin.php`
- Create: `wp-content/themes/goetz-legal/inc/site-settings.php`
- Modify: `wp-content/themes/goetz-legal/functions.php`
- Modify: `wp-content/themes/goetz-legal/header.php`
- Modify: `wp-content/themes/goetz-legal/footer.php`
- Modify: `wp-content/themes/goetz-legal/template-parts/content-contact.php`
- Create: `tests/phpunit/unit/settings/SiteSettingsTest.php`
- Create: `wp-content/plugins/goetz-site/tests/php/site-settings.php`
- Create: `tests/e2e/settings.spec.ts`

**Public interfaces:**

```php
namespace Goetz\Site\Settings;

final class Site_Settings {
    public const OPTION_NAME = 'goetz_site_settings';
    public const OPTION_GROUP = 'goetz_site';
    public static function defaults(): array;
    public static function all(): array;
    public static function get(string $key, mixed $fallback = null): mixed;
    public static function sanitize(mixed $input): array;
    public static function sanitize_e164(string $value, string $fallback): string;
    public static function sanitize_url_or_path(string $value, string $fallback): string;
    public static function formatted_address(): string;
}

final class Settings_Page {
    public static function hooks(): void;
    public static function register(): void;
    public static function render(): void;
    public static function enqueue(string $hook_suffix): void;
}

function goetz_site_get_setting(string $key, mixed $fallback = null): mixed;
function goetz_legal_setting(string $key, mixed $fallback = null): mixed;
function goetz_legal_formatted_address(): string;
function goetz_legal_map_url(): string;
```

Exact defaults:

```php
[
    'business_name' => 'Goetz & Goetz',
    'alternate_name' => 'Goetz and Goetz',
    'phone_display' => '(239) 936-2841',
    'phone_e164' => '+12399362841',
    'email' => 'info@goetzlegal.com',
    'street_address' => '33 Barkley Cir Ste 100',
    'locality' => 'Fort Myers',
    'region' => 'FL',
    'postal_code' => '33907',
    'country_code' => 'US',
    'location_label' => 'Fort Myers, Florida',
    'cta_label' => 'Get Consultation',
    'cta_url' => '/contact/',
    'footer_disclaimer' => 'The content of this Website is intended to provide general information about Goetz & Goetz. The information provided is not an offer to represent you or create an attorney-client relationship. The content of any E-mail communication, facsimile or correspondence sent to Goetz & Goetz or to any of its attorneys will not, in and of itself, create an attorney-client relationship.',
    'footer_legal_copy' => 'The hiring of a lawyer is an important decision that should not be based solely upon advertisements. Before you decide, ask us to send you free written information about our qualifications and experience.',
    'copyright_start_year' => 2024,
    'copyright_text' => 'Goetz & Goetz. All Rights Reserved',
    'copyright_dynamic_year' => true,
    'social_image_id' => 0,
]
```

- [ ] **Step 1: Write RED unit tests**

Cover exact defaults, merge-with-current behavior for omitted fields, `sanitize_text_field`, `sanitize_email`/`is_email`, E.164 `/^\+[1-9]\d{7,14}$/`, root-relative or HTTP(S)-only CTA URLs, `wp_kses_post` legal copy, explicit boolean conversion, and image-attachment validation that preserves the previous/default value on invalid input.

```bash
./manager.sh phpunit:test --filter SiteSettingsTest
```

Expected: failure because `Site_Settings` is missing.

- [ ] **Step 2: Implement settings values and make unit tests GREEN**

`all()` merges stored values over defaults. `sanitize()` merges submitted fields over the current sanitized option; it must not reset a value merely because another field was omitted. Return only known keys.

```bash
./manager.sh phpunit:test --filter SiteSettingsTest
```

Expected: all settings unit cases pass.

- [ ] **Step 3: Write RED WordPress/browser tests for the page**

Assert `add_options_page()` slug `goetz-site-settings`, `manage_options`, Settings API registration, nonce-bearing form, current-user guard, field escaping, and Media Library enqueue only on `settings_page_goetz-site-settings`. In Playwright, an administrator saves a temporary phone/email and sees sanitized values; a subscriber receives 403/no access. Restore the original option in teardown.

- [ ] **Step 4: Implement the Settings API screen**

Use:

```php
register_setting(Site_Settings::OPTION_GROUP, Site_Settings::OPTION_NAME, [
    'type' => 'array',
    'sanitize_callback' => [Site_Settings::class, 'sanitize'],
    'default' => Site_Settings::defaults(),
]);
```

Render with `settings_fields()`, `do_settings_sections()`, and `submit_button()`. The image picker stores only the attachment ID and shows a WordPress-generated preview.

- [ ] **Step 5: Replace hard-coded runtime reads with the theme adapter**

Load `inc/site-settings.php` before templates. Keep the existing constants only as plugin-disabled fallback inputs. Header, footer, contact address/map, CTA defaults, legal copy, and copyright all call the adapter and escape for their output context.

- [ ] **Step 6: Run GREEN and commit**

```bash
./manager.sh phpunit:test --filter SiteSettingsTest
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/site-settings.php
./manager.sh test:e2e:auth --grep "Site Settings"
git add wp-content/plugins/goetz-site/includes wp-content/plugins/goetz-site/assets/js/settings-media.js \
  wp-content/themes/goetz-legal/inc/site-settings.php wp-content/themes/goetz-legal/functions.php \
  wp-content/themes/goetz-legal/header.php wp-content/themes/goetz-legal/footer.php \
  wp-content/themes/goetz-legal/template-parts/content-contact.php tests/e2e \
  tests/phpunit wp-content/plugins/goetz-site/tests/php/site-settings.php
git diff --cached --check
git commit -m "feat: add secure editable site settings"
```

---

### Task 5: Replace every placeholder editor with a real native editor UI

**Files:**

- Create: `wp-content/plugins/goetz-site/src/components/media-control.js`
- Create: `wp-content/plugins/goetz-site/src/components/link-control.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/hero/edit.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/attorney-card/edit.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/cta/edit.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/faq-list/edit.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/resource-links/edit.js`
- Modify: `wp-content/plugins/goetz-site/src/index.js`
- Modify: `wp-content/plugins/goetz-site/blocks/{hero,attorney-card,cta,faq-list,resource-links}/block.json`
- Modify: matching `render.php` and `style.css` files
- Create: `wp-content/plugins/goetz-site/tests/js/{hero,attorney-card,cta,faq-list,resource-links}.test.js`
- Create: `tests/e2e/gutenberg-existing-blocks.spec.ts`

**Attribute compatibility:**

- `goetz/hero`: retain `eyebrow`, `heading`, `content`, `imageUrl`, `imageAlt`, `buttonText`, `buttonUrl`; add `imageId` number default `0` and `buttonNewTab` boolean default `false`.
- `goetz/attorney-card`: retain all current attributes; add `imageId` number default `0` and `profileNewTab` boolean default `false`.
- `goetz/cta`: retain current attributes; add `backgroundImageId` number default `0`, `backgroundImageUrl` string default `''`, and `buttonNewTab` boolean default `false`.
- `goetz/faq-list`: retain the `items` array. Each item has `{question, answer}` and the editor supports add, delete, and reorder.
- `goetz/resource-links`: retain `groups`, `imageUrl`, `imageAlt`; add `imageId` number default `0`. Each group has `{heading, links:[{label,url,newTab}]}` with add, delete, and reorder controls; missing `newTab` is treated as `false` for old content.

All five dynamic blocks use `useBlockProps()`, native `RichText`, `MediaUpload`, `URLInputButton`/`LinkControl`, InspectorControls where appropriate, `supports.html: false`, and `save: () => null`. Media selection writes ID, URL, and alt for backward compatibility; PHP prefers the attachment ID and falls back to the stored URL. Each link control exposes an explicit “Open in new tab” toggle; renderers add `target="_blank" rel="noopener noreferrer"` only when that toggle is true.

- [ ] **Step 1: Write focused RED editor tests**

Mock WordPress data/components and assert each editor:

- renders current attribute content rather than a placeholder;
- sends the exact changed attribute from RichText;
- selects/removes media IDs and fallback URLs;
- handles link changes without erasing labels;
- adds/removes/reorders repeated FAQ/resource entries without mutating the original array;
- returns `null` from save.

```bash
./manager.sh site:test --runInBand --testPathPattern='(hero|attorney-card|cta|faq-list|resource-links)'
```

Expected: failure because the edit components do not exist.

- [ ] **Step 2: Implement shared controls and the five editors**

The media interface must follow this shape:

```js
<MediaUploadCheck>
  <MediaUpload
    allowedTypes={['image']}
    value={imageId || 0}
    onSelect={(media) => onChange({
      imageId: media.id,
      imageUrl: media.url,
      imageAlt: media.alt || '',
    })}
    render={({ open }) => <Button onClick={open}>Select image</Button>}
  />
</MediaUploadCheck>
```

Render RichText values as restricted HTML in PHP (`strong`, `b`, `em`, `br`, `a` only where designed), plain text through `esc_html()`, links through `esc_url()`, and images through `wp_get_attachment_image()` with intrinsic dimensions, `srcset`, and `sizes`.

- [ ] **Step 3: Make unit tests GREEN**

```bash
./manager.sh site:build
./manager.sh site:test --runInBand --testPathPattern='(hero|attorney-card|cta|faq-list|resource-links)'
```

Expected: all five editor suites pass and `build/index.asset.php` exists.

- [ ] **Step 4: Prove Gutenberg save/reload compatibility in RED/GREEN order**

The Playwright test creates a temporary draft, inserts each block, edits every field, selects an existing test image, changes repeated items, saves, reloads, and asserts:

- the saved values remain;
- no “invalid block” warning;
- no “modified externally”/dirty normalization notice;
- the post can update a second time;
- cleanup moves the temporary draft to trash.

```bash
./manager.sh test:e2e:auth --grep "existing Goetz blocks"
```

Expected after implementation: pass.

- [ ] **Step 5: Commit the editor upgrade**

```bash
git add wp-content/plugins/goetz-site/src wp-content/plugins/goetz-site/blocks \
  wp-content/plugins/goetz-site/tests tests/e2e/gutenberg-existing-blocks.spec.ts
git diff --cached --check
git commit -m "feat: make existing Goetz blocks natively editable"
```

---

### Task 6: Build the native Welcome block

**Files:**

- Create: `wp-content/plugins/goetz-site/blocks/welcome/block.json`
- Create: `wp-content/plugins/goetz-site/blocks/welcome/render.php`
- Create: `wp-content/plugins/goetz-site/blocks/welcome/style.css`
- Create: `wp-content/plugins/goetz-site/src/blocks/welcome/edit.js`
- Create: `wp-content/plugins/goetz-site/tests/js/welcome.test.js`
- Modify: `wp-content/plugins/goetz-site/src/index.js`
- Modify: `wp-content/plugins/goetz-site/tests/php/block-registration.php`
- Modify: `tests/e2e/gutenberg-existing-blocks.spec.ts`

**Block API:**

```json
{
  "apiVersion": 3,
  "name": "goetz/welcome",
  "attributes": {
    "leftImageId": {"type": "number", "default": 0},
    "leftImageUrl": {"type": "string", "default": ""},
    "leftImageAlt": {"type": "string", "default": ""},
    "rightImageId": {"type": "number", "default": 0},
    "rightImageUrl": {"type": "string", "default": ""},
    "rightImageAlt": {"type": "string", "default": ""},
    "heading": {"type": "string", "default": "<strong>Mr. Goetz welcomes</strong> you to browse this site to learn more about his firm and get information."},
    "contentPrefix": {"type": "string", "default": "If you would like to speak with Mr. Goetz, please call"},
    "phoneLabel": {"type": "string", "default": ""},
    "phoneUrl": {"type": "string", "default": ""},
    "contentJoin": {"type": "string", "default": "or contact the firm"},
    "onlineLabel": {"type": "string", "default": "online"},
    "onlineUrl": {"type": "string", "default": ""}
  }
}
```

Empty phone values resolve at render time to the Site Settings phone display and E.164 `tel:` URL; an empty online URL resolves to `/contact/`. This preserves the reference sentence as two distinct links: the phone number and “online.” Stored overrides remain editable. The decorative scale icon is presentation/seed media and has empty alt text.

- [ ] **Step 1: Write RED unit/registration/render tests**

Test both images, RichText heading/sentence fragments, phone and online settings fallbacks, independent explicit overrides, allowed markup, attachment-ID preference, URL fallback, and responsive image output.

```bash
./manager.sh site:test --runInBand --testPathPattern=welcome
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/block-registration.php
```

Expected: failure because `goetz/welcome` is not registered.

- [ ] **Step 2: Implement the editor and renderer**

Use one semantic `<section>`, a single `<h2>`, two independent Media Library controls, and editor styles identical to the completed frontend state. Do not embed source-site URLs as defaults.

- [ ] **Step 3: Run GREEN and browser round trip**

```bash
./manager.sh site:build
./manager.sh site:test --runInBand --testPathPattern=welcome
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/block-registration.php
./manager.sh test:e2e:auth --grep "Welcome block"
```

Expected: registration, editor, save/reload, and frontend render pass.

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/goetz-site/blocks/welcome wp-content/plugins/goetz-site/src \
  wp-content/plugins/goetz-site/tests tests/e2e
git diff --cached --check
git commit -m "feat: add the native Gutenberg welcome section"
```

---

### Task 7: Build the Practice Areas InnerBlocks API and measured scroll animation

**Files:**

- Create: `wp-content/plugins/goetz-site/blocks/practice-areas/{block.json,render.php,style.css,view.js}`
- Create: `wp-content/plugins/goetz-site/blocks/practice-area-item/{block.json,render.php,style.css}`
- Create: `wp-content/plugins/goetz-site/src/blocks/practice-areas/edit.js`
- Create: `wp-content/plugins/goetz-site/src/blocks/practice-area-item/edit.js`
- Create: `wp-content/plugins/goetz-site/tests/js/{practice-areas,practice-animation}.test.js`
- Modify: `wp-content/plugins/goetz-site/src/index.js`
- Modify: `wp-content/plugins/goetz-site/tests/php/block-registration.php`
- Create: `tests/e2e/practice-animation.spec.ts`

**Parent API:** `heading`, `backgroundImageId`, `backgroundImageUrl`, `backgroundImageAlt`, `scaleImageId`, `scaleImageUrl`, and `scaleImageAlt`. It permits only `goetz/practice-area-item`, seeds exactly Corporate, Construction, Real Estate, Probate, Criminal, Bankruptcy, and Appeals, and keeps child insertion/reorder unlocked.

**Child API:** `label` string only. It uses parent context for the scale image and is unavailable as a free-standing inserter item.

**Animation state contract:**

```js
const START_DELAY_MS = 200;
const ITEM_STAGGER_MS = 350;
const THRESHOLD = 0.15;
```

The server-rendered default is fully visible. JavaScript adds `is-animation-ready` only after initialization, observes once, and adds the completed/reveal states. The scale uses one second and `cubic-bezier(0.175, 0.885, 0.320, 1.275)`. Reduced motion skips timers/transitions and immediately completes. Re-entry never restarts.

- [ ] **Step 1: Write RED InnerBlocks tests**

Assert allowed block types, seven-item template, movable/removable children, heading/media edits, child label edits, `templateLock={false}` for children, and `supports.inserter=false` on the child.

- [ ] **Step 2: Write RED animation tests with fake timers**

Assert no hidden content before initialization, observer threshold `0.15`, first reveal at 200 ms, subsequent reveals at 550/900/1250/1600/1950/2300 ms, one-shot disconnect, final persisted state, immediate reduced-motion state, and all-visible behavior when IntersectionObserver is unavailable.

```bash
./manager.sh site:test --runInBand --testPathPattern='practice'
```

Expected: failure because both blocks and animation are missing.

- [ ] **Step 3: Implement blocks and progressive enhancement**

The parent editor uses:

```js
<InnerBlocks
  allowedBlocks={['goetz/practice-area-item']}
  template={DEFAULT_ITEMS.map((label) => ['goetz/practice-area-item', { label }])}
  templateLock={false}
  renderAppender={InnerBlocks.ButtonBlockAppender}
/>
```

The PHP parent renders `$content` inside the stable list wrapper; no manual child serialization. CSS uses an animation-ready ancestor to create the initial hidden transform and an explicit `.no-js`/default visible state.

Unlike leaf dynamic blocks, the parent save function must serialize its children:

```js
save: () => <InnerBlocks.Content />
```

The PHP `render.php` receives the resulting child markup in `$content`. A `save: () => null` parent is prohibited because it would discard the InnerBlocks on save.

- [ ] **Step 4: Run unit and browser GREEN gates**

```bash
./manager.sh site:build
./manager.sh site:test --runInBand --testPathPattern='practice'
./manager.sh test:e2e:auth --grep "Practice Areas editor"
./manager.sh test:public --grep "Practice Areas animation"
```

Playwright must test normal motion timing with tolerance, reduced motion, JavaScript-disabled visibility, scroll out/back one-shot behavior, keyboard editing/reorder, and 320/390/989/990/1440 widths.

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/goetz-site/blocks/practice-* wp-content/plugins/goetz-site/src \
  wp-content/plugins/goetz-site/tests tests/e2e/practice-animation.spec.ts
git diff --cached --check
git commit -m "feat: add editable practice areas and measured animation"
```

---

### Task 8: Build the Attorney Grid and finish Hero/CTA homepage presentation APIs

**Files:**

- Create: `wp-content/plugins/goetz-site/blocks/attorney-grid/{block.json,render.php,style.css}`
- Create: `wp-content/plugins/goetz-site/src/blocks/attorney-grid/edit.js`
- Create: `wp-content/plugins/goetz-site/tests/js/attorney-grid.test.js`
- Modify: `wp-content/plugins/goetz-site/blocks/attorney-card/{block.json,render.php,style.css}`
- Modify: `wp-content/plugins/goetz-site/blocks/hero/{block.json,render.php,style.css}`
- Modify: `wp-content/plugins/goetz-site/blocks/cta/{block.json,render.php,style.css}`
- Modify: `wp-content/plugins/goetz-site/src/index.js`
- Modify: `wp-content/plugins/goetz-site/tests/php/block-registration.php`
- Modify: `tests/e2e/gutenberg-existing-blocks.spec.ts`

**Attorney Grid API:** `heading` string default `Attorneys`; only `goetz/attorney-card` children; default template contains James L. Goetz and Gregory W. Goetz cards; children remain addable/removable/reorderable. A parent class controls the homepage flat/no-shadow variant and outlined buttons without altering attorney cards used on biography/staff pages.

As with Practice Areas, Attorney Grid uses `save: () => <InnerBlocks.Content />`; its dynamic PHP renderer wraps the persisted `$content` and never duplicates children into an attribute.

**Hero presentation contract:** desktop reference proportions; one H1; intentional RichText emphasis; text before circular image on mobile; hero image rendered eager with `fetchpriority="high"`; button uses the approved link component and external-link rel protection.

**CTA presentation contract:** the selected attachment renders as a CSS custom property or data-backed generated style that survives WordPress filtering; the gavel remains visibly present beneath the matched overlay. Empty label/URL falls back to Site Settings.

- [ ] **Step 1: Write RED tests**

Test the parent child restriction/template/reorder behavior, inherited homepage CSS class, absence of card shadow in grid output, outlined buttons, responsive image markup, Hero mobile source order, CTA attachment/fallback settings, and external `target=_blank` implying `rel="noopener noreferrer"`.

```bash
./manager.sh site:test --runInBand --testPathPattern='(attorney-grid|hero|cta)'
```

Expected: failure for missing grid and unfinished render contracts.

- [ ] **Step 2: Implement and build**

Use `$block->context` only if a child truly needs server-side context; prefer a parent descendant class for presentation. Do not duplicate child content into a parent attribute.

- [ ] **Step 3: Run GREEN/editor reload gates**

```bash
./manager.sh site:build
./manager.sh site:test --runInBand --testPathPattern='(attorney-grid|hero|cta)'
./manager.sh test:e2e:auth --grep "Attorney Grid|Hero block|CTA block"
```

Expected: all assertions pass with no invalid block warnings.

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/goetz-site/blocks wp-content/plugins/goetz-site/src \
  wp-content/plugins/goetz-site/tests tests/e2e
git diff --cached --check
git commit -m "feat: complete native homepage block sections"
```

---

### Task 9: Seed curated media and migrate the homepage once with native serialization

**Files:**

- Create tracked originals:
  - `wp-content/plugins/goetz-site/assets/seed/Goetz-Legal-Exterior-1.png`
  - `wp-content/plugins/goetz-site/assets/seed/PXL_20220818_164549897_2.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/Sue.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/law-scale-icon-purple.png`
  - `wp-content/plugins/goetz-site/assets/seed/firm-bg.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/JAMES-L.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/Greg-Website-Portrait-6.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/law-updates-bg.jpg`
  - `wp-content/plugins/goetz-site/assets/seed/GoetzLogo.png`
  - `wp-content/plugins/goetz-site/assets/seed/Goetz-footer-logo.png`
  - `wp-content/plugins/goetz-site/assets/seed/goetz-social-1200x630.jpg`
- Create: `wp-content/plugins/goetz-site/includes/migrations/class-media-seeder.php`
- Create: `wp-content/plugins/goetz-site/includes/migrations/class-homepage-migration.php`
- Create: `wp-content/plugins/goetz-site/includes/migrations/class-site-bootstrap.php`
- Create: `wp-content/plugins/goetz-site/includes/editor/class-homepage-editor.php`
- Create: `wp-content/plugins/goetz-site/includes/cli/class-migrate-command.php`
- Create: `wp-content/plugins/goetz-site/tests/php/homepage-migration.php`
- Create: `wp-content/plugins/goetz-site/config/homepage.php`
- Modify: `wp-content/plugins/goetz-site/includes/class-plugin.php`

**Public interfaces:**

```php
namespace Goetz\Site\Migrations;

final class Media_Seeder {
    public const META_KEY = '_goetz_site_seed_key';
    public function seed(string $key): int;
    public function seed_all(bool $dry_run = false): array;
}

final class Homepage_Migration {
    public const VERSION = 1;
    public const VERSION_OPTION = 'goetz_site_homepage_schema_version';
    public const BACKUP_META = '_goetz_site_homepage_backup_v1';
    public function plan(): array;
    public function apply(bool $force = false): array;
    public function build_blocks(array $attachment_ids): array;
}

final class Site_Bootstrap {
    public function plan(): array;
    public function apply(): array;
}

final class Homepage_Editor {
    public static function filter_settings(array $settings, WP_Block_Editor_Context $context): array;
}
```

CLI:

```bash
wp goetz-site migrate homepage --dry-run --format=json
wp goetz-site migrate homepage --apply --format=json
wp goetz-site migrate homepage --apply --format=json
```

The second apply reports `status=noop`. A forced rerun is reserved for a reviewed recovery and must not be part of ordinary deployment. The homepage apply command invokes `Site_Bootstrap` before serializing content. `Site_Bootstrap` assigns primary/footer WordPress menus only when their locations are empty, preserves every client-assigned menu, sets the seeded `GoetzLogo.png` as Custom Logo only when no custom logo exists, and sets the seeded 1200x630 social attachment in Site Settings only when that setting is empty. `Homepage_Editor` applies a root `templateLock='all'` only when the editor context is the configured front page; the Practice and Attorney InnerBlocks explicitly retain `templateLock={false}` so their children remain addable/removable/reorderable.

- [ ] **Step 1: Copy only original seed files and verify integrity**

Use ordinary filesystem copies during implementation, never generated WordPress thumbnails. Derive the exact 1200x630 social image once from the approved exterior/brand image, visually inspect it, and commit that curated derivative. Record SHA-256 hashes in `config/homepage.php` and make the integration test verify them before sideloading.

- [ ] **Step 2: Write RED migration tests**

In a disposable test page, assert:

- dry-run makes no option/post/media changes;
- seed lookup reuses `_goetz_site_seed_key` attachments;
- only an image MIME type is accepted;
- apply creates a protected original-content backup once;
- block order is Hero, Welcome, Practice Areas, Attorney Grid, CTA;
- every top-level block stores `lock: {"move": true, "remove": true}` while Practice/Attorney child blocks remain reorderable;
- the front-page editor rejects insertion/reorder/removal at the root while still allowing edits and all approved child operations inside the two InnerBlocks parents;
- parent/child parsing returns no invalid/freeform replacement;
- all image attributes have IDs and portable URLs;
- first apply writes version `1`, second apply is byte-for-byte no-op;
- post-version editor changes survive a normal rerun;
- failure before `wp_update_post()` leaves original content/version intact.
- unassigned primary/footer locations receive native WordPress menus, while existing client menu assignments and custom logo remain unchanged;
- the curated social attachment is exactly 1200x630 and fills only an empty `social_image_id` setting.

```bash
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/homepage-migration.php
```

Expected: failure because classes/config do not exist.

- [ ] **Step 3: Implement with WordPress serializers**

`build_blocks()` returns canonical block arrays, then calls:

```php
$content = serialize_blocks($blocks);
$parsed = parse_blocks($content);
if (serialize_blocks($parsed) !== $content) {
    throw new RuntimeException('Homepage block serialization did not round trip.');
}
```

Resolve the front page from `page_on_front`, require slug `home`, and report/stop on any mismatch in strict apply mode.

- [ ] **Step 4: Run RED/GREEN on a database snapshot**

Export a local DB first, run dry-run, apply, rerun, and inspect the editor:

```bash
./manager.sh db:export
./manager.sh wp goetz-site migrate homepage --dry-run --format=json
./manager.sh wp goetz-site migrate homepage --apply --format=json
./manager.sh wp goetz-site migrate homepage --apply --format=json
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/homepage-migration.php
./manager.sh test:e2e:auth --grep "production homepage template"
```

Expected: dry-run reports the five-section conversion; first apply succeeds; second is `noop`; editor shows the locked five-section tree and editable children with no invalid-block warning.

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/goetz-site/assets/seed wp-content/plugins/goetz-site/config/homepage.php \
  wp-content/plugins/goetz-site/includes/migrations wp-content/plugins/goetz-site/includes/cli \
  wp-content/plugins/goetz-site/includes/class-plugin.php wp-content/plugins/goetz-site/tests tests/e2e
git diff --cached --check
git commit -m "feat: add idempotent native homepage migration"
```

---

### Task 10: Make the legacy importer create-only and diff-gated

**Files:**

- Modify: `wp-content/plugins/goetz-migration/includes/class-scraper.php`
- Modify: `wp-content/plugins/goetz-migration/goetz-migration.php`
- Create: `wp-content/plugins/goetz-migration/tests/import-safety.php`
- Modify: `manager.sh`
- Modify: `README.md`

**Command contract:**

```bash
wp goetz-migration import --source=https://goetzlegal.com --dry-run
wp goetz-migration import --source=https://goetzlegal.com
wp goetz-migration import --source=https://goetzlegal.com --force-existing
```

Without `--force-existing`, the command may create a missing approved page but must skip every existing page and report its status. `--dry-run` reports a normalized human-readable block diff without writing posts, attachments, menus, options, or Yoast meta. `--force-existing` requires an interactive confirmation unless `--yes` is also supplied and remains unavailable through ordinary `manager.sh` deployment commands.

The plugin's wp-admin scan/import handler calls the same planner. Its screen says “create missing pages” rather than “create/update,” exposes dry-run discovery only, never exposes force-existing mode, enforces `manage_options` plus nonce, and makes no scan-result option/cache write during dry-run.

- [ ] **Step 1: Write RED safety tests**

Seed one existing page with editor changes and one absent test page. Assert default import preserves existing `post_content`, template, title, menu assignment, and `_yoast_wpseo_*` meta; dry-run writes no post/media/menu/option/transient/cache state; missing-page creation works; CLI force mode is explicit. Invoke the admin handler with valid capability/nonce and assert it uses create-only behavior; assert missing capability/bad nonce is rejected and no admin request can force an existing update. Assert import no longer stores an environment-specific `_yoast_wpseo_canonical`.

```bash
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-migration/tests/import-safety.php
```

Expected: failure because the importer currently updates existing pages and canonicals.

- [ ] **Step 2: Separate discovery, planning, and apply**

Create an import plan before any write. Replace the current unconditional update path with:

```php
if ($existing_id && !$force_existing) {
    $result['skipped_existing'][] = $slug;
    continue;
}
```

Use WordPress block parsing for the diff. Remove canonical import; titles/descriptions are owned by the new SEO configurator.

- [ ] **Step 3: Run GREEN and prove manager cannot force it**

```bash
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-migration/tests/import-safety.php
! grep -Eq 'force-existing|migrate:import' manager.sh
bash tests/contracts/repository-release.sh
```

Expected: all safety assertions pass and ordinary manager routing exposes only the new `goetz-site` migration.

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/goetz-migration manager.sh README.md tests/contracts/repository-release.sh
git diff --cached --check
git commit -m "fix: protect editor content from legacy reimports"
```

---

### Task 11: Match shared chrome, homepage geometry, and responsive/accessibility behavior

**Files:**

- Modify: `wp-content/themes/goetz-legal/header.php`
- Modify: `wp-content/themes/goetz-legal/footer.php`
- Modify: `wp-content/themes/goetz-legal/page-templates/template-home.php`
- Modify: `wp-content/themes/goetz-legal/resources/ts/app.ts`
- Modify: `wp-content/themes/goetz-legal/resources/scss/custom.scss`
- Modify: `wp-content/themes/goetz-legal/resources/scss/editor-style.scss`
- Modify: `wp-content/themes/goetz-legal/theme.json`
- Create: `tests/e2e/navigation-accessibility.spec.ts`
- Create: `tests/e2e/homepage-layout.spec.ts`

**Header/menu contract:**

- Call `wp_body_open()` immediately after `<body>` and render a visible-on-focus skip link to `#primary-content`.
- Give the main element `id="primary-content"` and retain one landmark.
- The menu button has `aria-controls="primary-navigation"`, `aria-expanded`, and an accessible label that changes between open/close.
- Desktop navigation is active at 990 px and above; the full-viewport overlay is active at 989 px and below.
- Open moves focus to the first menu link, traps Tab/Shift+Tab inside, Escape closes, close restores focus to the toggle, body scrolling is locked only while open, and resize to desktop resets state.
- The navigation and all content remain usable when JS fails.

**Visual contract:** exact approved brand palette; Roboto numeric weights 300/400/500/700; reference header/footer rhythm; desktop Hero 1% width/3% section tolerance; mobile copy before circular image; Welcome 3%/16 px tolerance; Practice section and animation geometry; flat attorney cards with outlined buttons; visible gavel CTA; no overflow from 320 through 1440.

- [ ] **Step 1: Write RED navigation/accessibility tests**

Test mouse and keyboard open/close, `aria-*`, Escape, focus trap/restore, body lock, resize cleanup, skip link, main target, one H1, heading order, visible focus, 44 px mobile targets, and no-JS navigation visibility.

```bash
./manager.sh test:public --grep "navigation accessibility"
```

Expected: failure for missing controls/focus behavior and the current dropdown layout.

- [ ] **Step 2: Implement semantic markup and menu controller**

Use a small controller with explicit `open()`, `close({restoreFocus})`, `onKeydown()`, and `onResize()` functions. Query focusable descendants at the time of each key event so menu edits do not stale the list. Do not intercept normal desktop Tab order.

- [ ] **Step 3: Write RED geometry tests before CSS changes**

Use the immutable Task 2A geometry/PNG fixtures. At 1440x900, 390x844, 989x844, 990x844, and 320x700, record candidate DOMRects and computed typography for header, each five homepage sections, primary images, buttons, footer columns, and full page. Assert the spec tolerances and `document.documentElement.scrollWidth === viewport.width`.

```bash
./manager.sh test:public --grep "homepage geometry"
```

Expected: failure for the audited hero/intro/practice/card/CTA/footer mismatches.

- [ ] **Step 4: Tune source styles, not generated artifacts**

Adjust `custom.scss`, editor style, and `theme.json`; use media queries at `max-width: 989px` and `min-width: 990px`. Remove the old `959px` split. Keep the editor in the final visible animation state. Do not add per-browser magic values outside the documented tolerance data.

- [ ] **Step 5: Run GREEN, axe, and no-JS gates**

```bash
./manager.sh theme:build
./manager.sh site:build
./manager.sh test:public --grep "navigation accessibility|homepage geometry|Practice Areas animation"
```

Expected: behavior/geometry pass at all viewports, no serious/critical axe violations, no overflow, no hidden no-JS content.

- [ ] **Step 6: Commit**

```bash
git add wp-content/themes/goetz-legal wp-content/plugins/goetz-site/blocks tests/e2e
git diff --cached --check
git commit -m "feat: match the reference homepage and responsive chrome"
```

---

### Task 12: Configure Yoast idempotently and extend its graph to LegalService

**Files:**

- Create: `wp-content/plugins/goetz-site/config/seo-pages.php`
- Create: `wp-content/plugins/goetz-site/includes/seo/class-yoast-configurator.php`
- Create: `wp-content/plugins/goetz-site/includes/seo/class-schema.php`
- Create: `wp-content/plugins/goetz-site/includes/cli/class-seo-command.php`
- Modify: `wp-content/plugins/goetz-site/includes/class-plugin.php`
- Modify: `wp-content/themes/goetz-legal/functions.php`
- Create: `tests/phpunit/unit/seo/YoastConfiguratorTest.php`
- Create: `wp-content/plugins/goetz-site/tests/php/seo-integration.php`
- Create: `tests/e2e/seo.spec.ts`
- Create: `tests/fixtures/seo-pages.json`

**Exact page metadata fixture:**

```json
{
  "home": ["Fort Myers Trial Attorneys | Goetz & Goetz", "Goetz & Goetz provides experienced legal counsel in Fort Myers for corporate, construction, real estate, probate, criminal and bankruptcy matters."],
  "james-l-goetz": ["James L. Goetz, Attorney | Goetz & Goetz", "Learn about James L. Goetz, a Fort Myers attorney with more than 50 years of experience in trial, probate, real estate and commercial litigation."],
  "gregory-w-goetz": ["Gregory W. Goetz, Attorney | Goetz & Goetz", "Learn about Gregory W. Goetz, a Fort Myers attorney serving clients in Florida state and federal courts across a range of legal matters."],
  "staff": ["Legal Team and Staff | Goetz & Goetz", "Meet the attorneys and legal staff at Goetz & Goetz in Fort Myers, Florida, and find direct contact information for the firm."],
  "questions": ["Florida Legal Questions | Goetz & Goetz", "Read answers from Goetz & Goetz to common Florida legal questions about construction, homestead protection, wills, real estate and dispute resolution."],
  "links": ["Florida and Federal Legal Links | Goetz & Goetz", "Find useful Florida and federal court, government, bar association, property, tax and legal resources selected by Goetz & Goetz."],
  "contact": ["Contact Goetz & Goetz | Fort Myers Attorneys", "Contact Goetz & Goetz in Fort Myers, Florida, by phone, email or online form to discuss your legal questions and request a consultation."]
}
```

**Public interfaces:**

```php
namespace Goetz\Site\SEO;

final class Yoast_Configurator {
    public const SCHEMA_VERSION = '1';
    public const VERSION_OPTION = 'goetz_site_yoast_schema_version';
    public function is_available(): bool;
    public function configure(bool $dry_run = false): array;
    public function desired_option_values(int $logo_id, int $social_image_id): array;
    public function configure_pages(bool $dry_run = false): array;
}

final class Schema {
    public static function filter_organization(array $piece, mixed $context = null): array;
    public static function exclude_post_type(bool $excluded, string $post_type): bool;
    public static function exclude_taxonomy(bool $excluded, string $taxonomy): bool;
    public static function fallback_graph(): array;
    public static function render_fallback(): void;
}
```

CLI: `wp goetz-site seo configure [--dry-run] [--strict] [--format=json]`.

- [ ] **Step 1: Write RED option/page tests**

Seed synthetic `googleverify`, `pinterestverify`, OAuth/integration arrays, and token-shaped values without printing them. Assert:

- unavailable Yoast returns `skipped` without a frontend fatal;
- only approved keys change via `WPSEO_Options::set($key, $value, $group)`;
- unmanaged values remain byte-for-byte identical;
- tracking, Semrush, and Wincher are false;
- XML sitemap, Schema, Open Graph, and Twitter summary-large-image are enabled;
- organization/site identity and custom-logo/social attachment ID+URL are set;
- exactly seven page titles/descriptions are written;
- `_yoast_wpseo_canonical` is deleted for these pages and no other Yoast meta is deleted;
- missing pages are reported, never created;
- second apply reports zero changes.

```bash
./manager.sh phpunit:test --filter YoastConfiguratorTest
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/seo-integration.php
```

Expected: failure because the configurator is missing.

- [ ] **Step 2: Implement allowlisted configuration**

Approved `wpseo` keys: `enable_xml_sitemap=true`, `enable_schema=true`, `tracking=false`, `semrush_integration_active=false`, `wincher_integration_active=false`.

Approved `wpseo_titles` values include company/alternate name, logo URL/ID, organization phone/email, disabled author/date/post-format/attachment archives, `noindex-page=false`, `noindex-post=true`, and noindex for category/tag/attachment. Approved `wpseo_social` values enable Open Graph/Twitter, set `summary_large_image`, and use the 1200x630 seeded social image URL/ID.

Record schema version after a successful apply, but do not use it to skip comparisons when Site Settings change.

- [ ] **Step 3: Write RED schema/sitemap tests**

Assert the Yoast organization piece retains its existing `@id`, logo/image, and graph links while changing `@type` to `['Organization', 'LegalService']` and adding dynamic `home_url('/')`, name, alternateName, E.164 phone, email, `PostalAddress`, and Fort Myers service area. Assert every post type except `page` and every taxonomy is excluded from sitemaps. Assert the non-Yoast fallback renders exactly one equivalent graph and the theme's current duplicate fallback is removed.

- [ ] **Step 4: Implement schema and CLI, then run GREEN twice**

```bash
./manager.sh wp goetz-site seo configure --dry-run --strict --format=json
./manager.sh wp goetz-site seo configure --strict --format=json
./manager.sh wp goetz-site seo configure --strict --format=json
./manager.sh wp yoast index --reindex --skip-confirmation
./manager.sh phpunit:test --filter YoastConfiguratorTest
./manager.sh wp eval-file /var/www/html/wp-content/plugins/goetz-site/tests/php/seo-integration.php
```

Expected: dry-run makes no writes; first apply reports changes; second reports `changed_options=0` and `changed_pages=0`; reindex succeeds.

- [ ] **Step 5: Browser-verify SEO output**

Recursively parse the sitemap index rather than assuming one URL. For all seven routes assert one exact title, description, portable self-canonical equal to the route under `GOETZ_EXPECT_ORIGIN` (defaulting to the selected base URL), Open Graph/Twitter image of 1200x630, and one JSON-LD graph containing `LegalService` and the correct address/contact. Assert sitemap has exactly seven page URLs under that same expected origin. Local mode permits only its configured localhost origin; staging permits only `goetzgoetz.kinsta.cloud`; production sets `GOETZ_EXPECT_PRODUCTION=1` and rejects both local and temporary origins everywhere. Assert author/date/attachment/unused taxonomy archives are absent in every environment.

```bash
./manager.sh test:public --grep "SEO contract"
```

Expected: all SEO assertions pass.

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/goetz-site/config/seo-pages.php wp-content/plugins/goetz-site/includes/seo \
  wp-content/plugins/goetz-site/includes/cli/class-seo-command.php \
  wp-content/plugins/goetz-site/includes/class-plugin.php wp-content/themes/goetz-legal/functions.php \
  tests/phpunit tests/e2e/seo.spec.ts tests/fixtures/seo-pages.json
git diff --cached --check
git commit -m "feat: configure portable Yoast SEO and LegalService schema"
```

---

### Task 13: Enforce the frozen reference with visual and route regression tests

**Files:**

- Verify: `tests/visual/fixtures/legacy/`
- Create: `tests/e2e/helpers/visual-compare.ts`
- Create: `tests/e2e/visual.spec.ts`
- Create: `tests/e2e/frontend.spec.ts`
- Modify: `.gitignore`
- Modify: `manager.sh`

**Fixture contract:** Task 2A has already captured and committed the legacy reference before tuning/cutover. This task refuses to run if any recorded fixture hash differs from `geometry.json`. Transient candidate/diff output stays below ignored `artifacts/`.

**Visual acceptance:** component SSIM at least `0.98` or at most `3%` changed pixels after the documented antialias threshold; total height within 5%; major widths within 1%; section heights within 3% or 16 px; font size within 1 px and line-height within 2 px; component geometry within 2 px desktop/4 px mobile. Mask only dynamic values explicitly named in `geometry.json`; do not mask whole content sections.

- [ ] **Step 1: Write RED comparison/helper tests**

Use synthetic identical and deliberately changed PNG fixtures to prove the comparator passes identical pixels and fails beyond each threshold. Reuse the already-tested page-settle helper from Task 2A.

```bash
./manager.sh test:public --grep "visual comparator contract"
```

Expected: failure because the comparator is missing.

- [ ] **Step 2: Validate the committed legacy fixture without recapturing it**

Run:

```bash
git diff --exit-code -- tests/visual/fixtures/legacy
jq -e '.components | length > 0' tests/visual/fixtures/legacy/geometry.json
sha256sum tests/visual/fixtures/legacy/*
```

Expected: fixture files are unchanged/non-empty and recorded component geometry exists. Never silently refresh the baseline to make a candidate pass.

- [ ] **Step 3: Implement candidate comparison and seven-route frontend suite**

The frontend suite checks all seven routes for 200, expected final URL, one H1 where appropriate, no console errors, no failed same-origin asset, complete images, no overflow at 320/390/989/990/1440, safe external links, and contact form rendering without submission.

- [ ] **Step 4: Run RED against known current mismatches, then GREEN after source tuning**

```bash
GOETZ_BASE_URL=http://localhost:8080 ./manager.sh visual:compare
./manager.sh test:public --grep "frontend routes|visual parity"
```

Expected final state: all component/geometry thresholds pass; any intentional corrected-font delta is narrowly documented rather than broadly masked.

- [ ] **Step 5: Commit comparison and route tests**

```bash
git add tests/visual tests/e2e manager.sh .gitignore
git diff --cached --check
git commit -m "test: enforce homepage visual and route parity"
```

---

### Task 14: Build a clean checksum release and allowlisted Kinsta deployment/rollback toolchain

**Files:**

- Create: `scripts/release/build.sh`
- Create: `scripts/release/verify.sh`
- Create: `scripts/release/remote-backup.sh`
- Create: `scripts/release/remote-apply.sh`
- Create: `scripts/release/cutover.sh`
- Create: `scripts/release/rollback.sh`
- Create: `tests/contracts/release-payload.sh`
- Modify: `manager.sh`
- Modify: `.gitignore`
- Modify: `README.md`
- Create: `docs/deployment/goetz-production-runbook.md`

**Release payload allowlist:**

```text
wp-content/themes/goetz-legal/
wp-content/plugins/goetz-site/
wp-content/plugins/goetz-migration/
wp-content/plugins/wordpress-seo/
wp-content/plugins/wpforms-lite/
release.json
RELEASE-MANIFEST.sha256
```

The payload contains generated theme `dist/` and `vendor/`, generated site-plugin `build/`, exact Composer-managed third-party directories, source needed by runtime, `release.json`, and `RELEASE-MANIFEST.sha256`. It excludes `.env*`, `.git`, SQL, uploads, root dev vendor, node_modules, tests, screenshots, maps not required at runtime, and local artifacts.

- [ ] **Step 1: Write RED payload/deployment tests**

Assert build refuses a dirty tree, refuses a commit not equal to `origin/main`, starts from `git archive <sha>` rather than the working directory, uses `npm ci`/locked Composer, validates required generated files/plugin headers/versions, emits hashes, and produces only the five runtime roots plus the two named root metadata files. Shell-test `remote-apply.sh`, `cutover.sh`, and rollback with fake `ssh`/`rsync` binaries. Assert they never target plugin root/MU plugins/core, never use unresolved/broad paths, always supply `StrictHostKeyChecking=yes` plus the pinned known-host source, and make no cutover write without exact from/to/verified-backup arguments plus `--apply`.

```bash
bash tests/contracts/release-payload.sh
```

Expected: failure because release scripts are missing.

- [ ] **Step 2: Implement the clean builder**

Build below `__dev/releases/<sha>/work`, verify exact dependencies, then assemble `payload/`. Set `SOURCE_DATE_EPOCH` from the release commit time. `release.json` records commit, branch, deterministic commit UTC time, WordPress compatibility range, PHP version, plugin versions, and Node/Composer lock hashes; it does not contain its own aggregate hash. `RELEASE-MANIFEST.sha256` hashes every payload file except itself, including `release.json`. The SHA-256 of the completed manifest is the aggregate release digest recorded outside the payload in the launch receipt. Run the builder twice and require identical file lists, file hashes, and manifest hash; normalize or remove any nondeterministic generated metadata.

- [ ] **Step 3: Implement coupled backup/apply/rollback**

Before apply, create `/www/goetzgoetz_755/private/backups/<timestamp>/` mode 0700 with:

- database SQL;
- uploads tarball;
- current tarballs for each of the five allowlisted code directories (record absent directories explicitly);
- active theme/plugin list and site/home URLs;
- `SHA256SUMS` mode 0600.

Download the packet to ignored `__dev/kinsta-backups/<timestamp>/` and compare local/remote hashes. Upload the release to `/www/goetzgoetz_755/private/releases/<sha>/`, verify `sha256sum -c`, then sync each explicit directory with `--delete-delay` only inside that directory. Rollback restores the coupled code/DB/uploads packet, prior activation state, rewrites/cache, and seven-route smoke.

- [ ] **Step 4: Add manager commands without secret leakage**

Expose `release:build`, `release:verify`, `remote:backup`, `remote:deploy`, `remote:cutover`, `remote:rollback`, and `verify:remote`. `remote:cutover` requires explicit `--from`, `--to`, and the verified pre-cutover backup ID; it defaults to dry-run and requires `--apply` for writes. Every remote command calls `require_kinsta_config`, requires an already-unlocked `SSH_AUTH_SOCK`, uses explicit quoted paths, and does not forward `.env` to Docker/SSH.

- [ ] **Step 5: Run GREEN and a local fake-remote rollback rehearsal**

```bash
bash -n manager.sh scripts/release/*.sh
bash tests/contracts/repository-release.sh
bash tests/contracts/release-payload.sh
./manager.sh release:build "$(git rev-parse HEAD)" || true
```

The first real builder may intentionally refuse until the branch is clean/pushed; the contract/fake-remote tests must pass and prove restore order and explicit targets.

- [ ] **Step 6: Commit**

```bash
git add scripts/release tests/contracts manager.sh .gitignore README.md docs/deployment/goetz-production-runbook.md
git diff --cached --check
git commit -m "feat: add checksum Kinsta release and rollback tooling"
```

---

### Task 15: Run the complete local production gate and independent code review

**Files:**

- Modify only files implicated by a failing test or accepted review finding.
- Create: `artifacts/local-production-gate/` (ignored evidence)
- Update: `docs/deployment/goetz-production-runbook.md`

- [ ] **Step 1: Start from a clean dependency/runtime state**

```bash
./manager.sh stop
./manager.sh compose pull
./manager.sh deps:install
./manager.sh start
./manager.sh install
./manager.sh theme:build
./manager.sh site:build
```

Expected: exact locked dependencies install; theme, `goetz-site`, `goetz-migration`, Yoast 28.0, and WPForms Lite 1.10.0.4 are active; no floating install occurs.

- [ ] **Step 2: Run static, unit, integration, and content gates**

```bash
bash tests/contracts/repository-release.sh
bash -n manager.sh scripts/release/*.sh
find wp-content/themes/goetz-legal wp-content/plugins/goetz-site wp-content/plugins/goetz-migration \
  -name '*.php' -print0 | xargs -0 -n1 php -l
find wp-content/plugins/goetz-site/blocks -name block.json -print0 | xargs -0 -n1 jq -e .
jq -e . wp-content/themes/goetz-legal/theme.json
./manager.sh compose run --rm -w /app composer composer validate --strict
./manager.sh compose run --rm -w /app composer composer audit --locked
./manager.sh test:unit
./manager.sh test:integration
./manager.sh test:compat
./manager.sh wp goetz-site migrate homepage --dry-run --format=json
./manager.sh wp goetz-site migrate homepage --apply --format=json
./manager.sh wp goetz-site migrate homepage --apply --format=json
./manager.sh wp goetz-site seo configure --strict --format=json
./manager.sh wp goetz-site seo configure --strict --format=json
./manager.sh wp yoast index --reindex --skip-confirmation
debug_start="$((debug_before + 1))"
if [[ -f wp-content/debug.log ]] && tail -n "+${debug_start}" wp-content/debug.log | grep -Eq 'PHP (Fatal|Parse|Warning)'; then
  echo 'New PHP fatal, parse error, or warning found in the production gate.' >&2
  exit 1
fi
```

Expected: syntax/schema/dependency/tests pass; migration and SEO second applies are no-op; no PHP warning/fatal is added to the debug log.

- [ ] **Step 3: Run the complete browser, accessibility, SEO, and visual gate**

```bash
./manager.sh test:e2e
GOETZ_BASE_URL=http://localhost:8080 ./manager.sh visual:compare
```

Expected: all Gutenberg round trips, Settings, five homepage sections, animation modes, menu/focus behavior, seven routes, assets, responsive widths, axe, exact SEO, sitemap/schema, and visual thresholds pass.

- [ ] **Step 4: Run production performance evidence**

Run Lighthouse against the built local site at desktop and mobile after a warm request. Record LCP, CLS, INP/TBT proxy, accessibility, best-practices, and SEO in the ignored gate artifact. Investigate new blocking assets, missing dimensions, or material regressions; do not invent a numeric score guarantee unsupported by the reference environment.

- [ ] **Step 5: Request an independent code review**

Use `superpowers:requesting-code-review` with the approved spec, this plan, `git diff ac7b53d..HEAD`, test output, and the definition of done. The reviewer must inspect:

- stable block compatibility and editor save normalization;
- escaping/capability/nonce/settings sanitization;
- migration idempotency and preservation of editor data;
- Yoast allowlist/secret preservation/schema duplication;
- accessibility/no-JS/reduced-motion behavior;
- release allowlists, path quoting, backup/rollback, and secret handling;
- visual acceptance evidence and test validity.

- [ ] **Step 6: Process review findings with evidence**

Use `superpowers:receiving-code-review`. Reproduce each valid issue with a failing focused test, implement the smallest correction, rerun the focused test, then rerun the complete relevant gate. Reject incorrect findings with concrete code/test evidence.

- [ ] **Step 7: Commit only verified review fixes**

```bash
git status --short
git diff --check
git diff --name-only
```

After inspecting `git diff --name-only`, run `git add` separately with each exact reviewed path; never use a wildcard or stage unrelated user changes.

```bash
git diff --cached --check
git diff --cached --name-only
git commit -m "fix: resolve production readiness review findings"
```

Skip this commit if review requires no changes.

---

### Task 16: Produce a clean pushed release commit and deterministic payload

**Files:**

- Generate ignored: `__dev/releases/<sha>/`

- [ ] **Step 1: Run verification-before-completion on the Git boundary**

```bash
git diff --check
git status --short
git log --oneline --decorate -12
git ls-files .env .env.local 2>/dev/null
git grep -n -E 'SSH_KEY_PW=|BEGIN (OPENSSH|RSA|EC) PRIVATE KEY|goetzgoetz@163\.192\.209\.112' -- . ':!docs/superpowers/plans/*' ':!docs/superpowers/specs/*' || true
```

Expected: clean worktree, no tracked secret file/value/private key. The documented SSH endpoint is allowed only in reviewed deployment documentation, not as a credential.

- [ ] **Step 2: Run the final full suite fresh**

```bash
./manager.sh test:all
GOETZ_BASE_URL=http://localhost:8080 ./manager.sh visual:compare
git diff --check
```

Expected: fresh passes, not cached claims.

- [ ] **Step 3: Push through the normal repository workflow**

```bash
git push origin main
git fetch origin main
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
test -z "$(git status --porcelain)"
```

Expected: local HEAD equals `origin/main` and tree is clean. If branch protection requires a PR, use the repository's normal PR/review path and do not deploy until the merged commit is fetched and verified.

- [ ] **Step 4: Build/verify from the pushed commit**

```bash
release_sha="$(git rev-parse HEAD)"
./manager.sh release:build "$release_sha"
./manager.sh release:verify "__dev/releases/$release_sha"
GOETZ_RELEASE_DIR="__dev/releases/$release_sha/payload" bash tests/contracts/repository-release.sh
sha256sum "__dev/releases/$release_sha/payload/RELEASE-MANIFEST.sha256"
```

Expected: payload passes all allowlist/generated-file/version/hash contracts and contains no secret/local export.

---

### Task 17: Unlock the key ephemerally, back up Kinsta, and deploy the release to staging

**Files:**

- Read without output: `.env` key `SSH_KEY_PW`
- Create remote: `/www/goetzgoetz_755/private/backups/<timestamp>/`
- Create local ignored: `__dev/kinsta-backups/<timestamp>/`
- Create remote: `/www/goetzgoetz_755/private/releases/<sha>/`

**Fixed target:** `goetzgoetz@163.192.209.112:43854`, WordPress root `/www/goetzgoetz_755/public`, staging `https://goetzgoetz.kinsta.cloud/`.

- [ ] **Step 1: Pin the SSH host identity before sending credentials**

Inspect the existing known-host entry for `[163.192.209.112]:43854` and its SHA-256 fingerprint. Compare it with the previously verified deployment record or the host fingerprint shown through the authenticated MyKinsta site details. Do not create trust from an unauthenticated `ssh-keyscan` result. Every subsequent SSH/SCP/rsync command uses `StrictHostKeyChecking=yes` and that pinned known-host file; stop on a missing/changed key and resolve it through MyKinsta before continuing.

- [ ] **Step 2: Start an isolated agent without exposing the passphrase**

Use an anonymous in-memory askpass helper (for example a Linux `memfd`) whose process reads `SSH_KEY_PW` directly from the mode-600 `.env`, supplies it only to `ssh-add`, then closes the descriptor. Do not put the value in shell history, command arguments, environment dumps, temporary disk files, tool output, or logs. Immediately `unset SSH_KEY_PW`; verify only key fingerprint/agent identity, never passphrase content.

- [ ] **Step 3: Resolve and validate the exact target read-only**

```bash
ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -p 43854 goetzgoetz@163.192.209.112 \
  'set -eu; cd /www/goetzgoetz_755/public; pwd; wp option get home; wp option get siteurl; wp core version; wp plugin list --format=json; wp theme list --format=json'
```

Expected: exact public root, current staging URL, current runtime inventory, and Kinsta MU plugin visible. Stop if the user/site/path differs.

- [ ] **Step 4: Create/download/verify the coupled rollback packet**

```bash
./manager.sh remote:backup
```

Expected: remote DB/uploads/five-code-dir packet and activation metadata exist with SHA256SUMS; local downloaded copy is non-empty; every local hash matches remote; database and tar archives pass integrity checks.

- [ ] **Step 5: Upload, verify, and apply the allowlisted payload**

```bash
release_sha="$(git rev-parse HEAD)"
./manager.sh remote:deploy "__dev/releases/$release_sha"
```

Remote apply order:

1. verify release manifest;
2. deploy/activate `goetz-site` while theme still has compatible saved names;
3. deploy theme, migration plugin, locked Yoast, and locked WPForms directories individually;
4. assert exact plugin headers/versions and active status;
5. run homepage migration dry-run, apply, and second no-op;
6. run strict SEO configure twice and Yoast reindex;
7. flush rewrites/object cache/Kinsta page cache;
8. assert no PHP fatal/parse errors and no import/release dump under public root.

- [ ] **Step 6: Keep the agent alive only for verification**

Do not remove the rollback packet. Retain the isolated agent until staging and production operations finish or until the work is paused; if paused, destroy it and recreate securely later.

---

### Task 18: Gate the staging deployment through the complete production story

**Files:**

- Create ignored: `artifacts/staging-<sha>/`
- Update working receipt notes only after evidence exists.

- [ ] **Step 1: Verify remote WordPress state and idempotency**

Run read-only SSH assertions for home/site URL, exactly seven published pages, front-page ID/slug, permalink structure, active theme, active `goetz-site`/`goetz-migration`/Yoast/WPForms versions, Kinsta MU plugin, registered nine block names, migration versions, and SEO second-run zero changes.

- [ ] **Step 2: Run remote frontend/editor/settings tests**

```bash
GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
GOETZ_E2E_ALLOW_REMOTE=1 \
./manager.sh test:e2e:auth
GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
./manager.sh test:public
```

Create a uniquely named temporary verification administrator through remote WP-CLI with a cryptographically random password held only in process memory; supply it on standard input through WP-CLI's `--prompt=user_pass`, never in arguments, shell history, files, artifacts, or logs. Install a shell trap that deletes the account and its content on success, failure, or interruption, pass the credential directly to Playwright's setup process, then assert the user no longer exists. Tests create only uniquely prefixed drafts/settings snapshots and restore/delete them. Never submit WPForms.

- [ ] **Step 3: Run staging visual/SEO/performance verification**

```bash
GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud ./manager.sh visual:compare
./manager.sh verify:remote https://goetzgoetz.kinsta.cloud
```

Expected: all seven routes and same-origin assets pass; editor round trips pass; settings capability/restore passes; animation/menu/a11y/visual thresholds pass; exact metadata/sitemap/LegalService graph contain no localhost. Each staging self-canonical equals its `goetzgoetz.kinsta.cloud` route; the test does not incorrectly require production canonicals before cutover.

- [ ] **Step 4: Exercise rollback readiness without destroying the verified site**

Run `remote:rollback --dry-run <backup-id>` and verify every source archive/hash/target and restoration order. If an isolated staging clone is available in Kinsta, perform a full restore rehearsal there; otherwise do not overwrite the passing staging site merely to demonstrate rollback.

- [ ] **Step 5: Stop before cutover on any failed gate**

If staging fails, leave legacy public DNS untouched, reproduce the failure locally, add a failing test, fix/commit/push/rebuild/rebackup/redeploy, and repeat this entire task. Do not patch production directly.

---

### Task 19: Verify Kinsta domain/TLS readiness and cut the public domain over safely

**External systems:** MyKinsta domain configuration and authoritative DNS for `goetzlegal.com`/`www.goetzlegal.com`.

- [ ] **Step 1: Capture authoritative pre-cutover state**

```bash
dig +noall +answer NS goetzlegal.com
dig +noall +answer DS goetzlegal.com
dig +noall +answer CAA goetzlegal.com
dig +noall +answer A goetzlegal.com
dig +noall +answer AAAA goetzlegal.com
dig +noall +answer A www.goetzlegal.com
dig +noall +answer AAAA www.goetzlegal.com
dig +noall +answer CNAME www.goetzlegal.com
dig +noall +answer MX goetzlegal.com
curl -fsSIL --max-redirs 5 https://goetzlegal.com/
```

Repeat the web queries against each authoritative nameserver so TTLs and stale AAAA/CAA/DNSSEC state are visible. Record current root/www web records and preserve all mail-related MX/SPF/DKIM/DMARC records. Inventory TXT record names/types/TTLs inside the authenticated DNS provider, but never print or copy TXT verification/token values into terminal output or artifacts; record only redacted presence/hash evidence. Do not infer a DNS target from memory; use the exact current MyKinsta instructions.

- [ ] **Step 2: Add and verify both domains in MyKinsta**

Add `goetzlegal.com` and `www.goetzlegal.com`, select the intended primary host, complete Kinsta ownership verification, and wait for TLS eligibility. Do not point public traffic until Kinsta accepts both hostnames and has a certificate path. Before mutating WordPress or DNS, use the exact MyKinsta target IP with `curl --resolve` for both hostnames to prove Kinsta serves the staged release under the production Host/SNI name with valid TLS and no application error.

- [ ] **Step 3: Take a second verified cutover backup**

Run `./manager.sh remote:backup` again after staging has passed and immediately before URL/DNS mutation. Label this packet `pre-domain-cutover`; download it, compare every checksum, and record its ID. This is distinct from the pre-deployment backup and is the recovery source for the URL conversion.

- [ ] **Step 4: Review the URL conversion and prepare exact recovery**

On Kinsta, run serialization-safe precise dry-run first:

```bash
./manager.sh remote:cutover \
  --from=https://goetzgoetz.kinsta.cloud \
  --to=https://goetzlegal.com \
  --backup-id=<pre-domain-cutover-id> \
  --dry-run
```

Review table/count output for unexpected domains. The approved apply sequence will set `home`/`siteurl`, rerun strict SEO configuration and Yoast index, flush rewrites/cache, and purge Kinsta cache. Because page canonicals were deleted, Yoast must derive the public URL rather than retain the temporary host.

Do not apply during this review step. Print the exact `./manager.sh remote:rollback --backup=<pre-domain-cutover-id>` recovery command in the private operator console, verify its dry-run targets/checksums, and keep it ready.

- [ ] **Step 5: Execute URL and web-DNS changes as one cutover window**

At the agreed cutover moment, run:

```bash
./manager.sh remote:cutover \
  --from=https://goetzgoetz.kinsta.cloud \
  --to=https://goetzlegal.com \
  --backup-id=<pre-domain-cutover-id> \
  --apply
```

The command applies the reviewed precise search-replace, sets `home`/`siteurl` to `https://goetzlegal.com`, reruns strict SEO configuration/Yoast reindex, flushes rewrites/object cache, and purges Kinsta cache. Immediately change only root/www web records to the exact MyKinsta targets while preserving mail/security records and removing any stale old-host AAAA record. Record the change receipt/time and old values for recovery.

Old-DNS users continue reaching the untouched legacy host during propagation; new-DNS users reach the already host-header/TLS-verified Kinsta release. The temporary Kinsta hostname may redirect during this window and is no longer the staging gate. If DNS application or the first new-host checks fail, restore the `pre-domain-cutover` coupled packet and old web records rather than attempting an ad hoc reverse replacement.

- [ ] **Step 6: Poll authoritative DNS and TLS without declaring success early**

Check each authoritative nameserver, public resolvers, root/www TLS, and redirects. Expected final behavior: both hosts present a valid certificate and one canonical host redirects once to `https://goetzlegal.com/` (or the explicitly selected primary), with no Kinsta temporary-domain redirect exposed to users.

If MyKinsta or authoritative DNS credentials are unavailable, this is the exact external-access gate. Continue every remaining read-only/preparatory check, document the needed account/action, and request that access; do not claim completion.

---

### Task 20: Prove the public launch, write the receipt, and destroy credential state

**Files:**

- Create/update: `docs/deployment/2026-07-17-gutenberg-launch-receipt.md`
- Update: `README.md` only if final operator commands changed

- [ ] **Step 1: Run fresh public smoke and asset checks**

For `/`, `/james-l-goetz/`, `/gregory-w-goetz/`, `/staff/`, `/questions/`, `/links/`, and `/contact/`, assert 200 after the intended canonical redirect, same-origin CSS/JS/images 200, no mixed content, no console failures, complete images, one main landmark, expected page title/H1, and no horizontal overflow. Check root and www separately for valid TLS and deterministic redirect behavior.

- [ ] **Step 2: Run the public Gutenberg/settings regression safely**

Recreate the same stdin-only, trap-deleted temporary verification administrator used on staging. Open the homepage editor, verify the locked five-section tree and editable child controls, make no content change, and leave without a dirty prompt. Open Site Settings, verify values render safely, and make no write unless the reversible test harness snapshots/restores the exact option. Confirm WordPress Menus supply header/footer navigation, delete the temporary account, and assert it is absent.

- [ ] **Step 3: Run fresh public visual, accessibility, animation, and responsive gates**

```bash
GOETZ_BASE_URL=https://goetzlegal.com ./manager.sh visual:compare
GOETZ_BASE_URL=https://goetzlegal.com \
GOETZ_EXPECT_ORIGIN=https://goetzlegal.com \
GOETZ_EXPECT_PRODUCTION=1 \
./manager.sh test:public --grep "frontend|navigation|Practice Areas animation|SEO contract"
GOETZ_BASE_URL=https://goetzlegal.com \
GOETZ_E2E_ALLOW_REMOTE=1 \
./manager.sh test:e2e:auth --grep "production homepage read-only|Site Settings read-only"
```

Expected: desktop/mobile/breakpoint parity, one-shot measured animation, reduced-motion/no-JS visibility, full-screen keyboard-safe menu, no serious/critical axe violations, and all accepted geometry thresholds pass.

- [ ] **Step 4: Verify final SEO/indexing contract**

Assert exactly seven page URLs across recursively parsed Yoast sitemaps; one exact title/description/self-canonical per route; one graph with `LegalService`, logo, phone, email, PostalAddress, and Fort Myers service area; 1200x630 Open Graph/Twitter image; desired robots directives; no `localhost`, `goetzgoetz.kinsta.cloud`, legacy canonical, author/date/attachment/unwanted archive URL in HTML or sitemap. Confirm `robots.txt` does not unintentionally disallow the site.

- [ ] **Step 5: Record the durable launch receipt**

The receipt includes:

- `deployed_release_sha` and confirmation it equaled `origin/main` when the payload was built and deployed;
- dependency/plugin/theme/runtime versions and lock hashes;
- release manifest hash and remote release path;
- backup ID, local/remote paths, checksums, and rollback command;
- migration/SEO schema versions and second-run no-op evidence;
- staging/public route, editor, settings, visual, accessibility, performance, SEO, sitemap, schema, DNS, TLS, and redirect results;
- exact DNS old/new web records and confirmation mail records were preserved;
- any accepted non-blocking deviation with evidence and owner;
- timestamp and final canonical URL.

Do not include credentials, private keys, tokens, verification values, cookies, auth state, or the SSH passphrase.

- [ ] **Step 6: Commit/push the evidence through the normal workflow**

```bash
git add docs/deployment/2026-07-17-gutenberg-launch-receipt.md README.md
git diff --cached --check
git commit -m "docs: record the verified Goetz production launch"
git push origin main
git fetch origin main
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
receipt_commit_sha="$(git rev-parse HEAD)"
deployed_release_sha="$(sed -n 's/^deployed_release_sha: //p' docs/deployment/2026-07-17-gutenberg-launch-receipt.md)"
printf 'Deployed release: %s\nReceipt commit: %s\n' "$deployed_release_sha" "$receipt_commit_sha"
```

Expected: `origin/main` now points to the later documentation-only `receipt_commit_sha`; the deployed code remains the separately recorded `deployed_release_sha`. Do not claim the deployed server equals the receipt commit.

- [ ] **Step 7: Destroy ephemeral credential state and request user cleanup**

Run `ssh-add -D`, terminate the isolated agent, remove its socket/helper descriptor, unset `SSH_AUTH_SOCK`, and verify the agent no longer lists identities. Tell the user to remove `SSH_KEY_PW` from `.env`; never read it again merely to confirm removal.

- [ ] **Step 8: Apply verification-before-completion to every definition-of-done item**

Cross-check the table below against fresh evidence. Only after every row is PASS may the active goal be marked complete.

## Requirement-to-evidence matrix

| Requirement | Primary implementation | Required proof |
|---|---|---|
| Native editable homepage | Tasks 3, 5-9 | Gutenberg insert/edit/save/reload, five-section locked tree, no invalid blocks |
| Reference visuals and animation | Tasks 7, 8, 11, 13 | Legacy fixtures, geometry thresholds, SSIM/pixel gate, normal/reduced/no-JS timing |
| Shared editable business/footer data | Task 4 | Sanitization unit tests, capability/nonce browser test, header/footer/contact render |
| Theme-independent stable blocks | Task 3 | Plugin-only registry/render integration test with existing names |
| Reproducible Yoast/SEO | Tasks 2, 12 | Exact version, allowlist preservation, seven metadata rows, sitemap/schema/canonical tests |
| Locked clean builds | Tasks 1, 2, 14, 16 | Committed locks, clean install/build, pushed SHA, checksum payload |
| Safe idempotent migrations | Tasks 9, 10 | Dry-run/no-write, backup, apply/no-op, editor-change preservation |
| Automated production gates | Tasks 5-15 | Unit/integration/E2E/a11y/visual/SEO/frontend complete suite |
| Normal committed/pushed workflow | Tasks 15, 16, 20 | Scoped commits, clean tree, HEAD equals origin/main |
| Kinsta backup/deploy/rollback | Tasks 14, 17, 18 | Coupled checksum backup, allowlisted deploy, dry-run/rehearsed rollback, staging pass |
| Public launch | Tasks 19, 20 | Authoritative DNS, valid TLS/redirects, public full suite, durable receipt |

## Plan self-review checklist

- [x] Every approved design requirement maps to an implementation task and a fresh verification gate.
- [x] All custom block names/attributes preserve current saved-content compatibility or add fallback fields.
- [x] All behavior changes start with a focused failing test and name the expected failure.
- [x] All database/remote mutations have a dry-run/read-only check, exact target, backup, idempotency assertion, and rollback path.
- [x] All settings/SEO writes are allowlisted, capability-protected where applicable, sanitized, escaped, and tested for preservation of omitted/unmanaged values.
- [x] Exact dependency, build, release, and remote allowlists are stated; floating install/destructive DB deployment is prohibited.
- [x] The plan contains no passphrase, token, private key, verification value, cookie, or copied secret.
- [x] The unfinished-marker and unresolved-path placeholder scan is clean.
- [x] Public cutover and complete production verification—not staging—are the final definition of done.
