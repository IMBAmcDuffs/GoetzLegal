import {
  expect,
  test,
  type Browser,
  type Page,
  type TestInfo,
} from '@playwright/test';
import { isLoopbackURL } from './helpers/browser.mjs';
import { withTemporaryDraft } from './helpers/wordpress';

const parentBlockName = 'goetz/practice-areas';
const childBlockName = 'goetz/practice-area-item';
const labels = [
  'Corporate',
  'Construction',
  'Real Estate',
  'Probate',
  'Criminal',
  'Bankruptcy',
  'Appeals',
];
const widths = [320, 390, 989, 990, 1440];

function requireBaseURL(testInfo: TestInfo): string {
  const baseURL = testInfo.project.use.baseURL;
  if (typeof baseURL !== 'string') {
    throw new Error('Practice Areas browser coverage requires a configured base URL.');
  }
  return baseURL;
}

function requireLocalAuthenticatedProject(testInfo: TestInfo): string {
  const baseURL = requireBaseURL(testInfo);
  test.skip(
    !isLoopbackURL(baseURL),
    'Practice Areas editor mutation coverage is local-only.',
  );
  return baseURL;
}

async function waitForPracticeBlocks(page: Page): Promise<void> {
  await page.waitForFunction(
    ({ parent, child }) => Boolean(
      (globalThis as any).wp?.blocks?.getBlockType(parent)
        && (globalThis as any).wp?.blocks?.getBlockType(child)
        && (globalThis as any).wp?.data,
    ),
    { parent: parentBlockName, child: childBlockName },
  );
}

async function resetToPracticeAreas(page: Page): Promise<void> {
  await page.evaluate(
    ({ parent, child, initialLabels }) => {
      const wordpress = (globalThis as any).wp;
      const children = initialLabels.map((label: string) =>
        wordpress.blocks.createBlock(child, { label }),
      );
      const practice = wordpress.blocks.createBlock(
        parent,
        {
          heading: 'Providing <strong>Legal Advice</strong> in:',
          backgroundImageId: 0,
          backgroundImageUrl:
            '/wp-content/plugins/goetz-site/assets/seed/JAMES-L-2.jpg',
          backgroundImageAlt: 'Law office library',
          scaleImageId: 0,
          scaleImageUrl:
            '/wp-content/plugins/goetz-site/assets/seed/law-scale-icon-purple.png',
          scaleImageAlt: '',
        },
        children,
      );
      wordpress.data.dispatch('core/block-editor').resetBlocks([practice]);
    },
    { parent: parentBlockName, child: childBlockName, initialLabels: labels },
  );

  await expect.poll(() => readPracticeState(page)).toMatchObject({
    name: parentBlockName,
    labels,
    valid: true,
  });
}

async function readPracticeState(page: Page): Promise<{
  name: string;
  attributes: Record<string, unknown>;
  labels: string[];
  valid: boolean;
  content: string;
}> {
  return page.evaluate((parent) => {
    const wordpress = (globalThis as any).wp;
    const practice = wordpress.data
      .select('core/block-editor')
      .getBlocks()
      .find((block: any) => block.name === parent);
    return {
      name: String(practice?.name || ''),
      attributes: practice?.attributes || {},
      labels: (practice?.innerBlocks || []).map(
        (block: any) => String(block.attributes?.label || ''),
      ),
      valid: practice?.isValid !== false,
      content: String(
        wordpress.data.select('core/editor').getEditedPostContent?.() || '',
      ),
    };
  }, parentBlockName);
}

async function saveEditor(page: Page): Promise<void> {
  const error = await page.evaluate(async () => {
    const wordpress = (globalThis as any).wp;
    await wordpress.data.dispatch('core/editor').savePost();
    const editor = wordpress.data.select('core/editor');
    const lastError = editor.getLastPostSavingError?.();
    return lastError ? String(lastError.message || lastError.code || lastError) : '';
  });
  expect(error).toBe('');
  await expect.poll(() => page.evaluate(() => {
    const editor = (globalThis as any).wp.data.select('core/editor');
    return {
      dirty: editor.isEditedPostDirty(),
      saving: editor.isSavingPost(),
    };
  })).toEqual({ dirty: false, saving: false });
}

async function editorCanvas(page: Page) {
  const iframe = page.locator('iframe[name="editor-canvas"]');
  return (await iframe.count()) > 0 ? iframe.contentFrame() : page;
}

function practiceAssetURL(baseURL: string, filename: string): string {
  return new URL(
    `/wp-content/plugins/goetz-site/blocks/practice-areas/${filename}`,
    baseURL,
  ).toString();
}

