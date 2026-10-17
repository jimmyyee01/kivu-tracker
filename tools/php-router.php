<?php
/* =====================================================================
   KIVU Tracker — tools/php-router.php
   Router for PHP's built-in server (Apache uses .htaccess instead):

       php -S 127.0.0.1:8080 tools/php-router.php

   Then open http://127.0.0.1:8080/app
   ===================================================================== */
$root = dirname(__DIR__);
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/* JSON API -> php/api.php (same paths as Apache) */
if ($uri === '/api' || strpos($uri, '/api/') === 0) {
    $_GET['r'] = substr($uri, 4);
    if ($_GET['r'] === '') $_GET['r'] = '/';
    require $root . '/php/api.php';
    return true;
}

$path = trim($uri, '/');

/* real files at the project root (install.php, database.sql, uploads...) */
if ($path !== '' && is_file($root . '/' . $path)) return false;

/* static assets live in public/ */
if ($path !== '' && (strpos($path, 'css/') === 0 || strpos($path, 'js/') === 0 || strpos($path, 'img/') === 0
    || strpos($path, 'download/') === 0)) {
    $file = $root . '/public/' . $path;
    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = ['css' => 'text/css', 'js' => 'text/javascript', 'map' => 'application/json',
                 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
                 'woff2' => 'font/woff2', 'apk' => 'application/vnd.android.package-archive',
                 'json' => 'application/json', 'txt' => 'text/plain', 'md' => 'text/markdown'][$ext]
            ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . (strpos($mime, 'text/') === 0 ? '; charset=utf-8' : ''));
        header('Content-Length: ' . filesize($file));
        /* the app package is meant to be downloaded, not rendered */
        if ($ext === 'apk') header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Cache-Control: no-store');
        readfile($file);
        return true;
    }
}

/* pretty pages */
$pages = ['' => 'index.html', 'login' => 'login.html', 'app' => 'app.html', 'admin' => 'admin.html',
    'reports' => 'reports.html', 'super' => 'super.html', 'track' => 'track.html', 'index.html' => 'index.html',
    'register' => 'register.html', 'signup' => 'register.html', 'billing' => 'billing.html',
    'reseller' => 'reseller.html', 'pay' => 'pay.html', 'download' => 'download.html'];
if (isset($pages[$path])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($root . '/public/' . $pages[$path]);
    return true;
}

/* a sandbox invoice page such as /pay/<invoice id> */
if (preg_match('#^pay/[A-Za-z0-9_\-]{3,64}$#', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($root . '/public/pay.html');
    return true;
}

/* a unit deep-link such as /track/<unit id> */
if (preg_match('#^track/[A-Za-z0-9_\-]{3,64}$#', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/public/track.html');
    return true;
}

if ($uri === '/favicon.ico') { http_response_code(204); return true; }

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Unknown endpoint: ' . $uri]);
return true;
