# Goetz Legal — WordPress Theme

A TailPress 5.x WordPress theme for Goetz & Goetz Law Firm, built with **Tailwind CSS 4**, **SCSS**, and **TypeScript**.

## Development

```bash
# Install dependencies
composer install
npm install

# Development server (HMR on port 3000)
npm run dev

# Production build
npm run build
```

## Build Output

Production assets are compiled to `dist/` with a Vite manifest for cache-busting:

| Entry | Output |
|-------|--------|
| `resources/ts/app.ts` | `dist/assets/app-[hash].js` |
| `resources/scss/app.scss` | `dist/assets/app-[hash].css` |
| `resources/scss/editor-style.scss` | `dist/assets/editor-style-[hash].css` |

## Design Tokens

| Token | Value | CSS Variable |
|-------|-------|-------------|
| Primary | `#0F3460` (Navy) | `--color-primary` |
| Secondary | `#D4AF37` (Gold) | `--color-secondary` |
| Dark | `#1A1A2E` | `--color-dark` |
| Light | `#F5F5F5` | `--color-light` |
| Heading Font | Playfair Display | `--font-heading` |
| Body Font | Lato | `--font-body` |
| UI Font | Roboto | `--font-ui` |

## Page Templates

| Template | File | Usage |
|----------|------|-------|
| Homepage | `page-templates/template-home.php` | Front page with hero, practice areas, attorneys, CTA |
| Attorneys | `page-templates/template-attorneys.php` | Attorney profiles grid |
| Practice Areas | `page-templates/template-practice-areas.php` | Practice area listing |
| Contact | `page-templates/template-contact.php` | Contact form + info |
| About | `page-templates/template-about.php` | Firm history |
| Resources | `page-templates/template-resources.php` | Legal resource links |

## Custom Post Types

- **Attorney** (`attorney`) — Attorney bios at `/attorneys/`
- **Practice Area** (`practice_area`) — Practice areas at `/practice-areas/`

## Performance Features

Built-in optimizations in `functions.php`:

- Preconnect hints for Google Fonts
- Script deferral for non-critical JS
- WordPress emoji script removal
- Query string removal from static assets
- Clean `<head>` (no RSD, WLW, generator tags)
- Post revision limit (5)

## Extending

### Adding a New Page Template

1. Create `page-templates/template-yourpage.php`
2. Add the template header:
   ```php
   <?php
   /**
    * Template Name: Your Page
    * Template Post Type: page
    */
   ```
3. Use `get_header()` / `get_footer()` and Tailwind classes

### Adding a New Custom Post Type

Add to `functions.php` inside `goetz_legal_register_post_types()` following the existing `attorney` / `practice_area` pattern.

### Adding New SCSS

1. Create your file in `resources/scss/`
2. Import it in `resources/scss/app.scss`
3. Run `npm run build` to compile
