<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogModules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

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
     * Change-list fields that hold a branch id or a list of them.
     */
    private const BRANCH_KEYS = ['branch_id', 'branch_ids'];

    /**
     * Columns stored in whole centavos, by the class of the row's target. Any
     * field named `*_centavos` is too, on any target.
     */
    private const CENTAVO_FIELDS = [
        'AccountingJournal' => ['total_debit', 'total_credit'],
        'AccountingExpense' => ['amount', 'amount_paid'],
        'AccountingExpensePayment' => ['amount'],
        'AccountingJournalLine' => ['debit', 'credit'],
        'AccountingReconciliation' => ['statement_balance'],
        'AccountingReconciliationLine' => ['amount'],
    ];

    /**
     * A money field's name, on a row a service wrote (whose keys aren't always
     * columns) or one with no target: a word for money anywhere in the name
     * (`outstanding_balance`, `collected_at_release`, `new_principal`), unless
     * NOT_MONEY_FIELD says it is about money rather than an amount of it.
     */
    private const MONEY_FIELD = '/(^|_)(amount|applied|balance|charge|charged|collected|credit|debit|deductions|due|income|outstanding|overpayment|paid|premium|principal|proceeds|shortfall)(_|$)|^(notarial_fee|snapshot_value|total_payment)$/';

    private const NOT_MONEY_FIELD = '/(_at|_by|_count|_date|_id|_ids|_method|_number|_pct|_percentage|_rate|_status|_type)$|^auto_credit$/';

    /**
     * Fields holding an enum key, shown in words. Never a status: the frontend
     * maps those from the raw value.
     */
    private const ENUM_FIELDS = [
        'adjustment_type', 'cash_flow_category', 'cash_kind', 'category', 'civil_status', 'frequencies',
        'frequency', 'gender', 'insurance_payment_type', 'interest_method', 'interest_option',
        'interest_rate_frequency', 'interest_type', 'kind', 'method', 'normal_balance', 'payment_frequency',
        'payment_method', 'payment_type', 'source', 'term_unit', 'type',
    ];

    /**
     * The enum keys Str::headline() doesn't read the way the app does.
     */
    private const ENUM_WORDS = ['bi_weekly' => 'Bi-Weekly', 'gcash' => 'GCash', 'semi_monthly' => 'Semi-Monthly'];

    /**
     * Load, in ONE query each, the users, roles and branches these rows' old
     * and new values name, and hand the sets to every row so the change list
     * can show names instead of ids and keys without a query per row.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public static function loadChangeNames(iterable $logs): void
    {
        $logs = collect($logs);

        $userIds = self::changeIds($logs, self::USER_FOREIGN_KEYS);
        $branchIds = self::changeIds($logs, self::BRANCH_KEYS);
        $roleKeys = self::changeValues($logs, ['role'])->filter(fn ($key) => is_string($key))->unique()->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $branches = $branchIds->isEmpty()
            ? collect()
            : Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        $roles = $roleKeys->isEmpty()
            ? collect()
            : Role::query()->whereIn('name', $roleKeys)->where('guard_name', 'web')->get()->keyBy('name');

        $logs->each(fn (AuditLog $log) => $log->setRelation('changeUsers', $users)
            ->setRelation('changeBranches', $branches)
            ->setRelation('changeRoles', $roles));
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @param  list<string>  $fields
     * @return Collection<int, mixed>
     */
    private static function changeValues(Collection $logs, array $fields): Collection
    {
        return $logs->flatMap(fn (AuditLog $log) => [
            ...(is_array($log->old_values) ? array_values(array_intersect_key($log->old_values, array_flip($fields))) : []),
            ...(is_array($log->new_values) ? array_values(array_intersect_key($log->new_values, array_flip($fields))) : []),
        ])->map(self::decoded(...))->flatten();
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @param  list<string>  $fields
     * @return Collection<int, int>
     */
    private static function changeIds(Collection $logs, array $fields): Collection
    {
        return self::changeValues($logs, $fields)
            ->filter(self::isId(...))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
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
     * keeps them anywhere). The CSV export reads the same.
     */
    public static function typeInWords(string $basename): string
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
     * its old fields, already redacted when written, with `new: null`. Values
     * read in plain words (self::shownFor()); when both sides read the same, in
     * detail (self::detailed()), else as JSON, unless the same yes or no, moment
     * or date was only written two ways, which isn't a change. Display only;
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
            if ($oldShown === $newShown && $this->isOneReadingField($field, $oldValue, $newValue)) {
                continue;
            }

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

    /**
     * A value in plain words: a user, branch or role by name, a boolean as Yes
     * or No, a timestamp in Manila time, a date as its date, money in pesos and
     * an enum key in words. Anything else, a status included, as stored.
     */
    private function shownFor(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->namedBy($field, $value)
            ?? self::booleanInWords($field, $value, $this->castOf($field))
            ?? $this->timeInWords($field, $value)
            ?? $this->pesosFor($field, $value)
            ?? self::enumInWords($field, $value)
            ?? self::shown($value);
    }

    private function namedBy(string $field, mixed $value): ?string
    {
        $isUser = in_array($field, self::USER_FOREIGN_KEYS, true) && self::isId($value);
        $isBranch = in_array($field, self::BRANCH_KEYS, true);
        $isRole = $field === 'role' && is_string($value);

        if (! $isUser && ! $isBranch && ! $isRole) {
            return null;
        }

        if (! $this->relationLoaded('changeUsers')) {
            self::loadChangeNames([$this->resource]);
        }

        if ($isUser) {
            return $this->getRelation('changeUsers')->get((int) $value)?->full_name ?? "User #{$value}";
        }

        if ($isRole) {
            return ($this->getRelation('changeRoles')->get($value) ?? new Role(['name' => $value]))->auditName();
        }

        if (self::isId($value)) {
            return $this->getRelation('changeBranches')->get((int) $value) ?? "Branch #{$value}";
        }

        if (! is_array($value) || $value === [] || ! array_all($value, self::isId(...))) {
            return null;
        }

        return implode(', ', array_map(
            fn ($id) => $this->getRelation('changeBranches')->get((int) $id) ?? "Branch #{$id}",
            $value,
        ));
    }

    /**
     * Whether a boolean or time field that reads the same on both sides is one
     * value written two ways (`true` and `1`, a UTC and a Manila timestamp).
     */
    private function isOneReadingField(string $field, mixed $old, mixed $new): bool
    {
        return (self::booleanInWords($field, $old, $this->castOf($field)) !== null && self::booleanInWords($field, $new, $this->castOf($field)) !== null)
            || ($this->timeInWords($field, $old) !== null && $this->timeInWords($field, $new) !== null);
    }

    /**
     * The cast the row's target class declares for this field, or null.
     */
    private function castOf(string $field): ?string
    {
        $cast = self::castsOf($this->auditable_type)[$field] ?? null;

        return is_string($cast) ? $cast : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function castsOf(?string $type): array
    {
        static $casts = [];

        if ($type === null) {
            return [];
        }

        return $casts[$type] ??= is_subclass_of($type, Model::class) ? (new $type)->getCasts() : [];
    }

    private static function booleanInWords(string $field, mixed $value, ?string $cast): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $isBooleanField = $cast === 'boolean' || $field === 'auto_credit' || preg_match('/^(is|has)_/', $field) === 1;

        if (! $isBooleanField || ! in_array($value, [0, 1, '0', '1'], true)) {
            return null;
        }

        return (int) $value === 1 ? 'Yes' : 'No';
    }

    /**
     * A timestamp in Manila time, whichever way it was stored: JSON writes a
     * cast model's value in UTC, a raw attribute has the app timezone's wall
     * clock. A date column reads as its Manila date, with no time. A value that
     * isn't a date reads as stored.
     */
    private function timeInWords(string $field, mixed $value): ?string
    {
        $cast = (string) $this->castOf($field);
        $isDate = str_starts_with($cast, 'date') && ! str_starts_with($cast, 'datetime');
        $isTimestamp = str_contains($cast, 'datetime') || str_ends_with($field, '_at');

        if ((! $isDate && ! $isTimestamp) || ! is_string($value) || $value === '') {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($value, config('app.timezone'))->setTimezone('Asia/Manila');
        } catch (Throwable) {
            return null;
        }

        return $time->format($isDate ? 'M j, Y' : 'M j, Y, g:i A');
    }

    /**
     * Money in pesos with two decimals, in whichever form it was stored
     * (`1500`, `"1500.0"`, `"1,500.00"`), a column stored in centavos converted.
     * A rate, a count, a code or a value that isn't a number reads as stored.
     */
    private function pesosFor(string $field, mixed $value): ?string
    {
        $plain = is_string($value) ? str_replace([',', '₱', ' '], '', $value) : $value;

        if (is_bool($plain) || ! is_numeric($plain)) {
            return null;
        }

        $inCentavos = str_ends_with($field, '_centavos')
            || in_array($field, self::CENTAVO_FIELDS[class_basename((string) $this->auditable_type)] ?? [], true);

        if ($inCentavos) {
            $amount = (float) $plain / 100;
        } elseif ($this->isPesoField($field)) {
            $amount = (float) $plain;
        } else {
            return null;
        }

        return ($amount < 0 ? '-' : '').'₱'.number_format(abs($amount), 2);
    }

    /**
     * A column the target casts to two decimals is money, unless named as a
     * rate. Otherwise (a service's own key, a row with no target) the name says.
     */
    private function isPesoField(string $field): bool
    {
        if (preg_match(self::NOT_MONEY_FIELD, $field) === 1) {
            return false;
        }

        return $this->castOf($field) === 'decimal:2' || preg_match(self::MONEY_FIELD, $field) === 1;
    }

    /**
     * An enum key, or a list of them, in words: "semi_monthly" reads
     * "Semi-Monthly". A value that isn't written as a key reads as stored.
     */
    private static function enumInWords(string $field, mixed $value): ?string
    {
        if (! in_array($field, self::ENUM_FIELDS, true)) {
            return null;
        }

        if (is_string($value)) {
            return self::enumKeyInWords($value);
        }

        if (! is_array($value) || $value === [] || ! array_all($value, fn ($item) => is_string($item) && self::enumKeyInWords($item) !== null)) {
            return null;
        }

        return implode(', ', array_map(self::enumKeyInWords(...), $value));
    }

    private static function enumKeyInWords(string $key): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            return null;
        }

        return self::ENUM_WORDS[$key] ?? Str::headline($key);
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
     * hold the same content: `5` and `"5"`, or `1500` and `"1500.0"`, are not
     * a change. Only an int, a float, or a string already written as a plain
     * number counts as a number; any other string (a mobile number's leading
     * zero, a padded code, a long reference) is compared exactly as stored.
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value) === 1)) {
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
     * when its `type` is `fixed`. A record typed as a percentage has one only
     * when it carries `original_value`: a saved loan deduction keeps the rate
     * there and the computed pesos in `amount` (LoanService::deductionItems()),
     * while a stated rate without it keeps the rate in `amount`, which must
     * never read as pesos.
     *
     * @param  array<mixed>  $item
     */
    private static function pesosOf(array $item): int|float|string|null
    {
        $type = is_string($item['type'] ?? null) ? strtolower($item['type']) : null;

        if ($type !== null && str_contains($type, 'percent') && ! array_key_exists('original_value', $item)) {
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
