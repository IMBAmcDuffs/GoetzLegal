import { expect, test, type Page, type TestInfo } from '@playwright/test';

function requireBaseURL(testInfo: TestInfo): string {
  const baseURL = testInfo.project.use.baseURL;
  if (typeof baseURL !== 'string') {
    throw new Error('Homepage section presentation coverage requires a base URL.');
  }
  return baseURL;
}

function assetURL(baseURL: string, path: string): string {
  return new URL(path, baseURL).toString();
}

function homepageSectionsMarkup(baseURL: string): string {
  const portrait = assetURL(
    baseURL,
    '/wp-content/plugins/goetz-site/assets/seed/JAMES-L-2.jpg',
  );
  const card = (name: string) => `
    <article class="wp-block-goetz-attorney-card goetz-attorney-card">
      <img class="goetz-attorney-card__image" src="${portrait}" alt="${name}">
      <div class="goetz-attorney-card__body">
        <h3>${name}</h3>
        <p>Representative attorney biography.</p>
        <div class="goetz-attorney-card__links"><a href="#bio">Read Full Bio</a></div>
      </div>
    </article>`;

  return `<!doctype html><html><head>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="${assetURL(baseURL, '/wp-content/plugins/goetz-site/blocks/hero/view.css')}">
    <link rel="stylesheet" href="${assetURL(baseURL, '/wp-content/plugins/goetz-site/blocks/attorney-grid/style.css')}">
    <link rel="stylesheet" href="${assetURL(baseURL, '/wp-content/plugins/goetz-site/blocks/attorney-card/view.css')}">
    <link rel="stylesheet" href="${assetURL(baseURL, '/wp-content/plugins/goetz-site/blocks/cta/view.css')}">
  </head><body style="margin:0">
    <section class="wp-block-goetz-hero goetz-hero">
      <div class="goetz-hero__content">
        <p class="goetz-hero__eyebrow">GoetzLegal.com</p>
        <h1>A law firm with <strong>seasoned trial attorneys</strong> in Fort Myers, Florida.</h1>
        <p>Focused representation.</p>
        <a class="goetz-button" href="#about">Learn More About Us</a>
      </div>
      <figure class="goetz-hero__media"><img class="goetz-hero__image" src="${portrait}" alt="Goetz attorneys"></figure>
    </section>
    <section class="wp-block-goetz-attorney-grid goetz-attorney-grid goetz-section--attorneys">
      <h2 class="goetz-attorney-grid__heading">Attorneys</h2>
      <div class="goetz-attorney-grid__cards">${card('James L. Goetz')}${card('Gregory W. Goetz')}</div>
    </section>
    <section class="wp-block-goetz-cta goetz-cta" data-goetz-cta-background="${portrait}">
      <div><p>WE ARE AN EXPERIENCED TEAM</p><h2>NEED A <strong>LAWYER?</strong></h2></div>
      <a class="goetz-button" href="#contact">Get Consultation</a>
    </section>
    <script src="${assetURL(baseURL, '/wp-content/plugins/goetz-site/blocks/cta/view.js')}"></script>
  </body></html>`;
}

async function openFixture(page: Page, baseURL: string): Promise<void> {
  const failedAssets: string[] = [];
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/goetz-site/')) {
      failedAssets.push(`${response.status()} ${response.url()}`);
    }
  });
  await page.setContent(homepageSectionsMarkup(baseURL), { waitUntil: 'load' });
  expect(failedAssets).toEqual([]);
}

