import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const baseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';

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
  outputDir: path.resolve('../../__dev/playwright/public-results'),
  use: {
    ...devices['Desktop Chrome'],
    browserName: 'chromium',
    baseURL,
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
