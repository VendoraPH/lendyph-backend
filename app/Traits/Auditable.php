<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * The three automatic audit rows.
     *
     * Each is gated on AuditLogService::modelAuditingIsSuppressed() so that a
     * bulk writer can turn the audit row off WITHOUT turning the model event
     * off. That distinction is load-bearing: `Borrower::booted()` hangs the
     * member's ShareCapitalPledge on the same `created` event, so
     * `withoutEvents()` or `saveQuietly()` would take the pledge with it and
     * lose the amount permanently and silently. See
     * AuditLogService::withoutModelAuditing().
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            AuditLogService::log('created', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            if ($model->wasChanged()) {
                AuditLogService::log('updated', $model, $model->getOriginal(), $model->getChanges());
            }
        });

        static::deleted(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            AuditLogService::log('deleted', $model, $model->getAttributes());
        });
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
