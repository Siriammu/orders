<?php

declare(strict_types=1);

/**
 * Acceptance tests for every check in section 4 of the brief, plus the
 * reviewer's update file, the database constraints and queries.sql.
 * Runs against a throw-away database:   php tests/acceptance.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use OrderInsights\Import\CsvParser;
use OrderInsights\Import\ImportException;
use OrderInsights\Import\Money;
use OrderInsights\Repository\MetricsRepository;
use OrderInsights\Repository\OrderWriter;
use OrderInsights\Support\Database;
use OrderInsights\Support\DateRange;

$failures = 0;
$passes = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $passes;
    if ($expected === $actual) {
        $passes++;
        echo "  \u{2713} {$label}\n";
        return;
    }
    $failures++;
    echo "  \u{2717} {$label}\n      expected: " . json_encode($expected) . "\n      actual:   " . json_encode($actual) . "\n";
}

function import(PDO $pdo, string $file): \OrderInsights\Import\ImportResult
{
    $path = __DIR__ . '/fixtures/' . $file;
    $result = (new CsvParser())->parse($path);
    (new OrderWriter($pdo))->save($result, $file, hash_file('sha256', $path));
    return $result;
}

$dbFile = sys_get_temp_dir() . '/order-insights-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$pdo = Database::connect($dbFile);
$metrics = new MetricsRepository($pdo);
$range = static fn (?string $from = null, ?string $to = null) => DateRange::fromQuery(array_filter(['from' => $from, 'to' => $to]));

try {
    echo "Money parsing\n";
    check('19.99 -> 1999', 1999, Money::toCents('19.99'));
    check('14.5 -> 1450', 1450, Money::toCents('14.5'));
    check('0 -> 0', 0, Money::toCents('0'));
    check('abc rejected', null, Money::toCents('abc'));
    check('-1 rejected', null, Money::toCents('-1'));
    check('1.234 rejected', null, Money::toCents('1.234'));

    echo "\nImport orders_sample.csv into an empty database\n";
    $r = import($pdo, 'orders_sample.csv');
    check('totalRows 15', 15, $r->totalRows);
    check('imported 10', 10, $r->imported());
    check('rejected 5', 5, $r->rejected());
    check('rejected rows are 9-13', [9, 10, 11, 12, 13], array_column($r->errors, 'row'));
    foreach ($r->errors as $e) {
        echo "      row {$e['row']}: {$e['reason']}\n";
    }

    $expectedSummary = ['revenue' => 429.45, 'orders' => 5, 'aov' => 85.89, 'units' => 13, 'refundRate' => 0.2857];
    check('summary 429.45 · 5 · 85.89 · 13 · 28.6%', $expectedSummary, $metrics->summary($range()));

    $top = $metrics->topProducts($range(), 5);
    check('top product Running Shoes: 3 units, 267.00',
        ['sku' => 'SHO-07', 'product' => 'Running Shoes', 'units' => 3, 'revenue' => 267.0], $top[0] ?? null);

    $day1 = $metrics->summary($range('2026-09-01', '2026-09-01'));
    check('filter 09-01..09-01 revenue 143.48', 143.48, $day1['revenue']);
    check('filter 09-01..09-01 orders 2', 2, $day1['orders']);

    check('category "apparel " merged into Apparel',
        1, (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE name_key = 'apparel'")->fetchColumn());
    check('three categories total', 3, (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn());
    check('product with comma stored intact', 'Socks, 2-Pack',
        $pdo->query("SELECT name FROM products WHERE sku = 'SOC-05'")->fetchColumn());

    $daily = $metrics->dailyRevenue($range('2026-09-01', '2026-09-07'), 3660);
    check('daily revenue: 7 rows incl. zero days', 7, count($daily));
    check('daily revenue 09-04 is 0', 0.0, $daily[3]['revenue']);

    echo "\nRe-upload the same file\n";
    $snapshot = fn () => [
        $metrics->summary($range()), $metrics->topProducts($range(), 5),
        $metrics->dailyRevenue($range(), 3660),
        $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn(),
    ];
    $before = $snapshot();
    import($pdo, 'orders_sample.csv');
    check('every number unchanged', $before, $snapshot());

    echo "\nReviewer update file (O-1005 completed -> refunded, new O-1013)\n";
    $r2 = import($pdo, 'reviewer_hidden_update.csv');
    check('2 rows imported, 0 rejected', [2, 0], [$r2->imported(), $r2->rejected()]);
    check('O-1005 is now refunded', 'refunded',
        $pdo->query("SELECT status FROM orders WHERE order_id = 'O-1005'")->fetchColumn());
    check('summary after update', ['revenue' => 280.45, 'orders' => 5, 'aov' => 56.09, 'units' => 13, 'refundRate' => 0.375],
        $metrics->summary($range()));
    $top2 = $metrics->topProducts($range(), 1);
    check('top product after update is Cotton Tee (5 units, 99.95)',
        ['sku' => 'TEE-01', 'product' => 'Cotton Tee', 'units' => 5, 'revenue' => 99.95], $top2[0] ?? null);

    echo "\nFile-level validation saves nothing\n";
    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();
    try {
        (new CsvParser())->parse(__DIR__ . '/fixtures/missing_status_column.csv');
        check('missing column throws', true, false);
    } catch (ImportException $e) {
        check('missing column -> 400', 400, $e->httpStatus);
        check('missing column named', ['status'], $e->details['missingColumns'] ?? null);
    }
    check('no rows written', $countBefore, (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());

    echo "\nEdge cases: BOM, CRLF, header case, extra column, escaped quotes, blank line\n";
    $edge = (new CsvParser())->parse(__DIR__ . '/fixtures/edge_cases.csv');
    check('7 data rows (blank line skipped)', 7, $edge->totalRows);
    check('1 imported', 1, $edge->imported());
    check('rejected rows keep spreadsheet numbering', [3, 4, 5, 6, 7, 9], array_column($edge->errors, 'row'));
    check('escaped quotes and comma kept', 'Mug, "Large"', $edge->lines[0]->product ?? null);
    check('status normalised to lower case', 'completed', $edge->lines[0]->status ?? null);
    check('category key trimmed + lower-cased', 'home goods', $edge->lines[0]->categoryKey ?? null);

    echo "\nDatabase constraints (defence in depth)\n";
    $rejects = static function (string $sql) use ($pdo): bool {
        try {
            $pdo->exec($sql);
            return false;
        } catch (PDOException) {
            return true;
        }
    };
    check('CHECK rejects 2026-09-31', true, $rejects("INSERT INTO orders VALUES ('X1','2026-09-31','C-501','completed',DEFAULT,DEFAULT)"));
    check('CHECK rejects status shipped', true, $rejects("INSERT INTO orders (order_id,order_date,customer_id,status) VALUES ('X2','2026-09-01','C-501','shipped')"));
    check('CHECK rejects quantity 0', true, $rejects("INSERT INTO order_items (order_id,sku,quantity,unit_price_cents) VALUES ('O-1001','SHO-07',0,100)"));
    check('CHECK rejects float price', true, $rejects("INSERT INTO order_items (order_id,sku,quantity,unit_price_cents) VALUES ('O-1001','SHO-07',1,19.99)"));
    check('FK rejects unknown order', true, $rejects("INSERT INTO order_items (order_id,sku,quantity,unit_price_cents) VALUES ('NOPE','SHO-07',1,100)"));

    echo "\nIndex is used by the daily-revenue query\n";
    $plan = $pdo->query("EXPLAIN QUERY PLAN SELECT o.order_date, SUM(oi.quantity*oi.unit_price_cents)
        FROM orders o JOIN order_items oi ON oi.order_id=o.order_id
        WHERE o.status='completed' AND o.order_date BETWEEN '2026-09-01' AND '2026-09-07' GROUP BY o.order_date")
        ->fetchAll(PDO::FETCH_COLUMN, 3);
    check('plan uses idx_orders_status_date', true, str_contains(implode(' | ', $plan), 'idx_orders_status_date'));
} finally {
    $pdo = null;
    foreach ([$dbFile, "{$dbFile}-wal", "{$dbFile}-shm"] as $f) {
        @unlink($f);
    }
}

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
