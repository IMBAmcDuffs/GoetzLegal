function initMobileNavigation(): void {
  const mobileMenuButton = document.querySelector('#mobile-menu-button');
  const mobileMenu = document.querySelector('#mobile-menu');
  
  if (mobileMenuButton && mobileMenu) {
    mobileMenuButton.addEventListener('click', () => {
      const isExpanded = mobileMenuButton.getAttribute('aria-expanded') === 'true';
      mobileMenuButton.setAttribute('aria-expanded', (!isExpanded).toString());
      mobileMenu.classList.toggle('hidden');
    });
  }
}

function initSmoothScroll(): void {
  const links = document.querySelectorAll('a[href^="#"]');
  
  links.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const targetId = link.getAttribute('href');
      const targetElement = document.querySelector(targetId);
      
      if (targetElement) {
        window.scrollTo({
          top: targetElement.offsetTop - 80,
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
  // Handle scroll events for performance optimization
  const scrollPosition = window.scrollY;
  
  // Add scroll-specific classes or behaviors here
  if (scrollPosition > 100) {
    document.body.classList.add('scrolled');
  } else {
    document.body.classList.remove('scrolled');
  }
};

// Initialize all features
window.addEventListener('DOMContentLoaded', () => {
  initMobileNavigation();
  initSmoothScroll();
  initHeaderScroll();
  
  // Add scroll event listener with throttling
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (!ticking) {
      requestAnimationFrame(() => {
        handleScroll();
        ticking = false;
      });
      ticking = true;
    }
  });
});
