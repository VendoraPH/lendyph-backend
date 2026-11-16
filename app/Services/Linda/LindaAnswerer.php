<?php

namespace App\Services\Linda;

use App\Models\User;
use App\Services\Linda\Answers\Answer;
use App\Services\Linda\Answers\CollectionSummaryAnswer;
use App\Services\Linda\Answers\DisbursementSummaryAnswer;
use App\Services\Linda\Answers\GeneralLendingSummaryAnswer;
use App\Services\Linda\Answers\LoanSummaryAnswer;
use App\Services\Linda\Answers\NotYetAnswer;
use App\Services\Linda\Answers\OverdueAccountsAnswer;
use App\Services\Linda\Answers\UnsupportedAnswer;

/**
 * Turns an understood question (intent, period, branch name) into Linda's
 * reply, from the reports' own figures, for the user's own branches and
 * permissions. Every sentence is a template in LindaWording, so no figure
 * in a reply is ever the model's.
 *
 * In order: an unsupported question gets the refusal and an intent Linda
 * doesn't answer yet a fixed reply; then the answer's permission (a refusal
 * in words, with no figures and no links); then the branch, matched only
 * among the user's own; then the figures. Collections, disbursements and the
 * summary default to this month; overdue and active loans are as of today.
 */
final class LindaAnswerer
{
    /** @var array<string, class-string<Answer>> */
    private const ANSWERS = [
        'collection_summary' => CollectionSummaryAnswer::class,
        'disbursement_summary' => DisbursementSummaryAnswer::class,
        'loan_summary' => LoanSummaryAnswer::class,
        'overdue_accounts' => OverdueAccountsAnswer::class,
        'general_lending_summary' => GeneralLendingSummaryAnswer::class,
    ];

    public function answer(User $user, LindaIntent $intent, ?LindaPeriod $period, ?string $branchName): LindaReply
    {
        if ($intent === LindaIntent::UnsupportedQuestion) {
            return $this->unsupported();
        }

        $class = self::ANSWERS[$intent->value] ?? null;

        if ($class === null) {
            return $this->notYet($intent, $user);
        }

        /** @var Answer $answer */
        $answer = app($class);

        if (! $answer->allows($user)) {
            return $answer->denied();
        }

        $scope = LindaScope::resolve($user, $branchName);

        if ($scope->problem === 'no_branch_assigned') {
            return new LindaReply($intent->value, LindaWording::noBranchAssigned(), 'denied', answerFunction: $answer->name());
        }

        if ($scope->problem === 'not_found') {
            return $this->branchNotFound((string) $branchName, $intent);
        }

        if ($scope->problem === 'ambiguous') {
            return $this->branchAmbiguous($scope->matches, $intent);
        }

        if ($period === null && $intent->defaultsToThisMonth()) {
            $period = LindaPeriod::named('this_month');
        }

        return $answer->build($user, $scope, $period);
    }

    /** What Linda can answer, with the five suggested questions. */
    public function unmatched(): LindaReply
    {
        return new LindaReply(LindaIntent::UnsupportedQuestion->value, LindaWording::unmatched(), 'unmatched');
    }

    public function unclearPeriod(?LindaIntent $intent = null): LindaReply
    {
        return new LindaReply(($intent ?? LindaIntent::UnsupportedQuestion)->value, LindaWording::unclearPeriod(), 'unclear_period');
    }

    /** @param  string  $reason  "future", or "over_10_years" */
    public function periodRefused(string $reason, ?LindaIntent $intent = null): LindaReply
    {
        return new LindaReply(($intent ?? LindaIntent::UnsupportedQuestion)->value, LindaWording::periodRefused($reason), 'period_refused');
    }

    public function unsupported(): LindaReply
    {
        return UnsupportedAnswer::reply();
    }

    /** A fixed reply, with the matching report's link when $user can open it. */
    public function notYet(LindaIntent $intent, ?User $user = null): LindaReply
    {
        return NotYetAnswer::for($intent, $user);
    }

    public function branchNotFound(string $name, ?LindaIntent $intent = null): LindaReply
    {
        return new LindaReply(($intent ?? LindaIntent::UnsupportedQuestion)->value, LindaWording::branchNotFound($name), 'no_branch');
    }

    /** @param  list<string>  $names */
    public function branchAmbiguous(array $names, ?LindaIntent $intent = null): LindaReply
    {
        return new LindaReply(($intent ?? LindaIntent::UnsupportedQuestion)->value, LindaWording::branchAmbiguous($names), 'ambiguous_branch');
    }

    /** For a question only the model could read while it can't be asked. */
    public function aiOffForNow(): LindaReply
    {
        return new LindaReply(LindaIntent::UnsupportedQuestion->value, LindaWording::aiOffForNow(), 'unmatched');
    }
}
