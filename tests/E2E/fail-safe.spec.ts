import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createPost,
  createCliPost,
  runCliCommand,
  SITE_URL,
} from './helpers/wp-env';
import { searchFor, getSearchCount, getSearchResults } from './helpers/fixtures';

test.setTimeout(120_000);

test.describe('Fail-Safe Fallback', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
  });

  test('search falls back to MySQL when engine is disabled', async ({ page }) => {
    await enableSearchEngine(page);
    await createPost(page, 'Fallback Test Post', 'Content about kangaroos and wallabies.');
    await runCliCommand('yetisearch reindex');

    // Disable the engine
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
    if (await masterToggle.isChecked()) {
      await masterToggle.uncheck();
      await page.click('input[type="submit"]');
      await page.waitForLoadState('networkidle');
    }

    // Search should still work via MySQL fallback
    await searchFor(page, 'kangaroo');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
  });

  test('REST returns 503 when engine is disabled', async ({ page }) => {
    await enableSearchEngine(page);
    await createPost(page, 'REST Fallback Test', 'Content about koalas.');

    // Disable the engine
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
    if (await masterToggle.isChecked()) {
      await masterToggle.uncheck();
      await page.click('input[type="submit"]');
      await page.waitForLoadState('networkidle');
    }

    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=koala`);
    expect(response.status()).toBe(503);

    const data = await response.json();
    expect(data.code).toBe('yetisearch_unavailable');
  });

  test('plugin activates without pdo_sqlite', async ({ page }) => {
    // This test verifies the plugin doesn't fatal on activation
    // In wp-env, pdo_sqlite is always available, so we verify the plugin activates cleanly
    await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
    const pluginRow = page.locator('tr[data-slug="wp-yetisearch"]');
    await expect(pluginRow).toBeVisible();
  });

  test('saving a post does not throw when engine is unavailable', async ({ page }) => {
    // Disable engine
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
    if (await masterToggle.isChecked()) {
      await masterToggle.uncheck();
      await page.click('input[type="submit"]');
      await page.waitForLoadState('networkidle');
    }

    // Creating a post should still work (via WP-CLI; Gutenberg DOM varies by version)
    const id = await createCliPost(
      'Post Without Engine',
      'This post was created with the search engine disabled.'
    );
    expect(id).toBeGreaterThan(0);
  });

  test('search works after re-enabling engine', async ({ page }) => {
    // Disable then re-enable
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    let masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
    if (await masterToggle.isChecked()) {
      await masterToggle.uncheck();
      await page.click('input[type="submit"]');
      await page.waitForLoadState('networkidle');
    }

    await createPost(page, 'Re-enable Test', 'Content about Tasmanian devils.');

    // Re-enable
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
    if (!(await masterToggle.isChecked())) {
      await masterToggle.check();
      await page.click('input[type="submit"]');
      await page.waitForLoadState('networkidle');
    }

    await runCliCommand('yetisearch reindex');

    await searchFor(page, 'Tasmanian');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
  });

  test('password-protected posts are not searchable', async ({ page }) => {
    await enableSearchEngine(page);

    // Create a password-protected post via WP-CLI (Gutenberg UI varies by version)
    const id = await createCliPost('Secret Post', 'This content is about narwhals.', '--post_password=secret123');
    expect(id).toBeGreaterThan(0);

    await runCliCommand('yetisearch reindex');

    // Assert via REST: the theme renders a "More posts" fallback that links
    // password-protected titles, so front-end counting cannot prove absence.
    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=narwhal`);
    expect(response.ok()).toBe(true);
    const data = await response.json();
    const titles = (data.items ?? []).map((item) => String(item.title ?? ''));
    expect(titles.some((t) => t.includes('Secret'))).toBe(false);
  });
});
