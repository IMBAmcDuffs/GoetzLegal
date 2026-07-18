import { expect, test, type Page, type Request } from '@playwright/test';

const homepageRootNames = [
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

const settingsKeys = [
  'business_name',
  'alternate_name',
  'phone_display',
  'phone_e164',
  'email',
  'street_address',
  'locality',
  'region',
  'postal_code',
  'country_code',
  'location_label',
  'cta_label',
  'cta_url',
  'footer_disclaimer',
  'footer_legal_copy',
  'copyright_start_year',
  'copyright_text',
  'copyright_dynamic_year',
  'social_image_id',
] as const;

const invalidBlockWarning =
  /unexpected or invalid content|attempt block recovery|modified externally|updated outside of this editor/i;

interface FrontPageSnapshot {
  id: number;
  slug: string;
  status: string;
  modifiedGmt: string;
  content: string;
}

interface SettingFieldSnapshot {
  key: string;
  tag: string;
  type: string;
  value: string;
  checked: boolean | null;
}

function tracksContentMutation(request: Request): boolean {
  const method = request.method().toUpperCase();
  if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    return false;
  }

  const url = new URL(request.url());
  return url.pathname === '/wp-admin/options.php'
    || /\/wp-json\/wp\/v2\/(?:pages(?:\/|$)|settings(?:\/|$))/.test(url.pathname);
}

async function openAdminApi(page: Page): Promise<void> {
  const response = await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.apiFetch));
}

async function readFrontPageSnapshot(page: Page): Promise<FrontPageSnapshot> {
  const snapshot = await page.evaluate(async () => {
    const apiFetch = (globalThis as any).wp.apiFetch;
    const settings = await apiFetch({ path: '/wp/v2/settings?context=edit' });
    const id = Number(settings?.page_on_front || 0);
    if (String(settings?.show_on_front || '') !== 'page' || !Number.isInteger(id) || id < 1) {
      throw new Error('WordPress is not configured with a static front page.');
    }

    const frontPage = await apiFetch({ path: `/wp/v2/pages/${id}?context=edit` });
    return {
      id: Number(frontPage?.id || 0),
      slug: String(frontPage?.slug || ''),
      status: String(frontPage?.status || ''),
      modifiedGmt: String(frontPage?.modified_gmt || ''),
      content: String(frontPage?.content?.raw || ''),
    };
  });

  expect(snapshot.id).toBeGreaterThan(0);
  expect(snapshot.slug).toBe('home');
  expect(snapshot.status).toBe('publish');
  expect(snapshot.modifiedGmt).not.toBe('');
  expect(snapshot.content).not.toBe('');
  return snapshot;
}

async function dismissWelcomeGuide(page: Page): Promise<void> {
  const welcome = page.getByRole('dialog', { name: 'Welcome to the editor' });
  if (await welcome.isVisible()) {
    await welcome.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(welcome).toBeHidden();
  }
}

async function openHomepageEditor(page: Page, postId: number): Promise<void> {
  const response = await page.goto(`/wp-admin/post.php?post=${postId}&action=edit`, {
    waitUntil: 'domcontentloaded',
  });
  expect(response?.status()).toBe(200);
  await page.waitForFunction(({ id, names }) => {
    const wordpress = (globalThis as any).wp;
    const editor = wordpress?.data?.select('core/editor');
    return Boolean(
      wordpress?.blocks
      && editor
      && Number(editor.getCurrentPostId?.() || 0) === id
      && names.every((name: string) => wordpress.blocks.getBlockType(name)),
    );
  }, { id: postId, names: homepageRootNames });
  await dismissWelcomeGuide(page);
}

