<?php

declare(strict_types=1);

namespace OrderInsights\Controller;

use OrderInsights\Http\HttpException;
use OrderInsights\Repository\MetricsRepository;
use OrderInsights\Support\Config;
use OrderInsights\Support\DateRange;
use PDO;

/** GET /api/summary, /api/top-products, /api/daily-revenue */
final class MetricsController
{
    private readonly MetricsRepository $metrics;

    public function __construct(PDO $pdo)
    {
        $this->metrics = new MetricsRepository($pdo);
    }

    /** @param array<string, mixed> $query */
    public function summary(array $query): array
    {
        return [200, $this->metrics->summary(DateRange::fromQuery($query))];
    }

    /** @param array<string, mixed> $query */
    public function topProducts(array $query): array
    {
        $range = DateRange::fromQuery($query);
        $limit = Config::TOP_PRODUCTS_DEFAULT;

        if (isset($query['limit']) && $query['limit'] !== '') {
            $raw = $query['limit'];
            if (!is_string($raw) || !preg_match('/^\d{1,3}$/D', $raw)
                || (int) $raw < 1 || (int) $raw > Config::TOP_PRODUCTS_MAX) {
                throw new HttpException(400, sprintf("'limit' must be a whole number from 1 to %d.", Config::TOP_PRODUCTS_MAX));
            }
            $limit = (int) $raw;
        }

        return [200, $this->metrics->topProducts($range, $limit)];
    }

    /** @param array<string, mixed> $query */
    public function dailyRevenue(array $query): array
    {
        $range = DateRange::fromQuery($query);
        $days = $range->days();
        if ($days !== null && $days > Config::MAX_RANGE_DAYS) {
            throw new HttpException(400, sprintf('Choose a range of at most %d days.', Config::MAX_RANGE_DAYS));
        }
        return [200, $this->metrics->dailyRevenue($range, Config::MAX_RANGE_DAYS)];
    }
}
