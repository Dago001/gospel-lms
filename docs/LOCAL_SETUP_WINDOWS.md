# Local setup on Windows (C:\global-lms)

1. Install: Git, PHP 8.3+ (enable `pdo_pgsql`, `pgsql`, `redis`, `mbstring`, `intl`, `zip`, `gd` in php.ini), Composer 2, Node 22+, PostgreSQL 16+.
2. `git clone https://github.com/Dago001/gospel-lms C:\global-lms`
3. `cd C:\global-lms` then `powershell -ExecutionPolicy Bypass -File scripts\setup-windows.ps1`
4. Run the API: `cd backend; php artisan serve` (http://localhost:8000)
5. Run the UI: `cd frontend; npm run dev` (http://localhost:5173)

The script creates the local dev database user `gospel` with a development-only password; change it for any shared or production environment.
