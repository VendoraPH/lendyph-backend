<?php

namespace Database\Factories;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A raw subledger row, for reads and fixtures only: it bypasses SavingsLedger,
 * so it neither checks the date rules nor moves the account's
 * `ledger_balance`. Post through SavingsLedger to test money.
 *
 * @extends Factory<SavingsTransaction>
 */
class SavingsTransactionFactory extends Factory
{
    protected $model = SavingsTransaction::class;

    public function definition(): array
    {
        return [
            'account_id' => SavingsAccount::factory(),
            'reference' => 'SVT-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'type' => 'deposit',
            'date' => now()->toDateString(),
            'value_date' => now()->toDateString(),
            'debit' => '0.00',
            'credit' => '100.00',
            'balance_after' => '100.00',
            'method' => 'cash',
            'created_by' => null,
        ];
    }
}
