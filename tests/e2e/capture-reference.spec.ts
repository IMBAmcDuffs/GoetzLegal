import { createHash } from 'node:crypto';
import {
  lstat,
  mkdir,
  mkdtemp,
  readFile,
  readdir,
  rm,
  stat,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { devices, expect, test, type BrowserContext, type Page } from '@playwright/test';
import { PNG } from 'pngjs';
import {
  captureApprovedScreenshot,
  LEGACY_FIXTURE_NAMES,
  LEGACY_STAGING_PREFIX,
  publishCaptureTransaction,
  recoverCaptureTransaction,
  validateStagedFixtureSet,
} from './helpers/capture-transaction';
import { settlePage } from './helpers/settle-page';

const DEFAULT_REFERENCE_URL = 'https://goetzlegal.com/';
const DEFAULT_REFERENCE_ORIGIN = 'https://goetzlegal.com';
const CAPTURE_MODE = process.env.GOETZ_CAPTURE_MODE || 'contract';
const FIXED_CAPTURE_OUTPUT_DIR = '/work/fixtures/legacy';

const VIEWPORTS = [
  { key: '1440x900', width: 1440, height: 900 },
  { key: '390x844', width: 390, height: 844 },
  { key: '989x844', width: 989, height: 844 },
  { key: '990x844', width: 990, height: 844 },
] as const;

const SECTION_SELECTORS = [
  '#av_section_1',
  '#av_section_2',
  '#av-layout-grid-1',
  '#av_section_3',
  '#av_section_4',
  '#av_section_5',
] as const;

const COMPONENT_SELECTORS = {
  header: '#header',
  topbar: '#header_meta',
  header_main: '#header_main',
  primary_nav: '#avia-menu',
  logo: '#header_main .logo img',
  hero: '#av_section_1',
  hero_heading: '#av_section_1 h1',
  hero_image: '#av_section_1 .avia-image-container img',
  hero_button: '#av_section_1 .avia-button',
  welcome: '#av_section_2',
  welcome_heading: '#av_section_2 h1',
  welcome_border_images: '#av_section_2 .border-img',
  welcome_scale_image: '#av_section_2 .avia-image-container img',
  practice: '#av-layout-grid-1',
  practice_cells: '#av-layout-grid-1 .flex_cell',
  practice_headings: '#av-layout-grid-1 h1, #av-layout-grid-1 h2, #av-layout-grid-1 h3',
  practice_items: '#av-layout-grid-1 .article-icon-entry',
  practice_icons: '#av-layout-grid-1 .iconlist_icon',
  attorneys: '#av_section_3',
  attorney_scale_image: '#av_section_3 img.wp-image-135',
  james_portrait: '#av_section_3 img.wp-image-133',
  gregory_portrait: '#av_section_3 img.wp-image-259',
  attorney_buttons: '#av_section_3 .avia-button',
  cta: '#av_section_4',
  cta_heading: '#av_section_4 h1',
  cta_button: '#av_section_4 .avia-button',
  footer: '#av_section_5',
  footer_logo: '#av_section_5 img',
  footer_columns: '#av_section_5 .entry-content-wrapper > .flex_column',
} as const;

const FIXTURE_NAMES = LEGACY_FIXTURE_NAMES;

interface ReadOnlyGuard {
  guardPage(page: Page): void;
  assertClean(): void;
}

interface ElementGeometry {
  selector: string;
  index: number;
  tag: string;
  id: string;
  class_name: string;
  text: string;
  rect: {
    x: number;
    y: number;
    width: number;
    height: number;
  };
  style: {
    display: string;
    visibility: string;
    opacity: string;
    font_family: string;
    font_size: string;
    line_height: string;
    font_weight: string;
    color: string;
    background_color: string;
    background_image: string;
    border_top_width: string;
    border_top_style: string;
    border_top_color: string;
    border_right_width: string;
    border_right_style: string;
    border_right_color: string;
    border_bottom_width: string;
    border_bottom_style: string;
    border_bottom_color: string;
    border_left_width: string;
    border_left_style: string;
    border_left_color: string;
    border_radius: string;
    padding_top: string;
    padding_right: string;
    padding_bottom: string;
    padding_left: string;
    margin_top: string;
    margin_right: string;
    margin_bottom: string;
    margin_left: string;
    object_fit: string;
    object_position: string;
    transform: string;
    letter_spacing: string;
    text_align: string;
    text_transform: string;
  };
}

function canonicalHTTPSRoot(value: string, label: string): URL {
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error(`${label} is not a valid URL.`);
  }

  if (
    parsed.protocol !== 'https:' ||
    parsed.username !== '' ||
    parsed.password !== '' ||
    parsed.pathname !== '/' ||
    parsed.search !== '' ||
    parsed.hash !== ''
  ) {
    throw new Error(`${label} must be an exact HTTPS origin URL.`);
  }

  return parsed;
}

