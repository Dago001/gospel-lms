import { expect, test } from '@playwright/test';
import { signIn } from './helpers.js';

test.describe('staff areas', () => {
  test('lecturer sees their own courses only', async ({ page }) => {
    await signIn(page, 'lecturer@academy.test');
    await expect(page).toHaveURL(/\/lecturer/);
    await page.getByRole('link', { name: 'My courses' }).first().click();
    await expect(page.getByRole('link', { name: /Introduction to the Gospels/ })).toBeVisible();
    await page.goto('/admin');
    await expect(page.getByText(/You do not have access to this/)).toBeVisible();
  });

  test('administrators sign in with two-step verification and manage users', async ({ page }) => {
    await signIn(page, 'admin@academy.test', undefined, { mfa: true });
    await expect(page).toHaveURL(/\/admin/);
    await expect(page.getByRole('heading', { name: 'Administration' })).toBeVisible();
    await page.getByRole('link', { name: 'Users', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Users' })).toBeVisible();
    await expect(page.getByText('Adaeze Okafor').first()).toBeVisible();
    await page.getByRole('searchbox', { name: /Search name or email/ }).fill('lecturer@');
    await expect(page.getByText('Dr Samuel Adeyemi')).toBeVisible();
  });

  test('finance officers reach payments but not roles or users', async ({ page }) => {
    await signIn(page, 'finance@academy.test', undefined, { mfa: true });
    await expect(page.getByRole('link', { name: 'Payments', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Roles & permissions' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Audit log' })).toHaveCount(0);
    await page.goto('/admin/roles');
    await expect(page.getByText(/You do not have access to this/)).toBeVisible();
  });
});
