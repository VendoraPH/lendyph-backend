<?php

namespace App\Services\Amla\Events\Sources;

use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventSource;
use App\Services\BranchAttribution;
use App\Services\Savings\TimeDeposits\Placement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Time deposit placements (AMLA design §2 "Time deposits", §4.4): one event
 * per deposit, its `principal`, by its `funding_method`, in the deposit's
 * branch, dated `placement_date` and recorded at `created_at`. A placement
 * funded from savings (linked to its savings row) or rolled over from an
 * earlier deposit (linked to it) is internal, never new funds.
 */
final class TimeDepositPlacements extends EventSource
{
    public function name(): string
    {
        return 'time_deposit_placements';
    }

    public function words(): string
    {
        return 'Time deposit placements';
    }

    public function eventSource(): string
    {
        return 'time_deposit';
    }

    public function recordedColumn(): string
    {
        return 'time_deposits.created_at';
    }

    public function idColumn(): string
    {
        return 'time_deposits.id';
    }

    public function rows(): Builder
    {
        return DB::table('time_deposits')->select([
            'time_deposits.id', 'time_deposits.created_at as recorded', 'time_deposits.certificate_number', 'time_deposits.borrower_id',
            'time_deposits.principal', 'time_deposits.placement_date', 'time_deposits.funding_method', 'time_deposits.funding_transaction_id',
            'time_deposits.rolled_over_from_id',
            DB::raw(BranchAttribution::timeDepositBranchSql().' as branch_id'),
        ]);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $rollover = $row->funding_method === Placement::ROLLOVER_FUNDING || $row->rolled_over_from_id !== null;
        $fromSavings = $row->funding_method === 'savings';

        return new BuiltRow([$this->event($row, 'time_deposit_placement', $harvestedBy, [
            'direction' => 'in',
            'is_internal' => $rollover || $fromSavings,
            'channel' => $row->funding_method,
            'amount' => $this->positive((string) $row->principal, 'Time deposit '.($row->certificate_number ?? "#{$row->id}")),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => (string) $row->placement_date,
            'reference' => $row->certificate_number,
            'linked_source' => match (true) {
                $rollover && $row->rolled_over_from_id !== null => 'time_deposit',
                $fromSavings && $row->funding_transaction_id !== null => 'savings_transaction',
                default => null,
            },
            'linked_source_id' => match (true) {
                $rollover && $row->rolled_over_from_id !== null => (int) $row->rolled_over_from_id,
                $fromSavings && $row->funding_transaction_id !== null => (int) $row->funding_transaction_id,
                default => null,
            },
        ])]);
    }

    protected function coverageKinds(): array
    {
        return ['time_deposit_placement'];
    }
}
