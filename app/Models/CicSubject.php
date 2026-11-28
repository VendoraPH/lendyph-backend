<?php

namespace App\Models;

use App\Exceptions\CicRecordIsFrozenException;
use App\Traits\GuardsCicWrites;
use Database\Factories\CicSubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The CIC subject register: a member or co-maker the lender has captured for
 * reporting, with the Provider Subject No CIC knows them by.
 *
 * - Exactly one of `borrower_id` (a `member` subject) and `co_maker_id` (a
 *   `co_maker` subject) is set, matching `subject_type`.
 * - Who the row is (type, member, co-maker) and its `subject_no` never change
 *   once set, and the row is never deleted, so CIC always reads the same person
 *   under the same number. A member with a row can't be purged
 *   (BorrowerPurgeService).
 * - `first_captured_at` and `first_reported_submission_id` are set once.
 * - `subject_no` is never mass-assigned: the capture sets it.
 *
 * @property int $id
 * @property string $subject_type member|co_maker
 * @property int|null $borrower_id
 * @property int|null $co_maker_id
 * @property string $subject_no
 * @property Carbon|null $first_captured_at
 * @property int|null $first_reported_submission_id
 */
class CicSubject extends Model
{
    /** @use HasFactory<CicSubjectFactory> */
    use GuardsCicWrites, HasFactory;

    public const TYPE_MEMBER = 'member';

    public const TYPE_CO_MAKER = 'co_maker';

    /** The Provider Subject No's column length (CSDF ID5). */
    public const SUBJECT_NO_MAX = 38;

    /** Who the row is: never changes. */
    private const IDENTITY = ['subject_type', 'borrower_id', 'co_maker_id'];

    /** Set once, then kept. */
    private const SET_ONCE = ['subject_no', 'first_captured_at', 'first_reported_submission_id'];

    protected $fillable = ['subject_type', 'borrower_id', 'co_maker_id', 'first_captured_at'];

    protected function casts(): array
    {
        return [
            'borrower_id' => 'integer',
            'co_maker_id' => 'integer',
            'first_captured_at' => 'datetime',
            'first_reported_submission_id' => 'integer',
        ];
    }

    /**
     * Whether the register holds this member: as a member subject, or through
     * a co-maker subject that is theirs (a co-maker on their loan, or the
     * member acting as someone's co-maker). Such a member can't be purged.
     */
    public static function holdsMember(int $borrowerId): bool
    {
        return static::query()->where('borrower_id', $borrowerId)->exists()
            || static::query()->whereHas('coMaker', fn ($coMakers) => $coMakers
                ->where('borrower_id', $borrowerId)
                ->orWhere('member_borrower_id', $borrowerId))->exists();
    }

    protected function cicGuardInsert(): void
    {
        $this->assertWellFormed();
    }

    protected function cicGuardUpdate(): void
    {
        $dirty = $this->cicDirtyColumns();

        if (array_intersect($dirty, self::IDENTITY) !== []) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a subject always names the same member or co-maker.');
        }

        foreach (array_intersect($dirty, self::SET_ONCE) as $column) {
            if ($this->getOriginal($column) !== null) {
                throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), $column === 'subject_no'
                    ? 'a subject\'s number never changes once set: CIC knows the person by it.'
                    : "a subject's {$column} is set once and kept.");
            }
        }

        $this->assertWellFormed();
    }

    protected function cicGuardDelete(): void
    {
        throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'the subject register is kept for good.');
    }

    private function assertWellFormed(): void
    {
        $member = $this->borrower_id !== null;
        $coMaker = $this->co_maker_id !== null;
        $matches = match ($this->subject_type) {
            self::TYPE_MEMBER => $member && ! $coMaker,
            self::TYPE_CO_MAKER => $coMaker && ! $member,
            default => false,
        };

        if (! $matches) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a subject names exactly one member or one co-maker, matching its type.');
        }

        $number = $this->subject_no;

        if (! is_string($number) || trim($number) === '' || mb_strlen($number) > self::SUBJECT_NO_MAX) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a subject needs a number of 1 to '.self::SUBJECT_NO_MAX.' characters.');
        }
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function coMaker(): BelongsTo
    {
        return $this->belongsTo(CoMaker::class);
    }

    public function firstReportedSubmission(): BelongsTo
    {
        return $this->belongsTo(CicSubmission::class, 'first_reported_submission_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CicCaptureRecord::class, 'cic_subject_id');
    }
}
