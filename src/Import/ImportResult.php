<?php

declare(strict_types=1);

namespace OrderInsights\Import;

use JsonSerializable;

final class ImportResult implements JsonSerializable
{
    /**
     * @param list<OrderLine>                         $lines
     * @param list<array{row:int, reason:string}>     $errors
     */
    public function __construct(
        public readonly int $totalRows,
        public readonly array $lines,
        public readonly array $errors,
    ) {
    }

    public function imported(): int
    {
        return count($this->lines);
    }

    public function rejected(): int
    {
        return count($this->errors);
    }

    /** @return array{totalRows:int, imported:int, rejected:int, errors:list<array{row:int, reason:string}>} */
    public function jsonSerialize(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'imported'  => $this->imported(),
            'rejected'  => $this->rejected(),
            'errors'    => $this->errors,
        ];
    }
}
