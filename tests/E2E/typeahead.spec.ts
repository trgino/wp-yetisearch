import { test, expect } from '@playwright/test';
import { loginAsAdmin, activatePlugin, enableSearchEngine, createPost, runCliCommand, watchConsoleErrors, expectNoConsoleErrors, expectNoOwnConsoleErrors, SITE_URL } from './helpers/wp-env';

test.setTimeout(120_000);

test.describe('Typeahead', () => {
  let consoleErrors: string[];

  test.beforeEach(async ({ page }) => {
    consoleErrors = watchConsoleErrors(page);
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
    await createPost(page, 'Cheetah Speed', 'Cheetahs are the fastest land animals.');
    await createPost(page, 'Cheetah Habitat', 'Cheetahs live in African savannas.');
    await createPost(page, 'Cheetah Diet', 'Cheetahs hunt gazelles and impalas.');
  });

  test('typeahead shows suggestions while typing', async ({ page }) => {
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'cheetah');

    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });
    const suggestions = await page.locator('.yetisearch-option').allTextContents();
    expect(suggestions.length).toBeGreaterThan(0);
    expectNoConsoleErrors(consoleErrors);
  });

  test('typeahead suggestion navigates with keyboard', async ({ page }) => {
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'cheetah');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });

    await page.keyboard.press('ArrowDown');
    const selected = await page.locator('.yetisearch-option[aria-selected="true"]').count();
    expect(selected).toBe(1);
    expectNoConsoleErrors(consoleErrors);
  });

  test('typeahead Enter navigates to result', async ({ page }) => {
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'cheetah');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');
    // Pretty permalinks yield /slug/, plain yield ?p=ID — either way we leave the search page.
    expect(page.url()).not.toContain('?s=cheetah');
    expect(page.url()).not.toBe(`${SITE_URL}/`);
    expectNoConsoleErrors(consoleErrors);
  });

  test('typeahead Escape closes suggestions', async ({ page }) => {
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'cheetah');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });

    await page.keyboard.press('Escape');
    const isHidden = await page.locator('.yetisearch-listbox').evaluate((el) => (el as HTMLElement).hidden);
    expect(isHidden).toBe(true);
    expectNoConsoleErrors(consoleErrors);
  });

  test('typeahead does not execute script tags', async ({ page }) => {
    const xssId = await createPost(page, '<script>alert("xss")</script>Safe Title', 'Content about armadillos.');

    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'armadillo');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });

    const html = await page.locator('.yetisearch-listbox').innerHTML();
    expect(html).not.toContain('<script>');

    // Cleanup: the raw title would otherwise execute in the theme's own
    // front-page loop (core assumes kses-cleaned titles; CLI bypasses that)
    // and pollute later tests' console assertions. Our surfaces stay clean.
    await runCliCommand(`post delete ${xssId} --force`);
    // The theme's inline execution is out of scope; our listbox (asserted
    // script-free above) and our assets/endpoints must stay silent.
    expectNoOwnConsoleErrors(consoleErrors);
  });

  test('typeahead shows no results message', async ({ page }) => {
    await page.goto(SITE_URL);
    await page.fill('input[name="s"]', 'xyznonexistent123');
    await page.waitForSelector('.yetisearch-listbox:not([hidden])', { timeout: 5000 });

    const empty = await page.locator('.yetisearch-empty').textContent();
    expect(empty).toContain('No results found');
    expectNoConsoleErrors(consoleErrors);
  });
});
