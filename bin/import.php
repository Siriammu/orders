<?php

declare(strict_types=1);

/** CLI import, same code path as the API:  php bin/import.php path/to/orders.csv */

require dirname(__DIR__) . '/src/bootstrap.php';

use OrderInsights\Import\CsvParser;
use OrderInsights\Import\ImportException;
use OrderInsights\Repository\OrderWriter;
use OrderInsights\Support\Database;

$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/import.php <file.csv>\n");
    exit(2);
}

try {
    $result = (new CsvParser())->parse($file);
} catch (ImportException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

(new OrderWriter(Database::connect()))->save($result, basename($file), hash_file('sha256', $file));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
