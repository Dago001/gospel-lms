import { execSync } from 'node:child_process';

const env = { ...process.env, DB_DATABASE: 'gospel_lms_e2e', APP_ENV: 'local' };
const sh = (cmd, opts = {}) => execSync(cmd, { stdio: 'pipe', env, ...opts }).toString();

export default async function globalSetup() {
  // Creates the throw-away e2e database. Override with E2E_CREATE_DB_CMD where `su postgres` is not available (e.g. CI: `PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -c "CREATE DATABASE gospel_lms_e2e"`).
  const create = process.env.E2E_CREATE_DB_CMD || `su postgres -c "psql -c \\"CREATE DATABASE gospel_lms_e2e OWNER gospel\\""`;
  try { sh(create); } catch { /* already exists */ }
  sh('php artisan migrate:fresh --force', { cwd: '../backend' });
  sh('php artisan db:seed --class=Database\\\\Seeders\\\\SampleDataSeeder --force', { cwd: '../backend' });
}
