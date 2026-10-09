import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createCliPost,
  runCliCommand,
  SITE_URL,
} from './helpers/wp-env';

test.setTimeout(180_000);

test.describe('Stemming', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('inflected English matches by stem', async ({ page }) => {
    await createCliPost('Morning jog', 'Running shoes for runners.');
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=run`
    );
    expect(response.ok()).toBe(true);
    const data = await response.json();

    expect(data.total).toBeGreaterThan(0);
    expect(JSON.stringify(data.items)).toContain('Morning jog');
  });
});
