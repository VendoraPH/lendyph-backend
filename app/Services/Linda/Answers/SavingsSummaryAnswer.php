<?php

namespace App\Services\Linda\Answers;

use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\Savings\Dashboard\SavingsDashboard;

/**
 * "How much savings do we have?": the Savings dashboard's figures as of
 * today (SavingsDashboard::build(), GET /savings/dashboard with no dates):
 * total deposits, the savings balance, time deposits outstanding, accounts by
 * status and time deposits maturing in the next 30 days, in the caller's
 * branches. It needs `savings:view`, as the dashboard does.
 *
 * The backend has no savings switch of its own (the menu's is a frontend
 * build flag), so a deployment with no savings product and no account is
 * told plainly that savings isn't set up.
 */
final class SavingsSummaryAnswer extends Answer
{
    public function __construct(private readonly SavingsDashboard $dashboard) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::SavingsSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('savings:view');
    }

    protected function subject(): string
    {
        return 'savings figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        if (! SavingsProduct::query()->exists() && ! SavingsAccount::query()->exists()) {
            return new LindaReply($this->intent()->value, W::savingsNotSetUp(), 'not_yet', answerFunction: $this->name());
        }

        $today = LindaPeriod::today();
        $kpis = $this->dashboard->build(
            ['branch_ids' => $scope->branchIds, 'branch_id' => $scope->branchId, 'product_id' => null],
            $today->startOfMonth()->toDateString(),
            $today->toDateString(),
        )['kpis'];

        $accounts = $kpis['accounts'];
        $maturing = $kpis['maturing_30_days'];

        $answer = W::asOfToday().', total deposits '.W::forScope($scope).' are '.W::money($kpis['total_deposits']).': '
            .W::money($kpis['savings_balance']).' in savings accounts and '.W::money($kpis['time_deposits']['amount']).' in '
            .W::count((int) $kpis['time_deposits']['count'], 'time deposit').'. There '.((int) $accounts['active'] === 1 ? 'is ' : 'are ')
            .W::listOf([$accounts['active'].' active', $accounts['dormant'].' dormant', $accounts['frozen'].' frozen'])
            .' savings '.((int) $accounts['active'] === 1 ? 'account' : 'accounts').'. '
            .((int) $maturing['count'] === 0
                ? 'No time deposit matures in the next 30 days.'
                : W::count((int) $maturing['count'], 'time deposit').' ('.W::money($maturing['principal']).') '
                    .((int) $maturing['count'] === 1 ? 'matures' : 'mature').' in the next 30 days, paying '.W::money($maturing['maturity_amount']).'.');

        if ($period !== null) {
            $answer .= ' '.W::todayOnly();
        }

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::money('Total Deposits', $kpis['total_deposits']),
                LindaReply::money('Savings Balance', $kpis['savings_balance']),
                LindaReply::number('Time Deposits', (int) $kpis['time_deposits']['count']),
                LindaReply::money('Time Deposits (₱)', $kpis['time_deposits']['amount']),
                LindaReply::number('Active Accounts', (int) $accounts['active']),
                LindaReply::number('Dormant Accounts', (int) $accounts['dormant']),
                LindaReply::number('Frozen Accounts', (int) $accounts['frozen']),
                LindaReply::number('Maturing in 30 Days', (int) $maturing['count']),
                LindaReply::money('Maturity Amount (30 Days)', $maturing['maturity_amount']),
            ], W::asOfToday()),
        ], LindaLinks::for($user, 'savings_dashboard'));
    }
}
