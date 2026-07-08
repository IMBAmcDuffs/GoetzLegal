document.documentElement.classList.add('goetz-resource-links-ready');

const items = document.querySelectorAll('.goetz-resource-links li');

if (!('IntersectionObserver' in window)) {
  items.forEach((item) => item.classList.add('is-visible'));
} else {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    },
    {
      rootMargin: '0px 0px -12% 0px',
      threshold: 0.2,
    },
  );

  items.forEach((item) => observer.observe(item));
}
