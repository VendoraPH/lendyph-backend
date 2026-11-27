<?php

namespace App\Services\Savings\Reports;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One savings account's statement for a period (design §2): the opening
 * balance (every row dated before the period), each row in the period with
 * the running balance after it, the period's money in and out, and the
 * closing balance. By business date; the period defaults to the account's
 * opening day through today. Worked out in centavos from the rows
 * themselves, so the closing balance on today equals the account's balance.
 */
class StatementBuilder
{
    public function __construct(private readonly SavingsReports $reports) {}

    /**
     * The account's balance before `$from`: every row dated earlier, in
     * centavos. The statement's opening balance, and the member portal
     * passbook's.
     */
    public function openingBalance(SavingsAccount $account, string $from): int
    {
        $before = DB::table('savings_transactions')
            ->where('account_id', $account->id)
            ->where('date', '<', $from)
            ->selectRaw('COALESCE(SUM(credit), 0) AS credits, COALESCE(SUM(debit), 0) AS debits')
            ->first();

        return SavingsMath::centavos($before->credits) - SavingsMath::centavos($before->debits);
    }

    /** @return array<string, mixed> */
    public function build(SavingsAccount $account, ?string $dateFrom, ?string $dateTo): array
    {
        $account->loadMissing(['borrower', 'branch', 'product']);
        $from = $dateFrom ?? $account->opened_on->toDateString();
        $to = $dateTo ?? CarbonImmutable::parse(now()->toDateString())->max(CarbonImmutable::parse($from))->toDateString();

        $opening = $this->openingBalance($account, $from);

        $running = $opening;
        $credits = 0;
        $debits = 0;
        $rows = [];

        $lines = SavingsTransaction::query()
            ->where('account_id', $account->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $credit = SavingsMath::centavos($line->credit);
            $debit = SavingsMath::centavos($line->debit);
            $running += $credit - $debit;
            $credits += $credit;
            $debits += $debit;

            $rows[] = [
                'id' => $line->id,
                'date' => $line->date->toDateString(),
                'value_date' => $line->value_date->toDateString(),
                'reference' => $line->reference,
                'type' => $line->type,
                'method' => $line->method,
                'external_reference' => $line->external_reference,
                'remarks' => $line->remarks,
                'credit' => SavingsMath::pesos($credit),
                'debit' => SavingsMath::pesos($debit),
                'balance_after' => SavingsMath::pesos($running),
                'reverses_transaction_id' => $line->reverses_transaction_id,
            ];
        }

        return [
            'account' => [
                ...$this->reports->accountFields($account),
                'id' => $account->id,
                'opened_on' => $account->opened_on->toDateString(),
                'closed_on' => $account->closed_on?->toDateString(),
                'interest_credited_through' => $account->interest_credited_through?->toDateString(),
            ],
            'date_from' => $from,
            'date_to' => $to,
            'opening_balance' => SavingsMath::pesos($opening),
            'closing_balance' => SavingsMath::pesos($running),
            'totals' => ['credit' => SavingsMath::pesos($credits), 'debit' => SavingsMath::pesos($debits), 'count' => count($rows)],
            'rows' => $rows,
        ];
    }
}
