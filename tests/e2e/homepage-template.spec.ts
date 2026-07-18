import { createHash, randomUUID } from 'node:crypto';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { isLoopbackURL } from './helpers/browser.mjs';

const rootNames = [
  'goetz/hero',
  'goetz/welcome',
  'goetz/practice-areas',
  'goetz/attorney-grid',
  'goetz/cta',
] as const;

const practiceLabels = [
  'Corporate',
  'Construction',
  'Real Estate',
  'Probate',
  'Criminal',
  'Bankruptcy',
  'Appeals',
] as const;

const invalidBlockWarning =
  /unexpected or invalid content|attempt block recovery|modified externally|updated outside of this editor/i;

interface FrontPageSettings {
  show_on_front: string;
  page_on_front: number;
}

interface PageSnapshot {
  id: number;
  slug: string;
  status: string;
  modifiedGmt: string;
  content: string;
  contentSha256: string;
}

interface TemporaryPages {
  parentId?: number;
  homeId?: number;
}

interface EditMarkers {
  heroHeading: string;
  heroContent: string;
  heroImageAlt: string;
  practiceLabel: string;
  attorneyName: string;
}

function requireLocalBaseURL(testInfo: TestInfo): string {
  const configured = testInfo.project.use.baseURL;
  test.skip(
    testInfo.project.metadata.scope !== 'auth'
      || typeof configured !== 'string'
      || !isLoopbackURL(configured),
    'Homepage template mutation coverage is authenticated and local-only.',
  );
  if (typeof configured !== 'string') {
    throw new Error('Homepage template coverage requires a configured base URL.');
  }
  return configured;
}

function sha256(value: string): string {
  return createHash('sha256').update(value).digest('hex');
}

async function openAdminApi(page: Page): Promise<void> {
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.apiFetch));
}

async function frontPageSettings(page: Page): Promise<FrontPageSettings> {
  return page.evaluate(async () => {
    const settings = await (globalThis as any).wp.apiFetch({
      path: '/wp/v2/settings?context=edit',
    });
    return {
      show_on_front: String(settings.show_on_front || 'posts'),
      page_on_front: Number(settings.page_on_front || 0),
    };
  });
}

async function updateFrontPageSettings(
  page: Page,
  settings: FrontPageSettings,
): Promise<void> {
  await page.evaluate(async (next) => {
    await (globalThis as any).wp.apiFetch({
      path: '/wp/v2/settings',
      method: 'POST',
      data: next,
    });
  }, settings);
}

async function pageSnapshot(page: Page, id: number): Promise<PageSnapshot> {
  const snapshot = await page.evaluate(async (pageId) => {
    const entity = await (globalThis as any).wp.apiFetch({
      path: `/wp/v2/pages/${pageId}?context=edit`,
    });
    return {
      id: Number(entity?.id || 0),
      slug: String(entity?.slug || ''),
      status: String(entity?.status || ''),
      modifiedGmt: String(entity?.modified_gmt || ''),
      content: String(entity?.content?.raw || ''),
    };
  }, id);

  if (snapshot.id !== id || !snapshot.content) {
    throw new Error(`WordPress did not return the exact editable page ${id}.`);
  }

  return {
    ...snapshot,
    contentSha256: sha256(snapshot.content),
  };
}