function validateReferenceTarget(
  referenceValue: string,
  expectedOriginValue: string,
  overrideApproved: string | undefined,
): URL {
  const reference = canonicalHTTPSRoot(referenceValue, 'Reference URL');
  const expected = canonicalHTTPSRoot(expectedOriginValue, 'Reference expected origin');

  if (reference.origin !== expected.origin) {
    throw new Error('Reference URL and expected origin must match.');
  }
  if (reference.origin !== DEFAULT_REFERENCE_ORIGIN && overrideApproved !== '1') {
    throw new Error('A non-default reference requires explicit override approval.');
  }

  return reference;
}

function captureOutputDirectory(): string {
  if (process.env.GOETZ_CAPTURE_OUTPUT_DIR !== FIXED_CAPTURE_OUTPUT_DIR) {
    throw new Error('Capture output must use the fixed Compose fixture mount.');
  }
  return FIXED_CAPTURE_OUTPUT_DIR;
}

async function targetExists(filePath: string): Promise<boolean> {
  try {
    await lstat(filePath);
    return true;
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return false;
    }
    throw error;
  }
}

async function sha256(filePath: string): Promise<string> {
  return createHash('sha256').update(await readFile(filePath)).digest('hex');
}

async function writeSyntheticFixtureStage(stagingDir: string, seed = 'fixture'): Promise<void> {
  await mkdir(stagingDir, { mode: 0o700 });
  const files: Record<string, {
    sha256: string;
    bytes: number;
    pixel_width: number;
    pixel_height: number;
  }> = {};
  const viewports: Record<string, unknown> = {};
  const components: Record<string, unknown> = {};

  for (const viewport of VIEWPORTS) {
    const fileName = `home-${viewport.key}.png`;
    const image = new PNG({ width: viewport.width, height: viewport.height });
    image.data.fill(seed.length % 255);
    const bytes = PNG.sync.write(image);
    await writeFile(path.join(stagingDir, fileName), bytes, { flag: 'wx' });
    const digest = createHash('sha256').update(bytes).digest('hex');
    files[fileName] = {
      sha256: digest,
      bytes: bytes.length,
      pixel_width: viewport.width,
      pixel_height: viewport.height,
    };
    viewports[viewport.key] = {
      viewport: { width: viewport.width, height: viewport.height, device_scale_factor: 1 },
      http_status: 200,
      final_url: 'https://reference.invalid/',
      document: {
        scroll_width: viewport.width,
        scroll_height: viewport.height * 4,
        body_width: viewport.width,
        body_height: viewport.height * 4,
      },
      settlement: {
        scrollPositions: [0, viewport.height, viewport.height * 2, viewport.height * 3],
        finalScrollY: 0,
      },
      images_complete: true,
      returned_to_top: true,
      png: {
        file: fileName,
        sha256: digest,
        bytes: bytes.length,
        pixel_width: viewport.width,
        pixel_height: viewport.height,
      },
    };
    components[viewport.key] = { synthetic: [] };
  }

  await writeFile(
    path.join(stagingDir, 'geometry.json'),
    `${JSON.stringify({
      schema_version: 1,
      reference_url: 'https://reference.invalid/',
      reference_origin: 'https://reference.invalid',
      captured_at_utc: '2026-07-17T00:00:00.000Z',
      browser_name: 'chromium',
      browser_version: 'synthetic',
      device_scale_factor: 1,
      read_only_contract: {
        allowed_methods: ['GET', 'HEAD'],
        blocked_requests: [],
        service_workers: 'blocked',
        downloads: 'blocked',
        popups: 'blocked',
        fixture_overwrite: 'refused',
      },
      dynamic_masks: [],
      viewport_order: VIEWPORTS.map(({ key }) => key),
      viewports,
      components,
      files,
    })}\n`,
    { flag: 'wx' },
  );
}

async function installReadOnlyGuard(context: BrowserContext): Promise<ReadOnlyGuard> {
  const violations: string[] = [];

  await context.route('**/*', async (route) => {
    const method = route.request().method().toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      violations.push(`blocked-http-method:${method}`);
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });

  return {
    guardPage(page: Page): void {
      page.on('popup', (popup) => {
        violations.push('popup');
        void popup.close();
      });
      page.on('download', (download) => {
        violations.push('download');
        void download.cancel();
      });
      page.on('websocket', () => {
        violations.push('websocket');
      });
    },
    assertClean(): void {
      if (violations.length > 0) {
        throw new Error(`Read-only capture policy was violated: ${violations.join(',')}`);
      }
    },
  };
}

