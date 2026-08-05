<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single debit or credit against a loan.
 *
 * Debits increase what the borrower owes (interest charged when a loan is
 * extended); credits reduce it (the payment collected for that interest).
 * Entries are only ever appended — nothing rewrites an earlier one.
 */
class LoanLedgerEntry extends Model
{
    protected $fillable = [
        'loan_id',
        'loan_adjustment_id',
        'repayment_id',
        'type',
        'category',
        'amount',
        'entry_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function loanAdjustment(): BelongsTo
    {
        return $this->belongsTo(LoanAdjustment::class);
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }
}
