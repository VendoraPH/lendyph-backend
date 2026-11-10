<?php

namespace App\Services\Savings\Interest;

use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\LoanWriteTransaction;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\SavingsNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An interest run's decisions (design §5): approve and reject by a checker
 * who is not its maker (403), and post.
 *
 * Posting re-reads the account ids plainly, then, in one transaction, locks
 * the product row, the accounts (lock step 3) and the run (step 5) and
 * re-checks the status.
 * It works the figures out again under those locks and answers 409 when any
 * line differs from the draft the checker approved. Then it writes each
 * line's interest row and tax row (value date = the period's end, dated
 * today), credits the accounts through the period's end, sets the run's
 * totals from the rows it wrote and posts one `savings_interest` journal
 * (none when nothing was earned).
 *
 * Large branches (review M3): the whole run posts in one transaction, under
 * every account's lock, through SavingsLedger row by row. 500 accounts post
 * in about 1.2 s on a local MySQL (InterestRunPostTimeTest, 2026-11-10), and
 * tellers on those accounts wait that long. A bulk ledger path, or chunked
 * posting, is for v1.1 if a branch outgrows it.
 *
 * Locks: create, post and reverse each take the product row, then the
 * accounts, then the run. While one of them works out figures or writes the
 * ledger, it holds the product row, so opening a new account on that product
 * or adding a rate to it waits; teller deposits and withdrawals take only
 * the account rows and don't wait on the product.
 */
class RunPoster
{
    public function __construct(
        private readonly RunBuilder $builder,
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly SavingsNumbers $numbers,
        private readonly AutomaticPoster $poster,
    ) {}

    public function approve(SavingsInterestRun $run, User $checker): SavingsInterestRun
    {
        $this->assertNotMaker($run, $checker, 'approve');

        return LoanWriteTransaction::run(function () use ($run, $checker): SavingsInterestRun {
            $locked = $this->lockRun($run);

            if ($locked->status !== 'draft') {
                throw LoanWriteTransaction::conflict("Only a draft run can be approved; this one is {$this->statusWords($locked->status)}.");
            }

            $locked->update(['status' => 'approved', 'checked_by' => $checker->id, 'checked_at' => now()]);

            return $locked;
        }, null, $this->conflictMessage());
    }

    public function reject(SavingsInterestRun $run, User $checker, string $reason): SavingsInterestRun
    {
        $this->assertNotMaker($run, $checker, 'reject');

        return LoanWriteTransaction::run(function () use ($run, $checker, $reason): SavingsInterestRun {
            $locked = $this->lockRun($run);

            if (! in_array($locked->status, ['draft', 'approved'], true)) {
                throw LoanWriteTransaction::conflict("Only a draft or approved run can be rejected; this one is {$this->statusWords($locked->status)}.");
            }

            $locked->update([
                'status' => 'rejected',
                'checked_by' => $checker->id,
                'checked_at' => now(),
                'rejection_reason' => $reason,
                'live_key' => null,
            ]);

            return $locked;
        }, null, $this->conflictMessage());
    }

