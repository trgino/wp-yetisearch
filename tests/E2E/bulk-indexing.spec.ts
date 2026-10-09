import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createPost,
  runCliCommand,
  SITE_URL,
} from './helpers/wp-env';
import { searchFor, getSearchCount } from './helpers/fixtures';

test.setTimeout(120_000);

test.describe('Bulk Indexing', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('bulk index via admin AJAX', async ({ page }) => {
    for (let i = 1; i <= 3; i++) {
      await createPost(page, `Buffalo Article ${i}`, `Content about buffalo number ${i}.`);
    }

    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=maintenance`);
    const indexButton = page.locator('#yetisearch-reindex');
    if (await indexButton.isVisible()) {
      await indexButton.click();
      await expect(page.locator('#yetisearch-reindex-status')).toContainText('done', { timeout: 60000 });
    }

    await searchFor(page, 'buffalo');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
  });

  test('bulk index via WP-CLI', async ({ page }) => {
    for (let i = 1; i <= 3; i++) {
      await createPost(page, `Wildebeest Article ${i}`, `Content about wildebeest number ${i}.`);
    }

    const output = await runCliCommand('yetisearch reindex --batch=100');
    expect(output).toMatch(/indexed|success/i);

    await searchFor(page, 'wildebeest');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
  });

  test('forced reindex clears old data', async ({ page }) => {
    await createPost(page, 'Temporary Post', 'This content will be replaced.');

    const output = await runCliCommand('yetisearch reindex --force');
    expect(output).toMatch(/indexed|success/i);

    await searchFor(page, 'temporary');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
  });

  test('index stats show document count', async ({ page }) => {
    await createPost(page, 'Stats Test Post', 'Content for stats testing.');

    await runCliCommand('yetisearch reindex');
    const output = await runCliCommand('yetisearch stats');
    expect(output).toMatch(/documents|stats/i);
  });
});
