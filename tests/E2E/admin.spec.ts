import { test, expect } from '@playwright/test';
import { loginAsAdmin, activatePlugin, enableSearchEngine, createCliPost, runCliCommand, watchConsoleErrors, expectNoConsoleErrors, SITE_URL } from './helpers/wp-env';

test.setTimeout(120_000);

test.describe('Admin Settings', () => {
  let consoleErrors: string[];

  test.beforeEach(async ({ page }) => {
    consoleErrors = watchConsoleErrors(page);
    await loginAsAdmin(page);
    await activatePlugin(page);
  });

  test('settings page loads', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await expect(page.locator('h1')).toContainText('WP YetiSearch');
    expectNoConsoleErrors(consoleErrors);
  });

  test('top-level menu entry exists', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/`);
    const entry = page.locator('#adminmenu a[href="admin.php?page=yetisearch"]');
    await expect(entry).toContainText('WP YetiSearch');
    expectNoConsoleErrors(consoleErrors);
  });

  test('overview strip shows status and counts', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await expect(page.locator('#yetisearch-strip')).toBeVisible();
    await expect(page.locator('.yetisearch-strip-docs')).toContainText(/[0-9]+/);
    expectNoConsoleErrors(consoleErrors);
  });

  test('first-run checklist shows on fresh state', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await expect(page.locator('#yetisearch-checklist')).toBeVisible();
    expectNoConsoleErrors(consoleErrors);
  });

  test('maintenance preview searches the index', async ({ page }) => {
    await enableSearchEngine(page);
    await createCliPost('Preview kangaroo', 'Kangaroos hop across preview land.');
    await runCliCommand('yetisearch reindex');

    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=maintenance`);
    await page.fill('#yetisearch-preview-input', 'kangaroo');
    await expect(page.locator('#yetisearch-preview-results .yetisearch-option').first()).toBeVisible({ timeout: 10000 });
    expectNoConsoleErrors(consoleErrors);
  });

  test('master toggle is visible', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const toggle = page.locator('input[name="yetisearch[master_enabled]"]');
    await expect(toggle).toBeVisible();
    expectNoConsoleErrors(consoleErrors);
  });

  test('health check section is visible', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const content = await page.textContent('body');
    expect(content).toContain('Health');
    expectNoConsoleErrors(consoleErrors);
  });

  test('settings tabs are present', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    const tabs = await page.locator('.nav-tab').allTextContents();
    expect(tabs.length).toBeGreaterThanOrEqual(5);
    expectNoConsoleErrors(consoleErrors);
  });

  test('saving settings persists values', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=relevance`);
    const fuzzyToggle = page.locator('input[name="yetisearch[enable_fuzzy]"]');
    const initial = await fuzzyToggle.isChecked();
    if (initial) {
      await fuzzyToggle.uncheck();
    } else {
      await fuzzyToggle.check();
    }
    await page.click('input[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.reload();
    expect(await fuzzyToggle.isChecked()).toBe(!initial);
    expectNoConsoleErrors(consoleErrors);
  });

  test('maintenance tab shows bulk index button', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=maintenance`);
    const content = await page.textContent('body');
    expect(content).toMatch(/index|reindex/i);
    expectNoConsoleErrors(consoleErrors);
  });

  test('API key field is password type', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=semantic`);
    const apiKey = page.locator('input[name="yetisearch[semantic_api_key]"]');
    const type = await apiKey.getAttribute('type');
    expect(type).toBe('password');
    expectNoConsoleErrors(consoleErrors);
  });

  test('API key field does not expose value', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=semantic`);
    const apiKey = page.locator('input[name="yetisearch[semantic_api_key]"]');
    const value = await apiKey.inputValue();
    expect(value).toBe('');
    expectNoConsoleErrors(consoleErrors);
  });

  test('directory check button validates paths', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await page.fill('input[name="yetisearch[db_custom_dir]"]', 'relative/path');
    await page.click('#yetisearch-dircheck');
    await expect(page.locator('#yetisearch-dircheck-result')).toContainText('absolute');
    expectNoConsoleErrors(consoleErrors);
  });

  test('directory check passes for writable directory', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await page.fill('input[name="yetisearch[db_custom_dir]"]', '/tmp');
    await page.click('#yetisearch-dircheck');
    await expect(page.locator('#yetisearch-dircheck-result')).toContainText('usable');
    expectNoConsoleErrors(consoleErrors);
  });

  test('reindex progress is hidden until indexing starts', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=maintenance`);
    await expect(page.locator('#yetisearch-reindex-progress')).toBeHidden();
    expectNoConsoleErrors(consoleErrors);
  });

  test('health table uses status icons', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
    await expect(page.locator('.yetisearch-health .dashicons-yes-alt, .yetisearch-health .dashicons-dismiss').first()).toBeVisible();
    expectNoConsoleErrors(consoleErrors);
  });

  test('dashboard widget shows status', async ({ page }) => {
    await page.goto(`${SITE_URL}/wp-admin/`);
    await expect(page.locator('#yetisearch_status')).toBeVisible();
    expectNoConsoleErrors(consoleErrors);
  });
});
