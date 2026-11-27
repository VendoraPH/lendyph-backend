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
 * Savings rows (AMLA design §2 "Savings", §4.4): one event per row, its own
 * debit or credit as the savings lists show it, in its account's branch,
 * dated `date` and recorded at `created_at`.
 * - Customer money: deposits, withdrawals and closing payouts by cash, bank
 *   transfer, GCash or Maya.
 * - Internal, never new funds: transfers between accounts, interest,
 *   withholding tax, fees, transfers to share capital, money moved to or from
 *   a time deposit, and anything by the `savings` method.
 * - A reversal row is an internal event linked to the row it reverses, and
 *   marks that row's event reversed. A row already reversed when it is
 *   harvested is harvested marked reversed.
 */
final class SavingsTransactions extends EventSource
{
    /** @var array<string, array{0: string, 1: bool}> type => kind, always internal */
    private const KINDS = [
        'deposit' => ['savings_deposit', false],
        'withdrawal' => ['savings_withdrawal', false],
        'closing' => ['savings_closing', false],
        'transfer_in' => ['savings_transfer_in', true],
        'transfer_out' => ['savings_transfer_out', true],
        'interest' => ['savings_interest', true],
        'withholding_tax' => ['savings_withholding_tax', true],
        'fee' => ['savings_fee', true],
        'share_capital_transfer' => ['savings_to_share_capital', true],
        'reversal' => ['savings_reversal', true],
    ];

    /** The method of a movement between the member's own savings, time deposits and share capital. */
    private const INTERNAL_METHOD = 'savings';

    public function name(): string
    {
        return 'savings';
    }

    public function words(): string
    {
        return 'Savings';
    }

    public function eventSource(): string
    {
        return 'savings_transaction';
    }

    public function recordedColumn(): string
    {
        return 'savings_transactions.created_at';
    }

    public function idColumn(): string
    {
        return 'savings_transactions.id';
    }

    public function rows(): Builder
    {
        return DB::table('savings_transactions')
            ->join('savings_accounts', 'savings_accounts.id', '=', 'savings_transactions.account_id')
            ->select([
                'savings_transactions.id', 'savings_transactions.created_at as recorded', 'savings_transactions.reference',
                'savings_transactions.type', 'savings_transactions.date', 'savings_transactions.debit', 'savings_transactions.credit',
                'savings_transactions.method', 'savings_transactions.external_reference', 'savings_transactions.counterpart_transaction_id',
                'savings_transactions.reverses_transaction_id', 'savings_transactions.time_deposit_id', 'savings_transactions.fee_for_transaction_id',
                'savings_accounts.borrower_id',
                DB::raw(BranchAttribution::savingsBranchSql().' as branch_id'),
            ])
            ->selectSub($this->firstReversal('id'), 'reversed_by_id')
            ->selectSub($this->firstReversal('created_at'), 'reversed_by_at');
    }

    /** The first reversal row of a savings row, one column of it, as a subquery. */
    private function firstReversal(string $column): Builder
    {
        return DB::table('savings_transactions as reversal')
            ->whereColumn('reversal.reverses_transaction_id', 'savings_transactions.id')
            ->where('reversal.type', 'reversal')
            ->orderBy('reversal.id')
            ->limit(1)
            ->select("reversal.{$column}");
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $what = 'Savings row '.($row->reference ?? "#{$row->id}");

        if (! array_key_exists((string) $row->type, self::KINDS)) {
            throw new EventCannotBeBuilt("{$what} is of a type AMLA doesn't know (\"{$row->type}\").");
        }

        $debit = PesoAmount::centavos((string) $row->debit);
        $credit = PesoAmount::centavos((string) $row->credit);

        if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
            throw new EventCannotBeBuilt("{$what} doesn't carry exactly one of a debit or a credit above ₱0.00.");
        }

        [$kind, $internal] = self::KINDS[$row->type];
        [$kind, $linked, $linkedId] = match (true) {
            $row->time_deposit_id !== null && $row->type === 'deposit' => ['savings_from_time_deposit', 'time_deposit', $row->time_deposit_id],
            $row->time_deposit_id !== null && $row->type === 'withdrawal' => ['savings_to_time_deposit', 'time_deposit', $row->time_deposit_id],
            $row->reverses_transaction_id !== null => [$kind, 'savings_transaction', $row->reverses_transaction_id],
            $row->counterpart_transaction_id !== null => [$kind, 'savings_transaction', $row->counterpart_transaction_id],
            $row->fee_for_transaction_id !== null => [$kind, 'savings_transaction', $row->fee_for_transaction_id],
            default => [$kind, null, null],
        };
        $internal = $internal || $row->time_deposit_id !== null || $row->method === self::INTERNAL_METHOD;
        $reversed = $row->reversed_by_id !== null;

        $event = $this->event($row, $kind, $harvestedBy, [
            'direction' => $credit > 0 ? 'in' : 'out',
            'is_internal' => $internal,
            'channel' => $row->method,
            'amount' => PesoAmount::normal((string) ($credit > 0 ? $row->credit : $row->debit)),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => (string) $row->date,
            'reference' => $row->reference,
            'channel_reference' => $row->external_reference,
            'linked_source' => $linked,
            'linked_source_id' => self::intOrNull($linkedId),
            'reversed_at' => $reversed ? $row->reversed_by_at : null,
            'reversal_source' => $reversed ? 'savings_transaction' : null,
            'reversal_source_id' => $reversed ? (int) $row->reversed_by_id : null,
        ]);

        $reversals = $row->type === 'reversal' && $row->reverses_transaction_id !== null
            ? [['source' => 'savings_transaction', 'source_id' => (int) $row->reverses_transaction_id, 'by' => 'savings_transaction', 'by_id' => (int) $row->id, 'at' => (string) $row->recorded]]
            : [];

        return new BuiltRow([$event], $reversals);
    }
}
