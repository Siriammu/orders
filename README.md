# Order Insights

Upload an orders CSV, store it normalised in SQLite, and see dashboard numbers computed entirely in SQL.
Plain PHP 8.1+ (no framework, no Composer packages), PDO/SQLite, vanilla JS. Needs `pdo\\\_sqlite`, `mbstring`, `fileinfo`.

## Run (fresh clone)

```bash
git clone git remote add origin https://github.com/Siriammu/orders.git order-insights \\\&\\\& cd order-insights
php -S 127.0.0.1:8000 -t public public/index.php     # open http://127.0.0.1:8000
php tests/acceptance.php                              # optional: 39 checks, all from the brief
```

The database (`storage/database.sqlite`) is created from `schema.sql` on first request. Reset with `php bin/migrate.php --fresh`; import from the CLI with `php bin/import.php file.csv`.

## Where things are

|Path|What|
|-|-|
|`schema.sql`|`customers`, `categories`, `products`, `orders`, `order\\\_items` (PK `(order\\\_id, sku)`), `imports` audit; PK/FK/NOT NULL/CHECK; money in integer cents|
|`queries.sql`|Repeat customers · top product per category (`ROW\\\_NUMBER`) · 7-day calendar with running total|
|`src/Import/`|Streaming CSV parser + row validator (row numbers as a spreadsheet shows them)|
|`src/Repository/OrderWriter.php`|One `BEGIN IMMEDIATE` transaction of `INSERT … ON CONFLICT DO UPDATE` upserts|
|`src/Repository/MetricsRepository.php`|Every API number, as SQL|
|`public/`|Front controller + one-page dashboard|

**Index:** `idx\\\_orders\\\_status\\\_date ON orders(status, order\\\_date)` is used by the **`GET /api/daily-revenue`** query (`MetricsRepository::dailyRevenue`), verified with `EXPLAIN QUERY PLAN` in the tests.

**Design choices:** status lives on `orders`, so a later file that flips an order to `refunded` changes every line of that order. Lines of one order that disagree on date, customer or status are rejected with the conflicting row named. `refundRate` is returned as a ratio (`0.2857`); the UI shows `28.6%`.

## AI note

* Tools: Claude (Anthropic) for scaffolding, SQL drafts and tests; every number checked against section 4 of the brief.
* AI got wrong: the row field-count check compared against the 9 required columns, not the header width, so a file with an extra column rejected every row.
* Caught it with an edge-case fixture (BOM, CRLF, mixed-case headers, extra column); it is now in `tests/acceptance.php`.
* Another hour: handle lines removed from an order on re-upload, PostgreSQL + Docker option, PHPUnit and CI, paginate very large error lists.

