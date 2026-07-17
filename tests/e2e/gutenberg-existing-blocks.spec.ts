import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { isLoopbackURL } from './helpers/browser.mjs';

const blockNames = [
  'goetz/hero',
  'goetz/attorney-card',
  'goetz/cta',
  'goetz/faq-list',
  'goetz/resource-links',
] as const;

type BlockName = (typeof blockNames)[number];

interface TemporaryDraft {
  id: number;
  title: string;
}

interface TemporaryImage {
  id: number;
  title: string;
  sourceUrl: string;
}

interface CreatedFixtures {
  draft?: TemporaryDraft;
  image?: TemporaryImage;
}

interface SavedBlock {
  name: string;
  attributes: Record<string, unknown>;
  isValid: boolean;
}

const transparentPngBase64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

function requireLocalAuthenticatedProject(testInfo: TestInfo): string {
  const configuredBaseURL = testInfo.project.use.baseURL;

  test.skip(
    typeof configuredBaseURL !== 'string' || !isLoopbackURL(configuredBaseURL),
    'Gutenberg mutation coverage is local-only, including when remote authentication is opted in.',
  );

  if (typeof configuredBaseURL !== 'string') {
    throw new Error('Authenticated Gutenberg coverage requires a configured base URL.');
  }

  return configuredBaseURL;
}

async function waitForEditor(page: Page): Promise<void> {
  await page.waitForFunction((expectedNames) => {
    const wordpress = (globalThis as any).wp;
    return Boolean(
      wordpress?.apiFetch &&
      wordpress?.blocks &&
      wordpress?.data &&
      expectedNames.every((name: string) => wordpress.blocks.getBlockType(name)),
    );
  }, blockNames);
}

async function dismissWelcomeGuide(page: Page): Promise<void> {
  const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
  if (await welcome.isVisible()) {
    await welcome.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(welcome).toBeHidden();
  }
}

async function createTemporaryDraft(page: Page, title: string): Promise<TemporaryDraft> {
  await page.goto('/wp-admin/post-new.php?post_type=page', { waitUntil: 'domcontentloaded' });
  await waitForEditor(page);
  await dismissWelcomeGuide(page);

  const id = await page.evaluate(async (draftTitle) => {
    const editor = (globalThis as any).wp.data.dispatch('core/editor');
    editor.editPost({ title: draftTitle, status: 'draft' });
    await editor.savePost();
    return Number((globalThis as any).wp.data.select('core/editor').getCurrentPostId());
  }, title);

  if (!Number.isInteger(id) || id <= 0) {
    throw new Error('WordPress did not return a valid temporary Gutenberg draft ID.');
  }

  return { id, title };
}

async function createTemporaryImage(
  page: Page,
  uniqueSuffix: string,
): Promise<TemporaryImage> {
  const title = `Goetz Gutenberg E2E image ${uniqueSuffix}`;
  const filename = `goetz-gutenberg-e2e-${uniqueSuffix}.png`;
  const image = await page.evaluate(async ({ base64, filename: uploadName, title: uploadTitle }) => {
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
  }, { base64: transparentPngBase64, filename, title });

  const id = Number(image?.id);
  const sourceUrl = String(image?.source_url || '');
  if (!Number.isInteger(id) || id <= 0 || !sourceUrl) {
    throw new Error('WordPress did not return a valid temporary Gutenberg image attachment.');
  }

  return { id, title, sourceUrl };
}

async function ensureApiPage(page: Page): Promise<void> {
  if (page.isClosed()) {
    throw new Error('Cannot clean Gutenberg fixtures from a closed browser page.');
  }

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.apiFetch));
}

async function hardDeleteRestEntity(
  page: Page,
  restBase: 'pages' | 'media',
  id: number,
): Promise<void> {
  await page.evaluate(async ({ entityBase, entityId }) => {
    try {
      await (globalThis as any).wp.apiFetch({
        path: `/wp/v2/${entityBase}/${entityId}?force=true`,
        method: 'DELETE',
      });
    } catch (error) {
      const code = (error as { code?: string })?.code;
      if (code !== 'rest_post_invalid_id') {
        throw error;
      }
    }
  }, { entityBase: restBase, entityId: id });
}

