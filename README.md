# Goetz & Goetz Law Firm — WordPress Site

A modern WordPress website for **Goetz & Goetz**, a Fort Myers, Florida law firm specializing in **Corporate Law** and **Construction Law**. Built on [TailPress 5.x](https://tailpress.io/) with Tailwind CSS, SCSS, and TypeScript.

> **Template Note:** This repository is designed to be reused as a starter template for similar professional-services WordPress sites. See [Using as a Template](#using-as-a-template) below.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Theme Development](#theme-development)
- [Required & Recommended Plugins](#required--recommended-plugins)
- [AI Authority Files](#ai-authority-files)
- [Performance](#performance)
- [Deployment](#deployment)
- [Using as a Template](#using-as-a-template)

---

## Quick Start

### Prerequisites

| Tool | Version |
|------|---------|
| PHP | ≥ 8.0.2 |
| Composer | ≥ 2.x |
| Node.js | ≥ 18 LTS |
| npm | ≥ 9 |
| WordPress | ≥ 6.4 |

### 1. Clone & Install

```bash
git clone https://github.com/IMBAmcDuffs/GoetzLegal.git
cd GoetzLegal

# Install theme PHP dependencies
cd wp-content/themes/goetz-legal
composer install

# Install theme JS/CSS dependencies
npm install
```

### 2. Build Assets

```bash
# Development (with hot-reload)
npm run dev

# Production build
npm run build
```

### 3. WordPress Setup

1. Point your local WordPress installation's `wp-content/` at this repo's `wp-content/` directory (or symlink the theme/plugins).
2. Activate the **Goetz Legal** theme.
3. Activate plugins:
   - **Goetz Migration** — Tools → Goetz Migration
   - **Goetz AI Authority** — Settings → AI Authority
4. Install required plugins (Yoast SEO, Wordfence, WPForms Lite, WP Super Cache) — admin notices will guide you.

---

## Project Structure

```
GoetzLegal/
├── README.md                        ← This file
├── .gitignore
└── wp-content/
    ├── mu-plugins/
    │   └── goetz-recommended-plugins.php   ← Must-use: plugin dependency checker
    ├── plugins/
    │   ├── goetz-ai-authority/             ← AI authority file generator
    │   │   ├── goetz-ai-authority.php
    │   │   └── includes/
    │   │       └── class-generator.php
    │   └── goetz-migration/                ← Site content scraper/importer
    │       ├── goetz-migration.php
    │       └── includes/
    │           └── class-scraper.php
    └── themes/
        └── goetz-legal/                    ← Main WordPress theme
            ├── style.css                   ← WP theme header
            ├── theme.json                  ← WP block editor settings
            ├── functions.php               ← Theme setup, CPTs, performance
            ├── header.php / footer.php     ← Global layout
            ├── index.php / single.php / page.php / 404.php
            ├── searchform.php / comments.php
            ├── page-templates/             ← Custom page templates
            │   ├── template-home.php
            │   ├── template-attorneys.php
            │   ├── template-practice-areas.php
            │   ├── template-contact.php
            │   ├── template-about.php
            │   └── template-resources.php
            ├── template-parts/             ← Reusable partials
            │   ├── content.php
            │   └── content-single.php
            ├── resources/
            │   ├── scss/                   ← SCSS source (compiled by Vite)
            │   │   ├── app.scss
            │   │   ├── theme.scss
            │   │   ├── custom.scss
            │   │   ├── utilities.scss
            │   │   └── editor-style.scss
            │   └── ts/                     ← TypeScript source
            │       └── app.ts
            ├── package.json                ← npm scripts & dependencies
            ├── vite.config.mjs             ← Vite build configuration
            ├── tsconfig.json               ← TypeScript configuration
            ├── composer.json               ← PHP (TailPress framework)
            └── safelist.txt                ← Tailwind CSS safelist
```

---

## Theme Development

### Build System

The theme uses **Vite** for asset compilation:

| Command | Purpose |
|---------|---------|
| `npm run dev` | Start Vite dev server with HMR on port 3000 |
| `npm run build` | Production build → `dist/` with manifest |

### Entry Points

Defined in `vite.config.mjs`:

- `resources/ts/app.ts` — TypeScript → JavaScript
- `resources/scss/app.scss` — SCSS + Tailwind → CSS
- `resources/scss/editor-style.scss` — Block editor styles

### Design System

| Token | Value | Usage |
|-------|-------|-------|
| `--color-primary` | `#0F3460` (Navy) | Headers, buttons, text |
| `--color-secondary` | `#D4AF37` (Gold) | Accents, CTAs, hover states |
| `--color-dark` | `#1A1A2E` | Dark backgrounds |
| `--color-light` | `#F5F5F5` | Light backgrounds, cards |
| `--font-heading` | Playfair Display | H1–H6 headings |
| `--font-body` | Lato / Inter | Body text |
| `--font-ui` | Roboto | Forms, labels, small text |

Colors and typography are defined in both `theme.json` (block editor) and `resources/scss/theme.scss` (Tailwind).

### Custom Post Types

| Post Type | Slug | Usage |
|-----------|------|-------|
| `attorney` | `/attorneys/` | Attorney bios & profiles |
| `practice_area` | `/practice-areas/` | Practice area pages |

Both support the block editor (Gutenberg), thumbnails, excerpts, and custom fields.

---

## Required & Recommended Plugins

A **must-use plugin** (`mu-plugins/goetz-recommended-plugins.php`) automatically displays admin notices for missing plugins.

### Required

| Plugin | Purpose |
|--------|---------|
| **[Yoast SEO](https://wordpress.org/plugins/wordpress-seo/)** | On-page SEO, XML sitemaps, schema markup, meta tags |
| **[Wordfence Security](https://wordpress.org/plugins/wordfence/)** | Firewall, malware scanner, brute-force protection |
| **[WPForms Lite](https://wordpress.org/plugins/wpforms-lite/)** | Contact forms, appointment requests |
| **[WP Super Cache](https://wordpress.org/plugins/wp-super-cache/)** | Page caching for performance |

### Recommended

| Plugin | Purpose |
|--------|---------|
| **[Safe SVG](https://wordpress.org/plugins/safe-svg/)** | Allow safe SVG uploads for logos/icons |
| **[Redirection](https://wordpress.org/plugins/redirection/)** | 301 redirect management, 404 monitoring |
| **[UpdraftPlus](https://wordpress.org/plugins/updraftplus/)** | Automated backups to cloud storage |

### Post-Install Configuration

**Yoast SEO:**
1. Run the First-time Configuration wizard
2. Set Organization name to "Goetz & Goetz"
3. Enable XML Sitemaps (SEO → General → Features)
4. Configure Social profiles
5. Set breadcrumbs (SEO → Search Appearance → Breadcrumbs)

**Wordfence:**
1. Run the initial scan
2. Enable Firewall in "Learning Mode" for 1 week, then switch to "Enabled and Protecting"
3. Enable Two-Factor Authentication for admin accounts
4. Configure scan schedule (daily recommended)

**WPForms:**
1. Create a "Contact" form with: Name, Email, Phone, Case Type (dropdown), Message
2. Create an "Emergency Intake" form with: Name, Phone, Urgency Level, Brief Description
3. Embed forms in the Contact page using the `[wpforms id="X"]` shortcode

**WP Super Cache:**
1. Enable caching (Settings → WP Super Cache)
2. Choose "Simple" mode for compatibility
3. Enable "Compress pages" for gzip
4. Set cache timeout to 3600 seconds (1 hour)

---

## AI Authority Files

The **Goetz AI Authority** plugin (Settings → AI Authority) generates files that help AI crawlers understand, attribute, and respect the site's content.

### Files Generated

| File | Standard | Purpose |
|------|----------|---------|
| `llms.txt` | [llmstxt.org](https://llmstxt.org/) | Structured site info for LLMs (ChatGPT, Claude, etc.) |
| `ai.txt` | Emerging convention | AI usage policies, attribution requirements |
| `humans.txt` | [humanstxt.org](http://humanstxt.org/) | Team, technology stack, contact info |

### How to Use

1. Go to **Settings → AI Authority** in wp-admin
2. Review/edit the firm information
3. Click **"Generate All Files"**
4. Files are written to the WordPress root directory (alongside `wp-config.php`)

### When to Regenerate

- After changing firm contact info
- After adding/removing attorneys
- After adding new practice areas
- After a major site redesign

---

## Performance

The theme includes several built-in performance optimizations:

| Optimization | Implementation |
|-------------|----------------|
| **Resource Hints** | `preconnect` to Google Fonts origins |
| **Script Deferral** | All non-jQuery front-end scripts load with `defer` |
| **Emoji Removal** | WordPress emoji JS/CSS removed (saves ~50 KB) |
| **Query String Removal** | `?ver=` stripped from static assets for CDN caching |
| **Clean `<head>`** | RSD, WLW manifest, generator meta tags removed |
| **Revision Limit** | Post revisions capped at 5 to reduce DB bloat |
| **Lazy Loading** | WordPress native lazy loading for images (WP 5.5+) |
| **Tailwind CSS** | Purged/tree-shaken — only used classes in production bundle |
| **Vite Build** | Minified, hashed, and manifest-based asset loading |

### Additional Recommendations

- Install **WP Super Cache** for page-level caching
- Use a **CDN** (Cloudflare) in production for static asset delivery
- Enable **gzip/brotli** compression at the server level
- Optimize images before upload (WebP format preferred)

---

## Deployment

### Production Checklist

- [ ] Run `npm run build` in the theme directory
- [ ] Run `composer install --no-dev` in the theme directory
- [ ] Install & activate required plugins (Yoast, Wordfence, WPForms, WP Super Cache)
- [ ] Configure Yoast SEO (run setup wizard)
- [ ] Configure Wordfence (run initial scan)
- [ ] Set up contact forms in WPForms
- [ ] Generate AI authority files (Settings → AI Authority)
- [ ] Set permalinks to "Post name" (Settings → Permalinks)
- [ ] Create menus (Appearance → Menus): Primary Nav + Footer Nav
- [ ] Set homepage to use the "Homepage" template
- [ ] Verify 301 redirects from old site URLs
- [ ] Submit sitemap to Google Search Console
- [ ] Test all forms submit correctly
- [ ] Test mobile responsiveness
- [ ] Run Lighthouse/PageSpeed audit (target: 90+)

---

## Using as a Template

This repo is structured to be forked and reused for other professional-services WordPress sites.

### Steps to Adapt

1. **Fork** this repository
2. **Find & replace** across all files:
   - `goetz-legal` → `your-theme-slug`
   - `goetz_legal` → `your_theme_prefix`
   - `GoetzLegal` → `YourThemeName`
   - `Goetz & Goetz` → `Your Firm Name`
   - `goetzlegal.com` → `yourdomain.com`
3. **Update colors** in `theme.json` and `resources/scss/theme.scss`
4. **Update fonts** in `functions.php` (Google Fonts URL) and `resources/scss/theme.scss`
5. **Update page templates** with your content structure
6. **Update AI Authority plugin** defaults in the plugin settings
7. **Update the migration plugin** with your source site URLs
8. Run `npm install && npm run build`

### What's Template-Ready

- ✅ Build system (Vite + SCSS + TypeScript)
- ✅ Performance optimizations
- ✅ Plugin dependency checker (mu-plugin)
- ✅ AI authority file generator
- ✅ Content migration tool
- ✅ Responsive header/footer layout
- ✅ Custom post type registration pattern
- ✅ Block editor integration

---

## License

MIT — See [LICENSE](LICENSE) for details.
