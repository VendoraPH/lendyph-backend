<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            AuditLogService::log('created', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            if ($model->wasChanged()) {
                AuditLogService::log('updated', $model, $model->getOriginal(), $model->getChanges());
            }
        });

        static::deleted(function (Model $model) {
            AuditLogService::log('deleted', $model, $model->getAttributes());
        });
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
