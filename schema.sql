-- =====================================================================
-- Order Insights · schema.sql (SQLite 3.35+)
--
-- Design notes
--   * 3NF: an order's date/customer/status live once on `orders`, a SKU's
--     name/category live once on `products`, a line is (order, sku, qty, price).
--     Because status is order-level, a later upload that changes it
--     (e.g. completed -> refunded) changes every line of that order at once.
--   * Money is INTEGER cents. No REAL/FLOAT column exists anywhere.
--   * CHECK constraints duplicate the importer's validation on purpose:
--     the database is the last line of defence, not the application.
--   * Idempotent (IF NOT EXISTS) so it can be re-applied safely.
-- =====================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS customers (
    customer_id  TEXT PRIMARY KEY
                 CHECK (length(customer_id) BETWEEN 1 AND 64 AND customer_id = trim(customer_id))
);

-- Categories are matched case-insensitively and trimmed ("apparel " = "Apparel").
-- name_key is the normalised identity; name keeps the first spelling seen for display.
CREATE TABLE IF NOT EXISTS categories (
    category_id  INTEGER PRIMARY KEY,
    name_key     TEXT NOT NULL UNIQUE
                 CHECK (length(name_key) BETWEEN 1 AND 100 AND name_key = lower(trim(name_key))),
    name         TEXT NOT NULL
                 CHECK (length(name) BETWEEN 1 AND 100 AND name = trim(name))
);

CREATE TABLE IF NOT EXISTS products (
    sku          TEXT PRIMARY KEY
                 CHECK (length(sku) BETWEEN 1 AND 64 AND sku = trim(sku)),
    name         TEXT NOT NULL
                 CHECK (length(name) BETWEEN 1 AND 255),
    category_id  INTEGER NOT NULL
                 REFERENCES categories (category_id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS orders (
    order_id     TEXT PRIMARY KEY
                 CHECK (length(order_id) BETWEEN 1 AND 64 AND order_id = trim(order_id)),
    -- ISO date; date(x) = x rejects impossible dates such as 2026-09-31,
    -- because SQLite normalises them (2026-09-31 -> 2026-10-01).
    order_date   TEXT NOT NULL
                 CHECK (order_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                        AND date(order_date) = order_date),
    customer_id  TEXT NOT NULL
                 REFERENCES customers (customer_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    status       TEXT NOT NULL
                 CHECK (status IN ('completed', 'refunded', 'cancelled')),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- One row per (order, sku). The composite primary key is what makes
-- re-uploading a file an UPSERT instead of a duplicate.
CREATE TABLE IF NOT EXISTS order_items (
    order_id          TEXT NOT NULL
                      REFERENCES orders (order_id) ON UPDATE CASCADE ON DELETE CASCADE,
    sku               TEXT NOT NULL
                      REFERENCES products (sku) ON UPDATE CASCADE ON DELETE RESTRICT,
    quantity          INTEGER NOT NULL
                      CHECK (typeof(quantity) = 'integer' AND quantity >= 1),
    unit_price_cents  INTEGER NOT NULL
                      CHECK (typeof(unit_price_cents) = 'integer' AND unit_price_cents >= 0),
    updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (order_id, sku)
) WITHOUT ROWID;

-- Audit trail: one row per upload (who/what/when is useful when numbers move).
CREATE TABLE IF NOT EXISTS imports (
    import_id     INTEGER PRIMARY KEY,
    filename      TEXT NOT NULL CHECK (length(filename) BETWEEN 1 AND 255),
    sha256        TEXT NOT NULL CHECK (length(sha256) = 64),
    total_rows    INTEGER NOT NULL CHECK (total_rows >= 0),
    imported_rows INTEGER NOT NULL CHECK (imported_rows >= 0),
    rejected_rows INTEGER NOT NULL CHECK (rejected_rows >= 0),
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (imported_rows + rejected_rows = total_rows)
);

-- ---------------------------------------------------------------------
-- Indexes
-- ---------------------------------------------------------------------

-- Dashboard index. Used by GET /api/daily-revenue (and /api/summary,
-- /api/top-products): WHERE status = 'completed' AND order_date BETWEEN ? AND ?
-- becomes an index range scan instead of a full scan of orders.
CREATE INDEX IF NOT EXISTS idx_orders_status_date ON orders (status, order_date);

-- Foreign-key support indexes (SQLite does not create these automatically).
CREATE INDEX IF NOT EXISTS idx_order_items_sku  ON order_items (sku);
CREATE INDEX IF NOT EXISTS idx_orders_customer  ON orders (customer_id);
CREATE INDEX IF NOT EXISTS idx_products_category ON products (category_id);

PRAGMA user_version = 1;
