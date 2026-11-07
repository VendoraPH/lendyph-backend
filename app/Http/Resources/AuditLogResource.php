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
            'user' => new UserResource($this->resource->inferredActor() ?? $this->whenLoaded('user')),
            // True when `user` is the signed-in user of an old login row that
            // recorded no user_id, rather than the row's own actor.
            'actor_inferred' => $this->resource->inferredActor() !== null,
            'action' => $this->action,
            // Frontend-canonical module slug; the list's `module` filter uses
            // the same rules (AuditLogModules).
            'module' => AuditLogModules::for($this->action, $this->auditable_type, $this->description),
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

    private function resolveAuditableLabel(): string
    {
        if (! $this->relationLoaded('auditable') || ! $this->auditable) {
            return $this->fallbackLabel();
        }

        $model = $this->auditable;

        if ($model instanceof Role) {
            return $model->auditLabel();
        }

        // Prefer the most human-friendly attribute available on the related model.
        foreach (['full_name', 'name', 'application_number', 'loan_account_number', 'receipt_number', 'borrower_code', 'code', 'title', 'journal_no', 'reference'] as $attr) {
            if (isset($model->{$attr}) && $model->{$attr} !== null && $model->{$attr} !== '') {
                return (string) $model->{$attr};
            }
        }

        return $this->fallbackLabel();
    }

    /**
     * A target with nothing to name it by: its class in words and its id, e.g.
     * "Accounting journal #557" or "Member #12".
     */
    private function fallbackLabel(): string
    {
        $type = $this->auditable_type ? self::typeInWords(class_basename($this->auditable_type)) : 'Record';

        return "{$type} #{$this->auditable_id}";
    }

    /**
     * A class name in words, the rule of the frontend's `auditTargetType()`:
     * `Borrower…` reads "Member…" and `CoMaker` reads "Co-maker", CamelCase is
     * split into words, and only the first word keeps its capitals ("GCash"
     * keeps them anywhere).
     */
    private static function typeInWords(string $basename): string
    {
        $named = str_replace('CoMaker', 'Co-maker', (string) preg_replace('/^Borrower/', 'Member', $basename));
        $words = explode(' ', (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $named));

        foreach ($words as $i => $word) {
            if ($i > 0 && $word !== 'GCash') {
                $words[$i] = strtolower($word);
            }
        }

        return implode(' ', $words);
    }

    /**
     * The fields this row changed, for display.
     *
     * Only keys present in the new values are listed, and only when their
     * stored value differs from the old one (compared with associative keys
     * sorted, as a MySQL JSON column keeps its own key order). An `updated` row's old values hold
     * the WHOLE original row while its new values hold only the changed
     * columns, so comparing every key listed each untouched field as changed
     * to nothing. Timestamps are never listed, and a `created` row lists
     * nothing: its new values are the whole inserted row and the action
     * already says what happened. A row with no new values (a deletion) lists
     * its old fields, already redacted when written, with `new: null`. User ids show as names, and array
     * values read in words (self::arrayInWords()); when both sides read the
     * same, in detail (self::detailed()), else as JSON. Display only;
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
        $new = is_array($this->new_values) ? $this->new_values : null;

        $changes = [];

        // A row with no new values (a deletion) lists what was removed.
        foreach (array_keys($new ?? $old) as $field) {
            $field = (string) $field;

            if (in_array($field, self::TIMESTAMP_KEYS, true)) {
                continue;
            }

            $oldValue = self::decoded($old[$field] ?? null);
            $newValue = self::decoded($new[$field] ?? null);

            if (self::canonical($oldValue) === self::canonical($newValue)) {
                continue;
            }

            $oldShown = $this->shownFor($field, $oldValue);
            $newShown = $this->shownFor($field, $newValue);

            // Two different values can read the same in words (a fee's amount
            // changed inside a deductions list): show the detail, and JSON as a
            // last resort, so a real change is never hidden.
            if ($oldShown === $newShown) {
                $oldShown = self::detailed($oldValue);
                $newShown = self::detailed($newValue);
            }

            if ($oldShown === $newShown) {
                $oldShown = self::asJson($oldValue);
                $newShown = self::asJson($newValue);
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

        if (is_array($value)) {
            return self::arrayInWords($value);
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /**
     * An array value in words: a list of scalars joined with ", ", a list of
     * records by each one's `name` (else `label`, else `title`), any other
     * list as "N entries", an associative array as "N fields", and an empty
     * one as "none".
     *
     * @param  array<mixed>  $value
     */
    private static function arrayInWords(array $value): string
    {
        if ($value === []) {
            return 'none';
        }

        $count = count($value);

        if (! array_is_list($value)) {
            return $count === 1 ? '1 field' : "{$count} fields";
        }

        if (array_all($value, fn (mixed $item): bool => is_scalar($item))) {
            return implode(', ', array_map(fn (mixed $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, $value));
        }

        $names = array_map(fn (mixed $item): ?string => self::itemName($item), $value);

        if (! in_array(null, $names, true)) {
            return implode(', ', $names);
        }

        return $count === 1 ? '1 entry' : "{$count} entries";
    }

    /**
     * A string holding a JSON array or object, decoded; anything else as it is.
     *
     * An Auditable `updated` row takes its old values from getOriginal(), which
     * casts an array field to an array, and its new values from getChanges(),
     * which holds the raw JSON string. Decoding lets both sides compare and
     * read the same way.
     */
    private static function decoded(mixed $value): mixed
    {
        if (! is_string($value) || ! json_validate($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * A value with every associative array's keys sorted and every number in
     * one form, at every depth, so two values compare equal exactly when they
     * hold the same content: `5` and `"5"`, or `1500` and `1500.0`, are not a
     * change.
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return (string) ($value + 0);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * A value in more detail than self::shown(), for two values that read the
     * same in words: a list of records as each one's name and its peso figure
     * (self::pesosOf()), e.g. "Processing Fee
     * ₱2,000.00, Notarial Fee ₱100.00"; an associative array as "key: value"
     * pairs, keys in words and sorted (a MySQL JSON column keeps its own key
     * order), joined with "; ". Anything else as self::shown().
     */
    private static function detailed(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return self::shown($value);
        }

        if (! array_is_list($value)) {
            $pairs = [];

            foreach (self::canonical($value) as $key => $item) {
                $pairs[] = str_replace('_', ' ', (string) $key).': '.(is_array($item) ? self::arrayInWords($item) : (self::shown($item) ?? 'none'));
            }

            return implode('; ', $pairs);
        }

        $items = [];

        foreach ($value as $item) {
            $name = self::itemName($item);

            if ($name === null) {
                return self::shown($value);
            }

            $pesos = self::pesosOf($item);
            $items[] = $pesos === null ? $name : $name.' ₱'.number_format((float) $pesos, 2);
        }

        return implode(', ', $items);
    }

    /**
     * A record's peso figure: its numeric `amount`, or its numeric `value`
     * when its `type` is `fixed`. Never a percentage: a record typed as one
     * (a loan's percentage deduction keeps its rate in `amount`) has none.
     *
     * @param  array<mixed>  $item
     */
    private static function pesosOf(array $item): int|float|string|null
    {
        $type = is_string($item['type'] ?? null) ? strtolower($item['type']) : null;

        if ($type !== null && str_contains($type, 'percent')) {
            return null;
        }

        if (is_numeric($item['amount'] ?? null)) {
            return $item['amount'];
        }

        return $type === 'fixed' && is_numeric($item['value'] ?? null) ? $item['value'] : null;
    }

    private static function asJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function itemName(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        foreach (['name', 'label', 'title'] as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && (string) $item[$key] !== '') {
                return (string) $item[$key];
            }
        }

        return null;
    }
}
