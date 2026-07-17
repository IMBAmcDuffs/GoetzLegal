import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const baseURL = process.env.GOETZ_BASE_URL || process.env.WP_URL || 'http://localhost:8080';

export default defineConfig({
  testDir: '.',
  testMatch: [
    'smoke.spec.ts',
    'settings.spec.ts',
    'gutenberg-existing-blocks.spec.ts',
  ],
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: 'line',
  globalSetup: './global-setup.ts',
  outputDir: path.resolve('../../__dev/playwright/auth-results'),
  use: {
    baseURL,
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
        storageState: path.resolve('../../__dev/playwright/auth-state.json'),
      },
    },
  ],
});
