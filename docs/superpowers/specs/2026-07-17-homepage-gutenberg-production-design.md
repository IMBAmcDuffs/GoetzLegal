# Goetz & Goetz Homepage Gutenberg Production Design

**Date:** 2026-07-17

**Status:** Approved design

**Target:** WordPress 6.9+ on the existing Kinsta site

**Reference site:** `https://goetzlegal.com/`

**Staging site:** `https://goetzgoetz.kinsta.cloud/`

## Objective

Reproduce the public Goetz & Goetz homepage as closely as practical while replacing its page-builder implementation with a production-grade, handoff-friendly WordPress architecture. The homepage must be managed through native Gutenberg controls, preserve the legacy scroll animation, match the shared header and footer, include reproducible Yoast configuration, and deploy safely through the repository to Kinsta.

The release is intended to be ready for public launch during the week of 2026-07-13. Public cutover remains dependent on Kinsta custom-domain and DNS access.

## Approved decisions

- Use native Gutenberg blocks. Do not install ACF because the client has no ACF Pro license and ACF Free does not provide the required block or options-page features.
- Keep the existing classic/hybrid theme rather than converting to a full block theme.
- Use WordPress Menus for primary and footer navigation.
- Provide a small Site Settings screen for global business and footer details.
- Put content blocks, settings, migrations, and SEO automation in a tracked `goetz-site` plugin.
- Keep presentation, page templates, and shared chrome in the `goetz-legal` theme.
- Manage free third-party plugins through exact Composer constraints and a committed lockfile rather than committing vendor source trees.
- Make the legacy migration plugin create-only by default after the handoff.
- Deploy incremental, idempotent migrations. Do not use full database replacement for ordinary releases.
- Preserve current valid semantic, accessibility, security, and link improvements even where the legacy site contains broken or outdated implementation details.

## Scope

### In scope

- Homepage content and visual parity from header through footer.
- Full-screen mobile navigation and the legacy responsive breakpoint behavior.
- Native editor controls and editor/frontend visual parity for homepage blocks.
- Scroll-triggered practice-area animation with reduced-motion and no-JavaScript fallbacks.
- Global phone, email, address, CTA defaults, and footer legal text.
- Reproducible Yoast installation, configuration, metadata, sitemap, and schema.
- Repository dependency locks, build changes, deployment commands, tests, receipts, and rollback support.
- Regression smoke checks for all seven public routes.

### Out of scope for this release

- Converting the site into a full Site Editor block theme.
- Adding ACF or any paid plugin.
- Rebuilding non-homepage body layouts beyond changes required to prevent regressions.
- Reproducing broken legacy font requests, dead links, duplicate H1 elements, placeholder contact links, or inaccessible markup.
- Search Console, analytics, social-network, Semrush, or Wincher account connection without client-owned credentials.
- DNS or domain-provider changes without the required account access.

## Architecture and ownership

| Layer | Responsibility |
|---|---|
| `wp-content/plugins/goetz-site/` | Native blocks, Site Settings, migrations, media seeding, Yoast configuration, schema extensions, and WP-CLI release commands |
| `wp-content/themes/goetz-legal/` | Frontend presentation, editor styling, templates, header/footer rendering, menu output, and responsive behavior |
| Root Composer manifest and lock | Exact official Yoast and WPForms Lite versions plus installation paths |
| Theme and site-plugin package locks | Exact JavaScript/build dependencies and reproducible `npm ci` builds |
| WordPress database | Client-edited block content, menu assignments, media records, and sanitized global settings |
| `goetz-migration` | Initial legacy discovery/import only; existing pages require explicit dry-run, diff, and force approval |

The current `goetz/*` block names are stable content APIs. Their registration will move from the theme to `goetz-site` without renaming saved blocks. Theme-side registration will be removed only after the site plugin registers successfully so no content disappears during the transition.

