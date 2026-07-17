import {
  expect,
  test,
  type Browser,
  type Locator,
  type Page,
  type TestInfo,
} from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { isLoopbackURL } from './helpers/browser.mjs';

const welcomeBlockName = 'goetz/welcome';
const transparentPngBase64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

interface TemporaryDraft {
  id: number;
  title: string;
}

interface TemporaryImage {
  id: number;
  title: string;
  sourceUrl: string;
}

interface WelcomeFixtures {
  draft?: TemporaryDraft;
  images: TemporaryImage[];
}

interface SavedWelcomeBlock {
  name: string;
  attributes: Record<string, unknown>;
  isValid: boolean;
}

interface WelcomeEditorSettings {
  phoneLabel: string;
  phoneUrl: string;
  onlineUrl: string;
}

function requireLocalAuthenticatedProject(testInfo: TestInfo): string {
  const configuredBaseURL = testInfo.project.use.baseURL;

  test.skip(
    typeof configuredBaseURL !== 'string' || !isLoopbackURL(configuredBaseURL),
    'Welcome block mutation coverage is local-only, including with remote authentication enabled.',
  );

  if (typeof configuredBaseURL !== 'string') {
    throw new Error('Authenticated Welcome block coverage requires a configured base URL.');
  }

  return configuredBaseURL;
}

async function waitForWelcomeBlock(page: Page): Promise<void> {
  await page.waitForFunction((blockName) => {
    const wordpress = (globalThis as any).wp;
    return Boolean(
      wordpress?.apiFetch &&
      wordpress?.blocks?.getBlockType(blockName) &&
      wordpress?.data,
    );
  }, welcomeBlockName);
}

async function dismissWelcomeGuide(page: Page): Promise<void> {
  const welcomeGuide = page.getByRole('dialog', { name: 'Welcome to the editor' });
  if (await welcomeGuide.isVisible()) {
    await welcomeGuide.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(welcomeGuide).toBeHidden();
  }
}

async function createTemporaryDraft(page: Page, title: string): Promise<TemporaryDraft> {
  await page.goto('/wp-admin/post-new.php?post_type=page', {
    waitUntil: 'domcontentloaded',
  });
  await waitForWelcomeBlock(page);
  await dismissWelcomeGuide(page);

  const id = await page.evaluate(async (draftTitle) => {
    const editor = (globalThis as any).wp.data.dispatch('core/editor');
    editor.editPost({ title: draftTitle, status: 'draft' });
    await editor.savePost();
    return Number((globalThis as any).wp.data.select('core/editor').getCurrentPostId());
  }, title);

  if (!Number.isInteger(id) || id <= 0) {
    throw new Error('WordPress did not return a valid temporary Welcome draft ID.');
  }

  return { id, title };
}

async function createTemporaryImage(
  page: Page,
  uniqueSuffix: string,
  side: 'left' | 'right',
): Promise<TemporaryImage> {
  const title = `Goetz Welcome E2E ${side} image ${uniqueSuffix}`;
  const filename = `goetz-welcome-e2e-${side}-${uniqueSuffix}.png`;
  const image = await page.evaluate(
    async ({ base64, filename: uploadName, title: uploadTitle }) => {
      const binary = atob(base64);
      const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
      const form = new FormData();
      form.append('file', new File([bytes], uploadName, { type: 'image/png' }));
      form.append('title', uploadTitle);
      form.append('alt_text', `${uploadTitle} alt`);

      return (globalThis as any).wp.apiFetch({
        path: '/wp/v2/media',
        method: 'POST',
        body: form,
      });
    },
    { base64: transparentPngBase64, filename, title },
  );

  const id = Number(image?.id);
  const sourceUrl = String(image?.source_url || '');
  if (!Number.isInteger(id) || id <= 0 || !sourceUrl) {
    throw new Error(`WordPress did not return a valid temporary ${side} image.`);
  }

  return { id, title, sourceUrl };
}

async function ensureApiPage(page: Page): Promise<void> {
  if (page.isClosed()) {
    throw new Error('Cannot clean Welcome fixtures from a closed browser page.');
  }

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.apiFetch));
}

