<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string|null $counted_amount what the rule counted when the alert opened, the figure its reason states (null before AMLA-B8)
 * @property int|null $counted_transactions the transactions behind `counted_amount`
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

    public const ASSIGNED = 'assigned';

    public const UNDER_REVIEW = 'under_review';

    public const ESCALATED = 'escalated';

    public const CLOSED = 'closed';

    /** @var array<string, string> */
    public const STATUS_WORDS = [
        self::OPEN => 'Open',
        self::ASSIGNED => 'Assigned',
        self::UNDER_REVIEW => 'Under review',
        self::ESCALATED => 'Escalated to a case',
        self::CLOSED => 'Closed with a documented reason',
        self::CLEARED => 'Cleared by a rerun',
    ];

    /** @var list<string> statuses a person can still act on */
    public const WORKABLE = [self::OPEN, self::ASSIGNED, self::UNDER_REVIEW];

    /** @var array<string, list<string>> each workable status and where it may go next (AMLA-B6 workflow, B3's rerun clearing) */
    private const NEXT = [
        self::OPEN => [self::ASSIGNED, self::UNDER_REVIEW, self::ESCALATED, self::CLOSED, self::CLEARED],
        self::ASSIGNED => [self::ASSIGNED, self::UNDER_REVIEW, self::ESCALATED, self::CLOSED],
        self::UNDER_REVIEW => [self::UNDER_REVIEW, self::ESCALATED, self::CLOSED],
    ];

    /** @var array<string, string> */
    public const PRIORITY_WORDS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    private const WORKFLOW_COLUMNS = ['status', 'open_key', 'cleared_at', 'cleared_note', 'assigned_to', 'case_id', 'closed_at'];

    protected $fillable = [
        'reference', 'rule_code', 'rule_version', 'priority', 'status', 'open_key', 'borrower_id', 'gcash_non_member_id',
        'branch_id', 'window_start', 'window_end', 'reason', 'indicators', 'counted_amount', 'counted_transactions', 'from_history', 'cleared_at', 'cleared_note',
        'assigned_to', 'case_id', 'closed_at',
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
            'counted_transactions' => 'integer',
            'from_history' => 'boolean',
            'cleared_at' => 'datetime',
            'assigned_to' => 'integer',
            'case_id' => 'integer',
            'closed_at' => 'datetime',
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

    /**
     * Only a workable alert changes, one step of its workflow at a time:
     * assigned, under review, escalated, closed, or (from open only) cleared
     * by a rerun. Leaving the workflow (escalated, closed, cleared) frees its
     * window for a new alert. The UPDATE requires the status this copy read.
     */
    protected function amlaAllowsUpdate(): bool
    {
        $from = (string) $this->getOriginal('status');
        $to = (string) $this->status;

        if (! in_array($to, self::NEXT[$from] ?? [], true) || array_diff(array_keys($this->getDirty()), self::WORKFLOW_COLUMNS) !== []) {
            return false;
        }

        return match ($to) {
            self::CLEARED => $this->cleared_at !== null && $this->open_key === null,
            self::CLOSED => $this->closed_at !== null && $this->open_key === null,
            self::ESCALATED => $this->case_id !== null && $this->open_key === null,
            default => $this->open_key !== null && $this->open_key === $this->getOriginal('open_key'),
        };
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => $this->getOriginal('status')];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function amlaCase(): BelongsTo
    {
        return $this->belongsTo(AmlaCase::class, 'case_id');
    }

    /** @return HasMany<AmlaAlertAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AmlaAlertAction::class, 'alert_id');
    }
}