test('Hero block CTA block and Attorney Grid preserve responsive homepage presentation', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');
  test.setTimeout(45_000);
  const baseURL = requireBaseURL(testInfo);

  for (const width of [390, 989, 990, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await openFixture(page, baseURL);

    const hero = page.locator('.goetz-hero');
    const heroContent = page.locator('.goetz-hero__content');
    const heroImage = page.locator('.goetz-hero__media img');
    const grid = page.locator('.goetz-attorney-grid__cards');
    const card = page.locator('.goetz-attorney-card').first();
    const bioButton = card.getByRole('link', { name: 'Read Full Bio' });
    const cta = page.locator('.goetz-cta');

    await expect(heroImage).toHaveCSS('border-radius', /(?:999px|50%)/);
    await expect(card).toHaveCSS('box-shadow', 'none');
    await expect(bioButton).toHaveCSS('border-top-width', '3px');
    await expect(bioButton).toHaveCSS('border-top-left-radius', '40px');
    await expect(bioButton).toHaveCSS('border-top-color', 'rgba(0, 0, 0, 0.6)');
    await expect(bioButton).toHaveCSS('color', 'rgba(0, 0, 0, 0.6)');
    await expect(bioButton).toHaveCSS('font-size', '20px');
    await expect(bioButton).toHaveCSS('font-weight', '500');
    await expect(bioButton).toHaveCSS('line-height', '24px');
    await expect(bioButton).toHaveCSS('text-transform', 'uppercase');
    await expect(cta).toHaveCSS('background-image', /linear-gradient.*JAMES-L-2\.jpg/);
    expect(await cta.evaluate((section) => (
      (section as HTMLElement).style.getPropertyValue('--goetz-cta-background-image')
    ))).toContain('JAMES-L-2.jpg');

    const geometry = await page.evaluate(() => {
      const rect = (selector: string) => {
        const bounds = document.querySelector(selector)!.getBoundingClientRect();
        return { x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height };
      };
      const columns = getComputedStyle(
        document.querySelector('.goetz-attorney-grid__cards')!,
      ).gridTemplateColumns.split(' ').length;
      return {
        hero: rect('.goetz-hero'),
        content: rect('.goetz-hero__content'),
        image: rect('.goetz-hero__media img'),
        button: rect('.goetz-hero .goetz-button'),
        cardImage: rect('.goetz-attorney-card__image'),
        bioButton: rect('.goetz-attorney-card__links a'),
        heroHeadingFont: parseFloat(getComputedStyle(document.querySelector('.goetz-hero h1')!).fontSize),
        heroHeadingLine: parseFloat(getComputedStyle(document.querySelector('.goetz-hero h1')!).lineHeight),
        columns,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    expect(geometry.overflow).toBeLessThanOrEqual(0);

    if (width === 390) {
      expect(geometry.content.width).toBeCloseTo(331.5, 0);
      expect(geometry.image.width).toBeCloseTo(331.5, 0);
      expect(geometry.image.y).toBeGreaterThan(geometry.button.y + geometry.button.height);
      expect(geometry.columns).toBe(1);
      expect(geometry.heroHeadingFont).toBeCloseTo(24, 0);
      expect(geometry.heroHeadingLine).toBeCloseTo(26.4, 0);
    } else {
      expect(geometry.columns).toBe(2);
      if (width === 989) {
        expect(geometry.heroHeadingFont).toBeCloseTo(40, 0);
        expect(geometry.heroHeadingLine).toBeCloseTo(44, 0);
      }
      if (width === 990) {
        expect(geometry.heroHeadingFont).toBeCloseTo(50, 0);
        expect(geometry.heroHeadingLine).toBeCloseTo(55, 0);
      }
      if (width === 1440) {
        expect(geometry.hero.width).toBeCloseTo(1080, 0);
        expect(geometry.content.width).toBeCloseTo(507.6, 0);
        expect(geometry.image.width).toBeCloseTo(507.6, 0);
        expect(geometry.image.x - (geometry.content.x + geometry.content.width)).toBeCloseTo(64.8, 0);
        expect(geometry.cardImage.width).toBeCloseTo(507.6, 0);
        expect(geometry.cardImage.width / geometry.cardImage.height).toBeCloseTo(1.819, 2);
        expect(geometry.bioButton.width).toBeCloseTo(197, 0);
        expect(geometry.bioButton.height).toBeCloseTo(56, 0);
        expect(geometry.heroHeadingFont).toBeCloseTo(50, 0);
        expect(geometry.heroHeadingLine).toBeCloseTo(55, 0);
      }
    }
  }
});

test('CTA block keeps its copy and consultation link visible without JavaScript', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');
  const baseURL = requireBaseURL(testInfo);
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();

  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await openFixture(page, baseURL);
    const cta = page.locator('.goetz-cta');
    await expect(cta.getByText('WE ARE AN EXPERIENCED TEAM', { exact: true })).toBeVisible();
    await expect(cta.getByRole('heading', { name: 'NEED A LAWYER?' })).toBeVisible();
    await expect(cta.getByRole('link', { name: 'Get Consultation' })).toBeVisible();
    expect(await cta.getAttribute('data-goetz-cta-background')).toContain('JAMES-L-2.jpg');
  } finally {
    await context.close();
  }
});
