import { randomUUID } from 'node:crypto';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { isLoopbackURL } from './helpers/browser.mjs';

interface TemporaryPage {
  id: number;
  link: string;
}

function requireLocalBaseURL(testInfo: TestInfo): string {
  const baseURL = testInfo.project.use.baseURL;
  test.skip(
    typeof baseURL !== 'string' || !isLoopbackURL(baseURL),
    'Homepage section mutation coverage is local-only.',
  );
  if (typeof baseURL !== 'string') {
    throw new Error('Homepage section coverage requires a configured base URL.');
  }
  return baseURL;
}

function block(name: string, attributes: Record<string, unknown>): string {
  return `<!-- wp:${name} ${JSON.stringify(attributes)} /-->`;
}

function homepageContent(baseURL: string): string {
  const portraitURL = new URL(
    '/wp-content/plugins/goetz-site/assets/seed/JAMES-L-2.jpg',
    baseURL,
  ).toString();

  const hero = block('goetz/hero', {
    eyebrow: 'GoetzLegal.com',
    heading:
      'A law firm with <strong>seasoned trial attorneys</strong> in Fort Myers, Florida.',
    content: 'Focused representation.',
    imageUrl: portraitURL,
    imageAlt: 'Goetz attorneys',
    buttonText: 'Learn More About Us',
    buttonUrl: '/james-l-goetz/',
  });
  const james = block('goetz/attorney-card', {
    name: 'James L. Goetz',
    bio: 'Representative attorney biography.',
    imageUrl: portraitURL,
    imageAlt: 'James L. Goetz',
    profileUrl: '/james-l-goetz/',
  });
  const gregory = block('goetz/attorney-card', {
    name: 'Gregory W. Goetz',
    bio: 'Representative attorney biography.',
    imageUrl: portraitURL,
    imageAlt: 'Gregory W. Goetz',
    profileUrl: '/gregory-w-goetz/',
  });
  const grid = [
    '<!-- wp:goetz/attorney-grid {"heading":"Attorneys"} -->',
    james,
    gregory,
    '<!-- /wp:goetz/attorney-grid -->',
  ].join('\n');
  const cta = block('goetz/cta', {
    eyebrow: 'WE ARE AN EXPERIENCED TEAM',
    heading: 'NEED A <strong>LAWYER?</strong>',
    buttonText: 'Get Consultation',
    buttonUrl: '/contact/',
    backgroundImageUrl: portraitURL,
  });

  return [hero, grid, cta].join('\n\n');
}

async function openAdminApi(page: Page): Promise<void> {
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.apiFetch));
}

async function readFrontPageId(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const settings = await (globalThis as any).wp.apiFetch({
      path: '/wp/v2/settings?context=edit',
    });
    return Number(settings.page_on_front || 0);
  });
}

async function createTemporaryPage(
  page: Page,
  baseURL: string,
): Promise<TemporaryPage> {
  const unique = randomUUID();
  const created = await page.evaluate(
    async ({ content, slug, title }) => (globalThis as any).wp.apiFetch({
      path: '/wp/v2/pages',
      method: 'POST',
      data: {
        content,
        slug,
        status: 'publish',
        title,
      },
    }),
    {
      content: homepageContent(baseURL),
      slug: `goetz-e2e-homepage-sections-${unique}`,
      title: `Goetz homepage sections E2E ${unique}`,
    },
  );
  const id = Number(created?.id);
  const link = String(created?.link || '');
  if (!Number.isInteger(id) || id <= 0 || link === '') {
    throw new Error('WordPress did not create a valid temporary homepage-sections page.');
  }
  return { id, link };
}

async function hardDeleteTemporaryPage(page: Page, temporaryPage: TemporaryPage): Promise<void> {
  const cleanupPage = await page.context().newPage();
  cleanupPage.setDefaultTimeout(10_000);
  cleanupPage.setDefaultNavigationTimeout(20_000);
  try {
    await openAdminApi(cleanupPage);
    const result = await cleanupPage.evaluate(async (pageId) => {
      await (globalThis as any).wp.apiFetch({
        path: `/wp/v2/pages/${pageId}?force=true`,
        method: 'DELETE',
      });
      try {
        await (globalThis as any).wp.apiFetch({ path: `/wp/v2/pages/${pageId}` });
        return { deleted: false, code: '' };
      } catch (error) {
        return {
          deleted: (error as { code?: string })?.code === 'rest_post_invalid_id',
          code: String((error as { code?: string })?.code || ''),
        };
      }
    }, temporaryPage.id);
    expect(result, `temporary page ${temporaryPage.id} must be hard-deleted`).toEqual({
      deleted: true,
      code: 'rest_post_invalid_id',
    });
  } finally {
    await cleanupPage.close();
  }
}

function publicURL(baseURL: string, wordpressLink: string): string {
  const link = new URL(wordpressLink);
  return new URL(`${link.pathname}${link.search}`, baseURL).toString();
}

