<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShareCapitalLedgerFactory extends Factory
{
    protected $model = ShareCapitalLedger::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'description' => 'Initial share capital contribution',
            'debit' => 0,
            'credit' => 1000.0,
        ];
    }
}
