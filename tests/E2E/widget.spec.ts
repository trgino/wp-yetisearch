import { test, expect } from '@playwright/test';
import {
  loginAsAdmin,
  activatePlugin,
  enableSearchEngine,
  createCliPost,
  runCliCommand,
  watchConsoleErrors,
  expectNoConsoleErrors,
  SITE_URL,
} from './helpers/wp-env';

test.setTimeout(180_000);

test.describe('Search Widget', () => {
  let consoleErrors: string[];

  test.beforeEach(async ({ page }) => {
    consoleErrors = watchConsoleErrors(page);
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('widget renders an escaped search form in real WordPress', async () => {
    const out = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/E2E/helpers/render-widget.php'
    );
    expect(out).toContain('MATRIX_WIDGET FORM-OK,ESC-OK,NOTAG-OK,ROLE-OK');
  });

  test('block renders an escaped search form in real WordPress', async () => {
    const out = await runCliCommand(
      'eval-file /var/www/html/wp-content/plugins/wp-yetisearch/tests/E2E/helpers/render-block.php'
    );
    expect(out).toContain('MATRIX_BLOCK FORM-OK,ESC-OK,ROLE-OK');
  });

  test('widget input gets live typeahead suggestions', async ({ page }) => {
    const id = await createCliPost('Widget wombat', 'Wombats waddle through widget land.');
    expect(id).toBeGreaterThan(0);
    await runCliCommand('yetisearch reindex');

    // The widget uses the same input[name="s"] the typeahead binds to.
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'wombat');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 15000 });
    const suggestions = await page.locator('.yetisearch-option').allTextContents();
    expect(suggestions.length).toBeGreaterThan(0);
    expectNoConsoleErrors(consoleErrors);
  });
});
