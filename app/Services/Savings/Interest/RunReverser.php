<?php

namespace App\Services\Savings\Interest;

use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\LoanWriteTransaction;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted interest run (design §5). Only the latest posted run for
 * its product and branch, and only while no later run is open (422).
 *
 * Under the account locks, then the run's: each tax row is credited back and
 * each interest row debited back with a `reversal` row of its own value date
 * (dated today), the originals left untouched; then the accounts' interest
 * is credited through the day before the period again; the journal is
 * reversed; and `live_key` is freed so the period can be run again as a new
 * run. An account that would go below ₱0.00 or is closed refuses the whole
 * reversal (SavingsLedger, 422).
 */
class RunReverser
{
    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
    ) {}

    public function reverse(SavingsInterestRun $run, User $user, string $reason): SavingsInterestRun
    {
        $accountIds = $run->lines()->pluck('account_id')->all();

        return LoanWriteTransaction::run(function () use ($run, $user, $reason, $accountIds): SavingsInterestRun {
            // The product row first (review M1), as a create takes it: the two
            // never interleave, so no run is created on a period being reversed.
            SavingsProduct::query()->whereKey($run->product_id)->lockForUpdate()->firstOrFail();
            $accounts = $this->locks->accounts($accountIds);
            $locked = SavingsInterestRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'posted') {
                throw LoanWriteTransaction::conflict($locked->status === 'reversed'
                    ? 'This interest run has already been reversed.'
                    : 'Only a posted interest run can be reversed.');
            }

            $this->assertLatest($locked);

            $start = $locked->period_start->toDateString();
            $end = $locked->period_end->toDateString();
            $lines = $locked->lines()->with(['interestTransaction', 'taxTransaction'])->orderBy('id')->get();
            $remarks = "Reversal of interest run #{$locked->id}: {$reason}";

            foreach ($lines as $line) {
                $this->reverseLine($accounts[$line->account_id], $line, $user, $remarks);
            }

            $before = CarbonImmutable::parse($start)->subDay()->toDateString();

            foreach ($lines as $line) {
                $account = $accounts[$line->account_id];

                if ($line->skip_reason === RunBuilder::SKIP_CLOSED) {
                    continue;
                }

                DB::table('savings_accounts')
                    ->where('id', $account->id)
                    ->where('interest_credited_through', $end)
                    ->update([
                        'interest_credited_through' => $before >= $account->opened_on->toDateString() ? $before : null,
                        'updated_at' => now(),
                    ]);
            }

            $this->poster->reverseFor($locked, 'savings_interest', $user->id, $reason);

            $locked->update([
                'status' => 'reversed',
                'reversed_by' => $user->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'live_key' => null,
            ]);

            return $locked;
        }, null, 'Another change to this interest run or its accounts was saved at the same time. Reload and try again.');
    }

    /** @throws ValidationException */
    private function assertLatest(SavingsInterestRun $run): void
    {
        $later = SavingsInterestRun::query()
            ->where('product_id', $run->product_id)
            ->where('branch_id', $run->branch_id)
            ->where('id', '!=', $run->id)
            ->where('period_start', '>', $run->period_start->toDateString())
            ->whereIn('status', ['draft', 'approved', 'posted'])
            ->orderByDesc('period_start')
            ->first();

        if ($later !== null) {
            $what = $later->status === 'posted' ? 'posted' : 'open';

            throw ValidationException::withMessages(['run' => [
                'Only the latest interest run can be reversed. The run from '.$later->period_start->format('M j, Y').' to '
                .$later->period_end->format('M j, Y')." is {$what}; ".($what === 'posted' ? 'reverse it first.' : 'reject it first.'),
            ]]);
        }
    }

    private function reverseLine(SavingsAccount $account, SavingsInterestRunLine $line, User $user, string $remarks): void
    {
        $tax = $line->taxTransaction;
        $interest = $line->interestTransaction;

        if ($tax instanceof SavingsTransaction) {
            $this->ledger->credit($account, 'reversal', SavingsMath::centavos($tax->debit), [
                'value_date' => $tax->value_date->toDateString(),
                'reverses_transaction_id' => $tax->id,
                'interest_run_id' => $line->run_id,
                'created_by' => $user->id,
                'remarks' => $remarks,
            ]);
        }

        if ($interest instanceof SavingsTransaction) {
            $this->ledger->debit($account, 'reversal', SavingsMath::centavos($interest->credit), [
                'value_date' => $interest->value_date->toDateString(),
                'reverses_transaction_id' => $interest->id,
                'interest_run_id' => $line->run_id,
                'created_by' => $user->id,
                'remarks' => $remarks,
            ]);
        }
    }
}
