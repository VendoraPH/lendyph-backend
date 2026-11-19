<?php

namespace App\Services\Amla\Events\Sources;

use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventCannotBeBuilt;
use App\Services\Amla\Events\EventSource;
use App\Services\Amla\Events\PesoAmount;
use App\Services\BranchAttribution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Share capital rows (AMLA design §2 "Share capital", §4.4): only rows linked
 * to nothing else are events (manual entries and auto-credit), a credit as a
 * contribution and a debit as a withdrawal, by the row's `method`, dated
 * `date`, recorded at `created_at`, in the branch BranchAttribution gives it.
 * A row linked to a payment, a release or a savings row already sits inside
 * that event.
 */
final class ShareCapital extends EventSource
{
    public function name(): string
    {
        return 'share_capital';
    }

    public function words(): string
    {
        return 'Share capital';
    }

    public function eventSource(): string
    {
        return 'share_capital';
    }

    public function recordedColumn(): string
    {
        return 'share_capital_ledger.created_at';
    }

    public function idColumn(): string
    {
        return 'share_capital_ledger.id';
    }

    public function rows(): Builder
    {
        return DB::table('share_capital_ledger')
            ->leftJoin('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
            ->whereNull('share_capital_ledger.repayment_id')
            ->whereNull('share_capital_ledger.loan_id')
            ->whereNull('share_capital_ledger.savings_transaction_id')
            ->select([
                'share_capital_ledger.id', 'share_capital_ledger.created_at as recorded', 'share_capital_ledger.borrower_id',
                'share_capital_ledger.date', 'share_capital_ledger.reference', 'share_capital_ledger.debit', 'share_capital_ledger.credit',
                'share_capital_ledger.method', 'share_capital_ledger.reference_number',
                DB::raw(BranchAttribution::shareCapitalBranchSql().' as branch_id'),
            ]);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $debit = PesoAmount::centavos((string) $row->debit);
        $credit = PesoAmount::centavos((string) $row->credit);

        if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
            throw new EventCannotBeBuilt('Share capital row '.($row->reference ?? "#{$row->id}").' doesn\'t carry exactly one of a debit or a credit above ₱0.00.');
        }

        return new BuiltRow([$this->event($row, $credit > 0 ? 'share_capital_contribution' : 'share_capital_withdrawal', $harvestedBy, [
            'direction' => $credit > 0 ? 'in' : 'out',
            'is_internal' => false,
            'channel' => $row->method,
            'amount' => PesoAmount::normal((string) ($credit > 0 ? $row->credit : $row->debit)),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => (string) $row->date,
            'reference' => $row->reference,
            'channel_reference' => $row->reference_number,
        ])]);
    }
}
