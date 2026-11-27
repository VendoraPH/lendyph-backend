<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active 90-day placement of ₱50,000.00 at 3%, funded in cash. The figures
 * are fixtures, not a quote: tests of the maths use the quote itself.
 *
 * @extends Factory<TimeDeposit>
 */
class TimeDepositFactory extends Factory
{
    protected $model = TimeDeposit::class;

    public function definition(): array
    {
        return [
            'certificate_number' => 'TD-2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'borrower_id' => Borrower::factory(),
            'product_id' => SavingsProduct::factory()->timeDeposit(),
            'branch_id' => fn (array $attributes) => Borrower::query()->whereKey($attributes['borrower_id'])->value('branch_id'),
            'principal' => '50000.00',
            'annual_rate' => '3.0000',
            'pretermination_rate' => '1.0000',
            'withholding_tax_rate' => '0.0000',
            'term_days' => 90,
            'placement_date' => now()->toDateString(),
            'maturity_date' => now()->addDays(90)->toDateString(),
            'gross_interest' => '369.86',
            'withholding_tax' => '0.00',
            'net_interest' => '369.86',
            'maturity_amount' => '50369.86',
            'maturity_instruction' => 'payout',
            'funding_method' => 'cash',
            'status' => 'active',
            'created_by' => null,
        ];
    }
}
