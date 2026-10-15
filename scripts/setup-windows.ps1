# GOSPEL LMS - local Windows setup. Run from the repository root (e.g. C:\global-lms) in PowerShell.
# Prerequisites: PHP 8.3+ (with pdo_pgsql, pgsql, redis, mbstring, intl, zip, gd), Composer 2, Node 22+, PostgreSQL 16+, Git.
$ErrorActionPreference = 'Stop'
foreach ($c in 'php','composer','node','npm','psql') {
  if (-not (Get-Command $c -ErrorAction SilentlyContinue)) { throw "Missing prerequisite: $c" }
}
Write-Host 'Creating database (you will be prompted for the postgres superuser password)...'
psql -U postgres -c "CREATE USER gospel WITH PASSWORD 'gospel_dev' CREATEDB;" 2>$null
psql -U postgres -c "CREATE DATABASE gospel_lms OWNER gospel;" 2>$null
psql -U postgres -c "CREATE DATABASE gospel_lms_test OWNER gospel;" 2>$null
Push-Location backend
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env; php artisan key:generate }
(Get-Content .env) -replace '^DB_PASSWORD=.*','DB_PASSWORD=gospel_dev' | Set-Content .env
php artisan migrate --force
Pop-Location
Push-Location frontend
npm install
Pop-Location
Write-Host 'Done. Start API: cd backend; php artisan serve   |   Start UI: cd frontend; npm run dev'
