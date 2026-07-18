import { expect, test, type Page } from '@playwright/test';

const pageMetadata = {
  home: [
    'Fort Myers Trial Attorneys | Goetz & Goetz',
    'Goetz & Goetz provides experienced legal counsel in Fort Myers for corporate, construction, real estate, probate, criminal and bankruptcy matters.',
  ],
  'james-l-goetz': [
    'James L. Goetz, Attorney | Goetz & Goetz',
    'Learn about James L. Goetz, a Fort Myers attorney with more than 50 years of experience in trial, probate, real estate and commercial litigation.',
  ],
  'gregory-w-goetz': [
    'Gregory W. Goetz, Attorney | Goetz & Goetz',
    'Learn about Gregory W. Goetz, a Fort Myers attorney serving clients in Florida state and federal courts across a range of legal matters.',
  ],
  staff: [
    'Legal Team and Staff | Goetz & Goetz',
    'Meet the attorneys and legal staff at Goetz & Goetz in Fort Myers, Florida, and find direct contact information for the firm.',
  ],
  questions: [
    'Florida Legal Questions | Goetz & Goetz',
    'Read answers from Goetz & Goetz to common Florida legal questions about construction, homestead protection, wills, real estate and dispute resolution.',
  ],
  links: [
    'Florida and Federal Legal Links | Goetz & Goetz',
    'Find useful Florida and federal court, government, bar association, property, tax and legal resources selected by Goetz & Goetz.',
  ],
  contact: [
    'Contact Goetz & Goetz | Fort Myers Attorneys',
    'Contact Goetz & Goetz in Fort Myers, Florida, by phone, email or online form to discuss your legal questions and request a consultation.',
  ],
} as const;

const selectedBaseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';
const expectedOrigin = new URL(process.env.GOETZ_EXPECT_ORIGIN || selectedBaseURL).origin;
const productionExpected = process.env.GOETZ_EXPECT_PRODUCTION === '1';
const environmentHosts = new Set([
  'localhost',
  '127.0.0.1',
  '::1',
  'goetzgoetz.kinsta.cloud',
  'goetzlegal.com',
  'www.goetzlegal.com',
]);

function routeForSlug(slug: keyof typeof pageMetadata): string {
  return slug === 'home' ? '/' : `/${slug}/`;
}

function expectedURL(path: string): string {
  return new URL(path, `${expectedOrigin}/`).href;
}

function xmlLocations(xml: string): string[] {
  return Array.from(xml.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/giu), (match) => (
    match[1]
      .replaceAll('&amp;', '&')
      .replaceAll('&lt;', '<')
      .replaceAll('&gt;', '>')
      .replaceAll('&quot;', '"')
      .replaceAll('&#039;', "'")
      .trim()
  ));
}

