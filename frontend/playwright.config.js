import { defineConfig } from '@playwright/test';

const dbEnv = {
  APP_ENV: 'local', DB_DATABASE: 'gospel_lms_e2e', FRONTEND_URL: 'http://localhost:5174', APP_URL: 'http://localhost:8001', SANCTUM_STATEFUL_DOMAINS: 'localhost:5174',
  PAYSTACK_SECRET_KEY: 'sk_test_e2e', PAYSTACK_PUBLIC_KEY: 'pk_test_e2e', PAYSTACK_BASE_URL: 'http://localhost:9099', PAYSTACK_MODE: 'test',
  QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log', CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false',
};

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.js',
  globalSetup: './e2e/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  reporter: [['list']],
  use: { baseURL: 'http://localhost:5174', launchOptions: { executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] }, trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: [
    { command: 'node e2e/fake-paystack.mjs', port: 9099, reuseExistingServer: true },
    { command: 'php -S 127.0.0.1:8001 -t public public/index.php', cwd: '../backend', port: 8001, env: { ...dbEnv, PHP_CLI_SERVER_WORKERS: '4' }, reuseExistingServer: false, timeout: 30_000 },
    { command: 'npx vite --port 5174 --strictPort', port: 5174, env: { VITE_API_TARGET: 'http://127.0.0.1:8001' }, reuseExistingServer: false, timeout: 30_000 },
  ],
});
