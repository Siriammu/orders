<?php

declare(strict_types=1);

namespace OrderInsights\Repository;

use OrderInsights\Import\ImportResult;
use PDO;
use Throwable;

/**
 * Persists validated lines in ONE transaction: all good rows land, or none do.
 *
 * Every write is an UPSERT keyed on the natural key (sku, order_id,
 * (order_id, sku)), so uploading the same file twice changes no number.
 * The `WHERE ... IS NOT ...` guards skip no-op updates, so updated_at only
 * moves when a value really changed.
 */
final class OrderWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(ImportResult $result, string $filename, string $sha256): void
    {
        // IMMEDIATE takes the write lock up front: no "database is locked"
        // half-way through, and readers keep working thanks to WAL.
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $insertCategory = $this->pdo->prepare(
                'INSERT INTO categories (name_key, name) VALUES (:key, :name)
                 ON CONFLICT (name_key) DO NOTHING'
            );
            $findCategory = $this->pdo->prepare('SELECT category_id FROM categories WHERE name_key = :key');

            $upsertCustomer = $this->pdo->prepare(
                'INSERT INTO customers (customer_id) VALUES (:id) ON CONFLICT (customer_id) DO NOTHING'
            );

            $upsertProduct = $this->pdo->prepare(
                'INSERT INTO products (sku, name, category_id) VALUES (:sku, :name, :category_id)
                 ON CONFLICT (sku) DO UPDATE SET
                     name        = excluded.name,
                     category_id = excluded.category_id
                 WHERE products.name IS NOT excluded.name
                    OR products.category_id IS NOT excluded.category_id'
            );

            $upsertOrder = $this->pdo->prepare(
                "INSERT INTO orders (order_id, order_date, customer_id, status)
                 VALUES (:order_id, :order_date, :customer_id, :status)
                 ON CONFLICT (order_id) DO UPDATE SET
                     order_date  = excluded.order_date,
                     customer_id = excluded.customer_id,
                     status      = excluded.status,
                     updated_at  = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                 WHERE orders.order_date  IS NOT excluded.order_date
                    OR orders.customer_id IS NOT excluded.customer_id
                    OR orders.status      IS NOT excluded.status"
            );

            $upsertItem = $this->pdo->prepare(
                "INSERT INTO order_items (order_id, sku, quantity, unit_price_cents)
                 VALUES (:order_id, :sku, :quantity, :cents)
                 ON CONFLICT (order_id, sku) DO UPDATE SET
                     quantity         = excluded.quantity,
                     unit_price_cents = excluded.unit_price_cents,
                     updated_at       = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                 WHERE order_items.quantity         IS NOT excluded.quantity
                    OR order_items.unit_price_cents IS NOT excluded.unit_price_cents"
            );

            $categoryIds = [];

            foreach ($result->lines as $line) {
                if (!isset($categoryIds[$line->categoryKey])) {
                    $insertCategory->execute([':key' => $line->categoryKey, ':name' => $line->category]);
                    $findCategory->execute([':key' => $line->categoryKey]);
                    $categoryIds[$line->categoryKey] = (int) $findCategory->fetchColumn();
                    $findCategory->closeCursor();
                }

                $upsertCustomer->execute([':id' => $line->customer]);

                $upsertProduct->execute([
                    ':sku'         => $line->sku,
                    ':name'        => $line->product,
                    ':category_id' => $categoryIds[$line->categoryKey],
                ]);

                $upsertOrder->execute([
                    ':order_id'    => $line->orderId,
                    ':order_date'  => $line->orderDate,
                    ':customer_id' => $line->customer,
                    ':status'      => $line->status,
                ]);

                $upsertItem->bindValue(':order_id', $line->orderId);
                $upsertItem->bindValue(':sku', $line->sku);
                $upsertItem->bindValue(':quantity', $line->quantity, PDO::PARAM_INT);
                $upsertItem->bindValue(':cents', $line->unitPriceCents, PDO::PARAM_INT);
                $upsertItem->execute();
            }

            $audit = $this->pdo->prepare(
                'INSERT INTO imports (filename, sha256, total_rows, imported_rows, rejected_rows)
                 VALUES (:filename, :sha, :total, :imported, :rejected)'
            );
            $audit->execute([
                ':filename' => mb_substr($filename, 0, 255) ?: 'upload.csv',
                ':sha'      => $sha256,
                ':total'    => $result->totalRows,
                ':imported' => $result->imported(),
                ':rejected' => $result->rejected(),
            ]);

            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
