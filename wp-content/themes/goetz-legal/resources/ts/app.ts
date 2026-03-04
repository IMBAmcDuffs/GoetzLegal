/**
 * Goetz Legal - Main Application TypeScript
 *
 * @package GoetzLegal
 */

/**
 * Mobile navigation toggle functionality.
 */
function initMobileNavigation(): void {
    const mainNavigation = document.getElementById('primary-navigation');
    const mainNavigationToggle = document.getElementById('primary-menu-toggle');

    if (mainNavigation && mainNavigationToggle) {
        mainNavigationToggle.addEventListener('click', (e: Event) => {
            e.preventDefault();
            mainNavigation.classList.toggle('hidden');
        });
    }
}

/**
 * Smooth scroll for anchor links.
 */
function initSmoothScroll(): void {
    document.querySelectorAll<HTMLAnchorElement>('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', (e: Event) => {
            const href = anchor.getAttribute('href');
            if (!href || href === '#') return;

            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            }
        });
    });
}

/**
 * Header scroll effect - add shadow on scroll.
 */
function initHeaderScroll(): void {
    const header = document.querySelector('header');
    if (!header) return;

    const handleScroll = (): void => {
        if (window.scrollY > 10) {
            header.classList.add('shadow-xl');
        } else {
            header.classList.remove('shadow-xl');
        }
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
}

/**
 * Initialize all application features when DOM is ready.
 */
window.addEventListener('load', (): void => {
    initMobileNavigation();
    initSmoothScroll();
    initHeaderScroll();
});
