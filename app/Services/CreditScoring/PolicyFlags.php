<?php

namespace App\Services\CreditScoring;

use App\Models\CreditPolicyFlag;
use App\Models\CreditScoringSetting;
use App\Services\BorrowerDuplicateDetector;
use Carbon\Carbon;

/**
 * Credit policy warnings, kept apart from the score: a flag never moves the
 * number, it tells the loan officer what to review.
 *
 * Hard flags: a loan more than the settings' hard limit (30) days past due, a
 * defaulted loan, a write-off, failed income verification. Soft flags: a
 * loan 1 to 30 days past due, active loans (outside lenders included) above
 * the settings' limit, a possible duplicate member record, a debt service
 * ratio above the caution level, and an application whose credit assessment
 * notice isn't recorded.
 *
 * `scope` says where a flag lives: `member` flags describe the member and
 * are kept by the member's own score; `loan` flags come from one
 * application's details and are kept by that loan's assessment. A rule the
 * revision switches off raises nothing.
 */
final class PolicyFlags
{
    public function __construct(private readonly BorrowerDuplicateDetector $duplicates) {}

    /**
     * @return list<array{rule_key: string, type: string, label: string, detail: string, scope: string}>
     */
    public function evaluate(
        MemberCreditData $data,
        PaymentHistory $history,
        Exposure $exposure,
        Capacity $capacity,
        CreditScoringSetting $settings,
        FactorTable $table,
    ): array {
        $flags = [];
        $raise = function (string $key, string $detail, string $scope) use (&$flags, $table) {
            $rule = $table->policyRule($key);

            if ($rule !== null && $table->policyRuleIsActive($key)) {
                $flags[] = ['rule_key' => $key, 'type' => $rule['type'], 'label' => $rule['label'], 'detail' => $detail, 'scope' => $scope];
            }
        };

        $dpd = $history->daysPastDueNow;
        $hardLimit = $settings->hard_past_due_days;

        if ($dpd > $hardLimit) {
            $raise('past_due_over_limit', "A loan is {$dpd} days past due, more than {$hardLimit} days. Manual review required.", 'member');
        } elseif ($dpd > 0) {
            $raise('past_due', "A loan is {$dpd} days past due.", 'member');
        }

        if ($history->defaultedLoans > 0) {
            $raise('existing_default', $history->defaultedLoans === 1 ? 'The member has 1 defaulted loan.' : "The member has {$history->defaultedLoans} defaulted loans.", 'member');
        }

        if ($history->writtenOffLoans > 0) {
            $raise('previous_write_off', $history->writtenOffLoans === 1 ? 'Part of 1 previous loan was written off.' : "Parts of {$history->writtenOffLoans} previous loans were written off.", 'member');
        }

        $outsideLenders = (int) ($data->profile?->outside_lenders_count ?? 0);
        $activeLoans = $exposure->activeLoans + $outsideLenders;

        if ($activeLoans > $settings->max_active_loans) {
            $raise('too_many_active_loans', "The member has {$activeLoans} active loans, outside lenders included; the policy limit is {$settings->max_active_loans}.", 'member');
        }

        $duplicate = $this->duplicates->findDuplicate(
            (string) $data->member->first_name,
            $data->member->middle_name,
            (string) $data->member->last_name,
            null,
            (int) $data->member->id,
        );

        if ($duplicate !== null) {
            $raise('possible_duplicate', "Member {$duplicate->borrower_code} has the same name.", 'member');
        }

        $scope = $data->applicationLoan === null ? 'member' : 'loan';

        if ($capacity->assessment === Capacity::ABOVE_CAPACITY) {
            $raise('debt_service_above_caution', "The debt service ratio is {$capacity->debtServiceRatioText()}%, above the caution level of ".self::percent($settings->dsr_caution_max).'%.', $scope);
        }

        if ($data->applicationLoan !== null) {
            if ($data->profile?->income_verification === 'failed') {
                $raise('income_verification_failed', 'The member\'s declared income could not be verified.', 'loan');
            }

            if (! $data->noticeAcknowledged) {
                $raise('notice_not_recorded', 'The member\'s acknowledgement of the credit assessment notice is not recorded for this loan.', 'loan');
            }
        }

        return $flags;
    }

    /**
     * Keep the stored flags of one scope in step with what was raised: a new
     * condition opens a flag, one still holding is seen again, and one no
     * longer raised is cleared. A cleared flag is never reopened; the
     * condition coming back opens a new one.
     *
     * @param  list<array{rule_key: string, type: string, label: string, detail: string, scope: string}>  $raised
     */
    public function sync(int $borrowerId, ?int $loanId, array $raised, Carbon $now): void
    {
        $scope = $loanId === null ? 'member' : 'loan';
        $raised = collect($raised)->where('scope', $scope)->keyBy('rule_key');

        $open = CreditPolicyFlag::query()
            ->where('borrower_id', $borrowerId)
            ->when($loanId === null, fn ($q) => $q->whereNull('loan_id'), fn ($q) => $q->where('loan_id', $loanId))
            ->open()
            ->get()
            ->keyBy('rule_key');

        foreach ($open as $key => $flag) {
            if ($raised->has($key)) {
                $flag->update(['detail' => $raised[$key]['detail'], 'label' => $raised[$key]['label'], 'last_seen_at' => $now]);
            } else {
                $flag->update(['cleared_at' => $now]);
            }
        }

        foreach ($raised as $key => $flag) {
            if (! $open->has($key)) {
                CreditPolicyFlag::create([
                    'borrower_id' => $borrowerId,
                    'loan_id' => $loanId,
                    'rule_key' => $key,
                    'type' => $flag['type'],
                    'label' => $flag['label'],
                    'detail' => $flag['detail'],
                    'first_triggered_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }
        }
    }

    private static function percent(string|float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