test('production homepage section presentation uses real WordPress rendering and cleans up', async ({ browser, page }, testInfo) => {
  test.setTimeout(120_000);
  const baseURL = requireLocalBaseURL(testInfo);
  let temporaryPage: TemporaryPage | undefined;
  let originalFrontPageId = 0;

  try {
    await openAdminApi(page);
    originalFrontPageId = await readFrontPageId(page);
    temporaryPage = await createTemporaryPage(page, baseURL);
    expect(await readFrontPageId(page)).toBe(originalFrontPageId);
    const permalink = publicURL(baseURL, temporaryPage.link);

    const failedBlockAssets: string[] = [];
    page.on('response', (response) => {
      if (
        response.status() >= 400
        && response.url().includes('/wp-content/plugins/goetz-site/blocks/')
      ) {
        failedBlockAssets.push(`${response.status()} ${response.url()}`);
      }
    });

    for (const width of [390, 989, 990, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const response = await page.goto(permalink, { waitUntil: 'networkidle' });
      expect(response?.ok()).toBeTruthy();

      const hero = page.locator('.wp-block-goetz-hero.goetz-hero');
      const grid = page.locator('.wp-block-goetz-attorney-grid.goetz-attorney-grid');
      const cards = grid.locator('.goetz-attorney-card');
      const cta = page.locator('.wp-block-goetz-cta.goetz-cta');
      await expect(hero).toHaveCount(1);
      await expect(grid).toHaveCount(1);
      await expect(cards).toHaveCount(2);
      await expect(grid.locator('h2.goetz-attorney-grid__heading')).toHaveText('Attorneys');
      await expect(cards.locator('h3')).toHaveCount(2);
      await expect(cta).toHaveCount(1);
      await expect(cta.locator('.goetz-cta__background')).toHaveCount(1);
      await expect(cta.getByRole('link', { name: 'Get Consultation' })).toHaveAttribute(
        'href',
        /\/contact\/$/,
      );
      await expect(page.locator('html')).toHaveClass(/goetz-cta-ready/);
      expect(
        await cta.evaluate((section) => (
          section as HTMLElement
        ).style.getPropertyValue('--goetz-cta-background-image')),
      ).toContain('JAMES-L-2.jpg');

      const geometry = await page.evaluate(() => {
        const bounds = (selector: string) => {
          const element = document.querySelector(selector);
          if (!(element instanceof HTMLElement)) throw new Error(`Missing ${selector}`);
          const rect = element.getBoundingClientRect();
          return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
        };
        const gridElement = document.querySelector('.goetz-attorney-grid__cards');
        const body = document.querySelector('.goetz-attorney-card__body');
        const links = document.querySelector('.goetz-attorney-card__links');
        const heading = document.querySelector('.goetz-hero h1');
        if (!gridElement || !body || !links || !heading) {
          throw new Error('Rendered homepage section geometry is incomplete.');
        }
        return {
          heroContent: bounds('.goetz-hero__content'),
          heroImage: bounds('.goetz-hero__media img'),
          cardImage: bounds('.goetz-attorney-card__image'),
          columns: getComputedStyle(gridElement).gridTemplateColumns.split(' ').length,
          cardTextAlign: getComputedStyle(body).textAlign,
          linksJustification: getComputedStyle(links).justifyContent,
          headingFont: parseFloat(getComputedStyle(heading).fontSize),
          overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
      });

      expect(geometry.overflow).toBeLessThanOrEqual(1);
      expect(geometry.cardTextAlign).toBe('center');
      expect(geometry.linksJustification).toBe('center');
      if (width === 390) {
        expect(geometry.columns).toBe(1);
        expect(geometry.heroImage.y).toBeGreaterThan(
          geometry.heroContent.y + geometry.heroContent.height,
        );
        expect(geometry.headingFont).toBeCloseTo(24, 0);
      } else {
        expect(geometry.columns).toBe(2);
        expect(geometry.heroImage.x).toBeGreaterThan(
          geometry.heroContent.x + geometry.heroContent.width,
        );
        expect(geometry.headingFont).toBeCloseTo(width === 989 ? 40 : 50, 0);
      }
      if (width === 1440) {
        expect(geometry.heroContent.width).toBeCloseTo(507.6, 0);
        expect(geometry.heroImage.width).toBeCloseTo(507.6, 0);
        expect(geometry.cardImage.width).toBeCloseTo(507.6, 0);
      }
    }
    expect(failedBlockAssets).toEqual([]);

    const noScriptContext = await browser.newContext({ javaScriptEnabled: false });
    const noScriptPage = await noScriptContext.newPage();
    try {
      await noScriptPage.setViewportSize({ width: 390, height: 844 });
      const response = await noScriptPage.goto(permalink, { waitUntil: 'networkidle' });
      expect(response?.ok()).toBeTruthy();
      const cta = noScriptPage.locator('.wp-block-goetz-cta.goetz-cta');
      await expect(cta.getByText('WE ARE AN EXPERIENCED TEAM', { exact: true })).toBeVisible();
      await expect(cta.getByRole('heading', { name: 'NEED A LAWYER?' })).toBeVisible();
      await expect(cta.getByRole('link', { name: 'Get Consultation' })).toBeVisible();
      const fallback = cta.locator('img.goetz-cta__background');
      await expect(fallback).toBeVisible();
      await expect(fallback).toHaveAttribute('alt', '');
      expect(await fallback.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
      await expect(noScriptPage.locator('html')).not.toHaveClass(/goetz-cta-ready/);
    } finally {
      await noScriptContext.close();
    }
  } finally {
    if (temporaryPage) {
      await hardDeleteTemporaryPage(page, temporaryPage);
      await openAdminApi(page);
      expect(await readFrontPageId(page)).toBe(originalFrontPageId);
    }
  }
});