async function navigateToReference(page: Page, target: URL): Promise<number> {
  const response = await page.goto(target.href, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() < 200 || response.status() >= 300) {
    throw new Error('Reference homepage did not return a successful document response.');
  }

  assertFinalReferenceLocation(page, target);
  return response.status();
}

function assertFinalReferenceLocation(page: Page, target: URL): void {
  let finalURL: URL;
  try {
    finalURL = new URL(page.url());
  } catch {
    throw new Error('Reference homepage returned an invalid final URL.');
  }
  if (finalURL.origin !== target.origin || finalURL.href !== target.href) {
    throw new Error('Reference homepage final origin or URL did not match the approved target.');
  }
}

async function assertLegacyHomepage(page: Page): Promise<void> {
  const requiredSelectors = ['#header', ...SECTION_SELECTORS];
  for (const selector of requiredSelectors) {
    if (await page.locator(selector).count() !== 1) {
      throw new Error(`Live legacy homepage selector changed: ${selector}`);
    }
  }

  const requiredText = [
    'A law firm with seasoned trial attorneys',
    'Mr. Goetz welcomes',
    'Providing Legal Advice in:',
    'Corporate',
    'Appeals',
    'Attorneys',
    'NEED A LAWYER?',
    'The hiring of a lawyer is an important decision',
  ];
  const bodyText = ((await page.locator('body').textContent()) || '').replace(/\s+/g, ' ');
  for (const marker of requiredText) {
    if (!bodyText.includes(marker)) {
      throw new Error(`Live legacy homepage content marker changed: ${marker}`);
    }
  }
}

async function assertPracticeIconsComplete(page: Page, expectedCount = 7): Promise<void> {
  const states = await page.locator('#av-layout-grid-1 .iconlist_icon, #contract-practice .iconlist_icon')
    .evaluateAll((elements) => elements.map((element) => ({
      opacity: getComputedStyle(element).opacity,
      transform: getComputedStyle(element).transform,
    })));
  const isFinalTransform = (transform: string) =>
    transform === 'none' || transform === 'matrix(1, 0, 0, 1, 0, 0)';
  if (
    states.length !== expectedCount ||
    states.some(({ opacity, transform }) => opacity !== '1' || !isFinalTransform(transform))
  ) {
    throw new Error(
      `Practice icon animation did not reach its complete final state: ${JSON.stringify(states)}`,
    );
  }
}

async function measureComponents(page: Page): Promise<Record<string, ElementGeometry[]>> {
  return page.evaluate((selectorEntries) => {
    const output: Record<string, ElementGeometry[]> = {};
    const normalize = (value: number) => Number(value.toFixed(3));

    for (const [key, selector] of selectorEntries) {
      const elements = Array.from(document.querySelectorAll(selector));
      if (elements.length === 0) {
        throw new Error(`Required capture component is missing: ${key}`);
      }
      output[key] = elements.map((element, index) => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return {
          selector,
          index,
          tag: element.tagName.toLowerCase(),
          id: element.id,
          class_name: typeof element.className === 'string' ? element.className : '',
          text: (element.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 240),
          rect: {
            x: normalize(rect.x),
            y: normalize(rect.y + window.scrollY),
            width: normalize(rect.width),
            height: normalize(rect.height),
          },
          style: {
            display: style.display,
            visibility: style.visibility,
            opacity: style.opacity,
            font_family: style.fontFamily,
            font_size: style.fontSize,
            line_height: style.lineHeight,
            font_weight: style.fontWeight,
            color: style.color,
            background_color: style.backgroundColor,
            background_image: style.backgroundImage,
            border_top_width: style.borderTopWidth,
            border_top_style: style.borderTopStyle,
            border_top_color: style.borderTopColor,
            border_right_width: style.borderRightWidth,
            border_right_style: style.borderRightStyle,
            border_right_color: style.borderRightColor,
            border_bottom_width: style.borderBottomWidth,
            border_bottom_style: style.borderBottomStyle,
            border_bottom_color: style.borderBottomColor,
            border_left_width: style.borderLeftWidth,
            border_left_style: style.borderLeftStyle,
            border_left_color: style.borderLeftColor,
            border_radius: style.borderRadius,
            padding_top: style.paddingTop,
            padding_right: style.paddingRight,
            padding_bottom: style.paddingBottom,
            padding_left: style.paddingLeft,
            margin_top: style.marginTop,
            margin_right: style.marginRight,
            margin_bottom: style.marginBottom,
            margin_left: style.marginLeft,
            object_fit: style.objectFit,
            object_position: style.objectPosition,
            transform: style.transform,
            letter_spacing: style.letterSpacing,
            text_align: style.textAlign,
            text_transform: style.textTransform,
          },
        };
      });
    }

    return output;
  }, Object.entries(COMPONENT_SELECTORS));
}

