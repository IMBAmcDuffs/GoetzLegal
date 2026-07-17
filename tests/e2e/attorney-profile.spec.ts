import { expect, test } from '@playwright/test';

const profilePath = '/james-l-goetz/';

async function expectNoHorizontalOverflow(page) {
  const widths = await page.evaluate(() => ({
    client: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth,
  }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client + 1);
}

test('James profile matches the reference structure and desktop geometry', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const response = await page.goto(profilePath, { waitUntil: 'networkidle' });
  expect(response?.ok()).toBeTruthy();

  await expect(page.locator('main h1')).toHaveCount(1);
  await expect(page.locator('.goetz-page-hero h1')).toHaveText('James L Goetz');

  const section = page.locator('.goetz-attorney-profile-section');
  const profile = section.locator('.goetz-attorney-card--profile');
  const portrait = profile.locator('.goetz-attorney-card__image');
  const body = profile.locator('.goetz-attorney-card__body');

  await expect(section).toHaveCount(1);
  await expect(profile).toHaveCount(1);
  await expect(profile.locator('h2')).toHaveText('James L. Goetz');
  await expect(profile.locator('h2 .goetz-attorney-card__accent')).toHaveText('James L.');
  await expect(profile.locator('.goetz-attorney-card__mark')).toHaveAttribute('alt', '');
  await expect(portrait).toHaveAttribute('srcset', /JAMES-L/);
  await expect(profile.getByRole('link', { name: 'Email James L. Goetz' })).toHaveAttribute(
    'href',
    'mailto:info@goetzlegal.com'
  );

  const geometry = await page.evaluate(() => {
    const rect = (selector: string) => {
      const element = document.querySelector(selector);
      if (!(element instanceof HTMLElement)) throw new Error(`Missing ${selector}`);
      const box = element.getBoundingClientRect();
      return { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height };
    };
    return {
      section: rect('.goetz-attorney-profile-section'),
      profile: rect('.goetz-attorney-card--profile'),
      portrait: rect('.goetz-attorney-card--profile .goetz-attorney-card__image'),
      body: rect('.goetz-attorney-card--profile .goetz-attorney-card__body'),
      cta: rect('.goetz-cta'),
      ctaCopy: rect('.goetz-cta > div'),
      ctaEyebrow: rect('.goetz-cta p'),
      ctaHeading: rect('.goetz-cta h2'),
      ctaButton: rect('.goetz-cta .goetz-button'),
      footer: rect('.site-footer'),
      footerGrid: rect('.site-footer__grid'),
      footerLogo: rect('.site-footer__logo'),
      footerBottom: rect('.site-footer__bottom'),
      footerSections: Array.from(document.querySelectorAll('.site-footer__grid > section')).map((element) => {
        const box = element.getBoundingClientRect();
        return { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height };
      }),
      background: getComputedStyle(document.querySelector('.goetz-attorney-profile-section')!).backgroundColor,
    };
  });

  expect(geometry.background).toBe('rgb(255, 255, 255)');
  expect(geometry.profile.x).toBeCloseTo(180, 0);
  expect(geometry.profile.width).toBeCloseTo(1080, 0);
  expect(geometry.portrait.width).toBeCloseTo(393, 0);
  expect(geometry.portrait.height).toBeCloseTo(285, 0);
  expect(geometry.body.x - (geometry.portrait.x + geometry.portrait.width)).toBeCloseTo(65, 0);
  expect(geometry.portrait.y - geometry.section.y).toBeCloseTo(130, 0);
  expect(geometry.section.height).toBeCloseTo(792.2, 0);
  expect(geometry.cta.y).toBeCloseTo(1312.1, 0);
  expect(geometry.cta.height).toBeCloseTo(215.2, 0);
  expect(geometry.ctaCopy.x).toBeCloseTo(180, 0);
  expect(geometry.ctaCopy.width).toBeCloseTo(698.4, 0);
  expect(geometry.ctaCopy.y - geometry.cta.y).toBeCloseTo(50, 0);
  expect(geometry.ctaEyebrow.height).toBeCloseTo(36.4, 0);
  expect(geometry.ctaHeading.height).toBeCloseTo(70.4, 0);
  expect(geometry.ctaButton.x).toBeCloseTo(1008.45, 0);
  expect(geometry.ctaButton.width).toBeCloseTo(251.55, 0);
  expect(geometry.ctaButton.height).toBeCloseTo(52, 0);
  expect(geometry.footer.y).toBeCloseTo(1527.3, 0);
  expect(geometry.footer.height).toBeCloseTo(593, 0);
  expect(geometry.footerGrid.x).toBeCloseTo(180, 0);
  expect(geometry.footerGrid.y - geometry.footer.y).toBeCloseTo(50, 0);
  expect(geometry.footerGrid.width).toBeCloseTo(1080, 0);
  expect(geometry.footerSections[0].x).toBeCloseTo(180, 0);
  expect(geometry.footerSections[1].x).toBeCloseTo(561.6, 0);
  expect(geometry.footerSections[2].x).toBeCloseTo(943.2, 0);
  expect(geometry.footerSections[0].width).toBeCloseTo(316.8, 0);
  expect(geometry.footerLogo.x).toBeCloseTo(180, 0);
  expect(geometry.footerLogo.y - geometry.footer.y).toBeCloseTo(61.05, 0);
  expect(geometry.footerBottom.x).toBeCloseTo(180, 0);
  expect(geometry.footerBottom.y - geometry.footer.y).toBeCloseTo(429.4, 0);
  expect(geometry.footerBottom.width).toBeCloseTo(1080, 0);
  expect(geometry.footerBottom.height).toBeCloseTo(113.6, 0);
  await expect(page.locator('.site-footer__bottom')).toHaveText(
    '© Copyright 2024 – Goetz & Goetz. All Rights Reserved'
  );
  await expectNoHorizontalOverflow(page);
});

