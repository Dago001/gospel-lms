import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

test.describe('certificate template (admin)', () => {
  test('upload artwork, reject a bad file, adjust the layout, preview the PDF', async ({ page }) => {
    await signIn(page, 'admin@demo.test', undefined, { mfa: true });
    await page.goto('/admin/certificates');
    await page.getByRole('tab', { name: 'Template & layout' }).click();
    await expect(page.getByText('No template uploaded yet')).toBeVisible();

    // a file that is not an image is refused by the server
    await page.locator('input[type=file]').setInputFiles({ name: 'evil.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1; ?>') });
    await page.getByRole('button', { name: 'Upload template' }).click();
    await expect(page.getByText(/must be a PNG or JPG/i)).toBeVisible();
    await expect(page.getByText('No template uploaded yet')).toBeVisible();

    // the real artwork is accepted
    await page.locator('input[type=file]').setInputFiles('../docs/samples/certificate-template-sample.png');
    await page.getByRole('button', { name: 'Upload template' }).click();
    await expect(page.getByText(/Current template: 3508 × 2480 px/)).toBeVisible();
    await expect(page.locator('.tpl-stage')).toHaveCSS('background-image', /blob:/);
    await expect(page.locator('.tpl-item').filter({ hasText: 'Sample Student Name' })).toBeVisible();

    // move the student name and save
    await page.getByLabel('Vertical position (%)').fill('44');
    await page.getByLabel('Text size (pt)').fill('36');
    await page.getByRole('button', { name: 'Save layout' }).click();
    await expect(page.getByText(/Layout saved/)).toBeVisible();
    await page.reload();
    await page.getByRole('tab', { name: 'Template & layout' }).click();
    await expect(page.getByLabel('Vertical position (%)')).toHaveValue('44');

    // keyboard nudging works
    await page.locator('.tpl-item').filter({ hasText: 'Sample Student Name' }).focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByLabel('Vertical position (%)')).toHaveValue('44.5');

    // the PDF preview is a real PDF
    const href = await page.getByRole('link', { name: 'Preview as PDF' }).getAttribute('href');
    const res = await page.request.get(href, { headers: { referer: page.url() } });
    expect(res.status()).toBe(200);
    expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
  });

  test('students and lecturers cannot reach the template tools', async ({ page }) => {
    await signIn(page, 'student@demo.test');
    const r = await page.request.get('/api/v1/admin/certificate-template', { headers: { referer: page.url() } });
    expect(r.status()).toBe(403);
    await page.goto('/admin/certificates');
    await expect(page.getByText('You do not have access to this page')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Template & layout' })).toHaveCount(0);
  });
});
