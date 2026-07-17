(function initializePracticeAreasView() {
  const START_DELAY_MS = 200;
  const ITEM_STAGGER_MS = 350;
  const THRESHOLD = 0.15;

  function revealAll(section, list, items) {
    items.forEach((item) => item.classList.add('is-revealed'));
    list.style.setProperty('--goetz-practice-progress', '1');
    section.classList.add('is-animation-complete');
  }

  function initializeSection(section) {
    if (section.dataset.goetzPracticeAnimationInitialized === 'true') {
      return;
    }

    const list = section.querySelector('.goetz-practice-list');
    const items = list
      ? Array.from(list.querySelectorAll('.goetz-practice-area-item'))
      : [];
    if (!list || items.length === 0) {
      return;
    }

    section.dataset.goetzPracticeAnimationInitialized = 'true';
    list.style.setProperty('--goetz-practice-progress', '0');
    section.classList.add('is-animation-ready');

    const reducedMotion = typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const supportsObserver = typeof window.IntersectionObserver === 'function';

    if (reducedMotion || !supportsObserver) {
      revealAll(section, list, items);
      return;
    }

    let hasStarted = false;
    const observer = new window.IntersectionObserver((entries) => {
      const entered = entries.some((entry) => (
        entry.target === list
          && entry.isIntersecting
          && entry.intersectionRatio >= THRESHOLD
      ));
      if (!entered || hasStarted) {
        return;
      }

      hasStarted = true;
      observer.disconnect();

      items.forEach((item, index) => {
        window.setTimeout(() => {
          item.classList.add('is-revealed');
          list.style.setProperty(
            '--goetz-practice-progress',
            String((index + 1) / items.length)
          );
          if (index === items.length - 1) {
            section.classList.add('is-animation-complete');
          }
        }, START_DELAY_MS + (ITEM_STAGGER_MS * index));
      });
    }, { threshold: THRESHOLD });

    observer.observe(list);
  }

  function initialize() {
    document.documentElement.classList.remove('no-js');
    document
      .querySelectorAll('.goetz-practice-areas')
      .forEach(initializeSection);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
}());
