<?php

namespace App\Services\Portal\Read;

use App\Models\ShareCapitalLedger;
use App\Services\Portal\MemberScope;
use App\Services\ShareCapitalMemberService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A member's share capital (portal design §3): the paid-up balance and the
 * period totals from ShareCapitalMemberService (the Subsidiary Ledger's
 * figures), the pledge, and the ledger rows with the server's running
 * balance (date, then posting order).
 */
final class ShareCapitalReader
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly ShareCapitalMemberService $members,
    ) {}

    /**
     * The paid-up balance, the pledge and what remains of it come from
     * ShareCapitalMemberService::pledgePosition().
     *
     * @return array{paid_up: string, pledged: string, remaining_pledge: string, total_contributions: string, last_contribution: array{date: string, amount: string}|null}
     */
    public function summary(): array
    {
        $memberId = $this->scope->borrowerId();
        $period = $this->members->memberPeriod($memberId, null, null);
        $pledge = $this->members->pledgePosition($memberId);

        $last = ShareCapitalLedger::query()
            ->where('borrower_id', $memberId)
            ->where('credit', '>', 0)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first(['date', 'credit']);

        return [
            'paid_up' => Pesos::of($pledge['paid_up']),
            'pledged' => Pesos::of($pledge['pledged']),
            'remaining_pledge' => Pesos::of($pledge['remaining_pledge']),
            'total_contributions' => Pesos::of($period['total_credits']),
            'last_contribution' => $last === null ? null : [
                'date' => $last->date->toDateString(),
                'amount' => Pesos::of((string) $last->getRawOriginal('credit')),
            ],
        ];
    }

    /** The paid-up balance alone, for the dashboard and the membership card. */
    public function paidUp(): string
    {
        return Pesos::of($this->members->memberPeriod($this->scope->borrowerId(), null, null)['ending_balance']);
    }

    /** @return LengthAwarePaginator<int, ShareCapitalLedger> newest first */
    public function transactions(int $perPage): LengthAwarePaginator
    {
        return $this->members->withRunningBalance(ShareCapitalLedger::query())
            ->where('share_capital_ledger.borrower_id', $this->scope->borrowerId())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  Collection<int, ShareCapitalLedger>  $rows
     * @return list<array{date: string, reference: string|null, description: string|null, debit: string, credit: string, running_balance: string}>
     */
    public function rows(Collection $rows): array
    {
        return $rows->map(fn (ShareCapitalLedger $row): array => [
            'date' => $row->date->toDateString(),
            'reference' => $row->reference,
            'description' => $row->description,
            'debit' => Pesos::of((string) $row->getRawOriginal('debit')),
            'credit' => Pesos::of((string) $row->getRawOriginal('credit')),
            'running_balance' => Pesos::of((string) $row->getAttribute('running_balance')),
        ])->values()->all();
    }
}
