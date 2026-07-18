import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { settlePage } from './helpers/settle-page';

interface Rect {
  x: number;
  y: number;
  width: number;
  height: number;
}

interface Style {
  font_family: string;
  font_size: string;
  line_height: string;
  font_weight: string;
  color: string;
  background_color: string;
}

interface FixtureComponent {
  rect: Rect;
  style: Style;
}

interface GeometryFixture {
  schema_version: number;
  viewport_order: string[];
  files: Record<string, { sha256: string; bytes: number }>;
  viewports: Record<string, {
    document: { scroll_width: number; scroll_height: number };
  }>;
  components: Record<string, Record<string, FixtureComponent[]>>;
}

interface CandidateComponent {
  rect: Rect;
  style: {
    fontFamily: string;
    fontSize: number;
    lineHeight: number;
    fontWeight: string;
    color: string;
    backgroundColor: string;
  };
}

type CandidateGeometry = Record<string, CandidateComponent[]> & {
  document: CandidateComponent[];
};

const fixtureDirectory = process.env.GOETZ_VISUAL_FIXTURE_DIR
  || (existsSync('/work/fixtures/legacy')
    ? '/work/fixtures/legacy'
    : path.resolve('../visual/fixtures/legacy'));
const fixturePath = path.join(fixtureDirectory, 'geometry.json');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as GeometryFixture;
const sectionSelectors = [
  '.site-header',
  '.goetz-hero',
  '.goetz-welcome',
  '.goetz-practice-areas',
  '.goetz-attorney-grid',
  '.goetz-cta',
  '.site-footer',
] as const;

const candidateSelectors: Record<string, string> = {
  header: '.site-header',
  topbar: '.site-header__top',
  header_main: '.site-header__nav-row',
  primary_nav: '#primary-navigation',
  logo: '.site-branding-card .custom-logo',
  hero: '.goetz-hero',
  hero_heading: '.goetz-hero h1',
  hero_image: '.goetz-hero__image',
  hero_button: '.goetz-hero .goetz-button',
  welcome: '.goetz-welcome',
  welcome_heading: '.goetz-intro__heading',
  welcome_border_images: '.goetz-intro__image',
  welcome_scale_image: '.goetz-intro__icon',
  practice: '.goetz-practice-areas',
  practice_cells: '.goetz-practice-band__image, .goetz-practice-band__content',
  practice_headings: '.goetz-practice-areas__heading, .goetz-practice-area-item__label',
  practice_items: '.goetz-practice-area-item',
  practice_icons: '.goetz-practice-area-item__scale',
  attorneys: '.goetz-attorney-grid',
  james_portrait: '.goetz-attorney-grid .goetz-attorney-card:first-child .goetz-attorney-card__image',
  gregory_portrait: '.goetz-attorney-grid .goetz-attorney-card:nth-child(2) .goetz-attorney-card__image',
  attorney_buttons: '.goetz-attorney-grid .goetz-attorney-card__links a',
  cta: '.goetz-cta',
  cta_heading: '.goetz-cta h2',
  cta_button: '.goetz-cta .goetz-button',
  footer: '.site-footer',
  footer_logo: '.site-footer__logo',
  footer_columns: '.site-footer__grid > section, .site-footer__bottom',
};

function fixtureComponent(viewport: string, key: string, index = 0): FixtureComponent {
  const component = fixture.components[viewport]?.[key]?.[index];
  if (!component) {
    throw new Error(`Missing legacy geometry component ${viewport}.${key}[${index}]`);
  }

  return component;
}

function within(actual: number, expected: number, tolerance: number, label: string): void {
  expect(Math.abs(actual - expected), `${label}: expected ${expected} +/- ${tolerance}, received ${actual}`)
    .toBeLessThanOrEqual(tolerance);
}

function dimensionTolerance(reference: number, mobile: boolean): number {
  return Math.max(mobile ? 4 : 2, reference * 0.01);
}

function sectionTolerance(reference: number): number {
  return Math.max(16, reference * 0.03);
}

function numeric(value: string): number {
  return Number.parseFloat(value);
}

