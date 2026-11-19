<?php

namespace App\Services\Amla\Events\Sources;

use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventSource;
use App\Services\BranchAttribution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Time deposit payouts (AMLA design §2 "Time deposits", §4.4): a settled
 * deposit that paid money out (`settled_amount` above ₱0.00) is one event,
 * that amount, by its `payout_method`, dated `settled_on` and recorded at the
 * deposit's `updated_at`. Paid into the member's savings it is internal; the
 * part rolled over is the new deposit's placement.
 */
final class TimeDepositPayouts extends EventSource
{
    public function name(): string
    {
        return 'time_deposit_payouts';
    }

    public function words(): string
    {
        return 'Time deposit payouts';
    }

    public function eventSource(): string
    {
        return 'time_deposit';
    }

    public function recordedColumn(): string
    {
        return 'time_deposits.updated_at';
    }

    public function idColumn(): string
    {
        return 'time_deposits.id';
    }

    public function rows(): Builder
    {
        return DB::table('time_deposits')
            ->whereNotNull('time_deposits.settled_on')
            ->where('time_deposits.settled_amount', '>', 0)
            ->select([
                'time_deposits.id', 'time_deposits.updated_at as recorded', 'time_deposits.certificate_number', 'time_deposits.borrower_id',
                'time_deposits.settled_on', 'time_deposits.settled_amount', 'time_deposits.payout_method', 'time_deposits.payout_account_id',
                DB::raw(BranchAttribution::timeDepositBranchSql().' as branch_id'),
            ]);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $intoSavings = $row->payout_method === 'savings';

        return new BuiltRow([$this->event($row, 'time_deposit_payout', $harvestedBy, [
            'direction' => 'out',
            'is_internal' => $intoSavings,
            'channel' => $row->payout_method,
            'amount' => $this->positive((string) $row->settled_amount, 'The payout of time deposit '.($row->certificate_number ?? "#{$row->id}")),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => (string) $row->settled_on,
            'reference' => $row->certificate_number,
            'linked_source' => $intoSavings && $row->payout_account_id !== null ? 'savings_account' : null,
            'linked_source_id' => $intoSavings ? self::intOrNull($row->payout_account_id) : null,
        ])]);
    }

    protected function coverageKinds(): array
    {
        return ['time_deposit_payout'];
    }
}
