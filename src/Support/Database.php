<?php

declare(strict_types=1);

namespace OrderInsights\Support;

use PDO;
use RuntimeException;

/**
 * Creates the PDO connection with safe defaults and applies schema.sql the
 * first time the database is opened (tracked with PRAGMA user_version).
 */
final class Database
{
    public const SCHEMA_VERSION = 1;

    public static function connect(?string $path = null): PDO
    {
        $path ??= Config::dbPath();
        $dir = dirname($path);
        if ($path !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create database directory.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Per-connection settings: enforce FKs, wait on locks instead of failing,
        // WAL lets the dashboard read while an import is writing.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        self::migrate($pdo);

        return $pdo;
    }

    public static function migrate(PDO $pdo, bool $force = false): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if (!$force && $version >= self::SCHEMA_VERSION) {
            return;
        }
        $sql = file_get_contents(APP_ROOT . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema.sql not found.');
        }
        $pdo->exec($sql);
    }
}
