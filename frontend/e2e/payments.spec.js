import { expect, test } from '@playwright/test';
import { registerStudent, verifyEmail } from './helpers.js';

test.describe('paid enrolment through the (fake) Paystack gateway', () => {
  test('checkout -> hosted payment -> server verification -> access and receipt', async ({ page }) => {
    const email = await registerStudent(page, 'Paying Student');
    verifyEmail(email);
    await page.goto('/courses/foundations-of-discipleship');
    await expect(page.getByRole('button', { name: /Pay ₦25,000\.00/ })).toBeVisible();
    await page.getByRole('button', { name: /Pay ₦25,000\.00/ }).click();

    await expect(page).toHaveURL(/(localhost|127\.0\.0\.1):9099\/pay\//);                       // redirected to the hosted gateway
    await expect(page.getByText('NGN 25000.00')).toBeVisible();
    await page.locator('#pay').click();

    await expect(page).toHaveURL(/\/checkout\/callback\?reference=/);
    await expect(page.getByRole('heading', { name: 'Payment confirmed' })).toBeVisible({ timeout: 15_000 });
    await page.getByRole('link', { name: 'Go to my courses' }).click();
    await expect(page.getByRole('link', { name: 'Foundations of Discipleship' })).toBeVisible();

    await page.goto('/student/billing');
    await expect(page.getByText('Paid', { exact: true })).toBeVisible();
    const href = await page.getByRole('link', { name: 'Receipt (PDF)' }).getAttribute('href');
    const res = await page.request.get(href, { headers: { referer: page.url() } });
    expect(res.status()).toBe(200);
    expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
  });

  test('a declined payment grants nothing', async ({ page }) => {
    const email = await registerStudent(page, 'Declined Student');
    verifyEmail(email);
    await page.goto('/courses/foundations-of-discipleship');
    await page.getByRole('button', { name: /Pay ₦/ }).click();
    await page.locator('#decline').click();
    await expect(page.getByRole('heading', { name: 'Payment did not go through' })).toBeVisible({ timeout: 15_000 });
    await page.goto('/student/courses');
    await expect(page.getByText('Foundations of Discipleship')).toHaveCount(0);
  });

  test('forging the callback URL cannot unlock a course', async ({ page }) => {
    const email = await registerStudent(page, 'Forger');
    verifyEmail(email);
    await page.goto('/checkout/callback?reference=GLFORGED12345&trxref=GLFORGED12345');
    await expect(page.getByText('Payment not found')).toBeVisible();
    await page.goto('/student/courses');
    await expect(page.getByText('Foundations of Discipleship')).toHaveCount(0);
  });
});
