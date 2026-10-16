import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

// Clicks every sidebar link for each role and fails on: a wrong URL, a missing heading, a visible error state, a JS error,
// or any API response with a 4xx/5xx status.
async function walk(page, label) {
  const problems = [];
  page.on('pageerror', (e) => problems.push(`JS error: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') problems.push(`console: ${m.text().slice(0, 160)}`); });
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^.*\/api\/v1/, '')}`); });

  await page.waitForLoadState('networkidle');
  await page.locator('.sidebar a.nav-link').first().waitFor();
  const links = await page.locator('.sidebar a.nav-link').evaluateAll((as) => as.map((a) => ({ href: a.getAttribute('href'), text: a.textContent.trim() })));
  expect(links.length, `${label}: sidebar has links`).toBeGreaterThan(3);
  const seen = [];
  for (const l of links) {
    problems.length = 0;
    await page.locator(`.sidebar a.nav-link[href="${l.href}"]`).first().click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(200);
    const where = `${label} → ${l.text} (${l.href})`;
    expect(new URL(page.url()).pathname, where).toBe(l.href.split('?')[0]);
    await expect(page.locator('main h1').first(), `${where}: heading`).toBeVisible();
    const errs = await page.locator('main [role="alert"].alert-error, main .error-state').allTextContents();
    expect(errs, `${where}: error state shown`).toEqual([]);
    expect(problems, `${where}: runtime problems`).toEqual([]);
    seen.push(l.text);
  }
  return seen;
}

test('student sidebar: every menu works', async ({ page }) => {
  await signIn(page, 'student@academy.test');
  const seen = await walk(page, 'student');
  expect(seen).toEqual(expect.arrayContaining(['Dashboard', 'My courses', 'Results', 'Certificates', 'Payments & receipts', 'Notifications', 'Support']));
});

test('lecturer sidebar: every menu works', async ({ page }) => {
  await signIn(page, 'lecturer@academy.test');
  const seen = await walk(page, 'lecturer');
  expect(seen).toEqual(expect.arrayContaining(['Dashboard', 'My courses']));
});

test('administrator sidebar: every menu works and nothing is missing', async ({ page }) => {
  await signIn(page, 'admin@academy.test', undefined, { mfa: true });
  const seen = await walk(page, 'admin');
  expect(seen).toEqual(expect.arrayContaining(['Dashboard', 'Users', 'Roles & permissions', 'Enrolments', 'Courses', 'Categories', 'Certificates', 'Payments', 'Orders', 'Refunds', 'Coupons', 'Support desk', 'Audit log']));
});

test('finance sidebar: every menu works', async ({ page }) => {
  await signIn(page, 'finance@academy.test', undefined, { mfa: true });
  const seen = await walk(page, 'finance');
  expect(seen).toEqual(expect.arrayContaining(['Payments', 'Orders', 'Refunds']));
});
