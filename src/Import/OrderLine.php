<?php

declare(strict_types=1);

namespace OrderInsights\Import;

/** One validated, normalised CSV line, ready to persist. */
final class OrderLine
{
    public function __construct(
        public readonly int $row,
        public readonly string $orderId,
        public readonly string $orderDate,
        public readonly string $customer,
        public readonly string $sku,
        public readonly string $product,
        public readonly string $category,
        public readonly string $categoryKey,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        public readonly string $status,
    ) {
    }
}