async function homepageEditorState(page: Page) {
  return page.evaluate((names) => {
    const wordpress = (globalThis as any).wp;
    const selector = wordpress.data.select('core/block-editor');
    const editor = wordpress.data.select('core/editor');
    const roots = selector.getBlocks();
    const rootClientId = roots.length > 0
      ? String(selector.getBlockRootClientId(roots[0].clientId) || '')
      : '';
    const practice = roots.find((block: any) => block.name === names[2]);
    const attorneys = roots.find((block: any) => block.name === names[3]);
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
    const allowedNames = (clientId: string): string[] | null => {
      const allowed = selector.getAllowedBlocks(clientId);
      if (!Array.isArray(allowed)) return null;
      return allowed.map((blockType: any) => (
        typeof blockType === 'string' ? blockType : String(blockType?.name || '')
      ));
    };

    return {
      names: roots.map((block: any) => block.name),
      locks: roots.map((block: any) => block.attributes.lock ?? null),
      valid: validTree(roots),
      templateLock: selector.getSettings().templateLock,
      canInsertRoot: selector.canInsertBlockType('core/paragraph', rootClientId),
      canMoveRoots: roots.map((block: any) => canMove(block.clientId, rootClientId)),
      canRemoveRoots: roots.map((block: any) => canRemove(block.clientId, rootClientId)),
      practiceNames: (practice?.innerBlocks || []).map((block: any) => block.name),
      practiceLabels: (practice?.innerBlocks || []).map(
        (block: any) => String(block.attributes?.label || ''),
      ),
      practiceLocks: (practice?.innerBlocks || []).map(
        (block: any) => block.attributes.lock ?? null,
      ),
      practiceAllowed: practice ? allowedNames(practice.clientId) : null,
      canInsertPractice: Boolean(practice) && selector.canInsertBlockType(
        'goetz/practice-area-item',
        practice.clientId,
      ),
      canMovePractice: Boolean(practice?.innerBlocks?.[0]) && canMove(
        practice.innerBlocks[0].clientId,
        practice.clientId,
      ),
      canRemovePractice: Boolean(practice?.innerBlocks?.[0]) && canRemove(
        practice.innerBlocks[0].clientId,
        practice.clientId,
      ),
      attorneyNames: (attorneys?.innerBlocks || []).map(
        (block: any) => String(block.attributes?.name || ''),
      ),
      attorneyBlockNames: (attorneys?.innerBlocks || []).map((block: any) => block.name),
      attorneyLocks: (attorneys?.innerBlocks || []).map(
        (block: any) => block.attributes.lock ?? null,
      ),
      attorneyAllowed: attorneys ? allowedNames(attorneys.clientId) : null,
      canInsertAttorney: Boolean(attorneys) && selector.canInsertBlockType(
        'goetz/attorney-card',
        attorneys.clientId,
      ),
      canMoveAttorney: Boolean(attorneys?.innerBlocks?.[0]) && canMove(
        attorneys.innerBlocks[0].clientId,
        attorneys.clientId,
      ),
      canRemoveAttorney: Boolean(attorneys?.innerBlocks?.[0]) && canRemove(
        attorneys.innerBlocks[0].clientId,
        attorneys.clientId,
      ),
      dirty: Boolean(editor.isEditedPostDirty()),
      saving: Boolean(editor.isSavingPost()),
    };
  }, homepageRootNames);
}

async function selectBlock(page: Page, blockName: string, childIndex?: number): Promise<void> {
  await page.evaluate(({ name, index }) => {
    const wordpress = (globalThis as any).wp;
    const selector = wordpress.data.select('core/block-editor');
    const roots = selector.getBlocks();
    const root = roots.find((block: any) => block.name === name);
    const block = index === undefined ? root : root?.innerBlocks?.[index];
    if (!block?.clientId) {
      throw new Error('The expected editable homepage block is missing.');
    }
    wordpress.data.dispatch('core/block-editor').selectBlock(block.clientId);
  }, { name: blockName, index: childIndex });
}

async function expectEditableHomepageControls(page: Page): Promise<void> {
  const editorCanvas = page.locator('iframe[name="editor-canvas"]');
  const editor = await editorCanvas.count() > 0 ? editorCanvas.contentFrame() : page;

  await selectBlock(page, 'goetz/hero');
  await expect(editor.getByLabel('Hero heading', { exact: true })).toBeEditable();

  await selectBlock(page, 'goetz/practice-areas', 0);
  await expect(editor.getByLabel('Practice area label', { exact: true }).first()).toBeEditable();

  await selectBlock(page, 'goetz/attorney-grid', 0);
  await expect(editor.getByLabel('Attorney name', { exact: true }).first()).toBeEditable();
}

async function readSettingFields(page: Page): Promise<SettingFieldSnapshot[]> {
  return page.locator('form[data-goetz-site-settings-form]').evaluate((form) => (
    Array.from(form.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>(
      '[data-goetz-setting-key]',
    )).map((field) => ({
      key: String(field.dataset.goetzSettingKey || ''),
      tag: field.tagName.toLowerCase(),
      type: field instanceof HTMLInputElement ? field.type : 'textarea',
      value: field.value,
      checked: field instanceof HTMLInputElement && field.type === 'checkbox'
        ? field.checked
        : null,
    }))
  ));
}

