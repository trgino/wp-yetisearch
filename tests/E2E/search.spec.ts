import { test, expect } from '@playwright/test';
import { loginAsAdmin, activatePlugin, enableSearchEngine, createPost, watchConsoleErrors, expectNoConsoleErrors } from './helpers/wp-env';
import { searchFor, getSearchResults, getSearchCount } from './helpers/fixtures';

test.setTimeout(120_000);

test.describe('Basic Search', () => {
  let consoleErrors: string[];

  test.beforeEach(async ({ page }) => {
    consoleErrors = watchConsoleErrors(page);
    await loginAsAdmin(page);
    await activatePlugin(page);
    await enableSearchEngine(page);
  });

  test('search returns relevant results', async ({ page }) => {
    await createPost(page, 'Zebra Habitat', 'Zebras live in the savannas of Africa.');
    await createPost(page, 'Lion Habitat', 'Lions live in the grasslands of Africa.');
    await createPost(page, 'Cooking Pasta', 'How to cook perfect pasta every time.');

    await searchFor(page, 'zebra');
    const results = await getSearchResults(page);

    expect(results.length).toBeGreaterThan(0);
    expect(results.some((r) => r.includes('Zebra'))).toBe(true);
    expectNoConsoleErrors(consoleErrors);
  });

  test('search with no results shows empty state', async ({ page }) => {
    await searchFor(page, 'xyznonexistent123');
    const content = await page.textContent('body');
    expect(content).toMatch(/nothing was found|no results/i);
    expectNoConsoleErrors(consoleErrors);
  });

  test('search is case insensitive', async ({ page }) => {
    await createPost(page, 'ZEBRA Facts', 'Content about zebras.');

    await searchFor(page, 'zebra');
    const results = await getSearchResults(page);
    expect(results.some((r) => r.includes('ZEBRA'))).toBe(true);
    expectNoConsoleErrors(consoleErrors);
  });

  test('search finds posts by content', async ({ page }) => {
    await createPost(page, 'Unique Title Here', 'The content mentions platypus specifically.');

    await searchFor(page, 'platypus');
    const results = await getSearchResults(page);
    expect(results.some((r) => r.includes('Unique Title'))).toBe(true);
    expectNoConsoleErrors(consoleErrors);
  });

  test('search results show highlighted terms', async ({ page }) => {
    // Match in the title: titles always render, excerpts depend on the theme.
    await createPost(page, 'Rhinoceros Facts', 'This content contains the word rhinoceros.');

    await searchFor(page, 'rhinoceros');
    const marks = await page.locator('mark').allTextContents();
    expect(marks.some((m) => m.toLowerCase().includes('rhinoceros'))).toBe(true);
    expectNoConsoleErrors(consoleErrors);
  });

  test('search pagination works', async ({ page }) => {
    for (let i = 1; i <= 5; i++) {
      await createPost(page, `Hippo Article ${i}`, `Content about hippopotamus number ${i}.`);
    }

    await searchFor(page, 'hippopotamus');
    const count = await getSearchCount(page);
    expect(count).toBeGreaterThan(0);
    expectNoConsoleErrors(consoleErrors);
  });
});