async function createDisposableClone(
  page: Page,
  originalContent: string,
  temporary: TemporaryPages,
): Promise<void> {
  const suffix = randomUUID();
  const parent = await page.evaluate(async ({ slug, title }) => (
    (globalThis as any).wp.apiFetch({
      path: '/wp/v2/pages',
      method: 'POST',
      data: { slug, status: 'draft', title },
    })
  ), {
    slug: `goetz-homepage-template-parent-${suffix}`,
    title: `Goetz homepage template parent ${suffix}`,
  });
  const parentId = Number(parent?.id);
  if (!Number.isInteger(parentId) || parentId <= 0) {
    throw new Error('WordPress did not create the disposable parent page.');
  }
  temporary.parentId = parentId;

  const home = await page.evaluate(
    async ({ content, parent: parentPageId, title }) => (
      (globalThis as any).wp.apiFetch({
        path: '/wp/v2/pages',
        method: 'POST',
        data: {
          content,
          parent: parentPageId,
          slug: 'home',
          status: 'draft',
          title,
        },
      })
    ),
    {
      content: originalContent,
      parent: parentId,
      title: `Goetz homepage template ${suffix}`,
    },
  );
  const homeId = Number(home?.id);
  if (!Number.isInteger(homeId) || homeId <= 0 || String(home?.slug) !== 'home') {
    throw new Error('WordPress did not create an exact disposable home-slug page.');
  }
  temporary.homeId = homeId;
}

async function hardDeletePage(page: Page, id: number): Promise<void> {
  await page.evaluate(async (pageId) => {
    try {
      await (globalThis as any).wp.apiFetch({
        path: `/wp/v2/pages/${pageId}?force=true`,
        method: 'DELETE',
      });
    } catch (error) {
      if ((error as { code?: string })?.code !== 'rest_post_invalid_id') throw error;
    }
  }, id);

  const deleted = await page.evaluate(async (pageId) => {
    try {
      await (globalThis as any).wp.apiFetch({ path: `/wp/v2/pages/${pageId}` });
      return false;
    } catch (error) {
      return (error as { code?: string })?.code === 'rest_post_invalid_id';
    }
  }, id);
  expect(deleted, `temporary page ${id} must be hard-deleted`).toBe(true);
}

