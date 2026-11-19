<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An AMLA monitoring alert (AMLA design §4.7; task §8, §12): one rule, one
 * subject (a member or a walk-in) and one window, citing the rule version it
 * ran under, with the reason and risk indicators found and the transactions
 * behind it. An alert is a reason to review, never a finding: nothing files,
 * blocks or freezes because of one.
 *
 * `open_key` holds one open alert per rule, subject and window; later
 * transactions in that window join it. Kept forever. The one change it allows
 * is clearing, once: a rerun after a void or reversal found the rule no
 * longer holds, noted in words. The review workflow comes with the cases
 * slice.
 *
 * @property int $id
 * @property string $reference
 * @property string $rule_code
 * @property int $rule_version
 * @property string $priority
 * @property string $status
 * @property string|null $open_key
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property int|null $branch_id
 * @property CarbonInterface $window_start
 * @property CarbonInterface $window_end
 * @property string $reason
 * @property list<array{label: string, value: string}> $indicators
 * @property bool $from_history
 * @property CarbonInterface|null $cleared_at
 * @property string|null $cleared_note
 */
class AmlaAlert extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    public const OPEN = 'open';

    public const CLEARED = 'cleared';

    /** @var array<string, string> */
    public const STATUS_WORDS = [self::OPEN => 'Open', self::CLEARED => 'Cleared by a rerun'];

    /** @var array<string, string> */
    public const PRIORITY_WORDS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    private const CLEARING_COLUMNS = ['status', 'open_key', 'cleared_at', 'cleared_note'];

    protected $fillable = [
        'reference', 'rule_code', 'rule_version', 'priority', 'status', 'open_key', 'borrower_id', 'gcash_non_member_id',
        'branch_id', 'window_start', 'window_end', 'reason', 'indicators', 'from_history', 'cleared_at', 'cleared_note',
    ];

    protected function casts(): array
    {
        return [
            'rule_version' => 'integer',
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'branch_id' => 'integer',
            'window_start' => 'date',
            'window_end' => 'date',
            'indicators' => 'array',
            'from_history' => 'boolean',
            'cleared_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** Clear the alert once: a rerun found its rule no longer holds. */
    public function clear(string $note, CarbonInterface $at): void
    {
        $this->forceFill(['status' => self::CLEARED, 'open_key' => null, 'cleared_at' => $at, 'cleared_note' => mb_substr($note, 0, 500)])->save();
    }

    /** @return BelongsToMany<AmlaEvent, $this> */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(AmlaEvent::class, 'amla_alert_events', 'alert_id', 'event_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function walkIn(): BelongsTo
    {
        return $this->belongsTo(GCashNonMember::class, 'gcash_non_member_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('status') === self::OPEN
            && $this->status === self::CLEARED
            && $this->cleared_at !== null
            && $this->open_key === null
            && array_diff(array_keys($this->getDirty()), self::CLEARING_COLUMNS) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::OPEN];
    }
}
