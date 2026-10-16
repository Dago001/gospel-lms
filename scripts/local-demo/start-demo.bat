@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul || (echo PHP was not found in PATH. & pause & exit /b 1)
cd backend
rem Background jobs and the scheduler (exam auto-submit, reminders) in their own window
start "GOSPEL LMS scheduler" /min cmd /c "php artisan schedule:work"
rem Demo payment page (a local stand-in for Paystack, written in PHP)
start "Demo payment gateway" /min cmd /c "php -S 127.0.0.1:9099 ..\fake-paystack.php"
echo.
echo GOSPEL LMS is starting at http://127.0.0.1:8000   (close this window to stop it)
start "" http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t public ..\local-router.php
