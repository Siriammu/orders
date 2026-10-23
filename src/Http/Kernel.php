<?php

declare(strict_types=1);

namespace OrderInsights\Http;

use OrderInsights\Controller\MetricsController;
use OrderInsights\Controller\UploadController;
use OrderInsights\Support\Database;
use Throwable;

/**
 * Minimal front controller: route table, method enforcement, same-origin
 * check for writes, and one place that turns exceptions into JSON.
 */
final class Kernel
{
    /** path => [method, handler-factory] */
    private function routes(): array
    {
        return [
            '/api/uploads'       => ['POST', static fn ($pdo, $q) => (new UploadController($pdo))()],
            '/api/summary'       => ['GET', static fn ($pdo, $q) => (new MetricsController($pdo))->summary($q)],
            '/api/top-products'  => ['GET', static fn ($pdo, $q) => (new MetricsController($pdo))->topProducts($q)],
            '/api/daily-revenue' => ['GET', static fn ($pdo, $q) => (new MetricsController($pdo))->dailyRevenue($q)],
        ];
    }

    public function handle(string $method, string $path, array $query): void
    {
        $requestId = bin2hex(random_bytes(8));
        header('X-Request-Id: ' . $requestId);

        try {
            $path = rtrim($path, '/') ?: '/';
            $routes = $this->routes();

            if (!isset($routes[$path])) {
                throw new HttpException(404, "No endpoint at {$path}.");
            }

            [$allowed, $handler] = $routes[$path];
            if ($method === 'OPTIONS') {
                http_response_code(204);
                Response::securityHeaders();
                header("Allow: {$allowed}, OPTIONS");
                return;
            }
            if ($method !== $allowed && !($allowed === 'GET' && $method === 'HEAD')) {
                throw new HttpException(405, "Use {$allowed} for {$path}.", [], ['Allow' => "{$allowed}, OPTIONS"]);
            }
            if ($method === 'POST') {
                $this->assertSameOrigin();
            }

            [$status, $body] = $handler(Database::connect(), $query);
            Response::json($status, $body);
        } catch (HttpException $e) {
            Response::error($e->status, $e->getMessage(), $e->details, $e->headers);
        } catch (Throwable $e) {
            // Log the detail server-side; give the client only a reference id.
            error_log(sprintf('[%s] %s: %s in %s:%d', $requestId, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            Response::error(500, 'Something went wrong on the server. Nothing was changed.', ['requestId' => $requestId]);
        }
    }

    /**
     * Browsers always send Origin on cross-site POSTs; reject those so another
     * site cannot push data into a locally running instance (CSRF). Non-browser
     * clients (curl, test harnesses) send no Origin and are allowed.
     */
    private function assertSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            return;
        }
        if ($origin === 'null') {   // sandboxed iframes / file:// pages
            throw new HttpException(403, 'Cross-origin uploads are not allowed.');
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $originHost = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ":{$p}" : '');
        if (!hash_equals(strtolower($host), strtolower($originHost))) {
            throw new HttpException(403, 'Cross-origin uploads are not allowed.');
        }
    }
}
