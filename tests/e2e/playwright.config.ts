import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { wordpressLaunchOptions } from './helpers/browser.mjs';

const baseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';
const artifactRoot = process.env.GOETZ_ARTIFACT_DIR || path.resolve('../../__dev/playwright');
const authStatePath = process.env.GOETZ_AUTH_STATE_PATH || path.resolve('../../__dev/playwright/auth-state/auth-state.json');

export default defineConfig({
  testDir: '.',
  testMatch: [
    'smoke.spec.ts',
    'settings.spec.ts',
    'gutenberg-existing-blocks.spec.ts',
    'gutenberg-welcome-block.spec.ts',
    'homepage-sections.spec.ts',
    'homepage-template.spec.ts',
    'practice-animation.spec.ts',
    'production-read-only.spec.ts',
  ],
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'line',
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  outputDir: path.join(artifactRoot, 'auth-results'),
  use: {
    baseURL,
    launchOptions: wordpressLaunchOptions(baseURL),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium-authenticated',
      metadata: { scope: 'auth' },
      use: {
        ...devices['Desktop Chrome'],
        browserName: 'chromium',
        storageState: authStatePath,
      },
    },
  ],
});