`goetz-site` is a required runtime dependency because it owns the dynamic block renderers. Deployment and health checks must fail clearly if it is missing or inactive. Global theme fallbacks retain current business details for shared chrome, and the transition sequence activates and verifies the plugin before removing theme-side block registration so activation order cannot create a blank homepage.

## Repository and dependency contract

- Add a root `composer.json` and committed `composer.lock` using exact WordPress.org plugin packages and `composer/installers` paths.
- Pin Yoast and WPForms Lite to the versions verified locally and on Kinsta. The initial Yoast target is 28.0; the lockfile is authoritative.
- Commit custom plugin source, block metadata, JavaScript/TypeScript source, PHP renderers, SCSS/CSS source, seed definitions, tests, and all lockfiles.
- Commit the theme `package-lock.json` and any site-plugin package lock. Use `npm ci`, not floating `npm install`, in release builds.
- Keep `vendor/`, `node_modules/`, runtime uploads, generated thumbnails, local exports, and secrets out of Git.
- Build artifacts are generated from the committed source and lockfiles, verified, and included in the deployment payload. They are not the source of truth.
- Curated original assets required to seed the homepage live under a tracked `assets/seed/` directory. The migration command sideloads them idempotently into the Media Library and records attachment IDs.
- Add an explicit `!.env.example` Git ignore exception while continuing to ignore `.env` and credentials.
- Never commit verification tokens, OAuth data, SSH passphrases, plugin-account credentials, or site-specific secret integrations.

## Gutenberg editing model

The homepage will use a locked top-level block template. Editors can change content and allowed child ordering without altering the structural wrappers, breakpoints, or spacing that provide visual parity.

### `goetz/hero`

Enhance the existing block with a real editor interface for:

- Eyebrow.
- Rich heading with controlled emphasis.
- Body copy.
- Circular Media Library image and alt text.
- Button label and internal/external link.

The frontend must match the legacy desktop dimensions and place copy before the circular image on mobile.

### `goetz/welcome`

Add a native dynamic block for:

- Left and right Media Library images with alt text.
- Heading and controlled emphasis.
- Supporting paragraph.
- Contact link whose default phone/contact values come from Site Settings.

This replaces the opaque `core/html` welcome section.

### `goetz/practice-areas`

Add a native parent block with:

- Heading and controlled emphasis.
- Background/feature image.
- Scale icon image.
- Restricted InnerBlocks containing only `goetz/practice-area-item` blocks.
- Native add, remove, and reorder controls for the child items.

Each child item stores a short editable label. The seven seeded labels are Corporate, Construction, Real Estate, Probate, Criminal, Bankruptcy, and Appeals.

The block replaces the opaque `core/html` practice section and owns the scroll animation.

### `goetz/attorney-grid`

Add a native parent block restricted to `goetz/attorney-card` children. Editors can reorder the cards and edit each attorney's name, short biography, image, alt text, and profile URL. The homepage style uses bare images, no card shadow, and outlined legacy-style buttons.

### `goetz/cta`

Enhance the existing block with native controls for eyebrow, heading, background image, button label, and link. The default gavel asset must render with the legacy overlay instead of a flat dark background.

### Block editor requirements

- All custom blocks use `apiVersion: 3` and metadata registration.
- Editor wrappers use `useBlockProps`; dynamic PHP wrappers use `get_block_wrapper_attributes`.
- Dynamic blocks disable direct HTML editing and expose only intentional supports.
- Text uses native RichText controls; media uses MediaUpload/Media Library IDs; links use native URL controls.
- Image rendering uses attachment IDs through `wp_get_attachment_image` to provide intrinsic dimensions, `srcset`, and `sizes`.
- Frontend block styles also load in the iframed editor so the saved page resembles the public result.
- The editor must save and reload without invalid-block or dirty-normalization warnings.
- Top-level section order is content-locked. Practice areas and attorney cards remain reorderable within their parents.

## Practice-area animation contract

The live reference animation has been measured and will be reproduced intentionally:

