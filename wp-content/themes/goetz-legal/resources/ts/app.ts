/**
 * Goetz Legal - Main Application TypeScript
 *
 * @package GoetzLegal
 */

const mobileNavigationQuery = '(max-width: 989px)';

/**
 * Full-screen mobile navigation with a current, keyboard-safe focus loop.
 */
function initMobileNavigation(): void {
    const navigation = document.getElementById('primary-navigation');
    const toggle = document.getElementById('primary-menu-toggle');

    if (!(navigation instanceof HTMLElement) || !(toggle instanceof HTMLButtonElement)) {
        return;
    }

    const media = window.matchMedia(mobileNavigationQuery);
    const openLabel = toggle.dataset.labelOpen || 'Open navigation';
    const closeLabel = toggle.dataset.labelClose || 'Close navigation';
    let isOpen = false;

    const focusableTargets = (): HTMLElement[] => [
        toggle,
        ...Array.from(
            navigation.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((element) => element.getClientRects().length > 0),
    ];

    const setToggleState = (open: boolean): void => {
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? closeLabel : openLabel);
    };

    const close = ({ restoreFocus = true }: { restoreFocus?: boolean } = {}): void => {
        const shouldRestoreFocus = restoreFocus && isOpen && media.matches;
        isOpen = false;
        navigation.classList.remove('is-open');
        document.body.classList.remove('is-navigation-open');
        setToggleState(false);

        if (shouldRestoreFocus) {
            toggle.focus();
        }
    };

    const open = (): void => {
        if (!media.matches || isOpen) {
            return;
        }

        isOpen = true;
        navigation.classList.add('is-open');
        document.body.classList.add('is-navigation-open');
        setToggleState(true);
        const focusFirstLink = (): void => {
            navigation.querySelector<HTMLElement>('a[href]')?.focus();
        };
        focusFirstLink();
        window.setTimeout(() => {
            if (isOpen) {
                focusFirstLink();
            }
        }, 50);
    };

    const onKeydown = (event: KeyboardEvent): void => {
        if (!isOpen || !media.matches) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const targets = focusableTargets();
        const first = targets[0];
        const last = targets[targets.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        } else if (!targets.includes(document.activeElement as HTMLElement)) {
            event.preventDefault();
            (targets[1] ?? first).focus();
        }
    };

    const onResize = (): void => {
        if (!media.matches) {
            close({ restoreFocus: false });
        }
    };

    toggle.addEventListener('click', (event: MouseEvent) => {
        event.preventDefault();
        if (isOpen) {
            close();
        } else {
            open();
        }
    });
    navigation.addEventListener('click', (event: MouseEvent) => {
        if (event.target instanceof Element && event.target.closest('a[href]')) {
            close({ restoreFocus: false });
        }
    });
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', onResize, { passive: true });

    setToggleState(false);
    document.documentElement.classList.add('is-navigation-enhanced');
    window.dispatchEvent(new Event('goetz:navigation-ready'));
}

/**
 * Smooth scroll for anchor links.
 */
function initSmoothScroll(): void {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll<HTMLAnchorElement>('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', (e: Event) => {
            const href = anchor.getAttribute('href');
            if (!href || href === '#') return;

            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: reduceMotion.matches ? 'auto' : 'smooth',
                    block: 'start',
                });
                if (target instanceof HTMLElement && target.hasAttribute('tabindex')) {
                    target.focus({ preventScroll: true });
                }
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
document.addEventListener('DOMContentLoaded', (): void => {
    initMobileNavigation();
    initSmoothScroll();
    initHeaderScroll();
    initContactFormPresentation();
});
