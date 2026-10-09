import type { Page } from '@playwright/test';
import { SITE_URL } from './wp-env';

export interface TestPost {
  id: number;
  title: string;
  content: string;
}

export const SAMPLE_POSTS: TestPost[] = [
  { id: 0, title: 'Zebra Facts', content: 'Zebras are African equines with distinctive black and white striped coats.' },
  { id: 0, title: 'Lion Facts', content: 'Lions are large cats native to Africa and India.' },
  { id: 0, title: 'Elephant Facts', content: 'Elephants are the largest existing land animals.' },
  { id: 0, title: 'Giraffe Facts', content: 'Giraffes are the tallest terrestrial animals.' },
  { id: 0, title: 'Penguin Facts', content: 'Penguins are a group of aquatic flightless birds.' },
];

export async function createSamplePosts(page: Page): Promise<TestPost[]> {
  const { createPost } = await import('./wp-env');
  const posts: TestPost[] = [];
  for (const post of SAMPLE_POSTS) {
    const id = await createPost(page, post.title, post.content);
    posts.push({ ...post, id });
  }
  return posts;
}

export async function searchFor(page: Page, term: string): Promise<void> {
  await page.goto(SITE_URL);
  await page.fill('input[name="s"]', term);
  await page.press('input[name="s"]', 'Enter');
  await page.waitForLoadState('networkidle');
}

export async function getSearchResults(page: Page): Promise<string[]> {
  // Twenty Twenty-Five uses .wp-block-post-title; classic themes use .entry-title.
  return page.locator('.wp-block-post-title a, .entry-title a').allTextContents();
}

export async function getSearchCount(page: Page): Promise<number> {
  const results = await getSearchResults(page);
  return results.length;
}
