<?php

namespace App\Http\Resources\Savings;

use App\Models\SavingsProduct;
use App\Services\Savings\RateBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A savings product as the API shows it: money as two-decimal strings, the
 * rates in force today per term (`current_rates`), how many accounts it holds
 * and whether it can still be deleted (no account, no time deposit, and no
 * time deposit product paying early terminations at its rate). Load
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
        $accounts = (int) ($this->accounts_count ?? 0);
        $inUse = $accounts > 0 || (int) ($this->time_deposits_count ?? 0) > 0 || (int) ($this->pretermination_for_count ?? 0) > 0;

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
            'current_rates' => app(RateBook::class)->current($this->resource, now()->toDateString()),
            'account_count' => $accounts,
            'can_delete' => ! $inUse,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
