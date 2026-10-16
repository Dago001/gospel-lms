<?php
// Router for PHP's built-in web server: serves the built React site and the Laravel API from one address (http://localhost:8000).
// Used only for local demos: `php -S localhost:8000 -t public local-router.php`
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$public = __DIR__.'/backend/public';

if ($path !== '/' && is_file($public.$path) && ! str_ends_with($path, '.php')) {
    return false;                                   // real static file (JS, CSS, images)
}
if (preg_match('#^/(api|sanctum|up|index\.php)(/|$)#', $path)) {
    chdir($public);
    $_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require $public.'/index.php';                   // Laravel
    return true;
}
header('Content-Type: text/html; charset=utf-8');  // every other URL is a page of the single-page app
readfile($public.'/index.html');
