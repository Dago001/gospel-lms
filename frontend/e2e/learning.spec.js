import { expect, test } from '@playwright/test';
import { artisan, PASSWORD, registerStudent, signIn, verifyEmail } from './helpers.js';

test.describe('public site', () => {
  test('home, search and course detail use real catalogue data', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Study the Scriptures');
    await page.getByRole('searchbox', { name: 'Find a course' }).fill('gospels');
    await page.getByRole('button', { name: 'Search' }).click();
    await expect(page).toHaveURL(/\/courses\?q=gospels/);
    await page.getByRole('link', { name: /Introduction to the Gospels/ }).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Introduction to the Gospels');
    await expect(page.getByText('Free preview')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create an account to enrol' })).toBeVisible();
  });

  test('unknown certificate codes are reported as not found', async ({ page }) => {
    await page.goto('/verify');
    await page.getByLabel('Verification code').fill('a'.repeat(32));
    await page.getByRole('button', { name: 'Verify' }).click();
    await expect(page.getByText('No certificate found')).toBeVisible();
  });
});

test.describe('accounts and access control', () => {
  test('wrong password shows a helpful error and signs nobody in', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('student@academy.test');
    await page.getByLabel('Password').fill('nope-nope-nope');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText(/do not match/i);
    await expect(page).toHaveURL(/\/login/);
  });

  test('protected pages send guests to sign in and return them afterwards', async ({ page }) => {
    await page.goto('/student/courses');
    await expect(page).toHaveURL(/\/login\?next=%2Fstudent%2Fcourses/);
    await page.getByLabel('Email').fill('student@academy.test');
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/student\/courses/);
  });

  test('a student cannot open the admin or lecturer areas', async ({ page }) => {
    await signIn(page, 'student@academy.test');
    await page.goto('/admin');
    await expect(page.getByText('You do not have access to this page')).toBeVisible();
    await page.goto('/lecturer');
    await expect(page.getByText('You do not have access to this page')).toBeVisible();
  });

  test('a crafted ?next= cannot redirect off-site', async ({ page }) => {
    await page.goto('/login?next=//evil.example/x');
    await page.getByLabel('Email').fill('student@academy.test');
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/localhost:5174\/student/);
  });

  test('registration, then enrolment is blocked until the email is verified', async ({ page }) => {
    const email = await registerStudent(page);
    await expect(page.getByText('Verify your email address')).toBeVisible();
    await page.goto('/courses/introduction-to-the-gospels');
    await page.getByRole('button', { name: 'Enrol for free' }).click();
    await expect(page.getByText(/verify your email/i).first()).toBeVisible();
    verifyEmail(email);
    await page.reload();
    await page.getByRole('button', { name: 'Enrol for free' }).click();
    await expect(page).toHaveURL(/\/student\/courses\//);
  });
});

test.describe('learning', () => {
  test('lessons unlock in order and completion is decided by the server', async ({ page }) => {
    const email = await registerStudent(page, 'Lesson Learner');
    verifyEmail(email);
    await page.goto('/courses/introduction-to-the-gospels');
    await page.getByRole('button', { name: 'Enrol for free' }).click();
    await expect(page).toHaveURL(/\/student\/courses\//);

    await page.getByRole('tab', { name: 'Lessons' }).click();
    await expect(page.getByText('The world of the first century')).toBeVisible();
    await expect(page.getByText('(locked)')).toBeVisible();                       // lesson 2 is locked
    await page.getByRole('link', { name: /Why four Gospels/ }).click();
    await page.getByRole('button', { name: 'Mark lesson as complete' }).click();
    await expect(page.getByText('Lesson complete').first()).toBeVisible();
    await page.getByRole('link', { name: /Next lesson/ }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('The world of the first century');
  });

  test('a timed quiz autosaves answers, survives a reload, submits and shows the result', async ({ page }) => {
    const email = await registerStudent(page, 'Quiz Taker');
    verifyEmail(email);
    await page.goto('/courses/introduction-to-the-gospels');
    await page.getByRole('button', { name: 'Enrol for free' }).click();
    await page.getByRole('tab', { name: 'Assessments' }).click();
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page.getByRole('heading', { name: /Question 1 of 2/ })).toBeVisible();
    await expect(page.getByRole('timer')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('is_correct');          // answer key never reaches the page

    await page.getByLabel('Luke').check();
    await expect(page.getByText('Saved')).toBeVisible();
    await page.reload();                                                          // connection drop / refresh mid-exam
    await expect(page.getByLabel('Luke')).toBeChecked();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByLabel('Your answer').fill('Gospel of John');
    await expect(page.getByText('Saved')).toBeVisible();
    await page.getByRole('button', { name: 'Review and submit' }).click();
    await page.getByRole('button', { name: 'Submit now' }).click();
    await expect(page.getByText('Your result')).toBeVisible();
    await expect(page.getByText('Passed')).toBeVisible();
    await expect(page.getByText(/10 out of 10/)).toBeVisible();
  });
});