- Use an IntersectionObserver and trigger once when at least 15% of the practice list enters the effective viewport.
- Wait 200 ms before starting.
- Reveal item zero immediately after the initial delay and each subsequent item at 350 ms intervals.
- Animate the scale icon from 0.5 scale and 0.1 opacity to full scale/opacity over one second.
- Use `cubic-bezier(0.175, 0.885, 0.320, 1.275)` to reproduce the spring effect.
- Grow the vertical connector as items reveal.
- Persist the completed state when the section leaves and re-enters the viewport.
- Under `prefers-reduced-motion: reduce`, render the final state immediately with no delay or transition.
- Without JavaScript, render all content visible. JavaScript may enhance visibility but may never be required to expose content.
- The editor shows the completed state rather than repeatedly running the animation while editing.

## Shared header, navigation, footer, and Site Settings

The Site Settings screen is available only to users with `manage_options`. It uses the WordPress Settings API with nonce and capability enforcement, field-specific sanitizers, and escaped output.

Settings include:

- Business name and alternate name.
- Display phone and normalized E.164 telephone link.
- Primary email address.
- Street address, locality, region, postal code, and displayed location label.
- Default consultation CTA label and URL.
- Footer disclaimer/legal copy.
- Copyright text with a dynamic current-year option.
- Default social-share image attachment.

The existing WordPress Custom Logo remains the logo source. Primary and footer menu locations remain managed through WordPress Menus.

The responsive header must retain desktop parity, switch to the mobile mode at the legacy 989/990 px boundary, and open a full-viewport overlay. The menu button must expose `aria-controls` and `aria-expanded`, support Escape, move focus predictably, restore focus when closed, and keep all interactive targets keyboard accessible.

The footer must match the legacy spacing, typography, columns, logo, navigation, contact details, disclaimer, and copyright treatment while sourcing editable values from Site Settings.

## Content and migration flow

1. The first `goetz-site` migration registers settings and block types without changing existing page content.
2. A dry-run command parses the existing homepage and reports the exact conversion to the approved native block tree.
3. The apply command backs up the original `post_content`, converts the page once using WordPress block serialization APIs, resolves seed media to attachment IDs, and stores a migration version.
4. Rerunning the same migration is a no-op.
5. A newer migration may transform only content owned by that migration version and must not replace arbitrary editor changes.
6. `goetz-migration` changes to create-only behavior. Existing-page updates require explicit `--dry-run`, a human-readable diff, and a separate force flag.

Manual block-comment concatenation is prohibited. Generated content uses WordPress parse/serialize functions so special characters cannot corrupt delimiters or cause editor normalization.

## Yoast SEO design

Yoast is installed from the Composer lock and configured by an idempotent `wp goetz-site seo configure` command. The command merges only allowlisted option keys and never replaces entire Yoast option arrays that may contain verification or integration data.

### Global configuration

- Company name: `Goetz & Goetz`.
- Alternate name: `Goetz and Goetz`.
- Logo: resolve the current WordPress custom-logo attachment.
- Enable XML sitemaps, Schema.org graph output, Open Graph, and Twitter card metadata.
- Disable usage tracking.
- Disable unused author, date, attachment, post-format, and unused taxonomy archives.
- Disable Semrush and Wincher until the client explicitly connects them.
- Use the tracked exterior/brand image cropped to 1200x630 as the default social image.
- Remove environment-specific stored canonicals and allow Yoast to emit self-referencing URLs from the current production site URL.

### Page metadata

