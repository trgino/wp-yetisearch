import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  enableContentTypes,
  createCliPost,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under the `cpt` matrix leg (book CPT via mu-plugin mapping).
test.setTimeout(240_000);

test.describe('Matrix CPT', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
    await enableContentTypes(page, ['book']);
  });

  test('registered CPT is listed and indexed', async ({ page }) => {
    // The settings checkbox itself proves the CPT is registered.
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=content`);
    await expect(
      page.locator('input[name="yetisearch[indexed_post_types][]"][value="book"]')
    ).toBeChecked();

    const id = await createCliPost('The Rust Atlas', 'A book about atlas.', '--post_type=book');
    expect(id).toBeGreaterThan(0);
    await runCliCommand('yetisearch reindex');

    const res = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=atlas`
    );
    expect(res.ok()).toBe(true);
    const data = await res.json();
    expect(data.items.map((i: { id: number }) => i.id)).toContain(id);
    expect(data.items[0].post_type).toBe('book');
  });
});
