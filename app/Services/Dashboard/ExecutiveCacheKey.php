<?php

namespace App\Services\Dashboard;

/**
 * The executive dashboard's cache keys (Phase 6 EB-D1): one per endpoint,
 * day (Manila), period, branch scope and filters. The branch ids are cast to
 * integers and sorted, as DashboardService::scopeKey() does, so the same
 * branches in another order or form ("+5") read one entry, and different
 * scopes never share one. Callers' permissions are never in the key: they
 * are applied after the cache. v2: Required Allowance reads the allowance
 * posting's figure and cards carry `previous_state`, so cards cached before
 * that change are never served after it.
 */
final class ExecutiveCacheKey
{
    /**
     * @param  list<int|string>|null  $scope  null for every branch
     * @param  array<string, mixed>  $filters
     */
    public static function for(string $endpoint, DashboardPeriod $period, ?array $scope, array $filters): string
    {
        if ($scope !== null) {
            $scope = array_map('intval', $scope);
            sort($scope);
        }

        $id = fn (string $filter): ?int => filled($filters[$filter] ?? null) ? (int) $filters[$filter] : null;

        return "dashboard:executive:{$endpoint}:v2:".sha1((string) json_encode([
            DashboardPeriod::today()->toDateString(),
            $period->toArray(),
            $scope,
            $id('product_id'),
            $id('officer_id'),
            $id('collector_id'),
        ]));
    }
}
