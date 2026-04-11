<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/audit-logs',
        summary: 'List audit logs',
        description: 'Get paginated audit logs with optional filters',
        tags: ['Audit Logs'],
        security: [['sanctum' => []]],
        parameters: [
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

        $logs = AuditLog::with('user')
            ->when(request('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when(request('action'), fn ($q, $action) => $q->where('action', $action))
            ->when(request('auditable_type'), fn ($q, $type) => $q->where('auditable_type', 'like', "%{$type}%"))
            ->when(request('date_from'), fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when(request('date_to'), fn ($q, $date) => $q->where('created_at', '<=', "{$date} 23:59:59"))
            ->latest('created_at')
            ->paginate(min((int) request('per_page', 15), 100));

        return AuditLogResource::collection($logs);
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

        $auditLog->load('user');

        return new AuditLogResource($auditLog);
    }
}
