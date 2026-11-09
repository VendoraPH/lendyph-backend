<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exactly what was handed to a driver for one queue item: subject, body and
 * full recipient, kept until `reminders:redact-messages` blanks them two
 * years after the loan closed. `recipient_masked` stays for the history.
 *
 * Unique per queue item, and per loan, channel and `send_date`, so a member
 * gets at most one message per loan and channel a day. Not audited.
 */
class ReminderMessage extends Model
{
    protected $fillable = [
        'queue_item_id',
        'loan_id',
        'borrower_id',
        'rule_id',
        'period_number',
        'channel',
        'template_type',
        'language',
        'subject',
        'body',
        'recipient',
        'recipient_masked',
        'provider',
        'provider_reference',
        'status',
        'attempts',
        'failure_reason',
        'sms_parts',
        'cost',
        'test_mode',
        'send_date',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'redacted_at',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sms_parts' => 'integer',
            'cost' => 'decimal:4',
            'test_mode' => 'boolean',
            'send_date' => 'date',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(ReminderQueueItem::class, 'queue_item_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
