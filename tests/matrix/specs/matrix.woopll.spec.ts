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

// Runs under the `woopll` matrix leg (WooCommerce + Polylang free + Pro +
// Polylang for WooCommerce). Products are seeded per language through the
// PLLWC product language store by tests/matrix/seed-woopll.php.
test.setTimeout(600_000);

async function ensurePluginActive(page: import('@playwright/test').Page, slug: string): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const activateLink = page.locator(`tr[data-slug="${slug}"] .activate a`);
  if (await activateLink.isVisible()) {
    await activateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

test.describe('Matrix Woo+Polylang', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await ensurePluginActive(page, 'woocommerce');
    await ensurePluginActive(page, 'polylang');
    await ensurePluginActive(page, 'polylang-pro');
    await ensurePluginActive(page, 'polylang-wc');
    await enableSearchEngine(page);
    await enableContentTypes(page, ['product']);
    await enableFacets(page, true);
  });

  test('translated products search their own index with price buckets', async ({ page }) => {
    const seed = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/matrix/seed-woopll.php'
    );
    const match = seed.match(/MATRIX_WOOPLL ([\d,]+)/);
    expect(match).not.toBeNull();
    // Languages were assigned after creation: force rebuild (documented path).
    await runCliCommand('yetisearch reindex --force');

    const en = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=Woo&lang=en&facets=1`
    );
    expect(en.ok()).toBe(true);
    const enData = await en.json();
    expect(enData.items.length).toBe(2);
    expect(enData.items[0].post_type).toBe('product');
    expect(enData.facets?.price_range?.map((b: { count: number }) => b.count)).toEqual([1, 0, 0, 1]);

    const tr = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=Woo&lang=tr&facets=1`
    );
    expect(tr.ok()).toBe(true);
    const trData = await tr.json();
    expect(trData.items.length).toBe(2);
    expect(trData.facets?.price_range?.map((b: { count: number }) => b.count)).toEqual([0, 2, 0, 0]);

    const filtered = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=coat&lang=en&filter[price][gte]=1000`
    );
    const fdata = await filtered.json();
    expect(fdata.items.length).toBe(1);
  });
});
