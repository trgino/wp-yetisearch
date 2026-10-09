import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createPost,
  runCliCommand,
  SITE_URL,
} from './helpers/wp-env';

test.setTimeout(120_000);

test.describe('REST Endpoint', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('REST search returns results', async ({ page }) => {
    await createPost(page, 'REST Test Zebra', 'Zebras have unique stripe patterns.');
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=zebra`);
    expect(response.ok()).toBe(true);

    const data = await response.json();
    expect(data.items).toBeDefined();
    expect(data.items.length).toBeGreaterThan(0);
    expect(data.total).toBeGreaterThan(0);
  });

  test('REST response has correct shape', async ({ page }) => {
    await createPost(page, 'Shape Test', 'Content for shape testing.');
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=shape`);
    const data = await response.json();

    expect(data).toHaveProperty('items');
    expect(data).toHaveProperty('total');
    expect(data).toHaveProperty('page');
    expect(data).toHaveProperty('suggestion');
    expect(data).toHaveProperty('facets');
    expect(data).toHaveProperty('search_time');
    expect(response.headers()['cache-control']).toBe('public, max-age=60');

    if (data.items.length > 0) {
      const item = data.items[0];
      expect(item).toHaveProperty('id');
      expect(item).toHaveProperty('title');
      expect(item).toHaveProperty('title_html');
      expect(item).toHaveProperty('url');
      expect(item).toHaveProperty('excerpt_html');
      expect(item).toHaveProperty('post_type');
    }
  });

  test('REST returns 400 for empty query', async ({ page }) => {
    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=`);
    expect(response.status()).toBe(400);
  });

  test('REST returns 400 for invalid post type', async ({ page }) => {
    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=test&post_type=attachment`
    );
    expect(response.status()).toBe(400);
  });

  test('REST typeahead context returns fewer results', async ({ page }) => {
    for (let i = 1; i <= 5; i++) {
      await createPost(page, `Antelope ${i}`, `Antelope species number ${i}.`);
    }
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=antelope&context=typeahead&limit=3`
    );
    const data = await response.json();
    expect(data.items.length).toBeLessThanOrEqual(3);
  });

  test('REST does not expose raw document', async ({ page }) => {
    await createPost(page, 'Private Meta Test', 'Content with secret-meta-value inside.');
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=secret-meta-value`);
    const data = await response.json();

    // Only normalized fields may leave the server — never the raw document.
    const json = JSON.stringify(data);
    expect(json).not.toContain('"document"');
    for (const item of data.items ?? []) {
      expect(Object.keys(item).sort()).toEqual(
        ['excerpt_html', 'id', 'post_type', 'title', 'title_html', 'url'].sort()
      );
    }
  });

  test('REST pagination works', async ({ page }) => {
    for (let i = 1; i <= 5; i++) {
      await createPost(page, `Flamingo ${i}`, `Flamingo facts number ${i}.`);
    }
    await runCliCommand('yetisearch reindex');

    const response = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=flamingo&limit=2&page=1`
    );
    const data = await response.json();
    expect(data.items.length).toBeLessThanOrEqual(2);
    expect(data.page).toBe(1);
  });
});
