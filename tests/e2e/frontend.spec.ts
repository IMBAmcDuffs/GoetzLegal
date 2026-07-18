import { expect, test, type Page, type Request, type Response } from '@playwright/test';

const selectedBaseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';
const expectedOrigin = new URL(process.env.GOETZ_EXPECT_ORIGIN || selectedBaseURL).origin;

const routes = [
  { name: 'home', path: '/' },
  { name: 'James L. Goetz', path: '/james-l-goetz/' },
  { name: 'Gregory W. Goetz', path: '/gregory-w-goetz/' },
  { name: 'staff', path: '/staff/' },
  { name: 'questions', path: '/questions/' },
  { name: 'links', path: '/links/' },
  { name: 'contact', path: '/contact/' },
] as const;

const responsiveWidths = [320, 390, 989, 990, 1440] as const;
const sameOriginAssetTypes = new Set(['document', 'stylesheet', 'script', 'image', 'font']);

function expectedURL(routePath: string): string {
  return new URL(routePath, `${expectedOrigin}/`).href;
}

function isSameOriginAsset(request: Request): boolean {
  try {
    return new URL(request.url()).origin === expectedOrigin
      && sameOriginAssetTypes.has(request.resourceType());
  } catch {
    return false;
  }
}

function installFrontendDiagnostics(page: Page): {
  assertClean: () => void;
} {
  const errors: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console:${message.text()}`);
    }
  });
  page.on('pageerror', (error) => errors.push(`pageerror:${error.message}`));
  page.on('requestfailed', (request) => {
    if (isSameOriginAsset(request)) {
      errors.push(`requestfailed:${request.resourceType()}:${request.url()}:${request.failure()?.errorText || 'unknown'}`);
    }
  });
  page.on('response', (response: Response) => {
    const request = response.request();
    if (isSameOriginAsset(request) && response.status() >= 400) {
      errors.push(`response:${response.status()}:${request.resourceType()}:${response.url()}`);
    }
  });

  return {
    assertClean: () => expect(errors, `Frontend diagnostics failed:\n${errors.join('\n')}`).toEqual([]),
  };
}

async function settleRouteImages(page: Page): Promise<number> {
  const imageCount = await page.locator('img').count();
  await page.evaluate(async () => {
    if (document.fonts) await document.fonts.ready;
    const images = Array.from(document.images);
    for (const image of images) {
      image.scrollIntoView({ block: 'center' });
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
    }
    window.scrollTo(0, Math.max(0, document.documentElement.scrollHeight - window.innerHeight));
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
  });
  await page.waitForFunction(() => Array.from(document.images).every(
    (image) => image.complete && image.naturalWidth > 0 && image.naturalHeight > 0,
  ));
  await page.evaluate(async () => {
    await Promise.all(Array.from(document.images).map((image) => image.decode()));
    window.scrollTo(0, 0);
  });
  await page.waitForFunction(() => window.scrollY <= 1);
  return imageCount;
}

async function expectSafeLinks(page: Page): Promise<void> {
  const unsafe = await page.locator('a[href]').evaluateAll((anchors) => anchors.flatMap((anchor) => {
    const href = anchor.getAttribute('href')?.trim() || '';
    if (href === '') return ['dead-link:empty-href'];
    if (href.startsWith('#')) {
      if (href === '#') return ['dead-link:bare-fragment'];
      let fragment: string;
      try {
        fragment = decodeURIComponent(href.slice(1));
      } catch {
        return [`invalid-fragment:${href}`];
      }
      const document = anchor.ownerDocument;
      const targetExists = document.getElementById(fragment) !== null
        || document.getElementsByName(fragment).length > 0;
      return targetExists ? [] : [`dead-link:missing-fragment-target:${href}`];
    }

    let parsed: URL;
    try {
      parsed = new URL(href, window.location.href);
    } catch {
      return [`invalid-url:${href}`];
    }
    if (!['http:', 'https:', 'mailto:', 'tel:'].includes(parsed.protocol)) {
      return [`unsafe-protocol:${href}`];
    }
    if (parsed.username !== '' || parsed.password !== '') {
      return [`embedded-credentials:${href}`];
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return [];

    const target = (anchor.getAttribute('target') || '').toLowerCase();
    const rel = new Set((anchor.getAttribute('rel') || '').toLowerCase().split(/\s+/u).filter(Boolean));
    if (target === '_blank' && !rel.has('noopener') && !rel.has('noreferrer')) {
      return [`reverse-tabnabbing:${href}`];
    }
    if (target !== '' && !['_blank', '_self', '_parent', '_top'].includes(target)) {
      return [`unsafe-link-target:${target}:${href}`];
    }
    return [];
  }));

  expect(unsafe, `Unsafe links found:\n${unsafe.join('\n')}`).toEqual([]);
}

test.describe('frontend link safety contract', () => {
  test('rejects empty, bare, and unresolved same-document links', async ({ page }) => {
    for (const href of ['', '#', '#missing']) {
      await page.setContent(`<a href="${href}">Broken</a>`);
      await expect(expectSafeLinks(page)).rejects.toThrow(/Unsafe links found/);
    }
  });

  test('accepts a same-document fragment only when its target exists', async ({ page }) => {
    await page.setContent('<a href="#main">Skip</a><main id="main">Content</main>');
    await expect(expectSafeLinks(page)).resolves.toBeUndefined();
    await page.setContent('<a href="#legacy">Legacy</a><a name="legacy">Target</a>');
    await expect(expectSafeLinks(page)).resolves.toBeUndefined();
  });
});

for (const route of routes) {
  test(`frontend routes: ${route.name} returns one complete, error-free document`, async ({ page }) => {
    test.setTimeout(60_000);
    const diagnostics = installFrontendDiagnostics(page);
    const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });

    expect(response?.status()).toBe(200);
    expect(response?.url()).toBe(expectedURL(route.path));
    expect(page.url()).toBe(expectedURL(route.path));
    await expect(page.locator('main')).toHaveCount(1);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('main h1')).toHaveCount(1);
    await expect(page.locator('main h1')).toBeVisible();
    const imageCount = await settleRouteImages(page);
    expect(imageCount, `${route.path} must render at least one real image`).toBeGreaterThan(0);
    await page.waitForLoadState('networkidle');
    expect(await page.locator('img').evaluateAll((images) => images.map((image) => ({
      complete: (image as HTMLImageElement).complete,
      naturalHeight: (image as HTMLImageElement).naturalHeight,
      naturalWidth: (image as HTMLImageElement).naturalWidth,
    })).filter((image) => !image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0))).toEqual([]);
    await expectSafeLinks(page);
    diagnostics.assertClean();
  });

  test(`frontend routes: ${route.name} never overflows at the five responsive widths`, async ({ page }) => {
    test.setTimeout(120_000);
    const diagnostics = installFrontendDiagnostics(page);
    for (const width of responsiveWidths) {
      await page.setViewportSize({ width, height: width <= 390 ? 844 : 900 });
      const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), `${route.path} status at ${width}px`).toBe(200);
      expect(response?.url(), `${route.path} final URL at ${width}px`).toBe(expectedURL(route.path));
      const imageCount = await settleRouteImages(page);
      expect(imageCount, `${route.path} must render an image at ${width}px`).toBeGreaterThan(0);
      await page.waitForLoadState('networkidle');
      expect(page.url(), `${route.path} stable final URL at ${width}px`).toBe(expectedURL(route.path));
      const overflow = await page.evaluate(() => ({
        bodyClient: document.body.clientWidth,
        bodyScroll: document.body.scrollWidth,
        rootClient: document.documentElement.clientWidth,
        rootScroll: document.documentElement.scrollWidth,
      }));
      expect(overflow.rootScroll, `${route.path} root overflow at ${width}px`)
        .toBeLessThanOrEqual(overflow.rootClient + 1);
      expect(overflow.bodyScroll, `${route.path} body overflow at ${width}px`)
        .toBeLessThanOrEqual(overflow.bodyClient + 1);
    }
    diagnostics.assertClean();
  });
}

test('frontend routes: contact form renders completely without any submission', async ({ page }) => {
  let mutatingRequests = 0;
  await page.route('**/*', async (route) => {
    if (!['GET', 'HEAD'].includes(route.request().method())) {
      mutatingRequests += 1;
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });

  const response = await page.goto('/contact/', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  const form = page.locator('.goetz-contact-form form');
  await expect(form).toHaveCount(1);
  await expect(form).toBeVisible();
  const name = form.getByRole('textbox', { name: /^Name\b/i });
  const email = form.getByRole('textbox', { name: /^E-?Mail\b/i });
  const phone = form.getByRole('textbox', { name: /^Phone\b/i });
  const message = form.getByRole('textbox', { name: /^Message\b/i });
  for (const field of [name, email, phone, message]) {
    await expect(field).toHaveCount(1);
    await expect(field).toBeVisible();
  }
  const submit = form.getByRole('button', { name: /^Submit\b/i });
  await expect(submit).toHaveCount(1);
  await expect(submit).toBeVisible();
  await expect(submit).toBeEnabled();
  const target = await form.evaluate((element) => ({
    action: (element as HTMLFormElement).action,
    method: (element as HTMLFormElement).method.toLowerCase(),
  }));
  expect(target.method).toBe('post');
  expect(new URL(target.action || page.url()).origin).toBe(expectedOrigin);
  expect(page.url()).toBe(expectedURL('/contact/'));
  expect(mutatingRequests).toBe(0);
});
