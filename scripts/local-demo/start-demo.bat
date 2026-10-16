@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul || (echo PHP was not found in PATH. & pause & exit /b 1)
cd backend
rem Background jobs and the scheduler (exam auto-submit, reminders) in their own window
start "GOSPEL LMS scheduler" /min cmd /c "php artisan schedule:work"
rem Local stand-in for Paystack, only if Node.js is installed
where node >nul 2>nul && (start "Demo payment gateway" /min cmd /c "node ..\fake-paystack.mjs") || echo Node.js not found: paid-course checkout demo is unavailable ^(free courses work^).
echo.
echo GOSPEL LMS is starting at http://127.0.0.1:8000   (close this window to stop it)
start "" http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t public ..\local-router.php
