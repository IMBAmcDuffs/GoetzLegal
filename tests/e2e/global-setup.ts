import { chromium, type FullConfig } from '@playwright/test';
import path from 'node:path';
import { runAuthenticatedSetup } from './helpers/auth-setup.mjs';

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

  const storageState = process.env.GOETZ_AUTH_STATE_PATH ||
    path.resolve('../../__dev/playwright/auth-state/auth-state.json');

  await runAuthenticatedSetup({
    browserType: chromium,
    launchOptions: config.projects[0]?.use.launchOptions,
    storageState,
    login: {
      loginURL: new URL('/wp-login.php', baseURL).toString(),
      expectedOrigin: process.env.GOETZ_EXPECT_ORIGIN || baseURL.origin,
      username,
      password,
    },
  });
}
