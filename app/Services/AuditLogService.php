<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Write one audit entry.
     *
     * `$userId` and `$ipAddress` are trailing and optional, and default to the
     * authenticated user and the current request exactly as this method always
     * has. They exist because those two defaults are both null outside an HTTP
     * request: a queued job or a scheduled command has no `auth()` user and no
     * inbound request, so the summary row a background process writes would name
     * nobody and come from nowhere. The CSV importer is the case in point — it
     * is started by an admin through the UI but does its work later, on the
     * queue, and the person accountable for a bulk import of borrowers and loans
     * has to survive that hop. The caller captures both at the point the human
     * asked for the work and hands them back here.
     *
     * They are trailing so no existing caller has to change: none of them passes
     * more than five arguments today, and the majority pass named arguments
     * anyway.
     *
     * `user_agent` takes no override. It describes the browser that made a
     * request, and a background process genuinely does not have one — inventing
     * a value would be worse than the honest null.
     */
    public static function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => request()->userAgent(),
            'description' => $description,
        ]);
    }
}
