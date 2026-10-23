<?php

declare(strict_types=1);

namespace OrderInsights\Import;

use OrderInsights\Support\Config;

/**
 * Streams an orders CSV and splits it into valid lines and rejected rows.
 *
 * Row numbers follow the brief: "as a spreadsheet shows them (header = row 1)".
 * File-level problems (missing column, empty file) throw ImportException so the
 * controller can return 400 and save nothing.
 */
final class CsvParser
{
    public const REQUIRED_COLUMNS = [
        'order_id', 'order_date', 'customer', 'sku', 'product',
        'category', 'quantity', 'unit_price', 'status',
    ];

    public function __construct(private readonly RowValidator $validator = new RowValidator())
    {
    }

    public function parse(string $path): ImportResult
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportException('The uploaded file could not be read.');
        }

        try {
            [$columns, $width] = $this->readHeader($handle);

            $lines = [];
            $errors = [];
            $orders = [];     // order_id => [row, date, customer, status] of the first accepted line
            $total = 0;
            $row = 1;         // header is row 1

            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $row++;

                if ($fields === [null] || $fields === ['']) {
                    continue;   // blank line: not a data row
                }

                $total++;
                if ($total > Config::MAX_ROWS) {
                    throw new ImportException(
                        sprintf('The file has more than %d data rows. Split it and upload the parts.', Config::MAX_ROWS),
                        413,
                    );
                }

                $reason = $this->checkShape($fields, $width);
                if ($reason !== null) {
                    $errors[] = ['row' => $row, 'reason' => $reason];
                    continue;
                }

                $record = [];
                foreach (self::REQUIRED_COLUMNS as $name) {
                    $record[$name] = (string) $fields[$columns[$name]];
                }

                $result = $this->validator->validate($row, $record);
                if (is_array($result)) {
                    $errors[] = ['row' => $row, 'reason' => implode('; ', $result)];
                    continue;
                }

                $conflict = $this->orderConflict($result, $orders);
                if ($conflict !== null) {
                    $errors[] = ['row' => $row, 'reason' => $conflict];
                    continue;
                }

                $orders[$result->orderId] ??= [$row, $result->orderDate, $result->customer, $result->status];
                $lines[] = $result;
            }

            if ($total === 0) {
                throw new ImportException('The file has a header but no data rows.');
            }

            return new ImportResult($total, $lines, $errors);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * Extra columns are allowed and ignored, but every data row must have
     * as many fields as the header (that catches unquoted commas).
     *
     * @return array{0: array<string, int>, 1: int} [required column => index, header width]
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw new ImportException('The file is empty. Upload a CSV with a header row.');
        }

        $index = [];
        foreach ($header as $i => $name) {
            $name = (string) $name;
            if ($i === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);   // UTF-8 BOM from Excel
            }
            $key = strtolower(trim($name));
            if ($key === '') {
                continue;
            }
            if (isset($index[$key])) {
                throw new ImportException("Column '{$key}' appears more than once in the header.");
            }
            $index[$key] = $i;
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($index)));
        if ($missing !== []) {
            throw new ImportException(
                'Missing required column(s): ' . implode(', ', $missing) . '. Nothing was saved.',
                400,
                ['missingColumns' => $missing],
            );
        }

        return [array_intersect_key($index, array_flip(self::REQUIRED_COLUMNS)), count($header)];
    }

    /** @param list<string|null> $fields */
    private function checkShape(array $fields, int $width): ?string
    {
        $count = count($fields);
        if ($count !== $width) {
            $hint = $count > $width ? ' (is a value with a comma missing its quotes?)' : '';
            return "expected {$width} fields but found {$count}{$hint}";
        }
        foreach ($fields as $value) {
            if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
                return 'row is not valid UTF-8 text';
            }
        }
        return null;
    }

    /**
     * All lines of one order must agree on date, customer and status.
     *
     * @param array<string, array{0:int,1:string,2:string,3:string}> $orders
     */
    private function orderConflict(OrderLine $line, array $orders): ?string
    {
        if (!isset($orders[$line->orderId])) {
            return null;
        }
        [$firstRow, $date, $customer, $status] = $orders[$line->orderId];
        $checks = [
            'status'     => [$line->status, $status],
            'order_date' => [$line->orderDate, $date],
            'customer'   => [$line->customer, $customer],
        ];
        foreach ($checks as $field => [$mine, $theirs]) {
            if ($mine !== $theirs) {
                return "{$field} '{$mine}' does not match '{$theirs}' on row {$firstRow} for order {$line->orderId}";
            }
        }
        return null;
    }
}