function expectPortableEnvironmentURLs(document: string): void {
  const portableURLs = document.match(/(?:https?:)?\/\/[^\s"'<>]+/giu) ?? [];
  for (const candidate of portableURLs) {
    let parsed: URL;
    try {
      const decoded = candidate.replaceAll('&amp;', '&');
      parsed = new URL(decoded.startsWith('//') ? `${new URL(expectedOrigin).protocol}${decoded}` : decoded);
    } catch {
      continue;
    }
    if (environmentHosts.has(parsed.hostname.toLowerCase())) {
      expect(parsed.origin, `Environment URL escaped the selected origin: ${candidate}`).toBe(expectedOrigin);
    }
  }
}

async function expectOneMeta(page: Page, selector: string, expected: string): Promise<void> {
  const locator = page.locator(selector);
  await expect(locator).toHaveCount(1);
  await expect(locator).toHaveAttribute('content', expected);
}

async function expectSocialImageDimensions(page: Page, imageURL: string): Promise<void> {
  const dimensions = await page.evaluate(async (url) => {
    const image = new Image();
    const loaded = new Promise<{ width: number; height: number }>((resolve, reject) => {
      image.addEventListener('load', () => resolve({ width: image.naturalWidth, height: image.naturalHeight }), { once: true });
      image.addEventListener('error', () => reject(new Error('Social image failed to load.')), { once: true });
    });
    image.src = url;
    return loaded;
  }, imageURL);

  expect(dimensions).toEqual({ width: 1200, height: 630 });
}

function organizationFromGraphs(documents: unknown[]): Record<string, unknown> {
  const graphDocuments = documents.filter((document): document is { '@graph': unknown[] } => (
    typeof document === 'object'
      && document !== null
      && Array.isArray((document as { '@graph'?: unknown })['@graph'])
  ));
  expect(graphDocuments).toHaveLength(1);
  const organizations = graphDocuments[0]['@graph'].filter((piece): piece is Record<string, unknown> => {
    if (typeof piece !== 'object' || piece === null) return false;
    const types = Array.isArray((piece as Record<string, unknown>)['@type'])
      ? (piece as Record<string, unknown>)['@type'] as unknown[]
      : [(piece as Record<string, unknown>)['@type']];
    return types.includes('LegalService');
  });
  expect(organizations).toHaveLength(1);

  return organizations[0];
}

test.beforeAll(() => {
  const expected = new URL(expectedOrigin);
  const isLoopback = ['localhost', '127.0.0.1', '::1'].includes(expected.hostname);
  if (productionExpected) {
    expect(expected.origin).toBe('https://goetzlegal.com');
    return;
  }
  if (!isLoopback) {
    expect(expected.hostname).toBe('goetzgoetz.kinsta.cloud');
  }
});

for (const [slug, [title, description]] of Object.entries(pageMetadata) as Array<[
  keyof typeof pageMetadata,
  readonly [string, string],
]>) {
  test(`SEO contract: ${slug} has exact portable metadata and one LegalService graph`, async ({ page }) => {
    const path = routeForSlug(slug);
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBeTruthy();
    expect(response?.url()).toBe(expectedURL(path));

    await expect(page).toHaveTitle(title);
    await expectOneMeta(page, 'meta[name="description"]', description);
    await expectOneMeta(page, 'meta[property="og:title"]', title);
    await expectOneMeta(page, 'meta[property="og:description"]', description);

    const robots = page.locator('meta[name="robots"]');
    await expect(robots).toHaveCount(1);
    const robotsContent = (await robots.getAttribute('content')) || '';
    expect(robotsContent).toContain('index');
    expect(robotsContent).toContain('follow');
    expect(robotsContent).toContain('max-image-preview:large');
    expect(robotsContent).not.toContain('noindex');

    const canonical = page.locator('link[rel="canonical"]');
    await expect(canonical).toHaveCount(1);
    await expect(canonical).toHaveAttribute('href', expectedURL(path));
    await expectOneMeta(page, 'meta[property="og:url"]', expectedURL(path));

    const openGraphImage = page.locator('meta[property="og:image"]');
    const twitterImage = page.locator('meta[name="twitter:image"]');
    await expect(openGraphImage).toHaveCount(1);
    await expect(twitterImage).toHaveCount(1);
    const openGraphImageURL = await openGraphImage.getAttribute('content');
    const twitterImageURL = await twitterImage.getAttribute('content');
    expect(openGraphImageURL).toBeTruthy();
    expect(twitterImageURL).toBe(openGraphImageURL);
    expect(new URL(openGraphImageURL!).origin).toBe(expectedOrigin);
    await expectOneMeta(page, 'meta[property="og:image:width"]', '1200');
    await expectOneMeta(page, 'meta[property="og:image:height"]', '630');
    await expectOneMeta(page, 'meta[name="twitter:card"]', 'summary_large_image');
    await expectSocialImageDimensions(page, openGraphImageURL!);

    const schemaDocuments = await page.locator('script[type="application/ld+json"]').evaluateAll((scripts) => (
      scripts.map((script) => JSON.parse(script.textContent || 'null'))
    ));
    const organization = organizationFromGraphs(schemaDocuments);
    expect(organization['@type']).toEqual(['Organization', 'LegalService']);
    expect(organization['@id']).toBeTruthy();
    expect(organization['url']).toBe(expectedURL('/'));
    expect(organization['name']).toBe('Goetz & Goetz');
    expect(organization['alternateName']).toBe('Goetz and Goetz');
    expect(organization['telephone']).toBe('+12399362841');
    expect(organization['email']).toBe('info@goetzlegal.com');
    expect(organization['logo']).toBeTruthy();
    expect(organization['image']).toBeTruthy();
    expect(organization['address']).toEqual({
      '@type': 'PostalAddress',
      streetAddress: '33 Barkley Cir Ste 100',
      addressLocality: 'Fort Myers',
      addressRegion: 'FL',
      postalCode: '33907',
      addressCountry: 'US',
    });
    expect(organization['areaServed']).toEqual({
      '@type': 'City',
      name: 'Fort Myers, Florida',
    });

    expectPortableEnvironmentURLs(await page.content());
  });
}

async function recursivelyReadSitemaps(page: Page): Promise<{
  sitemapURLs: string[];
  pageURLs: string[];
}> {
  const bootstrapResponse = await page.goto(expectedURL('/'), { waitUntil: 'domcontentloaded' });
  expect(bootstrapResponse?.ok()).toBeTruthy();

  const pending = [expectedURL('/sitemap_index.xml')];
  const visited = new Set<string>();
  const sitemapURLs: string[] = [];
  const pageURLs: string[] = [];

  while (pending.length > 0) {
    const sitemapURL = pending.shift()!;
    if (visited.has(sitemapURL)) continue;
    visited.add(sitemapURL);
    const parsedSitemapURL = new URL(sitemapURL);
    expect(parsedSitemapURL.origin).toBe(expectedOrigin);

    // Browser navigation applies Yoast's XSL stylesheet and exposes transformed
    // HTML. Fetching from an established same-origin page preserves the raw XML.
    const response = await page.evaluate(async (url) => {
      const request = await fetch(url, { cache: 'no-store', credentials: 'omit' });
      return {
        body: await request.text(),
        ok: request.ok,
        status: request.status,
        url: request.url,
      };
    }, sitemapURL);
    expect(
      response.ok,
      `Sitemap request failed (${response.status}): ${sitemapURL}`,
    ).toBeTruthy();
    expect(new URL(response.url).origin).toBe(expectedOrigin);
    const body = response.body;
    expectPortableEnvironmentURLs(body);
    const locations = xmlLocations(body);
    expect(
      locations.length,
      `Sitemap has no <loc> entries: ${sitemapURL}\n${body.slice(0, 500)}`,
    ).toBeGreaterThan(0);

    if (/<sitemapindex[\s>]/iu.test(body)) {
      for (const location of locations) {
        expect(new URL(location).origin).toBe(expectedOrigin);
        pending.push(location);
        sitemapURLs.push(location);
      }
      continue;
    }

    expect(/<urlset[\s>]/iu.test(body)).toBeTruthy();
    for (const location of locations) {
      expect(new URL(location).origin).toBe(expectedOrigin);
      pageURLs.push(location);
    }
  }

  return { sitemapURLs, pageURLs };
}

test('SEO contract: sitemap recursively contains only the seven approved page URLs', async ({ page }) => {
  const { sitemapURLs, pageURLs } = await recursivelyReadSitemaps(page);
  const expectedPages = Object.keys(pageMetadata)
    .map((slug) => expectedURL(routeForSlug(slug as keyof typeof pageMetadata)))
    .sort();

  expect([...new Set(sitemapURLs)].sort()).toEqual([expectedURL('/page-sitemap.xml')]);
  expect([...new Set(pageURLs)].sort()).toEqual(expectedPages);
  expect(pageURLs).toHaveLength(7);
  expect(sitemapURLs.join('\n')).not.toMatch(/author|date|attachment|post-|category|post_tag|taxonomy/iu);
});
