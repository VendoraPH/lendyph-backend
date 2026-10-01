<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class LoanAdjustment extends Model
{
    use Auditable, HasFactory;

    /**
     * The `new_values` fields each requested type carries, and the only ones
     * it applies. `extension` rows are absent: POST /loans/{loan}/extend writes
     * them already applied, and they never carry a request.
     */
    public const NEW_VALUE_FIELDS = [
        'restructure' => ['interest_rate', 'term', 'frequency'],
        'penalty_waiver' => ['waive_all', 'schedule_ids'],
        'balance_adjustment' => ['adjustment_amount', 'reason'],
        'term_extension' => ['additional_terms'],
    ];

    protected $fillable = [
        'adjustment_number',
        'loan_id',
        'adjustment_type',
        'description',
        'old_values',
        'new_values',
        'status',
        'remarks',
        'adjusted_by',
        'approved_by',
        'approved_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * The fields in `$newValues` that belong to another type, not `$type`.
     *
     * @param  array<string, mixed>  $newValues
     * @return list<string>
     */
    public static function foreignFields(string $type, array $newValues): array
    {
        return array_values(array_diff(array_keys($newValues), self::NEW_VALUE_FIELDS[$type] ?? []));
    }

    /**
     * This adjustment's own `new_values` fields, and nothing another type would carry.
     *
     * @return array<string, mixed>
     */
    public function ownNewValues(): array
    {
        return Arr::only($this->new_values ?? [], self::NEW_VALUE_FIELDS[$this->adjustment_type] ?? []);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class);
    }

    protected static function booted(): void
    {
        static::creating(function (LoanAdjustment $adj) {
            $lastCode = static::query()->orderByDesc('id')->value('adjustment_number');
            $nextNum = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
            $adj->adjustment_number = 'ADJ-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function adjustedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
