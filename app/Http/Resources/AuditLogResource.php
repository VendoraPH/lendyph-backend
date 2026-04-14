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
        'Role' => 'users',
        'Branch' => 'users',
        'Fee' => 'loans',
        'ShareCapitalPledge' => 'collections',
        'ShareCapitalLedger' => 'collections',
    ];

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

    private function buildChanges(): array
    {
        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];

        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changes = [];

        foreach ($fields as $field) {
            $oldValue = $old[$field] ?? null;
            $newValue = $new[$field] ?? null;

            // Skip noise: unchanged values
            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'old' => $oldValue !== null ? (string) (is_scalar($oldValue) ? $oldValue : json_encode($oldValue)) : null,
                'new' => $newValue !== null ? (string) (is_scalar($newValue) ? $newValue : json_encode($newValue)) : null,
            ];
        }

        return $changes;
    }
}
