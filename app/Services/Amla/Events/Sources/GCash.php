<?php

namespace App\Services\Amla\Events\Sources;

use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventCannotBeBuilt;
use App\Services\Amla\Events\EventSource;
use App\Services\Amla\Events\PesoAmount;
use App\Services\BranchAttribution;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * GCash counter transactions (AMLA design §2 "GCash", §4.4): a paid Cash In
 * or a completed Cash Out is one event of the cash actually taken in or paid
 * out across the counter, its `total_amount` as the GCash list shows it (Cash
 * In: amount plus charge; Cash Out: amount less charge), so the channel is
 * cash. A pending Cash In is tracked, not an event, until it is paid.
 *
 * Dated `paid_at` (Cash In) or `transaction_date` (Cash Out), as the journal
 * is. Recorded when the money moved: `paid_at` (set when a Cash In is paid at
 * the counter or marked paid), else `created_at` (a Cash Out is created
 * completed). Never `updated_at`, which any later write moves (the branch
 * attribution backfill, a data fix), so an old row is never pulled into
 * monitoring. Its branch is BranchAttribution's: a walk-in done at head office
 * has none.
 *
 * A row whose total doesn't reconcile with its amount and charge, as the books
 * would refuse it, is a failure rather than an event of a guessed amount.
 */
final class GCash extends EventSource
{
    public function name(): string
    {
        return 'gcash';
    }

    public function words(): string
    {
        return 'GCash';
    }

    public function eventSource(): string
    {
        return 'gcash';
    }

    /** SQL for when a row's money moved; see the class note. */
    public const RECORDED_SQL = 'COALESCE(gcash_transactions.paid_at, gcash_transactions.created_at)';

    public function recordedColumn(): Expression
    {
        return DB::raw(self::RECORDED_SQL);
    }

    public function idColumn(): string
    {
        return 'gcash_transactions.id';
    }

    public function rows(): Builder
    {
        return $this->query()->whereIn('gcash_transactions.status', ['paid', 'completed']);
    }

    public function pendingRows(): Builder
    {
        return $this->query()->where('gcash_transactions.status', 'pending');
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $what = 'GCash transaction '.($row->reference_no ?? "#{$row->id}");
        $cashIn = $row->type === 'cash_in';
        $amount = PesoAmount::centavos((string) $row->amount);
        $charge = PesoAmount::centavos((string) ($row->charge_amount ?? '0'));
        $total = PesoAmount::centavos((string) $row->total_amount);
        $expected = $cashIn ? $amount + $charge : $amount - $charge;

        if ($total !== $expected) {
            throw new EventCannotBeBuilt("{$what} does not reconcile: its total is ".PesoAmount::words((string) $row->total_amount)
                .', but its amount and charge make '.PesoAmount::words(PesoAmount::fromCentavos($expected)).'.');
        }

        $date = $cashIn ? $row->paid_at : $row->transaction_date;

        if ($date === null) {
            throw new EventCannotBeBuilt("{$what} is {$row->status} but has no ".($cashIn ? 'paid' : 'transaction').' date.');
        }

        return new BuiltRow([$this->event($row, $cashIn ? 'gcash_cash_in' : 'gcash_cash_out', $harvestedBy, [
            'direction' => $cashIn ? 'in' : 'out',
            'is_internal' => false,
            'channel' => 'cash',
            'amount' => $this->positive((string) $row->total_amount, $what),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => self::intOrNull($row->gcash_non_member_id),
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => substr((string) $date, 0, 10),
            'reference' => $row->reference_no,
        ])]);
    }

    private function query(): Builder
    {
        return DB::table('gcash_transactions')
            ->leftJoin('borrowers', 'borrowers.id', '=', 'gcash_transactions.borrower_id')
            ->select([
                'gcash_transactions.id', DB::raw(self::RECORDED_SQL.' as recorded'), 'gcash_transactions.reference_no',
                'gcash_transactions.type', 'gcash_transactions.status', 'gcash_transactions.amount', 'gcash_transactions.charge_amount',
                'gcash_transactions.total_amount', 'gcash_transactions.borrower_id', 'gcash_transactions.gcash_non_member_id',
                'gcash_transactions.transaction_date', 'gcash_transactions.paid_at',
                DB::raw(BranchAttribution::gcashBranchSql().' as branch_id'),
            ]);
    }
}
