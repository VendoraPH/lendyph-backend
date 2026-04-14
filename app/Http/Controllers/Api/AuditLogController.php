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

        $logs = $this->buildQuery()
            ->latest('created_at')
            ->paginate(min((int) request('per_page', 15), 100));

        // Action count aggregation so the frontend can render action filter chips without a second request.
        $stats = AuditLog::selectRaw('action, COUNT(*) as count')
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

        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () {
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

            $this->buildQuery()
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
     * Shared query builder used by both index (paginated) and export (streamed).
     */
    private function buildQuery(): Builder
    {
        return AuditLog::with('user', 'auditable')
            ->when(request('search'), function ($q, $search) {
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
            ->when(request('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when(request('action'), fn ($q, $action) => $q->where('action', $action))
            ->when(request('auditable_type'), fn ($q, $type) => $q->where('auditable_type', 'like', "%{$type}%"))
            ->when(request('date_from'), fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when(request('date_to'), fn ($q, $date) => $q->where('created_at', '<=', "{$date} 23:59:59"));
    }
}
