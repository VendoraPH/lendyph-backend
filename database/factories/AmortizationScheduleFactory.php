<?php

namespace Database\Factories;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

class AmortizationScheduleFactory extends Factory
{
    protected $model = AmortizationSchedule::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'period_number' => 1,
            'due_date' => now()->addMonth(),
            'principal_due' => 8333.33,
            'interest_due' => 1250.00,
            'total_due' => 9583.33,
            'remaining_balance' => 41666.67,
            'status' => 'pending',
        ];
    }
}