function practiceMarkup(baseURL: string): string {
  const parentStyle = practiceAssetURL(baseURL, 'style.css');
  const childStyle = new URL(
    '/wp-content/plugins/goetz-site/blocks/practice-area-item/style.css',
    baseURL,
  ).toString();
  const script = practiceAssetURL(baseURL, 'view.js');
  const items = labels.map((label) => `
    <li class="wp-block-goetz-practice-area-item goetz-practice-area-item">
      <span class="goetz-practice-area-item__scale" aria-hidden="true">
        <img class="goetz-practice-area-item__scale-image"
          src="${new URL('/wp-content/plugins/goetz-site/assets/seed/law-scale-icon-purple.png', baseURL)}"
          alt="">
      </span>
      <b class="goetz-practice-area-item__label">${label}</b>
    </li>
  `).join('');

  return `<!doctype html>
    <html class="no-js"><head>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="${parentStyle}">
      <link rel="stylesheet" href="${childStyle}">
    </head><body>
      <div data-test-spacer style="height:1100px"></div>
      <section class="wp-block-goetz-practice-areas goetz-practice-areas goetz-practice-band">
        <figure class="goetz-practice-band__image"></figure>
        <div class="goetz-practice-band__content">
          <h2 class="goetz-practice-areas__heading">Practice Areas</h2>
          <ul class="goetz-practice-list">${items}</ul>
        </div>
      </section>
      <div style="height:1100px"></div>
      <script src="${script}"></script>
    </body></html>`;
}

async function openPracticeFixture(page: Page, baseURL: string): Promise<void> {
  const failedAssets: string[] = [];
  page.on('response', (response) => {
    if (response.status() >= 400 && response.url().includes('/goetz-site/')) {
      failedAssets.push(`${response.status()} ${response.url()}`);
    }
  });
  await page.setContent(practiceMarkup(baseURL), { waitUntil: 'load' });
  expect(failedAssets).toEqual([]);
}

async function completedState(page: Page) {
  return page.evaluate(() => {
    const section = document.querySelector('.goetz-practice-areas') as HTMLElement;
    const items = [...document.querySelectorAll<HTMLElement>('.goetz-practice-area-item')];
    return {
      ready: section.classList.contains('is-animation-ready'),
      complete: section.classList.contains('is-animation-complete'),
      revealed: items.filter((item) => item.classList.contains('is-revealed')).length,
      opacities: items.map((item) => getComputedStyle(item).opacity),
      transforms: items.map((item) => getComputedStyle(item).transform),
    };
  });
}

test('Practice Areas editor saves keyboard edits and child reorder through InnerBlocks', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'auth');
  test.setTimeout(120_000);
  page.setDefaultTimeout(8_000);
  page.setDefaultNavigationTimeout(15_000);
  requireLocalAuthenticatedProject(testInfo);

  await withTemporaryDraft(page, async (draft) => {
    await waitForPracticeBlocks(page);
    await resetToPracticeAreas(page);
    const canvas = await editorCanvas(page);
    const heading = canvas.getByLabel('Practice Areas heading', { exact: true });
    await expect(heading).toBeVisible();
    await heading.click();
    await heading.press('Control+A');
    await heading.pressSequentially('Trusted Practice Counsel');

    const childLabels = canvas.getByLabel('Practice area label', { exact: true });
    await expect(childLabels).toHaveCount(7);
    const firstLabel = childLabels.nth(0);
    await firstLabel.click();
    await firstLabel.press('Control+A');
    await firstLabel.pressSequentially('Corporate Counsel');

    const secondLabel = childLabels.nth(1);
    await secondLabel.click();
    await secondLabel.press('Control+Shift+Alt+T');
    await expect.poll(() => readPracticeState(page)).toMatchObject({
      labels: ['Construction', 'Corporate Counsel', ...labels.slice(2)],
    });

    await saveEditor(page);
    const beforeReload = await readPracticeState(page);
    expect(beforeReload.attributes).toEqual(expect.objectContaining({
      heading: 'Trusted Practice Counsel',
      backgroundImageAlt: 'Law office library',
    }));
    expect(beforeReload.content.match(/wp:goetz\/practice-area-item/g)).toHaveLength(7);

    await page.goto(`/wp-admin/post.php?post=${draft.id}&action=edit`, {
      waitUntil: 'domcontentloaded',
    });
    await waitForPracticeBlocks(page);
    const afterReload = await readPracticeState(page);
    expect(afterReload).toMatchObject({
      name: parentBlockName,
      labels: ['Construction', 'Corporate Counsel', ...labels.slice(2)],
      valid: true,
    });
    expect(afterReload.attributes).toEqual(expect.objectContaining({
      heading: 'Trusted Practice Counsel',
      backgroundImageUrl:
        '/wp-content/plugins/goetz-site/assets/seed/JAMES-L-2.jpg',
      scaleImageUrl:
        '/wp-content/plugins/goetz-site/assets/seed/law-scale-icon-purple.png',
    }));
    expect(
      await page.getByText(
        /invalid content|attempt block recovery|modified externally|updated outside of this editor/i,
      ).count(),
    ).toBe(0);
  });
});

