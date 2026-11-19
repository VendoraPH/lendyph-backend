<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CTR or STR record (task §13–14; AMLA design §4.13, §4.16): prepared from
 * a CTR candidate or a case's STR decision, approved by someone other than
 * its preparer, and filed by hand on the AMLC portal. Its fields and
 * narrative are a snapshot taken at preparation, encrypted. It is due from
 * `deadline_from` (the transaction date, or the determination date) under
 * the AMLA settings version recorded.
 *
 * Kept forever. It changes only by one filing step at a time, and the UPDATE
 * requires the status this copy read. "Submitted", "Accepted" and
 * "Rejected" each come from a recorded submission with a reference and
 * evidence; generating or downloading anything never submits.
 *
 * @property int $id
 * @property string $reference
 * @property string $type ctr|str
 * @property string $status
 * @property int $format_id
 * @property int|null $ctr_candidate_id
 * @property int|null $case_id
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property int|null $branch_id
 * @property list<array{label: string, value: string}> $fields
 * @property string|null $narrative
 * @property CarbonInterface $deadline_from
 * @property int $deadline_settings_version
 * @property int $prepared_by
 * @property int|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $returned_reason
 * @property string|null $submission_reference
 * @property CarbonInterface|null $submitted_on
 */
class AmlaReport extends Model
{
    use AmlaKeptForever;

    public const CTR = 'ctr';

    public const STR = 'str';

    public const PENDING_APPROVAL = 'pending_approval';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const CORRECTION_REQUIRED = 'correction_required';

    /** @var array<string, string> */
    public const STATUS_WORDS = [
        self::PENDING_APPROVAL => 'Waiting for approval',
        self::RETURNED => 'Returned to the preparer',
        self::APPROVED => 'Approved, ready for filing',
        self::SUBMITTED => 'Submitted on the AMLC portal',
        self::ACCEPTED => 'Accepted',
        self::REJECTED => 'Rejected by the reporting facility',
        self::CORRECTION_REQUIRED => 'Correction required',
    ];

    /** @var array<string, string> */
    public const TYPE_WORDS = [self::CTR => 'Covered transaction report (CTR)', self::STR => 'Suspicious transaction report (STR)'];

    /** @var list<string> statuses still waiting to be filed */
    public const UNFILED = [self::PENDING_APPROVAL, self::APPROVED, self::REJECTED, self::CORRECTION_REQUIRED];

    /** @var array<string, list<string>> */
    public const NEXT = [
        self::PENDING_APPROVAL => [self::APPROVED, self::RETURNED],
        self::APPROVED => [self::SUBMITTED],
        self::SUBMITTED => [self::ACCEPTED, self::REJECTED, self::CORRECTION_REQUIRED],
        self::REJECTED => [self::SUBMITTED],
        self::CORRECTION_REQUIRED => [self::SUBMITTED],
    ];

    private const WORKFLOW_COLUMNS = ['status', 'approved_by', 'approved_at', 'returned_reason', 'submission_reference', 'submitted_on', 'updated_at'];

    protected $fillable = [
        'reference', 'type', 'status', 'format_id', 'ctr_candidate_id', 'case_id', 'borrower_id', 'gcash_non_member_id', 'branch_id',
        'fields', 'narrative', 'deadline_from', 'deadline_settings_version', 'prepared_by', 'approved_by', 'approved_at',
        'returned_reason', 'submission_reference', 'submitted_on',
    ];

    protected function casts(): array
    {
        return [
            'format_id' => 'integer',
            'ctr_candidate_id' => 'integer',
            'case_id' => 'integer',
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'branch_id' => 'integer',
            'fields' => 'encrypted:array',
            'narrative' => 'encrypted',
            'deadline_from' => 'date',
            'deadline_settings_version' => 'integer',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'returned_reason' => 'encrypted',
            'submitted_on' => 'date',
        ];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(AmlaReportFormat::class, 'format_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(AmlaCtrCandidate::class, 'ctr_candidate_id');
    }

    public function amlaCase(): BelongsTo
    {
        return $this->belongsTo(AmlaCase::class, 'case_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<AmlaReportSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(AmlaReportSubmission::class, 'report_id');
    }

    protected function amlaAllowsUpdate(): bool
    {
        return in_array($this->status, self::NEXT[(string) $this->getOriginal('status')] ?? [], true)
            && array_diff(array_keys($this->getDirty()), self::WORKFLOW_COLUMNS) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => $this->getOriginal('status')];
    }
}
