<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a reminder's story: scheduled, sent, failed, cancelled,
 * skipped, paused, resumed. Append-only: an event is never updated or deleted,
 * whichever path tries (save, quiet save, increment, delete).
 */
class ReminderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'queue_item_id',
        'message_id',
        'loan_id',
        'borrower_id',
        'pause_id',
        'kind',
        'channel',
        'period_number',
        'reason_code',
        'detail',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(ReminderQueueItem::class, 'queue_item_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ReminderMessage::class, 'message_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $kind, array $attributes): self
    {
        return static::query()->create(['kind' => $kind] + $attributes);
    }

    public static function forItem(string $kind, ReminderQueueItem $item, ?string $detail = null, ?int $messageId = null): self
    {
        return static::record($kind, [
            'queue_item_id' => $item->id,
            'message_id' => $messageId,
            'loan_id' => $item->loan_id,
            'borrower_id' => $item->borrower_id,
            'channel' => $item->channel,
            'period_number' => $item->period_number,
            'reason_code' => $item->reason_code,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 255),
            'actor_id' => $item->source === 'manual' ? $item->created_by : null,
        ]);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): never
    {
        throw new LogicException("Reminder events are append-only; event {$this->getKey()} cannot be updated.");
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    {
        throw new LogicException("Reminder events are append-only; event {$this->getKey()} cannot be updated.");
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException("Reminder events are append-only; event {$this->getKey()} cannot be updated.");
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Reminder events are append-only; event {$this->getKey()} cannot be deleted.");
    }
}
