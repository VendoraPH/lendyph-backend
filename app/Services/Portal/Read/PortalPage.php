<?php

namespace App\Services\Portal\Read;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * A portal list page in the `{success, data, meta}` envelope:
 * `meta{current_page, last_page, per_page, total}` plus any extra figures.
 */
final class PortalPage
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** The `per_page` validation rule set. */
    public const PER_PAGE_RULES = ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE];

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public static function json(LengthAwarePaginator $page, array $rows, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                ...$meta,
            ],
        ]);
    }

    public static function perPage(mixed $value): int
    {
        return (int) ($value ?? self::DEFAULT_PER_PAGE);
    }
}