test.describe('reference capture contract', () => {
  test.skip(CAPTURE_MODE !== 'contract', 'Synthetic contracts run only in non-writing mode.');

  test('exposes the directory transaction and approved-screenshot API', async () => {
    const modulePath = './helpers/capture-transaction';
    await expect(import(modulePath)).resolves.toMatchObject({
      captureApprovedScreenshot: expect.any(Function),
      publishCaptureTransaction: expect.any(Function),
      recoverCaptureTransaction: expect.any(Function),
      validateStagedFixtureSet: expect.any(Function),
    });
  });

  test('waits for delayed fonts and lazy images, activates sections, stabilizes, and returns to top', async ({ page }) => {
    let fontRequested = false;
    let imageRequested = false;
    const pixel = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    );

    await page.route('https://contract.invalid/**', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname === '/font.woff2') {
        fontRequested = true;
        await new Promise((resolve) => setTimeout(resolve, 220));
        await route.fulfill({ status: 404, body: '' });
        return;
      }
      if (url.pathname === '/pixel.png') {
        imageRequested = true;
        await new Promise((resolve) => setTimeout(resolve, 320));
        await route.fulfill({ status: 200, contentType: 'image/png', body: pixel });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html>
          <style>
            @font-face { font-family: contract-delayed; src: url('/font.woff2') format('woff2'); }
            html { scroll-behavior: smooth; }
            html, body { margin: 0; font-family: contract-delayed, sans-serif; }
            [data-section] { min-height: 700px; }
          </style>
          <section id="contract-one" data-section>one</section>
          <section id="contract-practice" data-section>
            practice
            <div class="iconlist_icon" style="opacity: .1; transform: scale(.5)">icon one</div>
            <div class="article-icon-entry" style="opacity: .1; transform: translateY(20px)">one</div>
            <div class="iconlist_icon" style="opacity: .1; transform: scale(.5)">icon two</div>
            <div class="article-icon-entry" style="opacity: .1; transform: translateY(20px)">two</div>
          </section>
          <section id="contract-three" data-section>
            <img loading="lazy" src="/pixel.png" alt="contract pixel" width="1" height="1">
            <div id="late-layout">three</div>
          </section>
          <script>
            window.contractDecodeCalls = 0;
            const nativeDecode = HTMLImageElement.prototype.decode;
            HTMLImageElement.prototype.decode = async function () {
              window.contractDecodeCalls += 1;
              await new Promise((resolve) => setTimeout(resolve, 120));
              await nativeDecode.call(this);
            };
            const observer = new IntersectionObserver((entries) => {
              for (const entry of entries) {
                if (entry.isIntersecting) {
                  entry.target.dataset.activated = 'yes';
                  if (entry.target.id === 'contract-practice' && !entry.target.dataset.scheduled) {
                    entry.target.dataset.scheduled = 'yes';
                    const items = entry.target.querySelectorAll('.article-icon-entry');
                    setTimeout(() => {
                      items.forEach((item) => {
                        item.style.opacity = '.5';
                        item.style.transform = 'translateY(10px)';
                      });
                    }, 700);
                    setTimeout(() => {
                      items.forEach((item) => {
                        item.style.opacity = '1';
                        item.style.transform = 'none';
                      });
                    }, 1050);
                    const icons = entry.target.querySelectorAll('.iconlist_icon');
                    setTimeout(() => {
                      icons.forEach((icon) => {
                        icon.style.opacity = '.5';
                        icon.style.transform = 'scale(.7)';
                      });
                    }, 1300);
                    setTimeout(() => {
                      icons.forEach((icon) => {
                        icon.style.opacity = '.75';
                        icon.style.transform = 'scale(.85)';
                      });
                    }, 1650);
                    setTimeout(() => {
                      icons.forEach((icon) => {
                        icon.style.opacity = '1';
                        icon.style.transform = 'scale(1)';
                      });
                    }, 2000);
                  }
                }
              }
            }, { threshold: 0.15 });
            document.querySelectorAll('[data-section]').forEach((section) => observer.observe(section));
            setTimeout(() => { document.querySelector('#late-layout').style.height = '180px'; }, 420);
          </script>`,
      });
    });

    await page.goto('https://contract.invalid/', { waitUntil: 'domcontentloaded' });
    const startedAt = Date.now();
    const evidence = await settlePage(page, {
      sectionSelectors: ['#contract-one', '#contract-practice', '#contract-three'],
      practiceItemSelector: '.article-icon-entry',
      practiceIconSelector: '.iconlist_icon',
      requiredStableFrames: 45,
      timeoutMs: 8_000,
    });

    expect(Date.now() - startedAt).toBeGreaterThanOrEqual(300);
    expect(fontRequested).toBeTruthy();
    expect(imageRequested).toBeTruthy();
    expect(evidence.fontsReady).toBeTruthy();
    expect(await page.evaluate(() => (window as any).contractDecodeCalls)).toBe(1);
    expect(evidence.fonts.status).toBe('loaded');
    expect(evidence.fonts.faces.length).toBeGreaterThanOrEqual(1);
    expect(evidence.imageCount).toBe(1);
    expect(evidence.scrollPositions).toHaveLength(4);
    expect(evidence.scrollPositions[0]).toBeLessThanOrEqual(1);
    expect(evidence.scrollPositions[1]).toBeGreaterThan(400);
    expect(evidence.scrollPositions[2]).toBeGreaterThan(evidence.scrollPositions[1] + 400);
    expect(evidence.scrollPositions[3]).toBeGreaterThanOrEqual(evidence.scrollPositions[2]);
    expect(evidence.scrollPositions[3]).toBeCloseTo(await page.evaluate(() => (
      document.documentElement.scrollHeight - window.innerHeight
    )), 0);
    expect(evidence.layoutSamples).toBeGreaterThanOrEqual(4);
    expect(evidence.finalScrollY).toBeLessThanOrEqual(1);
    expect(await page.locator('[data-section][data-activated="yes"]').count()).toBe(3);
    expect(await page.locator('#late-layout').evaluate((element) => getComputedStyle(element).height)).toBe('180px');
    expect(await page.locator('.article-icon-entry').evaluateAll((elements) => elements.map((element) => ({
      opacity: getComputedStyle(element).opacity,
      transform: getComputedStyle(element).transform,
    })))).toEqual([
      { opacity: '1', transform: 'none' },
      { opacity: '1', transform: 'none' },
    ]);
    await expect(assertPracticeIconsComplete(page, 2)).resolves.toBeUndefined();
  });

  test('rejects redirects away from the exact approved final origin and URL', async ({ page }) => {
    const target = validateReferenceTarget(
      DEFAULT_REFERENCE_URL,
      DEFAULT_REFERENCE_ORIGIN,
      undefined,
    );
    await page.route('**/*', (route) => route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<!doctype html><title>redirect</title>',
    }));
    await page.goto('https://redirect.invalid/', { waitUntil: 'domcontentloaded' });

    expect(() => assertFinalReferenceLocation(page, target)).toThrow(
      'Reference homepage final origin or URL did not match the approved target.',
    );
  });

  test('requires explicit approval and an exact expected origin for HTTPS overrides', () => {
    expect(() => validateReferenceTarget(
      'https://reference.invalid/',
      'https://reference.invalid',
      undefined,
    )).toThrow('explicit override approval');
    expect(() => validateReferenceTarget(
      'https://reference.invalid/',
      'https://different.invalid',
      '1',
    )).toThrow('must match');
    expect(validateReferenceTarget(
      'https://reference.invalid/',
      'https://reference.invalid',
      '1',
    ).href).toBe('https://reference.invalid/');
    for (const invalid of [
      'http://reference.invalid/',
      'https://user@reference.invalid/',
      'https://reference.invalid/path',
      'https://reference.invalid/?query=1',
      'https://reference.invalid/#fragment',
    ]) {
      expect(() => validateReferenceTarget(invalid, 'https://reference.invalid', '1')).toThrow();
    }
  });

  test('validates legacy source text independently of visual text transforms', async ({ page }) => {
    await page.setContent(`<!doctype html>
      <style>body { text-transform: uppercase; }</style>
      <header id="header"></header>
      <section id="av_section_1">A law firm with seasoned trial attorneys</section>
      <section id="av_section_2">Mr. Goetz welcomes</section>
      <section id="av-layout-grid-1">Providing Legal Advice in: Corporate Appeals</section>
      <section id="av_section_3">Attorneys</section>
      <section id="av_section_4">NEED A LAWYER?</section>
      <section id="av_section_5">The hiring of a lawyer is an important decision</section>`);

    await expect(assertLegacyHomepage(page)).resolves.toBeUndefined();
  });

  test('blocks non-GET and non-HEAD browser requests', async ({ page }) => {
    const guard = await installReadOnlyGuard(page.context());
    guard.guardPage(page);
    await page.setContent('<!doctype html><title>read only</title>');
    await page.evaluate(async () => {
      await fetch('https://contract.invalid/mutate', { method: 'POST', body: 'blocked' }).catch(() => undefined);
    });
    expect(() => guard.assertClean()).toThrow('blocked-http-method:POST');
  });

  test('validates the exact complete staged set and its manifest hashes', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'goetz-capture-contract-'));
    const incomplete = path.join(root, '.legacy-staging-incomplete');
    const tampered = path.join(root, '.legacy-staging-tampered');
    const validate = validateStagedFixtureSet as unknown as (directory: string) => Promise<void>;

    try {
      await mkdir(incomplete);
      await writeFile(path.join(incomplete, 'home-1440x900.png'), 'partial', { flag: 'wx' });
      await expect(validate(incomplete)).rejects.toThrow('exact five-file fixture set');

      await writeSyntheticFixtureStage(tampered);
      const tamperedPath = path.join(tampered, 'home-390x844.png');
      const tamperedBytes = Buffer.from(await readFile(tamperedPath));
      tamperedBytes[0] ^= 0xff;
      await writeFile(tamperedPath, tamperedBytes);
      await expect(validate(tampered)).rejects.toThrow('hash mismatch');
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('recovers only a validated complete interrupted transaction', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'goetz-capture-contract-'));
    const staging = path.join(root, '.legacy-staging-interrupted');
    const finalDir = path.join(root, 'legacy');
    const recover = recoverCaptureTransaction as unknown as (
      parentDirectory: string,
      finalDirectory: string,
      approvedTarget: URL,
    ) => Promise<string>;

    try {
      await writeSyntheticFixtureStage(staging, 'recovered');
      await expect(recover(
        root,
        finalDir,
        new URL('https://reference.invalid/'),
      )).resolves.toBe('recovered');
      expect(await targetExists(staging)).toBeFalsy();
      expect((await readdir(finalDir)).sort()).toEqual([...FIXTURE_NAMES].sort());
      await expect(validateStagedFixtureSet(finalDir)).resolves.toBeUndefined();
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('does not recover a complete stage captured for a different approved target', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'goetz-capture-contract-'));
    const staging = path.join(root, '.legacy-staging-other-reference');
    const finalDir = path.join(root, 'legacy');
    const recover = recoverCaptureTransaction as unknown as (
      parentDirectory: string,
      finalDirectory: string,
      approvedTarget: URL,
    ) => Promise<string>;

    try {
      await writeSyntheticFixtureStage(staging, 'other-reference');
      await expect(recover(
        root,
        finalDir,
        new URL('https://current-reference.invalid/'),
      )).resolves.toBe('none');
      expect(await targetExists(staging)).toBeFalsy();
      expect(await targetExists(finalDir)).toBeFalsy();
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('cleans an interrupted partial stage and retries with atomic complete-set visibility', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'goetz-capture-contract-'));
    const partial = path.join(root, '.legacy-staging-partial');
    const retry = path.join(root, '.legacy-staging-retry');
    const finalDir = path.join(root, 'legacy');
    const recover = recoverCaptureTransaction as unknown as (
      parentDirectory: string,
      finalDirectory: string,
      approvedTarget: URL,
    ) => Promise<string>;
    const publish = publishCaptureTransaction as unknown as (
      stagingDirectory: string,
      finalDirectory: string,
      approvedTarget: URL,
    ) => Promise<void>;

    try {
      await mkdir(partial);
      await writeFile(path.join(partial, 'home-1440x900.png'), 'interrupted', { flag: 'wx' });
      const approvedTarget = new URL('https://reference.invalid/');
      await expect(recover(root, finalDir, approvedTarget)).resolves.toBe('none');
      expect(await targetExists(partial)).toBeFalsy();
      expect(await targetExists(finalDir)).toBeFalsy();

      await writeSyntheticFixtureStage(retry, 'retry');
      const observations: string[][] = [];
      let sampling = true;
      const observer = (async () => {
        while (sampling) {
          if (await targetExists(finalDir)) {
            observations.push((await readdir(finalDir)).sort());
          }
          await new Promise((resolve) => setTimeout(resolve, 0));
        }
      })();
      await publish(retry, finalDir, approvedTarget);
      observations.push((await readdir(finalDir)).sort());
      sampling = false;
      await observer;

      expect(await targetExists(retry)).toBeFalsy();
      expect(observations.length).toBeGreaterThan(0);
      expect(observations.every((names) => (
        JSON.stringify(names) === JSON.stringify([...FIXTURE_NAMES].sort())
      ))).toBeTruthy();
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('refuses an existing final set without changing either transaction directory', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'goetz-capture-contract-'));
    const staging = path.join(root, '.legacy-staging-new');
    const finalDir = path.join(root, 'legacy');
    const publish = publishCaptureTransaction as unknown as (
      stagingDirectory: string,
      finalDirectory: string,
      approvedTarget: URL,
    ) => Promise<void>;

    try {
      await writeSyntheticFixtureStage(finalDir, 'existing');
      await writeSyntheticFixtureStage(staging, 'new');
      const before = await Promise.all(FIXTURE_NAMES.map((name) => sha256(path.join(finalDir, name))));
      await expect(publish(
        staging,
        finalDir,
        new URL('https://reference.invalid/'),
      )).rejects.toThrow('Immutable capture target already exists');
      const after = await Promise.all(FIXTURE_NAMES.map((name) => sha256(path.join(finalDir, name))));
      expect(after).toEqual(before);
      expect(await targetExists(staging)).toBeTruthy();
      expect((await readdir(staging)).sort()).toEqual([...FIXTURE_NAMES].sort());
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('rejects a delayed navigation that completes during screenshot capture', async () => {
    const target = new URL('https://approved.invalid/');
    let currentURL = target.href;
    const delayedNavigationPage = {
      url: () => currentURL,
      screenshot: async () => {
        await new Promise((resolve) => setTimeout(resolve, 10));
        currentURL = 'https://late-navigation.invalid/';
        return Buffer.from('synthetic screenshot');
      },
    };
    const capture = captureApprovedScreenshot as unknown as (
      page: typeof delayedNavigationPage,
      approvedTarget: URL,
      screenshotPath: string,
    ) => Promise<Buffer>;

    await expect(capture(delayedNavigationPage, target, '/tmp/not-written.png')).rejects.toThrow(
      'Reference homepage final origin or URL did not match the approved target.',
    );
  });

  test('does not alter the fixed fixture directory in contract mode', async () => {
    if (process.env.GOETZ_CAPTURE_OUTPUT_DIR !== FIXED_CAPTURE_OUTPUT_DIR) {
      return;
    }
    const before = await readdir(FIXED_CAPTURE_OUTPUT_DIR);
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(await readdir(FIXED_CAPTURE_OUTPUT_DIR)).toEqual(before);
  });

  test('enforces the contract fixture parent as filesystem read-only', async () => {
    if (process.env.GOETZ_CAPTURE_OUTPUT_DIR !== FIXED_CAPTURE_OUTPUT_DIR) {
      return;
    }
    const probePath = path.join(path.dirname(FIXED_CAPTURE_OUTPUT_DIR), `.contract-write-probe-${process.pid}`);
    let errorCode = '';
    try {
      await writeFile(probePath, 'must not be written', { flag: 'wx' });
    } catch (error) {
      errorCode = (error as NodeJS.ErrnoException).code || '';
    } finally {
      await rm(probePath, { force: true }).catch(() => undefined);
    }
    expect(['EROFS', 'EACCES']).toContain(errorCode);
    expect(await targetExists(probePath)).toBeFalsy();
  });

  test('accepts the immutable pre-fix fixture schema without rewriting its evidence', async () => {
    if (process.env.GOETZ_CAPTURE_OUTPUT_DIR !== FIXED_CAPTURE_OUTPUT_DIR) {
      return;
    }
    const before = await Promise.all(FIXTURE_NAMES.map((name) => (
      sha256(path.join(FIXED_CAPTURE_OUTPUT_DIR, name))
    )));
    await expect(validateStagedFixtureSet(FIXED_CAPTURE_OUTPUT_DIR)).resolves.toBeUndefined();
    const after = await Promise.all(FIXTURE_NAMES.map((name) => (
      sha256(path.join(FIXED_CAPTURE_OUTPUT_DIR, name))
    )));
    expect(after).toEqual(before);
  });
});

test('writes the immutable legacy homepage capture set', async ({ browser }) => {
  test.skip(CAPTURE_MODE !== 'write', 'Live capture runs only through visual:capture-reference.');

  const outputDir = captureOutputDirectory();
  const referenceURL = process.env.GOETZ_REFERENCE_URL;
  const expectedOrigin = process.env.GOETZ_REFERENCE_EXPECT_ORIGIN;
  if (!referenceURL || !expectedOrigin) {
    throw new Error('Validated reference target variables were not supplied.');
  }
  const target = validateReferenceTarget(
    referenceURL,
    expectedOrigin,
    process.env.GOETZ_REFERENCE_OVERRIDE_APPROVED,
  );

  const fixturesParent = path.dirname(outputDir);
  const recovery = await recoverCaptureTransaction(fixturesParent, outputDir, target);
  if (recovery === 'recovered') {
    await validateStagedFixtureSet(outputDir, target);
    return;
  }
  const stagingDir = await mkdtemp(path.join(fixturesParent, LEGACY_STAGING_PREFIX));

  const viewports: Record<string, unknown> = {};
  const components: Record<string, Record<string, ElementGeometry[]>> = {};
  const files: Record<string, {
    sha256: string;
    bytes: number;
    pixel_width: number;
    pixel_height: number;
  }> = {};

  try {
    for (const viewport of VIEWPORTS) {
      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: 1,
        serviceWorkers: 'block',
        acceptDownloads: false,
        colorScheme: 'light',
        reducedMotion: 'no-preference',
        locale: 'en-US',
        timezoneId: 'America/New_York',
        userAgent: devices['Desktop Chrome'].userAgent,
      });
      const guard = await installReadOnlyGuard(context);

      try {
        const page = await context.newPage();
        guard.guardPage(page);
        context.on('page', (candidate) => {
          if (candidate !== page) {
            void candidate.close();
          }
        });

        const status = await navigateToReference(page, target);
        await assertLegacyHomepage(page);
        const settlement = await settlePage(page, {
          sectionSelectors: SECTION_SELECTORS,
          practiceItemSelector: '.article-icon-entry',
          practiceIconSelector: '.iconlist_icon',
          timeoutMs: 60_000,
          requiredStableFrames: 45,
        });
        guard.assertClean();
        await assertPracticeIconsComplete(page);
        assertFinalReferenceLocation(page, target);

        const componentGeometry = await measureComponents(page);
        components[viewport.key] = componentGeometry;
        const documentGeometry = await page.evaluate(() => {
          const normalize = (value: number) => Number(value.toFixed(3));
          return {
            scroll_width: document.documentElement.scrollWidth,
            scroll_height: document.documentElement.scrollHeight,
            body_width: normalize(document.body.getBoundingClientRect().width),
            body_height: normalize(document.body.getBoundingClientRect().height),
          };
        });

        const fileName = `home-${viewport.key}.png`;
        const screenshotPath = path.join(stagingDir, fileName);
        const imageBuffer = await captureApprovedScreenshot(page, target, screenshotPath);
        const png = PNG.sync.read(imageBuffer);
        if (png.width !== viewport.width || png.height < viewport.height) {
          throw new Error(`Captured PNG dimensions are invalid for ${viewport.key}.`);
        }

        const digest = await sha256(screenshotPath);
        const fileStat = await stat(screenshotPath);
        files[fileName] = {
          sha256: digest,
          bytes: fileStat.size,
          pixel_width: png.width,
          pixel_height: png.height,
        };
        const maximumScrollY = Math.max(0, documentGeometry.scroll_height - viewport.height);
        if (
          settlement.scrollPositions.length !== SECTION_SELECTORS.length + 1
          || settlement.scrollPositions.some((position, index, positions) => (
            position < 0 || (index > 0 && position < positions[index - 1])
          ))
          || Math.abs(settlement.scrollPositions.at(-1)! - maximumScrollY) > 1
        ) {
          throw new Error(`Reference section traversal was not meaningful for ${viewport.key}.`);
        }
        assertFinalReferenceLocation(page, target);
        viewports[viewport.key] = {
          viewport: { width: viewport.width, height: viewport.height, device_scale_factor: 1 },
          http_status: status,
          final_url: page.url(),
          document: documentGeometry,
          settlement,
          images_complete: true,
          returned_to_top: settlement.finalScrollY <= 1,
          png: {
            file: fileName,
            sha256: digest,
            bytes: fileStat.size,
            pixel_width: png.width,
            pixel_height: png.height,
          },
        };
        guard.assertClean();
      } finally {
        await context.close();
      }
    }

    const geometry = {
      schema_version: 1,
      reference_url: target.href,
      reference_origin: target.origin,
      captured_at_utc: new Date().toISOString(),
      browser_name: browser.browserType().name(),
      browser_version: browser.version(),
      device_scale_factor: 1,
      read_only_contract: {
        allowed_methods: ['GET', 'HEAD'],
        blocked_requests: [],
        service_workers: 'blocked',
        downloads: 'blocked',
        popups: 'blocked',
        fixture_overwrite: 'refused',
      },
      dynamic_masks: [],
      viewport_order: VIEWPORTS.map(({ key }) => key),
      viewports,
      components,
      files,
    };
    await writeFile(
      path.join(stagingDir, 'geometry.json'),
      `${JSON.stringify(geometry, null, 2)}\n`,
      { flag: 'wx', mode: 0o644 },
    );

    await publishCaptureTransaction(stagingDir, outputDir, target);
  } finally {
    await rm(stagingDir, { recursive: true, force: true });
  }
});
