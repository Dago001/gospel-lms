import { expect, test } from '@playwright/test';
import { artisan, signIn } from './helpers.js';

// Every page, at phone widths: nothing may spill past the screen edge, except inside its own horizontal scroller (tables).
const PUBLIC = ['/', '/courses', '/courses/introduction-to-the-gospels', '/lecturers', '/pricing', '/about', '/faq', '/contact', '/support', '/terms', '/privacy', '/refunds', '/verify', '/login', '/register', '/forgot-password', '/does-not-exist'];
const STUDENT = ['/student', '/student/courses', '/student/results', '/student/certificates', '/student/billing', '/student/notifications', '/student/support', '/account'];
const LECTURER = ['/lecturer', '/lecturer/courses', '/lecturer/notifications'];
const TABS = ['details', 'curriculum', 'assignments', 'banks', 'assessments', 'live', 'announcements', 'students', 'analytics'];
const ADMIN = ['/admin', '/admin/users', '/admin/roles', '/admin/courses', '/admin/categories', '/admin/enrolments', '/admin/certificates', '/admin/reports', '/admin/finance', '/admin/finance/orders', '/admin/finance/refunds', '/admin/finance/coupons', '/admin/finance/exceptions', '/admin/finance/webhooks', '/admin/finance/reports', '/admin/support', '/admin/notifications', '/admin/audit', '/admin/system'];

async function offenders(page) {
  return page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const out = [];
    const clipped = (el) => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') return true; } return false; };
    for (const el of document.body.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      const st = getComputedStyle(el);
      if (st.position === 'fixed' || st.visibility === 'hidden' || st.display === 'none') continue;
      if (r.right > vw + 1 && !clipped(el)) out.push(`${el.tagName.toLowerCase()}.${String(el.className).slice(0, 40)} right=${Math.round(r.right)}`);
      if (out.length > 4) break;
    }
    return { scroll: document.documentElement.scrollWidth - vw, out };
  });
}
async function check(page, path) {
  await page.goto(path); await page.waitForLoadState('networkidle'); await page.waitForTimeout(250);
  const r = await offenders(page);
  expect(r.scroll, `${path} page scrolls sideways by ${r.scroll}px; wide elements: ${r.out.join(' | ')}`).toBeLessThanOrEqual(0);
  expect(r.out, `${path} has elements past the screen edge`).toEqual([]);
}

for (const width of [360, 390]) {
  test.describe(`phone ${width}px`, () => {
    test.use({ viewport: { width, height: 780 }, hasTouch: true });
    test('public pages', async ({ page }) => { for (const p of PUBLIC) await check(page, p); });
    test('student portal', async ({ page }) => {
      await signIn(page, 'student@academy.test');
      const ids = artisan("$c=App\\Models\\Course::where('slug','introduction-to-the-gospels')->first(); echo 'IDS:'.$c->id.':'.$c->lessons()->orderBy('position')->first()->id;").match(/IDS:([^:]+):(\S+)/);
      await page.goto('/courses/introduction-to-the-gospels');
      const enrol = page.getByRole('button', { name: 'Enrol for free' });
      if (await enrol.count()) await enrol.click();
      for (const p of [...STUDENT, `/student/courses/${ids[1]}`, `/student/courses/${ids[1]}/lessons/${ids[2]}`]) await check(page, p);
    });
    test('lecturer portal', async ({ page }) => {
      await signIn(page, 'lecturer@academy.test');
      const id = artisan("echo 'ID:'.App\\Models\\Course::where('slug','introduction-to-the-gospels')->value('id');").match(/ID:(\S+)/)[1];
      for (const p of [...LECTURER, ...TABS.map((t) => `/lecturer/courses/${id}?tab=${t}`)]) await check(page, p);
    });
    test('admin and finance portal', async ({ page }) => {
      await signIn(page, 'admin@academy.test', undefined, { mfa: true });
      for (const p of ADMIN) await check(page, p);
    });
  });
}
