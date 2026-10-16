import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

// Automated WCAG 2.x A/AA rule checks. These catch a subset of issues (roughly a third);
// keyboard, screen-reader and zoom review remain manual (see docs/ACCESSIBILITY.md).
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function audit(page, label) {
  await page.waitForLoadState('networkidle');
  const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  const summary = violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.length} node(s) e.g. ${v.nodes[0].target.join(' ')}`);
  expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

test.describe('accessibility: public pages', () => {
  for (const path of ['/', '/courses', '/courses/introduction-to-the-gospels', '/login', '/register', '/contact', '/faq', '/pricing', '/verify']) {
    test(`axe ${path}`, async ({ page }) => { await page.goto(path); await audit(page, path); });
  }
});

test.describe('accessibility: signed-in pages', () => {
  test('student', async ({ page }) => {
    await signIn(page, 'student@academy.test');
    for (const path of ['/student', '/student/courses', '/student/billing', '/account']) { await page.goto(path); await audit(page, path); }
  });
  test('lecturer', async ({ page }) => {
    await signIn(page, 'lecturer@academy.test');
    for (const path of ['/lecturer', '/lecturer/courses']) { await page.goto(path); await audit(page, path); }
  });
  test('admin', async ({ page }) => {
    await signIn(page, 'admin@academy.test', undefined, { mfa: true });
    for (const path of ['/admin', '/admin/users', '/admin/finance']) { await page.goto(path); await audit(page, path); }
  });
});
