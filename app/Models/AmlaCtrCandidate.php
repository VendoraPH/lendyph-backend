<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A covered transaction (CTR) candidate (AMLA design §4.6): one customer
 * event in a channel counted as cash, above the CTR threshold of the settings
 * version in force when it was recorded. Exactly the threshold isn't one, and
 * a same-day sum never makes one (that's a structuring alert).
 *
 * Kept forever. The one change it allows is marking, once, that its event was
 * later voided or reversed; its status workflow comes with the filing slice.
 *
 * @property int $id
 * @property string $reference
 * @property int $event_id
 * @property int $settings_version
 * @property string $threshold
 * @property string $amount
 * @property string $direction
 * @property string|null $channel
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property int|null $branch_id
 * @property CarbonInterface $occurred_on
 * @property string $status
 * @property bool $from_history
 * @property string $explanation
 * @property CarbonInterface|null $event_reversed_at
 */
class AmlaCtrCandidate extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    public const DETECTED = 'detected';

    public const REPORT_PREPARED = 'report_prepared';

    public const NOT_REPORTABLE = 'not_reportable';

    /** @var array<string, string> */
    public const STATUS_WORDS = [
        self::DETECTED => 'Detected',
        self::REPORT_PREPARED => 'CTR prepared',
        self::NOT_REPORTABLE => 'Not reportable, with a documented reason',
    ];

    /** @var array<string, list<string>> where a candidate's filing status may go (a returned report sends it back) */
    private const NEXT = [
        self::DETECTED => [self::REPORT_PREPARED, self::NOT_REPORTABLE],
        self::REPORT_PREPARED => [self::DETECTED],
    ];

    protected $fillable = [
        'reference', 'event_id', 'settings_version', 'threshold', 'amount', 'direction', 'channel', 'borrower_id',
        'gcash_non_member_id', 'branch_id', 'occurred_on', 'status', 'from_history', 'explanation', 'event_reversed_at',
        'not_reportable_reason', 'status_changed_by',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'settings_version' => 'integer',
            'threshold' => 'decimal:2',
            'amount' => 'decimal:2',
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'branch_id' => 'integer',
            'occurred_on' => 'date',
            'from_history' => 'boolean',
            'event_reversed_at' => 'datetime',
            'not_reportable_reason' => 'encrypted',
            'status_changed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function markEventReversed(CarbonInterface $at): void
    {
        $this->forceFill(['event_reversed_at' => $at])->save();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AmlaEvent::class, 'event_id');
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

    /**
     * Two kinds of change, never together: marking once that the event was
     * reversed, or one step of the filing status (with who made it, and a
     * reason when not reportable). The UPDATE requires what this copy read.
     */
    protected function amlaAllowsUpdate(): bool
    {
        $dirty = array_keys($this->getDirty());

        if ($dirty === ['event_reversed_at']) {
            return $this->getOriginal('event_reversed_at') === null && $this->event_reversed_at !== null;
        }

        return in_array('status', $dirty, true)
            && array_diff($dirty, ['status', 'status_changed_by', 'not_reportable_reason']) === []
            && in_array($this->status, self::NEXT[(string) $this->getOriginal('status')] ?? [], true)
            && $this->status_changed_by !== null
            && ($this->status !== self::NOT_REPORTABLE || filled($this->not_reportable_reason));
    }

    protected function amlaUpdateConditions(): array
    {
        return array_keys($this->getDirty()) === ['event_reversed_at']
            ? ['event_reversed_at' => null]
            : ['status' => $this->getOriginal('status')];
    }
}
