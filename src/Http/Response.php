<?php

declare(strict_types=1);

namespace OrderInsights\Http;

/** Emits responses with a consistent set of security headers. */
final class Response
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; "
        . "form-action 'self'; frame-ancestors 'none'";

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Content-Security-Policy: ' . self::CSP);
        header_remove('X-Powered-By');
    }

    /**
     * @param array<mixed>|\JsonSerializable $body
     * @param array<string, string>          $headers
     */
    public static function json(int $status, array|\JsonSerializable $body, array $headers = []): void
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $message, array $details = [], array $headers = []): void
    {
        self::json($status, ['error' => ['status' => $status, 'message' => $message] + $details], $headers);
    }
}
