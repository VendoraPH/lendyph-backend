<?php

namespace App\Http\Resources\Savings;

use App\Models\SavingsProduct;
use App\Services\Savings\RateBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A savings product as the API shows it (the frontend's `SavingsProduct`):
 * money as two-decimal strings, the rates in force today per term
 * (`current_rates`), how many accounts and time deposits it holds
 * (`account_count`) and whether it can still be deleted (none of either, and
 * no time deposit product paying early terminations at its rate). Load
 * `accounts_count`, `time_deposits_count`, `pretermination_for_count` and
 * `rates` first.
 *
 * @mixin SavingsProduct
 */
class SavingsProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $holdings = (int) ($this->accounts_count ?? 0) + (int) ($this->time_deposits_count ?? 0);
        $inUse = $holdings > 0 || (int) ($this->pretermination_for_count ?? 0) > 0;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'interest_crediting' => $this->interest_crediting,
            'minimum_opening_balance' => $this->minimum_opening_balance,
            'maintaining_balance' => $this->maintaining_balance,
            'minimum_adb_for_interest' => $this->minimum_adb_for_interest,
            'term_options_days' => $this->term_options_days === null ? null : array_map('intval', $this->term_options_days),
            'pretermination_product_id' => $this->pretermination_product_id,
            'is_active' => (bool) $this->is_active,
            // Product rules (gap round S1): null limits are no limit, null lists are everyone.
            'max_balance' => $this->max_balance,
            'min_deposit' => $this->min_deposit,
            'max_deposit' => $this->max_deposit,
            'min_withdrawal' => $this->min_withdrawal,
            'max_withdrawal' => $this->max_withdrawal,
            'free_withdrawals_per_month' => $this->free_withdrawals_per_month,
            'excess_withdrawal_fee' => $this->excess_withdrawal_fee,
            'allow_deposits' => (bool) ($this->allow_deposits ?? true),
            'allow_withdrawals' => (bool) ($this->allow_withdrawals ?? true),
            'allow_transfers' => (bool) ($this->allow_transfers ?? true),
            'eligible_member_types' => $this->eligible_member_types,
            'eligible_branch_ids' => $this->eligible_branch_ids === null ? null : array_map('intval', $this->eligible_branch_ids),
            'current_rates' => app(RateBook::class)->current($this->resource, now()->toDateString()),
            'account_count' => $holdings,
            'can_delete' => ! $inUse,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
