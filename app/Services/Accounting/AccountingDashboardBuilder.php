<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The accounting dashboard's figures, as `AccountingDashboard` in
 * `src/types/accounting.ts`.
 *
 * ## Every figure here comes from the trial balance
 *
 * Not one of these is its own aggregate, and that is the whole design. The
 * dashboard's headline numbers sit beside a balance sheet built by regrouping
 * the trial balance client-side, so a second aggregate — however carefully
 * written — would eventually disagree with it over a reversal, a group account,
 * or a date boundary. Two figures that disagree and are both plausible are
 * worse than one figure that is wrong, because there is no way to tell which to
 * trust. {@see TrialBalanceBuilder} is therefore the only source, and
 * `is_balanced` below is literally the trial balance's own verdict.
 *
 * The one exception is `unposted_journals`, which counts draft HEADERS. Drafts
 * are excluded from the trial balance by definition, so there is nothing there
 * to count them from, and a count of entries is not a balance.
 *
 * Money is centavos throughout.
 */
final class AccountingDashboardBuilder
{
    public function __construct(private TrialBalanceBuilder $trialBalance) {}

    /** @return array<string, mixed> */
    public function build(string $asOf, ?int $branchId = null): array
    {
        $trialBalance = $this->trialBalance->build($asOf, $branchId);

        // Month-to-date is a DIFFERENCE between two positions, not a third
        // query. Income and expense accounts accumulate from the start of the
        // year, so "this month" is where they stand now less where they stood
        // at the close of last month — both read from the same builder, so the
        // subtraction cannot drift from the statement it appears beside.
        $priorMonthEnd = CarbonImmutable::parse($asOf)->startOfMonth()->subDay()->toDateString();
        $priorMonth = $this->trialBalance->build($priorMonthEnd, $branchId);

        $chart = AccountingAccount::query()->get()->keyBy('id');
        $signed = $this->signedByAccount($trialBalance['rows'], $chart);

        $incomeMtd = $this->totalFor($trialBalance['rows'], 'income') - $this->totalFor($priorMonth['rows'], 'income');
        $expensesMtd = $this->totalFor($trialBalance['rows'], 'expense') - $this->totalFor($priorMonth['rows'], 'expense');

        return [
            'as_of' => $asOf,
            'cash_on_hand' => $this->sumByCashKind($signed, $chart, ['cash']),
            'cash_in_bank' => $this->sumByCashKind($signed, $chart, ['bank']),
            'e_wallets' => $this->sumByCashKind($signed, $chart, ['gcash', 'maya', 'wallet']),
            'loans_receivable_net' => $this->netReceivable($signed, $chart),
            'total_assets' => $this->totalFor($trialBalance['rows'], 'asset'),
            'total_liabilities' => $this->totalFor($trialBalance['rows'], 'liability'),
            'total_equity' => $this->totalFor($trialBalance['rows'], 'equity'),
            'income_mtd' => $incomeMtd,
            'expenses_mtd' => $expensesMtd,
            'net_income_mtd' => $incomeMtd - $expensesMtd,
            'unposted_journals' => $this->draftCount($branchId),
            'is_balanced' => $trialBalance['is_balanced'],
            // NULL, honestly. Accounting periods are a table this application
            // does not have yet — there is no `accounting_periods`, nothing
            // closes the books, and every date is therefore open. Answering
            // with the current month would be an invention, and the field the
            // invention feeds is the one that tells an accountant whether their
            // entry can still be edited.
            'open_period' => null,
        ];
    }

