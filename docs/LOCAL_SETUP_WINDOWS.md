# Local setup on Windows

## Easiest: the demo package (no Node.js, no Composer)
Needs only **PHP 8.3+** and **PostgreSQL 16**. Unzip `gospel-lms-demo.zip` (build it with `scripts/build-local-demo.sh`, or get it from the maintainer), then follow `README-DEMO.txt` inside it: run `setup-demo.ps1` once, then `start-demo.bat`. The site opens at http://127.0.0.1:8000 with demo accounts and a built-in demo payment page (a small PHP script, not Paystack).

## Developers only: working on the React source
The user interface source (`frontend/`) is compiled once into plain static files (HTML/JS/CSS) that PHP serves. **Node.js is needed only to recompile that source** (`npm run build`) and to run the developer test suites. It is never needed to run, host or deploy the application: the packages produced by `scripts/build-cpanel.sh` and `scripts/build-local-demo.sh` already contain the compiled site and run on PHP + PostgreSQL alone.

For development: Git, PHP 8.3+ (`pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip`, `gd`), Composer 2, Node 22+, PostgreSQL 16+, then `scripts\setup-windows.ps1`, `cd backend; php artisan serve`, `cd frontend; npm run dev`.
