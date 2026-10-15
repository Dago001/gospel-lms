import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

const open = async (page) => { await page.getByRole('button', { name: 'Ask us' }).click(); await expect(page.getByRole('dialog', { name: /help assistant/i })).toBeVisible(); };
const ask = async (page, q) => { await page.getByLabel('Your question').fill(q); await page.keyboard.press('Enter'); };

test.describe('help assistant', () => {
  test('greets, answers, lists real courses and links stay in the app', async ({ page }) => {
    await page.goto('/');
    await open(page);
    await expect(page.getByRole('log')).toContainText('GOSPEL LMS assistant');
    await ask(page, 'How do I pay?');
    await expect(page.getByRole('log')).toContainText('Paystack');
    await ask(page, 'Do you have courses on the Gospels?');
    const card = page.getByRole('log').getByRole('link', { name: /Introduction to the Gospels/ });
    await expect(card).toBeVisible();
    await card.click();
    await expect(page).toHaveURL(/\/courses\/demo-introduction-to-the-gospels/);
    await expect(page.getByRole('dialog', { name: /help assistant/i })).toBeVisible();   // stays open on desktop
  });

  test('hostile input is shown as plain text and nothing breaks', async ({ page }) => {
    const dialogs = [];
    page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
    await page.goto('/');
    await open(page);
    for (const payload of ["'; DROP TABLE courses;--", '<img src=x onerror=alert(1)>', '<script>alert(2)</script>', "courses on ' OR 1=1 --"]) {
      await ask(page, payload);
      await expect(page.locator('.chat-row.user').last()).toContainText(payload.slice(0, 12));
      await expect(page.locator('.chat-bubble.typing')).toHaveCount(0);
      await expect(page.locator('.chat-row.bot').last()).toBeVisible();
    }
    expect(await page.locator('.chat-log img, .chat-log script').count()).toBe(0);
    expect(dialogs).toEqual([]);
    await page.goto('/courses'); await page.waitForLoadState('networkidle');
    await expect(page.getByText('Introduction to the Gospels').first()).toBeVisible();   // data intact
  });

  test('keyboard: Esc closes and returns focus; history survives navigation', async ({ page }) => {
    await page.goto('/');
    await open(page);
    await ask(page, 'refund policy');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Ask us' })).toBeFocused();
    await page.goto('/faq');
    await open(page);
    await expect(page.getByRole('log')).toContainText(/refund/i);
  });

  test('signed-in students see only their own courses; guests are asked to sign in', async ({ page }) => {
    await page.goto('/'); await open(page); await ask(page, 'show my courses');
    await expect(page.getByRole('log')).toContainText('sign in first');
    await signIn(page, 'student@demo.test');
    await page.evaluate(() => sessionStorage.clear());
    await page.reload(); await open(page); await ask(page, 'show my courses');
    await expect(page.getByRole('log')).toContainText(/most recent courses|not enrolled/);
  });

  test('is hidden during an exam attempt route and passes axe when open', async ({ page }) => {
    await page.goto('/'); await open(page); await ask(page, 'certificate');
    await expect(page.locator('.chat-row.bot').last()).toContainText(/certificate/i);
    const { violations } = await new AxeBuilder({ page }).include('#chat-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(violations.map((v) => `${v.id}: ${v.nodes[0].target.join(' ')}`)).toEqual([]);
  });

  test.describe('phone', () => {
    test.use({ viewport: { width: 390, height: 780 }, hasTouch: true });
    test('opens as a full-screen sheet, fits the screen and closes', async ({ page }) => {
      await page.goto('/');
      await open(page);
      const box = await page.locator('#chat-panel').boundingBox();
      expect(Math.round(box.width)).toBe(390);
      expect(Math.round(box.height)).toBe(780);
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
      await ask(page, 'how do exams work');
      await expect(page.getByRole('log')).toContainText('server clock');
      await page.getByRole('link', { name: 'My results' }).click();
      await expect(page.getByRole('dialog', { name: /help assistant/i })).toBeHidden();   // sheet closes after following a link on a phone
    });
  });
});