    public function post(SavingsInterestRun $run, User $user): SavingsInterestRun
    {
        $start = $run->period_start->toDateString();
        $end = $run->period_end->toDateString();
        $accountIds = $run->lines()->pluck('account_id')
            ->merge($this->builder->accounts($run->product_id, (int) $run->branch_id, $start, $end)->pluck('id'))
            ->unique()
            ->values()
            ->all();

        return LoanWriteTransaction::run(function () use ($run, $user, $start, $end, $accountIds): SavingsInterestRun {
            // Product, then accounts, then run: the order create and reverse take too.
            SavingsProduct::query()->whereKey($run->product_id)->lockForUpdate()->firstOrFail();
            $this->locks->accounts($accountIds);
            $locked = $this->lockRun($run);

            if ($locked->status === 'posted') {
                throw LoanWriteTransaction::conflict('This interest run has already been posted.');
            }

            if ($locked->status !== 'approved') {
                throw LoanWriteTransaction::conflict("Only an approved run can be posted; this one is {$this->statusWords($locked->status)}.");
            }

            $this->assertNextInLine($locked, $start);

            $lines = $locked->lines()->orderBy('id')->get();
            $this->assertUnchanged($locked, $lines->keyBy('account_id')->map(fn (SavingsInterestRunLine $line) => RunBuilder::stored($line))->all(), $start, $end);

            $accounts = $locked->lines()->with('account')->get()->pluck('account', 'account_id');
            $period = CarbonImmutable::parse($start)->format('M j, Y').' to '.CarbonImmutable::parse($end)->format('M j, Y');
            $today = now()->toDateString();
            $number = 0;
            $gross = 0;
            $tax = 0;

            foreach ($lines as $line) {
                $lineGross = SavingsMath::centavos($line->gross_interest);
                $lineTax = SavingsMath::centavos($line->withholding_tax);

                if ($lineGross === 0) {
                    continue;
                }

                $account = $accounts[$line->account_id];
                $common = ['date' => $today, 'value_date' => $end, 'interest_run_id' => $locked->id, 'created_by' => $user->id];
                $interest = $this->ledger->credit($account, 'interest', $lineGross, [
                    ...$common,
                    'reference' => $this->numbers->interestReference($locked->id, ++$number),
                    'remarks' => "Interest, {$period}",
                ]);
                $taxRow = $lineTax === 0 ? null : $this->ledger->debit($account, 'withholding_tax', $lineTax, [
                    ...$common,
                    'reference' => $this->numbers->interestReference($locked->id, ++$number),
                    'remarks' => "Withholding tax on interest, {$period}",
                ]);

                $line->update(['interest_transaction_id' => $interest->id, 'tax_transaction_id' => $taxRow?->id]);
                $gross += SavingsMath::centavos($interest->credit);
                $tax += $taxRow === null ? 0 : SavingsMath::centavos($taxRow->debit);
            }

            $credited = $lines->filter(fn (SavingsInterestRunLine $line) => $line->skip_reason !== RunBuilder::SKIP_CLOSED)->pluck('account_id')->all();
            DB::table('savings_accounts')
                ->whereIn('id', $credited)
                ->where(fn ($q) => $q->whereNull('interest_credited_through')->orWhere('interest_credited_through', '<', $end))
                ->update(['interest_credited_through' => $end, 'updated_at' => now()]);

            $locked->update([
                'status' => 'posted',
                'posted_by' => $user->id,
                'posted_at' => now(),
                'total_gross' => SavingsMath::pesos($gross),
                'total_tax' => SavingsMath::pesos($tax),
                'total_net' => SavingsMath::pesos($gross - $tax),
            ]);

            if ($gross > 0) {
                $this->poster->savingsInterestRun($locked, $user->id);
            }

            return $locked;
        }, 'savings_transactions_reference_unique', $this->conflictMessage());
    }

    /**
     * 409 unless this run is the next one for its product and branch (review
     * M1): it starts the day after the latest posted run (or on the first
     * period) and no earlier run is still open. Checked under the run's lock,
     * so a previous period reversed after this run was created can't leave a
     * gap that loses its interest.
     */
    private function assertNextInLine(SavingsInterestRun $run, string $start): void
    {
        $expected = $this->builder->nextStart($run->product, (int) $run->branch_id);
        $earlierOpen = SavingsInterestRun::query()
            ->where('product_id', $run->product_id)
            ->where('branch_id', $run->branch_id)
            ->where('id', '!=', $run->id)
            ->whereIn('status', ['draft', 'approved'])
            ->where('period_start', '<', $start)
            ->exists();

        if ($expected === $start && ! $earlierOpen) {
            return;
        }

        $day = fn (?string $date) => $date === null ? 'an earlier day' : CarbonImmutable::parse($date)->format('M j, Y');

        throw LoanWriteTransaction::conflict($earlierOpen
            ? 'An earlier interest period for this product and branch is not posted yet. Post or reject it first; nothing was posted.'
            : 'This run starts on '.$day($start).', but interest for this product and branch must be credited from '.$day($expected)
                .' next (a previous run was reversed). Nothing was posted. Reject this run and run that period first.');
    }

    /**
     * 409 when the figures worked out now differ from the approved draft's.
     *
     * @param  array<int, array<string, mixed>>  $stored
     */
    private function assertUnchanged(SavingsInterestRun $run, array $stored, string $start, string $end): void
    {
        $fresh = $this->builder->figures($run->product, (int) $run->branch_id, $start, $end);
        $changed = array_keys($fresh) !== array_keys($stored);

        foreach ($fresh as $accountId => $line) {
            foreach (RunBuilder::COMPARED as $field) {
                if ($changed || ($stored[$accountId][$field] ?? null) !== $line[$field]) {
                    $changed = true;
                    break 2;
                }
            }
        }

        if ($changed) {
            throw LoanWriteTransaction::conflict(
                'The figures changed after this run was approved: an account in the period was opened, closed or had a transaction value-dated in it. '
                .'Nothing was posted. Reject this run and create it again.');
        }
    }

    private function lockRun(SavingsInterestRun $run): SavingsInterestRun
    {
        return SavingsInterestRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertNotMaker(SavingsInterestRun $run, User $checker, string $verb): void
    {
        abort_if(
            $run->created_by !== null && (int) $run->created_by === (int) $checker->id,
            403,
            "You created this interest run, so another user must {$verb} it.",
        );
    }

    private function statusWords(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function conflictMessage(): string
    {
        return 'Another change to this interest run was saved at the same time. Reload and try again.';
    }
}