test('Practice Areas animation follows measured timing once at every responsive width', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');
  test.setTimeout(45_000);
  const baseURL = requireBaseURL(testInfo);
  await page.setViewportSize({ width: 1440, height: 720 });
  await openPracticeFixture(page, baseURL);

  const section = page.locator('.goetz-practice-areas');
  await expect(section).toHaveClass(/is-animation-ready/);
  const initial = await completedState(page);
  expect(initial).toMatchObject({ ready: true, complete: false, revealed: 0 });
  expect(initial.opacities.every((opacity) => opacity === '0')).toBe(true);
  const corporateScale = page.getByRole('listitem')
    .filter({ hasText: 'Corporate' })
    .locator('.goetz-practice-area-item__scale');
  const initialScale = await corporateScale
    .evaluate((element) => {
      const style = getComputedStyle(element);
      return {
        opacity: style.opacity,
        transform: style.transform,
      };
    });
  expect(initialScale).toEqual({
    opacity: '0.1',
    transform: 'matrix(0.5, 0, 0, 0.5, 0, 0)',
  });

  await page.evaluate(() => {
    const items = [...document.querySelectorAll('.goetz-practice-area-item')];
    (globalThis as any).__goetzPracticeTimes = [];
    (globalThis as any).__goetzPracticeStart = performance.now();
    const observer = new MutationObserver((records) => {
      records.forEach((record) => {
        const item = record.target as HTMLElement;
        if (item.classList.contains('is-revealed')) {
          (globalThis as any).__goetzPracticeTimes.push(
            performance.now() - (globalThis as any).__goetzPracticeStart,
          );
        }
      });
    });
    items.forEach((item) => observer.observe(item, { attributes: true, attributeFilter: ['class'] }));
    document.querySelector('.goetz-practice-list')?.scrollIntoView({ block: 'center' });
  });

  await expect.poll(() => page.evaluate(
    () => ((globalThis as any).__goetzPracticeTimes || []).length,
  ), { timeout: 6_000 }).toBe(7);
  const times = await page.evaluate(
    () => (globalThis as any).__goetzPracticeTimes as number[],
  );
  const expectedTimes = [200, 550, 900, 1250, 1600, 1950, 2300];
  times.forEach((actual, index) => {
    expect(Math.abs(actual - expectedTimes[index])).toBeLessThanOrEqual(180);
  });

  const scaleTransition = await corporateScale
    .evaluate((element) => {
      const style = getComputedStyle(element);
      return {
        duration: style.transitionDuration,
        timing: style.transitionTimingFunction,
      };
    });
  expect(scaleTransition.duration).toContain('1s');
  expect(scaleTransition.timing).toContain(
    'cubic-bezier(0.175, 0.885, 0.32, 1.275)',
  );

  await expect.poll(() => completedState(page), { timeout: 5_000 }).toMatchObject({
    complete: true,
    revealed: 7,
    opacities: ['1', '1', '1', '1', '1', '1', '1'],
  });
  const persistedTimes = [...times];
  await page.locator('[data-test-spacer]').scrollIntoViewIfNeeded();
  await page.locator('.goetz-practice-list').scrollIntoViewIfNeeded();
  expect(await page.evaluate(
    () => (globalThis as any).__goetzPracticeTimes as number[],
  )).toEqual(persistedTimes);

  for (const width of widths) {
    await page.setViewportSize({ width, height: 720 });
    const responsive = await page.evaluate(() => {
      const section = document.querySelector('.goetz-practice-areas') as HTMLElement;
      return {
        overflow: document.documentElement.scrollWidth - window.innerWidth,
        columns: getComputedStyle(section).gridTemplateColumns.split(' ').length,
        visible: [...document.querySelectorAll('.goetz-practice-area-item')]
          .every((item) => getComputedStyle(item).visibility === 'visible'),
      };
    });
    expect(responsive.overflow, `width ${width} must not overflow`).toBeLessThanOrEqual(0);
    expect(responsive.visible, `width ${width} must retain every item`).toBe(true);
    expect(responsive.columns).toBe(width >= 990 ? 2 : 1);
  }
});

test('Practice Areas animation completes immediately for reduced motion', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');
  const baseURL = requireBaseURL(testInfo);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await openPracticeFixture(page, baseURL);

  expect(await completedState(page)).toMatchObject({
    ready: true,
    complete: true,
    revealed: 7,
    opacities: ['1', '1', '1', '1', '1', '1', '1'],
  });
  const durations = await page.locator('.goetz-practice-area-item__scale')
    .evaluateAll((elements) => elements.map(
      (element) => getComputedStyle(element).transitionDuration,
    ));
  expect(durations.every((duration) => duration === '0s')).toBe(true);
});

test('Practice Areas animation leaves the no-JavaScript default fully visible', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');
  const baseURL = requireBaseURL(testInfo);
  const context = await (browser as Browser).newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 720 },
  });
  const page = await context.newPage();
  try {
    await page.setContent(practiceMarkup(baseURL), { waitUntil: 'load' });
    const state = await completedState(page);
    expect(state).toMatchObject({ ready: false, complete: false, revealed: 0 });
    expect(state.opacities).toEqual(['1', '1', '1', '1', '1', '1', '1']);
    expect(state.transforms.every((transform) => transform === 'none')).toBe(true);
  } finally {
    await context.close();
  }
});
