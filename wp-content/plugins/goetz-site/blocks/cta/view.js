document.querySelectorAll('.goetz-cta[data-goetz-cta-background]').forEach((section) => {
  const backgroundUrl = section.dataset.goetzCtaBackground;
  if (backgroundUrl) {
    section.style.setProperty(
      '--goetz-cta-background-image',
      `url(${JSON.stringify(backgroundUrl)})`
    );
  }
});

document.documentElement.classList.add('goetz-cta-ready');
