<?php

declare(strict_types=1);

namespace OrderInsights\Import;

use RuntimeException;

/** A file-level problem (missing column, empty file...). Nothing is saved. */
final class ImportException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
