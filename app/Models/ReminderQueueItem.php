<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One reminder for one period, rule and channel, from planning to its end.
 *
 * Unique on loan, period number, due date, rule and channel, so planning
 * twice plans it once, and a moved due date is a different reminder.
 *
 * - `cancelled`: no longer needed (paid, loan closed, schedule changed, or a
 *   later reminder the same day covers it).
 * - `skipped`: still needed but not sendable (paused, opted out, no valid
 *   contact, outside contact hours, Auto-Pay).
 *
 * Not audited: the engine writes these, and reminder_events tells their story.
 */
class ReminderQueueItem extends Model
{
    public const OPEN_STATUSES = ['scheduled', 'queued'];

    public const FINAL_STATUSES = ['sent', 'delivered', 'failed', 'cancelled', 'skipped'];

    protected $fillable = [
        'loan_id',
        'borrower_id',
        'branch_id',
        'amortization_schedule_id',
        'period_number',
        'due_date',
        'rule_id',
        'channel',
        'template_type',
        'amount_due',
        'fire_date',
        'scheduled_at',
        'status',
        'reason_code',
        'reason',
        'attempts',
        'next_attempt_at',
        'claim_token',
        'claimed_at',
        'sending_started_at',
        'recipient_masked',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'due_date' => 'date',
            'fire_date' => 'date',
            'scheduled_at' => 'datetime',
            'amount_due' => 'decimal:2',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sending_started_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'rule_id');
    }

    public function message(): HasOne
    {
        return $this->hasOne(ReminderMessage::class, 'queue_item_id');
    }
}
