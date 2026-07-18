import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';

const mobileViewport = { width: 989, height: 844 };
const desktopViewport = { width: 990, height: 844 };

function baseURL(testInfo: TestInfo): string {
  const configured = testInfo.project.use.baseURL;
  if (typeof configured !== 'string' || configured === '') {
    throw new Error('The public navigation contract requires a configured baseURL.');
  }

  return configured;
}

async function openHomepage(testInfo: TestInfo, page: Page) {
  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await expect(page.locator('html')).toHaveClass(/\bis-navigation-enhanced\b/);
}

async function newNoScriptPage(browser: Browser, testInfo: TestInfo) {
  const context = await browser.newContext({
    baseURL: baseURL(testInfo),
    javaScriptEnabled: false,
    viewport: mobileViewport,
  });
  const page = await context.newPage();
  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();

  return { context, page };
}

test('navigation accessibility exposes semantic skip and heading landmarks', async ({ page }, testInfo) => {
  await page.setViewportSize(desktopViewport);
  await openHomepage(testInfo, page);

  const skipLink = page.locator('body > .goetz-skip-link');
  const main = page.locator('main#primary-content');

  await expect(skipLink).toHaveCount(1);
  await expect(skipLink).toHaveAttribute('href', '#primary-content');
  await expect(main).toHaveCount(1);
  await expect(main).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('main')).toHaveCount(1);
  await expect(page.locator('h1')).toHaveCount(1);

  const levels = await page.locator('main h1, main h2, main h3, main h4, main h5, main h6').evaluateAll(
    (headings) => headings.map((heading) => Number(heading.tagName.slice(1))),
  );
  expect(levels[0]).toBe(1);
  for (let index = 1; index < levels.length; index += 1) {
    expect(levels[index] - levels[index - 1]).toBeLessThanOrEqual(1);
  }

  await page.keyboard.press('Tab');
  await expect(skipLink).toBeFocused();
  await expect(skipLink).toBeVisible();
  const skipRect = await skipLink.boundingBox();
  expect(skipRect).not.toBeNull();
  expect(skipRect!.y).toBeGreaterThanOrEqual(0);

  await page.keyboard.press('Enter');
  await expect(main).toBeFocused();
});

test('navigation accessibility controls the mobile overlay with mouse and keyboard', async ({ page }, testInfo) => {
  await page.setViewportSize(mobileViewport);
  await openHomepage(testInfo, page);

  const toggle = page.locator('#primary-menu-toggle');
  const navigation = page.locator('#primary-navigation');
  const firstLink = navigation.locator('a[href]').first();
  const lastLink = navigation.locator('a[href]').last();

  await expect(toggle).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-controls', 'primary-navigation');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(navigation).not.toBeVisible();

  const closedTarget = await toggle.boundingBox();
  expect(closedTarget).not.toBeNull();
  expect(closedTarget!.width).toBeGreaterThanOrEqual(44);
  expect(closedTarget!.height).toBeGreaterThanOrEqual(44);

  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  for (let index = 0; index < 10 && ! await toggle.evaluate((element) => element === document.activeElement); index += 1) {
    await page.keyboard.press('Tab');
  }
  await expect(toggle).toBeFocused();
  const focusPresentation = await toggle.evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      focusVisible: element.matches(':focus-visible'),
      outlineStyle: style.outlineStyle,
      outlineWidth: Number.parseFloat(style.outlineWidth),
    };
  });
  expect(focusPresentation.focusVisible).toBe(true);
  expect(focusPresentation.outlineStyle).not.toBe('none');
  expect(focusPresentation.outlineWidth).toBeGreaterThanOrEqual(3);

  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(navigation).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toHaveAccessibleName('Close navigation');
  await expect(navigation).toBeVisible();
  await expect.poll(() => page.evaluate(() => (
    (document.activeElement as HTMLElement | null)?.id
      || document.activeElement?.textContent?.trim()
      || document.activeElement?.tagName
      || ''
  ))).toBe('Home');
  await expect(page.locator('body')).toHaveClass(/\bis-navigation-open\b/);
  expect(await page.locator('body').evaluate((body) => getComputedStyle(body).overflow)).toBe('hidden');

  const mobileTargets = await navigation.getByRole('link').evaluateAll((links) => links.map((link) => {
    const rect = link.getBoundingClientRect();
    return { width: rect.width, height: rect.height };
  }));
  for (const target of mobileTargets) {
    expect(target.width).toBeGreaterThanOrEqual(44);
    expect(target.height).toBeGreaterThanOrEqual(44);
  }

  await lastLink.focus();
  await page.keyboard.press('Tab');
  await expect(toggle).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(lastLink).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toHaveAttribute('aria-label', 'Open navigation');
  await expect(toggle).toBeFocused();
  await expect(navigation).not.toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/\bis-navigation-open\b/);
  expect(await page.locator('body').evaluate((body) => getComputedStyle(body).overflow)).not.toBe('hidden');
});

test('navigation accessibility resets the overlay at the 989 to 990 breakpoint', async ({ page }, testInfo) => {
  await page.setViewportSize(mobileViewport);
  await openHomepage(testInfo, page);

  const toggle = page.locator('#primary-menu-toggle');
  const navigation = page.locator('#primary-navigation');
  await toggle.click();
  await expect(navigation).toBeVisible();

  await page.setViewportSize(desktopViewport);
  await expect(toggle).toBeHidden();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toHaveAttribute('aria-label', 'Open navigation');
  await expect(navigation).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/\bis-navigation-open\b/);
});

test('navigation accessibility restores the mobile fallback when the theme controller fails', async ({ page }) => {
  await page.setViewportSize(mobileViewport);
  let abortedControllerRequests = 0;
  await page.route('**/wp-content/themes/goetz-legal/dist/assets/app-*.js', async (route) => {
    abortedControllerRequests += 1;
    await route.abort('failed');
  });

  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await expect.poll(() => abortedControllerRequests).toBeGreaterThan(0);
  await expect(page.locator('html')).not.toHaveClass(/\bis-navigation-enhanced\b/, { timeout: 5_000 });
  await expect(page.locator('#primary-menu-toggle')).toBeHidden();
  await expect(page.locator('#primary-navigation')).toBeVisible();
  await expect(page.locator('#primary-navigation').getByRole('link')).toHaveCount(7);
  await expect(page.locator('main#primary-content')).toBeVisible();
});

test('navigation accessibility leaves the mobile navigation usable without JavaScript', async ({ browser }, testInfo) => {
  const { context, page } = await newNoScriptPage(browser, testInfo);
  try {
    await expect(page.locator('html')).not.toHaveClass(/\bis-navigation-enhanced\b/);
    await expect(page.locator('#primary-menu-toggle')).toBeHidden();
    await expect(page.locator('#primary-navigation')).toBeVisible();
    await expect(page.locator('#primary-navigation').getByRole('link')).toHaveCount(7);
    await expect(page.locator('main#primary-content')).toBeVisible();
  } finally {
    await context.close();
  }
});

test('navigation accessibility has no serious or critical axe violations', async ({ page }, testInfo) => {
  await page.setViewportSize(mobileViewport);
  await openHomepage(testInfo, page);

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const blocking = results.violations.filter(({ impact }) => impact === 'serious' || impact === 'critical');

  expect(blocking).toEqual([]);
});
