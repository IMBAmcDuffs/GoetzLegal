import { expect, test } from '@playwright/test';

test('local smoke', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'auth');

  const response = await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await expect(page.locator('body.wp-admin')).toBeVisible();
});

test('public local smoke', async ({ page }, testInfo) => {
  test.skip(testInfo.project.metadata.scope !== 'public');

  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await expect(page.locator('body')).toBeVisible();
  await expect(page).toHaveTitle(/Goetz/i);
});
