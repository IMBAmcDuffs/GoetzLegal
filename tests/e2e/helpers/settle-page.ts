import type { Page } from '@playwright/test';

export interface SettlePageOptions {
  sectionSelectors: readonly string[];
  practiceItemSelector?: string;
  practiceIconSelector?: string;
  timeoutMs?: number;
  requiredStableFrames?: number;
}

export interface SettlePageEvidence {
  fontsReady: true;
  fonts: {
    status: 'loading' | 'loaded';
    faces: Array<{
      family: string;
      style: string;
      weight: string;
      status: 'unloaded' | 'loading' | 'loaded' | 'error';
    }>;
  };
  imageCount: number;
  scrollPositions: number[];
  layoutSamples: number;
  finalScrollY: number;
}

interface LayoutSignature {
  scrollWidth: number;
  scrollHeight: number;
  bodyWidth: number;
  bodyHeight: number;
  sections: Array<{
    selector: string;
    x: number;
    y: number;
    width: number;
    height: number;
  }>;
  practiceItems: Array<{
    index: number;
    x: number;
    y: number;
    width: number;
    height: number;
    opacity: string;
    transform: string;
  }>;
  practiceIcons: Array<{
    index: number;
    x: number;
    y: number;
    width: number;
    height: number;
    opacity: string;
    transform: string;
  }>;
}

const DEFAULT_TIMEOUT_MS = 45_000;

function signaturesMatch(left: LayoutSignature, right: LayoutSignature): boolean {
  const tolerance = 0.25;
  if (
    Math.abs(left.scrollWidth - right.scrollWidth) > tolerance ||
    Math.abs(left.scrollHeight - right.scrollHeight) > tolerance ||
    Math.abs(left.bodyWidth - right.bodyWidth) > tolerance ||
    Math.abs(left.bodyHeight - right.bodyHeight) > tolerance ||
    left.sections.length !== right.sections.length
    || left.practiceItems.length !== right.practiceItems.length
    || left.practiceIcons.length !== right.practiceIcons.length
  ) {
    return false;
  }

  const sectionsMatch = left.sections.every((section, index) => {
    const candidate = right.sections[index];
    return (
      section.selector === candidate.selector &&
      Math.abs(section.x - candidate.x) <= tolerance &&
      Math.abs(section.y - candidate.y) <= tolerance &&
      Math.abs(section.width - candidate.width) <= tolerance &&
      Math.abs(section.height - candidate.height) <= tolerance
    );
  });
  if (!sectionsMatch) {
    return false;
  }

  const practiceItemsMatch = left.practiceItems.every((item, index) => {
    const candidate = right.practiceItems[index];
    return (
      item.index === candidate.index &&
      Math.abs(item.x - candidate.x) <= tolerance &&
      Math.abs(item.y - candidate.y) <= tolerance &&
      Math.abs(item.width - candidate.width) <= tolerance &&
      Math.abs(item.height - candidate.height) <= tolerance &&
      item.opacity === candidate.opacity &&
      item.transform === candidate.transform
    );
  });
  if (!practiceItemsMatch) {
    return false;
  }

  return left.practiceIcons.every((icon, index) => {
    const candidate = right.practiceIcons[index];
    return (
      icon.index === candidate.index &&
      Math.abs(icon.x - candidate.x) <= tolerance &&
      Math.abs(icon.y - candidate.y) <= tolerance &&
      Math.abs(icon.width - candidate.width) <= tolerance &&
      Math.abs(icon.height - candidate.height) <= tolerance &&
      icon.opacity === candidate.opacity &&
      icon.transform === candidate.transform
    );
  });
}

