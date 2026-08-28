<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/audit-logs',
        summary: 'List audit logs',
        description: 'Get paginated audit logs with optional filters. Includes `meta.stats.actions` with per-action counts.',
        tags: ['Audit Logs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Matches action, auditable_type, description, or user name'),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auditable_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated audit log list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('audit_logs:view');

        $filters = $this->validatedFilters();

        $logs = $this->buildQuery($filters)
            ->latest('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        /**
         * Action counts so the frontend can render its filter chips without a
         * second request. Scoped to `user_id` and nothing else, which is the
         * same rule RepaymentController and UserController follow for their
         * `meta.stats`, and LoanController and BorrowerController before them:
         * narrow the counts by the ENTITY the page is about, never by `search`,
         * `action` or the date range.
         *
         * The user scope is not cosmetic. Left global — as it was — the chips
         * counted the whole organisation's trail while the rows underneath them
         * showed one user's, so every chip promised results the page could not
         * produce.
         */
        $stats = AuditLog::when(
            filled($filters['user_id'] ?? null),
            fn ($q) => $q->where('user_id', $filters['user_id']),
        )
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        return AuditLogResource::collection($logs)
            ->additional(['meta' => ['stats' => [
                'actions' => $stats,
                'total' => array_sum($stats),
            ]]]);
    }

    #[OA\Get(
        path: '/api/audit-logs/export',
        summary: 'Export audit logs as CSV',
        description: 'Streams a CSV file of all audit logs matching the same filters accepted by the list endpoint. Not paginated — returns every matching row.',
        tags: ['Audit Logs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auditable_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file stream', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function export(): StreamedResponse
    {
        $this->authorize('audit_logs:export');

        // Validated here, not inside the closure below. The closure runs after
        // the response has begun streaming, so a ValidationException thrown from
        // there would be appended to a half-written CSV instead of becoming a
        // 422.
        $filters = $this->validatedFilters();

        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Timestamp',
                'User',
                'Action',
                'Target Type',
                'Target ID',
                'Description',
                'IP Address',
                'Old Values',
                'New Values',
            ]);

            $this->buildQuery($filters)
                ->orderBy('created_at')
                ->chunk(500, function ($chunk) use ($handle) {
                    foreach ($chunk as $log) {
                        fputcsv($handle, [
                            $log->created_at?->toDateTimeString(),
                            $log->user?->full_name ?? ($log->user_id ? "User #{$log->user_id}" : 'system'),
                            $log->action,
                            class_basename($log->auditable_type ?? ''),
                            $log->auditable_id,
                            $log->description,
                            $log->ip_address,
                            $log->old_values ? json_encode($log->old_values) : '',
                            $log->new_values ? json_encode($log->new_values) : '',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    #[OA\Get(
        path: '/api/audit-logs/{id}',
        summary: 'Show audit log',
        description: 'Get a specific audit log entry',
        tags: ['Audit Logs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit log details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AuditLog $auditLog): AuditLogResource
    {
        $this->authorize('audit_logs:view');

        $auditLog->load('user', 'auditable');

        return new AuditLogResource($auditLog);
    }

    /**
     * The filter set both index() and export() read, validated once.
     *
     * `min:1` on `user_id` is not cosmetic: 0 is a valid integer that no row can
     * carry, and it used to reach a `when()` that treats it as absent.
     *
     * @return array<string, mixed>
     */
    private function validatedFilters(): array
    {
        return request()->validate([
            'search' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string'],
            'auditable_type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * Shared query builder used by both index (paginated) and export (streamed).
     *
     * Every filter is gated on filled() — PRESENCE — rather than on truthiness.
     * `Builder::when()` skips its callback for any falsy condition, and `0` and
     * `'0'` are falsy, so `?user_id=0` dropped the scoping filter and returned
     * the entire audit trail — every actor's activity, with `old_values` and
     * `new_values` payloads — to a caller who had asked about one user. Same
     * hole, same fix, as the loan, collateral, repayment, share-capital and user
     * lists. It bit the CSV export too, which shares this builder.
     *
     * @param  array<string, mixed>  $filters  from validatedFilters()
     */
    private function buildQuery(array $filters): Builder
    {
        $search = $filters['search'] ?? null;
        $userId = $filters['user_id'] ?? null;
        $action = $filters['action'] ?? null;
        $auditableType = $filters['auditable_type'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return AuditLog::with('user', 'auditable')
            ->when(filled($search), function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($uq) use ($search) {
                            $uq->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        });
                });
            })
            ->when(filled($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(filled($action), fn ($q) => $q->where('action', $action))
            ->when(filled($auditableType), fn ($q) => $q->where('auditable_type', 'like', "%{$auditableType}%"))
            ->when(filled($dateFrom), fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when(filled($dateTo), fn ($q) => $q->where('created_at', '<=', "{$dateTo} 23:59:59"));
    }
}
