<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open account at ₱0.00 in its member's branch. The factory writes the
 * row only: `ledger_balance` is whatever it is given, and no transaction is
 * written, so tests that need a true balance post through SavingsLedger.
 *
 * @extends Factory<SavingsAccount>
 */
class SavingsAccountFactory extends Factory
{
    protected $model = SavingsAccount::class;

    public function definition(): array
    {
        return [
            'account_number' => 'SAV-2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'borrower_id' => Borrower::factory(),
            'product_id' => SavingsProduct::factory(),
            'branch_id' => fn (array $attributes) => Borrower::query()->whereKey($attributes['borrower_id'])->value('branch_id'),
            'status' => SavingsAccount::STATUS_ACTIVE,
            'ledger_balance' => '0.00',
            'opened_on' => now()->toDateString(),
            'open_key' => fn (array $attributes) => ($attributes['status'] ?? null) === SavingsAccount::STATUS_CLOSED
                ? null
                : SavingsAccount::openKey((int) $attributes['borrower_id'], (int) $attributes['product_id']),
            'created_by' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => SavingsAccount::STATUS_CLOSED, 'closed_on' => now()->toDateString(), 'open_key' => null]);
    }
}
