<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShareCapitalPledgeFactory extends Factory
{
    protected $model = ShareCapitalPledge::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'amount' => 500.0,
            'schedule' => '15/30',
            'auto_credit' => false,
        ];
    }

    public function withAutoCredit(): static
    {
        return $this->state(['auto_credit' => true]);
    }
}