async function layoutSignature(
  page: Page,
  sectionSelectors: readonly string[],
  practiceItemSelector?: string,
  practiceIconSelector?: string,
): Promise<LayoutSignature> {
  return page.evaluate(({ selectors, itemSelector, iconSelector }) => {
    const root = document.documentElement;
    const body = document.body;
    return {
      scrollWidth: root.scrollWidth,
      scrollHeight: root.scrollHeight,
      bodyWidth: body.getBoundingClientRect().width,
      bodyHeight: body.getBoundingClientRect().height,
      sections: selectors.map((selector) => {
        const element = document.querySelector(selector);
        if (!(element instanceof HTMLElement)) {
          throw new Error(`Required settle section is missing: ${selector}`);
        }
        const rect = element.getBoundingClientRect();
        return {
          selector,
          x: rect.x,
          y: rect.y + window.scrollY,
          width: rect.width,
          height: rect.height,
        };
      }),
      practiceItems: itemSelector
        ? Array.from(document.querySelectorAll(itemSelector)).map((element, index) => {
          const rect = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          return {
            index,
            x: rect.x,
            y: rect.y + window.scrollY,
            width: rect.width,
            height: rect.height,
            opacity: style.opacity,
            transform: style.transform,
          };
        })
        : [],
      practiceIcons: iconSelector
        ? Array.from(document.querySelectorAll(iconSelector)).map((element, index) => {
          const rect = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          return {
            index,
            x: rect.x,
            y: rect.y + window.scrollY,
            width: rect.width,
            height: rect.height,
            opacity: style.opacity,
            transform: style.transform,
          };
        })
        : [],
    };
  }, {
    selectors: [...sectionSelectors],
    itemSelector: practiceItemSelector,
    iconSelector: practiceIconSelector,
  });
}

async function waitForLayoutStability(
  page: Page,
  sectionSelectors: readonly string[],
  practiceItemSelector: string | undefined,
  practiceIconSelector: string | undefined,
  timeoutMs: number,
  requiredStableFrames: number,
): Promise<number> {
  const deadline = Date.now() + timeoutMs;
  let previous: LayoutSignature | undefined;
  let consecutive = 0;
  let samples = 0;

  while (Date.now() < deadline) {
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => resolve())));
    const current = await layoutSignature(
      page,
      sectionSelectors,
      practiceItemSelector,
      practiceIconSelector,
    );
    samples += 1;

    if (previous && signaturesMatch(previous, current)) {
      consecutive += 1;
      if (consecutive >= requiredStableFrames) {
        return samples;
      }
    } else {
      consecutive = 1;
    }

    previous = current;
  }

  throw new Error('Page layout did not become stable before the capture timeout.');
}

export async function settlePage(
  page: Page,
  options: SettlePageOptions,
): Promise<SettlePageEvidence> {
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const requiredStableFrames = Math.max(45, options.requiredStableFrames ?? 45);

  if (options.sectionSelectors.length === 0) {
    throw new Error('At least one settle section selector is required.');
  }

  const fonts = await page.evaluate(async () => {
    if (document.fonts) {
      await document.fonts.ready;
    }
    return {
      status: document.fonts.status,
      faces: Array.from(document.fonts).map((face) => ({
        family: face.family,
        style: face.style,
        weight: face.weight,
        status: face.status,
      })),
    };
  });

  const scrollPositions: number[] = [];
  for (const selector of options.sectionSelectors) {
    const section = page.locator(selector);
    if (await section.count() !== 1) {
      throw new Error(`Required settle section must match exactly once: ${selector}`);
    }
    await section.evaluate((element) => {
      element.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
    });
    scrollPositions.push(await page.evaluate(() => window.scrollY));
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => resolve())));
  }

  await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'auto' }));
  scrollPositions.push(await page.evaluate(() => window.scrollY));

  await page.waitForFunction(
    () => Array.from(document.images).every(
      (image) => image.complete && image.naturalWidth > 0 && image.naturalHeight > 0,
    ),
    undefined,
    { timeout: timeoutMs },
  );

  const imageCount = await page.evaluate(() => document.images.length);
  await page.evaluate(async () => {
    await Promise.all(Array.from(document.images).map((image) => image.decode()));
  });
  const layoutSamples = await waitForLayoutStability(
    page,
    options.sectionSelectors,
    options.practiceItemSelector,
    options.practiceIconSelector,
    timeoutMs,
    requiredStableFrames,
  );

  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'auto' }));
  await page.waitForFunction(() => window.scrollY <= 1, undefined, { timeout: timeoutMs });

  return {
    fontsReady: true,
    fonts,
    imageCount,
    scrollPositions,
    layoutSamples,
    finalScrollY: await page.evaluate(() => window.scrollY),
  };
}
