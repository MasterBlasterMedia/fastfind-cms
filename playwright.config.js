import { defineConfig, devices } from '@playwright/test';
import { randomUUID } from 'node:crypto';

// Share only this run's private fixture location with the server and browser workers.
process.env.FASTFIND_E2E_RUN_ID ||= randomUUID();

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45000,
  expect: { timeout: 10000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    ...devices['Desktop Chrome'],
    baseURL: 'http://127.0.0.1:8123',
    channel: process.env.PW_CHANNEL || undefined,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'node scripts/serve-e2e.mjs',
    url: 'http://127.0.0.1:8123/api/v1/site',
    reuseExistingServer: false,
    timeout: 120000,
  },
});