test('James profile stacks like the reference at 390px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(profilePath, { waitUntil: 'networkidle' });

  const geometry = await page.evaluate(() => {
    const rect = (selector: string) => {
      const element = document.querySelector(selector);
      if (!(element instanceof HTMLElement)) throw new Error(`Missing ${selector}`);
      const box = element.getBoundingClientRect();
      return { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height };
    };
    return {
      headerTop: rect('.site-header__top'),
      navigationRow: rect('.site-header__nav-row'),
      branding: rect('.site-branding-card'),
      logo: rect('.site-branding-card .custom-logo'),
      menuToggle: rect('.site-menu-toggle'),
      menuBars: Array.from(document.querySelectorAll('.site-menu-toggle span')).map((element) => {
        const box = element.getBoundingClientRect();
        return { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height };
      }),
      hero: rect('.goetz-page-hero'),
      section: rect('.goetz-attorney-profile-section'),
      portrait: rect('.goetz-attorney-card--profile .goetz-attorney-card__image'),
      body: rect('.goetz-attorney-card--profile .goetz-attorney-card__body'),
      cta: rect('.goetz-cta'),
      ctaCopy: rect('.goetz-cta > div'),
      ctaEyebrow: rect('.goetz-cta p'),
      ctaHeading: rect('.goetz-cta h2'),
      ctaButton: rect('.goetz-cta .goetz-button'),
      footer: rect('.site-footer'),
      footerGrid: rect('.site-footer__grid'),
      footerLogo: rect('.site-footer__logo'),
      footerBottom: rect('.site-footer__bottom'),
      footerSections: Array.from(document.querySelectorAll('.site-footer__grid > section')).map((element) => {
        const box = element.getBoundingClientRect();
        return { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height };
      }),
      headingFont: parseFloat(getComputedStyle(document.querySelector('.goetz-attorney-card--profile h2')!).fontSize),
    };
  });

  expect(geometry.headerTop.height).toBeCloseTo(73, 0);
  expect(geometry.navigationRow.y).toBeCloseTo(72, 0);
  expect(geometry.navigationRow.height).toBeCloseTo(85, 0);
  expect(geometry.branding.x).toBeCloseTo(29.25, 1);
  expect(geometry.branding.y).toBeCloseTo(73, 0);
  expect(geometry.branding.width).toBeCloseTo(265.2, 1);
  expect(geometry.branding.height).toBeCloseTo(80, 0);
  expect(geometry.logo.x).toBeCloseTo(29.25, 1);
  expect(geometry.logo.width).toBeCloseTo(260.9, 1);
  expect(geometry.logo.height).toBeCloseTo(80, 0);
  expect(geometry.menuToggle.x).toBeCloseTo(305.75, 1);
  expect(geometry.menuToggle.width).toBeCloseTo(55, 0);
  expect(geometry.menuToggle.height).toBeCloseTo(80, 0);
  expect(geometry.menuBars).toHaveLength(3);
  expect(geometry.menuBars[0].x).toBeCloseTo(325.75, 1);
  expect(geometry.menuBars[0].width).toBeCloseTo(35, 0);
  expect(geometry.menuBars[0].height).toBeCloseTo(3, 0);
  expect(geometry.menuBars[1].y - geometry.menuBars[0].y).toBeCloseTo(10, 0);
  expect(geometry.menuBars[2].y - geometry.menuBars[1].y).toBeCloseTo(10, 0);
  expect(geometry.hero.y).toBeCloseTo(157, 0);
  expect(geometry.section.y).toBeCloseTo(557, 0);
  expect(geometry.portrait.x).toBeCloseTo(29.25, 2);
  expect(geometry.portrait.width).toBeCloseTo(331.5, 1);
  expect(geometry.portrait.height).toBeCloseTo(240.4, 1);
  expect(geometry.portrait.y - geometry.section.y).toBeCloseTo(130, 0);
  expect(geometry.body.y - (geometry.portrait.y + geometry.portrait.height)).toBeCloseTo(20, 0);
  expect(geometry.headingFont).toBeCloseTo(25.6, 0);
  expect(geometry.section.height).toBeCloseTo(1382.2, 0);
  expect(geometry.cta.y).toBeCloseTo(1940.2, 0);
  expect(geometry.cta.height).toBeCloseTo(254.3, 0);
  expect(geometry.ctaCopy.x).toBeCloseTo(29.25, 1);
  expect(geometry.ctaCopy.width).toBeCloseTo(331.5, 1);
  expect(geometry.ctaCopy.y - geometry.cta.y).toBeCloseTo(50, 0);
  expect(geometry.ctaEyebrow.height).toBeCloseTo(23.4, 0);
  expect(geometry.ctaHeading.height).toBeCloseTo(27.5, 0);
  expect(geometry.ctaButton.x).toBeCloseTo(109.2, 0);
  expect(geometry.ctaButton.width).toBeCloseTo(251.55, 0);
  expect(geometry.ctaButton.height).toBeCloseTo(52, 0);
  expect(geometry.footer.y).toBeCloseTo(2194.5, 0);
  expect(geometry.footer.height).toBeCloseTo(1256.4, 0);
  expect(geometry.footerGrid.x).toBeCloseTo(29.25, 1);
  expect(geometry.footerGrid.y - geometry.footer.y).toBeCloseTo(50, 0);
  expect(geometry.footerGrid.width).toBeCloseTo(331.5, 1);
  expect(geometry.footerSections[0].height).toBeCloseTo(275.8, 0);
  expect(geometry.footerSections[1].y - geometry.footer.y).toBeCloseTo(345.8, 0);
  expect(geometry.footerSections[1].height).toBeCloseTo(324.4, 0);
  expect(geometry.footerSections[2].y - geometry.footer.y).toBeCloseTo(690.2, 0);
  expect(geometry.footerSections[2].height).toBeCloseTo(301.3, 0);
  expect(geometry.footerLogo.x).toBeCloseTo(29.25, 1);
  expect(geometry.footerLogo.y - geometry.footer.y).toBeCloseTo(61.05, 0);
  expect(geometry.footerBottom.y - geometry.footer.y).toBeCloseTo(1066.4, 0);
  expect(geometry.footerBottom.height).toBeCloseTo(140, 0);
  await expectNoHorizontalOverflow(page);
});