async function measure(page: Page): Promise<CandidateGeometry> {
  return page.evaluate((selectors) => {
    const measured = Object.fromEntries(Object.entries(selectors).map(([key, selector]) => {
      const components = Array.from(document.querySelectorAll<HTMLElement>(selector)).map((element) => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return {
          rect: {
            x: box.x,
            y: box.y + window.scrollY,
            width: box.width,
            height: box.height,
          },
          style: {
            fontFamily: style.fontFamily,
            fontSize: Number.parseFloat(style.fontSize),
            lineHeight: Number.parseFloat(style.lineHeight),
            fontWeight: style.fontWeight,
            color: style.color,
            backgroundColor: style.backgroundColor,
          },
        };
      });
      if (components.length === 0) {
        throw new Error(`Required homepage geometry selector is missing: ${selector}`);
      }
      return [key, components];
    }));

    const root = document.documentElement;
    measured.document = [{
      rect: {
        x: 0,
        y: 0,
        width: root.scrollWidth,
        height: root.scrollHeight,
      },
      style: {
        fontFamily: getComputedStyle(document.body).fontFamily,
        fontSize: Number.parseFloat(getComputedStyle(document.body).fontSize),
        lineHeight: Number.parseFloat(getComputedStyle(document.body).lineHeight),
        fontWeight: getComputedStyle(document.body).fontWeight,
        color: getComputedStyle(document.body).color,
        backgroundColor: getComputedStyle(document.body).backgroundColor,
      },
    }];

    return measured as CandidateGeometry;
  }, candidateSelectors);
}

async function openSettledHomepage(page: Page): Promise<CandidateGeometry> {
  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  for (const selector of sectionSelectors) {
    await expect(page.locator(selector)).toHaveCount(1);
  }
  await settlePage(page, {
    sectionSelectors,
    practiceItemSelector: '.goetz-practice-area-item',
    practiceIconSelector: '.goetz-practice-area-item__scale',
  });
  await page.waitForFunction(() => {
    const section = document.querySelector('.goetz-practice-areas');
    const items = Array.from(
      document.querySelectorAll<HTMLElement>('.goetz-practice-area-item'),
    );
    const icons = Array.from(
      document.querySelectorAll<HTMLElement>('.goetz-practice-area-item__scale'),
    );

    return section?.classList.contains('is-animation-complete')
      && items.length > 0
      && items.every((item) => {
        const style = getComputedStyle(item);
        return style.opacity === '1' && style.transform === 'none';
      })
      && icons.length === items.length
      && icons.every((icon) => {
        const style = getComputedStyle(icon);
        const rect = icon.getBoundingClientRect();
        return style.opacity === '1'
          && (style.transform === 'none' || style.transform === 'matrix(1, 0, 0, 1, 0, 0)')
          && Math.abs(rect.width - Number.parseFloat(style.width)) <= 0.1
          && Math.abs(rect.height - Number.parseFloat(style.height)) <= 0.1;
      });
  });

  return measure(page);
}

test('homepage geometry uses the immutable committed reference fixture', async () => {
  expect(fixture.schema_version).toBe(1);
  expect(fixture.viewport_order).toEqual(['1440x900', '390x844', '989x844', '990x844']);
  expect(Object.keys(fixture.files).sort()).toEqual([
    'home-1440x900.png',
    'home-390x844.png',
    'home-989x844.png',
    'home-990x844.png',
  ]);

  for (const [filename, contract] of Object.entries(fixture.files)) {
    const bytes = readFileSync(path.join(fixtureDirectory, filename));
    expect(bytes.byteLength).toBe(contract.bytes);
    expect(createHash('sha256').update(bytes).digest('hex')).toBe(contract.sha256);
  }
});

