<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\GCashTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GCashTransaction>
 */
class GCashTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_no' => 'GC-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'transaction_date' => now(),
            'type' => 'cash_in',
            'amount' => 1000,
            'charge_amount' => 20,
            'total_amount' => 1020,
            'status' => 'paid',
            'borrower_id' => Borrower::factory(),
            'transactor_user_id' => User::factory(),
            'remarks' => null,
            'paid_at' => null,
            'paid_by_user_id' => null,
        ];
    }
}
