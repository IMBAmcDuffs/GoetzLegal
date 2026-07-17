import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { wordpressLaunchOptions } from './helpers/browser.mjs';

const baseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';
const artifactRoot = process.env.GOETZ_ARTIFACT_DIR || path.resolve('../../__dev/playwright');

export default defineConfig({
  testDir: '.',
  testMatch: [
    'smoke.spec.ts',
    'practice-animation.spec.ts',
    'navigation-accessibility.spec.ts',
    'homepage-layout.spec.ts',
    'seo.spec.ts',
    'visual.spec.ts',
    'frontend.spec.ts',
  ],
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'line',
  outputDir: path.join(artifactRoot, 'public-results'),
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
      name: 'chromium-public',
      metadata: { scope: 'public' },
      use: { browserName: 'chromium' },
    },
  ],
});
