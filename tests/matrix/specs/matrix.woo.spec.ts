import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  enableContentTypes,
  enableFacets,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under the `woo` matrix leg (WooCommerce mounted + activated).
// Woo boots slowly, hence the generous timeout and single-call seeding.
test.setTimeout(600_000);

async function ensureWooActive(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const activateLink = page.locator('tr[data-slug="woocommerce"] .activate a');
  if (await activateLink.isVisible()) {
    await activateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

test.describe('Matrix Woo', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await ensureWooActive(page);
    await enableSearchEngine(page);
    await enableContentTypes(page, ['product']);
    await enableFacets(page, true);
  });

  test('products are indexed with price buckets', async ({ page }) => {
    const seed = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/matrix/seed-woo.php'
    );
    expect(seed).toMatch(/MATRIX_WOO \d+,\d+,\d+/);
    await runCliCommand('yetisearch reindex');

    const res = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=boots&facets=1`
    );
    expect(res.ok()).toBe(true);
    const data = await res.json();

    expect(data.items.length).toBe(3);
    expect(data.items[0].post_type).toBe('product');
    const buckets = data.facets?.price_range ?? [];
    expect(buckets.map((b: { count: number }) => b.count)).toEqual([1, 1, 0, 1]);

    const filtered = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=boots&filter[price][gte]=100&filter[price][lte]=500`
    );
    const fdata = await filtered.json();
    expect(fdata.items.length).toBe(1);
  });
});
