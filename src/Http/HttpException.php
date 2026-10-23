<?php

declare(strict_types=1);

namespace OrderInsights\Http;

use RuntimeException;

/** An expected client error with a safe, user-facing message. */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
