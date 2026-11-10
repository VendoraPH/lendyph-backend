<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft run for the third quarter of 2026, with no lines.
 *
 * @extends Factory<SavingsInterestRun>
 */
class SavingsInterestRunFactory extends Factory
{
    protected $model = SavingsInterestRun::class;

    public function definition(): array
    {
        return [
            'product_id' => SavingsProduct::factory(),
            'branch_id' => Branch::factory(),
            'period_start' => '2026-07-01',
            'period_end' => '2026-09-30',
            'status' => 'draft',
            'live_key' => fn (array $attributes) => SavingsInterestRun::liveKey((int) $attributes['product_id'], $attributes['branch_id'] === null ? null : (int) $attributes['branch_id'], (string) $attributes['period_start']),
            'account_count' => 0,
            'total_gross' => '0.00',
            'total_tax' => '0.00',
            'total_net' => '0.00',
            'created_by' => null,
        ];
    }
}
