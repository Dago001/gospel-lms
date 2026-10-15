import { execSync } from 'node:child_process';

const env = { ...process.env, DB_DATABASE: 'gospel_lms_e2e', APP_ENV: 'local' };
const sh = (cmd, opts = {}) => execSync(cmd, { stdio: 'pipe', env, ...opts }).toString();

export default async function globalSetup() {
  try { sh(`su postgres -c "psql -c \\"CREATE DATABASE gospel_lms_e2e OWNER gospel\\""`); } catch { /* already exists */ }
  sh('php artisan migrate:fresh --force', { cwd: '../backend' });
  sh('php artisan db:seed --class=Database\\\\Seeders\\\\E2eSeeder --force', { cwd: '../backend' });
}
