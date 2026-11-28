<?php

namespace App\Models;

use App\Exceptions\CicRecordIsFrozenException;
use App\Traits\GuardsCicWrites;
use Database\Factories\CicCaptureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A CIC capture: every reportable record's CSDF values, frozen at a reference
 * date (CicCaptureRecord), with a checksum over them (computeChecksum()).
 * Files and corrections are built only from a complete capture.
 *
 * - A capture goes capturing → complete or capturing → failed, once.
 * - Its reference date and kind never change.
 * - Once complete, nothing about it changes and it is never deleted. A running
 *   or failed capture can be deleted, and its records go with it.
 * - One complete month_end capture per reference date: a second is refused,
 *   here and by the database's unique `month_end_key`.
 *
 * @property int $id
 * @property Carbon $reference_date
 * @property string $kind month_end|test|correction
 * @property string $status capturing|complete|failed
 * @property int $subject_count
 * @property int $contract_count
 * @property int $event_count
 * @property string|null $checksum
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property int|null $created_by null when the schedule made it
 * @property int|null $corrects_capture_id the month-end capture a correction corrects
 */
class CicCapture extends Model
{
    /** @use HasFactory<CicCaptureFactory> */
    use GuardsCicWrites, HasFactory;

    public const KIND_MONTH_END = 'month_end';

    public const KIND_TEST = 'test';

    public const KIND_CORRECTION = 'correction';

    public const STATUS_CAPTURING = 'capturing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> the record types a capture holds, in the file's order */
    public const RECORD_TYPES = ['ID', 'CI', 'CN', 'NE'];

    /** The capture service sets every field; nothing comes from a request. */
    protected $fillable = [];

    /** `month_end_key` is the database's own column (one complete month-end per date); never written. */
    protected $hidden = ['month_end_key'];

    protected $attributes = [
        'status' => self::STATUS_CAPTURING,
        'subject_count' => 0,
        'contract_count' => 0,
        'event_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date:Y-m-d',
            'subject_count' => 'integer',
            'contract_count' => 'integer',
            'event_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_by' => 'integer',
            'corrects_capture_id' => 'integer',
        ];
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * The checksum over this capture's records: SHA-256 of each record's type,
     * key and own checksum, one per line, in the file's record order and then
     * by key. The capture service stores it when it marks the capture complete.
     */
    public function computeChecksum(): string
    {
        $context = hash_init('sha256');

        foreach (self::RECORD_TYPES as $type) {
            $this->records()->getQuery()->toBase()
                ->where('record_type', $type)
                ->orderBy('record_key')
                ->select(['record_type', 'record_key', 'checksum'])
                ->cursor()
                ->each(function (object $row) use ($context): void {
                    hash_update($context, "{$row->record_type}|{$row->record_key}|{$row->checksum}\n");
                });
        }

        return hash_final($context);
    }

    protected function cicGuardInsert(): void
    {
        unset($this->attributes['month_end_key']);

        if ($this->status === self::STATUS_COMPLETE) {
            $this->assertOnlyCompleteMonthEnd();
        }
    }

    protected function cicGuardUpdate(): void
    {
        unset($this->attributes['month_end_key']);
        $dirty = $this->cicDirtyColumns();
        $from = (string) $this->getOriginal('status');

        if ($from !== self::STATUS_CAPTURING && $dirty !== []) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), "a {$from} capture never changes; take a new capture instead.");
        }

        if (array_intersect($dirty, ['reference_date', 'kind']) !== []) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a capture\'s reference date and kind never change.');
        }

        if ($this->status === self::STATUS_COMPLETE) {
            $this->assertOnlyCompleteMonthEnd();
        }
    }

    protected function cicGuardDelete(): void
    {
        if ($this->getOriginal('status') === self::STATUS_COMPLETE) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a complete capture is kept for good: files and corrections are built from it.');
        }
    }

    private function assertOnlyCompleteMonthEnd(): void
    {
        if ($this->kind !== self::KIND_MONTH_END) {
            return;
        }

        $other = static::query()
            ->where('kind', self::KIND_MONTH_END)
            ->where('status', self::STATUS_COMPLETE)
            ->whereDate('reference_date', $this->reference_date)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($other) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'this reference date already has a complete month-end capture.');
        }
    }

    public function records(): HasMany
    {
        return $this->hasMany(CicCaptureRecord::class, 'capture_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CicSubmission::class, 'capture_id');
    }

    /** The complete month-end capture a correction capture corrects. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_capture_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