    /**
     * A statement section's total, in the direction that section reads.
     *
     * Trial-balance rows are already placed in the column they landed on, so
     * one of `debit`/`credit` is always zero and the subtraction below is just
     * "signed, in the type's normal direction". Contra accounts need no special
     * case and must not get one: an allowance is an asset sitting in the credit
     * column, so it SUBTRACTS from total assets on its own, which is exactly
     * what "Net Loans Receivable is gross minus allowance" means at the
     * statement level.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function totalFor(array $rows, string $type): int
    {
        $debitNormal = $type === 'asset' || $type === 'expense';
        $total = 0;

        foreach ($rows as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $total += $debitNormal
                ? (int) $row['debit'] - (int) $row['credit']
                : (int) $row['credit'] - (int) $row['debit'];
        }

        return $total;
    }

    /**
     * Trial-balance rows re-signed into each account's own normal direction.
     *
     * Derived from the rows rather than by calling
     * {@see TrialBalanceBuilder::signedBalances()}, which would run the same
     * aggregate a second time for figures already in hand.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, AccountingAccount>  $chart
     * @return array<int, int>
     */
    private function signedByAccount(array $rows, Collection $chart): array
    {
        $signed = [];

        foreach ($rows as $row) {
            $account = $chart->get($row['account_id']);

            if ($account === null) {
                continue;
            }

            $signed[(int) $row['account_id']] = $account->normal_balance === 'debit'
                ? (int) $row['debit'] - (int) $row['credit']
                : (int) $row['credit'] - (int) $row['debit'];
        }

        return $signed;
    }

    /**
     * Money physically held, by where it sits.
     *
     * `cash_kind` is the only thing that puts an account on this figure — not
     * its code and not its name — so an organisation that renames "GCash" or
     * adds a second bank account gets the right total without anyone editing
     * this file.
     *
     * @param  array<int, int>  $signed
     * @param  Collection<int, AccountingAccount>  $chart
     * @param  list<string>  $kinds
     */
    private function sumByCashKind(array $signed, Collection $chart, array $kinds): int
    {
        $total = 0;

        foreach ($signed as $accountId => $balance) {
            $account = $chart->get($accountId);

            if ($account !== null && in_array($account->cash_kind, $kinds, true)) {
                $total += $balance;
            }
        }

        return $total;
    }

    /**
     * Gross loans receivable less the allowance for credit losses.
     *
     * The gross figure is the WHOLE receivable group, not just the account the
     * `loans_receivable` role points at. Moving a loan from current to past due
     * is a journal between two sibling accounts (1110 -> 1120 in the default
     * chart), so counting only the mapped one would make the portfolio appear
     * to shrink every time a borrower fell behind — a number that drops for the
     * wrong reason, on the card an operator glances at first.
     *
     * So: if the mapped account sits under a group heading, every postable
     * account under that heading counts. If it does not — an organisation that
     * mapped the role to a standalone account — only that account counts, which
     * is the most that can be claimed without guessing.
     *
     * The allowance is credit-normal (contra-asset), so its signed balance is
     * POSITIVE and is subtracted. Reading it as a negative asset and adding it
     * would report gross plus allowance, which overstates the portfolio by
     * twice the provision.
     *
     * @param  array<int, int>  $signed
     * @param  Collection<int, AccountingAccount>  $chart
     */
    private function netReceivable(array $signed, Collection $chart): int
    {
        $mapping = AccountingAccountMapping::resolved();

        $gross = 0;

        foreach ($this->receivableAccountIds($chart, $mapping) as $accountId) {
            $gross += $signed[$accountId] ?? 0;
        }

        $allowanceId = $mapping['allowance_credit_losses'] ?? null;
        $allowance = $allowanceId === null ? 0 : ($signed[$allowanceId] ?? 0);

        return $gross - $allowance;
    }

    /**
     * @param  Collection<int, AccountingAccount>  $chart
     * @param  array<string, int>  $mapping
     * @return list<int>
     */
    private function receivableAccountIds(Collection $chart, array $mapping): array
    {
        $mappedId = $mapping['loans_receivable'] ?? null;

        if ($mappedId === null) {
            return [];
        }

        $mapped = $chart->get($mappedId);

        if ($mapped === null) {
            return [];
        }

        $parent = $mapped->parent_id === null ? null : $chart->get($mapped->parent_id);

        if ($parent === null || ! $parent->is_group) {
            return [(int) $mapped->id];
        }

        return $chart
            ->where('parent_id', $parent->id)
            ->where('is_group', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Drafts waiting for someone to post them.
     *
     * Deliberately NOT filtered by `as_of`, unlike every other figure here.
     * This is a work queue, not a position: a draft dated next month is still
     * sitting on someone's desk, and it is precisely the one an as-of filter
     * would hide.
     */
    private function draftCount(?int $branchId): int
    {
        return AccountingJournal::query()
            ->where('status', 'draft')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
    }
}
