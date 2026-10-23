<?php

declare(strict_types=1);

namespace OrderInsights\Support;

/**
 * Central configuration. Values can be overridden with environment variables
 * so tests and deployments never need code changes.
 */
final class Config
{
    public static function dbPath(): string
    {
        $env = getenv('DB_PATH');
        return ($env !== false && $env !== '') ? $env : APP_ROOT . '/storage/database.sqlite';
    }

    /** Hard cap on upload size (bytes). Also bounded by php.ini upload_max_filesize. */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    /** Hard cap on data rows per upload, protects memory and transaction size. */
    public const MAX_ROWS = 50_000;

    /** Maximum span of a daily-revenue range (protects the calendar CTE). */
    public const MAX_RANGE_DAYS = 3660;

    public const TOP_PRODUCTS_DEFAULT = 5;
    public const TOP_PRODUCTS_MAX = 100;
}
