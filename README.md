# GOSPEL LMS

An independent online Christian academy platform for Nigeria: courses, protected video, assignments and exams, live classes, Paystack payments (NGN), certificates with public verification, and admin/finance tooling.

* **Backend**: Laravel 13, PHP 8.3, PostgreSQL, Redis, Sanctum cookie auth, Pest. `backend/`
* **Frontend**: React 19 + Vite, React Router, TanStack Query, plain CSS tokens (no TypeScript/Tailwind). `frontend/`
* **Docs**: `docs/` (start with `FINAL_REPORT.md`, then `PROGRESS.md`)

## Quick start (Linux/macOS)
```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
createdb gospel_lms && createdb gospel_lms_test        # adjust DB_* in .env
php artisan migrate && php artisan db:seed --class=DemoSeeder   # LOCAL ONLY: demo users, password Password-12345
php artisan serve                                        # API on :8000
cd ../frontend && npm install && npm run dev             # SPA on :5173, proxies /api to :8000
```
Demo accounts (local only): `student@`, `lecturer@`, `admin@`, `finance@demo.test`. Admin/finance must enrol TOTP at first sign-in. Windows: `docs/LOCAL_SETUP_WINDOWS.md`.

## Tests
```bash
cd backend && php artisan test         # 246 tests
cd frontend && npm test                # unit
cd frontend && npx playwright test     # e2e + axe (needs Chromium; set E2E_CHROMIUM_PATH if Playwright's own browser is not installed)
```

## Documentation
`docs/FINAL_REPORT.md` · `CERTIFICATE_TEMPLATE.md` · `CREDITS.md` · `PROGRESS.md` · `API.md` · `DEPLOYMENT.md` · `OPERATIONS.md` · `SECURITY.md` · `ACCESSIBILITY.md` · `VIDEO.md` · `KNOWN_LIMITATIONS.md` · `PRODUCTION_CHECKLIST.md`
