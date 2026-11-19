<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A potential match between a member, walk-in or co-maker and a watchlist
 * entry (task §11), with the score, the threshold it passed and the reasons.
 * The system never confirms one: a person confirms it or clears it as a false
 * positive, with a reason (encrypted), once, while the stored row is still
 * pending. Never deleted.
 *
 * @property int $id
 * @property string $subject_type member|walk_in|co_maker
 * @property int $subject_id
 * @property int $entry_id
 * @property int $version_id
 * @property string $source
 * @property string $match_key
 * @property int $score
 * @property int $threshold
 * @property list<string> $reasons
 * @property string $status pending|confirmed|cleared
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 */
class AmlaScreeningMatch extends Model
{
    use AmlaKeptForever;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CLEARED = 'cleared';

    /** @var array<string, string> */
    public const STATUSES = [
        self::PENDING => 'Pending review',
        self::CONFIRMED => 'Confirmed match',
        self::CLEARED => 'Cleared as a false positive',
    ];

    private const DECISION_COLUMNS = ['status', 'decided_by', 'decided_at', 'decision_reason', 'updated_at'];

    protected $fillable = ['subject_type', 'subject_id', 'entry_id', 'version_id', 'source', 'match_key', 'score', 'threshold', 'reasons', 'status', 'decided_by', 'decided_at', 'decision_reason'];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'entry_id' => 'integer',
            'version_id' => 'integer',
            'score' => 'integer',
            'threshold' => 'integer',
            'reasons' => 'array',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'decision_reason' => 'encrypted',
        ];
    }

    /** Only its one decision, by a person, while it is still pending. */
    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('status') === self::PENDING
            && in_array($this->status, [self::CONFIRMED, self::CLEARED], true)
            && $this->decided_by !== null
            && array_diff(array_keys($this->getDirty()), self::DECISION_COLUMNS) === [];
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::PENDING];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AmlaWatchlistEntry::class, 'entry_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AmlaWatchlistVersion::class, 'version_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
