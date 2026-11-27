<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pause on automated reminders: every reminder (`global`), one member's
 * (`borrower`) or one loan's (`loan`). It applies from `paused_at` until it is
 * resumed or its optional `until` passes. Pausing and resuming each add a
 * reminder event, so a loan's reminder timeline shows them.
 */
class ReminderPause extends Model
{
    use Auditable;

    public const SCOPES = ['global', 'borrower', 'loan'];

    protected $fillable = [
        'scope',
        'borrower_id',
        'loan_id',
        'reason',
        'paused_at',
        'until',
        'paused_by',
        'resumed_at',
        'resumed_by',
    ];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'until' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $pause): void {
            ReminderEvent::record('paused', [
                'pause_id' => $pause->id,
                'loan_id' => $pause->loan_id,
                'borrower_id' => $pause->borrower_id,
                'detail' => $pause->reason,
                'actor_id' => $pause->paused_by,
            ]);
        });

        static::updated(function (self $pause): void {
            if ($pause->wasChanged('resumed_at') && $pause->resumed_at !== null) {
                ReminderEvent::record('resumed', [
                    'pause_id' => $pause->id,
                    'loan_id' => $pause->loan_id,
                    'borrower_id' => $pause->borrower_id,
                    'actor_id' => $pause->resumed_by,
                ]);
            }
        });
    }

    /**
     * Pauses in force at `$at`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForce(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->whereNull('resumed_at')
            ->where('paused_at', '<=', $at)
            ->where(fn (Builder $until) => $until->whereNull('until')->orWhere('until', '>', $at));
    }

    /**
     * The pause in force that stops this loan's reminders, most specific
     * first: the loan's own, then the member's, then a global one.
     */
    public static function applyingTo(int $loanId, int $borrowerId, ?Carbon $at = null): ?self
    {
        return static::query()
            ->inForce($at)
            ->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $loan) => $loan->where('scope', 'loan')->where('loan_id', $loanId))
                ->orWhere(fn (Builder $member) => $member->where('scope', 'borrower')->where('borrower_id', $borrowerId))
                ->orWhere('scope', 'global'))
            ->get()
            ->sortBy(fn (self $pause) => array_search($pause->scope, ['loan', 'borrower', 'global'], true))
            ->first();
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }
}
