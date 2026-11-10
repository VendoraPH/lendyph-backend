<?php

namespace App\Services\Savings\TimeDeposits;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A placement as the savings screens read it (`TimeDeposit` in the frontend's
 * types/savings.ts): money as two-decimal strings, rates as four-decimal
 * strings, dates as YYYY-MM-DD, people as `{id, name}` (null = the system),
 * and the member's name and code and the branch's name beside their ids.
 *
 * A rollover's own placement is funded by the one it came from
 * (`rolled_over_from_id`), so its `funding_method` reads null: no money
 * method paid for it.
 */
class TimeDepositPresenter
{
    /** The relations {@see self::present()} reads, for eager loading. */
    public const RELATIONS = [
        'borrower:id,borrower_code,first_name,middle_name,last_name,suffix',
        'branch:id,name',
        'product:id,code,name',
        'payoutAccount:id,account_number',
        'settler:id,first_name,last_name,username',
        'creator:id,first_name,last_name,username',
        'rolledOverInto:id,rolled_over_from_id',
    ];

    /**
     * @param  Builder<TimeDeposit>  $query
     * @return Builder<TimeDeposit>
     */
    public function withRelations(Builder $query): Builder
    {
        return $query->with(self::RELATIONS);
    }

    /** @return array<string, mixed> */
    public function present(TimeDeposit $deposit): array
    {
        $deposit->loadMissing(self::RELATIONS);

        return [
            'id' => $deposit->id,
            'certificate_number' => $deposit->certificate_number,
            'borrower_id' => $deposit->borrower_id,
            'borrower_name' => $deposit->borrower?->full_name,
            'borrower_code' => $deposit->borrower?->borrower_code,
            'branch_id' => $deposit->branch_id,
            'branch_name' => $deposit->branch?->name,
            'product' => [
                'id' => $deposit->product_id,
                'code' => $deposit->product?->code,
                'name' => $deposit->product?->name,
            ],
            'principal' => $deposit->principal,
            'annual_rate' => $deposit->annual_rate,
            'pretermination_rate' => $deposit->pretermination_rate,
            'withholding_tax_rate' => $deposit->withholding_tax_rate,
            'term_days' => $deposit->term_days,
            'placement_date' => $deposit->placement_date?->toDateString(),
            'maturity_date' => $deposit->maturity_date?->toDateString(),
            'gross_interest' => $deposit->gross_interest,
            'withholding_tax' => $deposit->withholding_tax,
            'net_interest' => $deposit->net_interest,
            'maturity_amount' => $deposit->maturity_amount,
            'maturity_instruction' => $deposit->maturity_instruction,
            'funding_method' => $deposit->funding_method === Placement::ROLLOVER_FUNDING ? null : $deposit->funding_method,
            'payout_account_id' => $deposit->payout_account_id,
            'payout_account_number' => $deposit->payoutAccount?->account_number,
            'status' => $deposit->status,
            'settled_on' => $deposit->settled_on?->toDateString(),
            'settled_interest' => $deposit->settled_interest,
            'settled_tax' => $deposit->settled_tax,
            'settled_amount' => $deposit->settled_amount,
            'payout_method' => $deposit->payout_method,
            'settled_by' => SavingsUserRef::from($deposit->settler),
            'rolled_over_from_id' => $deposit->rolled_over_from_id,
            'rolled_over_to_id' => $deposit->rolledOverInto?->id,
            'created_by' => SavingsUserRef::from($deposit->creator),
            'created_at' => $deposit->created_at,
        ];
    }

    /**
     * @param  Collection<int, TimeDeposit>  $deposits
     * @return list<array<string, mixed>>
     */
    public function presentMany(Collection $deposits): array
    {
        return $deposits->map(fn (TimeDeposit $deposit) => $this->present($deposit))->values()->all();
    }

    /**
     * A payout or pre-termination waiting for a checker, in the approvals
     * queue's shape (`SavingsApproval`), for the 202 answer.
     *
     * @return array<string, mixed>
     */
    public function approval(SavingsApproval $approval, TimeDeposit $deposit): array
    {
        $approval->loadMissing(['requester:id,first_name,last_name,username', 'firstChecker:id,first_name,last_name,username', 'decider:id,first_name,last_name,username']);
        $deposit->loadMissing(self::RELATIONS);

        return [
            'id' => $approval->id,
            'kind' => $approval->kind,
            'status' => $approval->status,
            'account_id' => $approval->account_id,
            'account_number' => null,
            'time_deposit_id' => $deposit->id,
            'certificate_number' => $deposit->certificate_number,
            'borrower_name' => $deposit->borrower?->full_name,
            'branch_name' => $deposit->branch?->name,
            'target_transaction_id' => $approval->target_transaction_id,
            'target_reference' => null,
            'amount' => $approval->amount,
            'required_checkers' => $approval->required_checkers,
            'requested_by' => SavingsUserRef::from($approval->requester),
            'first_checker' => SavingsUserRef::from($approval->firstChecker),
            'decided_by' => SavingsUserRef::from($approval->decider),
            'decision_reason' => $approval->decision_reason,
            'decided_at' => $approval->decided_at,
            'expires_at' => $approval->expires_at,
            'result_transaction_id' => $approval->result_transaction_id,
            'created_at' => $approval->created_at,
        ];
    }
}