async function cleanup(
  page: Page,
  baseURL: string,
  originalSettings: FrontPageSettings | undefined,
  production: PageSnapshot | undefined,
  temporary: TemporaryPages,
): Promise<void> {
  if (!isLoopbackURL(baseURL)) {
    throw new Error('Refusing homepage template cleanup on a non-local origin.');
  }

  const cleanupPage = await page.context().newPage();
  cleanupPage.setDefaultTimeout(10_000);
  cleanupPage.setDefaultNavigationTimeout(20_000);
  const cleanupErrors: unknown[] = [];

  try {
    await openAdminApi(cleanupPage);

    if (originalSettings) {
      try {
        await updateFrontPageSettings(cleanupPage, originalSettings);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }

    for (const id of [temporary.homeId, temporary.parentId]) {
      if (!id) continue;
      try {
        await hardDeletePage(cleanupPage, id);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }

    if (originalSettings) {
      try {
        expect(await frontPageSettings(cleanupPage)).toEqual(originalSettings);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }

    if (production) {
      try {
        expect(await pageSnapshot(cleanupPage, production.id)).toEqual(production);
      } catch (error) {
        cleanupErrors.push(error);
      }
    }
  } finally {
    await cleanupPage.close();
  }

  if (cleanupErrors.length > 0) {
    throw new AggregateError(cleanupErrors, 'Homepage template cleanup was not exact.');
  }
}

class HomepageEditorPage {
  public constructor(private readonly page: Page) {}

  public async open(postId: number): Promise<void> {
    await this.page.goto(`/wp-admin/post.php?post=${postId}&action=edit`, {
      waitUntil: 'domcontentloaded',
    });
    await this.page.waitForFunction((names) => {
      const wordpress = (globalThis as any).wp;
      return Boolean(
        wordpress?.apiFetch
        && wordpress?.blocks
        && wordpress?.data
        && names.every((name: string) => wordpress.blocks.getBlockType(name)),
      );
    }, rootNames);

    const dialog = this.page.getByRole('dialog', { name: 'Welcome to the editor' });
    if (await dialog.isVisible()) {
      await dialog.getByRole('button', { name: 'Close', exact: true }).click();
      await expect(dialog).toBeHidden();
    }
  }

  public async content(): Promise<string> {
    return this.page.evaluate(() => String(
      (globalThis as any).wp.data.select('core/editor').getEditedPostContent?.() || '',
    ));
  }

  public async state() {
    return this.page.evaluate((names) => {
      const wordpress = (globalThis as any).wp;
      const selector = wordpress.data.select('core/block-editor');
      const roots = selector.getBlocks();
      const practice = roots.find((block: any) => block.name === names[2]);
      const attorneys = roots.find((block: any) => block.name === names[3]);
      const hero = roots.find((block: any) => block.name === names[0]);
      const welcome = roots.find((block: any) => block.name === names[1]);
      const cta = roots.find((block: any) => block.name === names[4]);
      const rootClientId = roots.length > 0
        ? String(selector.getBlockRootClientId(roots[0].clientId) || '')
        : '';
      const canMove = (clientId: string, parentId: string): boolean => (
        typeof selector.canMoveBlocks === 'function'
          ? selector.canMoveBlocks([clientId], parentId)
          : selector.canMoveBlock(clientId, parentId)
      );
      const canRemove = (clientId: string, parentId: string): boolean => (
        typeof selector.canRemoveBlocks === 'function'
          ? selector.canRemoveBlocks([clientId], parentId)
          : selector.canRemoveBlock(clientId, parentId)
      );
      const validTree = (blocks: any[]): boolean => blocks.every(
        (block) => block.isValid !== false && validTree(block.innerBlocks || []),
      );
      const allowedBlockNames = (allowed: any): string[] | null => {
        if (! Array.isArray(allowed)) {
          return null;
        }

        return allowed.map((blockType: any) => (
          typeof blockType === 'string' ? blockType : String(blockType?.name || '')
        ));
      };
      const images = [
        [hero?.attributes?.imageId, hero?.attributes?.imageUrl],
        [welcome?.attributes?.leftImageId, welcome?.attributes?.leftImageUrl],
        [welcome?.attributes?.rightImageId, welcome?.attributes?.rightImageUrl],
        [practice?.attributes?.backgroundImageId, practice?.attributes?.backgroundImageUrl],
        [practice?.attributes?.scaleImageId, practice?.attributes?.scaleImageUrl],
        ...((attorneys?.innerBlocks || []).map((block: any) => [
          block.attributes?.imageId,
          block.attributes?.imageUrl,
        ])),
        [cta?.attributes?.backgroundImageId, cta?.attributes?.backgroundImageUrl],
      ];

      return {
        names: roots.map((block: any) => block.name),
        rootIds: roots.map((block: any) => block.clientId),
        locks: roots.map((block: any) => block.attributes.lock ?? null),
        valid: validTree(roots),
        templateLock: selector.getSettings().templateLock,
        canInsertRoot: selector.canInsertBlockType('core/paragraph', rootClientId),
        canMoveRoots: roots.map((block: any) => canMove(block.clientId, rootClientId)),
        canRemoveRoots: roots.map((block: any) => canRemove(block.clientId, rootClientId)),
        practiceId: String(practice?.clientId || ''),
        practiceNames: (practice?.innerBlocks || []).map((block: any) => block.name),
        practiceLabels: (practice?.innerBlocks || []).map(
          (block: any) => String(block.attributes?.label || ''),
        ),
        practiceLocks: (practice?.innerBlocks || []).map(
          (block: any) => block.attributes.lock ?? null,
        ),
        practiceAllowed: practice
          ? allowedBlockNames(selector.getAllowedBlocks(practice.clientId))
          : null,
        canInsertPractice: Boolean(practice) && selector.canInsertBlockType(
          'goetz/practice-area-item',
          practice.clientId,
        ),
        canMovePracticeChild: Boolean(practice?.innerBlocks?.[0]) && canMove(
          practice.innerBlocks[0].clientId,
          practice.clientId,
        ),
        canRemovePracticeChild: Boolean(practice?.innerBlocks?.[0]) && canRemove(
          practice.innerBlocks[0].clientId,
          practice.clientId,
        ),
        attorneyId: String(attorneys?.clientId || ''),
        attorneyNames: (attorneys?.innerBlocks || []).map(
          (block: any) => String(block.attributes?.name || ''),
        ),
        attorneyBlockNames: (attorneys?.innerBlocks || []).map((block: any) => block.name),
        attorneyLocks: (attorneys?.innerBlocks || []).map(
          (block: any) => block.attributes.lock ?? null,
        ),
        attorneyAllowed: attorneys
          ? allowedBlockNames(selector.getAllowedBlocks(attorneys.clientId))
          : null,
        canInsertAttorney: Boolean(attorneys) && selector.canInsertBlockType(
          'goetz/attorney-card',
          attorneys.clientId,
        ),
        canMoveAttorneyChild: Boolean(attorneys?.innerBlocks?.[0]) && canMove(
          attorneys.innerBlocks[0].clientId,
          attorneys.clientId,
        ),
        canRemoveAttorneyChild: Boolean(attorneys?.innerBlocks?.[0]) && canRemove(
          attorneys.innerBlocks[0].clientId,
          attorneys.clientId,
        ),
        heroAttributes: hero?.attributes || {},
        welcomeAttributes: welcome?.attributes || {},
        images: images.map(([id, url]) => ({
          id: Number(id || 0),
          url: String(url || ''),
        })),
      };
    }, rootNames);
  }

  public async exerciseLocksAndEdits(markers: EditMarkers) {
    return this.page.evaluate(({ names, values }) => {
      const wordpress = (globalThis as any).wp;
      const selector = wordpress.data.select('core/block-editor');
      const dispatch = wordpress.data.dispatch('core/block-editor');
      const before = selector.getBlocks();
      const hero = before.find((block: any) => block.name === names[0]);
      const welcome = before.find((block: any) => block.name === names[1]);
      const practice = before.find((block: any) => block.name === names[2]);
      const attorneys = before.find((block: any) => block.name === names[3]);
      const cta = before.find((block: any) => block.name === names[4]);
      const rootClientId = String(selector.getBlockRootClientId(hero.clientId) || '');

      dispatch.insertBlock(
        wordpress.blocks.createBlock('core/paragraph', { content: 'blocked root insert' }),
        undefined,
        rootClientId,
      );
      dispatch.moveBlockToPosition(
        hero.clientId,
        rootClientId,
        rootClientId,
        before.length - 1,
      );
      dispatch.removeBlock(cta.clientId);

      dispatch.updateBlockAttributes(hero.clientId, {
        heading: values.heroHeading,
        content: values.heroContent,
        imageId: Number(welcome.attributes.leftImageId),
        imageUrl: String(welcome.attributes.leftImageUrl),
        imageAlt: values.heroImageAlt,
      });

      const practiceNew = wordpress.blocks.createBlock('goetz/practice-area-item', {
        label: values.practiceLabel,
      });
      dispatch.insertBlock(practiceNew, undefined, practice.clientId);
      dispatch.moveBlockToPosition(
        practiceNew.clientId,
        practice.clientId,
        practice.clientId,
        0,
      );
      const practiceChildren = selector.getBlocks(practice.clientId);
      dispatch.removeBlock(practiceChildren[practiceChildren.length - 1].clientId);

      const attorneyNew = wordpress.blocks.createBlock('goetz/attorney-card', {
        name: values.attorneyName,
        bio: 'Editable child content.',
        profileUrl: '/integration-attorney/',
      });
      dispatch.insertBlock(attorneyNew, undefined, attorneys.clientId);
      dispatch.moveBlockToPosition(
        attorneyNew.clientId,
        attorneys.clientId,
        attorneys.clientId,
        0,
      );
      const attorneyChildren = selector.getBlocks(attorneys.clientId);
      dispatch.removeBlock(attorneyChildren[attorneyChildren.length - 1].clientId);

      const after = selector.getBlocks();
      const editedHero = after.find((block: any) => block.name === names[0]);
      return {
        rootNames: after.map((block: any) => block.name),
        rootIds: after.map((block: any) => block.clientId),
        heroAttributes: editedHero.attributes,
        practiceLabels: selector.getBlocks(practice.clientId).map(
          (block: any) => String(block.attributes.label || ''),
        ),
        attorneyNames: selector.getBlocks(attorneys.clientId).map(
          (block: any) => String(block.attributes.name || ''),
        ),
      };
    }, { names: rootNames, values: markers });
  }

  public async save(): Promise<void> {
    const error = await this.page.evaluate(async () => {
      const wordpress = (globalThis as any).wp;
      await wordpress.data.dispatch('core/editor').savePost();
      const editor = wordpress.data.select('core/editor');
      const lastError = editor.getLastPostSavingError?.();
      return lastError ? String(lastError.message || lastError.code || lastError) : '';
    });
    expect(error).toBe('');
    await expect.poll(() => this.page.evaluate(() => {
      const editor = (globalThis as any).wp.data.select('core/editor');
      return {
        dirty: editor.isEditedPostDirty(),
        saving: editor.isSavingPost(),
      };
    })).toEqual({ dirty: false, saving: false });
  }

  public async expectCleanAndValid(): Promise<void> {
    expect(await this.page.evaluate(() => (
      (globalThis as any).wp.data.select('core/editor').isEditedPostDirty()
    ))).toBe(false);
    await expect(this.page.getByText(invalidBlockWarning)).toHaveCount(0);

    const editorCanvas = this.page.locator('iframe[name="editor-canvas"]');
    if (await editorCanvas.count()) {
      await expect(editorCanvas.contentFrame().getByText(invalidBlockWarning)).toHaveCount(0);
    }
  }
}

function expectCanonicalHomepage(state: Awaited<ReturnType<HomepageEditorPage['state']>>): void {
  expect(state.names).toEqual(rootNames);
  expect(state.locks).toEqual(rootNames.map(() => ({ move: true, remove: true })));
  expect(state.valid).toBe(true);
  expect(state.practiceNames).toEqual(practiceLabels.map(() => 'goetz/practice-area-item'));
  expect(state.practiceLabels).toEqual(practiceLabels);
  expect(state.practiceLocks).toEqual(practiceLabels.map(() => null));
  expect(state.attorneyBlockNames).toEqual(['goetz/attorney-card', 'goetz/attorney-card']);
  expect(state.attorneyNames).toEqual(['James L. Goetz', 'Gregory W. Goetz']);
  expect(state.attorneyLocks).toEqual([null, null]);
  expect(state.images).toHaveLength(8);
  for (const image of state.images) {
    expect(image.id).toBeGreaterThan(0);
    expect(image.url).toMatch(/^\/(?!\/)/);
    expect(image.url).not.toContain('://');
  }
}

test('production homepage template locks the root and preserves native child operations', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  page.setDefaultTimeout(10_000);
  page.setDefaultNavigationTimeout(20_000);
  const baseURL = requireLocalBaseURL(testInfo);
  const temporary: TemporaryPages = {};
  const editor = new HomepageEditorPage(page);
  let originalSettings: FrontPageSettings | undefined;
  let production: PageSnapshot | undefined;

  try {
    await openAdminApi(page);
    originalSettings = await frontPageSettings(page);
    expect(originalSettings.show_on_front).toBe('page');
    expect(originalSettings.page_on_front).toBeGreaterThan(0);

    production = await pageSnapshot(page, originalSettings.page_on_front);
    expect(production.slug).toBe('home');
    expect(production.status).toBe('publish');
    expect(production.contentSha256).toMatch(/^[a-f0-9]{64}$/);

    await editor.open(production.id);
    const productionState = await editor.state();
    expectCanonicalHomepage(productionState);
    expect(productionState.templateLock).toBe('all');
    expect(await editor.content()).toBe(production.content);
    await editor.expectCleanAndValid();

    await createDisposableClone(page, production.content, temporary);
    const clone = await pageSnapshot(page, temporary.homeId!);
    expect(clone.slug).toBe('home');
    expect(clone.content).toBe(production.content);
    expect(clone.contentSha256).toBe(production.contentSha256);

    await updateFrontPageSettings(page, {
      show_on_front: 'page',
      page_on_front: temporary.homeId!,
    });
    await editor.open(temporary.homeId!);

    const initial = await editor.state();
    expectCanonicalHomepage(initial);
    expect(initial.templateLock).toBe('all');
    expect(initial.canInsertRoot).toBe(false);
    expect(initial.canMoveRoots).toEqual(rootNames.map(() => false));
    expect(initial.canRemoveRoots).toEqual(rootNames.map(() => false));
    expect(initial.practiceAllowed).toEqual(['goetz/practice-area-item']);
    expect(initial.canInsertPractice).toBe(true);
    expect(initial.canMovePracticeChild).toBe(true);
    expect(initial.canRemovePracticeChild).toBe(true);
    expect(initial.attorneyAllowed).toEqual(['goetz/attorney-card']);
    expect(initial.canInsertAttorney).toBe(true);
    expect(initial.canMoveAttorneyChild).toBe(true);
    expect(initial.canRemoveAttorneyChild).toBe(true);
    expect(initial.heroAttributes.imageId).not.toBe(initial.welcomeAttributes.leftImageId);

    const suffix = randomUUID();
    const markers: EditMarkers = {
      heroHeading: `Editable root heading ${suffix}`,
      heroContent: `Editable root content ${suffix}`,
      heroImageAlt: `Editable root media ${suffix}`,
      practiceLabel: `Integration Practice ${suffix}`,
      attorneyName: `Integration Attorney ${suffix}`,
    };
    const operations = await editor.exerciseLocksAndEdits(markers);

    expect(operations.rootNames).toEqual(rootNames);
    expect(operations.rootIds).toEqual(initial.rootIds);
    expect(operations.heroAttributes).toEqual(expect.objectContaining({
      heading: markers.heroHeading,
      content: markers.heroContent,
      imageId: initial.welcomeAttributes.leftImageId,
      imageUrl: initial.welcomeAttributes.leftImageUrl,
      imageAlt: markers.heroImageAlt,
    }));
    expect(operations.practiceLabels[0]).toBe(markers.practiceLabel);
    expect(operations.practiceLabels).toHaveLength(practiceLabels.length);
    expect(operations.attorneyNames[0]).toBe(markers.attorneyName);
    expect(operations.attorneyNames).toHaveLength(2);

    await editor.save();
    await editor.open(temporary.homeId!);

    const reloaded = await editor.state();
    expect(reloaded.names).toEqual(rootNames);
    expect(reloaded.locks).toEqual(rootNames.map(() => ({ move: true, remove: true })));
    expect(reloaded.valid).toBe(true);
    expect(reloaded.templateLock).toBe('all');
    expect(reloaded.canInsertRoot).toBe(false);
    expect(reloaded.canMoveRoots).toEqual(rootNames.map(() => false));
    expect(reloaded.canRemoveRoots).toEqual(rootNames.map(() => false));
    expect(reloaded.heroAttributes).toEqual(expect.objectContaining({
      heading: markers.heroHeading,
      content: markers.heroContent,
      imageId: initial.welcomeAttributes.leftImageId,
      imageUrl: initial.welcomeAttributes.leftImageUrl,
      imageAlt: markers.heroImageAlt,
    }));
    expect(reloaded.practiceLabels[0]).toBe(markers.practiceLabel);
    expect(reloaded.practiceLabels).toHaveLength(practiceLabels.length);
    expect(reloaded.practiceLocks).toEqual(practiceLabels.map(() => null));
    expect(reloaded.attorneyNames[0]).toBe(markers.attorneyName);
    expect(reloaded.attorneyNames).toHaveLength(2);
    expect(reloaded.attorneyLocks).toEqual([null, null]);
    await editor.expectCleanAndValid();
  } finally {
    await cleanup(page, baseURL, originalSettings, production, temporary);
  }
});
