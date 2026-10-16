import { expect, test } from '@playwright/test';
import { artisan, registerStudent, verifyEmail } from './helpers.js';

test('the whole student journey: apply online, pay, study, sit the final exam, receive and verify the certificate', async ({ page }) => {
  // 1. Apply online (register) and verify the email address
  const email = await registerStudent(page, 'Grace Adeyemi');
  verifyEmail(email);

  // 2. Pay for the course through the (fake) Paystack gateway
  await page.goto('/courses/e2e-paid-course');
  await page.getByRole('button', { name: /Pay ₦25,000\.00/ }).click();
  await expect(page).toHaveURL(/(localhost|127\.0\.0\.1):9099\/pay\//);
  await page.locator('#pay').click();
  await expect(page.getByRole('heading', { name: 'Payment confirmed' })).toBeVisible({ timeout: 15_000 });

  // 3. No certificate yet: nothing has been studied or examined
  await page.goto('/student/certificates');
  await expect(page.getByText(/no certificates yet|not earned/i).first()).toBeVisible();

  // 4. Study: complete the required lesson
  await page.goto('/student/courses');
  await page.getByRole('link', { name: 'E2E Paid Course' }).click();
  await page.getByRole('tab', { name: 'Lessons' }).click();
  await page.locator('a[href*="/lessons/"]').first().click();
  await page.getByRole('button', { name: 'Mark lesson as complete' }).click();
  await expect(page.getByText('Lesson complete').first()).toBeVisible();

  // 5. Still no certificate: the final exam is required
  await page.goto('/student/certificates');
  await expect(page.locator('a[href*="/certificates/"][href$="/pdf"], a:has-text("Download")')).toHaveCount(0);

  // 6. Sit and pass the final examination
  await page.goto('/student/courses');
  await page.getByRole('link', { name: 'E2E Paid Course' }).click();
  await page.getByRole('tab', { name: 'Assessments' }).click();
  await page.getByRole('button', { name: 'Start' }).click();
  await page.getByLabel('Luke').check();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByLabel('Your answer').fill('John');
  await expect(page.getByText('Saved')).toBeVisible();
  await page.getByRole('button', { name: 'Review and submit' }).click();
  await page.getByRole('button', { name: 'Submit now' }).click();
  await expect(page.getByText('Passed')).toBeVisible();

  // 7. The certificate is issued by the server and carries the student's own details
  await page.goto('/student/certificates');
  await expect(page.getByText('Grace Adeyemi').first()).toBeVisible();
  await expect(page.getByText('E2E Paid Course').first()).toBeVisible();
  const dl = page.getByRole('link', { name: /Download/ }).first();
  const href = await dl.getAttribute('href');
  const pdf = await page.request.get(href, { headers: { referer: page.url() } });
  expect(pdf.status()).toBe(200);
  expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');

  // 8. Anyone can verify it publicly
  const code = artisan(`echo "CODE:".App\\Models\\Certificate::latest("issued_at")->value("verification_code");`).match(/CODE:(\S+)/)[1];
  await page.goto(`/verify/${code}`);
  await expect(page.getByText('Grace Adeyemi')).toBeVisible();
  await expect(page.getByText(/valid/i).first()).toBeVisible();
});
