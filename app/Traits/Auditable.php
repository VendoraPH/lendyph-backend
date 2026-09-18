<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

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
    /**
     * Attributes that must never reach `audit_logs`.
     *
     * The trail stores whole model rows, and for User that meant the bcrypt
     * `password` and `remember_token` were written into `old_values` /
     * `new_values` — which AuditLogResource returns verbatim, behind
     * `audit_logs:view`, a permission the read-only `viewer` role holds. Any
     * viewer could page the trail and walk off with every hash on the
     * deployment, super_admin's included, for offline cracking.
     *
     * Redacted here rather than at the call sites on purpose. The writers WANT
     * their audit rows: `UserController::resetPassword()` deliberately uses
     * `save()` over `saveQuietly()` so the reset is recorded, and
     * `AuthController::login()` already uses `saveQuietly()` citing this very
     * leak as its reason. The row should exist; the secret should not be in
     * it. Fixing it in the trait also covers every future writer, which a
     * per-call-site fix would not.
     *
     * A model opts in by declaring `protected array $auditRedacted`.
     *
     * @return list<string>
     */
    protected static function auditRedactedKeys(Model $model): array
    {
        return property_exists($model, 'auditRedacted')
            ? $model->auditRedacted
            : [];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    protected static function redactForAudit(Model $model, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $keys = static::auditRedactedKeys($model);

        return $keys === [] ? $values : Arr::except($values, $keys);
    }

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            AuditLogService::log('created', $model, null, static::redactForAudit($model, $model->getAttributes()));
        });

        static::updated(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            if ($model->wasChanged()) {
                AuditLogService::log(
                    'updated',
                    $model,
                    static::redactForAudit($model, $model->getOriginal()),
                    static::redactForAudit($model, $model->getChanges()),
                );
            }
        });

        static::deleted(function (Model $model) {
            if (AuditLogService::modelAuditingIsSuppressed()) {
                return;
            }

            AuditLogService::log('deleted', $model, static::redactForAudit($model, $model->getAttributes()));
        });
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
