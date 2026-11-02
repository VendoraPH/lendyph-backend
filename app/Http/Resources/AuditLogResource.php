<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    private const MODULE_MAP = [
        'Borrower' => 'borrowers',
        'CoMaker' => 'borrowers',
        'Loan' => 'loans',
        'LoanProduct' => 'loans',
        'LoanAdjustment' => 'loans',
        'Repayment' => 'payments',
        'Document' => 'borrowers',
        'User' => 'users',
        'Role' => 'roles',
        'Branch' => 'users',
        'Fee' => 'loans',
        'ShareCapitalPledge' => 'collections',
        'ShareCapitalLedger' => 'collections',
    ];

    /**
     * The module a row with no mapped auditable class is filed under.
     */
    public const UNMAPPED_MODULE = 'auth';

    /**
     * Every module name this resource sends, which is also every value the
     * list's `module` filter accepts.
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_values(array_unique([...array_values(self::MODULE_MAP), self::UNMAPPED_MODULE]));
    }

    /**
     * The stored `auditable_type` values (full class names; there is no morph
     * map) filed under a module. Empty for the unmapped module, whose rows are
     * the ones matching none of mappedAuditableTypes().
     *
     * @return list<string>
     */
    public static function auditableTypesFor(string $module): array
    {
        return array_map(
            self::auditableType(...),
            array_keys(array_filter(self::MODULE_MAP, fn (string $mapped) => $mapped === $module)),
        );
    }

    /**
     * @return list<string>
     */
    public static function mappedAuditableTypes(): array
    {
        return array_map(self::auditableType(...), array_keys(self::MODULE_MAP));
    }

    private static function auditableType(string $basename): string
    {
        return 'App\\Models\\'.$basename;
    }

    public function toArray(Request $request): array
    {
        $basename = $this->auditable_type ? class_basename($this->auditable_type) : null;

        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'action' => $this->action,
            // Frontend-canonical module slug derived from auditable type
            'module' => $basename && isset(self::MODULE_MAP[$basename])
                ? self::MODULE_MAP[$basename]
                : 'auth',
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
            $basename = $this->auditable_type ? class_basename($this->auditable_type) : 'Record';

            return "{$basename} #{$this->auditable_id}";
        }

        $model = $this->auditable;

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
     * to nothing. A `created` row lists nothing: its new values are the whole
     * inserted row (id and timestamps included) and the action already says
     * what happened. Display only; old_values and new_values are returned as
     * stored.
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
            $oldShown = self::shown($old[$field] ?? null);
            $newShown = self::shown($value);

            if ($oldShown === $newShown) {
                continue;
            }

            $changes[] = [
                'field' => (string) $field,
                'old' => $oldShown,
                'new' => $newShown,
            ];
        }

        return $changes;
    }

    private static function shown(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
