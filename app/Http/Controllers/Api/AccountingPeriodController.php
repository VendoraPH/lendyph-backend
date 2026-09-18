<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Services\Accounting\PeriodCalendar;
use App\Services\Accounting\PeriodGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Accounting periods: listing them, closing them, and reopening them.
 *
 * Closing is not cosmetic here. {@see PeriodGuard} is
 * consulted inside JournalPoster — the one writer of journals in this
 * application — so a closed period refuses the manual entry screen, an expense,
 * a fund transfer, a reversal, and every automatic posting a loan release or
 * collection raises, without any of them having to know periods exist.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(private PeriodCalendar $calendar) {}

    #[OA\Get(
        path: '/api/accounting/periods',
        summary: 'List accounting periods',
        description: 'Every month the books cover, oldest first — which is the order that matters here, because the periods anyone opens this screen to close are the OLD ones. Rows are provisioned from the ledger on read (see PeriodCalendar), so there is no "create period" endpoint and none is needed. Returns the raw Laravel paginator envelope ({data, links, meta}); `per_page` is clamped at 100.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'closed'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated periods'),
            new OA\Response(response: 403, description: 'Missing accounting:close'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        // The screen is guarded on `accounting:close`, so listing is too. There
        // is nothing on a period worth reading that the dashboard's
        // `open_period` does not already say more cheaply.
        $this->authorize('accounting:close');

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1`: `?per_page=0` silently becomes 15 inside paginate().
            'per_page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        // A read that writes, on purpose and safely — see PeriodCalendar for
        // why this is not a POST and what makes it idempotent. It creates only
        // missing months and never touches a row that exists, so it cannot
        // reopen anything.
        $this->calendar->ensureProvisioned();

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $periods = AccountingPeriod::query()
            // Display names, not ids — the screen renders `closed_by` straight
            // into a string. Eager-loaded so a drained list of sixty periods is
            // two extra queries rather than a hundred and twenty.
            ->with(['closer:id,first_name,last_name', 'reopener:id,first_name,last_name'])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            // Oldest first, with a total order — see scopeChronological().
            ->chronological()
            ->paginate($perPage)
            ->withQueryString();

        return AccountingPeriodResource::collection($periods);
    }

    #[OA\Post(
        path: '/api/accounting/periods/{id}/close',
        summary: 'Close a period',
        description: 'Locks the month. After this, NOTHING can be posted into it — not a manual entry, not an expense, not a transfer, not a reversal, and not an automatic posting from a loan release or collection. Takes an exclusive row lock so an entry already in flight either finishes first or is refused, rather than landing inside a month that has just been signed off.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Period closed'),
            new OA\Response(response: 403, description: 'Missing accounting:close'),
            new OA\Response(response: 422, description: 'Already closed'),
        ],
    )]
    public function close(AccountingPeriod $period): JsonResponse
    {
        $this->authorize('accounting:close');

        return $this->respond($this->calendar->close($period, request()->user()));
    }

    #[OA\Post(
        path: '/api/accounting/periods/{id}/reopen',
        summary: 'Reopen a period',
        description: 'Takes back a sign-off, and records that it happened — in the audit log and on the period row, which keeps `closed_by`/`closed_at` so the original sign-off is not erased by the reopening.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Period reopened'),
            new OA\Response(response: 403, description: 'Missing accounting:close'),
            new OA\Response(response: 422, description: 'Already open'),
        ],
    )]
    public function reopen(AccountingPeriod $period): JsonResponse
    {
        $this->authorize('accounting:close');

        return $this->respond($this->calendar->reopen($period, request()->user()));
    }

    /** One period in the `{"data": {...}}` envelope `api.post` unwraps. */
    private function respond(AccountingPeriod $period): JsonResponse
    {
        $period->load(['closer:id,first_name,last_name', 'reopener:id,first_name,last_name']);

        return (new AccountingPeriodResource($period))->response();
    }
}
