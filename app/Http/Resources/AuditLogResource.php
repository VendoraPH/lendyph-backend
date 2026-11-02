<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogModules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Change-list fields that hold a user id, shown as that user's name.
     */
    private const USER_FOREIGN_KEYS = [
        'account_officer_id', 'acted_by', 'added_by', 'adjusted_by', 'approved_by',
        'auto_pay_enabled_by', 'closed_by', 'created_by', 'initiated_by', 'paid_by_user_id',
        'posted_by', 'processed_by', 'received_by', 'rejected_by', 'released_by',
        'reopened_by', 'transactor_user_id', 'user_id', 'voided_by',
    ];

    /**
     * Never listed as a change: every save moves them.
     */
    private const TIMESTAMP_KEYS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Load, in ONE query, the users named by user foreign keys in these rows'
     * old and new values, and hand the set to every row so the change list can
     * show names instead of ids without a query per row.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public static function loadChangeUsers(iterable $logs): void
    {
        $logs = collect($logs);

        $ids = $logs->flatMap(fn (AuditLog $log) => collect([
            ...(is_array($log->old_values) ? array_intersect_key($log->old_values, array_flip(self::USER_FOREIGN_KEYS)) : []),
            ...(is_array($log->new_values) ? array_intersect_key($log->new_values, array_flip(self::USER_FOREIGN_KEYS)) : []),
        ])->values())
            ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $users = $ids->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $logs->each(fn (AuditLog $log) => $log->setRelation('changeUsers', $users));
    }

    public function toArray(Request $request): array
    {
        $basename = $this->auditable_type ? class_basename($this->auditable_type) : null;

        return [
            'id' => $this->id,
            'user' => new UserResource($this->actor()),
            'action' => $this->action,
            // Frontend-canonical module slug; the list's `module` filter uses
            // the same rules (AuditLogModules).
            'module' => AuditLogModules::for($this->action, $this->auditable_type),
            // Target summary (frontend renders as "type #id — label")
            'target' => $this->auditable_id ? [
                'id' => $this->auditable_id,
                'type' => $basename ?? 'Unknown',
                'label' => $this->resolveAuditableLabel(),
            ] : null,
            // Structured diff of old vs new values
            'changes' => $this->buildChanges(),
            // Legacy fields kept for backward compat
            'auditable_type' => $basename,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Who the row shows as having acted. A login row is written before the
     * user is signed in, so it has no user_id; for auth events the user the
     * row is about is the one who acted. Display only.
     */
    private function actor(): mixed
    {
        $user = $this->whenLoaded('user');

        if ($this->user_id === null
            && AuditLogModules::isAuthAction($this->action)
            && $this->relationLoaded('auditable')
            && $this->auditable instanceof User) {
            return $this->auditable;
        }

        return $user;
    }

    private function resolveAuditableLabel(): string
    {
        if (! $this->relationLoaded('auditable') || ! $this->auditable) {
            $basename = $this->auditable_type ? class_basename($this->auditable_type) : 'Record';

            return "{$basename} #{$this->auditable_id}";
        }

        $model = $this->auditable;

        if ($model instanceof Role) {
            return $model->auditLabel();
        }

        // Prefer the most human-friendly attribute available on the related model.
        foreach (['full_name', 'name', 'application_number', 'loan_account_number', 'receipt_number', 'borrower_code', 'code', 'title'] as $attr) {
            if (isset($model->{$attr}) && $model->{$attr} !== null && $model->{$attr} !== '') {
                return (string) $model->{$attr};
            }
        }

        $basename = class_basename($this->auditable_type);

        return "{$basename} #{$this->auditable_id}";
    }

    /**
     * The fields this row changed, for display.
     *
     * Only keys present in the new values are listed, and only when their
     * shown value differs from the old one. An `updated` row's old values hold
     * the WHOLE original row while its new values hold only the changed
     * columns, so comparing every key listed each untouched field as changed
     * to nothing. Timestamps are never listed, and a `created` row lists
     * nothing: its new values are the whole inserted row and the action
     * already says what happened. User ids show as names. Display only;
     * old_values and new_values are returned as stored.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function buildChanges(): array
    {
        if ($this->action === 'created') {
            return [];
        }

        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];

        $changes = [];

        foreach ($new as $field => $value) {
            $field = (string) $field;

            if (in_array($field, self::TIMESTAMP_KEYS, true)) {
                continue;
            }

            $oldShown = $this->shownFor($field, $old[$field] ?? null);
            $newShown = $this->shownFor($field, $value);

            if ($oldShown === $newShown) {
                continue;
            }

            $changes[] = ['field' => $field, 'old' => $oldShown, 'new' => $newShown];
        }

        return $changes;
    }

    private function shownFor(string $field, mixed $value): ?string
    {
        if ($value === null || ! in_array($field, self::USER_FOREIGN_KEYS, true)
            || ! (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return self::shown($value);
        }

        if (! $this->relationLoaded('changeUsers')) {
            self::loadChangeUsers([$this->resource]);
        }

        return $this->getRelation('changeUsers')->get((int) $value)?->full_name ?? "User #{$value}";
    }

    private static function shown(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
