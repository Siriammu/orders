<?php

declare(strict_types=1);

namespace OrderInsights\Repository;

use OrderInsights\Support\DateRange;
use PDO;

/**
 * Dashboard read model. Every number is computed by SQLite (GROUP BY, joins,
 * filters); PHP only casts types for JSON. Money stays in integer cents until
 * the final ROUND(cents / 100.0, 2).
 *
 * Metric rules (from the brief):
 *   revenue     = SUM(quantity * unit_price) on completed orders
 *   orders      = COUNT(DISTINCT completed order)
 *   aov         = revenue / orders
 *   units       = SUM(quantity) on completed orders
 *   refundRate  = refunded orders / (completed + refunded orders)
 *   cancelled orders count toward nothing; date filters are inclusive.
 */
final class MetricsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{revenue: float, orders: int, aov: float, units: int, refundRate: float} */
    public function summary(DateRange $range): array
    {
        // AOV is rounded half-up in integer cents ((2a + b) / 2b) so no
        // floating-point rounding can drift by a cent.
        $sql = <<<'SQL'
            WITH totals AS (
                SELECT
                    COALESCE(SUM(CASE WHEN o.status = 'completed'
                                      THEN oi.quantity * oi.unit_price_cents END), 0) AS revenue_cents,
                    COUNT(DISTINCT CASE WHEN o.status = 'completed' THEN o.order_id END)  AS completed_orders,
                    COALESCE(SUM(CASE WHEN o.status = 'completed' THEN oi.quantity END), 0) AS units,
                    COUNT(DISTINCT CASE WHEN o.status = 'refunded'  THEN o.order_id END)  AS refunded_orders
                FROM orders AS o
                JOIN order_items AS oi ON oi.order_id = o.order_id
                WHERE o.status IN ('completed', 'refunded')
                  AND o.order_date BETWEEN :from AND :to
            )
            SELECT
                ROUND(revenue_cents / 100.0, 2) AS revenue,
                completed_orders                AS orders,
                CASE WHEN completed_orders = 0 THEN 0
                     ELSE ROUND(((2 * revenue_cents + completed_orders) / (2 * completed_orders)) / 100.0, 2)
                END                             AS aov,
                units,
                CASE WHEN completed_orders + refunded_orders = 0 THEN 0
                     ELSE ROUND(CAST(refunded_orders AS REAL) / (completed_orders + refunded_orders), 4)
                END                             AS refund_rate
            FROM totals
            SQL;

        $row = $this->run($sql, $range)->fetch();

        return [
            'revenue'    => (float) $row['revenue'],
            'orders'     => (int) $row['orders'],
            'aov'        => (float) $row['aov'],
            'units'      => (int) $row['units'],
            'refundRate' => (float) $row['refund_rate'],
        ];
    }

    /** @return list<array{sku: string, product: string, units: int, revenue: float}> */
    public function topProducts(DateRange $range, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT
                p.sku,
                p.name                                              AS product,
                SUM(oi.quantity)                                    AS units,
                ROUND(SUM(oi.quantity * oi.unit_price_cents) / 100.0, 2) AS revenue
            FROM orders AS o
            JOIN order_items AS oi ON oi.order_id = o.order_id
            JOIN products    AS p  ON p.sku       = oi.sku
            WHERE o.status = 'completed'
              AND o.order_date BETWEEN :from AND :to
            GROUP BY p.sku, p.name
            ORDER BY SUM(oi.quantity * oi.unit_price_cents) DESC, units DESC, p.sku
            LIMIT :limit
            SQL;

        $rows = $this->run($sql, $range, [':limit' => $limit])->fetchAll();

        return array_map(static fn (array $r): array => [
            'sku'     => (string) $r['sku'],
            'product' => (string) $r['product'],
            'units'   => (int) $r['units'],
            'revenue' => (float) $r['revenue'],
        ], $rows);
    }

    /**
     * One {date, revenue} per day, including 0 for days without sales.
     * A recursive CTE builds the calendar; when a bound is omitted it falls
     * back to the first/last completed order date.
     *
     * Uses idx_orders_status_date (status, order_date).
     *
     * @return list<array{date: string, revenue: float}>
     */
    public function dailyRevenue(DateRange $range, int $maxDays): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE
            bounds AS (
                SELECT
                    COALESCE(:from_opt, (SELECT MIN(order_date) FROM orders WHERE status = 'completed')) AS d0,
                    COALESCE(:to_opt,   (SELECT MAX(order_date) FROM orders WHERE status = 'completed')) AS d1
            ),
            calendar(day, n) AS (
                SELECT d0, 1 FROM bounds WHERE d0 IS NOT NULL AND d1 IS NOT NULL AND d0 <= d1
                UNION ALL
                SELECT date(c.day, '+1 day'), c.n + 1
                FROM calendar AS c, bounds AS b
                WHERE c.day < b.d1 AND c.n < :max_days
            ),
            daily AS (
                SELECT o.order_date AS day,
                       SUM(oi.quantity * oi.unit_price_cents) AS revenue_cents
                FROM orders AS o
                JOIN order_items AS oi ON oi.order_id = o.order_id
                WHERE o.status = 'completed'
                  AND o.order_date BETWEEN :from AND :to
                GROUP BY o.order_date
            )
            SELECT c.day AS date,
                   ROUND(COALESCE(d.revenue_cents, 0) / 100.0, 2) AS revenue
            FROM calendar AS c
            LEFT JOIN daily AS d ON d.day = c.day
            ORDER BY c.day
            SQL;

        $rows = $this->run($sql, $range, [
            ':from_opt' => $range->from,
            ':to_opt'   => $range->to,
            ':max_days' => $maxDays,
        ])->fetchAll();

        return array_map(static fn (array $r): array => [
            'date'    => (string) $r['date'],
            'revenue' => (float) $r['revenue'],
        ], $rows);
    }

    /** @param array<string, string|int|null> $extra */
    private function run(string $sql, DateRange $range, array $extra = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':from', $range->lowerBound());
        $stmt->bindValue(':to', $range->upperBound());
        foreach ($extra as $name => $value) {
            $stmt->bindValue($name, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value)  => PDO::PARAM_INT,
                default         => PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        return $stmt;
    }
}
