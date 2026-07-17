import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { wordpressLaunchOptions } from './helpers/browser.mjs';

const baseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';
const artifactRoot = process.env.GOETZ_ARTIFACT_DIR || path.resolve('../../__dev/playwright');

export default defineConfig({
  testDir: '.',
  testMatch: ['capture-reference.spec.ts'],
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'line',
  outputDir: path.join(artifactRoot, 'capture-results'),
  use: {
    ...devices['Desktop Chrome'],
    browserName: 'chromium',
    baseURL,
    launchOptions: wordpressLaunchOptions(baseURL),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium-capture',
      metadata: { scope: 'capture' },
      use: { browserName: 'chromium' },
    },
  ],
});
