function initMobileNavigation(): void {
  const mobileMenuButton = document.querySelector('.mobile-menu-button');
  const mobileMenu = document.querySelector('.mobile-menu');
  
  if (mobileMenuButton && mobileMenu) {
    mobileMenuButton.addEventListener('click', (e) => {
      e.preventDefault();
      mobileMenu.classList.toggle('hidden');
      mobileMenuButton.classList.toggle('active');
    });
  }
}

function initSmoothScroll(): void {
  const links = document.querySelectorAll('a[href^="#"]');
  
  links.forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute('href')!);
      if (target) {
        window.scrollTo({
          top: target.offsetTop,
          behavior: 'smooth'
        });
      }
    });
  });
}

function initHeaderScroll(): void {
  const header = document.querySelector('header');
  
  if (header) {
    window.addEventListener('scroll', () => {
      if (window.scrollY > 50) {
        header.classList.add('scrolled');
      } else {
        header.classList.remove('scrolled');
      }
    });
  }
}

const handleScroll = (): void => {
  // Ensure smooth scrolling for all browsers
  if ('scrollBehavior' in document.documentElement.style) {
    // Modern browsers
    document.documentElement.style.scrollBehavior = 'smooth';
  } else {
    // Fallback for older browsers
    window.scrollTo({
      top: 0,
      behavior: 'auto'
    });
  }
};

// Initialize all features
initMobileNavigation();
initSmoothScroll();
initHeaderScroll();

// Handle page load
window.addEventListener('load', () => {
  handleScroll();
});

// Handle resize for responsive design
window.addEventListener('resize', () => {
  // Ensure responsive behavior on resize
  const mobileMenu = document.querySelector('.mobile-menu');
  if (mobileMenu) {
    mobileMenu.classList.add('hidden');
  }
});