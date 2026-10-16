import crypto from 'node:crypto';
import { execSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { expect } from '@playwright/test';

export const PASSWORD = 'Password-12345';
const E2E_ENV = { ...process.env, DB_DATABASE: 'gospel_lms_e2e', APP_ENV: 'local' };

export function totp(secret = 'JBSWY3DPEHPK3PXP', stepOffset = 0) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret) bits += A.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + stepOffset));
  const h = crypto.createHmac('sha1', key).update(buf).digest();
  const o = h[19] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

/** Runs a PHP snippet against the e2e database (used to simulate clicking the emailed verification link). */
export function artisan(php) {
  return execSync(`php artisan tinker --execute='${php.replace(/'/g, "'\\''")}'`, { cwd: '../backend', env: E2E_ENV, stdio: 'pipe' }).toString();
}

export async function signIn(page, email, password = PASSWORD, { mfa = false } = {}) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  if (mfa) {
    for (let attempt = 0; attempt < 3; attempt++) {       // a code can be refused near a 30-second boundary; try the next step
      await page.getByLabel('Code').fill(await freshTotp());
      await page.getByRole('button', { name: 'Verify' }).click();
      const gone = await page.getByLabel('Code').waitFor({ state: 'detached', timeout: 4000 }).then(() => true, () => false);
      if (gone) break;
    }
  }
  await expect(page).not.toHaveURL(/\/login/);
}

export async function registerStudent(page, name = 'E2E Student') {
  const email = `e2e+${Date.now()}${Math.floor(Math.random() * 1000)}@example.com`;
  await page.goto('/register');
  await page.getByLabel('Full name').fill(name);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel(/^Password/).fill('Str0ng-Passw0rd!');
  await page.getByLabel('Confirm password').fill('Str0ng-Passw0rd!');
  await page.getByLabel(/I agree to the/).check();
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page).toHaveURL(/\/student/);
  return email;
}

export const verifyEmail = (email) => artisan(`App\\Models\\User::where("email","${email}")->first()->markEmailAsVerified(); echo "ok";`);

/** The server rejects a TOTP step that was already used (replay protection) and accepts +/-1 step of drift,
 *  so consecutive staff sign-ins must each use a later step. Tracks the last step across test files. */
const STEP_FILE = '/tmp/gospel-e2e-last-totp-step';
async function freshTotp() {
  for (;;) {
    const now = Math.floor(Date.now() / 30000);
    const last = existsSync(STEP_FILE) ? Number(readFileSync(STEP_FILE, 'utf8')) || 0 : 0;
    const step = Math.max(last + 1, now - 1);
    if (step <= now + 1) { writeFileSync(STEP_FILE, String(step)); return totp('JBSWY3DPEHPK3PXP', step - now); }
    await new Promise((r) => setTimeout(r, 2000));   // all usable steps are spent: wait for the clock to advance
  }
}
