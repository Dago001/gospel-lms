# GOSPEL LMS: one-time local demo setup on Windows. Run from the folder that contains `backend`:
#   powershell -ExecutionPolicy Bypass -File setup-demo.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$backend = Join-Path $root 'backend'
if (-not (Test-Path $backend)) { throw "Cannot find the 'backend' folder next to this script." }

function Need($name, $hint) { if (-not (Get-Command $name -ErrorAction SilentlyContinue)) { throw "$name was not found. $hint" } }
Need php 'Install PHP 8.3 or newer and add it to PATH (see README-DEMO.txt).'

$v = (php -r 'echo PHP_VERSION;')
if ([version]($v -replace '[^0-9.].*','') -lt [version]'8.3.0') { throw "PHP $v is too old. PHP 8.3 or newer is required." }

# Make sure php.ini exists and the needed extensions are switched on
$ini = (php --ini | Select-String 'Loaded Configuration File:\s*(.+)$').Matches.Groups[1].Value.Trim()
if (-not $ini -or $ini -eq '(none)') {
  $phpDir = Split-Path -Parent (Get-Command php).Source
  if (Test-Path "$phpDir\php.ini-development") { Copy-Item "$phpDir\php.ini-development" "$phpDir\php.ini"; $ini = "$phpDir\php.ini"; Write-Host "Created $ini" } else { throw 'No php.ini found. Copy php.ini-development to php.ini in your PHP folder.' }
}
$text = Get-Content $ini -Raw
foreach ($e in 'pdo_pgsql','pgsql','mbstring','intl','zip','gd','fileinfo','openssl','curl','bcmath','sodium') {
  if ($text -notmatch "(?m)^\s*extension\s*=\s*$e\b") { $text = $text -replace "(?m)^\s*;\s*(extension\s*=\s*$e)\s*$", '$1' }
}
if ($text -notmatch '(?m)^\s*extension_dir\s*=') { $phpDir = Split-Path -Parent (Get-Command php).Source; $text = $text -replace '(?m)^\s*;\s*extension_dir\s*=\s*"ext"', "extension_dir = `"$phpDir\ext`"" }
Set-Content $ini $text
$missing = @(); foreach ($e in 'pdo_pgsql','pgsql','mbstring','intl','zip','gd','fileinfo') { if (-not ((php -m) -contains $e)) { $missing += $e } }
if ($missing) { throw "These PHP extensions are still not loaded: $($missing -join ', '). Open $ini and remove the leading ';' from the matching extension= lines, then run this script again." }
Write-Host "PHP $v OK, extensions loaded."

# PostgreSQL
$psql = (Get-Command psql -ErrorAction SilentlyContinue).Source
if (-not $psql) { $psql = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName }
if (-not $psql) { throw 'PostgreSQL (psql) was not found. Install PostgreSQL 16 from https://www.postgresql.org/download/windows/ and remember the postgres password.' }
$pg = Read-Host 'Enter the password you chose for the PostgreSQL "postgres" user' -AsSecureString
$env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($pg))
& $psql -h 127.0.0.1 -U postgres -c "SELECT 1" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not sign in to PostgreSQL with that password.' }
& $psql -h 127.0.0.1 -U postgres -c "DO `$`$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='gospel') THEN CREATE ROLE gospel LOGIN PASSWORD 'gospel_dev' CREATEDB; END IF; END `$`$;" | Out-Null
$exists = & $psql -h 127.0.0.1 -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='gospel_lms'"
if (-not $exists) { & $psql -h 127.0.0.1 -U postgres -c "CREATE DATABASE gospel_lms OWNER gospel" | Out-Null }
& $psql -h 127.0.0.1 -U postgres -d gospel_lms -c "CREATE EXTENSION IF NOT EXISTS pg_trgm" | Out-Null
Remove-Item Env:PGPASSWORD

# Application
Push-Location $backend
if (-not (Test-Path .env)) { Copy-Item (Join-Path $root 'env.local.example') .env }
php artisan key:generate --force
php artisan migrate:fresh --force
php artisan db:seed --class='Database\Seeders\E2eSeeder' --force
php artisan optimize:clear | Out-Null
Pop-Location
Write-Host ''
Write-Host 'Setup finished. Start the demo with start-demo.bat, then open http://127.0.0.1:8000' -ForegroundColor Green
