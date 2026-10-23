<?php

declare(strict_types=1);

namespace OrderInsights\Support;

use OrderInsights\Http\HttpException;
use OrderInsights\Import\RowValidator;

/** Validated, inclusive ?from=&to= filter. Either bound may be omitted. */
final class DateRange
{
    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $from = self::parse($query, 'from');
        $to = self::parse($query, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            throw new HttpException(400, "'from' ({$from}) must be on or before 'to' ({$to}).");
        }

        return new self($from, $to);
    }

    public static function all(): self
    {
        return new self(null, null);
    }

    /** Sentinel bounds keep the SQL a plain, index-friendly BETWEEN. */
    public function lowerBound(): string
    {
        return $this->from ?? '0000-01-01';
    }

    public function upperBound(): string
    {
        return $this->to ?? '9999-12-31';
    }

    public function days(): ?int
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }
        return (int) (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days + 1;
    }

    /** @param array<string, mixed> $query */
    private static function parse(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) {
            return null;
        }
        $value = $query[$key];
        if (!is_string($value)) {
            throw new HttpException(400, "'{$key}' must be a single date in YYYY-MM-DD format.");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!RowValidator::isCalendarDate($value)) {
            throw new HttpException(400, "'{$key}' must be a real date in YYYY-MM-DD format.");
        }
        return $value;
    }
}