test('production homepage read-only verifies the locked tree and editable controls without saving', async ({ page }) => {
  test.setTimeout(90_000);
  page.setDefaultTimeout(15_000);
  page.setDefaultNavigationTimeout(30_000);

  const mutations: string[] = [];
  const dialogs: string[] = [];
  page.on('request', (request) => {
    if (tracksContentMutation(request)) mutations.push(`${request.method()} ${request.url()}`);
  });
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.type());
    await dialog.dismiss();
  });

  await openAdminApi(page);
  const before = await readFrontPageSnapshot(page);
  await openHomepageEditor(page, before.id);

  const state = await homepageEditorState(page);
  expect(state.names).toEqual(homepageRootNames);
  expect(state.locks).toEqual(homepageRootNames.map(() => ({ move: true, remove: true })));
  expect(state.valid).toBe(true);
  expect(state.templateLock).toBe('all');
  expect(state.canInsertRoot).toBe(false);
  expect(state.canMoveRoots).toEqual(homepageRootNames.map(() => false));
  expect(state.canRemoveRoots).toEqual(homepageRootNames.map(() => false));
  expect(state.practiceNames).toEqual(practiceLabels.map(() => 'goetz/practice-area-item'));
  expect(state.practiceLabels).toEqual(practiceLabels);
  expect(state.practiceLocks).toEqual(practiceLabels.map(() => null));
  expect(state.practiceAllowed).toEqual(['goetz/practice-area-item']);
  expect(state.canInsertPractice).toBe(true);
  expect(state.canMovePractice).toBe(true);
  expect(state.canRemovePractice).toBe(true);
  expect(state.attorneyBlockNames).toEqual(['goetz/attorney-card', 'goetz/attorney-card']);
  expect(state.attorneyNames).toEqual(['James L. Goetz', 'Gregory W. Goetz']);
  expect(state.attorneyLocks).toEqual([null, null]);
  expect(state.attorneyAllowed).toEqual(['goetz/attorney-card']);
  expect(state.canInsertAttorney).toBe(true);
  expect(state.canMoveAttorney).toBe(true);
  expect(state.canRemoveAttorney).toBe(true);
  expect(state.dirty).toBe(false);
  expect(state.saving).toBe(false);
  await expectEditableHomepageControls(page);
  expect((await homepageEditorState(page)).dirty).toBe(false);
  await expect(page.getByText(invalidBlockWarning)).toHaveCount(0);

  await openAdminApi(page);
  const after = await readFrontPageSnapshot(page);
  expect(after).toEqual(before);
  expect(mutations).toEqual([]);
  expect(dialogs).toEqual([]);
});

test('Site Settings read-only renders escaped values without submitting the form', async ({ page }) => {
  test.setTimeout(60_000);
  page.setDefaultTimeout(15_000);
  page.setDefaultNavigationTimeout(30_000);

  const mutations: string[] = [];
  const dialogs: string[] = [];
  page.on('request', (request) => {
    if (tracksContentMutation(request)) mutations.push(`${request.method()} ${request.url()}`);
  });
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.type());
    await dialog.dismiss();
  });

  const settingsPath = '/wp-admin/options-general.php?page=goetz-site-settings';
  const response = await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Site Settings' })).toBeVisible();

  const form = page.locator('form[data-goetz-site-settings-form]');
  await expect(form).toBeVisible();
  await expect(form.locator('input[name="_wpnonce"]')).toHaveCount(1);
  expect((await form.getAttribute('method'))?.toLowerCase()).toBe('post');
  const action = new URL(await form.getAttribute('action') || '', page.url());
  expect(action.origin).toBe(new URL(page.url()).origin);
  expect(action.pathname).toBe('/wp-admin/options.php');
  await expect(form.locator('script, iframe, object, embed')).toHaveCount(0);
  expect(await form.evaluate((element) => Array.from(element.querySelectorAll('*')).some(
    (child) => Array.from(child.attributes).some((attribute) => /^on/i.test(attribute.name)),
  ))).toBe(false);

  const before = await readSettingFields(page);
  expect(before.map((field) => field.key)).toEqual(settingsKeys);
  expect(new Set(before.map((field) => field.key)).size).toBe(settingsKeys.length);
  expect(before.find((field) => field.key === 'business_name')?.value).not.toBe('');
  expect(before.find((field) => field.key === 'phone_display')?.value).not.toBe('');
  expect(before.find((field) => field.key === 'email')?.value).toMatch(/^[^@\s]+@[^@\s]+$/);

  await openAdminApi(page);
  expect(dialogs).toEqual([]);
  const returnResponse = await page.goto(settingsPath, { waitUntil: 'domcontentloaded' });
  expect(returnResponse?.status()).toBe(200);
  expect(await readSettingFields(page)).toEqual(before);
  expect(mutations).toEqual([]);
  expect(dialogs).toEqual([]);
});