| Slug | SEO title | Meta description |
|---|---|---|
| `home` | Fort Myers Trial Attorneys \| Goetz & Goetz | Goetz & Goetz provides experienced legal counsel in Fort Myers for corporate, construction, real estate, probate, criminal and bankruptcy matters. |
| `james-l-goetz` | James L. Goetz, Attorney \| Goetz & Goetz | Learn about James L. Goetz, a Fort Myers attorney with more than 50 years of experience in trial, probate, real estate and commercial litigation. |
| `gregory-w-goetz` | Gregory W. Goetz, Attorney \| Goetz & Goetz | Learn about Gregory W. Goetz, a Fort Myers attorney serving clients in Florida state and federal courts across a range of legal matters. |
| `staff` | Legal Team and Staff \| Goetz & Goetz | Meet the attorneys and legal staff at Goetz & Goetz in Fort Myers, Florida, and find direct contact information for the firm. |
| `questions` | Florida Legal Questions \| Goetz & Goetz | Read answers from Goetz & Goetz to common Florida legal questions about construction, homestead protection, wills, real estate and dispute resolution. |
| `links` | Florida and Federal Legal Links \| Goetz & Goetz | Find useful Florida and federal court, government, bar association, property, tax and legal resources selected by Goetz & Goetz. |
| `contact` | Contact Goetz & Goetz \| Fort Myers Attorneys | Contact Goetz & Goetz in Fort Myers, Florida, by phone, email or online form to discuss your legal questions and request a consultation. |

The SEO command keys pages by slug, preserves client verification fields, and records its schema version so it can be rerun safely.

### Structured data

Extend Yoast's organization graph rather than emitting a duplicate graph. The organization piece will identify the firm as a `LegalService` and include:

- Name and alternate name.
- Production URL and logo.
- Telephone and email.
- PostalAddress from Site Settings.
- Fort Myers, Florida service area.

After configuration, run the supported Yoast reindex command and verify that indexable processing completes.

## Visual and responsive acceptance

The intended Roboto family will load correctly; the release will not reproduce the legacy site's broken cross-origin font URLs.

Acceptance viewports are 1440x900 and 390x844, plus breakpoint probes at 989 and 990 px.

- Homepage section order and required assets match the legacy site exactly.
- Total rendered homepage height is within 5% after fonts and images settle.
- Large container and image widths are within 1% of the reference.
- Section heights are within 3% or 16 px, whichever is larger.
- Typography size is within 1 px and line height within 2 px; intended numeric weights match.
- Header, logo, buttons, and form/control geometry are within 4 px on mobile and 2 px on desktop where the reference behavior remains valid.
- Brand colors use the approved exact palette values.
- Homepage attorney cards are flat, use the reference image crop, and use outlined buttons.
- The CTA visibly renders the gavel background with the matched overlay.
- Mobile hero order, full-screen navigation, footer stacking, and tap targets match the reference intent.
- No horizontal overflow occurs from 320 px through 1440 px.
- Component-level visual regression is at least 0.98 SSIM or no more than 3% changed pixels after a small antialiasing threshold, excluding the intentionally corrected font loading.

## Accessibility and resilience gates

- One semantic H1 on the homepage with a logical heading hierarchy.
- `wp_body_open`, skip link, and a targetable main landmark.
- Visible keyboard focus on every interactive control.
- Mobile navigation keyboard and focus management described above.
- Text contrast meets WCAG 2.2 AA for normal text.
- Images have appropriate alt text or explicitly empty alt text when decorative.
- Link/button labels have accessible names and do not rely on icons alone.
- Motion respects reduced-motion preferences.
- Resource loading, animation, and smooth-scroll behavior never trap focus or hide content.
- The site remains navigable if custom frontend JavaScript fails.

## Verification plan

### Static and build checks

- Composer validation and a clean locked install.
- `npm ci` and production builds for theme and site plugin.
- PHP syntax checks for every changed PHP file.
- JSON/schema validation for each `block.json` and `theme.json`.
- Deterministic block and plugin scans.
- Git whitespace and staged-scope checks.

### Automated behavior checks

- Unit tests for settings sanitization, fallback resolution, migration versioning, block serialization, and SEO configuration merging.
- WordPress integration tests for plugin activation, registered blocks, idempotent migrations, attachment reuse, and Yoast configuration.
- Gutenberg browser tests for insertion, text editing, media selection, child addition/reordering, save, reload, and absence of invalid-block warnings.
- Browser tests for Site Settings capability enforcement and safe saving.
- Frontend tests for desktop/mobile menu behavior, keyboard paths, animation timing, reduced motion, no-JavaScript visibility, links, and responsive image output.

