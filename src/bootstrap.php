<?php

declare(strict_types=1);

/**
 * Application bootstrap: PSR-4 autoloader (no Composer needed), strict
 * error handling and configuration. Every entry point requires this file.
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "Order Insights requires PHP 8.1 or newer.\n");
    exit(1);
}

foreach (['pdo_sqlite', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        throw new RuntimeException("Required PHP extension missing: {$ext}");
    }
}

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'OrderInsights\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Turn every warning/notice into an exception so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ini_set('display_errors', '0');          // never leak stack traces to clients
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/app.log');
date_default_timezone_set('UTC');