async function hardDeleteRestEntity(
  page: Page,
  restBase: 'pages' | 'media',
  id: number,
): Promise<void> {
  await page.evaluate(
    async ({ entityBase, entityId }) => {
      try {
        await (globalThis as any).wp.apiFetch({
          path: `/wp/v2/${entityBase}/${entityId}?force=true`,
          method: 'DELETE',
        });
      } catch (error) {
        if ((error as { code?: string })?.code !== 'rest_post_invalid_id') {
          throw error;
        }
      }
    },
    { entityBase: restBase, entityId: id },
  );
}

async function expectRestEntityDeleted(
  page: Page,
  restBase: 'pages' | 'media',
  id: number,
): Promise<void> {
  const result = await page.evaluate(
    async ({ entityBase, entityId }) => {
      try {
        await (globalThis as any).wp.apiFetch({
          path: `/wp/v2/${entityBase}/${entityId}`,
        });
        return { deleted: false, code: '' };
      } catch (error) {
        const code = String((error as { code?: string })?.code || '');
        return { deleted: code === 'rest_post_invalid_id', code };
      }
    },
    { entityBase: restBase, entityId: id },
  );

  expect(result, `${restBase} ${id} must be hard-deleted`).toEqual({
    deleted: true,
    code: 'rest_post_invalid_id',
  });
}

