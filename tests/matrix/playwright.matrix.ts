import { defineConfig, devices } from '@playwright/test';

// Matrix legs (Woo/Polylang/mu-plugin envs). Run via bin/matrix-leg which
// installs the matching .wp-env.override.json first. NOT part of the
// default suite: run with --config tests/matrix/playwright.matrix.ts
// (optionally followed by a spec file filter).
export default defineConfig({
  testDir: './specs',
  globalSetup: '../E2E/global-setup.ts',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://localhost:8889',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
