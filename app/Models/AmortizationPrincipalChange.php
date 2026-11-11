<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a period's `principal_due`, from `effective_on`: written by a
 * balance adjustment for each period it restates. The late penalty charges
 * each month on the principal owed on its own day
 * (RepaymentService::penaltyCharges()), so the history is append-only: a row
 * is never updated or deleted.
 */
class AmortizationPrincipalChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'amortization_schedule_id',
        'effective_on',
        'principal_due_before',
        'principal_due_after',
        'loan_adjustment_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'principal_due_before' => 'decimal:2',
            'principal_due_after' => 'decimal:2',
        ];
    }

    /**
     * Record `$schedule`'s principal moving from `$before` to `$after` (pesos)
     * on `$on`, when it moves at all.
     */
    public static function record(AmortizationSchedule $schedule, string $on, float $before, float $after, ?int $loanAdjustmentId = null): void
    {
        if (round($before, 2) === round($after, 2)) {
            return;
        }

        static::create([
            'amortization_schedule_id' => $schedule->id,
            'effective_on' => $on,
            'principal_due_before' => $before,
            'principal_due_after' => $after,
            'loan_adjustment_id' => $loanAdjustmentId,
        ]);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AmortizationSchedule::class, 'amortization_schedule_id');
    }

    /**
     * Refused here rather than in an `updating` listener, so a quiet save is
     * refused too.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw new LogicException('A principal change is never edited.');
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException('A principal change is never deleted.');
    }
}
