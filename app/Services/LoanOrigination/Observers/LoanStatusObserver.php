<?php

namespace App\Services\LoanOrigination\Observers;

use App\Models\Loan;
use App\Models\OriginationApplication;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a handed-off application in step with its loan (origination §3.1,
 * Phase 6 decision 9), as the portal's LoanObserver follows a release. It
 * runs inside the loan write's own transaction, after the loan's row lock,
 * and moves the application through ApplicationTransitions as the `loan`
 * actor, with a history row naming the loan:
 *
 * - for approval (submitted, or an approved loan sent back): For Approval;
 * - approved: Approved;
 * - rejected: Rejected;
 * - released: Converted to Loan;
 * - void (before release): Withdrawn;
 * - back to draft: Under Assessment, so it can be handed off again.
 *
 * A loan that didn't come from an application costs one indexed read. An
 * application already at the status is left as it is. One that can't follow
 * (staff closed it some other way) never blocks the loan: the mismatch is
 * logged with ids and statuses only.
 *
 * Registered on Loan with #[ObservedBy]; no service provider is edited.
 */
class LoanStatusObserver
{
    /** Loan status => the application status it means. */
    public const FOLLOWS = [
        'for_review' => 'pending_approval',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'released' => 'converted',
        'void' => 'withdrawn',
        'draft' => 'under_assessment',
    ];

    private const WORDS = [
        'for_review' => 'is for approval',
        'approved' => 'was approved',
        'rejected' => 'was rejected',
        'released' => 'was released',
        'void' => 'was voided',
        'draft' => 'is back to draft',
    ];

    public function __construct(private readonly ApplicationTransitions $transitions) {}

    public function updated(Loan $loan): void
    {
        if (! $loan->wasChanged('status') || ! isset(self::FOLLOWS[$loan->status])) {
            return;
        }

        $application = OriginationApplication::query()->where('loan_id', $loan->getKey())->first();

        if ($application === null) {
            return;
        }

        $target = self::FOLLOWS[$loan->status];

        if ($application->status === $target) {
            return;
        }

        if (! $this->transitions->allows($application->status, $target, ApplicationTransitions::ACTOR_LOAN)) {
            Log::warning('Loan origination: an application could not follow its loan\'s status.', [
                'application_id' => $application->id,
                'loan_id' => $loan->getKey(),
                'application_status' => $application->status,
                'loan_status' => $loan->status,
            ]);

            return;
        }

        $this->transitions->move(
            $application,
            $target,
            ApplicationTransitions::ACTOR_LOAN,
            note: sprintf('Loan %s %s.', $loan->application_number, self::WORDS[$loan->status]),
        );
    }
}
