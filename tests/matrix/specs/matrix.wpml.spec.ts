import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under the `wpml` matrix leg (WPML core + String Translation + local
// activator, mounted from ./wpml/*.zip). Languages + posts are seeded by
// tests/matrix/seed-wpml.php.
test.setTimeout(600_000);

async function ensurePluginActive(page: import('@playwright/test').Page, slug: string): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const activateLink = page.locator(`tr[data-slug="${slug}"] .activate a`);
  if (await activateLink.isVisible()) {
    await activateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

test.describe('Matrix WPML', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await ensurePluginActive(page, 'sitepress-multilingual-cms');
    await ensurePluginActive(page, 'wpml-string-translation');
    await ensurePluginActive(page, 'sitepress-multilingual-cms-activator');
    await enableSearchEngine(page);
  });

  test('WPML languages search their own index', async ({ page }) => {
    const seed = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/matrix/seed-wpml.php'
    );
    const match = seed.match(/MATRIX_WPML EN=(\d+) TR=(\d+)/);
    expect(match).not.toBeNull();
    const [, enId, trId] = match!.map(Number);
    expect(enId).toBeGreaterThan(0);
    expect(trId).toBeGreaterThan(0);
    // Language was assigned after creation, so the posts also exist in the
    // wrong index from real-time indexing: a force rebuild is the documented
    // path after language changes.
    await runCliCommand('yetisearch reindex --force');

    const en = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=Brighton&lang=en`
    );
    expect(en.ok()).toBe(true);
    const enData = await en.json();
    expect(enData.items.map((i: { id: number }) => i.id)).toContain(enId);

    const tr = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=vapuru&lang=tr`
    );
    expect(tr.ok()).toBe(true);
    const trData = await tr.json();
    expect(trData.items.map((i: { id: number }) => i.id)).toContain(trId);

    // The TR post must not leak into the default index.
    const def = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=vapuru`
    );
    const defData = await def.json();
    expect(defData.items.map((i: { id: number }) => i.id)).not.toContain(trId);
  });
});
