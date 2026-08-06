<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmortizationSchedule extends Model
{
    use HasFactory;

    /**
     * Statuses a schedule can be in while it still owes money.
     *
     * Anything outside this list is settled and must never appear in a
     * due/past-due, aging, or outstanding figure.
     */
    public const UNPAID_STATUSES = ['pending', 'partial', 'overdue'];

    /**
     * SQL for the principal a single schedule row still owes.
     *
     * Floored at zero per ROW on purpose: an overpayment recorded against one
     * period must not net off what another period still owes, which is exactly
     * how `SUM(principal_due) - SUM(principal_paid)` used to understate the
     * portfolio. Every aggregate outstanding figure must go through these.
     */
    public static function remainingPrincipalSql(): string
    {
        return 'GREATEST(principal_due - principal_paid, 0)';
    }

    public static function remainingInterestSql(): string
    {
        return 'GREATEST(interest_due - interest_paid, 0)';
    }

    public static function remainingPenaltySql(): string
    {
        return 'GREATEST(penalty_amount - penalty_paid, 0)';
    }

    /**
     * Principal + interest + penalty still owed on a single schedule row.
     *
     * Parenthesised so a caller can safely wrap or scale the whole expression.
     */
    public static function remainingTotalSql(): string
    {
        return '('.self::remainingPrincipalSql()
            .' + '.self::remainingInterestSql()
            .' + '.self::remainingPenaltySql().')';
    }

    protected $fillable = [
        'loan_id',
        'period_number',
        'due_date',
        'principal_due',
        'interest_due',
        'total_due',
        'remaining_balance',
        'status',
        'principal_paid',
        'interest_paid',
        'penalty_amount',
        'penalty_paid',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'total_due' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'penalty_paid' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