for (const viewport of [
  { width: 1440, height: 900 },
  { width: 390, height: 844 },
  { width: 989, height: 844 },
  { width: 990, height: 844 },
]) {
  const fixtureKey = `${viewport.width}x${viewport.height}`;
  test(`homepage geometry matches the reference at ${fixtureKey}`, async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize(viewport);
    const candidate = await openSettledHomepage(page);
    const mobile = viewport.width <= 600;

    expect(candidate.document[0].rect.width).toBe(viewport.width);
    within(
      candidate.document[0].rect.height,
      fixture.viewports[fixtureKey].document.scroll_height,
      fixture.viewports[fixtureKey].document.scroll_height * 0.05,
      `${fixtureKey} document height`,
    );

    for (const key of ['header', 'hero', 'welcome', 'practice', 'attorneys', 'cta', 'footer']) {
      const actual = candidate[key][0].rect;
      const reference = fixtureComponent(fixtureKey, key).rect;
      within(actual.x, reference.x, dimensionTolerance(reference.width, mobile), `${fixtureKey} ${key} x`);
      within(actual.width, reference.width, dimensionTolerance(reference.width, mobile), `${fixtureKey} ${key} width`);
      within(actual.height, reference.height, sectionTolerance(reference.height), `${fixtureKey} ${key} height`);
      within(actual.y, reference.y, Math.max(20, reference.y * 0.03), `${fixtureKey} ${key} y`);
    }

    for (const key of [
      'hero_heading',
      'hero_image',
      'hero_button',
      'welcome_heading',
      'welcome_border_images',
      'welcome_scale_image',
      'practice_cells',
      'practice_items',
      'practice_icons',
      'james_portrait',
      'gregory_portrait',
      'attorney_buttons',
      'cta_heading',
      'cta_button',
      'footer_logo',
      'footer_columns',
    ]) {
      const references = fixture.components[fixtureKey][key];
      const actuals = candidate[key];
      expect(actuals.length, `${fixtureKey} ${key} count`).toBe(references.length);
      for (let index = 0; index < references.length; index += 1) {
        const actual = actuals[index].rect;
        const reference = references[index].rect;
        const tolerance = dimensionTolerance(reference.width, mobile);
        within(actual.x, reference.x, tolerance, `${fixtureKey} ${key}[${index}] x`);
        within(actual.width, reference.width, tolerance, `${fixtureKey} ${key}[${index}] width`);
        within(actual.height, reference.height, sectionTolerance(reference.height), `${fixtureKey} ${key}[${index}] height`);
      }
    }

    for (const key of ['hero_heading', 'welcome_heading', 'cta_heading', 'hero_button', 'attorney_buttons', 'cta_button']) {
      const actuals = candidate[key];
      const references = fixture.components[fixtureKey][key];
      for (let index = 0; index < references.length; index += 1) {
        expect(actuals[index].style.fontFamily.toLowerCase()).toContain('roboto');
        within(actuals[index].style.fontSize, numeric(references[index].style.font_size), 1, `${fixtureKey} ${key} font size`);
        within(actuals[index].style.lineHeight, numeric(references[index].style.line_height), 2, `${fixtureKey} ${key} line height`);
        expect(actuals[index].style.fontWeight).toBe(references[index].style.font_weight);
      }
    }

    const attorneyMark = await page.locator('img.goetz-attorney-grid__mark').evaluate((mark) => {
      const style = getComputedStyle(mark);
      return {
        display: style.display,
        height: Number.parseFloat(style.height),
        src: mark.getAttribute('src') || '',
        width: Number.parseFloat(style.width),
      };
    });
    expect(attorneyMark.display).toBe('block');
    expect(attorneyMark.src).toContain('law-scale-icon-purple.png');
    expect(attorneyMark.width).toBe(40);
    expect(attorneyMark.height).toBe(39);

    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(viewport.width);
  });
}

test('homepage geometry remains ordered and overflow-free at 320px', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 320, height: 700 });
  const candidate = await openSettledHomepage(page);

  expect(candidate.document[0].rect.width).toBe(320);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(320);

  const order = ['hero', 'welcome', 'practice', 'attorneys', 'cta', 'footer'];
  for (let index = 1; index < order.length; index += 1) {
    const previous = candidate[order[index - 1]][0].rect;
    const current = candidate[order[index]][0].rect;
    expect(current.y).toBeGreaterThanOrEqual(previous.y + previous.height - 1);
  }

  const heroContent = candidate.hero_heading[0].rect;
  const heroImage = candidate.hero_image[0].rect;
  expect(heroContent.y).toBeLessThan(heroImage.y);

  expect(candidate.practice_cells[0].rect.x).toBe(candidate.practice_cells[1].rect.x);
  expect(candidate.practice_cells[1].rect.y).toBeGreaterThan(candidate.practice_cells[0].rect.y);
  expect(candidate.james_portrait[0].rect.x).toBe(candidate.gregory_portrait[0].rect.x);
  expect(candidate.gregory_portrait[0].rect.y).toBeGreaterThan(candidate.james_portrait[0].rect.y);
});
