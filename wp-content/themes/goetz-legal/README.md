# Goetz Legal Theme

Tailpress 5 theme for the Goetz & Goetz page-only rebuild.

## Development

```bash
composer install
npm install
npm run dev
npm run build
```

The preferred project-level workflow is:

```bash
./manager.sh theme:dev
./manager.sh theme:build
```

## Notes

- No custom post types are registered in v1.
- Navigation mirrors the live site: Home, James L. Goetz, Gregory W. Goetz, Staff, Questions, Links, Contact.
- Contact information is defined in `functions.php` constants and matches the live site.
- Custom blocks are registered from `blocks/*/block.json` and use block metadata for conditional frontend assets.
