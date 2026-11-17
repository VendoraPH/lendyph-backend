<?php

namespace App\Services\Linda;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Linda\Answers\Answer;
use App\Services\Linda\Answers\BranchPerformanceAnswer;
use App\Services\Linda\Answers\CollectionEfficiencyAnswer;
use App\Services\Linda\Answers\CollectionSummaryAnswer;
use App\Services\Linda\Answers\DisbursementSummaryAnswer;
use App\Services\Linda\Answers\ExpenseSummaryAnswer;
use App\Services\Linda\Answers\GeneralLendingSummaryAnswer;
use App\Services\Linda\Answers\IncomeSummaryAnswer;
use App\Services\Linda\Answers\LoanLookupAnswer;
use App\Services\Linda\Answers\LoanSummaryAnswer;
use App\Services\Linda\Answers\LookupAnswer;
use App\Services\Linda\Answers\MemberLookupAnswer;
use App\Services\Linda\Answers\MemberSummaryAnswer;
use App\Services\Linda\Answers\NotYetAnswer;
use App\Services\Linda\Answers\OfficerPerformanceAnswer;
use App\Services\Linda\Answers\OverdueAccountsAnswer;
use App\Services\Linda\Answers\PaymentsAnswer;
use App\Services\Linda\Answers\PortfolioSummaryAnswer;
use App\Services\Linda\Answers\ReceiptLookupAnswer;
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
 *
 * Lookups (a member, a loan or a receipt) are answered by lookup(), on the
 * server, from the record the request names; an understood lookup intent
 * with no request asks which record is meant.
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
        'collection_details' => PaymentsAnswer::class,
        'collection_efficiency' => CollectionEfficiencyAnswer::class,
        'branch_performance' => BranchPerformanceAnswer::class,
        'officer_performance' => OfficerPerformanceAnswer::class,
        'borrower_summary' => MemberSummaryAnswer::class,
        'portfolio_summary' => PortfolioSummaryAnswer::class,
        'income_summary' => IncomeSummaryAnswer::class,
        'expense_summary' => ExpenseSummaryAnswer::class,
    ];

    /** The record numbers a lookup reads: receipts, loans (LN-, LA-) and members. */
    private const LOOKUP_PREFIXES = [Repayment::RECEIPT_PREFIX, 'LN', Loan::CODE_PREFIX, Borrower::CODE_PREFIX];

    /**
     * @param  ?string  $question  the question as typed, read only to rank or name rows (performance); never stored
     */
    public function answer(User $user, LindaIntent $intent, ?LindaPeriod $period, ?string $branchName, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        if ($intent === LindaIntent::UnsupportedQuestion) {
            return $this->unsupported();
        }

        $lookupKind = $intent->lookupKind();

        if ($lookupKind !== null) {
            return $this->lookup($user, new LindaLookupRequest($lookupKind, $intent));
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

        return $answer->build($user, $scope, $period, $question);
    }

    /**
     * A lookup, answered from the record it names, in the user's own
     * branches, never by the model. By its number when it has one: a member
     * (BRW-), a loan (LN-, LA-) or a receipt (RCP-); else by the member's
     * name. A member named in a loan lookup is answered with the member and
     * their loans; one named in a receipt lookup, with their latest payments.
     * A number Linda doesn't look up gets a fixed reply, and a lookup that
     * names nothing asks for the record.
     */
    public function lookup(User $user, LindaLookupRequest $request): LindaReply
    {
        $code = $request->code === null ? '' : mb_strtoupper(trim($request->code));
        $name = $request->name === null ? '' : trim($request->name);
        $prefix = $code === '' ? null : (preg_match('/^\pL+/u', $code, $letters) === 1 ? $letters[0] : $code);

        if ($prefix !== null && ! in_array($prefix, self::LOOKUP_PREFIXES, true)) {
            return new LindaReply($request->intent->value, LindaWording::cantLookUpNumber($prefix), 'not_yet', answerFunction: 'LookupAnswer');
        }

        /** @var LookupAnswer $answer */
        $answer = app(match (true) {
            $prefix === Repayment::RECEIPT_PREFIX => ReceiptLookupAnswer::class,
            in_array($prefix, ['LN', Loan::CODE_PREFIX], true) => LoanLookupAnswer::class,
            $request->kind === LindaLookupRequest::RECEIPT => ReceiptLookupAnswer::class,
            $request->kind === LindaLookupRequest::LOAN && $code === '' && $name === '' => LoanLookupAnswer::class,
            default => MemberLookupAnswer::class,
        });

        if (! $answer->allows($user)) {
            return $answer->denied();
        }

        if (app(BranchAccess::class)->for($user) === []) {
            return new LindaReply($answer->intent()->value, LindaWording::noBranchAssigned(), 'denied', answerFunction: $answer->name());
        }

        if ($code === '' && $name === '') {
            return new LindaReply($answer->intent()->value, LindaWording::whichRecord($request->kind), 'no_target', answerFunction: $answer->name());
        }

        return $answer->find($user, new LindaLookupRequest($request->kind, $request->intent, $code === '' ? null : $code, $name === '' ? null : $name));
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

    /** @param  string  $reason  LindaPeriod::REFUSED_FUTURE, REFUSED_TOO_LONG or REFUSED_INVALID */
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
