<?php

declare(strict_types=1);

/**
 * Applies schema.sql.  Usage:
 *   php bin/migrate.php           create tables if missing (idempotent)
 *   php bin/migrate.php --fresh   delete the database and start empty
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use OrderInsights\Support\Config;
use OrderInsights\Support\Database;

$path = Config::dbPath();

if (in_array('--fresh', $argv, true)) {
    foreach ([$path, "{$path}-wal", "{$path}-shm"] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$pdo = Database::connect($path);
Database::migrate($pdo, force: true);

echo "Schema ready at {$path}\n";
