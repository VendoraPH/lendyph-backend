<?php

namespace App\Models;

use App\Exceptions\CicRecordIsFrozenException;
use Database\Factories\CicSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A CIC submission (prompt §15): one file built from a complete capture,
 * standard (type 0) or a correction (type 1).
 *
 * - It goes generated → encrypted → submitted → accepted or rejected, one step
 *   at a time, never back.
 * - What it was built from and as (capture, date, type, correction link and
 *   reason, filename, record count, TXT checksum, generator) never changes.
 *   Each later step's fields are written once.
 * - `download_count` only goes up. Rows are never deleted.
 * - Only the `.zip.gpg` is kept (`gpg_path`, private disk); the plain TXT and
 *   ZIP never are.
 *
 * @property int $id
 * @property int $capture_id
 * @property Carbon $reference_date
 * @property int $submission_type 0 standard, 1 correction
 * @property int|null $corrects_submission_id
 * @property string|null $correction_reason
 * @property string $filename
 * @property int $record_count
 * @property string $txt_sha256
 * @property string|null $zip_sha256
 * @property string|null $gpg_sha256
 * @property string|null $gpg_path
 * @property string|null $key_fingerprint
 * @property string $status generated|encrypted|submitted|accepted|rejected
 * @property string|null $comments
 * @property int $generated_by
 * @property Carbon $generated_at
 * @property int|null $encrypted_by
 * @property Carbon|null $encrypted_at
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledgement_notes
 * @property string|null $cic_reference_no CIC's own reference for its answer (§14.8)
 * @property int $download_count
 */
class CicSubmission extends Model
{
    /** @use HasFactory<CicSubmissionFactory> */
    use HasFactory;

    public const TYPE_STANDARD = 0;

    public const TYPE_CORRECTION = 1;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_ENCRYPTED = 'encrypted';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** Each status and the statuses it may move to. */
    private const MOVES = [
        self::STATUS_GENERATED => [self::STATUS_ENCRYPTED],
        self::STATUS_ENCRYPTED => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_ACCEPTED => [],
        self::STATUS_REJECTED => [],
    ];

    /** Fixed when the file is generated. */
    private const FIXED = [
        'capture_id', 'reference_date', 'submission_type', 'corrects_submission_id', 'correction_reason',
        'filename', 'record_count', 'txt_sha256', 'comments', 'generated_by', 'generated_at',
    ];

    /** Written once by a later step, then kept. */
    private const SET_ONCE = [
        'zip_sha256', 'gpg_sha256', 'gpg_path', 'key_fingerprint', 'encrypted_by', 'encrypted_at',
        'submitted_by', 'submitted_at', 'acknowledged_by', 'acknowledged_at', 'acknowledgement_notes',
        'cic_reference_no',
    ];

    /** The submission services set every field; nothing comes from a request. */
    protected $fillable = [];

    protected $attributes = [
        'status' => self::STATUS_GENERATED,
        'download_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'capture_id' => 'integer',
            'reference_date' => 'date:Y-m-d',
            'submission_type' => 'integer',
            'corrects_submission_id' => 'integer',
            'record_count' => 'integer',
            'generated_by' => 'integer',
            'generated_at' => 'datetime',
            'encrypted_by' => 'integer',
            'encrypted_at' => 'datetime',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'acknowledged_by' => 'integer',
            'acknowledged_at' => 'datetime',
            'download_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CicSubmission $submission): void {
            $submission->assertAllowedChange();
        });

        static::deleting(function (CicSubmission $submission): void {
            throw CicRecordIsFrozenException::because($submission->getTable(), $submission->getKey(), 'the submission history is kept for good.');
        });
    }

    public function isCorrection(): bool
    {
        return $this->submission_type === self::TYPE_CORRECTION;
    }

    private function assertAllowedChange(): void
    {
        $dirty = array_keys($this->getDirty());

        if (array_intersect($dirty, self::FIXED) !== []) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'what a submission was built from never changes; make a correction instead.');
        }

        foreach (array_intersect($dirty, self::SET_ONCE) as $column) {
            if ($this->getOriginal($column) !== null) {
                throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), "a submission's {$column} is written once and kept.");
            }
        }

        if (in_array('status', $dirty, true)) {
            $from = (string) $this->getOriginal('status');

            if (! in_array($this->status, self::MOVES[$from] ?? [], true)) {
                throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), "a {$from} submission can't become {$this->status}.");
            }
        }

        if (in_array('download_count', $dirty, true) && (int) $this->download_count < (int) $this->getOriginal('download_count')) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'the download count only goes up.');
        }
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(CicCapture::class, 'capture_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_submission_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_submission_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function encrypter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encrypted_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
