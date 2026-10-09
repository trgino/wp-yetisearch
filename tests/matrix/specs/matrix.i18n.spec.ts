import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under the `i18n` matrix leg (Polylang mounted). Languages + posts are
// seeded by tests/matrix/seed-i18n.php via the leg driver.
test.setTimeout(240_000);

async function ensurePolylangActive(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const activateLink = page.locator('tr[data-slug="polylang"] .activate a');
  if (await activateLink.isVisible()) {
    await activateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

test.describe('Matrix i18n', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await ensurePolylangActive(page);
    await enableSearchEngine(page);
  });

  test('default and second language search their own index', async ({ page }) => {
    const seed = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/matrix/seed-i18n.php'
    );
    const match = seed.match(/MATRIX_I18N EN=(\d+) TR=(\d+)/);
    expect(match).not.toBeNull();
    const [, enId, trId] = match!.map(Number);
    await runCliCommand('yetisearch reindex');

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

    // Default (no lang) searches the legacy default index only.
    const def = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=Brighton`
    );
    const defData = await def.json();
    expect(defData.items.map((i: { id: number }) => i.id)).toContain(enId);
  });
});
