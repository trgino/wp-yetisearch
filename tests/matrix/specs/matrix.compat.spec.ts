import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createCliPost,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under compat legs (bin/matrix-compat): varying WP core + PHP.
// Smoke only: versions match the leg, engine activates, indexes, searches.
test.setTimeout(600_000);

const WANT_WP = process.env.MATRIX_WP ?? '';
const WANT_PHP = process.env.MATRIX_PHP ?? '';

test.describe('Matrix Compat', () => {
  test('versions match the leg', async () => {
    const wpOut = await runCliCommand('core version');
    const wp = wpOut.match(/(\d+\.\d+(\.\d+)?)/)?.[1] ?? '';
    const php = (await runCliCommand('--info')).match(/PHP version:\s*(\S+)/)?.[1] ?? '';
    console.log(`leg wp=${wp} php=${php}`);
    if (WANT_WP !== '') {
      expect(wp.startsWith(WANT_WP)).toBe(true);
    }
    if (WANT_PHP !== '') {
      expect(php.startsWith(WANT_PHP)).toBe(true);
    }
  });

  test('engine activates, indexes and searches', async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);

    const id = await createCliPost('Compat kangaroo', 'Kangaroos hop across compat land.');
    expect(id).toBeGreaterThan(0);
    await runCliCommand('yetisearch reindex');

    const res = await page.request.get(`${SITE_URL}/wp-json/yetisearch/v1/search?q=kangaroo`);
    expect(res.ok()).toBe(true);
    const data = await res.json();
    expect(data.items.map((i: { id: number }) => i.id)).toContain(id);
  });
});
