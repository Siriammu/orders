-- =====================================================================
-- Order Insights · queries.sql
-- Plain SQL against schema.sql. Run with:  sqlite3 storage/database.sqlite < queries.sql
-- Money is stored in integer cents and only converted to dollars at the end.
-- =====================================================================

.headers on
.mode column

-- ---------------------------------------------------------------------
-- 1. Customers with 2 or more completed orders:
--    customer, orders, total spend; highest spend first.
-- ---------------------------------------------------------------------
SELECT
    o.customer_id                                          AS customer,
    COUNT(DISTINCT o.order_id)                             AS orders,
    ROUND(SUM(oi.quantity * oi.unit_price_cents) / 100.0, 2) AS total_spend
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.order_id
WHERE o.status = 'completed'
GROUP BY o.customer_id
HAVING COUNT(DISTINCT o.order_id) >= 2
ORDER BY SUM(oi.quantity * oi.unit_price_cents) DESC, o.customer_id;

-- ---------------------------------------------------------------------
-- 2. Top product by completed revenue in each category (window function).
--    ROW_NUMBER returns exactly one product per category; ties are broken
--    by units, then SKU, so the result is deterministic.
--    (Swap ROW_NUMBER for RANK to list tied products together.)
-- ---------------------------------------------------------------------
WITH product_revenue AS (
    SELECT
        c.name                                   AS category,
        p.sku,
        p.name                                   AS product,
        SUM(oi.quantity)                         AS units,
        SUM(oi.quantity * oi.unit_price_cents)   AS revenue_cents
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id   = o.order_id
    JOIN products    AS p  ON p.sku         = oi.sku
    JOIN categories  AS c  ON c.category_id = p.category_id
    WHERE o.status = 'completed'
    GROUP BY c.category_id, p.sku
),
ranked AS (
    SELECT
        pr.*,
        ROW_NUMBER() OVER (
            PARTITION BY pr.category
            ORDER BY pr.revenue_cents DESC, pr.units DESC, pr.sku
        ) AS rn
    FROM product_revenue AS pr
)
SELECT
    category,
    sku,
    product,
    units,
    ROUND(revenue_cents / 100.0, 2) AS revenue
FROM ranked
WHERE rn = 1
ORDER BY revenue_cents DESC, category;

-- ---------------------------------------------------------------------
-- 3. Daily revenue 2026-09-01 .. 2026-09-07, 0 for days without sales,
--    plus a running total. A recursive CTE builds the calendar so that
--    empty days exist to LEFT JOIN against.
-- ---------------------------------------------------------------------
WITH RECURSIVE calendar(day) AS (
    SELECT '2026-09-01'
    UNION ALL
    SELECT date(day, '+1 day') FROM calendar WHERE day < '2026-09-07'
),
daily AS (
    SELECT o.order_date AS day,
           SUM(oi.quantity * oi.unit_price_cents) AS revenue_cents
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.order_id
    WHERE o.status = 'completed'
      AND o.order_date BETWEEN '2026-09-01' AND '2026-09-07'
    GROUP BY o.order_date
)
SELECT
    c.day                                                  AS date,
    ROUND(COALESCE(d.revenue_cents, 0) / 100.0, 2)         AS revenue,
    ROUND(SUM(COALESCE(d.revenue_cents, 0)) OVER (
              ORDER BY c.day
              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
          ) / 100.0, 2)                                    AS running_total
FROM calendar AS c
LEFT JOIN daily AS d ON d.day = c.day
ORDER BY c.day;
