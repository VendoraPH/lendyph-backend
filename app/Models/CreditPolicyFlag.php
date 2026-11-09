<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit policy warning on a member, apart from the score: it never moves
 * the number. `first_triggered_at` is when the condition was first seen,
 * `cleared_at` when it stopped holding. A member-wide flag has no `loan_id`;
 * one raised by a loan application's details carries that loan.
 */
class CreditPolicyFlag extends Model
{
    public const HARD = 'hard_flag';

    public const SOFT = 'soft_flag';

    protected $fillable = [
        'borrower_id',
        'loan_id',
        'rule_key',
        'type',
        'label',
        'detail',
        'first_triggered_at',
        'last_seen_at',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'first_triggered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('cleared_at');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
