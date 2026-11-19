<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AMLA compliance case (task §12–13; AMLA-B6): one member or walk-in, the
 * alerts escalated to it, notes and evidence, and an authorized decision.
 * A decision that suspicion is established records its determination date,
 * from which an STR is due. Head office only (`amla:cases` and
 * `branches:view_all`); never shown outside AMLA.
 *
 * Kept forever. It changes only by one workflow step at a time (assigned,
 * decided, closed once its STR is filed), and the UPDATE requires the status
 * this copy read. The title and the decision reason are encrypted.
 *
 * @property int $id
 * @property string $reference
 * @property string $status
 * @property string $title
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property int|null $branch_id
 * @property int $opened_by
 * @property int|null $assigned_to
 * @property string|null $decision
 * @property string|null $decision_reason
 * @property CarbonInterface|null $determination_date
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $closed_at
 */
class AmlaCase extends Model
{
    use AmlaKeptForever;

    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const STR_REQUIRED = 'str_required';

    public const CLOSED_NO_REPORT = 'closed_no_report';

    public const CLOSED_STR_FILED = 'closed_str_filed';

    /** @var array<string, string> */
    public const STATUS_WORDS = [
        self::OPEN => 'Open',
        self::INVESTIGATING => 'Investigating',
        self::STR_REQUIRED => 'Suspicion established: STR to file',
        self::CLOSED_NO_REPORT => 'Closed: no STR, with a documented reason',
        self::CLOSED_STR_FILED => 'Closed: STR filed',
    ];

    /** @var list<string> statuses that take notes, evidence, alerts and a decision */
    public const ACTIVE = [self::OPEN, self::INVESTIGATING];

    /** @var array<string, list<string>> */
    private const NEXT = [
        self::OPEN => [self::INVESTIGATING, self::STR_REQUIRED, self::CLOSED_NO_REPORT],
        self::INVESTIGATING => [self::INVESTIGATING, self::STR_REQUIRED, self::CLOSED_NO_REPORT],
        self::STR_REQUIRED => [self::CLOSED_STR_FILED],
    ];

    private const WORKFLOW_COLUMNS = ['status', 'assigned_to', 'decision', 'decision_reason', 'determination_date', 'decided_by', 'decided_at', 'closed_at', 'updated_at'];

    protected $fillable = [
        'reference', 'status', 'title', 'borrower_id', 'gcash_non_member_id', 'branch_id', 'opened_by', 'assigned_to',
        'decision', 'decision_reason', 'determination_date', 'decided_by', 'decided_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'branch_id' => 'integer',
            'opened_by' => 'integer',
            'assigned_to' => 'integer',
            'decision_reason' => 'encrypted',
            'determination_date' => 'date',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsToMany<AmlaAlert, $this> */
    public function alerts(): BelongsToMany
    {
        return $this->belongsToMany(AmlaAlert::class, 'amla_case_alerts', 'case_id', 'alert_id');
    }

    /** @return HasMany<AmlaCaseNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(AmlaCaseNote::class, 'case_id');
    }

    /** @return HasMany<AmlaCaseEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(AmlaCaseEvidence::class, 'case_id');
    }

    /** @return HasMany<AmlaCaseAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AmlaCaseAction::class, 'case_id');
    }

    /**
     * One workflow step at a time. A no-STR decision closes the case for
     * good, so its decider is never the case's opener or assignee.
     */
    protected function amlaAllowsUpdate(): bool
    {
        if (! in_array($this->status, self::NEXT[(string) $this->getOriginal('status')] ?? [], true)
            || array_diff(array_keys($this->getDirty()), self::WORKFLOW_COLUMNS) !== []) {
            return false;
        }

        return $this->status !== self::CLOSED_NO_REPORT
            || ($this->decided_by !== null && ! in_array($this->decided_by, [(int) $this->getOriginal('opened_by'), (int) $this->getOriginal('assigned_to')], true));
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => $this->getOriginal('status')];
    }
}
