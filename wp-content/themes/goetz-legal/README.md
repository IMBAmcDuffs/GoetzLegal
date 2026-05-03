# Goetz & Goetz Law Firm WordPress Theme

This theme is built on TailPress with Tailwind CSS and is optimized for cross-browser compatibility and responsive design.

## Features

- Responsive design that works across all modern browsers
- Cross-browser compatibility fixes for older browsers
- Mobile-first approach with progressive enhancement
- SEO optimized

## Cross-Browser Compatibility Fixes

This theme includes fixes for common cross-browser compatibility issues:

1. **CSS Box-sizing**: Ensures consistent box-model behavior across browsers
2. **Flexbox Fallbacks**: Provides fallbacks for older browsers that don't support flexbox
3. **Form Element Styling**: Normalizes form elements across browsers
4. **Font Rendering**: Improves font rendering consistency
5. **Grid Support**: Adds fallbacks for CSS Grid in older browsers

## Responsive Design Implementation

The theme follows a mobile-first responsive design approach:

1. **Mobile-First**: Starts with mobile styles and enhances for larger screens
2. **Flexible Grid**: Uses CSS Grid and Flexbox for responsive layouts
3. **Media Queries**: Implements responsive breakpoints for all device sizes
4. **Image Handling**: Responsive images that scale properly

## Browser Support

This theme supports:

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Internet Explorer 11 (with polyfills)

## Development

To build the theme:

```bash
npm run build
```

To run in development mode:

```bash
npm run dev
```

## Testing

For cross-browser compatibility testing, ensure to test on:

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Internet Explorer 11 (if required)

Responsive design should be tested at:

- Mobile (320px)
- Tablet (768px)
- Desktop (1024px)
- Large Desktop (1200px)
