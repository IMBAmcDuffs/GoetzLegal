const labels = [
  'Corporate',
  'Construction',
  'Real Estate',
  'Probate',
  'Criminal',
  'Bankruptcy',
  'Appeals',
];

function renderPracticeAreas() {
  document.body.innerHTML = `
    <section class="wp-block-goetz-practice-areas goetz-practice-areas">
      <ul class="goetz-practice-list">
        ${labels.map((label) => `
          <li class="wp-block-goetz-practice-area-item goetz-practice-area-item">
            <span class="goetz-practice-area-item__scale" aria-hidden="true"></span>
            <b>${label}</b>
          </li>
        `).join('')}
      </ul>
    </section>
  `;

  return {
    section: document.querySelector('.goetz-practice-areas'),
    list: document.querySelector('.goetz-practice-list'),
    items: [...document.querySelectorAll('.goetz-practice-area-item')],
  };
}

function installMatchMedia(reducedMotion) {
  window.matchMedia = jest.fn().mockReturnValue({
    matches: reducedMotion,
    media: '(prefers-reduced-motion: reduce)',
    addEventListener: jest.fn(),
    removeEventListener: jest.fn(),
  });
}

function installIntersectionObserver() {
  const records = [];
  class MockIntersectionObserver {
    constructor(callback, options) {
      this.callback = callback;
      this.options = options;
      this.observe = jest.fn();
      this.disconnect = jest.fn();
      records.push(this);
    }
  }
  window.IntersectionObserver = MockIntersectionObserver;
  global.IntersectionObserver = MockIntersectionObserver;
  return records;
}

function loadAnimation(section) {
  jest.isolateModules(() => {
    require('../../blocks/practice-areas/view.js');
  });
  if (!section.classList.contains('is-animation-ready')) {
    document.dispatchEvent(new Event('DOMContentLoaded'));
  }
}

describe('Practice Areas measured animation', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.resetModules();
    document.documentElement.className = 'no-js';
    document.body.innerHTML = '';
    delete window.IntersectionObserver;
    delete global.IntersectionObserver;
    delete window.matchMedia;
  });

  afterEach(() => {
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
    document.body.innerHTML = '';
  });

  test('leaves every server-rendered item exposed before initialization', () => {
    const { section, items } = renderPracticeAreas();

    expect(section.classList.contains('is-animation-ready')).toBe(false);
    expect(section.classList.contains('is-animation-complete')).toBe(false);
    items.forEach((item) => {
      expect(item.classList.contains('is-revealed')).toBe(false);
    });
  });

  test('observes the list once at 0.15 and reveals at 200 + 350n milliseconds', () => {
    const { section, list, items } = renderPracticeAreas();
    installMatchMedia(false);
    const observers = installIntersectionObserver();

    loadAnimation(section);

    expect(section.classList.contains('is-animation-ready')).toBe(true);
    expect(document.documentElement.classList.contains('no-js')).toBe(false);
    expect(observers).toHaveLength(1);
    expect(observers[0].options).toEqual({ threshold: 0.15 });
    expect(observers[0].observe).toHaveBeenCalledTimes(1);
    expect(observers[0].observe).toHaveBeenCalledWith(list);
    expect(list.style.getPropertyValue('--goetz-practice-progress')).toBe('0');
    expect(items.filter((item) => item.classList.contains('is-revealed'))).toHaveLength(0);

    observers[0].callback([
      { target: list, isIntersecting: true, intersectionRatio: 0.15 },
    ]);
    expect(observers[0].disconnect).toHaveBeenCalledTimes(1);

    const revealTimes = [200, 550, 900, 1250, 1600, 1950, 2300];
    let elapsed = 0;
    revealTimes.forEach((time, index) => {
      jest.advanceTimersByTime(time - elapsed - 1);
      expect(items.filter((item) => item.classList.contains('is-revealed'))).toHaveLength(index);
      jest.advanceTimersByTime(1);
      expect(items.filter((item) => item.classList.contains('is-revealed'))).toHaveLength(index + 1);
      expect(list.style.getPropertyValue('--goetz-practice-progress')).toBe(
        String((index + 1) / items.length)
      );
      elapsed = time;
    });

    expect(section.classList.contains('is-animation-complete')).toBe(true);
    expect(jest.getTimerCount()).toBe(0);
  });

  test('persists the completed state and ignores scroll re-entry', () => {
    const { section, list, items } = renderPracticeAreas();
    installMatchMedia(false);
    const observers = installIntersectionObserver();
    loadAnimation(section);
    const [observer] = observers;

    observer.callback([{ target: list, isIntersecting: true, intersectionRatio: 0.15 }]);
    jest.advanceTimersByTime(2300);
    const completedClasses = items.map((item) => item.className);

    observer.callback([{ target: list, isIntersecting: false, intersectionRatio: 0 }]);
    observer.callback([{ target: list, isIntersecting: true, intersectionRatio: 1 }]);
    jest.advanceTimersByTime(5000);

    expect(observer.disconnect).toHaveBeenCalledTimes(1);
    expect(items.map((item) => item.className)).toEqual(completedClasses);
    expect(section.classList.contains('is-animation-complete')).toBe(true);
    expect(jest.getTimerCount()).toBe(0);
  });

  test('completes immediately without observers or timers for reduced motion', () => {
    const { section, items } = renderPracticeAreas();
    installMatchMedia(true);
    const observers = installIntersectionObserver();

    loadAnimation(section);

    expect(observers).toHaveLength(0);
    expect(section.classList.contains('is-animation-ready')).toBe(true);
    expect(section.classList.contains('is-animation-complete')).toBe(true);
    items.forEach((item) => {
      expect(item.classList.contains('is-revealed')).toBe(true);
    });
    expect(jest.getTimerCount()).toBe(0);
  });

  test('keeps all content visible and completes immediately without IntersectionObserver', () => {
    const { section, items } = renderPracticeAreas();
    installMatchMedia(false);

    loadAnimation(section);

    expect(section.classList.contains('is-animation-ready')).toBe(true);
    expect(section.classList.contains('is-animation-complete')).toBe(true);
    items.forEach((item) => {
      expect(item.classList.contains('is-revealed')).toBe(true);
    });
    expect(jest.getTimerCount()).toBe(0);
  });
});
