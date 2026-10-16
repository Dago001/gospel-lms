# Deploying GOSPEL LMS on cPanel (subdomain, PHP 8.4, PostgreSQL)

Example names below use the subdomain `lms.stbenedictsacademy.com.ng` and the cPanel account `stbenedi`. Replace them with yours.
This is a **temporary / staging** setup on shared hosting. It has not been run on your server by the author; follow the checks at the end.

## 1. Create the subdomain (cPanel → Domains → Create A New Domain)
* Domain: `lms.stbenedictsacademy.com.ng`
* **Untick** "Share document root".
* Document root: `gospel-lms/backend/public`
* Then cPanel → **SSL/TLS Status** → run **AutoSSL** for the subdomain (wait until it shows a valid certificate).

## 2. PHP version
cPanel → **MultiPHP Manager** → select the subdomain → **PHP 8.4 (ea-php84)**.
cPanel → **MultiPHP INI Editor / Select PHP Version → Extensions**: make sure `pdo_pgsql`, `pgsql`, `mbstring`, `gd`, `intl`, `zip`, `bcmath`, `fileinfo`, `curl`, `xml`, `openssl` are enabled for ea-php84. If `pdo_pgsql` is missing, ask your host to install `ea-php84-php-pgsql`.

## 3. Database (cPanel → PostgreSQL Databases)
1. Create a database, e.g. `lms` (cPanel names it `stbenedi_lms`).
2. Create a user, e.g. `lmsuser` with a strong password (`stbenedi_lmsuser`).
3. **Add the user to the database** with **ALL PRIVILEGES**.

## 4. Upload the app
1. cPanel → **File Manager** → your home folder (`/home/stbenedi`) → create folder `gospel-lms`.
2. Upload `gospel-lms-cpanel.zip` into it and **Extract**. You should now have `/home/stbenedi/gospel-lms/backend/` (containing `app`, `public`, `vendor`, …). Delete the zip afterwards.
3. In `backend/`, copy `.env.production.example` to `.env` and edit it (File Manager → Edit): set the database name/user/password, your URLs, mail password and academy details. Leave the Paystack/Zoom/Cloudflare lines empty for now. **Never share this file.**
4. Make sure `backend/storage` and `backend/bootstrap/cache` are writable (permissions `775`).

## 5. One-time setup commands (cPanel → **Terminal**)
```bash
cd ~/gospel-lms/backend
alias php=/usr/local/bin/ea-php84
php -v                                   # must say 8.4.x
php -m | grep -i pgsql                   # must list pdo_pgsql and pgsql
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan gospel:create-admin          # follow the prompts; this is your administrator
php artisan optimize
```
Never run `DemoSeeder` or `E2eSeeder` here. If `migrate` fails on `pg_trgm`, ask the host to run `CREATE EXTENSION pg_trgm;` in your database (or enable it from phpPgAdmin as the database owner).

## 6. Scheduled tasks (cPanel → **Cron Jobs**, add two, "Once Per Minute" `* * * * *`)
```
/usr/local/bin/ea-php84 /home/stbenedi/gospel-lms/backend/artisan schedule:run >> /dev/null 2>&1
/usr/local/bin/ea-php84 /home/stbenedi/gospel-lms/backend/artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```
The first runs exam auto-submission, payment re-checks, reminders and backups. The second sends emails and other queued work.

## 7. Check it works
1. Open `https://lms.stbenedictsacademy.com.ng/` → the home page loads with the slider.
2. `https://lms.stbenedictsacademy.com.ng/up` → shows a green "up" page (Laravel health).
3. Sign in at `/login` with the admin you created; you will be asked to set up two-step verification (scan the QR code with Google Authenticator or similar and **save the recovery codes**).
4. Admin → **System** should show the database, queue and scheduler heartbeat OK (the heartbeat appears after the cron has run for a minute or two).
5. Register a test student; the verification email must arrive (check spam). If not, re-check the `MAIL_*` lines.

## 8. Payments, video and Zoom
Add the keys to `.env` when you have them, then run `php artisan config:clear && php artisan optimize`.
* **Paystack:** set `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_MODE=test`; in the Paystack dashboard set the webhook URL to `https://lms.stbenedictsacademy.com.ng/api/v1/webhooks/paystack`; run `php artisan paystack:smoke`.
* **Cloudflare Stream / Zoom:** see `docs/DEPLOYMENT.md` §7 for the webhook URLs.

## 9. Certificate template
Admin → **Certificates → Template & layout** → upload your artwork (see `docs/CERTIFICATE_TEMPLATE.md`).

## Updating later
Upload a new zip, extract over the old folder (keep `.env` and `storage/`), then in Terminal: `php artisan migrate --force && php artisan optimize:clear && php artisan optimize`.

## Backups
cPanel → **Backup** (or JetBackup) covers files; for the database also use cPanel's PostgreSQL backup or `php artisan gospel:backup` (needs `pg_dump`, set `PG_DUMP_BIN` if it is not on the path). Download a copy off the server.

## Limits of shared hosting
Fine for a demo or a small pilot. Exams/queues run once per minute (not instantly); uploaded files live on the server disk (no S3); there is no Redis. For a larger launch use a VPS and follow `docs/DEPLOYMENT.md`.

## What was verified, and what was not
Verified by the author: the zip builds; extracted with production vendor (no dev packages) under PHP 8.3, it migrated a fresh PostgreSQL database, seeded roles, served `/up` and the public API with `APP_ENV=production`, and ran the scheduler and queue worker. **Not verified:** PHP 8.4 (the automated tests ran on 8.3; the code uses no 8.4-specific features and dependencies support 8.4, but run `php artisan test` or at least the setup steps and the checks in section 7 on your server), Apache `.htaccess` behaviour on your host, cPanel cron timing, AutoSSL, and mail delivery.
