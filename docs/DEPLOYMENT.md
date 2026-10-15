# Production deployment

Target: one or more Linux servers (Ubuntu 22.04/24.04 LTS assumed) running Nginx + PHP-FPM 8.3, PostgreSQL 16, Redis, with the React build served as static files by Nginx from the **same origin** as the API (the SPA uses Sanctum cookie auth, so `https://lms.example.com/` and `https://lms.example.com/api` must share a host).

> **Status.** These steps are written from the codebase and its configuration. They have **not** been executed on a real production server by the author of this repository, and no production load or penetration test has been run. Work through `docs/PRODUCTION_CHECKLIST.md` on a staging server first.

## 1. Packages

```bash
sudo apt install nginx postgresql-16 postgresql-client-16 redis-server supervisor certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
# optional but recommended: sudo apt install clamav-daemon   (then CLAMAV_ENABLED=true)
```
Node 22 is needed only on the build machine (CI or a release host), not on the web server.

## 2. Database

```sql
CREATE ROLE gospel LOGIN PASSWORD '<strong, unique>';
CREATE DATABASE gospel_lms OWNER gospel;
\c gospel_lms
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- migrations also request this; needs a superuser the first time
```
Use a dedicated DB user. Do not reuse it for anything else. Enable `ssl` if the DB is on another host.

## 3. Application

```bash
sudo mkdir -p /var/www/gospel-lms && sudo chown deploy: /var/www/gospel-lms
git clone https://github.com/Dago001/gospel-lms.git /var/www/gospel-lms && cd /var/www/gospel-lms/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env (see §4), then:
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force     # roles & permissions only. NEVER run DemoSeeder / E2eSeeder in production.
php artisan gospel:create-admin                                    # prompts; the first admin must enrol TOTP at first sign-in
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache

cd ../frontend && npm ci && npm run build        # produces frontend/dist (run on the build host, rsync dist/ to the server)
```

## 4. Environment (`backend/.env`)

Set at minimum (full list with comments: `backend/.env.example`):

| Key | Production value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL`, `FRONTEND_URL` | `https://lms.example.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `lms.example.com` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` |
| `SESSION_SECURE_COOKIE` | `true` (SameSite `lax`) |
| `DB_*` | the database above |
| `PRIVATE_DISK_DRIVER=s3`, `PRIVATE_S3_*` | private bucket (no public ACL) for assignment files, certificates, receipts, backups |
| `MAIL_*`, `MAIL_FROM_ADDRESS` | a real provider; verification & reset emails depend on it |
| `ACADEMY_*` | real legal name, address, support email, signatory (printed on receipts and certificates) |
| `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_MODE=live` | live keys only at go-live; the app refuses a gateway mode that does not match the key |
| `CLOUDFLARE_*` | account id, API token, customer code, signing key id + base64 PEM, webhook secret |
| `ZOOM_*` | Server-to-Server OAuth credentials, webhook secret token |
| `SENTRY_LARAVEL_DSN` | optional |

Secrets live only in `.env` (mode `0640`, owner `deploy:www-data`) or your secret manager. They are never committed (`.env` is git-ignored) and the admin "Integrations" screen reports configured/not-configured without ever showing values.

## 5. Nginx (HTTPS, same origin)

```nginx
server {
  listen 80; server_name lms.example.com; return 301 https://$host$request_uri;
}
server {
  listen 443 ssl http2; server_name lms.example.com;
  # certbot --nginx -d lms.example.com  fills in ssl_certificate lines
  root /var/www/gospel-lms/frontend/dist;
  index index.html;
  client_max_body_size 55m;                       # assignment uploads are capped lower by the app

  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

  location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }

  location ~ ^/(api|sanctum)/ {
    root /var/www/gospel-lms/backend/public;
    try_files $uri /index.php?$query_string;
  }
  location = /index.php {
    root /var/www/gospel-lms/backend/public;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
  }
  location / { try_files $uri /index.html; }      # SPA fallback
  location ~ /\.(?!well-known) { deny all; }
}
```
The `SecurityHeaders` middleware adds a deny-all CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and `Permissions-Policy` to **API** responses only. Static SPA responses get only what Nginx adds, so add `X-Content-Type-Options`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy` and a Content-Security-Policy there (allow your own origin plus the video/payment/Zoom hosts you actually use). Behind a load balancer, set trusted proxies so client IPs (rate limits, audit log) are correct.

PHP-FPM sizing: start with `pm = dynamic`, `pm.max_children` = (RAM available to PHP) ÷ (~60 MB per worker) and tune from measurements. Enable OPcache (`opcache.validate_timestamps=0` with a reload on deploy).

## 6. Queue workers and scheduler

`/etc/supervisor/conf.d/gospel-worker.conf`
```ini
[program:gospel-worker]
command=php /var/www/gospel-lms/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/gospel-worker.log
```
Cron (one server only; the app uses `onOneServer()` for the rest):
```
* * * * * www-data cd /var/www/gospel-lms/backend && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler publishes scheduled courses, auto-submits expired exams, reconciles payments every 10 minutes, sends live-class reminders, syncs Zoom attendance, takes the nightly backup and runs the weekly restore test. Admin → System shows a scheduler heartbeat; if it is stale, the scheduler is not running.

## 7. Webhooks to register at the providers

| Provider | URL | Notes |
|---|---|---|
| Paystack | `https://lms.example.com/api/v1/webhooks/paystack` | HMAC-SHA512 with your secret key |
| Zoom | `https://lms.example.com/api/v1/webhooks/zoom` | endpoint validation handled; use the secret token |
| Cloudflare Stream | `https://lms.example.com/api/v1/webhooks/video/cloudflare` | set the same secret as `CLOUDFLARE_STREAM_WEBHOOK_SECRET` |

Then run `php artisan paystack:smoke` (test keys) and confirm Admin → Integrations is green. The Paystack refund payload and Cloudflare TUS/webhook formats are implemented from the provider documentation and must be confirmed against the real sandboxes before go-live.

## 8. Release and rollback

Release: `git pull` → `composer install --no-dev -o` → build/rsync `frontend/dist` → `php artisan down --retry=60` → `php artisan migrate --force` → `config:cache route:cache event:cache view:cache` → `php artisan queue:restart` → reload PHP-FPM → `php artisan up`.

Rollback: `php artisan down`, check out the previous tag, `composer install`, restore the previous `dist`, `queue:restart`, `up`. **Migrations are forward-only**: audit, grade and certificate-revocation tables are append-only by database triggers, and destructive down-migrations are not provided for them. If a migration must be undone, restore the pre-release backup (take one immediately before every release: `php artisan gospel:backup`) rather than running `migrate:rollback` against production.
