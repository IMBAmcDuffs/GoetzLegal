import { chromium, type FullConfig } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

function isLoopback(url: URL): boolean {
  return ['localhost', '127.0.0.1', '::1'].includes(url.hostname);
}

export default async function globalSetup(config: FullConfig): Promise<void> {
  const configuredBaseURL = config.projects[0]?.use.baseURL;
  if (typeof configuredBaseURL !== 'string') {
    throw new Error('Authenticated Playwright requires a GOETZ_BASE_URL.');
  }

  const baseURL = new URL(configuredBaseURL);
  if (!isLoopback(baseURL) && process.env.GOETZ_E2E_ALLOW_REMOTE !== '1') {
    throw new Error('Remote authenticated tests require GOETZ_E2E_ALLOW_REMOTE=1.');
  }

  const username = process.env.GOETZ_E2E_USER;
  const password = process.env.GOETZ_E2E_PASSWORD;
  if (!username || !password) {
    throw new Error('Authenticated Playwright credentials were not supplied.');
  }

  const storageState = path.resolve('../../__dev/playwright/auth-state.json');
  await mkdir(path.dirname(storageState), { recursive: true });

  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(new URL('/wp-login.php', baseURL).toString(), {
      waitUntil: 'domcontentloaded',
    });
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await Promise.all([
      page.waitForURL(/\/wp-admin\/?(?:$|[?#])/, { timeout: 30_000 }),
      page.locator('#wp-submit').click(),
    ]);
    await page.context().storageState({ path: storageState });
  } finally {
    await browser.close();
  }
}
