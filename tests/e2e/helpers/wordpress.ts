import { randomUUID } from 'node:crypto';
import type { Page } from '@playwright/test';

export interface TemporaryDraft {
  id: number;
  title: string;
}

async function createDraft(page: Page): Promise<TemporaryDraft> {
  const title = `goetz-e2e-${Date.now()}-${randomUUID()}`;
  await page.goto('/wp-admin/post-new.php?post_type=page', {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.data));

  const id = await page.evaluate(async (draftTitle) => {
    const data = (globalThis as any).wp.data;
    data.dispatch('core/editor').editPost({ title: draftTitle, status: 'draft' });
    await data.dispatch('core/editor').savePost();
    return Number(data.select('core/editor').getCurrentPostId());
  }, title);

  if (!Number.isInteger(id) || id <= 0) {
    throw new Error('WordPress did not return a valid temporary draft ID.');
  }

  return { id, title };
}

async function trashDraft(page: Page, draft: TemporaryDraft): Promise<void> {
  await page.goto(`/wp-admin/post.php?post=${draft.id}&action=edit`, {
    waitUntil: 'domcontentloaded',
  });
  await page.waitForFunction(() => Boolean((globalThis as any).wp?.data));
  await page.evaluate(async () => {
    await (globalThis as any).wp.data.dispatch('core/editor').trashPost();
  });
}

export async function withTemporaryDraft<T>(
  page: Page,
  run: (draft: TemporaryDraft) => Promise<T>,
): Promise<T> {
  const draft = await createDraft(page);
  try {
    return await run(draft);
  } finally {
    await trashDraft(page, draft);
  }
}
