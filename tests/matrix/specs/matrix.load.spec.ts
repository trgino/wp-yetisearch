import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createCliPost,
  runCliCommand,
  SITE_URL,
} from '../../E2E/helpers/wp-env';

// Runs under the `load` matrix leg. Seeds 300 generated posts, measures a
// full reindex, then asserts search still behaves. Duration is recorded,
// not asserted (CI machines vary).
test.setTimeout(600_000);

test.describe('Matrix Load', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('300 generated posts reindex and stay searchable', async ({ page }) => {
    await runCliCommand('post generate --count=300 --post_status=publish');
    const count = await runCliCommand('post list --post_status=publish --format=count');
    expect(Number(count.match(/(\d+)/)?.[1] ?? 0)).toBeGreaterThanOrEqual(300);

    const started = Date.now();
    const reindexOut = await runCliCommand('yetisearch reindex');
    const elapsedSec = (Date.now() - started) / 1000;
    console.log(`reindex 300 posts: ${elapsedSec.toFixed(1)}s`);
    expect(reindexOut).toMatch(/indexed|success|reindex/i);

    const marker = await createCliPost('Zanzibar marker post', 'Uniquely findable marker content zzzqxj.');
    await runCliCommand('yetisearch reindex');

    const res = await page.request.get(
      `${SITE_URL}/wp-json/yetisearch/v1/search?q=zzzqxj`
    );
    expect(res.ok()).toBe(true);
    const data = await res.json();
    expect(data.items.map((i: { id: number }) => i.id)).toContain(marker);
  });
});
