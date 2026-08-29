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
     * has. They exist because neither default means anything outside an HTTP
     * request: a queued job or a scheduled command has no `auth()` user, and it
     * has no inbound request either — Laravel's SetRequestForConsole bootstrapper
     * synthesises one with `Request::create()`, whose defaults make
     * `request()->ip()` the literal `127.0.0.1` for every background write on
     * every deployment. So the summary row a background process writes would
     * name nobody and claim to come from the loopback address of whichever box
     * happened to run it. The CSV importer is the case in point — it
     * is started by an admin through the UI but does its work later, on the
     * queue, and the person accountable for a bulk import of borrowers and loans
     * has to survive that hop. The caller captures both at the point the human
     * asked for the work and hands them back here.
     *
     * They are trailing so no existing caller has to change: none of them passes
     * more than five arguments today, and the majority pass named arguments
     * anyway.
     *
     * `user_agent` takes no override, and what it records for a background write
     * is NOT the honest null an earlier version of this note promised. The
     * synthesised console request above carries Symfony's own default header, so
     * every audit row a queued job or a scheduled command writes reads
     * `user_agent: "Symfony"` — verified by running it, not inferred. Noise
     * rather than a lie, since no browser is named, but it must not be read as
     * evidence that one was involved. Making it genuinely null means adding a
     * `$userAgent` parameter beside the two above; until somebody needs that,
     * `user_id` and `ip_address` are what identify a background write, and both
     * are already overridable.
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
