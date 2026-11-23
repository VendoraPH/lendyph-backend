<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Runs an application's capacity assessment and keeps each run, and each
 * change of the officer's notes, as an immutable revision (origination
 * decisions 14 and 15; #615's assessment actions).
 *
 * - An application is assessed from its submission until its hand-off: once
 *   it is For Approval it follows its loan, and a closed one is done.
 * - A run takes the verified monthly income and debt payments sent; one left
 *   out keeps the previous revision's, and null clears it. It assesses a loan
 *   starting on the start date sent, today by default, which the hand-off
 *   checks against the loan's. The officer's notes carry forward to the new
 *   run.
 * - Both lock the application row, so two runs at once number their
 *   revisions one after the other.
 * - Each writes an audit row on the application with ids and the result
 *   only, never a figure.
 */
final class AssessmentService
{
    /** The statuses an application can be assessed in. */
    public const ASSESSABLE_STATUSES = ['submitted', 'document_verification', 'additional_info_required', 'under_assessment'];

    public function __construct(private readonly CapacityAssessor $assessor) {}

    /**
     * @param  array{verified_monthly_income?: ?string, verified_debt_payments?: ?string, start_date?: ?string}  $input
     */
    public function run(OriginationApplication $application, User $user, array $input): OriginationAssessment
    {
        $this->assertAssessable($application);

        return DB::transaction(function () use ($application, $user, $input): OriginationAssessment {
            $locked = $this->lock($application);
            $previous = OriginationAssessment::currentFor($locked);

            $outcome = $this->assessor->assess(
                $locked,
                $this->carried($input, 'verified_monthly_income', $previous),
                $this->carried($input, 'verified_debt_payments', $previous),
                $input['start_date'] ?? null,
            );

            $assessment = OriginationAssessment::query()->create([
                'application_id' => $locked->id,
                'version' => ($previous?->version ?? 0) + 1,
                'kind' => OriginationAssessment::KIND_RUN,
                'rule_revision_id' => $outcome->ruleRevision->id,
                'borrower_id' => $outcome->borrowerId,
                'loan_product_id' => $outcome->request['loan_product_id'],
                'requested_amount' => $outcome->request['requested_amount'],
                'requested_term' => $outcome->request['requested_term'],
                'frequency' => $outcome->request['frequency'],
                'start_date' => $outcome->request['start_date'],
                'result' => $outcome->result,
                'provisional' => $outcome->provisional,
                'figures' => $outcome->figures,
                'inputs' => $outcome->inputs,
                'thresholds' => $outcome->thresholds,
                'checks' => $outcome->checks,
                'explanation' => $outcome->explanation,
                'missing_information' => $outcome->missingInformation,
                'conflicts' => $outcome->conflicts,
                'instalment_basis' => $outcome->instalmentBasis,
                'officer_findings' => $previous?->officer_findings,
                'recommendations' => $previous?->recommendations,
                'created_by' => $user->id,
            ]);

            $this->audit($locked, $assessment, 'assessed', "Assessment v{$assessment->version} run", $user);

            return $assessment;
        });
    }

    /**
     * A new revision with the current one's figures and the notes sent; a
     * note left out keeps its text, and null clears it.
     *
     * @param  array{officer_findings?: ?string, recommendations?: ?string}  $input
     */
    public function saveNotes(OriginationApplication $application, User $user, array $input): OriginationAssessment
    {
        $this->assertAssessable($application);

        return DB::transaction(function () use ($application, $user, $input): OriginationAssessment {
            $locked = $this->lock($application);
            $current = OriginationAssessment::currentFor($locked);

            if ($current === null) {
                throw ValidationException::withMessages(['assessment' => 'Run the assessment first.']);
            }

            $copy = collect($current->getAttributes())->except(['id', 'version', 'kind', 'created_by', 'created_at'])->all();
            $assessment = new OriginationAssessment;
            $assessment->setRawAttributes($copy);
            $assessment->forceFill([
                'version' => $current->version + 1,
                'kind' => OriginationAssessment::KIND_NOTES,
                'officer_findings' => array_key_exists('officer_findings', $input) ? $input['officer_findings'] : $current->officer_findings,
                'recommendations' => array_key_exists('recommendations', $input) ? $input['recommendations'] : $current->recommendations,
                'created_by' => $user->id,
            ])->save();

            $this->audit($locked, $assessment, 'assessment_notes_saved', "Assessment v{$assessment->version}: officer's notes saved", $user);

            return $assessment->refresh();
        });
    }

    /** @throws ValidationException 422 in words */
    public function assertAssessable(OriginationApplication $application): void
    {
        if ($application->loan_id !== null || ! in_array($application->status, self::ASSESSABLE_STATUSES, true)) {
            $label = ApplicationTransitions::label($application->status);
            $article = in_array(strtolower($label[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';

            throw ValidationException::withMessages([
                'status' => "{$article} {$label} application can't be assessed. Applications are assessed after they're submitted and before they're submitted for approval.",
            ]);
        }

        if ($application->loan_product_id === null || $application->requested_amount === null || $application->requested_term === null) {
            throw ValidationException::withMessages([
                'application' => 'The application has no loan product, amount or term to assess.',
            ]);
        }
    }

    private function lock(OriginationApplication $application): OriginationApplication
    {
        /** @var OriginationApplication $locked */
        $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
        $this->assertAssessable($locked);

        return $locked;
    }

    /** @param  array<string, mixed>  $input */
    private function carried(array $input, string $key, ?OriginationAssessment $previous): ?string
    {
        if (array_key_exists($key, $input)) {
            return $input[$key] === null ? null : (string) $input[$key];
        }

        return $previous?->inputs[$key] ?? null;
    }

    private function audit(OriginationApplication $application, OriginationAssessment $assessment, string $action, string $description, User $user): void
    {
        AuditLogService::log(
            $action,
            $application,
            newValues: ['assessment_id' => $assessment->id, 'version' => $assessment->version, 'result' => $assessment->result],
            description: "{$description} for online application #{$application->id}",
            userId: $user->id,
        );
    }
}