async function expectRestEntityDeleted(
  page: Page,
  restBase: 'pages' | 'media',
  id: number,
): Promise<void> {
  const result = await page.evaluate(async ({ entityBase, entityId }) => {
    try {
      await (globalThis as any).wp.apiFetch({ path: `/wp/v2/${entityBase}/${entityId}` });
      return { deleted: false, code: '' };
    } catch (error) {
      return {
        deleted: (error as { code?: string })?.code === 'rest_post_invalid_id',
        code: String((error as { code?: string })?.code || ''),
      };
    }
  }, { entityBase: restBase, entityId: id });

  expect(result, `${restBase} ${id} must be hard-deleted`).toEqual({
    deleted: true,
    code: 'rest_post_invalid_id',
  });
}

async function cleanupFixtures(
  authenticatedPage: Page,
  baseURL: string,
  fixtures: CreatedFixtures,
): Promise<void> {
  if (!isLoopbackURL(baseURL)) {
    throw new Error('Refusing to clean Gutenberg fixtures on a non-local WordPress origin.');
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

    if (fixtures.image) {
      try {
        await hardDeleteRestEntity(cleanupPage, 'media', fixtures.image.id);
        await expectRestEntityDeleted(cleanupPage, 'media', fixtures.image.id);
        const fileResponse = await cleanupPage.goto(fixtures.image.sourceUrl, {
          waitUntil: 'commit',
        });
        expect(
          fileResponse?.status(),
          'deleting the attachment must delete its upload file',
        ).toBe(404);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }
  } finally {
    await cleanupPage.close();
  }

  if (cleanupErrors.length > 0) {
    throw new AggregateError(cleanupErrors, 'Temporary Gutenberg fixture cleanup failed.');
  }
}

async function resetToStableBlocks(page: Page): Promise<void> {
  await page.evaluate((names) => {
    const wordpress = (globalThis as any).wp;
    const initialAttributes: Record<string, Record<string, unknown>> = {
      'goetz/hero': {
        eyebrow: 'Initial hero eyebrow',
        heading: 'Initial hero heading',
        content: 'Initial hero content',
        imageUrl: '',
        imageAlt: '',
        imageId: 0,
        buttonText: 'Initial hero button',
        buttonUrl: '/initial-hero/',
        buttonNewTab: false,
      },
      'goetz/attorney-card': {
        name: 'Initial attorney',
        role: 'Initial role',
        bio: 'Initial attorney biography',
        email: 'initial-attorney@example.test',
        imageUrl: '',
        imageAlt: '',
        imageId: 0,
        profileUrl: '/initial-attorney/',
        profileNewTab: false,
      },
      'goetz/cta': {
        eyebrow: 'Initial CTA eyebrow',
        heading: 'Initial CTA heading',
        buttonText: 'Initial CTA button',
        buttonUrl: '/initial-cta/',
        buttonNewTab: false,
        backgroundImageId: 0,
        backgroundImageUrl: '',
      },
      'goetz/faq-list': {
        items: [
          { question: 'Initial FAQ one?', answer: 'Initial FAQ one answer.' },
          { question: 'Initial FAQ two?', answer: 'Initial FAQ two answer.' },
        ],
      },
      'goetz/resource-links': {
        imageId: 0,
        imageUrl: '',
        imageAlt: '',
        groups: [
          {
            heading: 'Initial resources one',
            links: [
              { label: 'Initial link one', url: 'https://example.test/initial-one', newTab: false },
              { label: 'Initial link two', url: 'https://example.test/initial-two', newTab: true },
            ],
          },
          {
            heading: 'Initial resources two',
            links: [
              { label: 'Initial link three', url: 'https://example.test/initial-three', newTab: false },
            ],
          },
        ],
      },
    };

    const blocks = names.map((name: string) => wordpress.blocks.createBlock(
      name,
      initialAttributes[name],
    ));
    wordpress.data.dispatch('core/block-editor').resetBlocks(blocks);
  }, blockNames);

  await expect.poll(() => readSavedBlocks(page)).toHaveLength(blockNames.length);
}

async function editorBlock(page: Page, name: BlockName): Promise<Locator> {
  const iframe = page.locator('iframe[name="editor-canvas"]');
  if (await iframe.count()) {
    const block = iframe.contentFrame().locator(`[data-type="${name}"]`);
    await expect(block).toBeVisible();
    return block;
  }

  const block = page.locator(`[data-type="${name}"]`);
  await expect(block).toBeVisible();
  return block;
}

async function chooseImage(page: Page, block: Locator, image: TemporaryImage): Promise<void> {
  const selectButtonName = /^Select .+ image$/;
  const inlineButton = block.getByRole('button', { name: selectButtonName });
  const inspectorButton = page.getByRole('button', { name: selectButtonName });
  await expect.poll(async () => (
    await inlineButton.count() + await inspectorButton.count()
  )).toBeGreaterThan(0);
  const selectButton = await inlineButton.count() > 0 ? inlineButton : inspectorButton;
  await selectButton.click();

  const mediaDialog = page.getByRole('dialog').filter({
    has: page.getByText(/Media Library|Upload files/i),
  });
  await expect(mediaDialog).toBeVisible();
  const mediaLibraryTab = mediaDialog.getByRole('tab', {
    name: 'Media Library',
    exact: true,
  });
  if (await mediaLibraryTab.getAttribute('aria-selected') !== 'true') {
    await mediaLibraryTab.click();
  }
  await expect(mediaLibraryTab).toHaveAttribute('aria-selected', 'true');
  const attachment = mediaDialog.locator(`[data-id="${image.id}"]`);
  await expect(attachment).toBeVisible();
  await attachment.click();
  await mediaDialog.getByRole('button', { name: /^select$/i }).click();
  await expect(mediaDialog).toBeHidden();
}

async function selectBlock(page: Page, name: BlockName): Promise<void> {
  await page.evaluate((blockName) => {
    const wordpress = (globalThis as any).wp;
    const block = wordpress.data
      .select('core/block-editor')
      .getBlocks()
      .find((candidate: any) => candidate.name === blockName);
    if (!block) {
      throw new Error(`Could not select missing block ${blockName}.`);
    }
    wordpress.data.dispatch('core/block-editor').selectBlock(block.clientId);
  }, name);
}

async function labelledField(
  page: Page,
  block: Locator,
  label: string,
): Promise<Locator> {
  const inline = block.getByLabel(label, { exact: true });
  if (await inline.count() > 0) {
    return inline;
  }

  const inspector = page.getByLabel(label, { exact: true });
  await expect(inspector).toBeVisible();
  return inspector;
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

async function linkControl(
  page: Page,
  block: Locator,
  label: string,
): Promise<Locator> {
  const inlineControl = block.locator('.goetz-editor-link-control, .components-base-control').filter({
    hasText: label,
  });
  if (await inlineControl.count() > 0) {
    return inlineControl.first();
  }

  const inspectorControl = page.locator('.goetz-editor-link-control, .components-base-control').filter({
    hasText: label,
  });
  await expect(inspectorControl.first()).toBeVisible();
  return inspectorControl.first();
}

async function setLink(
  page: Page,
  block: Locator,
  label: string,
  toggleLabel: string,
  url: string,
  newTab: boolean,
): Promise<void> {
  const control = await linkControl(page, block, label);
  const toggleButton = control.getByRole('button', { name: /^(?:Edit|Insert) link$/ });
  await expect(toggleButton).toBeVisible();
  await toggleButton.click();

  const urlInput = control.getByRole('combobox', { name: 'URL', exact: true });
  await expect(urlInput).toBeVisible();
  await urlInput.fill(url);
  await control.getByRole('button', { name: 'Submit', exact: true }).click();
  await control.getByLabel(toggleLabel, { exact: true }).setChecked(newTab);
}

async function expectBlockAttributes(
  page: Page,
  name: BlockName,
  expectedAttributes: Record<string, unknown>,
): Promise<void> {
  const blocks = await readSavedBlocks(page);
  const block = blocks.find((candidate) => candidate.name === name);
  expect(block, `${name} must remain in the editor`).toBeDefined();
  expect(block?.attributes).toEqual(expect.objectContaining(expectedAttributes));
}

async function readSavedBlocks(page: Page): Promise<SavedBlock[]> {
  return page.evaluate(() => (globalThis as any).wp.data
    .select('core/block-editor')
    .getBlocks()
    .map((block: any) => ({
      name: block.name,
      attributes: block.attributes,
      isValid: block.isValid !== false,
    })));
}

async function saveEditor(page: Page): Promise<void> {
  const error = await page.evaluate(async () => {
    const wordpress = (globalThis as any).wp;
    await wordpress.data.dispatch('core/editor').savePost();
    const editor = wordpress.data.select('core/editor');
    const lastError = editor.getLastPostSavingError?.();
    return lastError ? String(lastError.message || lastError.code || lastError) : '';
  });

  expect(error, 'WordPress must save the draft without an editor error').toBe('');
  await expect.poll(() => page.evaluate(() => {
    const editor = (globalThis as any).wp.data.select('core/editor');
    return {
      dirty: editor.isEditedPostDirty(),
      saving: editor.isSavingPost(),
    };
  })).toEqual({ dirty: false, saving: false });
}

async function expectCleanValidEditor(page: Page): Promise<void> {
  const blocks = await readSavedBlocks(page);
  expect(blocks.map(({ name }) => name)).toEqual(blockNames);
  expect(blocks.every(({ isValid }) => isValid)).toBe(true);
  expect(await page.getByText(/invalid content|attempt block recovery|modified externally|updated outside of this editor/i).count()).toBe(0);
  expect(await page.evaluate(() => (
    (globalThis as any).wp.data.select('core/editor').isEditedPostDirty()
  ))).toBe(false);
}

test('existing Goetz blocks save, reload, and update through their native editors', async ({ page }, testInfo) => {
  test.setTimeout(240_000);
  page.setDefaultTimeout(8_000);
  page.setDefaultNavigationTimeout(15_000);
  const baseURL = requireLocalAuthenticatedProject(testInfo);
  const uniqueSuffix = randomUUID();
  const fixtures: CreatedFixtures = {};

  try {
    fixtures.draft = await createTemporaryDraft(
      page,
      `Goetz Gutenberg E2E ${uniqueSuffix}`,
    );
    fixtures.image = await createTemporaryImage(page, uniqueSuffix);
    await resetToStableBlocks(page);

    const hero = await editorBlock(page, 'goetz/hero');
    await selectBlock(page, 'goetz/hero');
    await fillLabelledField(page, hero, 'Hero eyebrow', 'Final hero eyebrow');
    await fillLabelledField(page, hero, 'Hero heading', 'Final hero heading');
    await fillLabelledField(page, hero, 'Hero content', 'Final hero content');
    await fillLabelledField(page, hero, 'Hero button text', 'Final hero button');
    await chooseImage(page, hero, fixtures.image);
    await fillLabelledField(page, hero, 'Hero image alt text', 'Final hero image alt');
    await setLink(
      page,
      hero,
      'Hero button link',
      'Open Hero button link in new tab',
      '/final-hero/',
      true,
    );

    const attorney = await editorBlock(page, 'goetz/attorney-card');
    await selectBlock(page, 'goetz/attorney-card');
    await fillLabelledField(page, attorney, 'Attorney name', 'Jane Final');
    await fillLabelledField(page, attorney, 'Attorney role', 'Final Partner');
    await fillLabelledField(page, attorney, 'Attorney biography', 'Final attorney biography');
    await fillLabelledField(page, attorney, 'Attorney email', 'jane.final@example.test');
    await chooseImage(page, attorney, fixtures.image);
    await fillLabelledField(page, attorney, 'Attorney image alt text', 'Final attorney image alt');
    await setLink(
      page,
      attorney,
      'Attorney profile link',
      'Open Attorney profile link in new tab',
      '/jane-final/',
      true,
    );

    const cta = await editorBlock(page, 'goetz/cta');
    await selectBlock(page, 'goetz/cta');
    await fillLabelledField(page, cta, 'CTA eyebrow', 'Final CTA eyebrow');
    await fillLabelledField(page, cta, 'CTA heading', 'Final CTA heading');
    await fillLabelledField(page, cta, 'CTA button text', 'Final CTA button');
    await chooseImage(page, cta, fixtures.image);
    await setLink(
      page,
      cta,
      'CTA button link',
      'Open CTA button link in new tab',
      '/final-consultation/',
      true,
    );

    const faq = await editorBlock(page, 'goetz/faq-list');
    await selectBlock(page, 'goetz/faq-list');
    await fillLabelledField(page, faq, 'FAQ 1 question', 'Final FAQ one?');
    await fillLabelledField(page, faq, 'FAQ 1 answer', 'Final FAQ one answer.');
    await fillLabelledField(page, faq, 'FAQ 2 question', 'Final FAQ two?');
    await fillLabelledField(page, faq, 'FAQ 2 answer', 'Final FAQ two answer.');
    await faq.getByRole('button', { name: 'Add FAQ', exact: true }).click();
    await fillLabelledField(page, faq, 'FAQ 3 question', 'Final FAQ three?');
    await fillLabelledField(page, faq, 'FAQ 3 answer', 'Final FAQ three answer.');
    await faq.getByRole('button', { name: 'Move FAQ 3 up', exact: true }).click();
    await faq.getByRole('button', { name: 'Move FAQ 1 down', exact: true }).click();
    await faq.getByRole('button', { name: 'Remove FAQ 3', exact: true }).click();
    await expect(faq.getByRole('button', { name: 'Move FAQ 1 up', exact: true })).toBeDisabled();
    await expect(faq.getByRole('button', { name: 'Move FAQ 2 down', exact: true })).toBeDisabled();

    const resources = await editorBlock(page, 'goetz/resource-links');
    await selectBlock(page, 'goetz/resource-links');
    await chooseImage(page, resources, fixtures.image);
    await fillLabelledField(
      page,
      resources,
      'Resource Links image alt text',
      'Final resources image alt',
    );
    await fillLabelledField(page, resources, 'Resource group 1 heading', 'Final courts');
    await fillLabelledField(page, resources, 'Resource group 1 link 1 label', 'Final court one');
    await setLink(
      page,
      resources,
      'Resource group 1 link 1 destination',
      'Open Resource group 1 link 1 destination in new tab',
      'https://example.test/final-court-one',
      true,
    );
    await fillLabelledField(page, resources, 'Resource group 1 link 2 label', 'Final court two');
    await setLink(
      page,
      resources,
      'Resource group 1 link 2 destination',
      'Open Resource group 1 link 2 destination in new tab',
      'https://example.test/final-court-two',
      false,
    );
    await fillLabelledField(page, resources, 'Resource group 2 heading', 'Final agencies');
    await fillLabelledField(page, resources, 'Resource group 2 link 1 label', 'Final agency one');
    await setLink(
      page,
      resources,
      'Resource group 2 link 1 destination',
      'Open Resource group 2 link 1 destination in new tab',
      'https://example.test/final-agency-one',
      false,
    );

    await resources.getByRole('button', {
      name: 'Add link to resource group 1',
      exact: true,
    }).click();
    await fillLabelledField(page, resources, 'Resource group 1 link 3 label', 'Final court three');
    await setLink(
      page,
      resources,
      'Resource group 1 link 3 destination',
      'Open Resource group 1 link 3 destination in new tab',
      'https://example.test/final-court-three',
      true,
    );
    await resources.getByRole('button', {
      name: 'Move resource group 1 link 3 up',
      exact: true,
    }).click();
    await resources.getByRole('button', {
      name: 'Move resource group 1 link 1 down',
      exact: true,
    }).click();
    await resources.getByRole('button', {
      name: 'Remove resource group 1 link 3',
      exact: true,
    }).click();

    await resources.getByRole('button', { name: 'Add resource group', exact: true }).click();
    await fillLabelledField(page, resources, 'Resource group 3 heading', 'Final bar associations');
    await resources.getByRole('button', {
      name: 'Add link to resource group 3',
      exact: true,
    }).click();
    await fillLabelledField(page, resources, 'Resource group 3 link 1 label', 'Final bar link');
    await setLink(
      page,
      resources,
      'Resource group 3 link 1 destination',
      'Open Resource group 3 link 1 destination in new tab',
      'https://example.test/final-bar',
      true,
    );
    await resources.getByRole('button', {
      name: 'Move resource group 3 up',
      exact: true,
    }).click();
    await resources.getByRole('button', {
      name: 'Move resource group 1 down',
      exact: true,
    }).click();
    await resources.getByRole('button', {
      name: 'Remove resource group 3',
      exact: true,
    }).click();
    await expect(resources.getByRole('button', {
      name: 'Move resource group 1 up',
      exact: true,
    })).toBeDisabled();
    await expect(resources.getByRole('button', {
      name: 'Move resource group 2 down',
      exact: true,
    })).toBeDisabled();

    const expectedHero = {
      eyebrow: 'Final hero eyebrow',
      heading: 'Final hero heading',
      content: 'Final hero content',
      imageId: fixtures.image.id,
      imageUrl: fixtures.image.sourceUrl,
      imageAlt: 'Final hero image alt',
      buttonText: 'Final hero button',
      buttonUrl: '/final-hero/',
      buttonNewTab: true,
    };
    const expectedAttorney = {
      name: 'Jane Final',
      role: 'Final Partner',
      bio: 'Final attorney biography',
      email: 'jane.final@example.test',
      imageId: fixtures.image.id,
      imageUrl: fixtures.image.sourceUrl,
      imageAlt: 'Final attorney image alt',
      profileUrl: '/jane-final/',
      profileNewTab: true,
    };
    const expectedCta = {
      eyebrow: 'Final CTA eyebrow',
      heading: 'Final CTA heading',
      buttonText: 'Final CTA button',
      buttonUrl: '/final-consultation/',
      buttonNewTab: true,
      backgroundImageId: fixtures.image.id,
      backgroundImageUrl: fixtures.image.sourceUrl,
    };
    const expectedFaq = {
      items: [
        { question: 'Final FAQ three?', answer: 'Final FAQ three answer.' },
        { question: 'Final FAQ one?', answer: 'Final FAQ one answer.' },
      ],
    };
    const expectedResources = {
      imageId: fixtures.image.id,
      imageUrl: fixtures.image.sourceUrl,
      imageAlt: 'Final resources image alt',
      groups: [
        {
          heading: 'Final bar associations',
          links: [{
            label: 'Final bar link',
            url: 'https://example.test/final-bar',
            newTab: true,
          }],
        },
        {
          heading: 'Final courts',
          links: [
            {
              label: 'Final court three',
              url: 'https://example.test/final-court-three',
              newTab: true,
            },
            {
              label: 'Final court one',
              url: 'https://example.test/final-court-one',
              newTab: true,
            },
          ],
        },
      ],
    };

    await expectBlockAttributes(page, 'goetz/hero', expectedHero);
    await expectBlockAttributes(page, 'goetz/attorney-card', expectedAttorney);
    await expectBlockAttributes(page, 'goetz/cta', expectedCta);
    await expectBlockAttributes(page, 'goetz/faq-list', expectedFaq);
    await expectBlockAttributes(page, 'goetz/resource-links', expectedResources);

    await saveEditor(page);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await waitForEditor(page);
    await dismissWelcomeGuide(page);
    await expectCleanValidEditor(page);
    await expectBlockAttributes(page, 'goetz/hero', expectedHero);
    await expectBlockAttributes(page, 'goetz/attorney-card', expectedAttorney);
    await expectBlockAttributes(page, 'goetz/cta', expectedCta);
    await expectBlockAttributes(page, 'goetz/faq-list', expectedFaq);
    await expectBlockAttributes(page, 'goetz/resource-links', expectedResources);

    const reloadedHero = await editorBlock(page, 'goetz/hero');
    await selectBlock(page, 'goetz/hero');
    await fillLabelledField(page, reloadedHero, 'Hero eyebrow', 'Hero eyebrow after reload');
    await saveEditor(page);
    await expectCleanValidEditor(page);
    await expectBlockAttributes(page, 'goetz/hero', {
      ...expectedHero,
      eyebrow: 'Hero eyebrow after reload',
    });
  } finally {
    await cleanupFixtures(page, baseURL, fixtures);
  }
});
