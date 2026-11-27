<?php

namespace App\Models;

use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One revision of an online application's capacity assessment (origination
 * decisions 14 and 15): a run (`kind` run) or a change of the officer's notes
 * on the figures of the run before it (`kind` notes). Numbered per
 * application; the highest `version` is the current one. Inserted once, never
 * changed or deleted (OriginationAppendOnly); the retention erasure removes
 * them with their application.
 *
 * - The assessed request (product, amount, term, frequency, start date) and
 *   the member whose Lendy loans counted (`borrower_id`) are kept plain, so
 *   the hand-off can tell whether the application changed since.
 * - The figures, the verified amounts (`inputs`), the explanation, the lists
 *   and the notes are encrypted: they are an applicant's finances, as the
 *   draft is.
 * - It isn't Auditable: the audit rows name the application, with ids only.
 *
 * @property array<string, ?string> $figures
 * @property array{verified_monthly_income: ?string, verified_debt_payments: ?string} $inputs
 * @property array<string, string> $thresholds
 * @property array<string, ?bool> $checks
 * @property list<string> $missing_information
 * @property list<string> $conflicts
 */
class OriginationAssessment extends Model
{
    use OriginationAppendOnly;

    public const UPDATED_AT = null;

    public const KIND_RUN = 'run';

    public const KIND_NOTES = 'notes';

    public const RESULTS = ['within_capacity', 'further_review', 'above_capacity', 'insufficient_information'];

    public const RESULT_LABELS = [
        'within_capacity' => 'Within capacity',
        'further_review' => 'Further review needed',
        'above_capacity' => 'Above capacity',
        'insufficient_information' => 'Insufficient information',
    ];

    /** The results that send the hand-off's loan to the Policy Exception chain (decision 12). */
    public const POLICY_EXCEPTION_RESULTS = ['above_capacity', 'insufficient_information'];

    protected $fillable = [
        'application_id', 'version', 'kind', 'rule_revision_id', 'borrower_id', 'loan_product_id', 'requested_amount',
        'requested_term', 'frequency', 'start_date', 'result', 'provisional', 'figures', 'inputs', 'thresholds', 'checks',
        'explanation', 'missing_information', 'conflicts', 'instalment_basis', 'officer_findings', 'recommendations',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'version' => 'integer',
            'rule_revision_id' => 'integer',
            'borrower_id' => 'integer',
            'loan_product_id' => 'integer',
            'requested_amount' => 'decimal:2',
            'requested_term' => 'integer',
            'start_date' => 'date:Y-m-d',
            'provisional' => 'boolean',
            'figures' => 'encrypted:array',
            'inputs' => 'encrypted:array',
            'thresholds' => 'array',
            'checks' => 'array',
            'explanation' => 'encrypted',
            'missing_information' => 'encrypted:array',
            'conflicts' => 'encrypted:array',
            'officer_findings' => 'encrypted',
            'recommendations' => 'encrypted',
            'created_by' => 'integer',
        ];
    }

    /** The application's current revision, or null before its first run. */
    public static function currentFor(OriginationApplication $application): ?self
    {
        return static::query()->where('application_id', $application->getKey())->orderByDesc('version')->first();
    }

    public function goesToPolicyException(): bool
    {
        return in_array($this->result, self::POLICY_EXCEPTION_RESULTS, true);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function ruleRevision(): BelongsTo
    {
        return $this->belongsTo(OriginationRuleRevision::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
