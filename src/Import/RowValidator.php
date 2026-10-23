<?php

declare(strict_types=1);

namespace OrderInsights\Import;

/**
 * Validates and normalises a single CSV record against the brief's rules.
 * Returns either an OrderLine or a list of human-readable reasons; it never
 * throws for bad data, because bad rows are skipped, not fatal.
 */
final class RowValidator
{
    public const STATUSES = ['completed', 'refunded', 'cancelled'];

    private const MAX_LEN = [
        'order_id' => 64,
        'customer' => 64,
        'sku'      => 64,
        'product'  => 255,
        'category' => 100,
    ];

    private const MAX_QUANTITY = 1_000_000;

    /**
     * @param array<string, string> $f  column name => raw value
     * @return OrderLine|list<string>
     */
    public function validate(int $row, array $f): OrderLine|array
    {
        $errors = [];
        $v = array_map(static fn (string $s): string => trim($s), $f);

        foreach ($v as $column => $value) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                $errors[] = "{$column} contains control characters";
            }
        }

        foreach (self::MAX_LEN as $column => $max) {
            if ($v[$column] === '') {
                $errors[] = "{$column} is required";
            } elseif (mb_strlen($v[$column]) > $max) {
                $errors[] = "{$column} is longer than {$max} characters";
            }
        }

        $date = $v['order_date'];
        if ($date === '') {
            $errors[] = 'order_date is required';
        } elseif (!self::isCalendarDate($date)) {
            $errors[] = "order_date '{$this->clip($date)}' is not a real calendar date (expected YYYY-MM-DD)";
        }

        $quantity = null;
        if ($v['quantity'] === '') {
            $errors[] = 'quantity is required';
        } elseif (!preg_match('/^\d{1,7}$/D', $v['quantity'])
            || (int) $v['quantity'] < 1
            || (int) $v['quantity'] > self::MAX_QUANTITY) {
            $errors[] = "quantity must be a whole number of 1 or more (got '{$this->clip($v['quantity'])}')";
        } else {
            $quantity = (int) $v['quantity'];
        }

        $cents = null;
        if ($v['unit_price'] === '') {
            $errors[] = 'unit_price is required';
        } else {
            $cents = Money::toCents($v['unit_price']);
            if ($cents === null) {
                $errors[] = "unit_price must be a number of 0 or more with at most 2 decimals (got '{$this->clip($v['unit_price'])}')";
            }
        }

        $status = mb_strtolower($v['status']);
        if ($status === '') {
            $errors[] = 'status is required';
        } elseif (!in_array($status, self::STATUSES, true)) {
            $errors[] = "status must be completed, refunded or cancelled (got '{$this->clip($v['status'])}')";
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        return new OrderLine(
            row: $row,
            orderId: $v['order_id'],
            orderDate: $date,
            customer: $v['customer'],
            sku: $v['sku'],
            product: $v['product'],
            category: $v['category'],
            categoryKey: mb_strtolower($v['category']),
            quantity: $quantity,
            unitPriceCents: $cents,
            status: $status,
        );
    }

    public static function isCalendarDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Echo user input back in messages, but bounded (no log/response flooding). */
    private function clip(string $value): string
    {
        return mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value;
    }
}