async function cleanupFixtures(
  authenticatedPage: Page,
  baseURL: string,
  fixtures: WelcomeFixtures,
): Promise<void> {
  if (!isLoopbackURL(baseURL)) {
    throw new Error('Refusing to clean Welcome fixtures on a non-local WordPress origin.');
  }

  const cleanupPage = await authenticatedPage.context().newPage();
  cleanupPage.setDefaultTimeout(8_000);
  cleanupPage.setDefaultNavigationTimeout(15_000);
  const cleanupErrors: unknown[] = [];

  try {
    await ensureApiPage(cleanupPage);

    if (fixtures.draft) {
      try {
        await hardDeleteRestEntity(cleanupPage, 'pages', fixtures.draft.id);
        await expectRestEntityDeleted(cleanupPage, 'pages', fixtures.draft.id);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }

    for (const image of fixtures.images) {
      try {
        await hardDeleteRestEntity(cleanupPage, 'media', image.id);
        await expectRestEntityDeleted(cleanupPage, 'media', image.id);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }

    for (const image of fixtures.images) {
      try {
        const fileResponse = await cleanupPage.goto(image.sourceUrl, {
          waitUntil: 'commit',
        });
        expect(
          fileResponse?.status(),
          `deleting attachment ${image.id} must delete its upload file`,
        ).toBe(404);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }
  } finally {
    await cleanupPage.close();
  }

  if (cleanupErrors.length > 0) {
    throw new AggregateError(cleanupErrors, 'Temporary Welcome fixture cleanup failed.');
  }
}

async function resetToWelcomeBlock(page: Page): Promise<void> {
  await page.evaluate((blockName) => {
    const wordpress = (globalThis as any).wp;
    const block = wordpress.blocks.createBlock(blockName);
    wordpress.data.dispatch('core/block-editor').resetBlocks([block]);
  }, welcomeBlockName);

  await expect.poll(() => readWelcomeBlock(page)).toMatchObject({
    name: welcomeBlockName,
    isValid: true,
  });
}

async function readWelcomeEditorSettings(page: Page): Promise<WelcomeEditorSettings | null> {
  return page.evaluate(() => {
    const settings = (globalThis as any).goetzSiteEditorSettings;
    if (!settings || typeof settings !== 'object') {
      return null;
    }

    return {
      phoneLabel: String(settings.phoneLabel || ''),
      phoneUrl: String(settings.phoneUrl || ''),
      onlineUrl: String(settings.onlineUrl || ''),
    };
  });
}

async function readWelcomeBlock(page: Page): Promise<SavedWelcomeBlock | null> {
  return page.evaluate((blockName) => {
    const block = (globalThis as any).wp.data
      .select('core/block-editor')
      .getBlocks()
      .find((candidate: any) => candidate.name === blockName);

    return block
      ? {
          name: block.name,
          attributes: block.attributes,
          isValid: block.isValid !== false,
        }
      : null;
  }, welcomeBlockName);
}

async function selectWelcomeBlock(page: Page): Promise<void> {
  await page.evaluate((blockName) => {
    const wordpress = (globalThis as any).wp;
    const block = wordpress.data
      .select('core/block-editor')
      .getBlocks()
      .find((candidate: any) => candidate.name === blockName);
    if (!block) {
      throw new Error('Could not select the missing Welcome block.');
    }
    wordpress.data.dispatch('core/block-editor').selectBlock(block.clientId);
  }, welcomeBlockName);
  await ensureSettingsSidebarOpen(page);
}

async function ensureSettingsSidebarOpen(page: Page): Promise<void> {
  const settingsButton = page.getByRole('button', { name: 'Settings', exact: true });
  await expect(settingsButton).toBeVisible();

  if ((await settingsButton.getAttribute('aria-expanded')) !== 'true') {
    await settingsButton.click();
  }

  await expect(settingsButton).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('region', { name: 'Editor settings' })).toBeVisible();
}

async function ensureSettingsSidebarClosed(page: Page): Promise<void> {
  const settingsButton = page.getByRole('button', { name: 'Settings', exact: true });
  await expect(settingsButton).toBeVisible();

  if ((await settingsButton.getAttribute('aria-expanded')) === 'true') {
    await settingsButton.click();
  }

  await expect(settingsButton).not.toHaveAttribute('aria-expanded', 'true');
}

async function welcomeEditorBlock(page: Page): Promise<Locator> {
  const iframe = page.locator('iframe[name="editor-canvas"]');
  if (await iframe.count()) {
    const block = iframe.contentFrame().locator(`[data-type="${welcomeBlockName}"]`);
    await expect(block).toBeVisible();
    return block;
  }

  const block = page.locator(`[data-type="${welcomeBlockName}"]`);
  await expect(block).toBeVisible();
  return block;
}

async function labelledField(
  page: Page,
  block: Locator,
  label: string,
): Promise<Locator> {
  const inlineField = block.getByLabel(label, { exact: true });
  if (await inlineField.count()) {
    return inlineField;
  }

  const inspectorField = page.getByLabel(label, { exact: true });
  await expect(inspectorField).toBeVisible();
  return inspectorField;
}

async function fillLabelledField(
  page: Page,
  block: Locator,
  label: string,
  value: string,
): Promise<void> {
  const field = await labelledField(page, block, label);
  await expect(field).toBeVisible();
  await field.fill(value);
}

async function chooseImage(
  page: Page,
  block: Locator,
  controlLabel: string,
  image: TemporaryImage,
): Promise<void> {
  const buttonName = `Select ${controlLabel}`;
  const inlineButton = block.getByRole('button', { name: buttonName, exact: true });
  const inspectorButton = page.getByRole('button', { name: buttonName, exact: true });
  await expect
    .poll(async () => (await inlineButton.count()) + (await inspectorButton.count()))
    .toBeGreaterThan(0);
  const selectButton = (await inlineButton.count()) > 0 ? inlineButton : inspectorButton;
  await selectButton.click();

  const mediaDialog = page.getByRole('dialog').filter({
    has: page.getByText(/Media Library|Upload files/i),
  });
  await expect(mediaDialog).toBeVisible();
  const mediaLibraryTab = mediaDialog.getByRole('tab', {
    name: 'Media Library',
    exact: true,
  });
  if ((await mediaLibraryTab.getAttribute('aria-selected')) !== 'true') {
    await mediaLibraryTab.click();
  }
  await expect(mediaLibraryTab).toHaveAttribute('aria-selected', 'true');

  const attachment = mediaDialog.locator(`[data-id="${image.id}"]`);
  await expect(attachment).toBeVisible();
  await attachment.click();
  await mediaDialog.getByRole('button', { name: /^select$/i }).click();
  await expect(mediaDialog).toBeHidden();
}

async function expectWelcomeAttributes(
  page: Page,
  expectedAttributes: Record<string, unknown>,
): Promise<void> {
  const block = await readWelcomeBlock(page);
  expect(block, 'Welcome block must remain in the editor').not.toBeNull();
  expect(block?.attributes).toEqual(expect.objectContaining(expectedAttributes));
}

async function saveEditor(page: Page): Promise<void> {
  const error = await page.evaluate(async () => {
    const wordpress = (globalThis as any).wp;
    await wordpress.data.dispatch('core/editor').savePost();
    const editor = wordpress.data.select('core/editor');
    const lastError = editor.getLastPostSavingError?.();
    return lastError ? String(lastError.message || lastError.code || lastError) : '';
  });

  expect(error, 'WordPress must save the Welcome draft without an editor error').toBe('');
  await expect
    .poll(() =>
      page.evaluate(() => {
        const editor = (globalThis as any).wp.data.select('core/editor');
        return {
          dirty: editor.isEditedPostDirty(),
          saving: editor.isSavingPost(),
        };
      }),
    )
    .toEqual({ dirty: false, saving: false });
}

async function expectCleanValidEditor(page: Page): Promise<void> {
  const block = await readWelcomeBlock(page);
  expect(block).toMatchObject({ name: welcomeBlockName, isValid: true });
  expect(
    await page
      .getByText(
        /invalid content|attempt block recovery|modified externally|updated outside of this editor/i,
      )
      .count(),
  ).toBe(0);
  expect(
    await page.evaluate(() =>
      (globalThis as any).wp.data.select('core/editor').isEditedPostDirty(),
    ),
  ).toBe(false);
}

async function resolveLocalPreviewURL(
  page: Page,
  baseURL: string,
  draftId: number,
): Promise<string> {
  const localOrigin = new URL(baseURL).origin;
  if (!isLoopbackURL(localOrigin)) {
    throw new Error('Refusing to open a Welcome preview on a non-local origin.');
  }

  const editorPreview = await page.evaluate(() => {
    const editor = (globalThis as any).wp.data.select('core/editor');
    return String(editor.getEditedPostPreviewLink?.() || '');
  });
  const previewURL = new URL(
    editorPreview || `/?page_id=${draftId}&preview=true`,
    baseURL,
  );

  if (previewURL.origin !== localOrigin || !isLoopbackURL(previewURL)) {
    throw new Error('Welcome preview escaped the exact local WordPress origin.');
  }

  return previewURL.toString();
}

async function expectResponsiveFrontend(
  page: Page,
  browser: Browser,
  baseURL: string,
  draftId: number,
): Promise<void> {
  const previewURL = await resolveLocalPreviewURL(page, baseURL, draftId);
  const previewPage = await page.context().newPage();
  previewPage.setDefaultTimeout(8_000);
  previewPage.setDefaultNavigationTimeout(15_000);

  try {
    await previewPage.setViewportSize({ width: 1440, height: 1100 });
    const response = await previewPage.goto(previewURL, {
      waitUntil: 'domcontentloaded',
    });
    expect(response?.status(), 'Welcome draft preview must load successfully').toBeLessThan(400);

    const section = previewPage.locator(
      'section.wp-block-goetz-welcome.goetz-intro-section',
    );
    const layout = section.locator(':scope > .goetz-intro');
    const leftMedia = layout.locator(':scope > .goetz-intro__media--left');
    const content = layout.locator(':scope > .goetz-intro__content');
    const rightMedia = layout.locator(':scope > .goetz-intro__media--right');

    await expect(section).toBeVisible();
    await expect(section).toHaveCSS('visibility', 'visible');
    await expect(section).toHaveCSS('opacity', '1');
    await expect(section.getByRole('heading', {
      level: 2,
      name: 'Final Welcome heading',
      exact: true,
    })).toBeVisible();
    await expect(section.getByRole('link', {
      name: '(239) 555-0199',
      exact: true,
    })).toHaveAttribute('href', 'tel:+12395550199');
    await expect(section.getByRole('link', {
      name: 'Final online label',
      exact: true,
    })).toHaveAttribute('href', /\/final-welcome-contact\/$/);
    await expect(section).toContainText('Welcome join after reload');
    await expect(section.getByRole('img', {
      name: 'Final left Welcome image',
      exact: true,
    })).toBeVisible();
    await expect(section.getByRole('img', {
      name: 'Final right Welcome image',
      exact: true,
    })).toBeVisible();
    const decorativeIcon = section.locator('img.goetz-intro__icon');
    await expect(decorativeIcon).toBeVisible();
    await expect(decorativeIcon).toHaveAttribute('alt', '');
    await expect(decorativeIcon).toHaveAttribute('aria-hidden', 'true');
    expect(
      await layout.evaluate((element) =>
        Array.from(element.children).map((child) => child.className),
      ),
      'Welcome frontend child order must stay left media, content, right media',
    ).toEqual([
      'goetz-intro__media goetz-intro__media--left',
      'goetz-intro__content',
      'goetz-intro__media goetz-intro__media--right',
    ]);

    const desktopBoxes = await Promise.all([
      leftMedia.boundingBox(),
      content.boundingBox(),
      rightMedia.boundingBox(),
    ]);
    expect(desktopBoxes.every(Boolean), 'Welcome desktop columns need measurable boxes').toBe(
      true,
    );
    const [desktopLeft, desktopContent, desktopRight] = desktopBoxes;
    expect(desktopLeft!.x).toBeLessThan(desktopContent!.x);
    expect(desktopContent!.x).toBeLessThan(desktopRight!.x);
    expect(
      await section.evaluate((element) => element.scrollWidth <= element.clientWidth + 1),
      'Welcome frontend must not overflow at desktop width',
    ).toBe(true);

    await previewPage.emulateMedia({ reducedMotion: 'reduce' });
    await previewPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(section).toBeVisible();
    await expect(section).toHaveCSS('visibility', 'visible');
    await expect(section).toHaveCSS('opacity', '1');

    await previewPage.setViewportSize({ width: 390, height: 844 });
    await expect(section).toBeVisible();
    const mobileBoxes = await Promise.all([
      leftMedia.boundingBox(),
      content.boundingBox(),
      rightMedia.boundingBox(),
    ]);
    expect(mobileBoxes.every(Boolean), 'Welcome mobile rows need measurable boxes').toBe(true);
    const [mobileLeft, mobileContent, mobileRight] = mobileBoxes;
    expect(mobileLeft!.y).toBeLessThan(mobileContent!.y);
    expect(mobileContent!.y).toBeLessThan(mobileRight!.y);
    expect(
      await previewPage.evaluate(
        () => document.documentElement.scrollWidth <= globalThis.innerWidth + 1,
      ),
      'Welcome frontend must not create horizontal mobile overflow',
    ).toBe(true);

    const noJavaScriptContext = await browser.newContext({
      baseURL,
      javaScriptEnabled: false,
      storageState: await page.context().storageState(),
      viewport: { width: 390, height: 844 },
    });
    try {
      const noJavaScriptPage = await noJavaScriptContext.newPage();
      const noJavaScriptResponse = await noJavaScriptPage.goto(previewURL, {
        waitUntil: 'domcontentloaded',
      });
      expect(
        noJavaScriptResponse?.status(),
        'Welcome no-JavaScript preview must load successfully',
      ).toBeLessThan(400);
      const noJavaScriptSection = noJavaScriptPage.locator(
        'section.wp-block-goetz-welcome.goetz-intro-section',
      );
      await expect(noJavaScriptSection).toBeVisible();
      await expect(noJavaScriptSection).toHaveCSS('visibility', 'visible');
      await expect(noJavaScriptSection).toHaveCSS('opacity', '1');
    } finally {
      await noJavaScriptContext.close();
    }
  } finally {
    await previewPage.close();
  }
}

test('Welcome block saves, reloads, updates, and stays visible responsively', async ({
  browser,
  page,
}, testInfo) => {
  test.setTimeout(240_000);
  page.setDefaultTimeout(8_000);
  page.setDefaultNavigationTimeout(15_000);
  const baseURL = requireLocalAuthenticatedProject(testInfo);
  const uniqueSuffix = randomUUID();
  const fixtures: WelcomeFixtures = { images: [] };

  try {
    fixtures.draft = await createTemporaryDraft(
      page,
      `Goetz Welcome E2E ${uniqueSuffix}`,
    );
    fixtures.images.push(
      await createTemporaryImage(page, uniqueSuffix, 'left'),
      await createTemporaryImage(page, uniqueSuffix, 'right'),
    );
    await resetToWelcomeBlock(page);
    await ensureSettingsSidebarClosed(page);
    await selectWelcomeBlock(page);

    const block = await welcomeEditorBlock(page);
    const editorSettings = await readWelcomeEditorSettings(page);
    expect(editorSettings).toEqual({
      phoneLabel: expect.any(String),
      phoneUrl: expect.stringMatching(/^tel:\+[1-9]\d{7,14}$/),
      onlineUrl: '/contact/',
    });
    expect(editorSettings?.phoneLabel).not.toBe('');
    await expectWelcomeAttributes(page, {
      phoneLabel: '',
      phoneUrl: '',
      onlineLabel: 'online',
      onlineUrl: '',
    });
    await expect(await labelledField(page, block, 'Welcome phone label')).toHaveText(
      editorSettings!.phoneLabel,
    );
    await expect(await labelledField(page, block, 'Welcome phone URL')).toHaveValue(
      editorSettings!.phoneUrl,
    );
    await expect(await labelledField(page, block, 'Welcome online URL')).toHaveValue(
      editorSettings!.onlineUrl,
    );
    await expect(block.getByRole('link', {
      name: editorSettings!.phoneLabel,
      exact: true,
    })).toHaveAttribute('href', editorSettings!.phoneUrl);
    await expect(block.getByRole('link', { name: 'online', exact: true })).toHaveAttribute(
      'href',
      editorSettings!.onlineUrl,
    );
    await expectWelcomeAttributes(page, {
      phoneLabel: '',
      phoneUrl: '',
      onlineUrl: '',
    });

    await fillLabelledField(page, block, 'Welcome heading', 'Final Welcome heading');
    await fillLabelledField(
      page,
      block,
      'Welcome content prefix',
      'Final Welcome prefix',
    );
    await fillLabelledField(page, block, 'Welcome phone label', '(239) 555-0199');
    await fillLabelledField(page, block, 'Welcome phone URL', 'tel:+12395550199');
    await fillLabelledField(page, block, 'Welcome content join', 'Final Welcome join');
    await fillLabelledField(page, block, 'Welcome online label', 'Final online label');
    await fillLabelledField(page, block, 'Welcome online URL', '/final-welcome-contact/');

    await chooseImage(page, block, 'Welcome left image', fixtures.images[0]);
    await fillLabelledField(
      page,
      block,
      'Welcome left image alt text',
      'Final left Welcome image',
    );
    await chooseImage(page, block, 'Welcome right image', fixtures.images[1]);
    await fillLabelledField(
      page,
      block,
      'Welcome right image alt text',
      'Final right Welcome image',
    );

    const expectedAttributes = {
      leftImageId: fixtures.images[0].id,
      leftImageUrl: fixtures.images[0].sourceUrl,
      leftImageAlt: 'Final left Welcome image',
      rightImageId: fixtures.images[1].id,
      rightImageUrl: fixtures.images[1].sourceUrl,
      rightImageAlt: 'Final right Welcome image',
      heading: 'Final Welcome heading',
      contentPrefix: 'Final Welcome prefix',
      phoneLabel: '(239) 555-0199',
      phoneUrl: 'tel:+12395550199',
      contentJoin: 'Final Welcome join',
      onlineLabel: 'Final online label',
      onlineUrl: '/final-welcome-contact/',
    };

    await expectWelcomeAttributes(page, expectedAttributes);
    await expect(block).toBeVisible();
    await expect(block).toHaveCSS('visibility', 'visible');
    await expect(block).toHaveCSS('opacity', '1');
    expect(
      await block.evaluate((element) => element.scrollWidth <= element.clientWidth + 1),
      'Welcome editor preview must not overflow at desktop width',
    ).toBe(true);

    await saveEditor(page);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForWelcomeBlock(page);
    await dismissWelcomeGuide(page);
    await expectCleanValidEditor(page);
    await expectWelcomeAttributes(page, expectedAttributes);

    await selectWelcomeBlock(page);
    const reloadedBlock = await welcomeEditorBlock(page);
    await fillLabelledField(
      page,
      reloadedBlock,
      'Welcome content join',
      'Welcome join after reload',
    );
    await saveEditor(page);
    await expectCleanValidEditor(page);
    await expectWelcomeAttributes(page, {
      ...expectedAttributes,
      contentJoin: 'Welcome join after reload',
    });

    await expectResponsiveFrontend(page, browser, baseURL, fixtures.draft.id);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(reloadedBlock).toBeVisible();
    await expect(reloadedBlock).toHaveCSS('visibility', 'visible');
    await expect(reloadedBlock).toHaveCSS('opacity', '1');
    expect(
      await reloadedBlock.evaluate(
        (element) => element.scrollWidth <= element.clientWidth + 1,
      ),
      'Welcome editor preview must not overflow at mobile width',
    ).toBe(true);
  } finally {
    await cleanupFixtures(page, baseURL, fixtures);
  }
});
