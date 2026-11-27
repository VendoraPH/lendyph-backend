<?php

namespace App\Services\Linda\Answers;

use App\Models\AccountingExpense;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;

/**
 * "What are our expenses this month?": the Expenses & Payables list's
 * `meta.totals` for the period (AccountingExpense::listTotals(), GET
 * /accounting/expenses with `from`, `to` and `branch_id`): how many expenses
 * were recorded, their amount, what was paid and what is still due. An
 * expense whose journal was reversed on the Journals screen counts in none of
 * them, as on the list. In the caller's branches (an expense with no branch
 * only for `branches:view_all`), this month by default; needs
 * `expenses:view`, as the list does.
 */
final class ExpenseSummaryAnswer extends Answer
{
    public function intent(): LindaIntent
    {
        return LindaIntent::ExpenseSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('expenses:view');
    }

    protected function subject(): string
    {
        return 'expense figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('this_month');

        $totals = AccountingExpense::listTotals(AccountingExpense::query()
            ->visibleTo($user)
            ->where('date', '>=', $period->from->toDateString())
            ->where('date', '<=', $period->to->toDateString())
            ->when($scope->branchId !== null, fn ($query) => $query->where('branch_id', $scope->branchId)));

        $answer = $totals['count'] === 0
            ? self::sentence(W::during($period)).', no expenses were recorded '.W::forScope($scope).'.'
            : self::sentence(W::during($period)).', '.W::count($totals['count'], 'expense').' '.($totals['count'] === 1 ? 'was' : 'were')
                .' recorded '.W::forScope($scope).', totalling '.W::centavos($totals['amount']).': '.W::centavos($totals['amount_paid'])
                .' paid and '.W::centavos($totals['amount_due']).' still due.';

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::number('Expenses', $totals['count']),
                LindaReply::money('Total Amount', $totals['amount'] / 100),
                LindaReply::money('Paid', $totals['amount_paid'] / 100),
                LindaReply::money('Still Due', $totals['amount_due'] / 100),
            ], self::sentence(W::during($period))),
        ], LindaLinks::for($user, 'expenses'));
    }
}
