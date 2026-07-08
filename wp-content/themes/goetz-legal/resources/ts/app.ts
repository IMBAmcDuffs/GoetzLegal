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
            const isOpen = mainNavigation.classList.toggle('is-open');
            mainNavigationToggle.classList.toggle('is-open', isOpen);
            mainNavigationToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
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
    const header = document.querySelector('.site-header');
    if (!header) return;

    const handleScroll = (): void => {
        if (window.scrollY > 10) {
            header.classList.add('is-scrolled');
        } else {
            header.classList.remove('is-scrolled');
        }
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
}

/**
 * Match the imported WPForms markup to the original contact form presentation.
 */
function initContactFormPresentation(): void {
    const contactForm = document.querySelector<HTMLFormElement>('.goetz-contact-form .wpforms-form');
    if (!contactForm) return;

    contactForm.querySelectorAll<HTMLElement>('.wpforms-field').forEach((field) => {
        const label = field.querySelector<HTMLLabelElement>('.wpforms-field-label');
        const control = field.querySelector<HTMLInputElement | HTMLTextAreaElement>('input:not([type="hidden"]), textarea');

        if (!label || !control) return;

        const placeholder = label.textContent?.replace(/\s+/g, ' ').replace(/\s*\*$/, '*').trim();
        if (placeholder && !control.placeholder) {
            control.placeholder = placeholder;
        }
    });

    const submitButton = contactForm.querySelector<HTMLButtonElement>('.wpforms-submit');
    if (submitButton) {
        submitButton.textContent = 'Submit';
    }
}

/**
 * Initialize all application features when DOM is ready.
 */
window.addEventListener('load', (): void => {
    initMobileNavigation();
    initSmoothScroll();
    initHeaderScroll();
    initContactFormPresentation();
});
