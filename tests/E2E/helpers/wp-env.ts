import { execSync } from 'child_process';
import { expect, type Page } from '@playwright/test';

const SITE_URL = 'http://localhost:8889';
const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'password';

export function startWpEnv(): void {
  try {
    execSync('npx wp-env start', { stdio: 'inherit', timeout: 120_000 });
  } catch {
    // wp-env may already be running
  }
}

export function stopWpEnv(): void {
  try {
    execSync('npx wp-env stop', { stdio: 'inherit' });
  } catch {
    // ignore
  }
}

export function resetWpEnv(): void {
  try {
    execSync('npx wp-env clean tests', { stdio: 'inherit' });
  } catch {
    // ignore
  }
}

export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-login.php`);
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASSWORD);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**');
}

export async function activatePlugin(page: Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const activateLink = page.locator('tr[data-slug="wp-yetisearch"] .activate a');
  if (await activateLink.isVisible()) {
    await activateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

export async function deactivatePlugin(page: Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  const deactivateLink = page.locator('tr[data-slug="wp-yetisearch"] .deactivate a');
  if (await deactivateLink.isVisible()) {
    await deactivateLink.click();
    await page.waitForLoadState('networkidle');
  }
}

export async function enableSearchEngine(page: Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch`);
  const masterToggle = page.locator('input[name="yetisearch[master_enabled]"]');
  if (!(await masterToggle.isChecked())) {
    await masterToggle.check();
    await page.click('input[type="submit"]');
    await page.waitForLoadState('networkidle');
  }
}

export async function createPost(page: Page, title: string, content: string): Promise<number> {
  // Gutenberg DOM varies across versions; WP-CLI is deterministic and also
  // exercises the real-time indexer (wp_after_insert_post).
  void page;
  return createCliPost(title, content);
}

export async function runCliCommand(command: string): Promise<string> {
  // NOTE: `tests-cli` (not `cli`) targets the :8889 tests environment, the `--`
  // separator is required, and the inner command must start with `wp` —
  // otherwise wp-env tries to exec the subcommand as a binary (exit 127).
  const { exec } = await import('child_process');
  return new Promise((resolve) => {
    exec(`npx wp-env run tests-cli -- wp ${command}`, { timeout: 60_000 }, (error, stdout, stderr) => {
      const out = String(stdout || '') + String(stderr || '');
      resolve(error ? out + String((error as Error).message) : out);
    });
  });
}

export async function createCliPost(title: string, content: string, extra = ''): Promise<number> {
  const out = await runCliCommand(
    `post create --post_title="${title}" --post_content="${content}" --post_status=publish --porcelain ${extra}`
  );
  const match = out.match(/^(\d+)$/m);
  return match ? parseInt(match[1], 10) : 0;
}

async function ensureChecked(page: Page, selector: string): Promise<boolean> {
  const box = page.locator(selector);
  if (!(await box.isChecked())) {
    await box.check();
    return true;
  }
  return false;
}

async function submitSettings(page: Page): Promise<void> {
  await page.click('input[type="submit"]');
  await page.waitForLoadState('networkidle');
}

/** Tick post-type checkboxes on the content tab (multiselect). */
export async function enableContentTypes(page: Page, types: string[]): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=content`);
  let dirty = false;
  for (const type of types) {
    if (await ensureChecked(page, `input[name="yetisearch[indexed_post_types][]"][value="${type}"]`)) {
      dirty = true;
    }
  }
  if (dirty) {
    await submitSettings(page);
  }
}

/** Master facet switch + optional price facet (facets_geo tab). */export async function enableFacets(page: Page, price = false): Promise<void> {
  await page.goto(`${SITE_URL}/wp-admin/admin.php?page=yetisearch&tab=facets_geo`);
  let dirty = await ensureChecked(page, 'input[name="yetisearch[facets_enabled]"]');
  if (price) {
    dirty = (await ensureChecked(page, 'input[name="yetisearch[price_facet_enabled]"]')) || dirty;
    const ranges = page.locator('input[name="yetisearch[price_ranges]"]');
    if ((await ranges.inputValue()) === '') {
      await ranges.fill('100, 500, 1000');
      dirty = true;
    }
  }
  if (dirty) {
    await submitSettings(page);
  }
}

export { SITE_URL, ADMIN_USER, ADMIN_PASSWORD };

/**
 * Collect browser console errors and uncaught page errors for the test to
 * assert on. Attach first in beforeEach so admin + frontend JS is covered.
 */
export function watchConsoleErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() !== 'error') {
      return;
    }
    const text = msg.text();
    const url = msg.location()?.url ?? '';
    // Core admin noise in the offline sandbox (community events, update
    // checks); only our own scripts matter here.
    if (!url.includes('yetisearch') && !/yetisearch/i.test(text)) {
      return;
    }
    errors.push(`${url} :: ${text}`);
  });
  page.on('pageerror', (err) => {
    const stack = err instanceof Error && typeof err.stack === 'string' ? ` :: ${err.stack.split('\n').slice(0, 4).join(' <- ')}` : '';
    errors.push(`pageerror :: ${String(err)}${stack}`);
  });
  page.on('response', (res) => {
    const body = res.request().postData() ?? '';
    if ((res.status() === 403 || res.status() === 500) && body.includes('yetisearch_')) {
      errors.push(`${res.status()} :: ${res.request().method()} ${res.request().url()} :: ${body.slice(0, 160)}`);
    }
  });
  return errors;
}

export function expectNoConsoleErrors(errors: string[]): void {
  expect(errors).toEqual([]);
}

/**
 * Scoped variant: only errors referencing our own assets/endpoints.
 * Used where the theme itself executes raw post content (core assumes
 * kses-cleaned titles; CLI-created fixtures bypass that) — outside our
 * control, while our surface is asserted clean by DOM checks.
 */
export function expectNoOwnConsoleErrors(errors: string[]): void {
  expect(errors.filter((e) => /yetisearch/i.test(e))).toEqual([]);
}
