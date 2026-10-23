<?php

declare(strict_types=1);

/**
 * Single entry point. Works as the PHP built-in server router
 * (php -S 127.0.0.1:8000 -t public public/index.php) and behind
 * Apache/Nginx with all requests rewritten here.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use OrderInsights\Http\Kernel;
use OrderInsights\Http\Response;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/') || $path === '/api') {
    (new Kernel())->handle($method, $path, $_GET);
    return;
}

// Static assets: explicit allow-list, so nothing outside it is ever served.
$assets = [
    '/'                => ['index.html', 'text/html; charset=utf-8'],
    '/index.html'      => ['index.html', 'text/html; charset=utf-8'],
    '/assets/app.js'   => ['assets/app.js', 'text/javascript; charset=utf-8'],
    '/assets/app.css'  => ['assets/app.css', 'text/css; charset=utf-8'],
    '/favicon.svg'     => ['favicon.svg', 'image/svg+xml'],
];

if (!isset($assets[$path]) || !in_array($method, ['GET', 'HEAD'], true)) {
    Response::error(404, 'Not found.');
    return;
}

[$file, $type] = $assets[$path];
Response::securityHeaders();
header("Content-Type: {$type}");
header('Cache-Control: no-cache');
readfile(__DIR__ . '/' . $file);
