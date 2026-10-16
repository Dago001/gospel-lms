import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

const WIDTHS = { mobile: 390, tablet: 768, laptop: 1366, large: 1920 };

for (const [name, width] of Object.entries(WIDTHS)) {
  test.describe(`${name} (${width}px)`, () => {
    test.use({ viewport: { width, height: 900 } });

    for (const path of ['/', '/courses', '/courses/introduction-to-the-gospels', '/login', '/register', '/contact', '/faq', '/pricing']) {
      test(`no horizontal overflow on ${path}`, async ({ page }) => {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);
      });
    }

    test('student portal fits the screen', async ({ page }) => {
      await signIn(page, 'student@academy.test');
      for (const p of ['/student', '/student/courses', '/student/billing', '/student/results', '/account']) {
        await page.goto(p);
        await page.waitForLoadState('networkidle');
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
      }
    });
  });
}

test.describe('mobile navigation', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('public menu opens and closes with the keyboard-accessible toggle', async ({ page }) => {
    await page.goto('/');
    const toggle = page.getByRole('button', { name: 'Menu' });
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Courses' }).click();
    await expect(page).toHaveURL(/\/courses/);
  });

  test('student sidebar becomes a drawer on a phone', async ({ page }) => {
    await signIn(page, 'student@academy.test');
    await expect(page.getByRole('link', { name: 'Payments & receipts' })).not.toBeInViewport();
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.getByRole('link', { name: 'Payments & receipts' })).toBeInViewport();
    await page.getByRole('link', { name: 'Payments & receipts' }).click();
    await expect(page).toHaveURL(/\/student\/billing/);
  });
});
