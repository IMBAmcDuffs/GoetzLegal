import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

const captureMode = process.env.GOETZ_CAPTURE_MODE || 'contract';
const baseURL = captureMode === 'write'
  ? process.env.GOETZ_REFERENCE_URL
  : 'https://goetzlegal.com/';
const artifactRoot = process.env.GOETZ_ARTIFACT_DIR || path.resolve('../../__dev/playwright/capture');

export default defineConfig({
  testDir: '.',
  testMatch: ['capture-reference.spec.ts'],
  forbidOnly: true,
  timeout: 240_000,
  expect: { timeout: 15_000 },
  retries: 0,
  workers: 1,
  reporter: 'line',
  outputDir: path.join(artifactRoot, 'capture-results'),
  use: {
    ...devices['Desktop Chrome'],
    browserName: 'chromium',
    baseURL,
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
    serviceWorkers: 'block',
    acceptDownloads: false,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium-capture',
      metadata: { scope: 'capture', mode: captureMode },
      use: { browserName: 'chromium' },
    },
  ],
});
