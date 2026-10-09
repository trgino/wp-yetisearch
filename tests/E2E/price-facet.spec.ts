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

async function createProduct(title: string, content: string, price: number): Promise<number> {
  const id = await createCliPost(title, content);
  expect(id).toBeGreaterThan(0);
  await runCliCommand(`post meta set ${id} _price ${price}`);
  return id;
}

async function enablePriceFacet(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=facets_geo`);
  let dirty = false;
  for (const name of ['yetisearch[facets_enabled]', 'yetisearch[price_facet_enabled]']) {
    const toggle = page.locator(`input[name="${name}"]`);
    if (!(await toggle.isChecked())) {
      await toggle.check();
      dirty = true;
    }
  }
  const ranges = page.locator('input[name="yetisearch[price_ranges]"]');
  if ((await ranges.inputValue()) === '') {
    await ranges.fill('100, 500, 1000');
    dirty = true;
  }
  if (dirty) {
    await page.click('input[type="submit"]');
    await page.waitForLoadState('networkidle');
  }
}

test.describe('Price Facet', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
    await enablePriceFacet(page);
  });

  test('buckets count products including empty ones', async ({ page }) => {    await createProduct('Cheap widget', 'Widget for sale.', 50);
    await createProduct('Mid widget', 'Widget for sale.', 150);
    await createProduct('Dear widget', 'Widget for sale.', 2000);
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=widget&facets=1`
    );
    expect(response.ok()).toBe(true);
    const data = await response.json();

    const buckets = data.facets?.price_range ?? [];
    expect(buckets.length).toBe(4);
    expect(buckets.map((b: { count: number }) => b.count)).toEqual([1, 1, 0, 1]);
    expect(buckets[0].filter).toEqual({ price: { lte: 100 } });
    expect(buckets[3].filter).toEqual({ price: { gte: 1000 } });
  });

  test('gte/lte filter narrows to the mid product', async ({ page }) => {
    await createProduct('Cheap gadget', 'Gadget for sale.', 50);
    const midId = await createProduct('Mid gadget', 'Gadget for sale.', 150);
    await createProduct('Dear gadget', 'Gadget for sale.', 2000);
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=gadget&filter[price][gte]=100&filter[price][lte]=500`
    );
    expect(response.ok()).toBe(true);
    const data = await response.json();

    expect(data.items.map((i: { id: number }) => i.id)).toEqual([midId]);
  });

  test('template tag renders clickable bucket links in real WordPress', async () => {
    const out = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/E2E/helpers/render-facet-links.php'
    );
    expect(out).toContain('MATRIX_FACETLINKS LIST-OK,URL-OK,COUNT-OK,EMPTY-OK');
  });
});