### Release verification

- Capture full-page legacy and candidate screenshots after scrolling all lazy content.
- Compare desktop/mobile geometry and visual thresholds above.
- Crawl all seven routes and required same-origin assets.
- Assert HTTP 200, no unexpected redirects, no console errors, no failed same-origin assets, and no horizontal overflow.
- Assert one title, one canonical, and one meta description per route.
- Assert sitemap contains exactly the seven canonical public pages.
- Assert one Yoast schema graph with a `LegalService` organization and correct contact data.
- Assert no `localhost`, temporary Kinsta hostname, or legacy host-specific canonical remains in production HTML or sitemaps.
- Run a production performance audit and record Core Web Vitals/Lighthouse results; investigate material regressions before launch.

## Deployment and rollback

### Incremental deployment

1. Preserve and review existing user-owned worktree changes; stage only intentional release files.
2. Commit scoped source, dependency locks, tests, documentation, and deployment changes.
3. Build from the exact release commit in a clean dependency state.
4. Back up the Kinsta database and uploads and record checksums/paths.
5. Deploy only the allowlisted theme, `goetz-site`, `goetz-migration`, and Composer-managed plugin directories. Never delete the whole plugin directory or Kinsta MU plugins.
6. Assert exact plugin versions and activate required plugins.
7. Run the versioned site/content migration and Yoast configuration/reindex commands.
8. Flush rewrites and caches.
9. Run the staging smoke, editor, SEO, accessibility, and visual suites.
10. Configure the Kinsta custom domain and DNS when access is available.
11. Repeat production verification after cutover and record a launch receipt.

Ordinary releases must not import a full local database into production because doing so could overwrite handoff edits, forms, SEO values, and environment-specific data.

### Rollback

- If code activation fails, restore the previous release commit and rerun the deployment allowlist.
- If a migration fails after writing, restore the pre-release database backup and the previous code release.
- If visual, SEO, accessibility, asset, or route gates fail before cutover, keep the legacy domain in place.
- If gates fail after cutover, revert DNS only if necessary and restore the last verified Kinsta release.
- Record what was restored, the backup location, checksums, and the post-rollback verification result.

## Security and secret handling

- `.env` remains mode-restricted and ignored.
- `SSH_KEY_PW` is used only to unlock a temporary local SSH agent and is unset before child processes run.
- No password, passphrase, private key, token, or verification value is printed, copied into commands, committed, or stored in deployment artifacts.
- Settings writes require capability checks, nonces, early sanitization, and escaped output.
- External links opened in a new tab use `noopener noreferrer`.
- Deployment scripts use explicit paths and allowlists and avoid destructive whole-directory syncs.

## Definition of done

The work is complete only when all of the following are true and evidenced:

1. The approved homepage block tree is editable in Gutenberg without raw HTML or placeholder-only editors.
2. The rendered homepage, shared header/footer, mobile menu, section rhythm, imagery, buttons, and animation pass the visual acceptance criteria.
3. Site Settings safely control shared business and footer values with fallbacks.
4. Native blocks remain registered independently of the theme and existing `goetz/*` content remains compatible.
5. Yoast is installed at the locked version and passes metadata, sitemap, canonical, schema, and environment-URL checks.
6. Composer and Node dependencies are locked and clean-clone builds are reproducible.
7. Migrations are versioned, idempotent, dry-runnable, and do not overwrite client edits on normal deployment.
8. Automated build, editor, frontend, accessibility, SEO, and regression checks pass.
9. The intended release is committed and pushed through the normal repository workflow without secrets or unrelated user changes.
10. Kinsta is backed up, the release is deployed and verified on staging, and a rollback path is proven.
11. The public domain is cut over and the complete production verification suite passes. A release-ready staging site with a documented DNS/account-access gate is useful progress but does not complete the overall go-live objective.
